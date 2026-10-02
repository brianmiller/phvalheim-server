#!/bin/sh
# Oracle test: the one-time serverblankpassword retirement in dbUpdate_2.53.sh.
#
# This block WRITES PASSWORDS to live worlds on an operator's upgrade, and it runs exactly once
# -- there is no second chance to get it right and no way to tell afterwards whether a world was
# skipped or given something unusable. Nothing else in the test suite executes it, because
# migrations only run inside a container against a real MariaDB.
#
# So: run the real block with sql() stubbed, and assert on the statements it emits.
#
# What it must do:
#   - add settings.blankPasswordRetired when absent, and not when present
#   - delete serverblankpassword world_mods rows scoped to OWNER AND name
#   - generate a 16-char password for every MODDED world that has none
#   - leave vanilla worlds alone, and leave modded worlds that already have one alone
#   - set the flag once, whether or not it found anything
#   - do nothing at all on a second run
#
# Usage:  sh dev_tools/test-blankpassword-retirement.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
MIG="$REPO/container/engine/dbUpdates/dbUpdate_2.53.sh"
PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

# Extract the REAL block rather than restating it here. A copy would keep passing after someone
# edited the shipped migration, which is the only failure this test exists to catch.
BLOCK=/tmp/phv-blankpw-block.sh
awk '/^# --- serverblankpassword is retired/{f=1} f{print} /^## END UPDATE ##$/{exit}' "$MIG" \
  | grep -v '^## END UPDATE ##$' > "$BLOCK"
[ -s "$BLOCK" ] || { echo "FATAL: could not extract the retirement block from $MIG"; exit 1; }
grep -q 'UPDATE worlds SET password' "$BLOCK" || {
	echo "FATAL: extracted block has no password generation -- extraction is stale"; exit 1; }

# The harness. sql() answers from scripted state and logs every statement.
run_block() {
	HAS_COLUMN="$1"; FLAG="$2"; BLANK_ROWS="$3"; WORLDS="$4"
	SQLLOG=/tmp/phv-blankpw-sql.log
	: > "$SQLLOG"
	export HAS_COLUMN FLAG BLANK_ROWS WORLDS SQLLOG
	# `date` is used for log lines only; leave the real one alone.
	sh -c '
		sql() {
			printf "%s\n" "$1" >> "$SQLLOG"
			case "$1" in
				"DESCRIBE settings")
					echo "id int"
					echo "quickConnectRetired tinyint"
					[ "$HAS_COLUMN" = "1" ] && echo "blankPasswordRetired tinyint" ;;
				*"MAX(blankPasswordRetired)"*) echo "$FLAG" ;;
				*"COUNT(*) FROM world_mods"*)  echo "$BLANK_ROWS" ;;
				*"SELECT name FROM worlds"*)   printf "%s\n" $WORLDS ;;
				*) : ;;
			esac
		}
		. "$1"
	' _ "$BLOCK" > /tmp/phv-blankpw-out.log 2>&1
}

echo
echo "serverblankpassword retirement (dbUpdate_2.53.sh)"
echo

# --- 1. fresh upgrade: column absent, flag 0, rows present, two modded worlds ----------
run_block 0 0 3 "midgard acltest"

grep -q 'ALTER TABLE settings ADD COLUMN blankPasswordRetired' "$SQLLOG" \
	&& pass "adds settings.blankPasswordRetired when absent" \
	|| fail "no ALTER for the flag column" "the flag read below would then be empty and the guard would misfire"

if grep -q "DELETE wm FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.owner = '1010101110' AND m.name = 'serverblankpassword'" "$SQLLOG"; then
	pass "deletes the rows scoped to BOTH owner and name"
else
	fail "the DELETE is missing or not owner-scoped" \
	     "mod identity here is (source, owner, name) -- matching on name alone can collapse another owner's package"
fi

# Two worlds in, two UPDATEs out. A loop that silently handled only the first is the failure
# this counts rather than assumes.
updates=$(grep -c '^UPDATE worlds SET password' "$SQLLOG")
[ "$updates" = "2" ] && pass "generates a password for EVERY modded world with none (2/2)" \
	|| fail "expected 2 password UPDATEs, got $updates"

