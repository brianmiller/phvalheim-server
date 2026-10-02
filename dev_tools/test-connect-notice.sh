#!/bin/bash
#
# 2.53 -- the one-shot "how players join has changed" notice.
#
# WHY THIS TEST EXISTS
# This notice is the only thing that tells an operator their modded worlds each need updating
# once. If it never fires, the change is silent. If it fires forever, the admin UI is unusable.
# Both failures have shipped before in this project:
#
#   - `?? 1` missing on the read: an undefined variable is null, PHP evaluates null == 0 as
#     TRUE, and the markup gates on == 0 -- so a server that had not yet run the migration got
#     the dialog on EVERY page load. The comment at the Hugin notice in index.php exists
#     because of this.
#   - seeded the wrong way round: 2.31 and 2.35 both shipped the setup wizard to the wrong
#     audience by guessing fresh-vs-upgrade instead of deriving it.
#
# THE CONTROL THAT MAKES IT AN ORACLE
# The gate is evaluated in real PHP against every combination of inputs, asserting both that
# it SHOWS when it should and that it does NOT show when it should not. Without the negative
# cases, a gate that never renders at all would pass.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MIG="$ROOT/container/engine/dbUpdates/dbUpdate_2.53.sh"
PULLER="$ROOT/container/nginx/www/includes/config_env_puller.php"
INDEX="$ROOT/container/nginx/www/admin/index.php"
API="$ROOT/container/nginx/www/admin/adminAPI.php"

fails=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; fails=$((fails + 1)); }

echo ""
echo "== site 1: the migration =="

[ -f "$MIG" ] || { echo "FATAL: $MIG does not exist"; exit 1; }

# Structure first. Everything below reasons about which branch a line sits in, and a script
# with an unbalanced if/fi makes that reasoning meaningless -- an earlier cut of this test was
# fooled by exactly that: a mutation removed a `fi`, the awk window ran to end-of-file, and
# the scope assertion below passed on a script that had been broken open.
if bash -n "$MIG" 2>/dev/null; then
	pass "migration parses"
else
	fail "migration does not parse -- the scope assertions below cannot be trusted"
fi

grep -q 'ADD COLUMN connectNoticeShown TINYINT NOT NULL DEFAULT 0' "$MIG" \
	&& pass "column added as TINYINT NOT NULL DEFAULT 0" \
	|| fail "column is not added with DEFAULT 0"

# The seeding must live INSIDE the column-does-not-exist branch. Every script in dbUpdates/
# runs on every boot, so seeding outside that branch would re-decide the answer on each
# restart -- and re-raise a dismissed notice forever.
#
# Asserted by INDENTATION DEPTH rather than by an awk window. Exactly two tabs means the
# statement is inside the outer column-missing branch and inside the inner count test. A
# hoisted copy lands at one tab or zero and fails here, and it fails independently of whether
# the surrounding if/fi structure is still intact.
grep -q '^		sql "UPDATE settings SET connectNoticeShown = 1;"' "$MIG" \
	&& pass "seeding is nested inside the column-missing branch (runs once, not every boot)" \
	|| fail "seeding is not nested where it should be -- it would re-run on every boot"

# Derived from MODDED worlds, which is the population the change actually affects.
grep -q 'COUNT(\*) FROM worlds WHERE IFNULL(vanilla,0) = 0' "$MIG" \
	&& pass "upgrade signal is the modded world count" \
	|| fail "upgrade signal is not the modded world count"

# Seeded quiet when there is nothing to say, loud when there is. Asserting the direction,
# because getting it backwards is the documented 2.31/2.35 failure.
awk '/moddedWorldCount" -eq 0/{f=1} f{print}' "$MIG" | head -4 | grep -q 'connectNoticeShown = 1' \
	&& pass "no modded worlds -> marked as seen (quiet)" \
	|| fail "no modded worlds does NOT mark the notice as seen"

echo ""
echo "== site 2: the read =="

grep -q "connectNoticeShown'\] ?? 1" "$PULLER" \
	&& pass "config_env_puller reads with ?? 1" \
	|| fail "config_env_puller is missing the ?? 1 default"

echo ""
echo "== site 3: the markup gate (evaluated in real PHP) =="

# Lift the actual gate condition out of index.php rather than restating it here. A restated
# condition tests this file's idea of the gate, not the shipped one.
#
# The pattern is deliberately TOLERANT of a missing `?? 1`. An exact-match grep looked
# stricter and was weaker: when the default was removed the grep simply found nothing, the
# test failed with "could not find the gate", and the PHP evaluation below -- the part that
# actually catches null == 0 -- never ran at all. Matching the broken form too is what lets
# the undefined-variable case be the thing that fails.
GATE=$(grep -oE 'if \(\$setupComplete == 2 && \(?\$connectNoticeShown( \?\? 1\))? == 0\)' "$INDEX" | head -1)
if [ -z "$GATE" ]; then
	fail "could not find the connectNoticeShown gate in index.php"
