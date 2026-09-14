#!/bin/bash
#
# End-to-end test of the 2.45 AI Helper against a REAL running container.
#
# dev_tools/test-ai-helper.sh checks the source. This one boots the image, lets the
# engine run its migrations, and drives the admin HTTP API exactly as the browser does:
# provider wizard, live model discovery, diagnostics, the tool-calling loop, and the SSE
# stream. The provider is a mock served by `php -S` inside the container, so this needs
# no API key, no network egress and costs nothing.
#
# It exists because static checks could not have caught the bug that shipped in the first
# 2.45 RC: dbUpdate_2.45.sh was committed non-executable, dbUpdater.sh ran it as a bare
# path, got exit 126, and logged NOTHING because that matched neither of its two branches.
# The image verified clean and the tables were simply absent. Only booting it found that.
#
#   dev_tools/test-ai-e2e.sh [image]     default: theoriginalbrian/phvalheim-server:rc

set -u
IMAGE="${1:-theoriginalbrian/phvalheim-server:rc}"
NAME=phv-ai-e2e
HERE="$(cd "$(dirname "$0")" && pwd)"

# LOCAL DISK, explicitly -- not `mktemp -d`.
#
# On this dev host TMPDIR is /mnt/wopr/development/brian, which is an NFS mount, and
# `mktemp -d` honours it. MySQL cannot initialise a data directory over NFS: mysqld_safe
# starts, the daemon exits immediately, supervisor reports "Exited too quickly", and the
# engine then sits in its wait-for-database loop forever. dbUpdater never runs, so the
# symptom is "no ai_* tables" -- which looks exactly like a broken migration and is not.
DATA="${PHV_E2E_DATA:-/tmp/phv-ai-e2e-$$}"
mkdir -p "$DATA" || exit 1
case "$(df -T "$DATA" | tail -1 | awk '{print $2}')" in
	nfs*|cifs|smb*)
		echo "REFUSING: $DATA is on a network filesystem; MySQL cannot initialise there."
		echo "Set PHV_E2E_DATA to a local path."
		exit 1 ;;
esac

cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1
	# The container writes /opt/stateful as root, so a plain `rm -rf` from the calling
	# user fails on nearly every file and leaves a few hundred MB of MySQL data behind.
	# Delete the contents from inside a throwaway container that is root, then remove
	# the now-empty directory here.
	docker run --rm -v "$DATA:/d" --entrypoint sh alpine -c 'rm -rf /d/..?* /d/.[!.]* /d/*' >/dev/null 2>&1
	rmdir "$DATA" 2>/dev/null || rm -rf "$DATA" 2>/dev/null
}
trap cleanup EXIT

echo "=== booting $IMAGE ==="
docker rm -f "$NAME" >/dev/null 2>&1
docker run -d --name "$NAME" -v "$DATA:/opt/stateful" "$IMAGE" >/dev/null || exit 1

sql() { docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim -e "$1" 2>/dev/null; }

echo "=== the migration must have run AT BOOT, unaided ==="
# POLL for the outcome; do not sleep a fixed amount and hope.
#
# A fixed wait conflates "the migration is broken" with "the container was still booting",
# and those need opposite responses. Poll for the tables, and bail early with the real
# reason if mysqld has died -- otherwise a dead database reads as a failed migration.
#
# The check is deliberately "did the ENGINE create these", not "does the SQL work".
# Running the migration by hand here would hide precisely the bug that shipped in the
# first 2.45 RC.
tables=0
for i in $(seq 1 60); do
	tables=$(sql "SHOW TABLES LIKE 'ai_%'" | wc -l)
	[ "$tables" = "2" ] && break
	if docker exec "$NAME" supervisorctl status mysqld 2>/dev/null | grep -qE 'FATAL|EXITED'; then
		echo "  FAIL  mysqld died -- the database never came up, so no migration could run."
		docker exec "$NAME" tail -5 /opt/stateful/logs/mysqld.log 2>/dev/null | sed 's/^/        /'
		echo "        (if this is a filesystem problem, check that $DATA is on local disk)"
		exit 1
	fi
	sleep 3
