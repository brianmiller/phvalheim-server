#!/bin/bash
#
# Per-mod destinations, against a REAL mod catalogue.
#
# dev_tools/test-mod-destinations.py covers the graph rule with a hand-built graph and stubbed
# SQL. That is the right place for the awkward shapes, but it cannot fail for any of the
# reasons a real run fails: it never executes the migration, never touches a real
# world_mods, and never proves the dependency closure of an actual mod pack resolves the
# same way it did before the change.
#
# So this one runs the real dbUpdate_2.53.sh and the real worldMods.py against the real
# catalogue -- 13k mods and ~290k dependency edges -- and its headline assertion is the
# REGRESSION one from section 8.8 of docs/RELEASE-2.53-DESIGN.md, the one that matters most
# and is easiest to skip:
#
#   with both flags at their migrated default, the install plan must be byte-identical to
#   the plan the PREVIOUS worldMods.py produced for the same world.
#
# Not "looks right" -- identical, diffed against the version in git HEAD. Every world that
# already exists has to install exactly what it installed yesterday.
#
# NOTHING is written to the live database. The catalogue is cloned into a scratch schema and
# the scratch schema is dropped on exit, including on failure -- Brian's six worlds and the
# engine loop polling them never see any of this. The engine would otherwise try to BUILD a
# world row inserted for a test.
#
# Usage:  dev_tools/test-mod-destinations-live.sh [container]

CONTAINER="${1:-phvalheim-dev}"
SCRATCH="phvalheim_dt"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
FAILED=0
PASSED=0

ok(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASSED=$((PASSED+1)); }
no(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "$2" ] && printf '        %s\n' "$2"; FAILED=$((FAILED+1)); }
die(){ printf '\033[31mSETUP FAILED: %s\033[0m\n' "$1"; exit 2; }

# Scratch-only. Every query in this script goes through here, so there is no path in the
# file that can reach the live schema by accident.
dq(){ docker exec "$CONTAINER" mysql -uroot --skip-column-names --database "$SCRATCH" -e "$1" 2>&1; }
adminq(){ docker exec "$CONTAINER" mysql -uroot --skip-column-names -e "$1" 2>&1; }

cleanup(){
    adminq "DROP DATABASE IF EXISTS $SCRATCH;" >/dev/null 2>&1
    docker exec "$CONTAINER" rm -rf /tmp/destlive >/dev/null 2>&1
}
trap cleanup EXIT

docker ps --format '{{.Names}}' | grep -qx "$CONTAINER" || die "container '$CONTAINER' is not running"

echo
echo "=== setup: cloning the catalogue into $SCRATCH (live schema is untouched) ==="

adminq "DROP DATABASE IF EXISTS $SCRATCH; CREATE DATABASE $SCRATCH;" >/dev/null

# CREATE TABLE LIKE, not a hand-written DDL: mods.owner / mods.name / mod_versions.version
# are COLLATE utf8mb4_0900_as_cs and that is load-bearing -- under the default ai_ci,
# IronTeam/Iron_ModPack and IronTeam/Iron_Modpack collapse into one row. A retyped schema
# here would quietly test a different database than the one that ships.
for t in mods mod_versions mod_deps settings worlds world_mods; do
    adminq "CREATE TABLE $SCRATCH.$t LIKE phvalheim.$t;" >/dev/null
done
# Catalogue and settings get their data; worlds and world_mods stay empty and get a
# synthetic world below.
for t in mods mod_versions mod_deps settings; do
    adminq "INSERT INTO $SCRATCH.$t SELECT * FROM phvalheim.$t;" >/dev/null
done

MODCOUNT=$(dq "SELECT COUNT(*) FROM mods;")
EDGECOUNT=$(dq "SELECT COUNT(*) FROM mod_deps WHERE dep_mod_id IS NOT NULL;")
case "$MODCOUNT" in ''|*[!0-9]*) die "could not clone the catalogue: '$MODCOUNT'";; esac
[ "$MODCOUNT" -gt 100 ] || die "catalogue clone has only $MODCOUNT mods -- nothing to test against"
echo "  cloned $MODCOUNT mods, $EDGECOUNT resolved dependency edges"

# The scratch clone starts WITHOUT the new columns, which is the point: the migration has to
# add them. If phvalheim.world_mods already has them the clone would too, and the migration
# assertions below would pass without the migration doing anything.
PREEXISTING=$(dq "DESCRIBE world_mods;" | awk '{print $1}' | grep -cx -e deploy_server -e deploy_client)
echo "  scratch world_mods starts with $PREEXISTING of 2 destination columns"


