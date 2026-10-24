#!/bin/bash
# The environment the CONTAINER bucket needs, in one place: which container and image are under
# test, the fixture worlds and files the browser tests read, the per-test arguments the tests
# have always taken on the command line, the special runners (in-container PHP, in-image bash,
# a second container for the Hugin suites), and the preconditions a runner cannot create.
#
# WHY. Until 2.58 run-oracles.sh --container ran every file bare: no arguments, no fixtures, no
# NODE_PATH. 34 of 69 tests failed on every release for reasons that had nothing to do with the
# product, and "same failures as last release" stood in for "passes". A diff is not a verdict:
# a regression hidden inside those 34 was invisible, and one real UI bug (the sync-log pane
# vanishing for the whole of every sync) sat in that pile for two releases.
#
# Sourced by run-oracles.sh for --container. Also runs by hand:
#   dev_tools/oracle-container-env.sh check      what would be used, what is missing
#   dev_tools/oracle-container-env.sh seed       create the fixtures (idempotent)
#   dev_tools/oracle-container-env.sh teardown   stop what seed started, remove the scratch container
#
# Settings (environment):
#   ORACLE_CONTAINER   the live container (default phvalheim-dev)
#   ORACLE_IMAGE       the image under test; default = the container's image. The Hugin suites
#                      boot their OWN container from it (they used to default to :rc, so they
#                      were validating the released image, not the one being tested)
#   ORACLE_ADMIN / ORACLE_PUBLIC   base URLs (default 127.0.0.1:8081 / :8080)
#   ORACLE_SKIP_PROBE=1            do not start the scratch container for the CSS probe test
#                                  (it is then reported UNRUNNABLE, never silently passed)

ORACLE_CONTAINER="${ORACLE_CONTAINER:-phvalheim-dev}"
ORACLE_ADMIN="${ORACLE_ADMIN:-http://127.0.0.1:8081}"
ORACLE_PUBLIC="${ORACLE_PUBLIC:-http://127.0.0.1:8080}"
ORACLE_PROBE_NAME="${ORACLE_PROBE_NAME:-phv-cssprobe}"
ORACLE_PROBE_PORT="${ORACLE_PROBE_PORT:-18080}"
ORACLE_PROBE_STEAMID=76561198000000001
_ORACLE_DEV=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
_ORACLE_REPO=$(cd -- "$_ORACLE_DEV/.." && pwd)

osql()  { docker exec "$ORACLE_CONTAINER" mysql -N -uroot phvalheim -e "$1" 2>/dev/null; }
oexec() { docker exec "$ORACLE_CONTAINER" sh -c "$1"; }

# ---------------------------------------------------------------- what is there
oracleDiscover() {
	ORACLE_IMAGE="${ORACLE_IMAGE:-$(docker inspect --format '{{.Config.Image}}' "$ORACLE_CONTAINER" 2>/dev/null)}"
	ORACLE_STEAM_BYPASS=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$ORACLE_CONTAINER" 2>/dev/null \
		| sed -n 's/^phvalheimDevSteamID=//p' | head -1)
	# Two STOPPED modded worlds: the browser config tests write fixture files into their
	# trees. Modded, because the config editor and the migration review only exist for them;
	# stopped, because the engine rewrites a running world's tree.
	local picks
	picks=$(osql "SELECT name FROM worlds WHERE IFNULL(vanilla,0)=0 AND mode='stopped' AND name NOT LIKE 'ztest%' AND name NOT LIKE 'dupplugin%' ORDER BY id LIMIT 2" | tr '\n' ' ')
	ORACLE_WORLD=${ORACLE_WORLD:-$(echo "$picks" | awk '{print $1}')}
	ORACLE_SOLO_WORLD=${ORACLE_SOLO_WORLD:-$(echo "$picks" | awk '{print $2}')}
	# A vanilla world for the restart-pending test, which needs it RUNNING; seed starts it.
	# ...and it must have a game tree, or `start` can only fail: a world row whose install
	# never ran (northlands on the dev volume) is vanilla and useless here.
	if [ -z "${ORACLE_VANILLA_WORLD:-}" ]; then
		local v
		for v in $(osql "SELECT name FROM worlds WHERE IFNULL(vanilla,0)=1 AND name NOT LIKE 'ztest%' ORDER BY (mode='running') DESC, id"); do
			oexec "test -x /opt/stateful/games/valheim/worlds/$v/game/valheim_server.x86_64" 2>/dev/null && { ORACLE_VANILLA_WORLD=$v; break; }
		done
	fi
	ORACLE_PLAYWRIGHT=0
	node -e "require('playwright')" >/dev/null 2>&1 && ORACLE_PLAYWRIGHT=1
	export ORACLE_CONTAINER ORACLE_IMAGE ORACLE_ADMIN ORACLE_PUBLIC ORACLE_STEAM_BYPASS \
	       ORACLE_WORLD ORACLE_SOLO_WORLD ORACLE_VANILLA_WORLD ORACLE_PLAYWRIGHT
}

