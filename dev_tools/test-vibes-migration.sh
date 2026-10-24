#!/bin/bash
# Oracle test: the 2.59 Hugin's Vibes schema and its gates (2.59).
# requires: container
#
# WHAT. dbUpdate_2.59.sh must create the three hv_* tables and the four settings columns, run
# twice without an error (every migration is re-run at every boot), and mark a job the previous
# engine left open as failed. includes/vibes.php must read a PARTIAL settings document over the
# defaults without losing either side, mask tokens for the browser, and leave a masked token
# unchanged on save. includes/modcatalog.php must offer the 'vibes' catalogue only while
# settings.vibesInstalled is 1 -- an uninstalled Vibes must not put an empty catalogue in the
# world mod picker.
#
# HOW. Drives the real migration script and the real PHP inside the container. Fixture rows are
# planted with a recognisable kind/source and removed on exit; the settings row is restored.

C="${1:-phvalheim-dev}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec -i "$C" mysql -N phvalheim -e "$1" 2>&1; }
phpc() { docker exec -i "$C" php -r "$1" 2>&1; }

docker exec "$C" true 2>/dev/null || { echo "HARNESS: container $C not running"; exit 2; }
docker exec "$C" test -f /opt/stateless/engine/dbUpdates/dbUpdate_2.59.sh \
	|| { echo "HARNESS: dbUpdate_2.59.sh is not in the container -- deploy it first"; exit 2; }

echo "=== Hugin's Vibes: 2.59 schema ==="

origInstalled=$(sql "SELECT vibesInstalled FROM settings LIMIT 1" 2>/dev/null)
origDoc=$(sql "SELECT IFNULL(vibesSettings,'') FROM settings LIMIT 1" 2>/dev/null)
cleanup() {
	sql "DELETE FROM hv_jobs WHERE kind='gc' AND error LIKE '%oracle%' OR (kind='gc' AND args LIKE '%oracle-fixture%')" >/dev/null
	[ -n "$origInstalled" ] && sql "UPDATE settings SET vibesInstalled=$origInstalled" >/dev/null
	if [ -n "$origDoc" ]; then
		docker exec -i "$C" mysql -N phvalheim -e "UPDATE settings SET vibesSettings='$(printf '%s' "$origDoc" | sed "s/'/''/g")'" >/dev/null 2>&1
	else
		sql "UPDATE settings SET vibesSettings=NULL" >/dev/null
	fi
}
trap cleanup EXIT

# --- the migration, twice ------------------------------------------------------------------
out1=$(docker exec "$C" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.59.sh 2>&1)
out2=$(docker exec "$C" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.59.sh 2>&1)
echo "$out2" | grep -qiE 'error|denied|syntax' \
	&& bad "second run is silent about errors" "$(echo "$out2" | grep -iE 'error|denied|syntax' | head -2)" \
	|| ok "migration re-runs without an error (idempotent)"
echo "$out2" | grep -q 'Creating table' \
	&& bad "second run must not create tables again" "$(echo "$out2" | grep 'Creating table')" \
	|| ok "second run creates nothing"

for t in hv_projects hv_sessions hv_jobs; do
	[ "$(sql "SHOW TABLES LIKE '$t'")" = "$t" ] && ok "table $t exists" || bad "table $t missing"
done
for col in vibesInstalled vibesBuildpakVersion vibesSettings vibesNoticeShown; do
	sql "DESCRIBE settings" | awk '{print $1}' | grep -qx "$col" && ok "settings.$col exists" || bad "settings.$col missing"
done

# The case-sensitive collation on the identity columns is load-bearing (CLAUDE.md): a mod
# published as MyMod and one as mymod are two catalogue rows and must be two projects.
coll=$(sql "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='phvalheim' AND TABLE_NAME='hv_projects' AND COLUMN_NAME='name'")
[ "$coll" = "utf8mb4_0900_as_cs" ] && ok "hv_projects.name is case-sensitive (utf8mb4_0900_as_cs)" || bad "hv_projects.name collation" "got '$coll'"

# --- a job left open by a dead engine is closed at the next boot ------------------------------
sql "INSERT INTO hv_jobs (kind, status, args) VALUES ('gc', 'running', '{\"oracle-fixture\":1}')" >/dev/null
jid=$(sql "SELECT MAX(id) FROM hv_jobs WHERE kind='gc' AND args LIKE '%oracle-fixture%'")
docker exec "$C" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.59.sh >/dev/null 2>&1
st=$(sql "SELECT status FROM hv_jobs WHERE id=$jid")
[ "$st" = "failed" ] && ok "a job still 'running' when the engine boots is marked failed" || bad "stale running job" "status is '$st'"
sql "DELETE FROM hv_jobs WHERE id=$jid" >/dev/null