echo
echo "=== 1. the real migration adds both columns, and survives running twice ==="

# The REAL dbUpdate_2.53.sh, with only its `source` line swapped for a sql() pointed at the
# scratch schema. Lifting the script rather than retyping its ALTERs is the whole point --
# a copy of the DDL in this file could drift from the one that ships and the test would
# still be green.
docker exec "$CONTAINER" mkdir -p /tmp/destlive
docker cp "$REPO/container/engine/dbUpdates/dbUpdate_2.53.sh" \
    "$CONTAINER:/tmp/destlive/mig-orig.sh" >/dev/null 2>&1 \
    || die "could not copy the migration into the container"
docker exec "$CONTAINER" sh -c "
    printf '%s\n' '#!/bin/bash' > /tmp/destlive/mig.sh
    printf '%s\n' 'sql(){ mysql -uroot --skip-column-names --database $SCRATCH -e \"\$1\"; }' >> /tmp/destlive/mig.sh
    grep -v 'phvalheim-static.conf' /tmp/destlive/mig-orig.sh >> /tmp/destlive/mig.sh
    chmod +x /tmp/destlive/mig.sh"

docker exec "$CONTAINER" /tmp/destlive/mig.sh >/tmp/mig1.$$ 2>&1
RC1=$?
[ "$RC1" = "0" ] && ok "migration exits 0 on a schema without the columns" \
    || no "migration exited $RC1" "$(tail -3 /tmp/mig1.$$)"

COLS=$(dq "DESCRIBE world_mods;" | awk '{print $1}' | grep -cx -e deploy_server -e deploy_client)
[ "$COLS" = "2" ] && ok "both deploy_server and deploy_client exist after the migration" \
    || no "found $COLS of 2 destination columns" "the ALTER did not run or named them differently"

# The default is the entire upgrade story: it is what makes an existing world install
# byte-identically. Read back from the schema rather than trusted from the script.
DEFS=$(dq "SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA='$SCRATCH' AND TABLE_NAME='world_mods'
             AND COLUMN_NAME IN ('deploy_server','deploy_client') ORDER BY COLUMN_NAME;")
WANT="deploy_client	1	NO
deploy_server	1	NO"
[ "$DEFS" = "$WANT" ] && ok "both columns are NOT NULL DEFAULT 1" \
    || no "schema reads:" "$DEFS"

# Every dbUpdates/ script runs on EVERY boot -- dbUpdater.sh has no version gate -- so the
# second run is not a nicety, it is the normal case.
docker exec "$CONTAINER" /tmp/destlive/mig.sh >/tmp/mig2.$$ 2>&1
RC2=$?
COLS2=$(dq "DESCRIBE world_mods;" | awk '{print $1}' | grep -cx -e deploy_server -e deploy_client)
if [ "$RC2" = "0" ] && [ "$COLS2" = "2" ] && ! grep -qi "duplicate column" /tmp/mig2.$$; then
    ok "a second run is a clean no-op (still exits 0, still 2 columns, no Duplicate column)"
else
    no "second migration run was not a no-op" "rc=$RC2 cols=$COLS2 $(grep -i duplicate /tmp/mig2.$$ | head -1)"
fi

# The notice block must not re-decide itself on that second boot, which is the trap
# dbUpdate_2.40.sh documents at length. Same question, asked of the scratch settings row.
NOTICE=$(dq "SELECT connectNoticeShown FROM settings LIMIT 1;")
case "$NOTICE" in
    0|1) ok "connectNoticeShown is a settled 0/1 after two runs (now: $NOTICE)" ;;
    *)   no "connectNoticeShown reads '$NOTICE'" "expected 0 or 1" ;;
esac


echo
echo "=== setup: a synthetic world whose picks have a REAL multi-level dependency graph ==="

# Resolved by (source, owner, name), never by id: mods.id is auto-increment per catalogue
# sync, so a hardcoded id is a finding with a shelf life. Picked for depth -- a pack whose
# dependencies have dependencies of their own, so the widening has somewhere to travel.
PICK_A=$(dq "SELECT id FROM mods WHERE source='thunderstore' AND owner='Captain'
             AND name='Journey_To_QoLhalla_Core' LIMIT 1;")
case "$PICK_A" in ''|*[!0-9]*) die "could not find the deep test mod in the catalogue (got '$PICK_A')";; esac

