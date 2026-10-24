#!/bin/bash
# requires: container
#
# Oracle: a Hugin's Vibes package published into the catalogue (mods.source='vibes') SURVIVES a
# forced Thunderstore sync -- its mods row, its mod_versions row and its mod_deps edges -- and
# its dependency edges resolve cross-source to the Thunderstore row (design 4, 6.4).
#
# Why this is the test that matters: modSync.py prunes by key diff PER SOURCE and a --force run
# does `DELETE FROM mod_deps` then rebuilds every edge from mod_versions.deps. If either of those
# ever iterated our rows, publishing would work until the next hourly cron and then silently
# vanish from every world's mod list. So: publish a fixture through the REAL vibesCatalogPublish()
# inside the container, SELECT the rows (the control), run modSync.py --force --source
# thunderstore, SELECT again, then unpublish through the real vibesCatalogUnpublish() and prove
# the cleanup left nothing. Thunderstore only: a full all-source force is slow and this proves
# the same code path.
#
# Deploys ONLY container/engine/tools/vibes into the container (the runner's own tree); nothing
# else in the image is touched and no world is involved.
#
# Usage: bash dev_tools/test-vibes-catalog-survives-sync.sh [container]   (default phvalheim-dev)

set -u
C="${1:-phvalheim-dev}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1${2:+ -- $2}"; fail=$((fail+1)); }
sql() { docker exec "$C" mysql -uroot --skip-column-names --batch phvalheim -e "$1" 2>/dev/null; }

docker ps --format '{{.Names}}' | grep -qx "$C" || { echo "FAIL  container $C is not running"; exit 1; }
sql "SELECT 1 FROM hv_projects LIMIT 0" >/dev/null 2>&1 || { echo "FAIL  hv_projects does not exist in $C (2.59 migration not applied)"; exit 1; }

# Deploy the runner tree (ours alone) so the in-container PHP drives the shipped code.
docker exec "$C" mkdir -p /opt/stateless/engine/tools/vibes
docker cp "$REPO/container/engine/tools/vibes/." "$C:/opt/stateless/engine/tools/vibes/"
docker exec "$C" chown -R phvalheim:phvalheim /opt/stateless/engine/tools/vibes

NS=ZzVibesTest; NAME=VibesSyncTest; SLUG=zz-vibes-synctest; VER=0.9.9
cleanup() {
	docker exec -i -u phvalheim "$C" php <<EOF >/dev/null 2>&1
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require '/opt/stateless/engine/tools/vibes/lib/catalog.php';
\$s = \$pdo->prepare("SELECT * FROM hv_projects WHERE slug=?"); \$s->execute(['$SLUG']);
if (\$p = \$s->fetch(PDO::FETCH_ASSOC)) { vibesCatalogUnpublish(\$pdo, \$p, '$VER'); \$pdo->prepare("DELETE FROM hv_projects WHERE id=?")->execute([\$p['id']]); }
\$pdo->exec("DELETE d FROM mod_deps d JOIN mod_versions v ON v.id=d.version_id JOIN mods m ON m.id=v.mod_id WHERE m.source='vibes' AND m.owner='$NS'");
\$pdo->exec("DELETE v FROM mod_versions v JOIN mods m ON m.id=v.mod_id WHERE m.source='vibes' AND m.owner='$NS'");
\$pdo->exec("DELETE FROM mods WHERE source='vibes' AND owner='$NS'");
vibesRmTree(vibesProjectDir('$SLUG'));
@unlink(vibesCatalogDir() . '/$NS-$NAME-$VER.zip');
EOF
}
trap cleanup EXIT
cleanup   # a previous aborted run must not make this one pass or fail for the wrong reason

