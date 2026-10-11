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
#   - the PASSWORD is generated for a NEW world only (2.55), by ensureNewWorldPassword().
#
# 2.55 moved that second one AGAIN, and this file is the reason it cannot drift back. The 2.53
# fix put generation in the UPDATE path, which is narrower than the migration and still wrong:
# create ends with `mode='update'`, so the single call site served new worlds AND every rebuild
# of an existing one -- a mod-list save, the Rebuild Mods button, Hugin. An operator running
# deliberately without a password got one imposed the first time they touched that world's
# mods. Brian: "We need to stop doing this and allow the operator to make the decision. New
# worlds--fine, but not existing."
#
# The assertions that carry that are part 2's packaged-world control and part 3's "the update
# block does not call it". Both are "nothing happened" claims, so both are paired with proof
# that the thing which must stay silent was actually reached.
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
	&& pass "the migration writes NO password (only a NEW world gets one, at create)" \
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
echo "== part 2: ensureNewWorldPassword(), which runs for a NEW world only =="

grep -q '^function ensureNewWorldPassword()' "$FUNCS" \
	&& pass "ensureNewWorldPassword() exists in 0-functions.sh" \
	|| { fail "ensureNewWorldPassword() is missing"; echo; echo "$PASS passed, $FAIL failed"; exit 1; }

# Extract the REAL function and drive it with SQL() stubbed.
FN=/tmp/phv-ensurepw.sh
awk '/^function ensureNewWorldPassword\(\)/{f=1} f{print} f&&/^}$/{exit}' "$FUNCS" > "$FN"
grep -q 'UPDATE worlds SET password' "$FN" || { echo "FATAL: extraction is stale"; exit 1; }

# $1=vanilla $2=existing password $3=world_md5 (non-empty = already published to players)
call_fn() {
	FN_VANILLA="$1"; FN_PW="$2"; FN_MD5="$3"; FNLOG=/tmp/phv-ensurepw-sql.log
	: > "$FNLOG"
	export FN_VANILLA FN_PW FN_MD5 FNLOG
	# bash, not sh. 0-functions.sh declares `function name()`, which dash rejects outright --
	# and the first cut of this harness used sh, so the function never ran at all. The two
	# "is untouched" controls below PASSED on that, because nothing writing a password looks
	# identical to the right thing not writing one. Hence the did-it-run check after each call.
	bash -c '
		SQL() {
			printf "%s\n" "$1" >> "$FNLOG"
			case "$1" in
				*"IFNULL(vanilla,0)"*)   echo "$FN_VANILLA" ;;
				*"IFNULL(world_md5,"*)   echo "$FN_MD5" ;;
				*"IFNULL(password,"*)    echo "$FN_PW" ;;
				*) : ;;
			esac
		}
		. "$1"
		ensureNewWorldPassword testworld
	' _ "$FN" > /tmp/phv-ensurepw-out.log 2>&1
}

# The case that must act: a brand new world, never packaged, no password on the form.
call_fn 0 "" ""
written=$(grep 'UPDATE worlds SET password' "$FNLOG" | sed "s/.*password = '\([^']*\)'.*/\1/")
if [ -n "$written" ]; then
	pass "a NEW modded world with no password GETS one"
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
call_fn 0 "" ""
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

call_fn 0 "alreadySet123" ""
if ran "existing-password control"; then
	grep -q 'UPDATE worlds SET password' "$FNLOG" \
		&& fail "overwrote an existing password" "an operator's own password must survive an update" \
		|| pass "a modded world that ALREADY has a password is untouched"
fi

call_fn 1 "" ""
if ran "vanilla control"; then
	grep -q 'UPDATE worlds SET password' "$FNLOG" \
		&& fail "gave a VANILLA world a password" \
		   "vanilla worlds have had a real password column since 2.40; empty there is a choice" \
		|| pass "a vanilla world with no password is untouched"
fi