# The version a pick will ACTUALLY install, by worldMods.py's own rule, quoted from
# effective_version(): ORDER BY source_rank ASC LIMIT 1.
#
# This is not a detail. The first version of this test picked its test data with
# `source_rank = 1`, read as "the first/newest version". source_rank is ZERO-based -- rank 0
# is newest, and modcatalog.php's `latest` is `source_rank = 0` -- so every query here was
# describing each mod's SECOND-NEWEST release. The shared dependency it found was shared
# between two versions neither pick installs, so the union assertion failed against
# perfectly correct code. A good oracle pointed at the wrong configuration.
effver(){ dq "SELECT id FROM mod_versions WHERE mod_id=$1 ORDER BY source_rank ASC LIMIT 1;"; }

VER_A=$(effver "$PICK_A")
case "$VER_A" in ''|*[!0-9]*) die "no installable version for mod $PICK_A";; esac

# A second pick that SHARES a dependency with the first: that overlap is what the
# opposite-sides union assertion needs, and it has to be real, not contrived. Matched on
# rank 0 -- the version each candidate would actually install.
PICK_B=$(dq "
  SELECT m2.id FROM mods m2
  JOIN mod_versions v2 ON v2.mod_id=m2.id AND v2.source_rank=0
  JOIN mod_deps d2 ON d2.version_id=v2.id AND d2.dep_mod_id IS NOT NULL
  WHERE m2.id <> $PICK_A AND m2.source='thunderstore' AND m2.name NOT LIKE 'BepInExPack%'
    AND d2.dep_mod_id IN (
      SELECT dep_mod_id FROM mod_deps WHERE version_id=$VER_A AND dep_mod_id IS NOT NULL)
  GROUP BY m2.id ORDER BY COUNT(*) DESC LIMIT 1;")
case "$PICK_B" in ''|*[!0-9]*) die "no mod in the catalogue shares a dependency with $PICK_A";; esac

VER_B=$(effver "$PICK_B")
case "$VER_B" in ''|*[!0-9]*) die "no installable version for mod $PICK_B";; esac

SHARED=$(dq "
  SELECT dep_mod_id FROM mod_deps
  WHERE version_id=$VER_A AND dep_mod_id IS NOT NULL
    AND dep_mod_id IN (SELECT dep_mod_id FROM mod_deps
                       WHERE version_id=$VER_B AND dep_mod_id IS NOT NULL)
  LIMIT 1;")
case "$SHARED" in ''|*[!0-9]*) die "could not identify the shared dependency";; esac

# Prove the SETUP before asserting anything about the behaviour. The failure this guards
# against is not a product bug and does not look like one: the assertions downstream all
# read as "the union is broken" when in fact the fixture never had the shape they assume.
# So the fixture states its own precondition, out loud, as a check that can fail.
EDGE_A=$(dq "SELECT COUNT(*) FROM mod_deps WHERE version_id=$VER_A AND dep_mod_id=$SHARED;")
EDGE_B=$(dq "SELECT COUNT(*) FROM mod_deps WHERE version_id=$VER_B AND dep_mod_id=$SHARED;")
RANK_A=$(dq "SELECT source_rank FROM mod_versions WHERE id=$VER_A;")
if [ "${EDGE_A:-0}" -ge 1 ] && [ "${EDGE_B:-0}" -ge 1 ]; then
    ok "fixture verified: mod $SHARED is a dependency of the version BOTH picks install"
else
    no "fixture is wrong: edges to $SHARED are A=$EDGE_A B=$EDGE_B" \
       "the union assertions below would fail against correct code -- fix the fixture, not the product"
fi
[ "$RANK_A" = "0" ] && ok "effective_version resolves to source_rank 0, the newest (convention pinned)" \
    || no "effective_version resolved to source_rank '$RANK_A', not 0" \
         "the newest-version convention changed; every dep query in this file assumes rank 0"

dq "INSERT INTO worlds (name) VALUES ('desttest');" >/dev/null
WID=$(dq "SELECT id FROM worlds WHERE name='desttest' LIMIT 1;")
case "$WID" in ''|*[!0-9]*) die "could not create the scratch world (got '$WID')";; esac
echo "  world id $WID, picks $PICK_A + $PICK_B, sharing dependency $SHARED"

# Both worldMods.py versions, each pointed at the scratch schema. The OLD one comes from git
# HEAD rather than from a copy kept in this file, so it is genuinely the previous behaviour.
git -C "$REPO" show HEAD:container/engine/tools/worldMods.py > /tmp/wm_old.$$ 2>/dev/null \
    || die "could not read the previous worldMods.py from git HEAD"
grep -q "deploy_server" /tmp/wm_old.$$ \
    && die "git HEAD already contains the destination work -- there is no 'before' to diff against"

