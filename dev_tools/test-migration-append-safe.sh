#!/bin/bash
# The current release's migration must accept new blocks.
#
# WHY THIS EXISTS
#
# While building the action layer I asserted that "each migration self-gates with `exit 2`
# once applied, so anything added to dbUpdate_2.45.sh would never run on a server that has
# already applied it", created a dbUpdate_2.46.sh, and bumped the Dockerfile -- a release
# decision that was not mine to make, taken to route around a problem that did not exist.
#
# dbUpdate_2.45.sh has no top-level gate at all. It is object-by-object idempotent, and its
# own header says so, for precisely this reason. `exit 2` gating is a LEGACY pattern (2.7
# through 2.38); every migration from 2.40 on is append-safe by construction.
#
# So this turns "can I append to the current migration?" from something to assume into
# something to run. It also fails on the symptom of getting it wrong: a migration file for a
# version newer than the Dockerfile.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/container/engine/dbUpdates"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

VERSION="$(grep -oP 'ENV phvalheimVersion=\K[0-9.]+' "$ROOT/Dockerfile")"
CUR="$DIR/dbUpdate_$VERSION.sh"

printf '\n\033[1mMigrations vs Dockerfile version %s\033[0m\n' "$VERSION"

# --- 1. no migration may claim a version the build does not ---------------------------
#
# This is the exact shape of the mistake: a dbUpdate_2.46.sh sitting beside a 2.45 build.
# Either the version bump was decided and the Dockerfile should say so, or the schema
# belongs in the current release's file.
ahead=""
for f in "$DIR"/dbUpdate_*.sh; do
	v="$(basename "$f" .sh)"; v="${v#dbUpdate_}"
	# Sort numerically by version; anything above $VERSION is ahead of the build.
	if [ "$(printf '%s\n%s\n' "$VERSION" "$v" | sort -V | tail -1)" != "$VERSION" ]; then
		ahead="$ahead $v"
	fi
done
if [ -z "$ahead" ]; then
	ok "no migration exists for a version newer than the build"
else
	bad "migration(s) ahead of Dockerfile $VERSION:$ahead — bump the version deliberately, or fold the schema into dbUpdate_$VERSION.sh"
fi

# --- 2. the current version's migration, IF it has one --------------------------------
#
# A release with no schema change legitimately has no migration file, and demanding one
# would push the next person to commit an empty script purely to satisfy this gate --
# which is the same "route around the check" move this file exists to prevent.
#
# Not a guess: v2.35, 2.36, v2.37, v2.39 and v2.41 all shipped with no dbUpdate of their
# own. This check used to fail the build for them.
#
# Check 1 above is the real guard, and it is unaffected: a migration NEWER than the
# Dockerfile is still a failure whether or not the current version has one.
if [ -f "$CUR" ]; then
	ok "dbUpdate_$VERSION.sh exists"
else
	printf '  \033[33mSKIP\033[0m  no dbUpdate_%s.sh — fine if %s changes no schema, and checks 3-4 have nothing to inspect\n' "$VERSION" "$VERSION"
	# Must still honour check 1. The first cut of this branch exited 0 unconditionally and
	# swallowed a genuine "migration ahead of the build" failure it had just printed.
	if [ "$FAIL" -gt 0 ]; then
		printf '\n\033[31m%s failure(s)\033[0m\n' "$FAIL"; exit 1
	fi
	printf '\n\033[32m%s passed, 0 failures\033[0m\n' "$PASS"; exit 0
fi

# --- 3. it must be append-safe --------------------------------------------------------
#
# A top-level `exit 2` means "already applied, stop here", so a block appended after an RC
# shipped would never run for the people who tested that RC -- the ones most likely to
# upgrade in place.
if grep -qE '^[[:space:]]*exit[[:space:]]+2\b' "$CUR"; then
	bad "dbUpdate_$VERSION.sh has a top-level 'exit 2' gate — appended blocks would never run on a server that already applied it"
else
	ok "no top-level 'already applied' gate — new blocks run on every boot"
fi

# --- 4. every object it creates must be individually guarded --------------------------
#
# Append-safety is only real if re-running is harmless. An unguarded CREATE TABLE turns the
# second boot into an error, and dbUpdater runs this on EVERY boot.
creates=$(grep -cE 'CREATE TABLE' "$CUR")
guarded=$(grep -cE 'CREATE TABLE IF NOT EXISTS' "$CUR")
ifblocks=$(grep -cE 'if ! tableExists' "$CUR")
if [ "$creates" -le $((guarded + ifblocks)) ]; then
	ok "all $creates CREATE TABLE statements are guarded ($ifblocks via tableExists, $guarded via IF NOT EXISTS)"
else
	bad "$creates CREATE TABLE statements but only $((guarded + ifblocks)) guards — a second boot would error"
fi

# ALTER TABLE ... ADD COLUMN must go through addColumn(), which checks DESCRIBE first.
raw_add=$(grep -cE 'ALTER TABLE [^;]*ADD COLUMN' "$CUR")
in_helper=$(awk '/^addColumn\(\)/,/^}/' "$CUR" | grep -cE 'ALTER TABLE [^;]*ADD COLUMN')
if [ "$raw_add" -le "$in_helper" ]; then
	ok "no raw ADD COLUMN outside the addColumn() helper"
else
	bad "$((raw_add - in_helper)) raw ALTER TABLE ... ADD COLUMN outside addColumn() — re-running would error"
fi

# --- 5. it must actually re-run clean --------------------------------------------------
#
# Static checks can only see shape. Running it twice against a real database is the real
# proof, and that is what dev_tools/test-ai-actions.sh does -- note it here so the two are
# not mistaken for the same coverage.
ok "(runtime idempotence is proven separately by test-ai-actions.sh)"

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mMigration is append-safe\033[0m (%s checks)\n' "$PASS"; exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
