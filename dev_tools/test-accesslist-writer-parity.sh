#!/bin/bash
# Oracle test: the PHP writer and the shell writer must render permittedlist.txt IDENTICALLY.
#
# WHY THIS EXISTS
#
# Two things write these files: writeAccessList() in accesslists.php (on Save, for immediate
# feedback) and syncAccessLists.sh (at every world start, and the authority). The fail-closed
# sentinel had to be implemented in BOTH -- separately, in two languages, with the entry and
# its four comment lines duplicated.
#
# That duplication is the hazard. If they drift, the admin UI writes one thing and the next
# world start writes another, and the difference is *which players can connect*. A change to
# one that forgets the other would otherwise show up only as a mysterious access change after
# a restart -- exactly the class of silent drift syncAccessLists.sh was built to end.
#
# So this diffs the actual bytes produced by each, across the three states that matter.
#
# Usage:  dev_tools/test-accesslist-writer-parity.sh [container] [world]

CONTAINER="${1:-phvalheim-dev}"
WORLD="${2:-test2}"
SAVEDIR="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"
LIST="$SAVEDIR/permittedlist.txt"

pass=0; fail=0
check() {
    if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  PASS  $1"
    else fail=$((fail+1)); echo "  FAIL  $1${3:+ -- $3}"; fi
}

sql() { docker exec "$CONTAINER" /opt/stateless/engine/tools/sql "$1"; }

ORIG=$(sql "SELECT IFNULL(public,0), IFNULL(CONCAT('@@',citizens),'NULL') FROM worlds WHERE name='$WORLD'")
ORIG_PUBLIC=$(echo "$ORIG" | cut -f1)
ORIG_CIT=$(echo "$ORIG" | cut -f2)
if [ "$ORIG_CIT" = "NULL" ]; then ORIG_CIT_SQL="NULL"; else ORIG_CIT_SQL="'${ORIG_CIT#@@}'"; fi
trap 'sql "UPDATE worlds SET public=$ORIG_PUBLIC, citizens=$ORIG_CIT_SQL WHERE name='"'"'$WORLD'"'"'" >/dev/null 2>&1' EXIT

# Render through the SHELL writer (reads the database itself).
renderShell() {
    sql "UPDATE worlds SET public=$1, citizens='$2' WHERE name='$WORLD'" >/dev/null
    docker exec "$CONTAINER" /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" >/dev/null 2>&1
    docker exec "$CONTAINER" cat "$LIST"
}

# Render through the PHP writer (told the same state explicitly).
# $1=public flag, $2=citizens -- mirrors what saveCitizensJson() passes.
renderPhp() {
    local isPublic="$1" cit="$2"
    docker exec "$CONTAINER" php -r "
        require_once '/opt/stateless/nginx/www/includes/accesslists.php';
        \$isPublic = $isPublic;
        \$r = writeAccessList('$WORLD', 'citizens', \$isPublic ? '' : '$cit', !\$isPublic);
        if (!\$r['ok']) { fwrite(STDERR, \$r['error']); exit(1); }
    " 2>/dev/null
    docker exec "$CONTAINER" cat "$LIST"
}

compare() {
    local label="$1" pub="$2" cit="$3"
    local a b
    a=$(renderShell "$pub" "$cit")
    b=$(renderPhp   "$pub" "$cit")
    if [ "$a" = "$b" ]; then
        check "$label" 1
    else
        check "$label" 0 "writers disagree"
        echo "    --- syncAccessLists.sh ---"; echo "$a" | sed 's/^/    /'
        echo "    --- writeAccessList()  ---"; echo "$b" | sed 's/^/    /'
    fi
}

echo "(container $CONTAINER, world \"$WORLD\")"
echo
echo "Byte-for-byte parity across the three states that change who can connect:"
compare "enforced + EMPTY  (both must emit the placeholder)" 0 ""
compare "enforced + one id (neither may emit the placeholder)" 0 "76561197960287930"
compare "list OFF + empty  (neither may emit anything)"        1 ""

echo
echo "Control: the comparison can actually detect a difference"
# Without this, a compare() that always returned equal -- a broken cat, an empty string on both
# sides -- would report three passes while testing nothing.
a=$(renderShell 0 "")
b=$(renderShell 1 "")
check "an open list and a closed list do NOT compare equal" "$([ "$a" != "$b" ] && echo 1 || echo 0)" \
    "identical output for opposite states means this test is blind"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