for pair in "old:/tmp/wm_old.$$" "new:$REPO/container/engine/tools/worldMods.py"; do
    tag="${pair%%:*}"; src="${pair#*:}"
    sed "s/^DB = \"phvalheim\"/DB = \"$SCRATCH\"/" "$src" > "/tmp/wm_$tag.run.$$"
    grep -q "DB = \"$SCRATCH\"" "/tmp/wm_$tag.run.$$" || die "failed to repoint the $tag worldMods.py at $SCRATCH"
    docker cp "/tmp/wm_$tag.run.$$" "$CONTAINER:/tmp/destlive/wm_$tag.py" >/dev/null 2>&1
    docker exec "$CONTAINER" chmod +x "/tmp/destlive/wm_$tag.py"
done
rm -f /tmp/wm_old.$$ /tmp/wm_old.run.$$ /tmp/wm_new.run.$$

wm(){ docker exec "$CONTAINER" python3 "/tmp/destlive/wm_$1.py" --world desttest "$2" 2>&1; }
setpicks(){  # setpicks "<mod:server:client> ..."
    dq "DELETE FROM world_mods WHERE world_id=$WID;" >/dev/null
    for spec in $1; do
        m="${spec%%:*}"; rest="${spec#*:}"; s="${rest%%:*}"; c="${rest##*:}"
        dq "INSERT INTO world_mods (world_id, mod_id, is_dep, deploy_server, deploy_client)
            VALUES ($WID, $m, 0, $s, $c);" >/dev/null
    done
}


echo
echo "=== 2. REGRESSION: at the migrated default, the plan is byte-identical to before ==="

# The assertion the whole change stands or falls on. Both switches at their DEFAULT 1 is
# every world that already exists, so the new plan must reproduce the old one exactly --
# same mods, same versions, same order, same filenames -- with nothing but the two flags
# appended. Diffed, not eyeballed.
setpicks "$PICK_A:1:1 $PICK_B:1:1"
wm old --resolve >/dev/null
OLDPLAN=$(wm old --plan)
setpicks "$PICK_A:1:1 $PICK_B:1:1"
wm new --resolve >/dev/null
NEWPLAN=$(wm new --plan)

OLDLINES=$(printf '%s\n' "$OLDPLAN" | grep -c .)
[ "${OLDLINES:-0}" -ge 5 ] \
    && ok "the previous worldMods.py plans $OLDLINES mods for this world (a real graph, not an empty one)" \
    || no "the old plan has only $OLDLINES lines" "an empty plan would make the diff below vacuous -- a no-op and a match look identical"

NEWTRIM=$(printf '%s\n' "$NEWPLAN" | cut -f1-9)
if [ "$NEWTRIM" = "$OLDPLAN" ]; then
    ok "new plan minus the two appended columns == old plan, exactly"
else
    no "the plan CHANGED for a world at the default destinations" \
       "$(diff <(printf '%s\n' "$OLDPLAN") <(printf '%s\n' "$NEWTRIM") | head -6)"
fi

NEWCOLS=$(printf '%s\n' "$NEWPLAN" | head -1 | awk -F'\t' '{print NF}')
[ "$NEWCOLS" = "11" ] && ok "the new plan has 11 columns" \
    || no "the new plan has $NEWCOLS columns" "expected 11"

DEFAULTFLAGS=$(printf '%s\n' "$NEWPLAN" | awk -F'\t' '$10!="1"||$11!="1"' | grep -c .)
[ "${DEFAULTFLAGS:-1}" = "0" ] \
    && ok "every mod in the default plan is destined for BOTH sides" \
    || no "$DEFAULTFLAGS row(s) are not 1/1 at the migrated default" "an upgrade would change what installs"


echo
echo "=== 3. a Server-only pick keeps its whole dependency subtree off the client ==="

setpicks "$PICK_A:1:0"
RESOLVE=$(wm new --resolve)
DEPROWS=$(dq "SELECT COUNT(*) FROM world_mods WHERE world_id=$WID AND is_dep=1;")
[ "${DEPROWS:-0}" -ge 3 ] \
    && ok "resolve wrote $DEPROWS dependency rows (the subtree is real)" \
    || no "only $DEPROWS dependency rows" "with no deps to inherit anything, the next two checks prove nothing"

