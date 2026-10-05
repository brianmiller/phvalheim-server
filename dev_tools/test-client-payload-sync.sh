#!/bin/bash
# Oracle tests for the 2.55 config-only client payload (packageClientConfig + the sync contract).
#
# THE BUGS THESE CATCH
#
# 1. AN OLD CLIENT IN A PERMANENT RE-DOWNLOAD LOOP. This is the one that decides the whole
#    design. Syncer.cs compares the server's world_md5 against getMD5() of the payload file ON
#    DISK, so world_md5 must always equal the md5 of the real <world>.zip. The tempting way to
#    make old clients notice a config change -- making world_md5 a composite of both archives
#    -- means an old client downloads the zip, hashes what it got, still disagrees, and tries
#    again on EVERY launch forever. T1 asserts the identity. No assertion about the new client
#    could ever see this, which is why it is first.
#
# 2. ZIPPING THE WRONG TREE. The config archive must come from the CLIENT STAGING tree, not
#    from game/. Build it from game/ and every server_only value -- an API key, a webhook URL --
#    ships to every player. T3 asserts a server_only value present in game/ is ABSENT from the
#    archive, and carries a control: a normal value in the same run must be PRESENT, or the
#    test would pass identically against a function that zipped nothing at all.
#
# 3. THE TWO CHECKSUMS DESCRIBING DIFFERENT GENERATIONS. A client told "full payload = X,
#    config = Y" assumes one state of the tree. T4 asserts the config bytes inside the FULL
#    payload are byte-identical to the config archive's, which is the only check that can see
#    the two drifting apart.
#
# 4. A FAILED REBUILD DESTROYING A GOOD ARCHIVE. The obvious `rm -f` then `zip` loses the
#    previous archive the moment zip fails, and the stored checksum then points at a file that
#    is not there -- so every client asks for a 404 instead of falling back. T5 asserts a
#    failed rebuild leaves BOTH the old archive and its checksum intact.
#
# 5. CLEARING vs KEEPING, confused. Those are two different outcomes: a world with no staging
#    config dir must CLEAR the checksum (so clients fall back), while a world whose zip merely
#    failed must KEEP it. T6 asserts the clear, and only passes alongside T5's keep -- a
#    function that always cleared, or always kept, fails one of them.
#
# 6. THE ARCHIVE QUIETLY CONTAINING THE WHOLE PAYLOAD. The entire point is that it is small.
#    T2 asserts the plugins tree is absent, because a glob that accidentally matched ./BepInEx
#    instead of ./BepInEx/config would still produce a working archive -- just a useless one,
#    and nothing about correctness would reveal it.
#
# Runs entirely locally against a temporary tree. No container and no database: SQL() is
# stubbed to a log so what WOULD be written is asserted directly.
#
# Usage: dev_tools/test-client-payload-sync.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
REPO="$(pwd)"

# Every path below is absolute, and packageClientConfig is only ever called inside a subshell.
#
# Both because the function ends up INSIDE the staging tree: it cds there to zip, and does not
# come back -- exactly as packageClient() has always done, which is why the engine's cwd after
# an update is the client staging directory. Called directly from this script, the next
# build_tree() would rm -rf the directory the shell was sitting in and every relative path
# afterwards failed with ESTALE. The subshell confines the cd; the stubbed SQL() still appends
# to the same log file, so nothing is lost by the isolation.
pkgcfg() { ( packageClientConfig "$1" > /dev/null 2>&1 ); }

FUNCS="container/engine/includes/0-functions.sh"
[ -f "$FUNCS" ] || { echo "FAIL: $FUNCS not found"; exit 1; }

PASS=0
FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check(){ # check <description> <expected> <actual>
	if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (want '$2', got '$3')"; fi
}

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# ---- stubs -----------------------------------------------------------------------------
# phvalheim-static.conf does not exist outside the container, so sourcing 0-functions.sh
# prints one error and carries on; every function we need is defined regardless. Redirected so
# it does not read as a test failure.
worldsDirectoryRoot="$TMP/worlds"
SQL_LOG="$TMP/sql.log"
: > "$SQL_LOG"

source "$FUNCS" > /dev/null 2>&1

# Defined AFTER the source so these win: bash takes the last definition.
SQL() { echo "$*" >> "$SQL_LOG"; }
# Keep the real getMD5 and the real clientStagingRoot -- they are md5sum and a path join, and
# stubbing them would mean testing the stubs.