# --- includes/vibes.php: partial document over defaults, masking, masked = unchanged ------------
sql "UPDATE settings SET vibesSettings='{\"schema\":1,\"agent\":{\"max_rounds\":7},\"github\":{\"token\":\"ghp_abcdef1234\",\"enabled\":true}}'" >/dev/null
r=$(phpc 'require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php"; require "/opt/stateless/nginx/www/includes/vibes.php";
$d = vibesSettingsGet($pdo);
echo vibesSetting($d, "agent.max_rounds"), "|", vibesSetting($d, "agent.turn_timeout_s"), "|", vibesSetting($d, "github.token"), "|", vibesSetting($d, "buildpak.repo"), "\n";
$c = vibesSettingsForClient($d); echo $c["github"]["token"], "\n";
[$n, $errs] = vibesSettingsApplyClient($d, ["github" => ["token" => $c["github"]["token"], "owner" => "someone"], "agent" => ["max_rounds" => 9]]);
echo $n["github"]["token"], "|", $n["github"]["owner"], "|", $n["agent"]["max_rounds"], "|", count($errs), "\n";
[$n2, $e2] = vibesSettingsApplyClient($d, ["github" => ["token" => ""]]); echo "[", $n2["github"]["token"], "]\n";
[$n3, $e3] = vibesSettingsApplyClient($d, ["author" => ["namespace" => "bad name!"]]); echo count($e3), "\n";')
l1=$(echo "$r" | sed -n 1p); l2=$(echo "$r" | sed -n 2p); l3=$(echo "$r" | sed -n 3p); l4=$(echo "$r" | sed -n 4p); l5=$(echo "$r" | sed -n 5p)
[ "$l1" = "7|900|ghp_abcdef1234|brianmiller/phvalheim-vibes-buildpak" ] && ok "partial document merges over defaults (7 kept, 900 and the repo filled in)" || bad "settings merge" "got '$l1'"
case "$l2" in *1234) [ "${l2#????}" = "1234" ] && [ "$l2" != "ghp_abcdef1234" ] && ok "token is masked for the browser ($l2)" || bad "mask shape" "got '$l2'";; *) bad "token masked" "got '$l2'";; esac
[ "$l3" = "ghp_abcdef1234|someone|9|0" ] && ok "saving the masked token back leaves the real token unchanged; other fields apply" || bad "masked = unchanged" "got '$l3'"
[ "$l4" = "[]" ] && ok "an empty token clears it" || bad "empty token clears" "got '$l4'"
[ "$l5" = "1" ] && ok "an invalid author namespace is refused with one error" || bad "namespace validation" "got '$l5'"

# --- the catalogue gate --------------------------------------------------------------------
sql "UPDATE settings SET vibesInstalled=0" >/dev/null
r0=$(phpc 'require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php"; require "/opt/stateless/nginx/www/includes/modcatalog.php"; $s = catalogSources($pdo); echo isset($s["vibes"]) ? ($s["vibes"]["enabled"] ? "on" : "off") : "absent";')
sql "UPDATE settings SET vibesInstalled=1" >/dev/null
r1=$(phpc 'require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php"; require "/opt/stateless/nginx/www/includes/modcatalog.php"; $s = catalogSources($pdo); echo isset($s["vibes"]) ? ($s["vibes"]["enabled"] ? "on" : "off") : "absent"; echo "|", $s["vibes"]["label"] ?? "", "|", $s["vibes"]["colour"] ?? "";')
[ "$r0" = "off" ] && ok "catalogSources: 'vibes' is OFF while Vibes is not installed" || bad "vibes source gate (uninstalled)" "got '$r0'"
[ "$r1" = "on|Hugin's Vibes|vibes" ] && ok "catalogSources: 'vibes' is ON, labelled Hugin's Vibes, colour 'vibes', once installed" || bad "vibes source gate (installed)" "got '$r1'"
grep -q '^\.src-pill\.src-vibes' "$(dirname "$0")/../container/nginx/www/css/phvalheimStyles.css" && ok "the 'vibes' pill has a CSS rule" || bad "pill css rule missing"

# --- job serialisation (rows only; no process is launched) -----------------------------------
sql "INSERT INTO hv_jobs (kind, project_id, status, args) VALUES ('build', 424242, 'running', '{\"oracle-fixture\":1}')" >/dev/null
jid=$(sql "SELECT MAX(id) FROM hv_jobs WHERE kind='build' AND project_id=424242")
r=$(phpc 'require "/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php"; require "/opt/stateless/nginx/www/includes/vibes.php";
echo vibesJobConflict($pdo, "build", 424242) ? "same-project-blocked" : "same-project-free", "|";
echo vibesJobConflict($pdo, "build", 424243) ? "other-project-blocked" : "other-project-free", "|";
echo vibesJobConflict($pdo, "install", null) ? "global-blocked" : "global-free";')
[ "$r" = "same-project-blocked|other-project-free|global-blocked" ] && ok "job serialisation: one per project; install waits for everything" || bad "job conflict rules" "got '$r'"
sql "DELETE FROM hv_jobs WHERE id=$jid" >/dev/null

echo "=== $pass passed, $fail failed ==="
[ "$fail" = "0" ]