# --- publish the fixture through the real code -----------------------------------------------
OUT=$(docker exec -i -u phvalheim "$C" php <<EOF 2>&1
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require '/opt/stateless/engine/tools/vibes/lib/catalog.php';
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$dir = vibesProjectDir('$SLUG');
@mkdir("\$dir/.vibes", 0775, true); @mkdir("\$dir/dist", 0775, true); @mkdir("\$dir/bin/Release/net472", 0775, true);
vibesWriteJson("\$dir/.vibes/project.json", ['slug' => '$SLUG', 'name' => '$NAME', 'namespace' => '$NS']);
vibesWriteJson("\$dir/manifest.json", ['name' => '$NAME', 'version_number' => '$VER', 'website_url' => '', 'description' => 'sync oracle fixture',
    'dependencies' => ['denikson-BepInExPack_Valheim-5.4.2202', 'NoSuchOwner-NoSuchMod-1.0.0']]);
file_put_contents("\$dir/README.md", "# fixture\n");
copy(VIBES_TOOLS . '/templates/basic-plugin/icon.png', "\$dir/icon.png");
file_put_contents("\$dir/bin/Release/net472/$NAME.dll", str_repeat('x', 2048));
vibesWriteJson("\$dir/.vibes/builds/last.json", ['ok' => true, 'errors' => [], 'warnings' => 0, 'ms' => 1, 'at' => date('c'), 'configuration' => 'Release', 'log' => null]);
\$pdo->prepare("INSERT INTO hv_projects (slug, name, namespace, description, version, template, status, last_build_ok, last_build_at) VALUES (?,?,?,?,?,'basic-plugin','built',1,NOW())")
    ->execute(['$SLUG', '$NAME', '$NS', 'sync oracle fixture', '$VER']);
\$id = (int)\$pdo->lastInsertId();
\$pk = vibesPackage('$SLUG');
if (empty(\$pk['success'])) { echo json_encode(['stage' => 'package'] + \$pk); exit(1); }
\$project = vibesProjectRow(\$pdo, \$id);
\$r = vibesCatalogPublish(\$pdo, \$project, '$VER');
echo json_encode(['stage' => 'publish', 'projectId' => \$id] + \$r);
EOF
)
echo "$OUT" | grep -q '"stage":"publish"' && echo "$OUT" | grep -q '"success":true' && ok "fixture packaged and published through vibesPackage()/vibesCatalogPublish()" || { bad "publish" "$OUT"; echo; echo "$pass passed, $fail failed"; exit 1; }

# --- the control: rows and edges exist BEFORE the sync ---------------------------------------
MODID=$(sql "SELECT id FROM mods WHERE source='vibes' AND owner='$NS' AND name='$NAME'")
[ -n "$MODID" ] && ok "mods row exists (id $MODID, source=vibes)" || bad "mods row"
VID=$(sql "SELECT id FROM mod_versions WHERE mod_id='$MODID' AND version='$VER'")
[ -n "$VID" ] && ok "mod_versions row exists (id $VID)" || bad "mod_versions row"
URL=$(sql "SELECT download_url FROM mod_versions WHERE id='$VID'")
[ "$URL" = "file:///opt/stateful/vibes/catalog/$NS-$NAME-$VER.zip" ] && ok "download_url is the file:// catalogue path" || bad "download_url" "$URL"
docker exec "$C" test -s "/opt/stateful/vibes/catalog/$NS-$NAME-$VER.zip" && ok "zip copied into catalog/" || bad "zip in catalog/"
DENORM=$(sql "SELECT full_name, latest_version, version_count, categories FROM mods WHERE id='$MODID'")
echo "$DENORM" | grep -q "^$NS-$NAME	$VER	1	.*Hugin's Vibes" && ok "mods row denormalised: full_name, latest_version, version_count, category" || bad "mods denormalised fields" "$DENORM"
EDGES_BEFORE=$(sql "SELECT COUNT(*) FROM mod_deps WHERE version_id='$VID'")
[ "$EDGES_BEFORE" = "2" ] && ok "2 mod_deps edges written (one per dependency string)" || bad "edges before" "$EDGES_BEFORE"
RES=$(sql "SELECT m.source FROM mod_deps d JOIN mods m ON m.id=d.dep_mod_id WHERE d.version_id='$VID' AND d.dep_string='denikson-BepInExPack_Valheim-5.4.2202'")
[ "$RES" = "thunderstore" ] && ok "BepInExPack edge resolved to the THUNDERSTORE row (cross-source, preferred)" || bad "cross-source resolution" "$RES"
UNRES=$(sql "SELECT dep_mod_id IS NULL FROM mod_deps WHERE version_id='$VID' AND dep_string='NoSuchOwner-NoSuchMod-1.0.0'")
[ "$UNRES" = "1" ] && ok "unknown dependency stored as an unresolved edge (as modSync does)" || bad "unresolved edge" "$UNRES"
sql "SELECT status, published_version FROM hv_projects WHERE slug='$SLUG'" | grep -q "^published	$VER$" && ok "hv_projects status=published, published_version set" || bad "hv_projects status"
TS_BEFORE=$(sql "SELECT COUNT(*) FROM mods WHERE source='thunderstore'")