WORLD="OracleWorld"
WROOT="$worldsDirectoryRoot/$WORLD"
STAGE="$WROOT/client"
GAMEDIR="$WROOT/game"
CFGZIP="$WROOT/$WORLD-config.zip"
FULLZIP="$WROOT/$WORLD.zip"

build_tree() {
	rm -rf "$WROOT"
	mkdir -p "$STAGE/BepInEx/config" "$STAGE/BepInEx/plugins/BigMod" "$GAMEDIR/BepInEx/config"

	# A client-destined value, and a second key left at its default as the control.
	cat > "$STAGE/BepInEx/config/Azumatt.AzuClock.cfg" <<'EOF'
[1 - General]

## Show the clock
# Setting type: Boolean
# Default value: On
Show Clock = Off

## Clock font size
# Setting type: Int32
# Default value: 24
Clock Font Size = 24
EOF

	# The loader config rides along in the real tree, so it must here too.
	printf '[Logging.Console]\n\nEnabled = true\n' > "$STAGE/BepInEx/config/BepInEx.cfg"

	# Stands in for the 572 MB of plugins a config edit cannot touch.
	#
	# /dev/urandom, not /dev/zero. Zeros compress to almost nothing, so an archive that
	# wrongly swallowed this whole blob still came out under the size threshold below and the
	# size assertion passed on the mutant -- it could only ever fail if the file were
	# incompressible. Verified by mutation: with zeros, zipping ./BepInEx instead of
	# ./BepInEx/config tripped one assertion; with random bytes it trips two.
	head -c 200000 /dev/urandom > "$STAGE/BepInEx/plugins/BigMod/BigMod.dll"

	# server_only lives ONLY in the server tree -- that is how materialiseModConfigs keeps it
	# off the client, and what T3 verifies the archive inherits.
	cp "$STAGE/BepInEx/config/Azumatt.AzuClock.cfg" "$GAMEDIR/BepInEx/config/Azumatt.AzuClock.cfg"
	printf '[Secrets]\n\nWebhook = https://example.invalid/SERVERONLYSECRET\n' \
		> "$GAMEDIR/BepInEx/config/Secret.Webhook.cfg"
}

echo
echo "=== 2.55 config-only payload oracles ==="
echo

# ---- T1: world_md5 identity (the old-client loop guard) ---------------------------------
echo "T1  world_md5 is the md5 of the real payload, never a composite"
build_tree
# A stand-in payload. The assertion is about the RELATIONSHIP the engine must preserve, so it
# does not matter what is in it.
printf 'pretend payload bytes' > "$FULLZIP"
: > "$SQL_LOG"
setMD5 "$WORLD" "$(getMD5 "$FULLZIP")" > /dev/null 2>&1
stored=$(grep -o "world_md5='[^']*'" "$SQL_LOG" | head -1 | sed "s/.*='//;s/'$//")
check "stored world_md5 == md5sum of the zip on disk" "$(md5sum "$FULLZIP" | cut -d' ' -f1)" "$stored"

# The mutation this is really guarding against, stated as its own assertion: a composite of
# the two archives must NOT equal the file's own hash, or T1 above would be vacuous.
pkgcfg "$WORLD"
composite=$(printf '%s%s' "$(getMD5 "$FULLZIP")" "$(getMD5 "$CFGZIP")" | md5sum | cut -d' ' -f1)
if [ "$composite" != "$(md5sum "$FULLZIP" | cut -d' ' -f1)" ]; then
	ok "a composite checksum is detectably different from the payload's own hash"
else
	bad "composite and real hash collided -- T1 cannot discriminate"
fi

# ---- T2: the archive is config ONLY -----------------------------------------------------
echo
echo "T2  the archive carries BepInEx/config and nothing else"
build_tree
: > "$SQL_LOG"
pkgcfg "$WORLD"
if [ -s "$CFGZIP" ]; then ok "archive was created and is non-empty"; else bad "archive missing"; fi

listing=$(unzip -Z1 "$CFGZIP" 2>/dev/null)
if echo "$listing" | grep -q "BepInEx/config/Azumatt.AzuClock.cfg"; then
	ok "contains the mod's config file"
else
	bad "mod config file missing from the archive"
fi
if echo "$listing" | grep -q "plugins"; then
	bad "archive contains the plugins tree -- it is not config-only"
else
	ok "contains no plugins (the 572 MB a config edit cannot touch)"
fi
# Size, as an independent witness. A glob that matched ./BepInEx would still "work".
cfgsize=$(stat -c %s "$CFGZIP")
if [ "$cfgsize" -lt 100000 ]; then
	ok "archive is small ($cfgsize bytes < 100 KB)"
