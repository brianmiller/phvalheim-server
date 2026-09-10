#!/bin/bash
# End-to-end tests for the CITIZENS / ADMINS / BANNED editors.
#
# These run against a LIVE container and assert on the bytes in the real list files, not on
# what the API said. That distinction is the whole point: the bug these tests exist for was
# an API that returned {"success":true,"message":"Saved successfully"} while the file it was
# supposed to write kept its previous contents.
#
#   ./test-accesslists.sh [container]     (default: phvtest)
#
# The container must be a running phvalheim-server with the database up.

set -u
CONTAINER="${1:-phvtest}"
WORLD="acltest"
SD="/opt/stateful/games/valheim/worlds/$WORLD/game/.config/unity3d/IronGate/Valheim"
API="http://localhost:8081/adminAPI.php"

PASS=0; FAIL=0

ok()   { echo "  PASS: $1"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL: $1"; FAIL=$((FAIL+1)); }

dex()  { docker exec "$CONTAINER" "$@"; }
dsh()  { docker exec "$CONTAINER" sh -c "$1"; }

# POST json to the admin API, echo the response body
post() { docker exec "$CONTAINER" curl -s -X POST -H 'Content-Type: application/json' -d "$2" "$API?action=$1"; }
get()  { docker exec "$CONTAINER" curl -s "$API?action=$1&world=$WORLD"; }

fileHas()    { dsh "grep -q '$2' $SD/$1" 2>/dev/null; }
fileExists() { dsh "test -f $SD/$1" 2>/dev/null; }

echo "=== setup: world '$WORLD' in container '$CONTAINER' ==="
dex mysql phvalheim -e "DELETE FROM worlds WHERE name='$WORLD';" >/dev/null 2>&1
dex mysql phvalheim -e "INSERT INTO worlds (name,ip,port,seed,status,mode,citizens,public,vanilla) VALUES ('$WORLD','127.0.0.1',25099,'seed','stopped','idle','',0,0);" >/dev/null 2>&1
dsh "rm -rf /opt/stateful/games/valheim/worlds/$WORLD" >/dev/null 2>&1
dex bash -c "source /opt/stateless/engine/includes/phvalheim-static.conf; source /opt/stateless/engine/includes/0-functions.sh; worldDirPrep $WORLD" >/dev/null 2>&1

# ---------------------------------------------------------------------------------------
echo
echo "--- 0. CONTROL: the assertions can actually detect a wrong file ---"
# If this control ever passes, every 'file contains X' assertion below is meaningless.
dsh "echo 'sentinel-not-a-steamid' > $SD/permittedlist.txt"
if fileHas permittedlist.txt "76561198000000101"; then
	bad "CONTROL: assertion reported a match in a file that does not contain it -- ALL RESULTS BELOW ARE INVALID"
else
	ok "CONTROL: a file without the ID is correctly seen as not matching"
fi

# ---------------------------------------------------------------------------------------
echo
echo "--- 1. world prep creates all three list files ---"
dsh "rm -rf /opt/stateful/games/valheim/worlds/$WORLD" >/dev/null 2>&1
dex bash -c "source /opt/stateless/engine/includes/phvalheim-static.conf; source /opt/stateless/engine/includes/0-functions.sh; worldDirPrep $WORLD" >/dev/null 2>&1
for f in permittedlist.txt adminlist.txt bannedlist.txt; do
	if fileExists "$f"; then ok "worldDirPrep created $f"; else bad "worldDirPrep did NOT create $f"; fi
done

# ---------------------------------------------------------------------------------------
echo
echo "--- 2. baseline: each editor writes its file ---"
post saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"76561198000000101 76561198000000102\",\"public\":0}" >/dev/null
post saveAdmins   "{\"world\":\"$WORLD\",\"admins\":\"76561198000000201\"}" >/dev/null
post saveBanned   "{\"world\":\"$WORLD\",\"banned\":\"76561198000000301\"}" >/dev/null

fileHas permittedlist.txt 76561198000000101 && ok "citizens reached permittedlist.txt" || bad "citizens did NOT reach permittedlist.txt"
fileHas adminlist.txt     76561198000000201 && ok "admins reached adminlist.txt"       || bad "admins did NOT reach adminlist.txt"
fileHas bannedlist.txt    76561198000000301 && ok "banned reached bannedlist.txt"      || bad "banned did NOT reach bannedlist.txt"

# ---------------------------------------------------------------------------------------
echo
echo "--- 3. REGRESSION: a list file the web user cannot open for writing ---"
# This is the reproduced bug. A list file owned by root -- left by an older engine or by a
# restore that runs as root -- made file_put_contents() fail. The API still answered
# {"success":true}, the database still updated, and the file kept its old contents, so the
# world went on enforcing a stale citizens list and refusing newly added players.
dsh "chown root:root $SD/permittedlist.txt $SD/adminlist.txt $SD/bannedlist.txt"
RESP_C=$(post saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"76561198000000101 76561198000000102 76561198000000999\",\"public\":0}")
RESP_A=$(post saveAdmins   "{\"world\":\"$WORLD\",\"admins\":\"76561198000000201 76561198000000888\"}")
RESP_B=$(post saveBanned   "{\"world\":\"$WORLD\",\"banned\":\"76561198000000301 76561198000000777\"}")

fileHas permittedlist.txt 76561198000000999 && ok "root-owned permittedlist.txt was still replaced" || bad "root-owned permittedlist.txt NOT updated (response was: $RESP_C)"
fileHas adminlist.txt     76561198000000888 && ok "root-owned adminlist.txt was still replaced"     || bad "root-owned adminlist.txt NOT updated (response was: $RESP_A)"
fileHas bannedlist.txt    76561198000000777 && ok "root-owned bannedlist.txt was still replaced"    || bad "root-owned bannedlist.txt NOT updated (response was: $RESP_B)"

# ---------------------------------------------------------------------------------------
echo
echo "--- 4. a write that genuinely cannot happen must NOT report success ---"
# Make the directory itself unwritable, so even the atomic rename has nowhere to go.
dsh "chown root:root $SD && chmod 555 $SD"
RESP=$(post saveAdmins "{\"world\":\"$WORLD\",\"admins\":\"76561198000000201 76561198000000555\"}")
case "$RESP" in
	*'"success":false'*) ok "unwritable directory reported as an error: $(echo "$RESP" | head -c 110)" ;;
	*)                   bad "unwritable directory still reported SUCCESS: $RESP" ;;
