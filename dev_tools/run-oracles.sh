#!/bin/bash
# Run the dev_tools oracles, partitioned by what they need to run.
#
# WHY A PARTITION AT ALL. There are over a hundred test files here and they are not one kind of
# thing: some are pure unit oracles that run anywhere, and some drive a live container with
# `docker exec` against a running MySQL. Putting all of them in one CI job means the whole gate
# is as fragile as the heaviest test, and the usual outcome is that the gate gets disabled.
#
# THE RULE THAT KEEPS THIS HONEST: every test file must land in exactly one bucket, and the
# counts must add up to the total. A test that matched neither pattern would otherwise vanish
# from CI while the summary still said "all tests passed" -- a cap nobody declared. --list
# prints the partition so it can be read, and the adds-up check below is fatal.
#
# Usage:
#   run-oracles.sh --list                 show the partition and exit
#   run-oracles.sh --standalone           run the tests that need no container
#   run-oracles.sh --container            run the tests that drive a live container
#   run-oracles.sh --all                  both
#
# Exit 0 only if every test in the selected bucket exited 0.

set -u

REPO=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DEV="$REPO/dev_tools"

# A test needs a live container if it shells into one. This is the only INFERRED signal that is
# actually load-bearing -- a test can mention mysql, php or an /opt/stateless path and still run
# entirely on fixtures. Ten of the standalone tests reference container paths and pass fine, so
# partitioning on a path would wrongly exile them to the slow bucket.
CONTAINER_RE='docker[[:space:]]+(exec|cp)|docker[[:space:]]+ps|docker[[:space:]]+run'

# ...and a test can DECLARE the requirement itself with a `requires: container` line in its
# header. That is better than any regex here: the test knows what it needs, the reason sits
# next to the code that needs it, and widening the regex cannot silently reclassify anything.
# Two tests are container-only for reasons no call-site grep could see: test-save-summary.php
# includes absolute in-image paths, and test-mod-config-editor.sh drives modConfigs.py, which
# chowns to a user that exists only in the image.
REQUIRES_RE='^[[:space:]]*(#|\*|//)?[[:space:]]*requires:[[:space:]]*container'

shopt -s nullglob
all=("$DEV"/test-*)
shopt -u nullglob

# An empty glob must not read as success. This is the shape that makes a test gate pass
# vacuously, and it has bitten this project before.
if [ "${#all[@]}" -eq 0 ]; then
	echo "FATAL: no test files matched $DEV/test-* -- refusing to report a pass."
	exit 1
fi

standalone=(); container=()
for t in "${all[@]}"; do
	if grep -qiE "$REQUIRES_RE" "$t" 2>/dev/null; then
		container+=("$t")
	elif grep -qE "$CONTAINER_RE" "$t" 2>/dev/null; then
		container+=("$t")
	else
		standalone+=("$t")
	fi
done

if [ $(( ${#standalone[@]} + ${#container[@]} )) -ne "${#all[@]}" ]; then
	echo "FATAL: the partition lost files: ${#standalone[@]} + ${#container[@]} != ${#all[@]}"
	exit 1
fi

mode="${1:---list}"

if [ "$mode" = "--list" ]; then
	echo "total test files: ${#all[@]}"
	echo
	echo "STANDALONE (${#standalone[@]}) -- no container needed:"
	for t in "${standalone[@]}"; do echo "  $(basename "$t")"; done
	echo
	echo "CONTAINER (${#container[@]}) -- drives a live phvalheim container:"
	for t in "${container[@]}"; do echo "  $(basename "$t")"; done
	exit 0
fi

# How to run a file, by extension. A test we cannot run must be reported, never skipped.
runOne(){
	local t="$1"
	case "$t" in
		*.sh)  bash "$t" ;;
		*.php) php "$t" ;;
		*.py)  python3 "$t" ;;
		*.js)  node "$t" ;;
		*)     echo "  (no runner for $(basename "$t"))"; return 2 ;;
	esac
}

selected=()
case "$mode" in
	--standalone) selected=("${standalone[@]}") ;;
	--container)  selected=("${container[@]}") ;;
	--all)        selected=("${all[@]}") ;;
	*) echo "usage: $0 --list|--standalone|--container|--all"; exit 1 ;;
esac

if [ "${#selected[@]}" -eq 0 ]; then
	echo "FATAL: the '$mode' bucket is empty -- not reporting a pass over nothing."
	exit 1
fi

cd "$REPO" || exit 1
mkdir -p "$REPO/.oracle-logs"

passed=0; failed=0; unrunnable=0
failedNames=(); unrunnableNames=()

echo "running ${#selected[@]} test file(s) [$mode]"
echo

for t in "${selected[@]}"; do
	name=$(basename "$t")
	log="$REPO/.oracle-logs/$name.log"
	if runOne "$t" > "$log" 2>&1; then
		echo "  ok    $name"
		passed=$((passed+1))
	else
		rc=$?
		if [ "$rc" -eq 2 ] && grep -q 'no runner for' "$log"; then
			echo "  ????  $name  (no runner)"
			unrunnable=$((unrunnable+1)); unrunnableNames+=("$name")
		else
			echo "  FAIL  $name  (exit $rc)"
			failed=$((failed+1)); failedNames+=("$name")
			tail -25 "$log" | sed 's/^/          /'
		fi
	fi
done

echo
echo "=============================================================="
echo " $mode: $passed passed, $failed failed, $unrunnable unrunnable"
echo "=============================================================="
if [ "$failed" -gt 0 ]; then
	echo "failed:"
	for n in "${failedNames[@]}"; do echo "  $n"; done
fi
if [ "$unrunnable" -gt 0 ]; then
	echo "unrunnable (counted as failures -- a test nothing can run gates nothing):"
	for n in "${unrunnableNames[@]}"; do echo "  $n"; done
fi

[ "$failed" -eq 0 ] && [ "$unrunnable" -eq 0 ]
