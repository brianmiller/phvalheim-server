#!/bin/bash
# A stopped world must not be diagnosed as a broken one.
#
# Differential by construction: the SAME log is scanned twice, once with the world marked
# running and once stopped. If the two runs produce identical findings the fix is absent,
# so this cannot pass for the wrong reason.
#
# The bug it pins: on a server with eleven deliberately-stopped test worlds, every one
# produced present-tense critical findings quoting log lines seven months old, and the model
# duly reported "the Valheim worlds are severely degraded". Nothing was wrong.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/logs"

# A log that trips several checks at once: a crash loop, a failed plugin, a port clash.
LOG="$WORK/logs/valheimworld_Oldworld.log"
{
  for i in 1 2 3 4 5 6 7 8; do
    echo "[Message: BepInEx] Valheim version: l-0.221.10 (network version 36)"
    echo "02/14/2026 01:29:41: DungeonDB Start 26887"
    echo "02/14/2026 01:29:41: Game server connected"
    echo "02/14/2026 01:32:11: Net scene destroyed"
  done
  echo "[Error  : BepInEx] Could not load [EpicLoot 0.9.0]"
  echo "Address already in use"
} > "$LOG"
# Age it: the whole point is that this is history.
touch -d '210 days ago' "$LOG"

run_scan() {   # $1 = status value ("Running" / "Down")
cat > "$WORK/scan.php" <<PHPEOF
<?php
define('AI_LOG_DIR', '$WORK/logs');
require '$ROOT/container/nginx/www/includes/aidiagnose.php';

// The scan asks the database which mods a world SHOULD have. Passing null fataled inside
// aiExpectedMods() and the whole run produced zero findings -- which three of the assertions
// below happily called a pass, because "no criticals" reads the same whether the fix works
// or the code never ran. That is why the differential and the running-case controls exist.
class FakeStmt { public function execute(\$a = null) { return true; }
                 public function fetchAll(\$m = null) { return []; }
                 public function fetchColumn(\$i = 0) { return 0; }
                 public function fetch(\$m = null) { return false; } }
class FakePdo  { public function prepare(\$s) { return new FakeStmt(); }
                 public function query(\$s)   { return new FakeStmt(); } }
\$pdo = new FakePdo();
\$w = [
    'name'   => 'Oldworld',
    'status' => '$1',
    'public' => 1,
    'vanilla'=> 1,
    'last_backup_time' => '2026-02-14 01:00:00',
    'backup_interval_minutes' => 30,
];
foreach (aiDiagnoseWorld(\$pdo, \$w) as \$f) {
    echo \$f['severity'] . '|' . \$f['title'] . "\n";
}
PHPEOF
php "$WORK/scan.php" 2>/dev/null
}

printf '\n\033[1mSame log, world running vs stopped\033[0m\n'
UP="$(run_scan Running)"
DOWN="$(run_scan Down)"

printf '  running -> %s finding(s)\n' "$(printf '%s' "$UP"   | grep -c .)"
printf '  stopped -> %s finding(s)\n' "$(printf '%s' "$DOWN" | grep -c .)"

if [ -z "$UP" ]; then
	bad "the running case produced NO findings — the fixture no longer trips any check"
else
	ok "the running case still reports faults ($(printf '%s' "$UP" | grep -c .))"
fi

if [ "$UP" = "$DOWN" ]; then
	bad "stopped and running produce IDENTICAL findings — status is being ignored"
else
	ok "stopped and running differ"
fi

# 1. No criticals for a world that is simply off.
if printf '%s' "$DOWN" | grep -q '^critical|'; then
	bad "a stopped world still produces critical findings:"
	printf '%s\n' "$DOWN" | grep '^critical|' | sed 's/^/          /'
else
	ok "no critical findings for a stopped world"
fi

# 2. The restart-loop check must not fire at all — a stopped world is not restarting.
if printf '%s' "$DOWN" | grep -qi 'restarting repeatedly'; then
	bad "a stopped world is reported as being in a restart loop"
else
	ok "no restart-loop finding for a stopped world"
fi
if printf '%s' "$UP" | grep -qi 'restarting repeatedly'; then
	ok "the restart-loop check still fires for a RUNNING world (negative control)"
else
	bad "restart-loop check no longer fires even when running — it was disabled, not scoped"
fi

# 3. Backups are irrelevant for a world nobody is playing.
if printf '%s' "$DOWN" | grep -qi 'backups are overdue'; then
	bad "a stopped world is nagged about overdue backups"
else
	ok "no backup nag for a stopped world"
fi

# 4. What survives must be marked as history, with its age.
HIST="$(printf '%s' "$DOWN" | grep -c 'at its last run')"
if [ "$HIST" -gt 0 ]; then
	ok "surviving findings are marked historical with an age ($HIST)"
	printf '%s\n' "$DOWN" | grep 'at its last run' | head -2 | sed 's/^/          /'
else
	bad "stopped-world findings are not marked as historical"
fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mStopped-world diagnosis OK\033[0m (%s checks)\n' "$PASS"
	exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