esac
dsh "chown phvalheim:phvalheim $SD && chmod 775 $SD"

# ---------------------------------------------------------------------------------------
echo
echo "--- 5. invalid SteamIDs are rejected and the file is left alone ---"
BEFORE=$(dsh "cat $SD/adminlist.txt")
RESP=$(post saveAdmins "{\"world\":\"$WORLD\",\"admins\":\"notasteamid\"}")
AFTER=$(dsh "cat $SD/adminlist.txt")
case "$RESP" in
	*'"success":false'*) ok "non-SteamID64 rejected" ;;
	*)                   bad "non-SteamID64 accepted: $RESP" ;;
esac
[ "$BEFORE" = "$AFTER" ] && ok "adminlist.txt untouched by a rejected save" || bad "adminlist.txt was modified by a rejected save"

# ---------------------------------------------------------------------------------------
echo
echo "--- 6. public world writes an EMPTY permitted list but keeps its citizens ---"
post saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"76561198000000101 76561198000000102\",\"public\":1}" >/dev/null
if fileHas permittedlist.txt 76561198000000101; then
	bad "public world still lists citizens in permittedlist.txt (would keep gating a public world)"
else
	ok "public world wrote an empty permitted list"
fi
case "$(get getCitizens)" in
	*76561198000000101*) ok "citizens preserved in the database while public" ;;
	*)                   bad "citizens lost from the database when set public" ;;
esac

# ---------------------------------------------------------------------------------------
echo
echo "--- 7. syncAccessLists.sh heals files that drifted from the database ---"
# A restore, a rebuild, or a failed write can leave these files disagreeing with the admin
# UI. Nothing used to reconcile them, so the drift was permanent.
post saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"76561198000000101\",\"public\":0}" >/dev/null
post saveAdmins   "{\"world\":\"$WORLD\",\"admins\":\"76561198000000201\"}" >/dev/null
post saveBanned   "{\"world\":\"$WORLD\",\"banned\":\"76561198000000301\"}" >/dev/null

# simulate the drift
dsh "echo '// stale' > $SD/permittedlist.txt; echo '76561198000000666' >> $SD/permittedlist.txt"
dsh "rm -f $SD/adminlist.txt $SD/bannedlist.txt"

dex /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" >/dev/null 2>&1

fileHas permittedlist.txt 76561198000000101 && ok "sync restored the real citizens"  || bad "sync did NOT restore citizens"
if fileHas permittedlist.txt 76561198000000666; then bad "sync left the stale entry behind"; else ok "sync removed the stale entry"; fi
fileHas adminlist.txt  76561198000000201 && ok "sync recreated adminlist.txt"  || bad "sync did NOT recreate adminlist.txt"
fileHas bannedlist.txt 76561198000000301 && ok "sync recreated bannedlist.txt" || bad "sync did NOT recreate bannedlist.txt"