else
	bad "archive is $cfgsize bytes -- the 200 KB plugin blob is probably in it"
fi

# ---- T3: server_only never reaches the archive, WITH a control --------------------------
echo
echo "T3  a server-only value stays out; a normal value gets in (control)"
if unzip -p "$CFGZIP" "BepInEx/config/Azumatt.AzuClock.cfg" 2>/dev/null | grep -q "Show Clock = Off"; then
	ok "CONTROL: the operator's client-destined value is in the archive"
else
	bad "CONTROL FAILED: the saved value is not in the archive -- T3 proves nothing"
fi
if unzip -p "$CFGZIP" "BepInEx/config/Secret.Webhook.cfg" > /dev/null 2>&1; then
	bad "the server-only file is IN the archive -- it was built from game/, not client/"
else
	ok "the server-only file is absent (archive came from the staging tree)"
fi
if unzip -p "$CFGZIP" "*" 2>/dev/null | grep -q "SERVERONLYSECRET"; then
	bad "a server-only secret appears somewhere in the archive"
else
	ok "no server-only secret anywhere in the archive"
fi

# ---- T4: both checksums describe ONE generation of the tree -----------------------------
echo
echo "T4  the config inside the full payload matches the config archive, byte for byte"
# Build a full payload the way packageClient does -- from the same staging tree, same moment.
( cd "$STAGE" && zip -q -r "$FULLZIP" ./BepInEx )
A="$TMP/fromfull"; B="$TMP/fromcfg"
rm -rf "$A" "$B"; mkdir -p "$A" "$B"
unzip -q "$FULLZIP" "BepInEx/config/*" -d "$A" 2>/dev/null
unzip -q "$CFGZIP"  -d "$B" 2>/dev/null
if diff -r "$A/BepInEx/config" "$B/BepInEx/config" > /dev/null 2>&1; then
	ok "the two archives agree on every config byte"
else
	bad "the full payload and the config archive disagree -- they describe different states"
fi

# ---- T5: a failed rebuild keeps the previous archive AND its checksum -------------------
echo
echo "T5  a failed rebuild keeps the good archive and its checksum"
goodmd5=$(getMD5 "$CFGZIP")
goodsize=$(stat -c %s "$CFGZIP")
: > "$SQL_LOG"
# Make zip fail without touching the staging tree: the destination directory is read-only, so
# the temp file cannot be written. The previous archive is still there, inside it.
chmod a-w "$WROOT"
pkgcfg "$WORLD"
rc=$?
chmod u+w "$WROOT"
check "returns non-zero on a failed rebuild" "1" "$([ $rc -ne 0 ] && echo 1 || echo 0)"
check "the previous archive is untouched" "$goodsize" "$(stat -c %s "$CFGZIP" 2>/dev/null)"
check "the previous archive still hashes the same" "$goodmd5" "$(getMD5 "$CFGZIP")"
if grep -q "config_md5" "$SQL_LOG"; then
	bad "a failed rebuild rewrote config_md5 -- it must leave the last good value alone"
else
	ok "config_md5 was not rewritten by a failed rebuild"
fi
if [ -e "$CFGZIP.tmp" ]; then
	bad "a .tmp archive was left behind"
else
	ok "no .tmp archive left behind"
fi

# ---- T6: no staging config dir CLEARS the checksum --------------------------------------
echo
echo "T6  no staging config dir clears the checksum (so clients fall back)"
build_tree
rm -rf "$STAGE/BepInEx/config"
: > "$SQL_LOG"
pkgcfg "$WORLD"
rc=$?
check "returns non-zero when there is no config to package" "1" "$([ $rc -ne 0 ] && echo 1 || echo 0)"
if grep -q "config_md5=NULL" "$SQL_LOG"; then
	ok "config_md5 set to NULL, not to '' (unknown is not a value)"
else
	bad "config_md5 was not cleared to NULL: $(cat "$SQL_LOG")"
fi

# ---- T7: setConfigMD5 distinguishes a value from unknown --------------------------------
echo
echo "T7  setConfigMD5 writes NULL for empty and a literal otherwise"
: > "$SQL_LOG"
setConfigMD5 "$WORLD" "" > /dev/null 2>&1
if grep -q "config_md5=NULL" "$SQL_LOG"; then ok "empty -> NULL"; else bad "empty did not write NULL"; fi
: > "$SQL_LOG"
setConfigMD5 "$WORLD" "deadbeef" > /dev/null 2>&1
if grep -q "config_md5='deadbeef'" "$SQL_LOG"; then ok "value -> quoted literal"; else bad "value not written"; fi

