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
# ANY lowercase-led assignment, not just v-prefixed. The chain gates plenty of markers that
# are not named vNNN -- cpw, cpb, cpc, sb, sg -- and a v-only pattern made every one of them
# invisible to this tool. cpw is precisely the marker that failed the build this was written
# to prevent, and it was never in the predicted set at all.
# Comment lines are stripped FIRST. nameTheFailures' own documentation contains the literal
# text `[ "$x" = "y" ]` as an example of the shape it parses, and extracting from prose
# invented a marker called x that wanted "y" and could never pass.
grep -vE '^\s*#' "$WORK/payload.sh" > "$WORK/code.sh"
grep -oE '^\s*[a-z][A-Za-z0-9_]*=\$\(.*\)$' "$WORK/code.sh" | sed 's/^\s*//' > "$WORK/assign.txt"
grep -oE '\[ "\$[a-z][A-Za-z0-9_]*" = "[^"]*" \]' "$WORK/code.sh" \
	| sed -E 's/\[ "\$([A-Za-z0-9_]+)" = "([^"]*)" \]/\1 \2/' | sort -u > "$WORK/want.txt"

echo "markers assigned: $(wc -l < "$WORK/assign.txt")   gated by the chain: $(wc -l < "$WORK/want.txt")"
echo

# The payload sets PATH VARIABLES ($zidx, $zfun, $zeng, $zcss, ...) and many markers reference
# them. Without these every such marker evaluates against an empty path and reports <empty>,
# which looks like a failing marker and is really a broken predictor. Extract and remap them
# too, then source them before evaluating anything.
grep -oE '^\s*z[A-Za-z0-9_]+=[^$(].*$' "$WORK/code.sh" | sed 's/^\s*//' | sort -u > "$WORK/paths.txt"

# COMPUTED helper variables as well -- the line numbers positional markers are built from
# ($mancall, $manzip, $zpwCall, $zpwUpdate, ...). These were previously skipped, and skipping
# them skips exactly the class of marker that keeps breaking: a positional marker is pinned to
# the SPELLING of the line it points at, so any refactor of that line silently empties it.
#
# Both builds this tool was meant to save were this class. v55dj was a spelling pin; cpw was a
# `^[[:space:]]*zip` anchor that stopped matching when the zip got wrapped in a subshell. The
# predictor reported "0 fail" both times because it had quietly SKIPPED them.
#
# They are greps and awks over the source, so evaluating them here is safe and cheap. Anything
# still unresolved after this is reported as SKIP, as before.
grep -oE '^\s*[a-z][A-Za-z0-9_]*=\$\((grep|awk|sed|tail|head).*$' "$WORK/code.sh" \
	| sed 's/^\s*//' \
	| grep -vE '^v[0-9]' \
	| sort -u > "$WORK/computed.txt"

remap() {
	sed -e 's#/opt/stateless/engine#container/engine#g' \
	    -e 's#/opt/stateless/nginx#container/nginx#g' \
	    -e 's#/opt/stateless#container#g' "$1"
}
remap "$WORK/paths.txt"    > "$WORK/paths.local.sh"
remap "$WORK/computed.txt" > "$WORK/computed.local.sh"
remap "$WORK/assign.txt"   > "$WORK/assign.local.sh"