done

if [ "$tables" = "2" ]; then
	echo "  PASS  ai_providers + ai_model_cache created at boot (after ~$((i*3))s)"
else
	echo "  FAIL  expected 2 ai_* tables, found $tables"
	echo "        engine log:"
	docker exec "$NAME" grep -iE "schema update|could not be run|2\.45" /opt/stateful/logs/phvalheim.log 2>/dev/null \
		| tail -8 | sed 's/^/        /'
	echo "        migration file mode: $(docker exec "$NAME" stat -c '%a' /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh 2>/dev/null)"
	exit 1
fi

echo "=== legacy credential migration ==="
# These feed $shellfail so they can actually fail the run. A check that only ever prints
# is decoration.
shellfail=0
sql "UPDATE settings SET openaiApiKey='sk-legacy', claudeApiKey='sk-ant-legacy', geminiApiKey='AIza-legacy', ollamaUrl='http://lab:11434';" >/dev/null
sql "DELETE FROM ai_providers;" >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1

n=$(sql "SELECT COUNT(*) FROM ai_providers")
[ "$n" = "4" ] && echo "  PASS  4 legacy credentials became provider rows" \
               || { echo "  FAIL  expected 4 provider rows, got $n"; shellfail=1; }

# 2.44 stored a BARE Ollama host:port because it spoke the native API. There is no native
# adapter any more, so the row has to arrive as openai_compatible with /v1 appended -- a
# row left on the dead kind would be a provider nothing can dispatch.
okind=$(sql "SELECT kind FROM ai_providers WHERE label='Ollama'")
ourl=$(sql "SELECT base_url FROM ai_providers WHERE label='Ollama'")
[ "$okind" = "openai_compatible" ] && echo "  PASS  the legacy Ollama URL became an openai_compatible provider" \
               || { echo "  FAIL  Ollama row has kind '$okind', expected openai_compatible"; shellfail=1; }
[ "$ourl" = "http://lab:11434/v1" ] && echo "  PASS  /v1 appended to the bare Ollama host ($ourl)" \
               || { echo "  FAIL  Ollama base_url is '$ourl', expected http://lab:11434/v1"; shellfail=1; }

# The other path: an install that already ran an earlier 2.45 RC and HAS kind='ollama'
# rows. That is not a legacy-settings import, so it must be repaired outside the
# registry-is-empty guard, and must be safe to run twice.
sql "UPDATE ai_providers SET kind='ollama', base_url='http://lab:11434' WHERE label='Ollama';" >/dev/null
# Assert the PRECONDITION. Without this the next two checks pass for the wrong reason if
# the seeding UPDATE silently did nothing: the row would already be openai_compatible with
# /v1, which is exactly what they assert. A fixture that cannot fail is not a fixture.
pre=$(sql "SELECT kind FROM ai_providers WHERE label='Ollama'")
[ "$pre" = "ollama" ] || { echo "  FAIL  could not seed a kind='ollama' row (got '$pre') -- the next checks would be meaningless"; shellfail=1; }
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
k2=$(sql "SELECT kind FROM ai_providers WHERE label='Ollama'")
u2=$(sql "SELECT base_url FROM ai_providers WHERE label='Ollama'")
[ "$k2" = "openai_compatible" ] && [ "$u2" = "http://lab:11434/v1" ] \
	&& echo "  PASS  an existing kind='ollama' row is converted in place ($u2)" \
	|| { echo "  FAIL  in-place conversion wrong: kind='$k2' url='$u2'"; shellfail=1; }

# The conversion is a change the operator did not make, so it must announce itself.
notice=$(sql "SELECT aiOllamaNotice FROM settings")
[ "$notice" = "1" ] && echo "  PASS  the conversion raised a one-shot notice for the admin UI" \
               || { echo "  FAIL  aiOllamaNotice is '$notice', expected 1"; shellfail=1; }