OFFCLIENT=$(dq "SELECT COUNT(*) FROM world_mods
                WHERE world_id=$WID AND is_dep=1 AND deploy_server=1 AND deploy_client=0;")
ONCLIENT=$(dq "SELECT COUNT(*) FROM world_mods
               WHERE world_id=$WID AND is_dep=1 AND deploy_client=1;")
[ "$OFFCLIENT" = "$DEPROWS" ] && [ "$ONCLIENT" = "0" ] \
    && ok "all $DEPROWS dependencies inherited server-only -- and NONE leaked onto the client" \
    || no "$OFFCLIENT of $DEPROWS are server-only, $ONCLIENT are on the client" \
         "the client count is the control here: a union that answers both-sides always would pass the first half"

PLANOFF=$(wm new --plan | awk -F'\t' '$10=="1"&&$11=="0"' | grep -c .)
PLANALL=$(wm new --plan | grep -c .)
[ "$PLANOFF" = "$PLANALL" ] && [ "${PLANALL:-0}" -gt 0 ] \
    && ok "the install plan agrees: all $PLANALL rows are server-only" \
    || no "plan says $PLANOFF of $PLANALL are server-only" "world_mods and the plan disagree -- they are derived from one closure() and must not"

# The mirror. Without it, "server-only stays off the client" is satisfied by anything that
# writes 1/0 unconditionally.
setpicks "$PICK_A:0:1"
wm new --resolve >/dev/null
MIRROR=$(dq "SELECT COUNT(*) FROM world_mods
             WHERE world_id=$WID AND is_dep=1 AND deploy_server=0 AND deploy_client=1;")
MIRRORALL=$(dq "SELECT COUNT(*) FROM world_mods WHERE world_id=$WID AND is_dep=1;")
[ "$MIRROR" = "$MIRRORALL" ] && [ "${MIRRORALL:-0}" -gt 0 ] \
    && ok "flipped: all $MIRRORALL dependencies inherit client-only instead" \
    || no "$MIRROR of $MIRRORALL are client-only" "the flags are not actually being read"


echo
echo "=== 4. a dependency shared by opposite-side parents lands on both ==="

setpicks "$PICK_A:1:0 $PICK_B:0:1"
wm new --resolve >/dev/null
SHAREDFLAGS=$(dq "SELECT CONCAT(deploy_server,'/',deploy_client) FROM world_mods
                  WHERE world_id=$WID AND mod_id=$SHARED LIMIT 1;")
SHAREDNAME=$(dq "SELECT CONCAT(owner,'/',name) FROM mods WHERE id=$SHARED LIMIT 1;")
[ "$SHAREDFLAGS" = "1/1" ] \
    && ok "$SHAREDNAME, needed by a Server-only and a Client-only pick, is on both sides" \
    || no "$SHAREDNAME reads $SHAREDFLAGS, expected 1/1" \
         "whichever side is 0 is a BepInEx load failure that never names this dependency"

# Control: same two picks, same shared dependency, both parents Server-only. If the shared
# dep is 1/1 here too, the union is not computing anything -- it is just defaulting.
setpicks "$PICK_A:1:0 $PICK_B:1:0"
wm new --resolve >/dev/null
CTRLFLAGS=$(dq "SELECT CONCAT(deploy_server,'/',deploy_client) FROM world_mods
                WHERE world_id=$WID AND mod_id=$SHARED LIMIT 1;")
[ "$CTRLFLAGS" = "1/0" ] \
    && ok "with both parents Server-only the same dependency is 1/0, not 1/1 (control)" \
    || no "control reads $CTRLFLAGS, expected 1/0" \
         "the union is defaulting to both rather than deriving -- section 4's PASS above is then meaningless"


echo
echo "=== 5. a mod destined nowhere is skipped loudly, not downloaded and discarded ==="

setpicks "$PICK_A:0:0"
wm new --resolve >/dev/null
NOWHERE=$(wm new --plan)
NOWHERELINES=$(printf '%s\n' "$NOWHERE" | grep -cE "$(printf '\t')")
if printf '%s\n' "$NOWHERE" | grep -q "installs nowhere" && [ "${NOWHERELINES:-1}" -eq 0 ]; then
    ok "a both-switches-off pick produces no plan rows and a WARN naming it"
else
    no "both-off produced $NOWHERELINES plan row(s) and no warning" \
       "the picker cannot create this state, but a row that reaches it must not install silently"
fi

echo
if [ "$FAILED" -gt 0 ]; then
    printf '\033[31m%d of %d checks failed\033[0m\n\n' "$FAILED" "$((PASSED+FAILED))"
    exit 1
fi
printf '\033[32mall %d checks passed\033[0m  (scratch schema %s dropped)\n\n' "$PASSED" "$SCRATCH"
exit 0
