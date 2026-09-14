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
BLOCK="$(awk '/^if \[ "\$isCrossplay" = "1" \] && \[ "\$isVanilla" = "1" \]; then/,/^fi$/' "$SRC")"
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
run 1 0
case "$ARGS" in
	*-crossplay*) bad "MODDED world was launched WITH -crossplay" ;;
	*)            ok  "modded + crossplay saved: -crossplay is NOT passed to Valheim" ;;
esac

if [ -z "$LOG" ]; then
	bad "nothing is logged at all -- the operator has no way to know why crossplay is inert"
else
	# The sentence must state the OUTCOME before it mentions the stored preference. Anchor
	# on the first 60 characters: that is what a reader actually consumes.
	head60="$(printf '%s' "$LOG" | sed 's/.*phvalheim\] //' | cut -c1-60)"
	case "$head60" in
		*"crossplay is OFF"*) ok "the line opens with the outcome: \"$head60...\"" ;;
		*)                    bad "the line does not lead with the outcome: \"$head60...\"" ;;
	esac

	# And it must never contain a phrase that reads as "it is on".
	bad_phrase=""
	for p in "has crossplay set" "crossplay is ON" "crossplay enabled" "with crossplay"; do
		case "$LOG" in *"$p"*) bad_phrase="$bad_phrase '$p'" ;; esac
	done
	[ -z "$bad_phrase" ] \
	  && ok "no phrase in it can be read as crossplay being enabled" \
	  || bad "the line still contains:$bad_phrase"

	case "$LOG" in
		*VANILLA*|*vanilla*) ok "it says what to do about it (switch the world to vanilla)" ;;
		*)                   bad "it explains the refusal but not the remedy" ;;
	esac
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
