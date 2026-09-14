#!/bin/bash
# Every agentic command, end to end, against a real MariaDB.
#
# Boots the shipped image, copies the working-tree files over the top, re-runs the migration and
# drives all eight actions through propose -> apply. Nothing is mocked: $pdo, the admin
# handlers, the engine schema and the guards are the real ones.
#
# Publishes NO ports. An earlier version of a sibling test used --network host and reached
# the operator's own phvalheim-dev on 8080/8081. Nothing was damaged, but the test had no
# business being able to touch it.

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
NAME="phv-actions-test-$$"

# MySQL cannot initialise a data directory over NFS: mysqld_safe starts, the daemon exits
# immediately, and the engine then waits for a database that will never arrive -- which
# reads as "the migration is broken" when it is nothing of the sort.
DATA="$(TMPDIR=/var/tmp mktemp -d)"
if stat -f -c %T "$DATA" 2>/dev/null | grep -qE 'nfs|cifs'; then
	echo "REFUSING: $DATA is on a network filesystem; MySQL cannot initialise there."
	exit 1
fi

# mktemp -d makes the directory 0700 owned by the invoking user. The container's mysql user
# is a different uid, so it cannot write there, and the only symptom is supervisor's
# "mysqld: Exited too quickly" -- which looks exactly like the NFS failure above and is a
# completely different cause. Widen it before anything mounts it.
chmod 755 "$DATA"

cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1
	# Root inside the container owns the MySQL tree, so rm as this user leaves a few
	# hundred MB behind. Delete it as root, in a throwaway container.
	#
	# The DIRECTORY ITSELF has to go the same way. The engine chowns /opt/stateful to its
	# own uid at boot, so the mount point stops belonging to whoever ran this script, and
	# /var/tmp is sticky -- a plain rmdir then fails with "Operation not permitted" and
	# leaves an empty directory behind after every run.
	docker run --rm -v "$DATA:/d" --entrypoint sh alpine -c 'rm -rf /d/..?* /d/.[!.]* /d/*' >/dev/null 2>&1
	rmdir "$DATA" 2>/dev/null || \
		docker run --rm -v /var/tmp:/vt --entrypoint sh alpine -c "rm -rf '/vt/$(basename "$DATA")'" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM

echo "Booting $IMAGE (no published ports)..."
docker run -d --name "$NAME" -v "$DATA:/opt/stateful" "$IMAGE" >/dev/null || exit 1

# POLL for readiness; never sleep a fixed amount and hope. Bail early and for the right
# reason if mysqld has died, otherwise a dead database reads as a failed migration.
#
# Only FATAL means supervisor has given up. A transient EXITED is NORMAL on a first boot:
# mysqld_safe's initial invocation ends once it has initialised the data directory -- that
# is the "mysqld from pid file ... ended" line -- and supervisor restarts it. Treating that
# as death made this harness report a dead database on every clean run while mysqld was in
# fact up 20 seconds later. A test that fails for its own reasons is worse than no test.
ready=0; exited=0
for i in $(seq 1 40); do
	if docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	     -e "SELECT 1" >/dev/null 2>&1; then ready=1; break; fi

	status=$(docker exec "$NAME" supervisorctl status mysqld 2>/dev/null)
	case "$status" in
		*FATAL*) exited=99 ;;
		*EXITED*|*BACKOFF*) exited=$((exited + 1)) ;;
		*) exited=0 ;;
	esac
	if [ "$exited" -ge 3 ]; then
		echo "  FAIL  mysqld will not stay up -- no database, so nothing below could be meaningful."
		echo "        supervisor says: $status"
		docker exec "$NAME" tail -5 /opt/stateful/logs/mysqld.log 2>/dev/null | sed 's/^/        /'
		exit 1
	fi
	sleep 3
done
[ "$ready" = 1 ] || { echo "  FAIL  database never came up"; exit 1; }

# Wait for the engine to finish the WHOLE migration chain, not just for a database.
#
# "worlds exists" is the wrong marker: dbUpdate_2.10.sh creates that table, so it is true
# within seconds while 2.40 and 2.45 are still to run. Waiting on it meant 2.46 was applied
# to a half-migrated schema -- ai_providers did not exist yet, so its two ALTERs failed,
# and the test then died on worlds.vanilla, a 2.40 column. Both failures were the harness
# racing the engine, and neither said so.
#
# ai_providers is 2.45's last object, so it is the marker for "everything before me has
# run". A new migration between 2.45 and this one would need this updated.
migrated=0
for i in $(seq 1 60); do
	if docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	     -e "DESCRIBE ai_providers" >/dev/null 2>&1 \
	   && docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	     -e "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1; then
		migrated=1; break
	fi
	sleep 2
done
if [ "$migrated" != 1 ]; then
	echo "  FAIL  the engine never finished its migrations (no ai_providers / worlds.vanilla)."
	docker exec "$NAME" grep -iE 'schema update|could not be run' /opt/stateful/logs/phvalheim.log 2>/dev/null | tail -5 | sed 's/^/        /'
	exit 1
fi
echo "  engine migrations complete (through 2.45)"

echo "Copying the working-tree files in..."
docker cp "$ROOT/container/nginx/www/includes/aiactions.php" "$NAME:/opt/stateless/nginx/www/includes/aiactions.php" >/dev/null
docker cp "$ROOT/container/nginx/www/includes/aicontext.php" "$NAME:/opt/stateless/nginx/www/includes/aicontext.php" >/dev/null
docker cp "$ROOT/container/nginx/www/admin/adminAPI.php"     "$NAME:/opt/stateless/nginx/www/admin/adminAPI.php"     >/dev/null
docker cp "$ROOT/container/engine/dbUpdates/dbUpdate_2.45.sh" "$NAME:/opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh" >/dev/null

# The migration is run with `bash`, exactly as dbUpdater.sh runs it -- so a missing execute
# bit cannot make this pass while the real boot path fails. That is how the first 2.45 RC
# shipped with no ai_providers table and nothing in the log to say so.
echo "Re-running dbUpdate_2.45.sh with the new blocks..."
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh 2>&1 | sed 's/^/  /'

for t in ai_proposals ai_usage; do
	if docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	     -e "DESCRIBE $t" >/dev/null 2>&1; then
		echo "  table $t created"
	else
		echo "  FAIL  table $t was not created -- the migration did not apply"
		exit 1
	fi
done

# Idempotence: dbUpdater runs every migration on EVERY boot, so a second run must be a
# no-op rather than an error or a duplicate.
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
rc=$?
if [ "$rc" = 0 ]; then echo "  re-running the migration is clean (exit 0)"
else                   echo "  FAIL  second run of the migration exited $rc"; exit 1; fi

echo "Driving every action..."
docker cp "$HERE/ai-e2e/actions.php" "$NAME:/tmp/actions.php" >/dev/null
docker exec "$NAME" php /tmp/actions.php
rc=$?

echo "Checking the capability card..."
docker cp "$HERE/ai-e2e/capabilities.php" "$NAME:/tmp/capabilities.php" >/dev/null
docker exec "$NAME" php /tmp/capabilities.php
crc=$?

[ "$rc" = 0 ] && [ "$crc" = 0 ]
exit $?
