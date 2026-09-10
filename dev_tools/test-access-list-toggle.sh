#!/bin/bash
# Oracle test for the "Use Access List" switch (formerly "Public World").
#
# THE RISK: 2.40 renamed the switch AND INVERTED its sense. It reads "Use Access
# List" -- on means the Citizens list is enforced -- but the column behind it is
# still worlds.public, which means the opposite. Three places in index.php do that
# inversion by hand. Get one backwards and a world silently flips from "citizens
# only" to "anyone may join", or locks its own players out. Neither says a word.
#
# Assertions are on what actually lands: the worlds.public COLUMN and the bytes of
# permittedlist.txt, which is the file Valheim reads. The layout test that ships
# alongside this one drives the same switch and passes either way -- it only ever
# looks at which DOM node is visible, so it cannot see an inverted save.
#
# The UI is driven through a real browser, because the inversion lives in the page's
# JavaScript. Posting to adminAPI.php directly would bypass the very code under test.
#
# Usage: dev_tools/test-access-list-toggle.sh [container] [adminURL] [world]

C="${1:-phvalheim-dev}"
URL="${2:-http://127.0.0.1:8081}"
W="${3:-midgard}"
IMG="mcr.microsoft.com/playwright:v1.47.0-jammy"
MARK="V_76561197960287930"

pass=0; fail=0
check () { # $1=name $2=ok $3=detail
	if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
	else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

SAVEDIR="/opt/stateful/games/valheim/worlds/$W/game/.config/unity3d/IronGate/Valheim"
PLIST="$SAVEDIR/permittedlist.txt"

q () { docker exec "$C" mysql -N -e "$1" 2>/dev/null; }
plist () { docker exec "$C" sh -c "cat '$PLIST' 2>/dev/null" | grep -v '^//' | grep -v '^[[:space:]]*$'; }

# --------------------------------------------------------------- capture first
# This world is Brian's. Capture BEFORE touching anything and restore at the end --
# a previous run of this kind overwrote a citizens list that had no backup.
ORIG_PUBLIC=$(q "select public from phvalheim.worlds where name='$W'")
ORIG_CITIZENS=$(q "select ifnull(citizens,'') from phvalheim.worlds where name='$W'")
if [ -z "$ORIG_PUBLIC" ]; then
	echo "world '$W' not found in $C -- cannot run."; exit 1
fi
docker exec "$C" sh -c "cp '$PLIST' '$PLIST.testbak' 2>/dev/null" || true
echo "Captured $W: public='$ORIG_PUBLIC' citizens='$(echo "$ORIG_CITIZENS" | tr '\n' ' ' | cut -c1-40)'"

restore () {
	q "update phvalheim.worlds set public='$ORIG_PUBLIC', citizens='$ORIG_CITIZENS' where name='$W'" >/dev/null
	docker exec "$C" sh -c "[ -f '$PLIST.testbak' ] && mv '$PLIST.testbak' '$PLIST'" >/dev/null 2>&1
	echo; echo "Restored $W to public='$ORIG_PUBLIC'."
}
trap restore EXIT

# Seed a known id so "did the list reach the file" is answerable.
q "update phvalheim.worlds set citizens='$MARK' where name='$W'" >/dev/null

# --------------------------------------------------------------- the driver
# $1 = "on" or "off": the state to leave the switch in before clicking Save Access.
drive () {
	docker run --rm --network host -e WANT="$1" -e ADMINURL="$URL" -e WORLD="$W" "$IMG" bash -c '
		npm i --silent --prefix /tmp/pw playwright@1.47.0 >/dev/null 2>&1
		NODE_PATH=/tmp/pw/node_modules node -e "
		const { chromium } = require(\"playwright\");
		(async () => {
			const b = await chromium.launch();
			const p = await b.newPage();
			await p.goto(process.env.ADMINURL + \"/index.php\", { waitUntil: \"networkidle\", timeout: 60000 });
			await p.evaluate((w) => showSettingsModal(w), process.env.WORLD);
			await p.waitForSelector(\"#settingsAccessListToggle\", { state: \"attached\", timeout: 15000 });
			await p.waitForTimeout(700);
			await p.click(\".backup-tab[data-tab=accessTab]\");
			await p.waitForTimeout(400);
			const want = process.env.WANT === \"on\";
			await p.evaluate((want) => {
				const t = document.getElementById(\"settingsAccessListToggle\");
				if (t.checked !== want) { t.checked = want; t.dispatchEvent(new Event(\"change\")); }
			}, want);
			await p.waitForTimeout(300);
			// Click the real button, not saveAccessPublic() directly.
			const btns = await p.\$\$(\"#accessTab .action-btn\");
			for (const btn of btns) {
				if ((await btn.innerText()).trim() === \"Save Access\") { await btn.click(); break; }
			}
			await p.waitForTimeout(2500);
			await b.close();
		})();
		"' >/dev/null 2>&1
}

# --------------------------------------------------------------- arm 1: ON
echo
echo "Case 1: switch ON = access list ENFORCED"
drive on
P1=$(q "select public from phvalheim.worlds where name='$W'")
F1=$(plist)
[ "$P1" = "0" ] && ok=1 || ok=0
check "worlds.public stored as 0 (not public)" "$ok" "public=$P1"
echo "$F1" | grep -q "$MARK" && ok=1 || ok=0
check "permittedlist.txt CONTAINS the citizen id" "$ok" "file=[$(echo "$F1" | tr '\n' ' ')]"

# --------------------------------------------------------------- arm 2: OFF
echo
echo "Case 2: switch OFF = anyone may join"
drive off
P2=$(q "select public from phvalheim.worlds where name='$W'")
F2=$(plist)
C2=$(q "select ifnull(citizens,'') from phvalheim.worlds where name='$W'")
[ "$P2" = "1" ] && ok=1 || ok=0
check "worlds.public stored as 1 (public)" "$ok" "public=$P2"
[ -z "$F2" ] && ok=1 || ok=0
check "permittedlist.txt is EMPTY of ids" "$ok" "file=[$(echo "$F2" | tr '\n' ' ')]"
# The list must be kept in the database, or switching the gate off destroys it.
echo "$C2" | grep -q "$MARK" && ok=1 || ok=0
check "the citizens list is KEPT in the db, not cleared" "$ok" "citizens=[$C2]"

# --------------------------------------------------------------- control
echo
echo "Case 3: CONTROL -- the two arms must actually differ"
# If saveAccessPublic() were wired to nothing, every assertion above could still
# pass by accident on a world that happened to start in the right state.
[ "$P1" != "$P2" ] && ok=1 || ok=0
check "the switch changes the stored flag at all" "$ok" "arm1=$P1 arm2=$P2"
[ "$F1" != "$F2" ] && ok=1 || ok=0
check "...and changes the file Valheim reads" "$ok" "arm1=[$F1] arm2=[$F2]"

echo
echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