# ---------------------------------------------------------------------------------------
echo
echo "--- 8. file format matches what Valheim itself writes ---"
# Verified against the real dedicated server: it creates these files with these exact
# headers. Note the DOUBLE space in the admin and banned headers -- that is Valheim's.
dsh "head -1 $SD/permittedlist.txt | grep -qx '// List permitted players ID ONE per line'" && ok "permittedlist header matches Valheim" || bad "permittedlist header differs from Valheim"
dsh "head -1 $SD/adminlist.txt     | grep -qx '// List admin players ID  ONE per line'"    && ok "adminlist header matches Valheim"     || bad "adminlist header differs from Valheim"
dsh "head -1 $SD/bannedlist.txt    | grep -qx '// List banned players ID  ONE per line'"   && ok "bannedlist header matches Valheim"    || bad "bannedlist header differs from Valheim"

# ---------------------------------------------------------------------------------------
echo
echo "--- 9. entries are written in the form Valheim actually matches ---"
# Valheim 1.0's ZNet.ListContainsId() ends with a lookup for the single-letter DISPLAY
# prefix form (Steam -> "V") that OVERWRITES the earlier bare/"Steam_" checks. So a plain
# SteamID64 on disk can never match, no matter that it is the id everyone knows.
# Confirmed by decompiling the shipped assembly and verified on a live server.
post saveCitizens "{\"world\":\"$WORLD\",\"citizens\":\"76561198000000101\",\"public\":0}" >/dev/null
if fileHas permittedlist.txt "^V_76561198000000101$"; then
	ok "bare SteamID64 is written as V_76561198000000101"
else
	bad "bare SteamID64 NOT converted -> file says: $(dsh "cat $SD/permittedlist.txt" | tr '\n' '|')"
fi
if fileHas permittedlist.txt "^76561198000000101$"; then
	bad "the unmatched bare form was written -- Valheim will ignore it"
else
	ok "the unmatched bare form is not written"
fi

# CHANGED 2026-09-10: the database used to keep the operator's original text, converting only
# at write time. That left the DB and the admin UI showing a bare SteamID64 while the file --
# the thing Valheim actually matches -- held V_<id>. Three views of one value, two in a shape
# Valheim would never match. A bare id is still accepted as INPUT; it is just stored canonical.
echo "  (the database stores the canonical, matched form:)"
case "$(get getCitizens)" in
	*'"citizens":"V_76561198000000101"'*) ok "database stores the canonical V_ form" ;;
	*'"citizens":"76561198000000101"'*)   bad "database still stores the bare id, which matches nothing" ;;
	*)                                    bad "unexpected: $(get getCitizens)" ;;
esac

echo
echo "--- 10. already-canonical and console IDs are accepted and preserved ---"
post saveAdmins "{\"world\":\"$WORLD\",\"admins\":\"V_76561198000000201 X_1234567890 Steam_76561198000000202\"}" >/dev/null
fileHas adminlist.txt "^V_76561198000000201$" && ok "V_ form passes through"          || bad "V_ form mangled"
fileHas adminlist.txt "^X_1234567890$"        && ok "Xbox console ID accepted"        || bad "Xbox console ID rejected or mangled"
fileHas adminlist.txt "^V_76561198000000202$" && ok "Steam_ long form mapped to V_"   || bad "Steam_ long form not mapped"

echo
echo "--- 11. genuinely invalid entries are still rejected ---"
BEFORE=$(dsh "cat $SD/adminlist.txt")
RESP=$(post saveAdmins "{\"world\":\"$WORLD\",\"admins\":\"notanid\"}")
case "$RESP" in
	*'"success":false'*) ok "bare non-numeric junk rejected" ;;
	*)                   bad "junk accepted: $RESP" ;;
esac
[ "$BEFORE" = "$(dsh "cat $SD/adminlist.txt")" ] && ok "adminlist.txt untouched" || bad "adminlist.txt modified by a rejected save"

echo
echo "--- 12. the engine-side sync converts identically to the PHP side ---"
# Two writers render these files (admin UI and world start). If they disagree, a world
# start silently rewrites what the UI just saved into a different form.
dex mysql phvalheim -e "UPDATE worlds SET citizens='76561198000000101', admins='76561198000000201', banned='76561198000000301' WHERE name='$WORLD';" >/dev/null 2>&1
dex /opt/stateless/games/valheim/scripts/syncAccessLists.sh "$WORLD" >/dev/null 2>&1
fileHas permittedlist.txt "^V_76561198000000101$" && ok "syncAccessLists converts citizens" || bad "syncAccessLists did NOT convert citizens"
fileHas adminlist.txt     "^V_76561198000000201$" && ok "syncAccessLists converts admins"   || bad "syncAccessLists did NOT convert admins"
fileHas bannedlist.txt    "^V_76561198000000301$" && ok "syncAccessLists converts banned"   || bad "syncAccessLists did NOT convert banned"

echo
echo "========================================"
echo " PASS: $PASS   FAIL: $FAIL"
echo "========================================"
[ "$FAIL" -eq 0 ] || exit 1