# ---- T7b: setModsMD5 survives a repackage, and never clears on failure -------------------
#
# The bug this exists for, measured live on VikingOutlaws before the fix: three repackages,
# three different world_md5 values, three 573 MB downloads. world_md5 has to move -- it is the
# md5 of a rebuilt zip, and it is what verifies a download -- so the "do I need the payload"
# question needs its own answer that does NOT move. This drives the real function through a
# real repackage: config rewritten, payload re-zipped, mods untouched.
echo
echo "T7b setModsMD5 stamps a key that a repackage does not move"
payloadKeyTool="$REPO/container/engine/tools/payloadKey.py"

mkzip() {   # $1=output zip -- the FULL payload, built from the staging tree
	rm -f "$1"
	( cd "$STAGE" && zip -q -r "$1" ./BepInEx )
}
stamped() { sed -n "s/.*mods_md5='\([^']*\)'.*/\1/p" "$SQL_LOG" | tail -1; }

build_tree
mkzip "$TMP/p1.zip"
: > "$SQL_LOG"; setModsMD5 "$WORLD" "$TMP/p1.zip" > /dev/null 2>&1
K_BEFORE="$(stamped)"

# The repackage: materialiseModConfigs rewrites BepInEx/config, then the zip is rebuilt.
sleep 1.1
printf '[1 - General]\n\n## Show the clock\n# Default value: On\nShow Clock = On\n' \
	> "$STAGE/BepInEx/config/Azumatt.AzuClock.cfg"
mkzip "$TMP/p2.zip"
: > "$SQL_LOG"; setModsMD5 "$WORLD" "$TMP/p2.zip" > /dev/null 2>&1
K_AFTER="$(stamped)"

W_BEFORE="$(getMD5 "$TMP/p1.zip")"
W_AFTER="$(getMD5 "$TMP/p2.zip")"

# The control. If world_md5 did NOT move, this fixture is not a repackage and the assertion
# after it would pass against a key that is simply constant.
if [ -n "$K_BEFORE" ] && [ "$W_BEFORE" != "$W_AFTER" ]; then
	ok "CONTROL: the repackage moved world_md5 (${W_BEFORE:0:8} -> ${W_AFTER:0:8})"
else
	bad "CONTROL: the repackage did not move world_md5 -- the next assertion proves nothing"
fi
check "the mod key is unchanged by the repackage" "$K_BEFORE" "$K_AFTER"

# And it must still notice a real mod change, or "unchanged" above is just a constant.
head -c 200000 /dev/urandom > "$STAGE/BepInEx/plugins/BigMod/BigMod.dll"
mkzip "$TMP/p3.zip"
: > "$SQL_LOG"; setModsMD5 "$WORLD" "$TMP/p3.zip" > /dev/null 2>&1
if [ -n "$(stamped)" ] && [ "$(stamped)" != "$K_AFTER" ]; then
	ok "a changed plugin DOES move the mod key"
else
	bad "a changed plugin did not move the mod key -- it is a constant, not an identity"
fi

# A key we cannot compute must leave the column alone. Clearing it says "unknown" to every
# client, and unknown costs a full download each -- too expensive to pay for one unreadable
# zip when the previous payload is still on disk and its previous key is still true.
: > "$SQL_LOG"; setModsMD5 "$WORLD" "$TMP/does-not-exist.zip" > /dev/null 2>&1
if [ ! -s "$SQL_LOG" ]; then
	ok "an unreadable payload writes NO sql (the previous key stands)"
else
	bad "an unreadable payload wrote '$(cat "$SQL_LOG")' -- a transient failure buys a fleet of 573 MB downloads"
fi

build_tree   # leave the tree as the later tests expect it

# ---- T8: the engine's repackage branch cannot park a world in a dead mode ---------------
echo
echo "T8  the repackage branch always lands on a mode the loop recognises"
ENGINE="$REPO/container/engine/phvalheim"
branch=$(awk '/if \[ "\$worldMode" = "repackage" \]; then/,/^\t\tfi$/' "$ENGINE")
if [ -z "$branch" ]; then
	bad "could not locate the repackage branch in $ENGINE"
