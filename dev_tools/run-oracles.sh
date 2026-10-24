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
# --container is driven through dev_tools/oracle-container-env.sh (2.58): it seeds the fixture
# worlds and files the browser tests read, passes each test the arguments its header
# documents, runs in-container PHP and in-image bash where they must run, boots the Hugin
# suites from the image under test, and reports a test whose precondition this host cannot
# provide as UNRUNNABLE -- which is NOT a pass: the bucket exits non-zero until the
# precondition is met or the test is marked `requires: manual`.
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
# `requires: manual` -- a test that needs a state no runner can seed (a pre-2.53 checkout to
# diff against, a hardware device). Listed by --list, never run, counted in the partition so
# it cannot vanish; the text after the marker is the reason.
MANUAL_RE='^[[:space:]]*(#|\*|//)?[[:space:]]*requires:[[:space:]]*manual'

# An HTTP call to the admin or public UI needs the container running just as much as a
# `docker exec` does. Found by running this in CI: test-create-requires-steamid.js and
# test-empty-accesslist-refused.js POST to 127.0.0.1:8081, passed here on a box with a live
# container, and died with ECONNREFUSED on a clean runner.
HTTP_RE='127\.0\.0\.1:80[0-9]{2}|localhost:80[0-9]{2}'

# THE THIRD BUCKET. Some oracles read a SIBLING REPOSITORY -- ../phvalheim-client/Arguments.cs
# for the launch-payload contract, ../phvalheim-companion for the Companion's source and its
# reachability test. Those are real cross-repo contracts and the tests are right to check
# them, but a single-repo checkout cannot: the file is simply absent and the test fails for
# its environment rather than for the contract.
#
# They are NOT lumped in with the container bucket. Mixing them would hide what each needs,
# and they are the cheaper gap to close -- a second `actions/checkout` with a `path:` would
# bring them into CI, whereas the container bucket needs the image built and MariaDB up.
CROSSREPO_RE='\.\./phvalheim-(client|companion)'

shopt -s nullglob
all=("$DEV"/test-*)
shopt -u nullglob

# An empty glob must not read as success. This is the shape that makes a test gate pass
# vacuously, and it has bitten this project before.
if [ "${#all[@]}" -eq 0 ]; then
	echo "FATAL: no test files matched $DEV/test-* -- refusing to report a pass."
	exit 1
fi

standalone=(); container=(); crossrepo=(); manual=()
for t in "${all[@]}"; do
	if grep -qiE "$MANUAL_RE" "$t" 2>/dev/null; then
		manual+=("$t")
	elif grep -qiE "$REQUIRES_RE" "$t" 2>/dev/null \
	   || grep -qE "$CONTAINER_RE" "$t" 2>/dev/null \
	   || grep -qE "$HTTP_RE" "$t" 2>/dev/null; then
		container+=("$t")
	elif grep -qE "$CROSSREPO_RE" "$t" 2>/dev/null; then
		crossrepo+=("$t")
	else
		standalone+=("$t")
	fi
done

if [ $(( ${#standalone[@]} + ${#container[@]} + ${#crossrepo[@]} + ${#manual[@]} )) -ne "${#all[@]}" ]; then
	echo "FATAL: the partition lost files: ${#standalone[@]} + ${#container[@]} + ${#crossrepo[@]} + ${#manual[@]} != ${#all[@]}"
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
	echo
	echo "CROSS-REPO (${#crossrepo[@]}) -- reads ../phvalheim-client or ../phvalheim-companion:"
	for t in "${crossrepo[@]}"; do echo "  $(basename "$t")"; done
	echo
	echo "MANUAL (${#manual[@]}) -- needs a state no runner can seed; run by hand, never counted as passed:"
	for t in "${manual[@]}"; do echo "  $(basename "$t")  ($(grep -ioE 'requires:[[:space:]]*manual.*' "$t" | head -1 | sed -E 's/requires:[[:space:]]*manual[[:space:]:-]*//'))"; done
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
	--crossrepo)  selected=("${crossrepo[@]}") ;;
	--all)        selected=("${all[@]}") ;;
	*) echo "usage: $0 --list|--standalone|--container|--crossrepo|--all"; exit 1 ;;
esac

if [ "${#selected[@]}" -eq 0 ]; then
	echo "FATAL: the '$mode' bucket is empty -- not reporting a pass over nothing."
	exit 1
fi

# Playwright is not a dependency of this repo; the browser tests find it through NODE_PATH.
# It lives in a sibling checkout on the dev box. Left unset, 27 of the container tests fail
# with "Cannot find module 'playwright'" and the bucket reports 45 failures that have
# nothing to do with the product (2026-10-22). An explicit NODE_PATH wins; the sibling is
# only a default. This MUST precede the container env's oracleCheck below: that is where
# Playwright is resolved once for the whole run, and with the default applied after it every
# browser test came back UNRUNNABLE from a shell that had not exported NODE_PATH itself --
# 30 of 70 on the 2.58 :dev run (2026-10-23), from a runner that was supposed to end that.
if [ -z "${NODE_PATH:-}" ] && [ -d "$REPO/../.pw-ui/node_modules/playwright" ]; then
	export NODE_PATH="$(cd "$REPO/../.pw-ui/node_modules" && pwd)"
fi
cd "$REPO" || exit 1
if [ "$mode" = "--container" ]; then
	# shellcheck source=oracle-container-env.sh
	. "$DEV/oracle-container-env.sh"
	oracleCheck
	echo
	oracleSeed || { echo "FATAL: the container bucket could not be seeded; nothing run."; exit 1; }
	trap oracleTeardown EXIT
	echo
fi
mkdir -p "$REPO/.oracle-logs"

passed=0; failed=0; unrunnable=0
failedNames=(); unrunnableNames=()

echo "running ${#selected[@]} test file(s) [$mode]"
echo

for t in "${selected[@]}"; do
	name=$(basename "$t")
	log="$REPO/.oracle-logs/$name.log"
	if [ "$mode" = "--container" ]; then
		reason=$(oracleUnrunnable "$name")
		if [ -n "$reason" ]; then
			echo "  ????  $name  (UNRUNNABLE: $reason)"
			unrunnable=$((unrunnable+1)); unrunnableNames+=("$name")
			continue
		fi
		oraclePre "$name"
		run() { local rc; oracleRun "$name" "$t"; rc=$?; oraclePost "$name"; return $rc; }
	else
		run() { runOne "$t"; }
	fi
	if run > "$log" 2>&1; then
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
