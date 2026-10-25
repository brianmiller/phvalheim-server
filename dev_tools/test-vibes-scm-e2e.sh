#!/usr/bin/env bash
# requires: container
# Disposable exact-image server + authenticated HTTPS GitHub/GitLab fixture + real smart Git.
# No external account or repository is changed. All normal API, jobs and browser code is real.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
SOURCE=${ORACLE_CONTAINER:-phvalheim-dev}
IMAGE=${ORACLE_IMAGE:-$(docker inspect --format '{{.Image}}' "$SOURCE")}
SCM_CONTAINER=${SCM_CONTAINER:-phv-scm-e2e}
SCM_PORT=${SCM_PORT:-18091}
SCM_VOLUME="$SCM_CONTAINER-data"
SCM_TMP=$(mktemp -d /tmp/phv-scm-e2e.XXXXXX)
cleanup() {
 docker rm -f "$SCM_CONTAINER" >/dev/null 2>&1 || true
 docker volume rm "$SCM_VOLUME" >/dev/null 2>&1 || true
 rm -rf "$SCM_TMP"
}
docker inspect "$SCM_CONTAINER" >/dev/null 2>&1 && { echo 'HARNESS: SCM scratch container already exists'; exit 2; }
trap cleanup EXIT
SOURCE_VOLUME=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/opt/stateful"}}{{.Source}}{{end}}{{end}}' "$SOURCE")
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout "$SCM_TMP/key.pem" -out "$SCM_TMP/cert.pem" \
 -subj '/CN=gitlab.fixture.test' -addext 'subjectAltName=DNS:gitlab.fixture.test,DNS:github.com' >/dev/null 2>&1
python3 - "$SCM_TMP/tokens.json" <<'PY'
import json,secrets,sys
with open(sys.argv[1],'w') as f: json.dump({k:secrets.token_hex(20) for k in ('github','gitlab')},f)
PY
docker run -d --name "$SCM_CONTAINER" -p "127.0.0.1:$SCM_PORT:8081" \
 --add-host github.com:127.0.0.1 --add-host gitlab.fixture.test:127.0.0.1 \
 -v "$SCM_VOLUME:/opt/stateful" \
 -v "$SOURCE_VOLUME/vibes/buildpak:/opt/stateful/vibes/buildpak:ro" \
 -v "$SOURCE_VOLUME/vibes/libs:/opt/stateful/vibes/libs:ro" \
 -v "$REPO/dev_tools/fixtures:/phv-fixtures:ro" "$IMAGE" >/dev/null
# Explicit development mode; the release oracle runs with this unset against image bytes.
if [ "${SCM_WORKTREE:-0}" = 1 ]; then
 for file in git.php scm.php; do
  docker cp "$REPO/container/engine/tools/vibes/lib/$file" "$SCM_CONTAINER:/opt/stateless/engine/tools/vibes/lib/$file"
  docker exec "$SCM_CONTAINER" chmod 644 "/opt/stateless/engine/tools/vibes/lib/$file"
 done
fi
SCM_SCHEMA_READY=0
for i in $(seq 1 150); do
 if docker exec "$SCM_CONTAINER" mysql -N -uroot phvalheim -e 'SELECT vibesInstalled,(SELECT COUNT(*) FROM hv_projects),(SELECT COUNT(*) FROM hv_sessions),(SELECT COUNT(*) FROM hv_jobs) FROM settings' >/dev/null 2>&1; then SCM_SCHEMA_READY=1; break; fi
 sleep 2
done
[ "$SCM_SCHEMA_READY" = 1 ] || { echo 'HARNESS: SCM scratch database did not finish its migrations'; exit 2; }
docker exec "$SCM_CONTAINER" supervisorctl stop phvalheim >/dev/null
docker exec "$SCM_CONTAINER" mkdir -p /tmp/vibes-scm-fixture
for file in cert.pem key.pem tokens.json; do docker cp "$SCM_TMP/$file" "$SCM_CONTAINER:/tmp/vibes-scm-fixture/$file"; done
docker cp "$SCM_TMP/cert.pem" "$SCM_CONTAINER:/usr/local/share/ca-certificates/phv-scm-fixture.crt"
docker exec "$SCM_CONTAINER" update-ca-certificates >/dev/null 2>&1
docker exec "$SCM_CONTAINER" chmod 600 /tmp/vibes-scm-fixture/key.pem
docker exec "$SCM_CONTAINER" php -r '
 require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php";
 require "/opt/stateless/nginx/www/includes/vibes.php";
 $d=vibesSettingsDefaults();$t=json_decode(file_get_contents("/tmp/vibes-scm-fixture/tokens.json"),true);
 $d["author"]["namespace"]="Fixture";
 $d["github"]=array_merge($d["github"],["enabled"=>true,"token"=>$t["github"],"owner"=>"fixture","api"=>"https://gitlab.fixture.test/github"]);
 $d["gitlab"]=array_merge($d["gitlab"],["enabled"=>true,"token"=>$t["gitlab"],"host"=>"https://gitlab.fixture.test","group"=>"team/sub"]);
 $pdo->prepare("UPDATE settings SET setupComplete=2,vibesInstalled=1,vibesNoticeShown=1,whatsNewShownVersion=?,vibesSettings=?")->execute([getenv("phvalheimVersion"),json_encode($d)]);
 '
docker exec -d "$SCM_CONTAINER" python3 /phv-fixtures/vibes-scm-server.py
if [ -z "${NODE_PATH:-}" ]; then export NODE_PATH="$REPO/../.pw-ui/node_modules"; fi
export SCM_CONTAINER SCM_PORT
node "$REPO/dev_tools/fixtures/vibes-scm-e2e.js"