oracleCheck() {
	oracleDiscover
	echo "container:      $ORACLE_CONTAINER ($(docker inspect --format '{{.State.Status}}' "$ORACLE_CONTAINER" 2>/dev/null || echo MISSING))"
	echo "image:          ${ORACLE_IMAGE:-?}"
	echo "admin/public:   $ORACLE_ADMIN  $ORACLE_PUBLIC"
	echo "fixture worlds: modded=${ORACLE_WORLD:-NONE} solo=${ORACLE_SOLO_WORLD:-NONE} vanilla=${ORACLE_VANILLA_WORLD:-NONE}"
	echo "playwright:     $([ "$ORACLE_PLAYWRIGHT" = 1 ] && echo "ok (NODE_PATH=${NODE_PATH:-unset})" || echo "MISSING -- browser tests cannot run")"
	echo "steam bypass:   ${ORACLE_STEAM_BYPASS:-OFF -- public-page tests UNRUNNABLE; start the container with dev_tools/devUp.sh <SteamID64>}"
	echo "css probe:      $([ "${ORACLE_SKIP_PROBE:-0}" = 1 ] && echo "skipped by ORACLE_SKIP_PROBE" || echo "scratch container $ORACLE_PROBE_NAME on :$ORACLE_PROBE_PORT (seed starts it)")"
}

# ---------------------------------------------------------------- fixtures
# Everything here is idempotent and written where the tests say they read it. The file
# contents are copied from the tests' own headers: note `# Default value:` with ONE hash where
# a test parses a changed setting, and `## ` where it must parse as zero changed.
_oracleWorldDir() { echo "/opt/stateful/games/valheim/worlds/$1"; }