# --- the stimulus: a forced Thunderstore sync (full prune + full dependency rebuild) ----------
echo "  ....  running modSync.py --force --source thunderstore (this takes a minute or two)"
# As phvalheim: that is the user cron.d/modSync runs it as, so lock files and log ownership match.
SYNC=$(docker exec -u phvalheim "$C" python3 /opt/stateless/engine/tools/modSync.py --force --source thunderstore --trigger manual 2>&1); RC=$?
[ "$RC" = "0" ] && ok "modSync.py exited 0" || bad "modSync.py exit $RC" "$(echo "$SYNC" | tail -3)"
TS_AFTER=$(sql "SELECT COUNT(*) FROM mods WHERE source='thunderstore'")
[ "$TS_AFTER" -gt 1000 ] 2>/dev/null && ok "the sync really ran: $TS_AFTER thunderstore rows (was $TS_BEFORE)" || bad "sync touched thunderstore rows" "$TS_AFTER"
LAST=$(sql "SELECT status FROM mod_sync_runs WHERE source='thunderstore' ORDER BY id DESC LIMIT 1" 2>/dev/null)
[ -n "$LAST" ] && echo "        last thunderstore run status: $LAST"

# --- the rows and edges must still be there -----------------------------------------------
[ "$(sql "SELECT COUNT(*) FROM mods WHERE id='$MODID' AND source='vibes'")" = "1" ] && ok "mods row survived the forced sync" || bad "mods row pruned by sync"
[ "$(sql "SELECT COUNT(*) FROM mod_versions WHERE id='$VID'")" = "1" ] && ok "mod_versions row survived" || bad "mod_versions row pruned"
EDGES_AFTER=$(sql "SELECT COUNT(*) FROM mod_deps WHERE version_id='$VID'")
[ "$EDGES_AFTER" = "2" ] && ok "mod_deps edges survived / were rebuilt from deps (2)" || bad "edges after sync" "$EDGES_AFTER"
RES=$(sql "SELECT m.source FROM mod_deps d JOIN mods m ON m.id=d.dep_mod_id WHERE d.version_id='$VID' AND d.dep_string='denikson-BepInExPack_Valheim-5.4.2202'")
[ "$RES" = "thunderstore" ] && ok "BepInExPack edge still resolved to thunderstore after the rebuild" || bad "edge resolution after sync" "$RES"
[ "$(sql "SELECT is_active FROM mod_versions WHERE id='$VID'")" = "1" ] && ok "version still active" || bad "is_active"

# --- unpublish through the real code; nothing may remain ------------------------------------
OUT=$(docker exec -i -u phvalheim "$C" php <<EOF 2>&1
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require '/opt/stateless/engine/tools/vibes/lib/catalog.php';
\$s = \$pdo->prepare("SELECT * FROM hv_projects WHERE slug=?"); \$s->execute(['$SLUG']); \$p = \$s->fetch(PDO::FETCH_ASSOC);
echo json_encode(vibesCatalogUnpublish(\$pdo, \$p, '$VER'));
EOF
)
echo "$OUT" | grep -q '"success":true' && echo "$OUT" | grep -q '"removedMod":true' && ok "unpublish of the only version removed the mods row too" || bad "unpublish" "$OUT"
[ "$(sql "SELECT COUNT(*) FROM mods WHERE id='$MODID'")" = "0" ] && ok "mods row gone" || bad "mods row remains"
[ "$(sql "SELECT COUNT(*) FROM mod_versions WHERE id='$VID'")" = "0" ] && ok "mod_versions row gone" || bad "mod_versions remains"
[ "$(sql "SELECT COUNT(*) FROM mod_deps WHERE version_id='$VID'")" = "0" ] && ok "mod_deps edges gone" || bad "edges remain"
docker exec "$C" test -e "/opt/stateful/vibes/catalog/$NS-$NAME-$VER.zip" && bad "catalog zip removed" || ok "catalog zip removed"
sql "SELECT status, published_version IS NULL FROM hv_projects WHERE slug='$SLUG'" | grep -q "^built	1$" && ok "hv_projects back to built with no published_version" || bad "hv_projects after unpublish" "$(sql "SELECT status, published_version FROM hv_projects WHERE slug='$SLUG'")"

echo; echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
