#!/bin/bash
# A modded world's log must not say crossplay is enabled.
#
# Reported as: "the world log for a modded world says crossplay is enabled. This isn't
# correct. modded worlds do not use crossplay."
#
# The launch gate was already right -- `-crossplay` has never been passed to a modded world.
# What was wrong was the SENTENCE. It opened:
#
#   World 'X' has crossplay set but is MODDED -- starting without -crossplay.
#
# The first thing that asserts is that crossplay is set, and that is what a reader scanning
# a log takes away. The rest of the line walking it back does not undo the first six words.
#
# Established before fixing it, by checking the game's own assemblies: every occurrence of
# "crossplay" in assembly_valheim.dll and Splatform.dll is a .NET IDENTIFIER
# (CrossplayAllowed, SetCrossplayPrivilege, m_crossplayServerToggle) -- metadata, not a log
# string. Valheim never prints the word, so any line mentioning it is ours.
#
# This runs the REAL block out of startWorld.sh rather than a copy, so the test cannot drift
# away from what ships.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/container/games/valheim/scripts/startWorld.sh"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

# Extract the crossplay decision verbatim: from the `if` that tests isCrossplay to its `fi`.
#
# The anchor used to require the single combined condition
#   if [ "$isCrossplay" = "1" ] && [ "$isVanilla" = "1" ]; then
# which 2.53 replaced with an outer isCrossplay test and an inner isVanilla test. The awk
# matched nothing, this exited 1 at the guard below, and the whole file has been reporting
# "renamed or restructured" since -- it has not actually checked a log line in two releases.
BLOCK="$(awk '/^if \[ "\$isCrossplay" = "1" \]; then/,/^fi$/' "$SRC")"
if [ -z "$BLOCK" ]; then
	echo "  FAIL  could not find the crossplay block in startWorld.sh -- it was renamed or restructured"
	exit 1
fi

# Run it for one combination and report the args it built plus anything it logged.
run() {
	isCrossplay="$1"; isVanilla="$2"; worldName="Midgard"
	export isCrossplay isVanilla worldName
	OUT="$(
		set -- -name "$worldName"
		eval "$BLOCK"
		printf 'ARGS:%s\n' "$*"
	)"
	ARGS="$(printf '%s' "$OUT" | grep '^ARGS:' | sed 's/^ARGS://')"
	LOG="$(printf '%s' "$OUT" | grep -v '^ARGS:')"
}

printf '\n\033[1mWhat a modded world is told about crossplay\033[0m\n'

# --- the case that was reported ---------------------------------------------------------
#
# WHAT CHANGED, and why the assertions below are not the ones originally written here.
#
# This file was authored when crossplay was scoped back to vanilla worlds (f6d72b94): a modded
# world kept the flag in the database but was started WITHOUT -crossplay, and the bug was that
# the log line announced crossplay anyway. 2.53 (67d243d5, "crossplay on modded worlds") made
# modded crossplay real: -crossplay IS passed now, whatever the world's mods, and the two
# branches differ only in how they describe reaching the world.
#
# So the old assertions -- no -crossplay, "crossplay is OFF", "switch it to vanilla" -- were
# asserting a retired policy against shipped behaviour. They could only ever fail. The
# behaviour worth guarding today is the one the original report was about: a log line a reader
# scanning it takes the WRONG meaning from.
run 1 0
case "$ARGS" in
	*-crossplay*) ok  "modded + crossplay: -crossplay IS passed (2.53 made this supported)" ;;
	*)            bad "a modded crossplay world was started WITHOUT -crossplay" \
	                  "2.53 enabled this deliberately; losing it makes the world unreachable" ;;
esac

if [ -z "$LOG" ]; then
	bad "nothing is logged at all -- the operator cannot tell how the world is reachable"
else
	# The sentence must state the OUTCOME before anything else. Anchor on the first 60
	# characters: that is what a reader scanning a log actually consumes. The original bug
	# was a line that opened by asserting the stored preference and only later walked it back.
	head60="$(printf '%s' "$LOG" | sed 's/.*phvalheim\] //' | cut -c1-60)"
	case "$head60" in
		*"crossplay is ON"*) ok "the line opens with the outcome: \"$head60...\"" ;;
		*)                   bad "the line does not lead with the outcome: \"$head60...\"" ;;
	esac

	# It must say MODDED, so the two branches are distinguishable in a log. A single shared
	# sentence would make a modded crossplay world indistinguishable from a vanilla one, and
	# how a player reaches it is the one thing that differs.
	case "$LOG" in
		*MODDED*) ok "and it names the world as MODDED, so the branch is identifiable" ;;
		*)        bad "the modded branch logs the same sentence as the vanilla one: '$LOG'" ;;
	esac

	# And it must say how the world is reached. Crossplay worlds are PlayFab: join code only,
	# no direct address. An operator reading "crossplay is ON" and nothing else will go looking
	# for a host and port that do not exist.
	case "$LOG" in
		*"code only"*|*"join code"*) ok "it says how the world is reached (by code only)" ;;
		*)                           bad "it announces crossplay without saying it is code-only: '$LOG'" ;;
	esac

	# The retired wording must not come back. These phrases describe a world that was NOT
	# started with -crossplay, which has not been true for a modded world since 2.53.
	bad_phrase=""
	for p in "has crossplay set" "crossplay is OFF" "starting without -crossplay" "switch it to vanilla"; do
		case "$LOG" in *"$p"*) bad_phrase="$bad_phrase '$p'" ;; esac
	done
	[ -z "$bad_phrase" ] \
	  && ok "no phrase in it describes the retired pre-2.53 refusal" \
	  || bad "the line still contains:$bad_phrase"
fi

# --- the vanilla cases must be unaffected ------------------------------------------------
run 1 1
case "$ARGS" in
	*-crossplay*) ok "vanilla + crossplay saved: -crossplay IS passed" ;;
	*)            bad "vanilla world lost its -crossplay" ;;
esac
case "$LOG" in
	*"crossplay is ON"*) ok "...and the log says crossplay is ON, which is true there" ;;
	*)                   bad "a real crossplay world logs nothing about it: '$LOG'" ;;
esac

run 0 0
[ -z "$LOG" ] && ok "modded without crossplay saved: says nothing, because there is nothing to explain" \
             || bad "logged something for a world with crossplay off: '$LOG'"
case "$ARGS" in *-crossplay*) bad "passed -crossplay with the flag off" ;; *) ok "and passes no -crossplay" ;; esac

run 0 1
[ -z "$LOG" ] && ok "vanilla without crossplay: also silent" || bad "unexpected log: '$LOG'"

# --- nothing ELSE in the tree writes crossplay to a world log ----------------------------
#
# If a second writer appears, the reported symptom could come back from somewhere this test
# is not looking.
others=$(grep -rl "crossplay" "$ROOT/container/games/valheim/scripts" 2>/dev/null | grep -v "startWorld.sh" | head -3)
[ -z "$others" ] \
  && ok "startWorld.sh is the only script that mentions crossplay in the world log" \
  || bad "another script also writes crossplay to the log: $others"

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mCrossplay logging is honest\033[0m (%s checks)\n' "$PASS"; exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