oracleSeed() {
	oracleDiscover
	[ -n "$ORACLE_WORLD" ] && [ -n "$ORACLE_SOLO_WORLD" ] || { echo "SEED FAILED: need two stopped modded worlds (have '$ORACLE_WORLD' '$ORACLE_SOLO_WORLD')"; return 1; }
	local wid mid d c p
	wid=$(osql "SELECT id FROM worlds WHERE name='$ORACLE_WORLD'")
	# The parked file SkyheimExtended.cfg must be "named after a mod the world has" (the
	# migration-orphan test's at-risk case), so attach that mod by NAME, not by id 1 as the
	# test's header does -- id 1 happens to be posixone/SkyheimExtended on the dev volume only.
	mid=$(osql "SELECT id FROM mods WHERE name='SkyheimExtended' ORDER BY (source='thunderstore') DESC, id LIMIT 1")
	[ -n "$mid" ] || mid=$(osql "SELECT id FROM mods WHERE source='thunderstore' ORDER BY id LIMIT 1")
	# One mod attached to the modded fixture world (the migration review compares parked files
	# against installed mods), plus the three config-tree files and two parked files the
	# accordion, unmatched-back and migration-orphan tests describe in their headers.
	[ -n "$wid" ] && [ -n "$mid" ] && osql "INSERT IGNORE INTO world_mods (world_id, mod_id) VALUES ($wid, $mid)"
	d=$(_oracleWorldDir "$ORACLE_WORLD")
	# Only the CONFIG-TREE files are seeded here. The PARKED files (custom_configs/.imported-
	# pre-2.55) are per-test, in oraclePre/oraclePost: the migration review is server-wide and
	# its Delete button removes every orphaned parked file on every world, so the gate test
	# deleted the orphan test's fixture and then failed its own "last original" check because
	# the orphan test's at-risk file was still there (2026-10-22).
	oexec "set -e
		C=$d/game/BepInEx/config
		mkdir -p \"\$C\"
		printf '[General]\n# Default value: 30\nMaxSailSpeed = 45\n' > \"\$C/SkyheimExtended.cfg\"
		printf '[General]\n# Default value: 5\nMystery = 9\n'        > \"\$C/nobody.knows.this.cfg\"
		printf '[General]\n# Default value: 1\nOther = 4\n'          > \"\$C/alsounmatched.cfg\"
		chown -R phvalheim:phvalheim \"$d/game/BepInEx\""
	# The solo world: exactly ONE config-tree file (the accordion's single-card rule) and one
	# parked file that parses as zero changed settings (the migration gate).
	d=$(_oracleWorldDir "$ORACLE_SOLO_WORLD")
	oexec "set -e
		C=$d/game/BepInEx/config
		mkdir -p \"\$C\"
		rm -f \"\$C\"/*.cfg
		printf '[General]\n# Default value: 2\nSolo = 3\n' > \"\$C/solo.fixture.cfg\"
		chown -R phvalheim:phvalheim \"$d/game/BepInEx\""
	# One-shot admin notices. On a container that has just moved to a new image version the
	# dashboard opens the What's New modal on every load until it is dismissed, and it sits on
	# top of everything: the first bucket run on the bumped 2.58 image lost eight browser tests
	# to it (2026-10-23). They are real features with their own tests; here they are noise.
	local imgver; imgver=$(docker exec "$ORACLE_CONTAINER" sh -c 'echo "$phvalheimVersion"' 2>/dev/null)
	# clientUpdateNoticeState: 1 = the engine updated a world and the "client update required"
	# overlay (z-index 1073) covers the whole admin page until dismissed (2 = seen). The 2.58
	# update-badge test runs a REAL world update and arms it; left armed it blocked every
	# click in nine later browser tests (2026-10-23). Seed it dismissed, and re-dismiss after.
	[ -n "$imgver" ] && osql "UPDATE settings SET whatsNewShownVersion='$imgver', huginNoticeShown=1, connectNoticeShown=1, migrationNoticeShown=1, clientUpdateNoticeState=IF(clientUpdateNoticeState=1,2,clientUpdateNoticeState)"
	# A log for the viewer-escaping test: assembly GUIDs in angle brackets, raw HTML, a blank
	# line. No log on a quiet dev volume contains a GUID, and phvLogFileAllowed() admits any
	# <name>.log inside the logs dir, so the fixture is a log of its own.
	oexec "printf '%s\n' \
		'10/22/2026 00:00:00: Fallback handler could not load library' \
		'[Info   :   BepInEx] Loading [Jotunn 2.20.0]' \
		'  at Game.Start () [0x00000] in <207a02655d0b483ca679ce75910d2c5e>:0' \
		'<b>not bold</b> & <script>alert(1)</script> &lt;kept as text&gt;' \
		'' \
		'Session \"fixture\" registered with join code 123456' > /opt/stateful/logs/oracle-fixture.log \
		&& chown phvalheim:phvalheim /opt/stateful/logs/oracle-fixture.log"
	# Public-page tests need the bypass identity to be a citizen somewhere, or no card renders.
	# ...on EVERY real world: the card-layout test re-measures at a wider viewport and needs
	# enough cards to reshuffle; two were not enough.
	if [ -n "$ORACLE_STEAM_BYPASS" ]; then
		# ...but NOT an enforced-but-empty world: test-empty-accesslist-modal.js needs one to exist,
		# and seeding a citizen into every world removed the only one (acltest) from under it.
		for w in $(osql "SELECT name FROM worlds WHERE name NOT LIKE 'ztest%' AND name NOT LIKE 'dupplugin%' AND name NOT LIKE 'migdeltest%' AND NOT (IFNULL(public,0)=0 AND TRIM(IFNULL(citizens,''))='')"); do
			[ -n "$w" ] && osql "UPDATE worlds SET citizens=TRIM(CONCAT(IFNULL(citizens,''),' V_$ORACLE_STEAM_BYPASS')) WHERE name='$w' AND IFNULL(citizens,'') NOT LIKE '%$ORACLE_STEAM_BYPASS%'"
		done
	fi
	# The restart-pending test needs a RUNNING vanilla world. It is started in that test's
	# pre-hook and stopped in its post-hook, not here: a real Valheim server is the heaviest
	# thing on the box, and keeping one up for the whole bucket made two browser tests miss
	# their redraw windows that pass every time alone (2026-10-23).
	ORACLE_STARTED_WORLD=""; export ORACLE_STARTED_WORLD
	[ "${ORACLE_SKIP_PROBE:-0}" = 1 ] || oracleProbeUp
	return 0
}

