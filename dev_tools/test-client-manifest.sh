#!/bin/bash
# Oracle test: the client manifest, and the Companion notice that reads it.
#
# WHY IT EXISTS
# A client older than $clientMinVersion does not pass --phvalheim-launch, so from 2.53 the
# Companion woke up knowing nothing: no world name, no address, and -- because
# FejdStartupPatch returned early with no payload -- nothing on screen either. The world it
# was installed for now has a password and no QuickConnect entry, so that silence is the
# difference between a player joining and a player stuck on the main menu.
#
# writeClientManifest() is the fix on the server side. It is the only thing that puts the
# world's identity inside the payload, and it runs exactly once per packaging, so there is no
# second chance and nothing downstream that would notice it had been skipped.
#
# WHAT MAKES IT EASY TO GET WRONG
#   * The manifest must be written BEFORE the zip. Written after, it ships in the NEXT
#     payload and therefore describes the world as it was one update ago.
#   * It must carry NO PASSWORD. argv lives for one process; this file sits on every player's
#     disk. A later "convenience" that prefills the password would quietly turn every client
#     install into a copy of the world's credentials.
#   * Its failure must not be fatal. A payload with no manifest is exactly what 2.52 shipped.
#     Losing the whole zip over a missing explanatory file would be the larger harm.
#
# Run with bash, not sh: the engine declares functions as `function name()`, which dash
# rejects outright -- a harness run under sh silently tests nothing at all.
#
# Usage:  bash dev_tools/test-client-manifest.sh

REPO=$(cd "$(dirname "$0")/.." && pwd)
FUNCS="$REPO/container/engine/includes/0-functions.sh"
CONF="$REPO/container/engine/includes/phvalheim-static.conf"
DLL="$REPO/container/games/valheim/custom_plugins/PhValheimCompanion/PhValheimCompanion.dll"

PASS=0; FAIL=0
pass(){ echo "  PASS: $1"; PASS=$((PASS+1)); }
fail(){ echo "  FAIL: $1"; [ -n "$2" ] && echo "        $2"; FAIL=$((FAIL+1)); }

echo
echo "== the minimum version is one constant =="

grep -qE '^clientMinVersion="[0-9]+\.[0-9]+\.[0-9]+"' "$CONF" \
	&& pass "clientMinVersion is set in phvalheim-static.conf ($(grep -oE '^clientMinVersion="[^"]*"' "$CONF"))" \
	|| fail "clientMinVersion is missing or not a version" \
	        "the manifest would ship an empty requirement and the notice would omit it"

echo
echo "== the writer runs, and runs before the zip =="

grep -q '^function writeClientManifest()' "$FUNCS" \
	&& pass "writeClientManifest() exists" \
	|| { fail "writeClientManifest() is missing"; echo; echo "$PASS passed, $FAIL failed"; exit 1; }

grep -q 'writeClientManifest "\$worldName"' "$FUNCS" \
	&& pass "packageClient calls it" || fail "nothing calls it -- no payload would carry a manifest"

# ORDER IS THE WHOLE POINT. Compared by line number inside packageClient, against the `zip`
# that actually builds the payload.
pkg=$(grep -n '^function packageClient()' "$FUNCS" | head -1 | cut -d: -f1)
callLine=$(awk -v s="$pkg" 'NR>s && /writeClientManifest "\$worldName"/{print NR; exit}' "$FUNCS")
zipLine=$(awk -v s="$pkg" 'NR>s && /^[[:space:]]*zip "\$zipPath" -r/{print NR; exit}' "$FUNCS")
if [ -n "$callLine" ] && [ -n "$zipLine" ]; then
	[ "$callLine" -lt "$zipLine" ] \
		&& pass "written BEFORE the zip (line $callLine < $zipLine)" \
		|| fail "written after the zip (line $callLine > $zipLine)" \
		        "the manifest would ship one update late, describing the previous state of the world"
else
	fail "could not locate the call and the zip inside packageClient" "call=$callLine zip=$zipLine"
fi

# Not `|| return 1`, and not `&&`-chained into the zip. A missing manifest must not cost the
# payload. Checked by reading the line itself rather than trusting the comment above it.
callText=$(awk -v n="$callLine" 'NR==n' "$FUNCS")
case "$callText" in
	*"||"*|*"&&"*|*"return"*) fail "the call is chained to control flow: $callText" \
	                               "a failed manifest would abort or skip the payload" ;;
	*) pass "the call is unguarded, so a failed manifest cannot cost the payload" ;;
esac

echo
echo "== it actually writes what it claims (executed, with sql stubbed) =="

TMP=$(mktemp -d)
ran=0

