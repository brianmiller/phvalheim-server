#!/bin/bash
# Oracle test: a FRESH install builds the 2.43 mod catalogue and does NOT create tsmods.
#
# WHY IT EXISTS: 2.43 removed the `tsmods` CREATE TABLE and the whole tsSeeder() path from
# newdbMySQL.sh. That file only ever runs against a brand-new database, so every upgrade
# test in this suite exercises the other branch — a mistake there is invisible until someone
# does a genuinely fresh install, by which point they have no mods and no error explaining
# why.
#
# It asserts on a THROWAWAY database, so the container's real one is untouched.
#
# The two things that must both hold:
#   1. newdbMySQL.sh creates worlds/settings/systemstats and NOT tsmods.
#   2. dbUpdate_2.43.sh then creates the catalogue tables and skips its migration cleanly
#      (no tsmods to read), leaving a schema the engine can actually use.
#
# Usage:  dev_tools/test-fresh-install-schema.sh [container]

C="${1:-phvalheim-dev}"
DB=phvalheim_freshtest

pass=0; fail=0
ck() {
    if [ "$2" = "$3" ]; then
        pass=$((pass+1)); printf '  PASS  %s\n' "$1"
    else
        fail=$((fail+1)); printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"
    fi
}

q() { docker exec "$C" mysql -uroot --skip-column-names -e "$1" "$DB" 2>/dev/null; }

echo "=== fresh-install schema ($C, throwaway db $DB) ==="

docker exec "$C" mysql -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB;" || exit 1

# Run newdbMySQL.sh's table DDL against the throwaway database.
#
# THREE lines must go, and the third is the one that matters: the script's own
# `source .../phvalheim-static.conf`. That file defines SQL() against the REAL `phvalheim`
# database, so leaving it in means the replayed CREATE TABLEs are aimed at production data.
# It happens to be harmless (the tables already exist, so they just error) but only by luck.
# DROP/CREATE DATABASE and CREATE USER go for the same reason.
docker exec "$C" bash -c "
  sed -e '/DROP DATABASE/d' -e '/CREATE DATABASE/d' -e '/CREATE USER/,+3d' \
      -e '/phvalheim-static.conf/d' \
      /opt/stateless/engine/tools/newdbMySQL.sh > /tmp/fresh_ddl.sh
  {
    echo 'SQL() { /usr/bin/mysql -uroot --database=$DB -e \"\$1\"; }'
    tail -n +2 /tmp/fresh_ddl.sh
  } > /tmp/fresh_run.sh
  bash /tmp/fresh_run.sh
" >/tmp/fresh_ddl.out 2>&1

# Fail loudly rather than silently measuring the wrong database. If the replay ever aims at
# `phvalheim` again, the throwaway db stays empty and every assertion below would report a
# missing table -- which reads like a broken release rather than a broken test.
if [ "$(q "SHOW TABLES LIKE 'worlds';" | wc -l)" != "1" ]; then
    echo "  ABORT: the newdbMySQL replay did not create tables in $DB."
    echo "         It may have been aimed at the real database. Output:"
    sed 's/^/           /' /tmp/fresh_ddl.out | head -10
    docker exec "$C" mysql -uroot -e "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1
    exit 2
fi

# newdbMySQL.sh only lays down worlds + systemstats. `settings` and everything else arrive
# from the dbUpdates chain, which is why the whole chain has to run below -- running
# dbUpdate_2.43.sh alone fails on a table that does not exist yet, and that is a property of
# the harness, not of the release.
for t in worlds systemstats; do
    ck "newdbMySQL creates '$t'" "$(q "SHOW TABLES LIKE '$t';" | wc -l)" "1"
done
# The point of the change: no legacy catalogue table on a new install.
ck "newdbMySQL does NOT create tsmods" "$(q "SHOW TABLES LIKE 'tsmods';" | wc -l)" "0"

# Now EVERY migration in ls -v order, exactly as dbUpdater.sh does it on a real first boot.
# A fresh install runs the whole chain, so testing 2.43 in isolation would miss any ordering
# assumption it makes about tables earlier scripts create.
docker exec "$C" bash -c "
  cat > /tmp/fresh_prelude.sh <<'PRELUDE'
phvalheimVersion=2.43
tsModDownloadUrl=\"https://thunderstore.io/package/download\"
worldsDirectoryRoot=/opt/stateful/games/valheim/worlds
PRELUDE
  echo 'SQL() { /usr/bin/mysql --skip-column-names -uroot --database=$DB -e \"\$1\"; }' >> /tmp/fresh_prelude.sh
  echo 'sql() { SQL \"\$1\"; }' >> /tmp/fresh_prelude.sh

  for s in \$(ls -v /opt/stateless/engine/dbUpdates/*.sh); do
    echo \"### \$(basename \$s)\"
    {
      cat /tmp/fresh_prelude.sh
      # Drop each script's own config source, which would repoint SQL() at the REAL database.
      grep -v 'phvalheim-static.conf' \"\$s\" | tail -n +2
    } > /tmp/fresh_one.sh
    bash /tmp/fresh_one.sh
  done
" >/tmp/fresh_mig.out 2>&1
# Only errors from the 2.43 script are this release's problem. Older scripts can legitimately
# complain on a fresh database (adding a column to a table a later script creates, etc).
migErrors=$(sed -n '/### dbUpdate_2.43.sh/,$p' /tmp/fresh_mig.out | grep -ciE 'ERROR [0-9]+')

for t in mods mod_versions mod_deps world_mods mod_sync_runs; do
    ck "2.43 migration creates '$t'" "$(q "SHOW TABLES LIKE '$t';" | wc -l)" "1"
done
ck "migration runs without SQL errors" "$migErrors" "0"
ck "still no tsmods after the migration" "$(q "SHOW TABLES LIKE 'tsmods';" | wc -l)" "0"

# Case sensitivity is the trap that silently merges distinct mods, so assert the collation
# actually landed rather than trusting the DDL text.
ck "mods.owner is case-SENSITIVE" \
   "$(q "SELECT COLLATION_NAME FROM information_schema.columns WHERE table_schema='$DB' AND table_name='mods' AND column_name='owner';")" \
   "utf8mb4_0900_as_cs"
ck "mod_versions.version is case-SENSITIVE" \
   "$(q "SELECT COLLATION_NAME FROM information_schema.columns WHERE table_schema='$DB' AND table_name='mod_versions' AND column_name='version';")" \
   "utf8mb4_0900_as_cs"

# Two mods differing only in case must BOTH survive -- 22 such pairs exist on Thunderstore.
docker exec "$C" mysql -uroot "$DB" -e "
  INSERT INTO mods (source,owner,name,full_name) VALUES
    ('thunderstore','IronTeam','Iron_ModPack','IronTeam-Iron_ModPack'),
    ('thunderstore','IronTeam','Iron_Modpack','IronTeam-Iron_Modpack');" >/dev/null 2>&1
ck "case-differing mods are stored separately" "$(q "SELECT COUNT(*) FROM mods WHERE owner='IronTeam';")" "2"

# The settings the catalogue UI reads must exist, or Server Settings renders blanks.
for c in thunderstoreEnabled hexiumEnabled thunderstoreApiKey hexiumApiKey modSyncIntervalHours; do
    ck "settings.$c exists" "$(q "SHOW COLUMNS FROM settings LIKE '$c';" | wc -l)" "1"
done

docker exec "$C" mysql -uroot -e "DROP DATABASE IF EXISTS $DB;" >/dev/null 2>&1

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || { echo; echo "  migration output:"; sed 's/^/    /' /tmp/fresh_mig.out | head -20; }
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