# The CSS probe test measures the PUBLIC page of a server where the viewer is logged in and a
# citizen of two password worlds. It forges a PHP session, so it gets a scratch container of
# the image under test, never the live one.
oracleProbeUp() {
	if docker inspect "$ORACLE_PROBE_NAME" >/dev/null 2>&1; then
		[ "$(docker inspect --format '{{.State.Running}}' "$ORACLE_PROBE_NAME")" = true ] && { ORACLE_PROBE=1; export ORACLE_PROBE; return 0; }
		docker rm -f "$ORACLE_PROBE_NAME" >/dev/null 2>&1
	fi
	[ -n "$ORACLE_IMAGE" ] || { echo "probe: no image known"; return 1; }
	echo "probe: starting $ORACLE_PROBE_NAME from $ORACLE_IMAGE on :$ORACLE_PROBE_PORT"
	docker volume rm "${ORACLE_PROBE_NAME}-data" >/dev/null 2>&1
	docker run -d --name "$ORACLE_PROBE_NAME" -p "$ORACLE_PROBE_PORT:8080" -v "${ORACLE_PROBE_NAME}-data:/opt/stateful" "$ORACLE_IMAGE" >/dev/null || return 1
	local psql i
	psql() { docker exec "$ORACLE_PROBE_NAME" mysql -N -uroot phvalheim -e "$1" 2>/dev/null; }
	# The schema arrives with the engine's migrations; `worlds.vanilla` is a late one.
	for i in $(seq 1 36); do sleep 5; psql "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1 && psql "SELECT setupComplete FROM settings" >/dev/null 2>&1 && break; done
	psql "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1 || { echo "probe: schema never arrived"; return 1; }
	psql "UPDATE settings SET setupComplete=2, whatsNewShownVersion='9.99', huginNoticeShown=1, connectNoticeShown=1, migrationNoticeShown=1, clientUpdateNoticeState=IF(clientUpdateNoticeState=1,2,clientUpdateNoticeState)"
	psql "INSERT IGNORE INTO worlds (mode,status,name,external_endpoint,seed) VALUES ('stopped','Down','probevanilla','probe.example.com:25001','probeseed'),('stopped','Down','probemodded','probe.example.com:25002','probeseed')"
	psql "UPDATE worlds SET vanilla=1, password='probepass1', public=0, citizens='V_$ORACLE_PROBE_STEAMID' WHERE name='probevanilla'"
	psql "UPDATE worlds SET vanilla=0, password='probepass2', public=0, citizens='V_$ORACLE_PROBE_STEAMID' WHERE name='probemodded'"
	# A logged-in session for the probe identity: PHP's own serialiser, the keys session_auth.php reads.
	docker exec "$ORACLE_PROBE_NAME" sh -c "printf 'steamID|s:17:\"%s\";login_time|i:%s;' '$ORACLE_PROBE_STEAMID' \"\$(date +%s)\" > /var/lib/php/sessions/sess_deadbeefdeadbeefdeadbeefdeadbeef && chown phvalheim:phvalheim /var/lib/php/sessions/sess_deadbeefdeadbeefdeadbeefdeadbeef && chmod 600 /var/lib/php/sessions/sess_deadbeefdeadbeefdeadbeefdeadbeef"
	ORACLE_PROBE=1; export ORACLE_PROBE
	echo "probe: ready after $((i*5))s"
}