# Idempotence: a second pass must not append /v1 twice.
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
u3=$(sql "SELECT base_url FROM ai_providers WHERE label='Ollama'")
[ "$u3" = "http://lab:11434/v1" ] && echo "  PASS  converting twice is a no-op (no doubled /v1)" \
               || { echo "  FAIL  second pass mangled the URL: '$u3'"; shellfail=1; }

# A dismissed notice must stay dismissed. The migration runs at EVERY boot, so if it
# re-raised the flag unconditionally the operator would see the dialog forever.
sql "UPDATE settings SET aiOllamaNotice = 0;" >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
n4=$(sql "SELECT aiOllamaNotice FROM settings")
[ "$n4" = "0" ] && echo "  PASS  a dismissed notice is not raised again on the next boot" \
               || { echo "  FAIL  notice came back as '$n4' with nothing left to convert"; shellfail=1; }

# And the dead kind must be gone from the code, not merely unused by the data.
kinds=$(docker exec "$NAME" php -r "require '/opt/stateless/nginx/www/includes/aiproviders.php'; echo implode(',', array_keys(aiProviderKinds()));")
case "$kinds" in
	*ollama*) echo "  FAIL  the ollama kind is still offered: $kinds"; shellfail=1 ;;
	*)        echo "  PASS  no ollama kind remains ($kinds)" ;;
esac

# The #83 rule: any model carried over from 2.44 could only be one of its stale constants.
withmodel=$(sql "SELECT COUNT(*) FROM ai_providers WHERE model<>''")
[ "$withmodel" = "0" ] && echo "  PASS  no model carried over (resolves live on first use)" \
                       || { echo "  FAIL  $withmodel row(s) carried a stale model"; shellfail=1; }

# Re-running must not duplicate, and must not resurrect something the operator deleted.
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
n2=$(sql "SELECT COUNT(*) FROM ai_providers")
[ "$n2" = "4" ] && echo "  PASS  re-running the migration does not duplicate rows" \
                || { echo "  FAIL  row count became $n2 on re-run"; shellfail=1; }

sql "DELETE FROM ai_providers WHERE kind='gemini';" >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
gem=$(sql "SELECT COUNT(*) FROM ai_providers WHERE kind='gemini'")
[ "$gem" = "0" ] && echo "  PASS  a deleted provider is not resurrected" \
                 || { echo "  FAIL  deleted provider came back"; shellfail=1; }

echo "=== a world for the scanner to find ==="
sql "INSERT INTO worlds (name,status,vanilla,public) VALUES ('Testworld','running',0,0);" >/dev/null
docker exec "$NAME" bash -c 'mkdir -p /opt/stateful/logs && cat > /opt/stateful/logs/valheimworld_Testworld.log <<EOF
[Info   : BepInEx] BepInEx 5.4.22 - Valheim
[Message: BepInEx] Loading [JewelHeim 1.2.0]
[Error  : BepInEx] Could not load [EpicLoot 0.9.34] : missing dependency ValheimLib
[Info   : Unity] DepthOfField shader warmup complete
[Error  : Unity] Address already in use, bind failed on port 25000
EOF'

echo "=== mock provider ==="
docker cp "$HERE/ai-e2e/mock-provider.php" "$NAME:/tmp/mock-provider.php" >/dev/null
docker exec "$NAME" chmod 755 /tmp/mock-provider.php
docker exec -d "$NAME" sh -c 'php -S 127.0.0.1:8899 /tmp/mock-provider.php > /tmp/mock.log 2>&1'
sleep 3

echo "=== driving the admin API ==="
docker cp "$HERE/ai-e2e/e2e.php" "$NAME:/tmp/e2e.php" >/dev/null
docker exec "$NAME" php /tmp/e2e.php
rc=$?

echo
if [ $rc -eq 0 ] && [ $shellfail -eq 0 ]; then
	echo "AI HELPER E2E OK"
	exit 0
fi
echo "AI HELPER E2E FAILED (php=$rc migration-checks=$shellfail)"
exit 1
