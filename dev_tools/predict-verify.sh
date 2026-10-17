#!/bin/bash
# Predict the in-image verify gate against the WORKING TREE, before spending a build.
#
# Why this exists. buildRcDetached.sh pushes :rc BEFORE it runs the verify, so a marker that
# fails does not stop a bad image reaching the live channel that real operators run -- it only
# tells you afterwards. And the markers are greps pinned to exact source spellings, so an
# ordinary refactor can break one without anything regressing: the bulk-forget change moved
# `'local_dll' => $localFor(...)` to `'local_dll' => $dll` and failed a 2.55 marker that was
# still perfectly true in substance. That cost a full build and a bad push.
#
# This extracts every marker assignment and the && chain out of buildRcDetached.sh, remaps the
# in-image paths onto the repo, and reports which markers would fail -- in seconds.
#
# It is a PREDICTION, not a substitute: markers that inspect things only present inside the
# built image (installed binaries, generated files) cannot be evaluated here and are reported
# as SKIP rather than silently counted as passes.
#
# Usage:  bash dev_tools/predict-verify.sh

cd "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)" || exit 1
B=dev_tools/buildRcDetached.sh
[ -f "$B" ] || { echo "FATAL: $B not found"; exit 1; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Pull the verify payload: the heredoc body that gets mounted into the container.
awk '/^cat > .*PHVVERIFYEOF|<<.?PHVVERIFYEOF/{f=1;next} /^PHVVERIFYEOF$/{exit} f{print}' "$B" > "$WORK/payload.sh"
if [ ! -s "$WORK/payload.sh" ]; then
	# Fall back to the whole script: the markers are assignments either way.
	cp "$B" "$WORK/payload.sh"
fi

# Marker assignments and the chain comparisons.
grep -oE '^\s*v[0-9]+[a-z]*=\$\(.*\)$' "$WORK/payload.sh" | sed 's/^\s*//' > "$WORK/assign.txt"
grep -oE '\[ "\$v[0-9]+[a-z]*" = "[^"]*" \]' "$WORK/payload.sh" \
	| sed -E 's/\[ "\$([a-z0-9]+)" = "([^"]*)" \]/\1 \2/' | sort -u > "$WORK/want.txt"

echo "markers assigned: $(wc -l < "$WORK/assign.txt")   gated by the chain: $(wc -l < "$WORK/want.txt")"
echo

# The payload sets PATH VARIABLES ($zidx, $zfun, $zeng, $zcss, ...) and many markers reference
# them. Without these every such marker evaluates against an empty path and reports <empty>,
# which looks like a failing marker and is really a broken predictor. Extract and remap them
# too, then source them before evaluating anything.
grep -oE '^\s*z[A-Za-z0-9_]+=[^$(].*$' "$WORK/payload.sh" | sed 's/^\s*//' | sort -u > "$WORK/paths.txt"

remap() {
	sed -e 's#/opt/stateless/engine#container/engine#g' \
	    -e 's#/opt/stateless/nginx#container/nginx#g' \
	    -e 's#/opt/stateless#container#g' "$1"
}
remap "$WORK/paths.txt"  > "$WORK/paths.local.sh"
remap "$WORK/assign.txt" > "$WORK/assign.local.sh"
# shellcheck disable=SC1090
. "$WORK/paths.local.sh" 2>/dev/null
echo "path vars resolved: $(wc -l < "$WORK/paths.local.sh")"

# Evaluate. Unresolvable paths -> SKIP, never a silent pass.
PASS=0; FAILN=0; SKIP=0
: > "$WORK/out.txt"
while read -r name want; do
	line=$(grep -E "^${name}=" "$WORK/assign.local.sh" | head -1)
	if [ -z "$line" ]; then
		printf 'SKIP  %-8s (no assignment found in the payload)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue
	fi
	# Does every file it reads exist here?
	missing=0
	for f in $(grep -oE 'container/[A-Za-z0-9_./-]+' <<<"$line" | sort -u); do
		[ -e "$f" ] || missing=1
	done
	if [ "$missing" = 1 ]; then
		printf 'SKIP  %-8s (reads a path not in the repo -- image-only)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue
	fi

	# NOT EVALUABLE HERE -- report as SKIP, never as FAIL. A predictor that reports false
	# failures gets ignored, and then it is worse than not having one.
	#
	# (a) Depends on a COMPUTED variable. Several markers are positional and take their
	#     bounds from line numbers the payload derives at runtime ($zpwCall, $zpwUpdate...).
	#     Unset here, `${zpwUpdate:-1}` silently becomes 1 and the marker measures the whole
	#     file instead of the region -- a confident wrong answer.
	unresolved=0
	for v in $(grep -oE '\$\{?z[A-Za-z0-9_]+' <<<"$line" | tr -d '${' | sort -u); do
		eval "val=\${$v-}"
		[ -z "$val" ] && unresolved=1
	done
	if [ "$unresolved" = 1 ]; then
		printf 'SKIP  %-8s (depends on a line number the payload computes at run time)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue
	fi

	# (b) The remap rewrote text INSIDE the search pattern. Some markers grep for a literal
	#     "/opt/stateless/..." string in the source; remapping the command also rewrote the
	#     needle, so it searches for a path that is not in the file. The marker is fine, the
	#     prediction is not.
	if grep -q '/opt/stateless' <<<"$(grep -E "^${name}=" "$WORK/assign.txt" | head -1 | grep -oE "'[^']*'|\"[^\"]*\"")"; then
		printf 'SKIP  %-8s (its search pattern contains an in-image path the remap rewrote)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue
	fi
	# Strip the $( ) wrapper and eval the INNER command.
	#
	# `eval "${line#*=}"` evaluates `$(grep -c ...)` -- command substitution runs first, so
	# eval then tries to EXECUTE the count ("1") as a command and produces nothing. Every
	# single marker came back <empty>, reading as 214 failures when the predictor was simply
	# throwing the answers away.
	#
	# < /dev/null is also load-bearing: without it the eval'd grep/awk inherits the
	# while-loop's stdin and consumes want.txt, so the loop dies after ~3 iterations.
	cmd=${line#*=}
	cmd=${cmd#\$\(}; cmd=${cmd%\)}
	got=$(eval "$cmd" < /dev/null 2>/dev/null)
	got=$(printf '%s' "$got" | tr -d ' \n')
	if [ "$got" = "$want" ]; then
		PASS=$((PASS+1))
	else
		printf 'FAIL  %-8s got=%-6s want=%-6s\n        %s\n' "$name" "${got:-<empty>}" "$want" "$line" >> "$WORK/out.txt"
		FAILN=$((FAILN+1))
	fi
done < "$WORK/want.txt"

grep '^FAIL' -A1 "$WORK/out.txt" 2>/dev/null
grep '^SKIP' "$WORK/out.txt" 2>/dev/null | head -12
s=$(grep -c '^SKIP' "$WORK/out.txt" 2>/dev/null); [ "${s:-0}" -gt 12 ] && echo "        ... and $((s-12)) more SKIP"
echo
echo "PREDICTED: $PASS pass, $FAILN fail, $SKIP skip"
[ "$FAILN" -eq 0 ] && echo "No marker would fail the gate on the evaluable set." \
                   || echo "FIX THESE BEFORE BUILDING -- :rc is pushed before the verify runs."
exit $([ "$FAILN" -eq 0 ] && echo 0 || echo 1)
