#!/bin/sh
# Oracle test: the "your players must update the client" dialog, raised by the first world UPDATE.
#
# WHY IT EXISTS
# A world updated to 2.53 has a password and no QuickConnect. A player on client 2.0.13 gets
# neither: the old client does not pass the launch payload, so the Companion never learns the
# password, and there is no server-list entry any more. They can still join by hand, but the
# one-click path is gone. The operator is the only person who can tell them, and nothing else
# in the product does.
#
# WHAT MAKES IT HARD TO GET RIGHT
# The state is a TRI-state, because "has not happened yet" and "the operator dismissed it" are
# different answers:   0 = not triggered   1 = pending   2 = dismissed
# A boolean cannot hold both, and collapsing them fails in one of two directions: the dialog
# appears on a server that has updated nothing, or it comes back after every world update
# forever. Both are asserted below, because only testing one of them is how this ships broken.
#
# Usage:  sh dev_tools/test-client-update-notice.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
MIG="$REPO/container/engine/dbUpdates/dbUpdate_2.53.sh"
FUNCS="$REPO/container/engine/includes/0-functions.sh"
ENGINE="$REPO/container/engine/phvalheim"
PULLER="$REPO/container/nginx/www/includes/config_env_puller.php"
INDEX="$REPO/container/nginx/www/admin/index.php"
API="$REPO/container/nginx/www/admin/adminAPI.php"
RESET="$REPO/dev_tools/resetNotices.sh"

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== the column =="

grep -q 'ADD COLUMN clientUpdateNoticeState TINYINT NOT NULL DEFAULT 0' "$MIG" \
	&& pass "added as TINYINT NOT NULL DEFAULT 0 (a fresh upgrade is silent)" \
	|| fail "column is not added with DEFAULT 0" \
	        "DEFAULT 1 would raise the dialog on every server the moment it upgrades"

echo
echo "== the trigger: fires once, on a world UPDATE =="

grep -q '^function noticeClientUpdateRequired()' "$FUNCS" \
	&& pass "noticeClientUpdateRequired() exists" \
	|| { fail "noticeClientUpdateRequired() is missing"; echo; echo "$PASS passed, $FAIL failed"; exit 1; }

# The WHERE clause is the whole mechanism. Without it the UPDATE promotes a DISMISSED notice
# (2) back to pending (1), and the operator meets the dialog once per world forever.
grep -q 'SET clientUpdateNoticeState = 1 WHERE clientUpdateNoticeState = 0' "$FUNCS" \
	&& pass "only promotes 0 -> 1, so a dismissed notice cannot be revived" \
	|| fail "the promotion is not scoped to state 0" \
	        "a dismissed (2) or pending (1) notice would be rewritten, re-showing it per world"

grep -q 'noticeClientUpdateRequired "\$worldName"' "$ENGINE" \
	&& pass "the engine calls it" || fail "nothing calls it -- the dialog can never appear"

# It must sit AFTER packageClient, inside the modded branch. Everything earlier in that branch
# can still bail out -- a failed mod install `continue`s -- and announcing an update that did
# not finish gives the operator an instruction they cannot act on.
pkgLine=$(grep -n 'packageClient "\$worldName"' "$ENGINE" | head -1 | cut -d: -f1)
trigLine=$(grep -n 'noticeClientUpdateRequired "\$worldName"' "$ENGINE" | head -1 | cut -d: -f1)
if [ -n "$pkgLine" ] && [ -n "$trigLine" ]; then
	[ "$trigLine" -gt "$pkgLine" ] \
		&& pass "fires AFTER packageClient (line $trigLine > $pkgLine), so a failed update stays quiet" \
		|| fail "fires before packageClient (line $trigLine < $pkgLine)" \
		        "a world whose mod install failed would still announce itself as updated"
fi

# CONTROL: a VANILLA world must never raise it. A vanilla world has no Companion, no payload
# and no password change -- nothing about its players' client changed.
vanillaBranch=$(awk '/is vanilla -- skipping BepInEx/{f=1} f&&/^				else$/{exit} f{print}' "$ENGINE")
case "$vanillaBranch" in *noticeClientUpdateRequired*)
	fail "the vanilla branch raises the client-update notice" \
	     "nothing about a vanilla world's players changed" ;;
	*) pass "control: the vanilla branch does NOT raise it" ;; esac

echo
echo "== the read and the gate (evaluated in real PHP) =="

grep -q "clientUpdateNoticeState'\] ?? 0" "$PULLER" \
	&& pass "config_env_puller reads with ?? 0 (a missing column means 'nothing happened')" \
	|| fail "the puller is missing the ?? 0 default"

