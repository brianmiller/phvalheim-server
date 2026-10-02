#!/bin/sh
# Oracle test: serverblankpassword retirement, and WHEN a modded world gets its password.
#
# The timing is the whole point of this file, and it shipped wrong once.
#
# The first cut generated a password for every modded world in dbUpdate_2.53.sh -- at UPGRADE.
# That password protects the world at its next RESTART, while it is still running QuickConnect
# and its players are still on a client that cannot forward a password. Every player is locked
# out of a world the operator has not touched, and it contradicts the promise 2.53 makes
# everywhere else: nothing changes until you update a world. Brian hit it upgrading 2.52 -> 2.53.
#
# So there are two populations and they must not be confused:
#   - the mod ROW is deleted at upgrade. Safe: the row only decides what the next REBUILD
#     installs, so a world that merely restarts keeps the plugin files it already has.
#   - the PASSWORD is generated at update, by ensureModdedWorldPassword(), so it lands with
#     everything else that update changes.
#
# Usage:  sh dev_tools/test-blankpassword-retirement.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
MIG="$REPO/container/engine/dbUpdates/dbUpdate_2.53.sh"
FUNCS="$REPO/container/engine/includes/0-functions.sh"
ENGINE="$REPO/container/engine/phvalheim"
PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== part 1: the migration retires the mod and does NOT touch passwords =="

BLOCK=/tmp/phv-blankpw-block.sh
awk '/^# --- serverblankpassword is retired/{f=1} f{print} /^## END UPDATE ##$/{exit}' "$MIG" \
  | grep -v '^## END UPDATE ##$' > "$BLOCK"
[ -s "$BLOCK" ] || { echo "FATAL: could not extract the retirement block from $MIG"; exit 1; }

run_block() {
	HAS_COLUMN="$1"; FLAG="$2"; BLANK_ROWS="$3"; WORLDS="$4"
	SQLLOG=/tmp/phv-blankpw-sql.log
	: > "$SQLLOG"
	export HAS_COLUMN FLAG BLANK_ROWS WORLDS SQLLOG
	sh -c '
		sql() {
			printf "%s\n" "$1" >> "$SQLLOG"
			case "$1" in
				"DESCRIBE settings")
					echo "id int"; echo "quickConnectRetired tinyint"
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

run_block 0 0 3 "midgard acltest"

grep -q 'ALTER TABLE settings ADD COLUMN blankPasswordRetired' "$SQLLOG" \
	&& pass "adds settings.blankPasswordRetired when absent" || fail "no ALTER for the flag column"

grep -q "DELETE wm FROM world_mods wm JOIN mods m ON m.id = wm.mod_id WHERE m.owner = '1010101110' AND m.name = 'serverblankpassword'" "$SQLLOG" \
	&& pass "deletes the rows scoped to BOTH owner and name" \
	|| fail "the DELETE is missing or not owner-scoped" \
	        "mod identity is (source, owner, name) -- matching on name alone can collapse another owner's package"

# THE REGRESSION. Two modded worlds with no password went in; the migration must write NO
# password for either. This is the assertion the shipped bug would have failed.
pwWrites=$(grep -c 'UPDATE worlds SET password' "$SQLLOG")
[ "$pwWrites" = "0" ] \
	&& pass "the migration writes NO password (generation belongs to the update path)" \
	|| fail "the migration wrote $pwWrites password(s) at UPGRADE" \
	        "this locks players out of worlds still on QuickConnect, with clients that cannot send one"

grep -q 'UPDATE settings SET blankPasswordRetired = 1' "$SQLLOG" \
	&& pass "sets the one-time flag" || fail "the flag is never set"

run_block 1 1 3 "midgard acltest"
grep -q 'DELETE wm FROM world_mods' "$SQLLOG" \
	&& fail "a second run still deleted rows" "this must be one-time" \
	|| pass "a second run writes nothing (one-time, like the QuickConnect retirement)"

run_block 1 0 0 ""
grep -q 'UPDATE settings SET blankPasswordRetired = 1' "$SQLLOG" \
	&& pass "sets the flag even when it found nothing" \
	|| fail "no flag set on a no-op run" "the block would re-check on every boot forever"
grep -q 'DELETE wm FROM world_mods' "$SQLLOG" \
	&& fail "issued a DELETE with zero matching rows" || pass "skips the DELETE when no rows match"

echo
echo "== part 2: ensureModdedWorldPassword(), which runs at UPDATE =="

grep -q '^function ensureModdedWorldPassword()' "$FUNCS" \
	&& pass "ensureModdedWorldPassword() exists in 0-functions.sh" \
	|| { fail "ensureModdedWorldPassword() is missing"; echo; echo "$PASS passed, $FAIL failed"; exit 1; }

# Extract the REAL function and drive it with SQL() stubbed.
FN=/tmp/phv-ensurepw.sh
awk '/^function ensureModdedWorldPassword\(\)/{f=1} f{print} f&&/^}$/{exit}' "$FUNCS" > "$FN"
grep -q 'UPDATE worlds SET password' "$FN" || { echo "FATAL: extraction is stale"; exit 1; }

# $1=vanilla $2=existing password
call_fn() {
	FN_VANILLA="$1"; FN_PW="$2"; FNLOG=/tmp/phv-ensurepw-sql.log
	: > "$FNLOG"
	export FN_VANILLA FN_PW FNLOG
	# bash, not sh. 0-functions.sh declares `function name()`, which dash rejects outright --
	# and the first cut of this harness used sh, so the function never ran at all. The two
	# "is untouched" controls below PASSED on that, because nothing writing a password looks
	# identical to the right thing not writing one. Hence the did-it-run check after each call.
	bash -c '
		SQL() {
			printf "%s\n" "$1" >> "$FNLOG"
			case "$1" in
				*"IFNULL(vanilla,0)"*)  echo "$FN_VANILLA" ;;
				*"IFNULL(password,"*)   echo "$FN_PW" ;;
				*) : ;;
			esac
		}
		. "$1"
		ensureModdedWorldPassword testworld
	' _ "$FN" > /tmp/phv-ensurepw-out.log 2>&1
}