oracleTeardown() {
	[ -n "${ORACLE_STARTED_WORLD:-}" ] && { echo "teardown: stopping '$ORACLE_STARTED_WORLD'"; _oracleStopWorld; }
	oexec "rm -f /opt/stateful/logs/oracle-fixture.log" 2>/dev/null
	if docker inspect "$ORACLE_PROBE_NAME" >/dev/null 2>&1; then
		docker rm -f "$ORACLE_PROBE_NAME" >/dev/null 2>&1
		docker volume rm "${ORACLE_PROBE_NAME}-data" >/dev/null 2>&1
		echo "teardown: removed $ORACLE_PROBE_NAME"
	fi
}

# ---------------------------------------------------------------- per test
# Why a test cannot run here, or nothing. A missing precondition is reported, never passed over.
oracleUnrunnable() {
	case "$1" in
		*.js) [ "$ORACLE_PLAYWRIGHT" = 1 ] || { echo "playwright not resolvable (set NODE_PATH to a node_modules that has it)"; return; } ;;
	esac
	case "$1" in
		test-public-card-render.js|test-crossplay-join-modal.js)
			[ -n "$ORACLE_STEAM_BYPASS" ] || echo "the public page needs the Steam-auth bypass: start the container with dev_tools/devUp.sh <SteamID64>" ;;
		test-password-row-styling.js)
			[ "${ORACLE_PROBE:-0}" = 1 ] || echo "needs the scratch container $ORACLE_PROBE_NAME on :$ORACLE_PROBE_PORT (seed starts it unless ORACLE_SKIP_PROBE=1)" ;;
		test-restart-pending.sh)
			[ -n "$ORACLE_VANILLA_WORLD" ] || echo "needs a vanilla world with a game tree to start" ;;
		test-config-*.js|test-configs-*.js)
			[ -n "$ORACLE_WORLD" ] && [ -n "$ORACLE_SOLO_WORLD" ] || echo "needs two stopped modded worlds for fixtures" ;;
	esac
}

# Just before a test: state it consumes. The two notice tests dismiss the notice as part of what
# they prove, so each passes once per arming.
_oracleAttachFixtureMod() {   # $1 = world
	local wid mid
	wid=$(osql "SELECT id FROM worlds WHERE name='$1'")
	mid=$(osql "SELECT id FROM mods WHERE name='SkyheimExtended' ORDER BY (source='thunderstore') DESC, id LIMIT 1")
	[ -n "$wid" ] && [ -n "$mid" ] && osql "INSERT IGNORE INTO world_mods (world_id, mod_id) VALUES ($wid, $mid)"
}

# Parked (pre-2.55 original) files for one world, or their removal. Contents from the tests'
# headers: ONE hash where the file must parse as changed settings, TWO where it must not.
_oracleParked() {   # $1 = world, $2 = orphan|gate|none
	local d; d=$(_oracleWorldDir "$1")
	case "$2" in
		orphan) oexec "set -e; P=$d/custom_configs/.imported-pre-2.55; mkdir -p \"\$P\"
			printf '[General]\n# Default value: 30\nMaxSailSpeed = 45\n' > \"\$P/SkyheimExtended.cfg\"
			printf '[General]\n# Default value: 5\nSleepDelay = 9\n'     > \"\$P/zolantris.GoneForever.cfg\"
			chown -R phvalheim:phvalheim \"$d/custom_configs\"" ;;
		gate)   oexec "set -e; P=$d/custom_configs/.imported-pre-2.55; mkdir -p \"\$P\"
			printf '[A]\n## Default value: 5\nx = 9\n' > \"\$P/gatefixture.cfg\"
			chown -R phvalheim:phvalheim \"$d/custom_configs\"" ;;
		none)   oexec "rm -f $d/custom_configs/.imported-pre-2.55/SkyheimExtended.cfg $d/custom_configs/.imported-pre-2.55/zolantris.GoneForever.cfg $d/custom_configs/.imported-pre-2.55/gatefixture.cfg" 2>/dev/null ;;
	esac
}

