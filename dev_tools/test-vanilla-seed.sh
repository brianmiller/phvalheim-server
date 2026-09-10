#!/bin/bash
# Oracle test for vanilla-world seed resolution.
#
# THE BUG: the engine stamped a random uint32 on every seedless world, vanilla ones
# included. That number can never be a vanilla world's seed -- a seed only reaches
# Valheim through the CustomSeed BepInEx mod, which a vanilla world does not run.
# syncWorldSeedFromSave()'s "already has a seed, stop" early-out then made the fake
# permanent, so the real seed could never be recorded.
#
# Assertions are on what is WRITTEN TO THE DATABASE, observed through a stubbed SQL().
# Asserting "the function returned 0" would pass in every arm.
#
# The .fwl is a REAL one copied out of a running container, not a hand-built fixture --
# the extraction is a byte-offset walk over Valheim's own format, and a fixture I wrote
# myself would only prove my understanding of it matches itself.
#
# Usage: dev_tools/test-vanilla-seed.sh [container]

C="${1:-phvalheim-dev}"
pass=0; fail=0
check () { # $1=name $2=ok $3=detail
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

# ---------------------------------------------------------------- fixture
# Grab a real .fwl and the seed the database already holds for it. That pairing is
# the control: if the extraction cannot reproduce a seed we independently know, no
# other result in this file means anything.
# Valheim 1.0 writes worlds_local/<name>/_main.N.fwl2; older builds wrote
# worlds_local/<name>.fwl. Accept either, so this runs on both.
SRC=$(docker exec "$C" sh -c 'ls -t /opt/stateful/games/valheim/worlds/*/game/.config/unity3d/IronGate/Valheim/worlds_local/*/_main.*.fwl2 2>/dev/null | head -1' 2>/dev/null)
FMT=fwl2
if [ -z "$SRC" ]; then
	SRC=$(docker exec "$C" sh -c 'find /opt/stateful/games/valheim/worlds -name "*.fwl" ! -name "*backup*" 2>/dev/null | head -1' 2>/dev/null)
	FMT=fwl
fi
if [ -z "$SRC" ]; then
	echo "NO world metadata file available in $C -- cannot run. Start and save a world first."
	exit 1
fi
if [ "$FMT" = "fwl2" ]; then
	SRCWORLD=$(basename "$(dirname "$SRC")")
else
	SRCWORLD=$(basename "$SRC" .fwl)
fi
KNOWN=$(docker exec "$C" mysql -N -e "select ifnull(seed,'') from phvalheim.worlds where name='$SRCWORLD'" 2>/dev/null)
docker cp "$C:$SRC" "$TMP/real.fwl" >/dev/null 2>&1
echo
echo "Fixture: $SRCWORLD.fwl, database says seed='$KNOWN'"

EXTRACT() { (head -c$(od -j$(od -j8 -N1 -An -t u1) -N1 -An -t u1);echo) < "$1"; }

echo
echo "Case 0: CONTROL -- the extraction reproduces a seed we already know"
GOT=$(EXTRACT "$TMP/real.fwl")
[ -n "$KNOWN" ] && [ "$GOT" = "$KNOWN" ] && ok=1 || ok=0
check "extraction returns the database's seed" "$ok" "extracted='$GOT' known='$KNOWN'"
if [ "$ok" != "1" ]; then
	echo "  (control failed -- every case below would be uninterpretable)"
	echo; echo "$pass passed, $((fail)) failed"; exit 1
fi

# ---------------------------------------------------------------- harness
# Pull syncWorldSeedFromSave out on its own and give it a stubbed SQL so we can see
# exactly what it would write.
FUNCS=$(dirname "$0")/../container/engine/includes/0-functions.sh
awk '/^function findWorldSaveMeta \(\)/,/^}/' "$FUNCS"  > "$TMP/fn.sh"
awk '/^function readSeedFromSaveMeta \(\)/,/^}/' "$FUNCS" >> "$TMP/fn.sh"
awk '/^function syncWorldSeedFromSave \(\)/,/^}/' "$FUNCS" >> "$TMP/fn.sh"
[ -s "$TMP/fn.sh" ] || { echo "could not extract syncWorldSeedFromSave"; exit 1; }

run_case () { # $1=world $2=vanilla $3=storedSeed $4=fwl(yes/no) -> prints the UPDATE it issued
	local world="$1" vanilla="$2" stored="$3" wantfwl="$4"
	local root="$TMP/run"; rm -rf "$root"
	local savedir="$root/opt/stateful/games/valheim/worlds/$world/game/.config/unity3d/IronGate/Valheim/worlds_local"
	mkdir -p "$savedir"
	if [ "$wantfwl" = "yes" ]; then
		if [ "$FMT" = "fwl2" ]; then
			mkdir -p "$savedir/$world"
			cp "$TMP/real.fwl" "$savedir/$world/_main.1.fwl2"
		else
			cp "$TMP/real.fwl" "$savedir/$world.fwl"
		fi
	fi

	cat > "$root/drive.sh" <<EOF
SQL () {
  case "\$1" in
    SELECT*) echo "$stored" ;;
    *) echo "SQLWRITE:\$1" >&2 ;;
  esac
}
. "$TMP/fn.sh"
# Point the function at the sandbox rather than the real /opt/stateful.
syncWorldSeedFromSave () { :; }
EOF
	# Re-extract with the path rebased, so no real filesystem is touched.
	sed "s#/opt/stateful#$root/opt/stateful#" "$TMP/fn.sh" > "$TMP/fn_rebased.sh"
	{
		echo 'SQL () { case "$1" in SELECT*) echo "'"$stored"'" ;; *) echo "SQLWRITE:$1" >&2 ;; esac }'
		cat "$TMP/fn_rebased.sh"
		echo "syncWorldSeedFromSave '$world' '$vanilla'"
	} > "$root/drive2.sh"
	bash "$root/drive2.sh" 2>&1 >/dev/null | grep '^SQLWRITE:' || true
}

