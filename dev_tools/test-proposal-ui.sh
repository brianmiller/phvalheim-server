#!/bin/bash
# The confirm-card, driven in a real browser.
#
# Boots the container with the admin UI reachable, points Hugin at a mock provider that
# asks to stop a world, and clicks through propose -> card -> Apply while asserting the
# database at each step.
#
# Publishes ONLY 19081, and only on 127.0.0.1. The operator's own phvalheim-dev owns
# 8080/8081 on this host; an earlier sibling test used --network host and reached it.

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
NAME="phv-propui-test-$$"
PORT=19081
PW="${PHV_PW_DIR:-/mnt/wopr/development/brian/.pw-ui}"

DATA="$(TMPDIR=/var/tmp mktemp -d)"; chmod 755 "$DATA"

cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1
	docker run --rm -v "$DATA:/d" --entrypoint sh alpine -c 'rm -rf /d/..?* /d/.[!.]* /d/*' >/dev/null 2>&1
	rmdir "$DATA" 2>/dev/null || \
		docker run --rm -v /var/tmp:/vt --entrypoint sh alpine -c "rm -rf '/vt/$(basename "$DATA")'" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM

echo "Booting $IMAGE on 127.0.0.1:$PORT ..."
docker run -d --name "$NAME" -p "127.0.0.1:$PORT:8081" -v "$DATA:/opt/stateful" "$IMAGE" >/dev/null || exit 1

sql() { docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim -e "$1" 2>/dev/null; }

# Wait for the WHOLE migration chain, not just a database -- ai_providers is 2.45's last
# object, so it means everything before this point has run.
for i in $(seq 1 60); do
	sql "DESCRIBE ai_providers" >/dev/null 2>&1 && sql "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1 && break
	sleep 3
done
sql "DESCRIBE ai_providers" >/dev/null 2>&1 || { echo "  FAIL  engine never finished migrating"; exit 1; }

echo "Copying the working-tree files in..."
# config_env_puller.php is in this list deliberately: it DEFINES $huginNoticeShown, and
# leaving it out left the container with an undefined variable -- which PHP compares
# equal to 0, so the one-shot modal rendered on every load and looked like a product bug.
for f in includes/aiactions.php includes/aicontext.php includes/aiproviders.php includes/aidiagnose.php \
         includes/hugin.php includes/config_env_puller.php \
         admin/adminAPI.php admin/aiStream.php admin/index.php css/phvalheimStyles.css; do
	docker cp "$ROOT/container/nginx/www/$f" "$NAME:/opt/stateless/nginx/www/$f" >/dev/null
done
docker cp "$ROOT/container/engine/dbUpdates/dbUpdate_2.45.sh" "$NAME:/opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh" >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1

# OPcache serves the old compile until php-fpm8 is restarted. Skipping this makes a correct
# fix look broken, which has cost a debugging round trip before.
docker exec "$NAME" chown -R phvalheim: /opt/stateless/nginx/www >/dev/null 2>&1
docker exec "$NAME" supervisorctl restart php-fpm8 >/dev/null 2>&1
sleep 2

echo "Seeding a running world and a mock provider..."

# A fresh install has setupComplete=0, and admin/index.php REDIRECTS that to setup.php --
# so the admin page never renders and every selector times out with a misleading
# "#aiHelperBtn not found". Mark it configured, and suppress the one-shot notices that
# would otherwise sit on top of the panel.
sql "UPDATE settings SET setupComplete = 2;"
sql "UPDATE settings SET migrationNoticeShown = 1;" 2>/dev/null
sql "UPDATE settings SET aiOllamaNotice = 0;" 2>/dev/null
# huginNoticeShown is deliberately left at 0: the meet-Hugin modal is exercised below, and
# the proposal driver dismisses any overlay it finds before it starts.
sql "UPDATE settings SET huginNoticeShown = 0;" 2>/dev/null

sql "DELETE FROM worlds WHERE name='Midgard';"
sql "INSERT INTO worlds (name, status, mode, port, seed, vanilla) VALUES ('Midgard','running','',25000,'seed',0);"

docker cp "$HERE/ai-e2e/mock-provider.php" "$NAME:/tmp/mock-provider.php" >/dev/null
docker exec -d "$NAME" sh -c 'php -S 127.0.0.1:8899 /tmp/mock-provider.php > /tmp/mock.log 2>&1'
sleep 2

sql "DELETE FROM ai_providers;"
sql "INSERT INTO ai_providers (kind, label, base_url, api_key, model, enabled, is_default)
     VALUES ('openai_compatible','Mock','http://127.0.0.1:8899/v1','testkey','mock-large',1,1);"

# Prove the UI is actually being served before blaming the browser for a blank page.
for i in $(seq 1 20); do
	code=$(docker exec "$NAME" sh -c "php -r \"echo @file_get_contents('http://127.0.0.1:8081/index.php') ? 'ok' : 'no';\"" 2>/dev/null)
	[ "$code" = ok ] && break
	sleep 2
done
[ "$code" = ok ] || { echo "  FAIL  admin UI is not serving inside the container"; docker logs --tail 20 "$NAME"; exit 1; }

echo "Driving the browser..."
[ -d "$PW/node_modules/playwright" ] || { echo "  FAIL  no playwright install at $PW"; exit 1; }

# Node resolves a bare `import ... from 'playwright'` relative to the SCRIPT's directory,
# not the working directory, so `cd` into the playwright tree does nothing for an .mjs that
# lives in dev_tools/. Run the driver from inside that tree instead.
# The welcome modal FIRST -- it is one-shot, so it has to be checked before anything else
# dismisses it. The proposal driver then runs against a page that has already seen it,
# which is also the state a real operator is in by the time they use the panel.
cp "$HERE/ai-e2e/hugin-notice.mjs" "$PW/.phv-hugin-notice.mjs"
PHV_UI_BASE="http://127.0.0.1:$PORT" node "$PW/.phv-hugin-notice.mjs"
nrc=$?
rm -f "$PW/.phv-hugin-notice.mjs"
echo "  huginNoticeShown in the DB after dismissal: [$(sql "SELECT huginNoticeShown FROM settings")]"

cp "$HERE/ai-e2e/proposal-ui.mjs" "$PW/.phv-proposal-ui.mjs"
PHV_UI_BASE="http://127.0.0.1:$PORT" PHV_UI_WORLD=Midgard node "$PW/.phv-proposal-ui.mjs"
rc=$?
rm -f "$PW/.phv-proposal-ui.mjs"
[ "$nrc" = 0 ] || rc=1

if [ "$rc" != 0 ]; then
	echo
	echo "--- ai.log ---"
	docker exec "$NAME" tail -20 /opt/stateful/logs/ai.log 2>/dev/null | sed 's/^/    /'
	echo "--- mock ---"
	docker exec "$NAME" tail -10 /tmp/mock.log 2>/dev/null | sed 's/^/    /'
fi
exit $rc