# No catalogue sync in flight. The sync-pane test (alphabetically just before the picker and
# the duplicate-plugin tests) leaves a forced Thunderstore sync rebuilding mods, versions and
# mod_deps for ~50 s; a picker loaded mid-rebuild sees a catalogue in flux.
_oracleWaitNoSync() {
	local i
	for i in $(seq 1 60); do
		[ "$(osql "SELECT COUNT(*) FROM mod_sync_runs WHERE status='running'")" = 0 ] && return 0
		sleep 3
	done
	echo "  (a catalogue sync was still running after 180s; carrying on)"
}

_oracleStartWorld() {
	[ -n "$1" ] || return 0
	[ "$(osql "SELECT mode FROM worlds WHERE name='$1'")" = running ] && return 0
	echo "  (starting vanilla world '$1' for this test)"
	osql "UPDATE worlds SET mode='start' WHERE name='$1'"
	ORACLE_STARTED_WORLD=$1; export ORACLE_STARTED_WORLD
	local i; for i in $(seq 1 36); do sleep 5; [ "$(osql "SELECT mode FROM worlds WHERE name='$1'")" = running ] && break; done
}
_oracleStopWorld() {
	[ -n "${ORACLE_STARTED_WORLD:-}" ] || return 0
	osql "UPDATE worlds SET mode='stop' WHERE name='$ORACLE_STARTED_WORLD'"
	local i; for i in $(seq 1 24); do sleep 5; [ "$(osql "SELECT mode FROM worlds WHERE name='$ORACLE_STARTED_WORLD'")" = stopped ] && break; done
	ORACLE_STARTED_WORLD=""; export ORACLE_STARTED_WORLD
}

oraclePre() {
	case "$1" in
		test-restart-pending.sh)         _oracleStartWorld "$ORACLE_VANILLA_WORLD" ;;
		test-multisource-picker.js|test-duplicate-plugin.sh|test-mod-all-tab.js|test-modsync-progress.sh) _oracleWaitNoSync ;;
		test-empty-accesslist-modal.js)
			# Needs ONE enforced-but-empty world. test-accesslists.sh (which runs first) leaves its
			# scratch world 'acltest' with citizens, and the create path refuses to make such a
			# world, so it is set up here by SQL on that scratch world when no other qualifies.
			if [ "$(osql "SELECT COUNT(*) FROM worlds WHERE IFNULL(public,0)=0 AND TRIM(IFNULL(citizens,''))=''")" = 0 ]; then
				osql "UPDATE worlds SET public=0, citizens='' WHERE name='acltest'"
			fi ;;
		test-modsync-log-pane.js)
			# The detail toggle is asserted on the Hexium pane's CURRENT run. After an engine restart
			# by an earlier test that run is a 12-line boot sync with no per-mod lines; force one.
			local before; before=$(osql "SELECT IFNULL(MAX(id),0) FROM mod_sync_runs")
			docker exec -d "$ORACLE_CONTAINER" /opt/stateless/engine/tools/modSync.py --source hexium --trigger manual --force
			for i in $(seq 1 36); do sleep 5; [ "$(osql "SELECT COUNT(*) FROM mod_sync_runs WHERE id > $before AND source='hexium' AND phase='done'")" -ge 1 ] && break; done ;;
		test-config-migration-gate.js)   _oracleParked "$ORACLE_SOLO_WORLD" gate ;;
		test-config-migration-orphan.js)
			# The at-risk case needs the SkyheimExtended mod ATTACHED to the world. The seed attaches
			# it, but the mods-hub tests save this world's mod list in between and drop it, so the
			# review then called the file orphaned (ticked) instead of at risk (unticked).
			_oracleAttachFixtureMod "$ORACLE_WORLD"
			# The test's own Import case writes override rows for the fixture file; left behind,
			# the next run's review calls the file "accounted" instead of at-risk. Clear them.
			osql "DELETE FROM mod_config_overrides WHERE world_id=(SELECT id FROM worlds WHERE name='$ORACLE_WORLD') AND cfg_file IN ('SkyheimExtended.cfg','zolantris.GoneForever.cfg')"
			_oracleParked "$ORACLE_WORLD" orphan ;;
		test-access-id-notice.js)        osql "UPDATE settings SET accessIdNoticeShown=0" ;;
		# ...and it asserts the ID notice FOLLOWS the switch notice, so both are armed for it.
		test-access-switch-notice-ui.js) osql "UPDATE settings SET accessSwitchNoticeShown=0, accessIdNoticeShown=0" ;;
	esac
}