echo
echo "Case 1: VANILLA, no save yet, carrying a fabricated seed -> must CLEAR it"
OUT=$(run_case wtest 1 4203263819 no)
echo "$OUT" | grep -q "seed=NULL" && ok=1 || ok=0
check "clears the fiction" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 2: VANILLA, save exists, stored seed DISAGREES -> must correct to the .fwl"
OUT=$(run_case "$SRCWORLD" 1 4203263819 yes)
echo "$OUT" | grep -q "seed='$KNOWN'" && ok=1 || ok=0
check "overwrites the fake with the real seed" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 3: VANILLA, save exists, stored seed already correct -> must write NOTHING"
OUT=$(run_case "$SRCWORLD" 1 "$KNOWN" yes)
[ -z "$OUT" ] && ok=1 || ok=0
check "idempotent, no pointless UPDATE" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 4: MODDED with a seed -> must NOT be touched (the seed is the INPUT there)"
OUT=$(run_case "$SRCWORLD" 0 myCustomSeed yes)
[ -z "$OUT" ] && ok=1 || ok=0
check "leaves a modded world's chosen seed alone" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 5: MODDED, no seed recorded, save exists -> still fills it in"
OUT=$(run_case "$SRCWORLD" 0 "" yes)
echo "$OUT" | grep -q "seed='$KNOWN'" && ok=1 || ok=0
check "records the seed for a modded world that lacked one" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 6: MODDED, no save, carrying a seed -> must NOT clear it"
OUT=$(run_case wtest 0 4203263819 no)
[ -z "$OUT" ] && ok=1 || ok=0
check "does not clear a modded world's seed" "$ok" "wrote: ${OUT:-<nothing>}"

echo
echo "Case 7: the engine never invents a seed for a vanilla world"
ENGINE=$(dirname "$0")/../container/engine/phvalheim
# The generator must sit inside a non-vanilla guard. Checked structurally because
# running the whole engine loop here is not feasible; the end-to-end arm is the
# create-a-vanilla-world run in the session log.
awk '/NEVER invent a seed for a vanilla world/,/^\t\tfi$/' "$ENGINE" > "$TMP/guard.txt"
grep -q 'if \[ "\$worldVanilla" != "1" \]; then' "$TMP/guard.txt" && ok=1 || ok=0
check "random-seed generator is behind a vanilla guard" "$ok"
grep -q 'od -An -tu4 -N4 /dev/urandom' "$TMP/guard.txt" && ok=1 || ok=0
check "...and the generator is the thing inside it" "$ok"

echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
