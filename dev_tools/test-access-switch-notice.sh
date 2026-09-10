#!/bin/bash
# Oracle test for the one-time "the access switch was renamed and inverted" notice.
#
# THE TRAP: dbUpdater.sh has NO version gate -- every script in dbUpdates/ is executed on
# EVERY container boot. So an arming step written as a plain UPDATE would set the flag back
# to 0 on every restart and the notice could never be dismissed. The arming is therefore
# guarded on the COLUMN NOT EXISTING YET, which is the only durable "this database has not
# seen 2.40 before" signal available here.
#
# Case 3 is the one that matters: re-running the same block against a database that already
# has the column must do NOTHING. A test that only checked "does a fresh upgrade arm it?"
# passes just as happily on a migration that re-arms forever.
#
# The block is driven with a stubbed sql() so every statement it would issue is observable.
# Asserting "the script exited 0" would pass in all four arms.
#
# Usage: dev_tools/test-access-switch-notice.sh

pass=0; fail=0
check () { # $1=name $2=ok $3=detail
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
MIG="$(dirname "$0")/../container/engine/dbUpdates/dbUpdate_2.40.sh"

# Pull just the arming block out of the migration.
awk '/one-time notice: the world access switch was RENAMED/,/^fi$/' "$MIG" > "$TMP/block.sh"
[ -s "$TMP/block.sh" ] || { echo "could not extract the arming block from $MIG"; exit 1; }
grep -q "accessSwitchNoticeShown" "$TMP/block.sh" || { echo "extracted block looks wrong"; exit 1; }

# $1 = "present"/"absent" (does settings.accessSwitchNoticeShown already exist)
# $2 = world count
# prints every SQL statement the block issues
run_case () {
	local colState="$1" worlds="$2"
	cat > "$TMP/drive.sh" <<EOF
sql () {
  case "\$1" in
    "DESCRIBE settings")
        echo "id"
        echo "setupComplete"
        $( [ "$colState" = "present" ] && echo 'echo "accessSwitchNoticeShown"' )
        ;;
    "SELECT COUNT(*) FROM worlds") echo "$worlds" ;;
    *) echo "SQLWRITE:\$1" >&2 ;;
  esac
}
$(cat "$TMP/block.sh")
EOF
	bash "$TMP/drive.sh" 2>&1 >/dev/null | grep '^SQLWRITE:' || true
}

echo
echo "Case 1: UPGRADE, first run -- adds the column AND arms the notice"
OUT=$(run_case absent 4)
echo "$OUT" | grep -q "ADD COLUMN accessSwitchNoticeShown" && ok=1 || ok=0
check "adds the column" "$ok" "${OUT:-<nothing>}"
echo "$OUT" | grep -q "SET accessSwitchNoticeShown = 0" && ok=1 || ok=0
check "arms the notice for an upgrader" "$ok" "${OUT:-<nothing>}"

echo
echo "Case 2: FRESH INSTALL (no worlds) -- adds the column, does NOT arm"
OUT=$(run_case absent 0)
echo "$OUT" | grep -q "ADD COLUMN accessSwitchNoticeShown" && ok=1 || ok=0
check "still adds the column" "$ok" "${OUT:-<nothing>}"
echo "$OUT" | grep -q "SET accessSwitchNoticeShown = 0" && ok=0 || ok=1
check "does NOT nag a fresh install about a change it never saw" "$ok" "${OUT:-<nothing>}"

echo
echo "Case 3: SECOND BOOT (column already there) -- must do NOTHING"
# dbUpdater.sh runs every script on every boot. If this arms again, the notice
# reappears after every restart and can never be dismissed.
OUT=$(run_case present 4)
[ -z "$OUT" ] && ok=1 || ok=0
check "no re-arming on a later boot" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 4: CONTROL -- the arm and no-arm paths actually differ"
# If sql() were stubbed wrong, or the block silently did nothing in every arm,
# every assertion above could pass by accident.
A=$(run_case absent 4); B=$(run_case present 4)
[ -n "$A" ] && [ "$A" != "$B" ] && ok=1 || ok=0
check "first boot and second boot are not the same" "$ok" "first=[$A] second=[$B]"

echo
echo "Case 5: the wiring exists end to end"
ROOT="$(dirname "$0")/.."
grep -q 'accessSwitchNoticeShown' "$ROOT/container/nginx/www/includes/config_env_puller.php" && ok=1 || ok=0
check "config_env_puller exposes the flag" "$ok"
grep -q "case 'dismissAccessSwitchNotice'" "$ROOT/container/nginx/www/admin/adminAPI.php" && ok=1 || ok=0
check "adminAPI routes the dismissal" "$ok"
grep -q 'SET accessSwitchNoticeShown = 1' "$ROOT/container/nginx/www/admin/adminAPI.php" && ok=1 || ok=0
check "...and the dismissal actually clears it" "$ok"

echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
