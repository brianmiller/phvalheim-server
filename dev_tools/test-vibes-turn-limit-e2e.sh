#!/usr/bin/env bash
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/fixture-analytics.sh" || exit 1
# requires: container
# Disposable exact-image server + a deterministic looping model. A REAL turn runs to the
# shipped round limit in a real browser, then the operator's next message has to go through
# without a page reload. The settings document seeded here is the one a 2.60/2.61 install holds
# (schema 1, 100 rounds, 15 minutes), so the turn also proves an upgrade reaches the new limit.
#
#   TURN_WORKTREE=1   copy this checkout's changed files into the container (development);
#                     unset, the image's own bytes are tested (the release oracle).
#   TURN_ROUNDS=200   save that limit first instead of running at the default. For the negative
#                     control against an image that predates the fix and tops out at 200.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
SOURCE=${ORACLE_CONTAINER:-phvalheim-dev}
IMAGE=${ORACLE_IMAGE:-$(docker inspect --format '{{.Image}}' "$SOURCE")}
TURN_CONTAINER=${TURN_CONTAINER:-phv-turn-limit-e2e}
TURN_PORT=${TURN_PORT:-18093}
TURN_VOLUME="$TURN_CONTAINER-data"
cleanup() {
 [ "${TURN_KEEP:-0}" = 1 ] && { echo "HARNESS: TURN_KEEP=1, leaving $TURN_CONTAINER and its volume"; return; }
 docker rm -f "$TURN_CONTAINER" >/dev/null 2>&1 || true
 docker volume rm "$TURN_VOLUME" >/dev/null 2>&1 || true
}
docker inspect "$TURN_CONTAINER" >/dev/null 2>&1 && { echo 'HARNESS: turn-limit scratch container already exists'; exit 2; }
trap cleanup EXIT
SOURCE_VOLUME=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/opt/stateful"}}{{.Source}}{{end}}{{end}}' "$SOURCE")
docker run -d "${PHV_TEST_ANALYTICS_ARGS[@]}" --name "$TURN_CONTAINER" -p "127.0.0.1:$TURN_PORT:8081" \
 -v "$TURN_VOLUME:/opt/stateful" \
 -v "$SOURCE_VOLUME/vibes/buildpak:/opt/stateful/vibes/buildpak:ro" \
 -v "$SOURCE_VOLUME/vibes/libs:/opt/stateful/vibes/libs:ro" \
 -v "$REPO/dev_tools/fixtures:/phv-fixtures:ro" "$IMAGE" >/dev/null
if [ "${TURN_WORKTREE:-0}" = 1 ]; then
 for file in nginx/www/js/vibes.js nginx/www/includes/vibes.php nginx/www/admin/hugins_vibes.php engine/tools/vibes/lib/agent.php; do
  docker cp "$REPO/container/$file" "$TURN_CONTAINER:/opt/stateless/$file"
  docker exec "$TURN_CONTAINER" chmod 644 "/opt/stateless/$file"
 done
fi
READY=0
for i in $(seq 1 150); do
 if docker exec "$TURN_CONTAINER" mysql -N -uroot phvalheim -e 'SELECT vibesInstalled,(SELECT COUNT(*) FROM hv_projects),(SELECT COUNT(*) FROM hv_jobs),(SELECT COUNT(*) FROM ai_providers) FROM settings' >/dev/null 2>&1; then READY=1; break; fi
 sleep 2
done
[ "$READY" = 1 ] || { echo 'HARNESS: scratch database did not finish its migrations'; exit 2; }
docker exec "$TURN_CONTAINER" supervisorctl stop phvalheim >/dev/null
# The engine prepares the data volume as it starts, but the database can answer before it gets
# there, and it has just been stopped. These are its own steps: the ownership of /opt/stateful
# from tools/phvalheimprep.sh (Hugin's request queue is made under it on first use, by the
# phvalheim user) and Vibes' home from container/engine/phvalheim.
docker exec "$TURN_CONTAINER" sh -c 'chown phvalheim: /opt/stateful
 mkdir -p /opt/stateful/vibes/projects /opt/stateful/vibes/assets /opt/stateful/vibes/catalog /opt/stateful/vibes/downloads /opt/stateful/vibes/logs/jobs
 chown phvalheim:phvalheim /opt/stateful/vibes /opt/stateful/vibes/projects /opt/stateful/vibes/assets /opt/stateful/vibes/catalog /opt/stateful/vibes/downloads /opt/stateful/vibes/logs /opt/stateful/vibes/logs/jobs'
[ "${TURN_WORKTREE:-0}" = 1 ] && docker exec "$TURN_CONTAINER" supervisorctl restart php-fpm8 >/dev/null
docker exec -u phvalheim "$TURN_CONTAINER" sh -c 'mkdir -p /tmp/vibes-turn-fixture && cd /tmp/vibes-turn-fixture && echo loop > mode && echo "$0" > delay_ms && echo 0 > count' "${TURN_DELAY_MS:-110}"
# As root, like the other fixture servers: a checkout is not necessarily readable by uid 1000.
docker exec -d "$TURN_CONTAINER" php -S 127.0.0.1:18999 /phv-fixtures/vibes-turn-loop-provider.php
# The document an install that saved its Vibes settings under 2.60/2.61 has on disk: the
# literal numbers, not today's constants, or this would test the new code against itself.
docker exec "$TURN_CONTAINER" php -r '
 require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php";
 require "/opt/stateless/nginx/www/includes/vibes.php";
 $d=vibesSettingsDefaults();$d["schema"]=1;$d["author"]["namespace"]="Fixture";
 $d["agent"]["max_rounds"]=100;$d["agent"]["turn_timeout_s"]=900;$d["agent"]["model_timeout_s"]=900;
 $d["agent"]["auto_build"]=false;$d["agent"]["auto_commit"]=false;
 $pdo->prepare("UPDATE settings SET setupComplete=2,vibesInstalled=1,vibesNoticeShown=1,whatsNewShownVersion=?,vibesSettings=?")->execute([getenv("phvalheimVersion"),json_encode($d)]);
 $pdo->exec("DELETE FROM ai_providers");
 $pdo->prepare("INSERT INTO ai_providers (kind,label,base_url,api_key,model,extra_headers,enabled,is_default,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,1,1,0,NOW(),NOW())")
     ->execute(["gemini","Loop fixture","http://127.0.0.1:18999","","fixture-flash","{}"]);
 '
if [ -z "${NODE_PATH:-}" ]; then export NODE_PATH="$REPO/../.pw-ui/node_modules"; fi
export TURN_CONTAINER TURN_PORT TURN_ROUNDS="${TURN_ROUNDS:-}"
node "$REPO/dev_tools/fixtures/vibes-turn-limit-e2e.js"