else
	ok "repackage branch found"

	# COMMENTS STRIPPED BEFORE MATCHING, and this is the whole reason T8 is written this way.
	#
	# Every negative assertion below first fired on the branch's own explanatory prose: the
	# comment stating that mode='start' would be wrong CONTAINS the string mode='start', and
	# the comment stating InstallCustomConfigSecureFiles is deliberately not called contains
	# its name. Three green-looking failures, none of them about the code. A marker that can
	# match prose is not testing anything -- this project has shipped that mistake before.
	#
	# Whole comment lines only, rather than sed 's/#.*//', so a '#' inside a string stays put.
	code=$(echo "$branch" | grep -v "^[[:space:]]*#")

	# Every exit must set running or stopped. 'repackaging' is not a command, so a branch
	# that could leave it set would have the main loop revisit that world every 2 seconds
	# forever -- the failure the update branch's own comment records.
	if echo "$code" | grep -q "mode='running'" && echo "$code" | grep -q "mode='stopped'"; then
		ok "sets both mode='running' and mode='stopped'"
	else
		bad "the branch does not set both terminal modes"
	fi

	# A `continue` would skip the mode restore entirely and leave 'repackaging' set.
	if echo "$code" | grep -qE "^[[:space:]]*continue[[:space:]]*$"; then
		bad "the branch contains a bare 'continue' -- it can exit without restoring the mode"
	else
		ok "no bare 'continue' that could skip the mode restore"
	fi

	# mode='start' would boot a world the operator had deliberately left stopped: this branch
	# never stopped anything, so it has nothing to restart.
	if echo "$code" | grep -q "mode='start'"; then
		bad "the branch sets mode='start' -- it would boot a world left stopped on purpose"
	else
		ok "never sets mode='start'"
	fi

	# The restore must sit OUTSIDE the vanilla if/else, or a vanilla world is parked in
	# 'repackaging' forever.
	#
	# $'\t' (ANSI-C quoting), not "\t". In a double-quoted bash string \t is a literal
	# backslash-t, and grep's BRE does not interpret it either -- so the pattern matched
	# nothing, vanilla_fi came back empty, and this assertion reported a structural bug that
	# did not exist. A pattern that cannot match what it is looking for always "finds" a fault.
	restore_at=$(echo "$code" | grep -n "worldProcessRunning" | head -1 | cut -d: -f1)
	vanilla_fi=$(echo "$code" | grep -n $'^\t\t\tfi$' | head -1 | cut -d: -f1)
	if [ -z "$vanilla_fi" ]; then
		bad "could not find the vanilla branch's closing fi -- indentation changed, fix this probe"
	elif [ -z "$restore_at" ]; then
		bad "the branch never calls worldProcessRunning -- it cannot know what mode to restore"
	elif [ "$restore_at" -gt "$vanilla_fi" ]; then
		ok "the mode restore runs after the vanilla branch closes (vanilla is restored too)"
	else
		bad "the mode restore sits inside the vanilla if/else -- a vanilla world would hang"
	fi

	# InstallCustomConfigSecureFiles after materialise would invert the DB-wins precedence.
	if echo "$code" | grep -q "InstallCustomConfigSecureFiles"; then
		bad "the branch calls InstallCustomConfigSecureFiles -- that inverts DB-over-file precedence"
	else
		ok "does not re-copy custom_configs_secure (would override the just-saved value)"
	fi

	# The three steps that must actually be there, in order. Without this the whole of T8 is
	# satisfiable by a branch that sets modes correctly and does no work at all.
	seq=$(echo "$code" | grep -oE "materialiseModConfigs|ensureBepInExLoaderConfig|packageClient " | tr -d ' ' | paste -sd, -)
	check "runs materialise, then the loader config, then packageClient" \
	      "materialiseModConfigs,ensureBepInExLoaderConfig,packageClient" "$seq"
fi

# ---- T9: the PHP guard is a whitelist -----------------------------------------------------
echo
echo "T9  repackageWorld only accepts an idle, modded world"
SETS="$REPO/container/nginx/www/includes/db_sets.php"
guard=$(awk '/function repackageWorld/,/^}/' "$SETS")
if echo "$guard" | grep -q "mode IN ('running','stopped')"; then
	ok "whitelists running/stopped rather than blacklisting busy states"
else
	bad "repackageWorld does not whitelist idle modes"
fi
if echo "$guard" | grep -q "IFNULL(vanilla,0) = 0"; then
	ok "excludes vanilla worlds in SQL, not only in the UI"
else
	bad "repackageWorld does not exclude vanilla worlds"