# The generated value itself: 16 characters, and from the unambiguous alphabet. A short read
# from /dev/urandom would otherwise store something under Valheim's 5-character minimum, and the
# world would refuse to boot the moment it was listed.
bad=0; seen=''
for pw in $(grep '^UPDATE worlds SET password' "$SQLLOG" | sed "s/.*password = '\([^']*\)'.*/\1/"); do
	len=$(printf '%s' "$pw" | wc -c | tr -d ' ')
	[ "$len" = "16" ] || bad=1
	printf '%s' "$pw" | grep -q '^[A-HJ-NP-Za-km-z2-9]\{16\}$' || bad=1
	seen="$seen $pw"
done
[ "$bad" = "0" ] && pass "each generated password is 16 chars from the unambiguous alphabet ($seen)" \
	|| fail "a generated password was the wrong length or alphabet" "got:$seen"

# ...and the two must DIFFER. One password shared by every world would be a single credential
# for the whole server, and the loop reads identically either way.
a=$(echo $seen | cut -d' ' -f1); b=$(echo $seen | cut -d' ' -f2)
[ -n "$a" ] && [ "$a" != "$b" ] && pass "each world gets a DIFFERENT password" \
	|| fail "both worlds got the same password" "got:$seen"

# No '?' ever, because the launch payload is '?'-delimited and positional with the password at
# field 2. Guaranteed by the alphabet, asserted because the alphabet is editable.
case "$seen" in *\?*) fail "a generated password contains '?'" "it would shift every later payload field" ;;
	*) pass "no generated password contains '?'" ;; esac

grep -q 'UPDATE settings SET blankPasswordRetired = 1' "$SQLLOG" \
	&& pass "sets the one-time flag" || fail "the flag is never set" "the block would re-run on every boot"

# The world query must be scoped to MODDED worlds with NO password. A vanilla world whose
# operator deliberately left the password blank must not be given one.
if grep -q "SELECT name FROM worlds WHERE IFNULL(vanilla,0) = 0 AND IFNULL(password,'') = ''" "$SQLLOG"; then
	pass "only modded worlds with no password are selected (NULL and '' both count)"
else
	fail "the world selection is not scoped to modded + passwordless" \
	     "$(grep 'SELECT name FROM worlds' "$SQLLOG")"
fi

# --- 2. already retired: the flag makes it one-time ------------------------------------
run_block 1 1 3 "midgard acltest"
if grep -q 'DELETE wm FROM world_mods' "$SQLLOG" || grep -q '^UPDATE worlds SET password' "$SQLLOG"; then
	fail "a second run still deleted rows or wrote passwords" \
	     "this must be one-time: nothing in the schema distinguishes PhValheim inserting the mod from the operator picking it"
else
	pass "a second run writes nothing (one-time, like the QuickConnect retirement)"
fi
grep -q 'ALTER TABLE settings ADD COLUMN blankPasswordRetired' "$SQLLOG" \
	&& fail "re-added the flag column that already exists" \
	|| pass "does not re-add the existing flag column"

# --- 3. nothing to do: no rows, no passwordless modded worlds --------------------------
# The flag must still be set, or a server with no modded worlds re-checks forever.
run_block 1 0 0 ""
grep -q 'UPDATE settings SET blankPasswordRetired = 1' "$SQLLOG" \
	&& pass "sets the flag even when it found nothing" \
	|| fail "no flag set on a no-op run" "the block would re-check on every boot forever"
[ "$(grep -c '^UPDATE worlds SET password' "$SQLLOG")" = "0" ] \
	&& pass "writes no password when there is no world needing one" \
	|| fail "wrote a password with no worlds to write for"
grep -q 'DELETE wm FROM world_mods' "$SQLLOG" \
	&& fail "issued a DELETE with zero matching rows" \
	|| pass "skips the DELETE when no rows match (the NOTICE stays quiet)"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