# Right after a test, whatever its outcome. A notice left ARMED by a failing notice test covered
# the Access tab for every later test (the toggle test's Save Access click landed on the
# overlay and read as a broken save, 2026-10-22). Disarm unconditionally.
oraclePost() {
	case "$1" in
		test-restart-pending.sh)         _oracleStopWorld ;;
		test-config-migration-gate.js)   _oracleParked "$ORACLE_SOLO_WORLD" none ;;
		test-config-migration-orphan.js) _oracleParked "$ORACLE_WORLD" none ;;
		test-access-id-notice.js|test-access-switch-notice-ui.js)
			osql "UPDATE settings SET accessIdNoticeShown=1, accessSwitchNoticeShown=1" ;;
		test-update-badge-after-update.sh|test-world-player-telemetry.sh|test-update-modal.js)
			# The real world update these run arms the client-update overlay; dismiss it or every
			# later browser test's clicks land on the overlay. The telemetry test also really
			# starts the world: make sure it is back down whatever happened.
			osql "UPDATE settings SET clientUpdateNoticeState=2 WHERE clientUpdateNoticeState=1"
			osql "UPDATE worlds SET mode='stop' WHERE name='$ORACLE_WORLD' AND mode='running'"
			# ...and WAIT for it: the next tests' harness checks want mode=stopped, and a bare
			# mode='stop' takes the engine 10-30s to carry out (two tests reported "mode=stop"
			# and skipped themselves, 2026-10-24).
			local i; for i in $(seq 1 30); do [ "$(osql "SELECT mode FROM worlds WHERE name='$ORACLE_WORLD'")" = stopped ] && break; sleep 5; done
			osql "UPDATE worlds SET mode='stopped' WHERE name='$ORACLE_WORLD' AND mode='broken'" ;;
		test-admin-row-consolidation.js)
			# The test stops the engine and sets its world running on paper; it restores both in
			# its own finally, but a crash between the two would leave every later test without
			# an engine. Belt: start it (a no-op when running) and clear the fixture's flags.
			docker exec "$ORACLE_CONTAINER" supervisorctl start phvalheim >/dev/null 2>&1
			osql "UPDATE worlds SET update_available_game=0, update_available_mods=0 WHERE name='$ORACLE_WORLD'" ;;
	esac
}