# ONE LINE AT A TIME, each one parse-checked first, and the count is of variables that
# actually HOLD a value.
#
# This used to be two `. file 2>/dev/null` sources, and it was broken in a way that reported
# itself healthy. The extraction keeps only the FIRST PHYSICAL LINE of each assignment, so a
# marker whose $( ) spans several lines contributes an unbalanced `$(` -- a SYNTAX ERROR.
# Sourcing a file aborts at a syntax error, so every assignment from that point to the end of
# the file was silently skipped. computed.txt is `sort -u`'d, so "that point" was alphabetical
# and arbitrary: the break sat around 'r', which left every z* var unset, including the one
# five brand-new markers depended on. They were reported as "depends on a line number the
# payload computes at run time" -- a plausible, wrong reason -- and counted as SKIP.
#
# And the old line printed `wc -l` of the FILE as "computed vars resolved: 560", which was
# true of the text and false of the variables. 3 of those 560 had values. A predictor that
# cannot tell you it went blind is worse than no predictor.
#
# So: validate each line on its own, eval the ones that parse, and count what is really set.
# SET_N/BAD_N are globals and sourceEach runs in THIS shell. It cannot be called inside $( ):
# the whole point is the side effect of defining the variables, and a command substitution
# runs in a subshell that throws every one of them away the moment it returns -- which is how
# an earlier version of this script "resolved" variables the evaluation step then could not
# see.
SET_N=0; BAD_N=0
sourceEach() {
	local file="$1" name line probe
	SET_N=0; BAD_N=0
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		name=${line%%=*}
		case "$name" in *[!A-Za-z0-9_]*|'') continue ;; esac
		if ! bash -n <<<"$line" 2>/dev/null; then
			BAD_N=$((BAD_N+1))
			continue
		fi
		eval "$line" < /dev/null 2>/dev/null
		eval "probe=\${$name-__UNSET__}"
		[ "$probe" != "__UNSET__" ] && SET_N=$((SET_N+1))
	done < "$file"
}

sourceEach "$WORK/paths.local.sh";    pSet=$SET_N; pBad=$BAD_N
# Computed vars AFTER the paths, since they grep the files the paths name.
sourceEach "$WORK/computed.local.sh"; cSet=$SET_N; cBad=$BAD_N
echo "path vars set: $pSet of $(wc -l < "$WORK/paths.local.sh")   computed vars set: $cSet of $(wc -l < "$WORK/computed.local.sh")"
[ "$((pBad + cBad))" -gt 0 ] && echo "  ($((pBad + cBad)) assignment(s) span multiple lines and cannot be evaluated here -- markers needing them SKIP)"

# Evaluate. Unresolvable paths -> SKIP, never a silent pass.
PASS=0; FAILN=0; SKIP=0
: > "$WORK/out.txt"
while read -r name want; do
	# A want that is itself a variable (`[ "$wt" = "$wu" ]`) has no literal to compare to.
	case "$want" in *'$'*)
		printf 'SKIP  %-8s (the chain compares it to another marker, not a literal)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue ;;
	esac
	line=$(grep -E "^${name}=" "$WORK/assign.local.sh" | head -1)
	if [ -z "$line" ]; then
		printf 'SKIP  %-8s (no assignment found in the payload)\n' "$name" >> "$WORK/out.txt"
		SKIP=$((SKIP+1)); continue
	fi
	# Does every file it reads exist here? ANY absolute path, not just container/... --
	# markers also read /etc/cron.d/..., /opt/stateful/..., /usr/..., which exist only inside
	# the image. Checking only the remapped paths made those read got=0 and report as real
	# failures, which is how this tool started crying wolf the moment it was widened.
	missing=0
	for f in $(grep -oE '(container|/etc|/opt|/usr|/var)/[A-Za-z0-9_./*-]+' <<<"$line" | sort -u); do
		case "$f" in
			# These roots exist on the dev box but hold the HOST's content, not the
			# image's, so a marker reading them gets a confident wrong number rather
			# than an error. Present-but-different is the worst of the three states.
			/etc/*|/usr/*|/var/*|/opt/stateful/*) missing=1; continue ;;
			*'*'*) continue ;;
		esac
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
	# ANY referenced variable, not just $z*. Markers build on other computed values too
	# ($wlin, $mancall, $manzip ...), and an unset one makes the command measure something
	# other than what it names -- a confident wrong answer dressed as a failure.
	unresolved=0
	for v in $(grep -oE '\$\{?[A-Za-z_][A-Za-z0-9_]*' <<<"$line" | tr -d '${' | sort -u); do
		case "$v" in NR|FS|OFS|RS|NF|PWD|HOME|PATH|IFS) continue ;; esac
		eval "val=\${$v-__UNSET__}"
		[ "$val" = "__UNSET__" ] && unresolved=1
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