(
	worldsDirectoryRoot="$TMP/worlds"
	gameDNS="valheim.example.com"
	# A URL with its own '=' signs, deliberately: the parser on the other side splits on the
	# FIRST '=' only, and this is the value that proves it.
	phvalheimClientURL="https://phv.example.com/download?os=win&v=2"
	clientMinVersion="9.9.9"

	function clientStagingRoot() { echo "$worldsDirectoryRoot/$1/client"; }
	SQL() {
		case "$1" in
			*"SELECT port"*)      echo 25003 ;;
			*"IFNULL(vanilla"*)   echo 0 ;;
			*"IFNULL(crossplay"*) echo 1 ;;
		esac
	}

	eval "$(awk '/^function writeClientManifest\(\)/,/^}/' "$FUNCS")"

	mkdir -p "$worldsDirectoryRoot/Midgard/client/BepInEx/plugins/PhValheimCompanion"
	writeClientManifest "Midgard" > "$TMP/log" 2>&1
	echo "$?" > "$TMP/rc"
) && ran=1

MAN="$TMP/worlds/Midgard/client/BepInEx/plugins/PhValheimCompanion/phvalheim-world.cfg"

# Did the harness run at all? Without this, every assertion below would "pass" by asserting
# things about a file that was never created -- and an absent file satisfies most negative
# checks, including the one about the password.
[ "$ran" = "1" ] && pass "the harness executed the function" \
	|| fail "the harness did not run -- nothing below means anything"

[ -f "$MAN" ] && pass "a manifest was written" \
	|| fail "no manifest at $MAN" "$(cat "$TMP/log" 2>/dev/null)"

if [ -f "$MAN" ]; then
	grep -qx 'world=Midgard'           "$MAN" && pass "names the world" || fail "no world= line"
	grep -qx 'host=valheim.example.com' "$MAN" && pass "carries the game host" || fail "no host= line"
	grep -qx 'port=25003'              "$MAN" && pass "carries the port" || fail "no port= line"
	grep -qx 'crossplay=1'             "$MAN" && pass "carries the crossplay flag from the DB" || fail "crossplay flag wrong"
	grep -qx 'minClientVersion=9.9.9'  "$MAN" \
		&& pass "carries clientMinVersion from the config, not a hardcoded copy" \
		|| fail "minClientVersion is not the configured value" \
		        "a second hardcoded copy would drift from phvalheim-static.conf"

	# NO client download URL. It was dropped when the "Get the app" button was: the setting is
	# a single url whose default is a Windows .exe, so on any other platform the button handed
	# the player the wrong installer. A field nothing reads is an orphan, and an orphan here
	# would invite the button back.
	if grep -qi '^clientUrl=' "$MAN"; then
		fail "the manifest still carries a client download URL" \
		     "nothing reads it since the Get the app button was removed"
	else
		pass "no client download URL (the button it fed is gone)"
	fi

	# THE ONE THAT MATTERS MOST. Negative checks pass trivially against a missing file, which
	# is why this is inside the -f guard and why "a manifest was written" is asserted above.
	if grep -qiE 'password|passwd' "$MAN"; then
		fail "the manifest mentions a password" \
		     "argv is for the password; this file lands on every player's disk and stays there"
	else
		pass "no password anywhere in the manifest"
	fi

	# No temp file left beside it -- packageClient zips this directory moments later and would
	# ship a half-written one.
	ls "$(dirname "$MAN")" | grep -q 'tmp' \
		&& fail "a .tmp file was left in the payload directory" \
		|| pass "no temp file left to be packaged"
fi

echo
echo "== a nameless world is refused rather than shipped blank =="

# The Companion refuses a manifest with no world name, because a notice that cannot say which
# world it concerns cannot be acted on. That refusal is only reachable if the writer can
# actually produce one, so this asserts the C# side instead -- see the harness in
# phvalheim-companion/dev_tools/renderDialog, which mutation-tests both directions.
if [ -f "$DLL" ]; then
	# NUL-stripped: .NET string literals are UTF-16 in the metadata, so grep stops at the
	# first NUL byte without it and reports nothing on a dll that contains the string.
	strs=$(tr -d '\000' < "$DLL")

	case "$strs" in *"phvalheim-world.cfg"*)
		pass "the shipped Companion dll looks for phvalheim-world.cfg" ;;
		*) fail "the bundled dll has no reference to phvalheim-world.cfg" \
		        "the server would write a manifest nothing reads -- rebuild the Companion" ;; esac

	case "$strs" in *"Nothing handed Valheim a world to join."*)
		pass "the shipped dll contains the launch-help notice" ;;
		*) fail "the bundled dll does not contain the notice text" \
		        "the dll predates this feature; rebuild it from phvalheim-companion" ;; esac

	case "$strs" in *"ShowLaunchHelp"*)
		pass "the shipped dll has the off switch" ;;
		*) fail "no ShowLaunchHelp config entry in the dll" \
		        "a single-player on a PhValheim install could not silence the notice" ;; esac

	# NEGATIVE: it must not tell the player their app is out of date. That state has two
	# causes and the Companion cannot tell them apart -- for the player who launched from
	# Steam on a current app, "out of date" is simply false.
	case "$strs" in *"is out of date"*)
		fail "the dll claims the player's app is out of date" \
		     "it cannot know that: a Steam launch on a current app reaches the same code" ;;
		*) pass "the dll does not claim the player's app is out of date" ;; esac