GATE=$(grep -oE 'if \(\$setupComplete == 2 && \(?\$clientUpdateNoticeState( \?\? 0\))? == 1\)' "$INDEX" | head -1)
if [ -z "$GATE" ]; then
	fail "could not find the clientUpdateNoticeState gate in index.php"
else
	pass "found the gate in index.php"
	gate() {
		got=$(php -r "$3 ${GATE} { echo 'show'; } else { echo 'hide'; }" 2>&1)
		if [ "$got" = "$2" ]; then pass "$1 -> $got"; else fail "$1 -> got '$got', expected '$2'"; fi
	}
	# The one state that shows it.
	gate "setup done, state 1 (pending)"   show '$setupComplete=2; $clientUpdateNoticeState=1;'
	# THE TWO CONTROLS. Without both, a tri-state collapsed to a boolean passes.
	gate "setup done, state 0 (untriggered)" hide '$setupComplete=2; $clientUpdateNoticeState=0;'
	gate "setup done, state 2 (dismissed)"   hide '$setupComplete=2; $clientUpdateNoticeState=2;'
	# An undefined flag must be silent -- the mirror of the null == 0 trap the other notices hit.
	gate "setup done, flag undefined"        hide '$setupComplete=2;'
	# Queued behind the setup wizard, like every other notice.
	gate "setup incomplete, state 1"         hide '$setupComplete=0; $clientUpdateNoticeState=1;'
fi

echo
echo "== the dismiss endpoint =="

grep -q "case 'dismissClientUpdateNotice':" "$API" \
	&& pass "adminAPI has a dismissClientUpdateNotice case" || fail "no dismiss endpoint"

# 2, not 0. Writing 0 would say "not triggered", and the next world update would raise it again.
grep -q 'UPDATE settings SET clientUpdateNoticeState = 2' "$API" \
	&& pass "dismiss writes 2 (dismissed), not 0 (not triggered)" \
	|| fail "dismiss does not write 2" \
	        "writing 0 re-arms it -- the operator would see this dialog once per world updated"

awk "/case 'dismissClientUpdateNotice':/{f=1} f&&/break;/{exit} f{print}" "$API" \
	| grep -q "REQUEST_METHOD'\] === 'POST'" \
	&& pass "the endpoint is POST-only" || fail "the endpoint is not POST-gated"

grep -q "action=dismissClientUpdateNotice" "$INDEX" \
	&& pass "index.php calls the dismiss endpoint" \
	|| fail "index.php never calls it -- the dialog would return on every reload"

echo
echo "== what it says =="

body=$(awk '/clientUpdateNoticeOverlay/{f=1} f&&/<\/script>/{exit} f{print}' "$INDEX" \
	| awk '/<!--/{c=1} !c{print} /-->/{c=0}' | tr '\n' ' ' | tr -s ' ')

case "$body" in *"needs the current PhValheim client"*) pass "says the client must be updated" ;;
	*) fail "does not say the client must be updated -- that is the entire point" ;; esac
case "$body" in *"Nobody is locked out"*) pass "says nobody is locked out" ;;
	*) fail "does not say nobody is locked out" \
	        "an operator who thinks their players cannot get in will panic -- they can, by hand" ;; esac
case "$body" in *QuickConnect*) pass "explains the server-list entry is gone" ;;
	*) fail "does not mention QuickConnect/the server list" ;; esac
case "$body" in *"shown once"*) pass "says it is shown once" ;;
	*) fail "does not say it is shown once" ;; esac

# NEGATIVE: it must not assert the OPERATOR did the update. Automatic updates run the same path
# unattended, so on a server with autoupdate_mode set the dialog arrives having done nothing --
# which is how Brian first met it, minutes after the container came up. Telling someone "you did
# X" when they did not reads as a bug and buries the instruction.
case "$body" in *"You have updated a world"*)
	fail "claims the operator updated a world" \
	     "auto-update fires this path too -- the dialog must not assert who did it" ;;
	*) pass "does not claim the operator did the update" ;; esac
case "$body" in *"automatic updates"*)
	pass "names automatic updates as a way this happens on its own" ;;
	*) fail "does not mention automatic updates" \
	        "an operator who did nothing has no way to explain the dialog" ;; esac

echo
echo "== the reset tool knows its armed value =="

grep -q 'clientUpdate:clientUpdateNoticeState:1:' "$RESET" \
	&& pass "resetNotices.sh arms it to 1, not 0" \
	|| fail "resetNotices.sh does not list it with armed value 1" \
	        "arming to 0 means 'not triggered', so the dialog would not appear"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