fi
if echo "$guard" | grep -q "rowCount"; then
	ok "reports whether the command was actually accepted"
else
	bad "repackageWorld cannot tell the caller it refused"
fi

# ---- T10: the sync contract the client depends on ----------------------------------------
echo
echo "T10 the server publishes both checksums in one response, and keeps getMD5 intact"
API="$REPO/container/nginx/www/public/api.php"
if grep -q 'mode == "getMD5"' "$API"; then
	ok "the pre-2.55 getMD5 contract is still served (old clients keep working)"
else
	bad "getMD5 was removed or renamed -- every existing client breaks"
fi
sync_block=$(awk '/mode == "getSyncState"/,/^}/' "$API")
if echo "$sync_block" | grep -q 'world=' && echo "$sync_block" | grep -q 'config=' \
   && echo "$sync_block" | grep -q 'mods='; then
	ok "getSyncState returns world=, mods= and config= in one response"
else
	bad "getSyncState does not return all three keys -- without mods= the client cannot tell a repackage from a mod change"
fi

# ---- T11: the client never treats an unknown config checksum as a match -----------------
echo
echo "T11 the client's decision logic fails safe on an unknown config checksum"
SYNCER="$REPO/../phvalheim-client/Syncer.cs"
if [ ! -f "$SYNCER" ]; then
	echo "  SKIP  phvalheim-client not checked out beside this repo"
else
	# The config branch must require a non-empty remote checksum. Without that test, a server
	# that publishes none would be compared against "" and the branch could fire every launch.
	if grep -q "remote.ConfigMd5.Length > 0 && heldConfigMD5 != remote.ConfigMd5" "$SYNCER"; then
		ok "config sync requires a published remote checksum AND a mismatch"
	else
		bad "the config-sync condition does not guard on a published remote checksum"
	fi
	# The payload question is asked of the MOD identity, not the zip's md5. Asked of the md5,
	# the answer is "differs" after every repackage and the config branch is unreachable --
	# which is what 2.55 shipped. The fallback must still exist for a pre-2.55 server.
	if grep -q "canCompareMods ? (localModsMD5 != remote.ModsMd5)" "$SYNCER" && \
	   grep -q ": (localWorldMD5 != remote.WorldMd5)" "$SYNCER"; then
		ok "the payload decision is the mod identity, with the zip md5 as the fallback"
	else
		bad "the payload decision still rests on the zip's md5 -- a repackage forces a full download"
	fi
	if grep -q "remote.ModsMd5.Length > 0 && localModsMD5.Length > 0" "$SYNCER"; then
		ok "an unknown mod identity on EITHER side falls back instead of guessing"
	else
		bad "the mod identity is compared without checking both sides are known"
	fi
	# After a config-only sync our payload is NOT the server's -- the server rebuilt its zip
	# and we did not. Recording the server's world_md5 would be a false claim about our own
	# file, and that value is what the next download's integrity check compares against.
	if grep -q "WriteSyncRecord(syncRecordFile, heldWorldMD5, remote.ModsMd5, remote.ConfigMd5)" "$SYNCER" && \
	   ! grep -q "WriteSyncRecord(syncRecordFile, remote.WorldMd5" "$SYNCER"; then
		ok "the record stores the payload md5 we HOLD, never the server's"
	else
		bad "the record copies the server's world_md5 for a payload we did not download"
	fi
	# A full download must be verified before the sync record is written, or a corrupt payload
	# is recorded as good and never re-fetched -- the self-healing the old client got by
	# accident from re-hashing on every launch.
	if grep -q "gotWorldMD5 != remote.WorldMd5" "$SYNCER"; then
		ok "a downloaded payload is hashed and verified before being recorded"
	else
		bad "the client records a payload as good without verifying what it downloaded"
	fi
	if grep -q "ZipFile.ExtractToDirectory(localConfigFile" "$SYNCER" && \
	   grep -q "Directory.Delete(configDir, true)" "$SYNCER"; then
		ok "the config directory is replaced wholesale, not merged"
	else
		bad "the config directory is not deleted before extracting -- a reset key would survive"
	fi
	if grep -q "mode=getMD5" "$SYNCER"; then
		ok "falls back to getMD5 against a server with no getSyncState"
	else
		bad "no fallback -- a new client would break against an old server"
	fi
fi

echo
echo "=== $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ] && echo "ALL CLIENT PAYLOAD SYNC ORACLES PASSED" || echo "CLIENT PAYLOAD SYNC ORACLES FAILED"
[ "$FAIL" -eq 0 ]