else
	fail "no bundled Companion dll at $DLL"
fi

rm -rf "$TMP"

echo
echo "== the in-game dialog uses PhValheim's OWN palette =="

# The Companion's colours are copied from :root in phvalheimStyles.css, and a copy drifts. This
# re-reads the CSS and requires the shipped dll to carry the same hex, so re-theming the web UI
# cannot quietly leave the in-game dialog on last year's accent.
#
# The dll is NUL-stripped first: .NET string literals are UTF-16 in the metadata, so a plain
# grep returns a confident zero for a string that is demonstrably there. I handed Brian a probe
# without this and it reported 0 for a string the game was printing at the time.
CSS="$REPO/container/nginx/www/css/phvalheimStyles.css"
if [ ! -f "$CSS" ]; then
	fail "no stylesheet at $CSS -- the palette is unverified"
elif [ ! -f "$DLL" ]; then
	fail "no bundled dll -- the palette is unverified"
else
	dllText=$(tr -d '\000' < "$DLL")

	cssvar() {
		awk '/^:root/,/^}/' "$CSS" | grep -oE "^[[:space:]]*$1:[[:space:]]*#[0-9a-fA-F]{6}" \
			| grep -oE '#[0-9a-fA-F]{6}' | head -1
	}

	# name:cssvar:what it is used for
	for spec in \
		"accent:--accent-primary:world name and address" \
		"muted:--text-secondary:row labels" \
		"warn:--warning:warnings" \
		"button:--accent-secondary:the menu button label" \
		"accentHover:--accent-hover:the menu button's hover state" \
		"buttonFill:--bg-tertiary:the dialog's button faces" \
		"textBody:--text-primary:the dialog's body prose"
	do
		name=${spec%%:*}; rest=${spec#*:}; var=${rest%%:*}; what=${rest#*:}
		want=$(cssvar "$var")

		if [ -z "$want" ]; then
			fail "could not read $var out of the stylesheet's :root" \
			     "the palette check is blind for $what"
			continue
		fi

		if echo "$dllText" | grep -qiF -- "$want"; then
			pass "$what uses $var ($want)"
		else
			fail "$what does not use $var ($want)" \
			     "the dll's colour has drifted from the stylesheet -- re-theme both or neither"
		fi
	done

	# CONTROL: the old hand-picked Valheim-parchment colours must be GONE. Without this the
	# checks above would pass on a dll that carried both palettes and rendered the old one.
	for dead in "#E8D9A0" "#FFC083" "#9AA3B8"; do
		if echo "$dllText" | grep -qiF -- "$dead"; then
			fail "the dll still carries the pre-theme colour $dead" \
			     "two palettes in one assembly; the one that renders is whichever the code reads"
		else
			pass "control: the pre-theme colour $dead is gone"
		fi
	done
fi

echo
echo "== the dialog's code is REACHABLE, not merely present =="

# Every check above this line asks "is it there?". None can ask "does anything run it?", and
# that distinction cost a release: the first :rc shipped with the notice unreachable because
# ConnectDialog.Update still returned early on a missing payload. The dll held every string,
# the manifest was in the zip, eight markers were green, and the player saw nothing.
#
# Reachability needs the IL, which needs the Companion source tree and the .NET SDK, so it
# cannot run inside the image verify. It runs here, before the build, and a MISSING tree is
# reported rather than skipped -- a silent skip is how this check would quietly stop existing.
COMPANION=${PHVALHEIM_COMPANION:-$REPO/../phvalheim-companion}
REACH="$COMPANION/dev_tools/test-dialog-reachability.sh"

if [ ! -f "$REACH" ]; then
	fail "cannot find the Companion reachability test" \
	     "expected $REACH -- set PHVALHEIM_COMPANION, or the dialog ships unverified"
elif ! command -v dotnet >/dev/null 2>&1; then
	fail "no dotnet on this host, so the dialog's reachability is untested" \
	     "the bundled dll could contain every string and still never run"
else
	# Against the dll this repo actually SHIPS, not the Companion's build output -- those are
	# the same file only if someone remembered to copy it, which is exactly the step that
	# fails silently.
	TMPOUT=$(mktemp)
	if bash "$REACH" "$DLL" >"$TMPOUT" 2>&1; then
		pass "the bundled dll's dialog gates are all reachable ($(grep -c '^  PASS' "$TMPOUT") IL checks)"
	else
		fail "the bundled dll has an unreachable dialog gate" \
		     "$(grep '^  FAIL' "$TMPOUT" | head -3)"
	fi
	rm -f "$TMPOUT"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = "0" ] || exit 1