# THE 2.55 CONTROL. An ESTABLISHED world -- one that has been packaged, so players may hold its
# payload -- must come away with nothing even though its password is empty and it is modded.
# This is the exact row the 2.53 code wrote to, and the only difference between the two
# behaviours is this single case: every other assertion in this file passes either way.
call_fn 0 "" "d41d8cd98f00b204e9800998ecf8427e"
if ran "already-published control"; then
	if grep -q 'UPDATE worlds SET password' "$FNLOG"; then
		fail "gave a PUBLISHED world a password" \
		     "its players already hold a payload; a password here is the operator's decision, not ours"
	else
		pass "a world already published to players is untouched, even with an empty password"
		grep -q 'IFNULL(world_md5,' "$FNLOG" \
			&& pass "and it reached that decision by ASKING (world_md5 was queried)" \
			|| fail "it never queried world_md5 -- it stayed silent for some other reason" \
			        "this control cannot distinguish the guard from an unrelated early return"
	fi
fi

echo
echo "== part 3: the engine calls it from CREATE, and NOT from update =="

grep -q 'ensureNewWorldPassword "\$worldName"' "$ENGINE" \
	&& pass "the engine calls ensureNewWorldPassword" \
	|| fail "nothing calls ensureNewWorldPassword -- no new world would ever get a password"

# WHERE it is called is the entire 2.55 fix, so locate the two mode blocks and place the call.
# Line numbers, not "is it in the file": the 2.53 bug was a correct function at the wrong site.
createLine=$(grep -n 'if \[ "\$worldMode" = "create" \]' "$ENGINE" | head -1 | cut -d: -f1)
updateLine=$(grep -n 'if \[ "\$worldMode" = "update" \]' "$ENGINE" | head -1 | cut -d: -f1)
callLine=$(grep -n 'ensureNewWorldPassword "\$worldName"' "$ENGINE" | head -1 | cut -d: -f1)
if [ -n "$createLine" ] && [ -n "$updateLine" ] && [ -n "$callLine" ]; then
	if [ "$callLine" -gt "$createLine" ] && [ "$callLine" -lt "$updateLine" ]; then
		pass "the call sits in the CREATE block (line $createLine < $callLine < $updateLine)"
	else
		fail "the call is outside the create block (create $createLine, call $callLine, update $updateLine)" \
		     "anywhere at or after $updateLine and every rebuild of an existing world generates a password again"
	fi
else
	fail "could not locate the create/update mode blocks or the call" \
	     "create=$createLine update=$updateLine call=$callLine -- this check is blind, treat it as failing"
fi

# The regression itself, stated directly: no generation call anywhere from the update block on.
tail -n +"$updateLine" "$ENGINE" | grep -q 'ensureNewWorldPassword\|ensureModdedWorldPassword' \
	&& fail "a password-generating call still exists at or below the update block" \
	        "that is the 2.53 behaviour Brian asked to stop: it fires on every mod-list save and Rebuild Mods" \
	|| pass "nothing from the update block onwards generates a password"

# It must run before the supervisor config consumes $worldPassword, and the value must be
# re-read -- otherwise the new world is deployed with the empty password it had a moment ago.
supLine=$(grep -n 'createSupervisorWorldConfig "\$worldName" "\$worldPassword"' "$ENGINE" | head -1 | cut -d: -f1)
if [ -n "$callLine" ] && [ -n "$supLine" ]; then
	[ "$callLine" -lt "$supLine" ] \
		&& pass "it runs BEFORE createSupervisorWorldConfig (line $callLine < $supLine)" \
		|| fail "it runs after createSupervisorWorldConfig (line $callLine > $supLine)" \
		        "the supervisor config would carry the empty password the world had at create"
fi

awk -v c="$callLine" 'NR>c && NR<=c+3 && /worldPassword=\$\(SQL "SELECT IFNULL\(password/{found=1} END{exit !found}' "$ENGINE" \
	&& pass "worldPassword is re-read immediately after generation" \
	|| fail "worldPassword is not re-read next to the call -- downstream sees the pre-create value"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