# The case that must act.
call_fn 0 ""
written=$(grep 'UPDATE worlds SET password' "$FNLOG" | sed "s/.*password = '\([^']*\)'.*/\1/")
if [ -n "$written" ]; then
	pass "a modded world with no password GETS one"
	len=$(printf '%s' "$written" | wc -c | tr -d ' ')
	[ "$len" = "16" ] && pass "it is 16 characters ($written)" || fail "wrong length: $len ($written)"
	printf '%s' "$written" | grep -q '^[A-HJ-NP-Za-km-z2-9]\{16\}$' \
		&& pass "from the unambiguous alphabet, so no '?' can reach the positional payload" \
		|| fail "wrong alphabet: $written"
else
	fail "a modded world with no password got nothing"
fi

# Two calls must not produce the same password -- a shared one is a single credential for the
# whole server, and the code reads identically either way.
call_fn 0 ""
second=$(grep 'UPDATE worlds SET password' "$FNLOG" | sed "s/.*password = '\([^']*\)'.*/\1/")
[ -n "$second" ] && [ "$written" != "$second" ] \
	&& pass "each call generates a DIFFERENT password" \
	|| fail "two calls produced the same password ($written / $second)"

# THE CONTROLS. Without these, a function that writes a password unconditionally passes above.
#
# ran() guards them, and it is not belt-and-braces. Both are "nothing was written" assertions,
# so a harness that never invokes the function satisfies both -- which is precisely what
# happened when this ran under dash and the `function name()` syntax was rejected. Proving the
# function executed is what makes its silence mean anything.
ran() {
	[ -s "$FNLOG" ] && return 0
	fail "$1 -- THE FUNCTION DID NOT RUN (no SQL at all)" \
	     "$(head -1 /tmp/phv-ensurepw-out.log); the check below would pass on silence"
	return 1
}

call_fn 0 "alreadySet123"
if ran "existing-password control"; then
	grep -q 'UPDATE worlds SET password' "$FNLOG" \
		&& fail "overwrote an existing password" "an operator's own password must survive an update" \
		|| pass "a modded world that ALREADY has a password is untouched"
fi

call_fn 1 ""
if ran "vanilla control"; then
	grep -q 'UPDATE worlds SET password' "$FNLOG" \
		&& fail "gave a VANILLA world a password" \
		   "vanilla worlds have had a real password column since 2.40; empty there is a choice" \
		|| pass "a vanilla world with no password is untouched"
fi

echo
echo "== part 3: the engine calls it, in the right place =="

grep -q 'ensureModdedWorldPassword "\$worldName"' "$ENGINE" \
	&& pass "the engine calls ensureModdedWorldPassword in the update path" \
	|| fail "nothing calls ensureModdedWorldPassword -- no world would ever get a password"

# It has to run before $worldPassword is consumed, or the QuickConnect cfg (still written when
# the Companion cannot connect) would carry the OLD empty value for the world that just got one.
callLine=$(grep -n 'ensureModdedWorldPassword "\$worldName"' "$ENGINE" | head -1 | cut -d: -f1)
qcLine=$(grep -n 'createQuickConnectConfig "\$worldName"' "$ENGINE" | head -1 | cut -d: -f1)
if [ -n "$callLine" ] && [ -n "$qcLine" ]; then
	[ "$callLine" -lt "$qcLine" ] \
		&& pass "it runs BEFORE the QuickConnect cfg is written (line $callLine < $qcLine)" \
		|| fail "it runs after createQuickConnectConfig (line $callLine > $qcLine)" \
		        "the cfg would be written with the password the world had before this update"
fi

# And the generated value must be re-read, or everything downstream uses the stale empty one.
grep -q 'worldPassword=\$(SQL "SELECT IFNULL(password' "$ENGINE" \
	&& pass "worldPassword is re-read after generation" \
	|| fail "worldPassword is not re-read -- downstream still sees the pre-update value"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