else
	pass "found the gate in index.php"

	# Named separately so the diagnosis is obvious, but the PHP cases below are the oracle.
	case "$GATE" in
		*'?? 1'*) pass "the gate carries the ?? 1 default" ;;
		*)        fail "the gate is missing ?? 1 -- an undefined flag would read as 0" ;;
	esac

	# $1=label $2=expect(show|hide) $3=php prelude
	gate() {
		got=$(php -r "$3 ${GATE} { echo 'show'; } else { echo 'hide'; }" 2>&1)
		if [ "$got" = "$2" ]; then pass "$1 -> $got"; else fail "$1 -> got '$got', expected '$2'"; fi
	}

	# The one case that must show it.
	gate "setup done, flag 0"            show '$setupComplete=2; $connectNoticeShown=0;'
	# Already dismissed. This is THE control: without it, a gate that never fires passes.
	gate "setup done, flag 1"            hide '$setupComplete=2; $connectNoticeShown=1;'
	# The null == 0 trap. An unset variable must NOT show it.
	gate "setup done, flag undefined"    hide '$setupComplete=2;'
	# Queued behind the setup wizard rather than stacked on top of it.
	gate "setup incomplete, flag 0"      hide '$setupComplete=0; $connectNoticeShown=0;'
	gate "setup migrated(1), flag 0"     hide '$setupComplete=1; $connectNoticeShown=0;'
fi

echo ""
echo "== site 4: the dismiss endpoint =="

grep -q "case 'dismissConnectNotice':" "$API" \
	&& pass "adminAPI has a dismissConnectNotice case" \
	|| fail "adminAPI has no dismissConnectNotice case"

grep -q 'UPDATE settings SET connectNoticeShown = 1' "$API" \
	&& pass "the endpoint sets the flag to 1" \
	|| fail "the endpoint does not set the flag"

# POST-only, like every other mutating endpoint in that file.
awk "/case 'dismissConnectNotice':/{f=1} f&&/break;/{exit} f{print}" "$API" \
	| grep -q "REQUEST_METHOD'\] === 'POST'" \
	&& pass "the endpoint is POST-only" \
	|| fail "the endpoint is not POST-gated"

# And the front end must actually call it, or the notice never clears.
grep -q "action=dismissConnectNotice" "$INDEX" \
	&& pass "index.php calls the dismiss endpoint" \
	|| fail "index.php never calls the dismiss endpoint -- the notice would return on reload"

echo ""
echo "== what the notice says =="

# The operator-facing facts that are not optional. Point 3 especially: someone who updates
# five worlds at peak and finds them all stopped has learned it the worst way.
# HTML COMMENTS ARE STRIPPED, and that is load-bearing rather than tidiness.
#
# The first cut of the negatives below failed against the FIXED file: the comment added above
# the paragraph, explaining why the catalogue wording was wrong, contains the very phrases the
# negatives search for. A comment documenting a removal is indistinguishable from the removed
# thing to a grep -- the same trap that made three build markers in this release match prose
# instead of behaviour. What the operator reads is the rendered text, so that is what is tested.
# WHITESPACE IS COLLAPSED, and that is load-bearing too. The phrase this test must catch is
# wrapped across a line in the source:
#
#     be installed once a Companion that can do the job is available in your mod
#     catalogue.
#
# so a search for "mod catalogue" finds nothing and reports the file CLEAN. Caught by running
# the negatives against the old wording and watching that one pass when it had to fail. Every
# phrase assertion below reads a single normalised line, the way an operator reads the sentence.
body=$(awk '/connectNoticeOverlay/{f=1} f&&/<\/script>/{exit} f{print}' "$INDEX" \
	| awk '/<!--/{c=1} !c{print} /-->/{c=0}' \
	| tr '\n' ' ' | tr -s ' ')
case "$body" in *"must be updated once"*) pass "says each world must be updated once" ;;
	*) fail "does not say each world must be updated once" ;; esac
case "$body" in *"stops it"*) pass "warns that updating a world stops it" ;;
	*) fail "does not warn that updating stops the world" ;; esac
case "$body" in *"Nothing breaks by waiting"*) pass "says nothing breaks by waiting" ;;
	*) fail "does not reassure that waiting is safe" ;; esac
case "$body" in *QuickConnect*) pass "names QuickConnect" ;;
	*) fail "does not name QuickConnect" ;; esac

# NEGATIVES -- claims that were TRUE of the design and FALSE of what shipped.
#
# The notice told operators QuickConnect "will no longer be installed once a Companion that can
# do the job is available in your mod catalogue". That was written while the Companion was still
# a Thunderstore package resolved at build time. It ships inside the image, so there is no
# catalogue, no version to wait for, and no condition left to satisfy -- the text asked the
# operator to wait for something that had already happened. Brian caught it; every assertion
# above passed on it, because they all check what the notice SAYS and none checked what it
# wrongly PROMISES.
case "$body" in *"mod catalogue"*)
	fail "still says the Companion comes from the mod catalogue -- it ships in the image" ;;
	*) pass "does not claim the Companion comes from a catalogue" ;; esac
case "$body" in *"once a Companion"*|*"will no longer be installed once"*)
	fail "still makes QuickConnect's retirement conditional on a future event" ;;
	*) pass "QuickConnect's retirement is stated as done, not pending" ;; esac
case "$body" in *"ships inside PhValheim"*)
	pass "says the Companion ships inside PhValheim" ;;
	*) fail "does not say the Companion ships inside PhValheim -- the operator cannot tell where it comes from" ;; esac

echo ""
if [ "$fails" -eq 0 ]; then
	echo "ALL PASS"
	exit 0
fi
echo "$fails FAILURE(S)"
exit 1