# Run one container-bucket test the way its header says. $1 = file name, $2 = path.
oracleRun() {
	local name="$1" t="$2"
	case "$name" in
		# ---- browser tests with arguments (what each argv means is in the file's header)
		test-config-accordion.js)        node "$t" "$ORACLE_ADMIN" "$ORACLE_WORLD" "$ORACLE_SOLO_WORLD" ;;
		test-config-migration-gate.js)   node "$t" "$ORACLE_ADMIN" "$ORACLE_SOLO_WORLD" ;;
		test-admin-row-consolidation.js) node "$t" "$ORACLE_ADMIN" "$ORACLE_CONTAINER" "$ORACLE_WORLD" ;;
		test-config-migration-orphan.js) node "$t" "$ORACLE_ADMIN" "$ORACLE_WORLD" zolantris.GoneForever.cfg SkyheimExtended.cfg ;;
		test-configs-unmatched-back.js)  node "$t" "$ORACLE_ADMIN" "$ORACLE_WORLD" SkyheimExtended.cfg ;;
		test-log-viewer-escaping.js)     node "$t" "$ORACLE_ADMIN" oracle-fixture.log ;;
		test-public-card-render.js)      node "$t" "$ORACLE_PUBLIC" ;;
		test-password-row-styling.js)    node "$t" "http://127.0.0.1:$ORACLE_PROBE_PORT" ;;
		# reads ../container/... relative to dev_tools, and the public page
		# (absolute path: run-oracles.sh passes one, but a relative "dev_tools/..." given by hand
		# would double up after the cd)
		test-crossplay-join-modal.js)
			local abs; abs="$(cd "$(dirname "$t")" && pwd)/$(basename "$t")"
			(cd "$_ORACLE_DEV" && PHV_URL="$ORACLE_PUBLIC/" node "$abs") ;;
		# ---- PHP that must run INSIDE the container (absolute in-image includes, live MariaDB)
		# docker cp keeps the host file's mode (0660 here) and uid, so the phvalheim user could not
		# read its own test: "Could not open input file". chmod after the copy.
		test-save-summary.php)
			docker cp "$t" "$ORACLE_CONTAINER:/tmp/oracle-$name" && docker exec "$ORACLE_CONTAINER" chmod 644 "/tmp/oracle-$name" && docker exec -u phvalheim "$ORACLE_CONTAINER" php "/tmp/oracle-$name" "$ORACLE_WORLD" ;;
		test-config-manual-match-live.php|test-event-log-live.php|test-config-migration-delete.php)
			docker cp "$t" "$ORACLE_CONTAINER:/tmp/oracle-$name" && docker exec "$ORACLE_CONTAINER" chmod 644 "/tmp/oracle-$name" && docker exec "$ORACLE_CONTAINER" php "/tmp/oracle-$name" ;;
		# ---- bash that needs the image's users and python (modConfigs.py chowns to phvalheim)
		test-mod-config-editor.sh)
			docker run --rm -v "$_ORACLE_REPO:/repo" -w /repo "$ORACLE_IMAGE" bash dev_tools/test-mod-config-editor.sh ;;
		# ---- suites that boot their own container: from the image under test, not :rc
		test-ai-e2e.sh)                  bash "$t" "$ORACLE_IMAGE" ;;
		test-ai-actions.sh|test-ai-actions-mutations.sh|test-ai-telemetry.sh|test-proposal-ui.sh)
			PHV_IMAGE="$ORACLE_IMAGE" bash "$t" ;;
		# ---- shell tests that take the container (their defaults name other containers or worlds)
		test-accesslists.sh)             bash "$t" "$ORACLE_CONTAINER" ;;
		test-modsync-progress.sh)        bash "$t" "$ORACLE_CONTAINER" ;;
		test-hugin-tool-results.sh)      bash "$t" "$ORACLE_CONTAINER" "$ORACLE_WORLD" ;;
		test-events-ack.sh)              bash "$t" "$ORACLE_CONTAINER" ;;
		# really starts the world, updates it through the modal and waits for it to come back
		test-update-modal.js)            node "$t" "$ORACLE_ADMIN" "$ORACLE_CONTAINER" "$ORACLE_WORLD" ;;
		test-delete-modal.js)            node "$t" "$ORACLE_ADMIN" "$ORACLE_CONTAINER" "$ORACLE_SOLO_WORLD" ;;
		test-updates-tab-vanilla.js)     node "$t" "$ORACLE_ADMIN" "$ORACLE_VANILLA_WORLD" "$ORACLE_WORLD" ;;
		# the fixture world WITH mods: a modded world with none selected cannot be updated (by design)
		test-update-badge-after-update.sh) bash "$t" "$ORACLE_CONTAINER" "$ORACLE_WORLD" ;;
		# really starts the world; may update it first to get the image's Companion onto it
		test-world-player-telemetry.sh)  bash "$t" "$ORACLE_CONTAINER" "$ORACLE_WORLD" ;;
		test-restart-pending.sh)         bash "$t" "$ORACLE_CONTAINER" "$ORACLE_VANILLA_WORLD" ;;
		*.sh)  bash "$t" ;;
		*.php) php "$t" ;;
		*.py)  python3 "$t" ;;
		*.js)  node "$t" "$ORACLE_ADMIN" ;;
		*)     echo "  (no runner for $name)"; return 2 ;;
	esac
}

# By hand only. When sourced, $1 belongs to the caller (run-oracles.sh --container) and must
# not be read here: the first version did, matched nothing, and exited the RUNNER with a usage
# line before a single test ran.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
	case "${1:-}" in
		check)    oracleCheck ;;
		seed)     oracleSeed ;;
		teardown) oracleDiscover; ORACLE_STARTED_WORLD="${2:-}"; oracleTeardown ;;
		*)        echo "usage: $0 check|seed|teardown [started-world]"; exit 1 ;;
	esac
fi
