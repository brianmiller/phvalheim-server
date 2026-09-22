#!/bin/bash
# 2.50: a world deployment must be judged on its postcondition, never on chown.
#
# The bug: the create branch in `phvalheim` did
#
#     chown -R phvalheim: $worldsDirectoryRoot/$worldName
#     RESULT=$?
#     if [ $RESULT = 0 ]; then ... else
#         DELETE FROM worlds ... ; rm -rf .../$worldName
#
# chown reports whether it could change ownership of every file it walked. That is not
# the same question as "is this world deployed", and it comes apart in both directions:
# ONE unchownable file destroyed a world whose deployment was fine, and an incomplete
# but chownable tree passed as created. The same bad oracle is documented in
# InstallAndUpdateValheim, where it only mislabelled a good update -- here it deleted.
#
# The case that makes this an ORACLE rather than a restatement is #7: a complete tree
# containing a file that cannot be chowned is still a successful deployment.
#
# Mutation check: restore chown-gating and case 7 goes red while 1-6 still pass.
# Confirmed before shipping.
#
# Run: ./dev_tools/test-world-deploy-verdict.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FUNCS="${FUNCS_UNDER_TEST:-$REPO/container/engine/includes/0-functions.sh}"
ENGINE="$REPO/container/engine/phvalheim"

pass=0
fail=0

eval "$(awk '/^function worldDirIsPrepared\(\)/,/^}/' "$FUNCS")"
if ! declare -f worldDirIsPrepared > /dev/null; then
	echo "FAIL  could not extract worldDirIsPrepared from $FUNCS"
	exit 1
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
worldsDirectoryRoot="$TMP"

SAVEDIR="game/.config/unity3d/IronGate/Valheim"
REQUIRED="game custom_configs custom_configs_secure custom_plugins custom_patchers $SAVEDIR"

# $1=world  $2=directory to OMIT (empty = build a complete tree)
#
# Omitting a parent must also omit everything beneath it -- `mkdir -p` of the savedir
# recreates `game`, so a naive exact-match skip silently built a COMPLETE tree while
# claiming `game` was missing. That is a fixture that cannot express the case it names.
buildWorld() {
	local g="$TMP/$1" d
	rm -rf "$g"
	for d in $REQUIRED; do
		[ -n "$2" ] && { [ "$d" = "$2" ] || [ "${d#$2/}" != "$d" ]; } && continue
		mkdir -p "$g/$d"
	done
}

# The engine source with comments stripped. Every code marker below reads THIS, because
# the fix carries comments that quote the removed code verbatim -- `rm -rf`, `RESULT=$?`
# -- and a grep over raw source matches the prose explaining the bug and reports the bug.
ENGINE_CODE=$(grep -vE "^[[:space:]]*#" "$ENGINE")

# $1=label  $2=world  $3=0 for prepared, 1 for not
check() {
	worldDirIsPrepared "$2" > /dev/null
	local got=$?
	if [ "$got" = "$3" ]; then
		echo "PASS  $1"
		pass=$((pass + 1))
	else
		echo "FAIL  $1 -- expected $3, got $got"
		fail=$((fail + 1))
	fi
}

# 1. The healthy case.
buildWorld complete ""
check "a complete tree is a successful deployment" complete 0

# 2-6. Each required directory missing in turn. Any one of them means worldDirPrep did
#      not finish, which is a genuine deployment failure.
for d in game custom_configs custom_plugins custom_patchers "$SAVEDIR"; do
	buildWorld "missing" "$d"
	check "missing $d is a failed deployment" missing 1
done

# 7. THE ORACLE CASE. A complete tree that contains a file chown cannot touch. The old
#    code destroyed this world; it is a perfectly good deployment.
buildWorld chowntrap ""
mkdir -p "$TMP/chowntrap/game/immutable"
echo "cannot be chowned by a non-root process" > "$TMP/chowntrap/game/immutable/root-owned.dat"
chmod 000 "$TMP/chowntrap/game/immutable" 2>/dev/null
check "an unchownable file does NOT fail the deployment" chowntrap 0
chmod 755 "$TMP/chowntrap/game/immutable" 2>/dev/null

# 8. It must name what is missing, or the operator has a broken world and no reason.
buildWorld named custom_plugins
reason=$(worldDirIsPrepared named)
case "$reason" in
	*custom_plugins*) echo "PASS  the failure names the missing directory"; pass=$((pass + 1)) ;;
	*) echo "FAIL  failure reason did not name custom_plugins: '$reason'"; fail=$((fail + 1)) ;;
esac

# 9. Empty world name must not be treated as a prepared world at the worlds root.
if worldDirIsPrepared "" > /dev/null; then
	echo "FAIL  empty world name reported as prepared"
	fail=$((fail + 1))
else
	echo "PASS  empty world name is refused"
	pass=$((pass + 1))
fi

echo "--------------------------------- engine call site"
# 10. NEGATIVE: the destructive branch must be gone. Without this, every case above can
#     pass while the engine still deletes the world on failure.
if printf '%s\n' "$ENGINE_CODE" | awk '/worldMode" = "create"/,/worldMode" = "delete"/' | grep -q "DELETE FROM worlds"; then
	echo "FAIL  the create branch still deletes the worlds row"
	fail=$((fail + 1))
else
	echo "PASS  the create branch no longer deletes the worlds row"
	pass=$((pass + 1))
fi

# 11. NEGATIVE: and no rm -rf of the world directory in the create branch either.
if printf '%s\n' "$ENGINE_CODE" | awk '/worldMode" = "create"/,/worldMode" = "delete"/' | grep -q "rm -rf"; then
	echo "FAIL  the create branch still rm -rf-s the world directory"
	fail=$((fail + 1))
else
	echo "PASS  the create branch no longer rm -rf-s the world directory"
	pass=$((pass + 1))
fi

# 12. It must mark the world broken instead -- the state this engine already uses.
if printf '%s\n' "$ENGINE_CODE" | awk '/worldMode" = "create"/,/worldMode" = "delete"/' | grep -q "mode='broken'"; then
	echo "PASS  a failed deployment is marked broken"
	pass=$((pass + 1))
else
	echo "FAIL  a failed deployment is not marked broken"
	fail=$((fail + 1))
fi

# 13. NEGATIVE: chown must no longer decide anything on this path.
if printf '%s\n' "$ENGINE_CODE" | awk '/Deploying new world/,/Checking for required mods/' | grep -q "RESULT="; then
	echo "FAIL  a chown exit status is still gating the deployment"
	fail=$((fail + 1))
else
	echo "PASS  no chown exit status gates the deployment"
	pass=$((pass + 1))
fi

echo
echo "passed=$pass failed=$fail"
[ "$fail" = "0" ] || exit 1
exit 0
