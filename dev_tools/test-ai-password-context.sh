#!/bin/bash
# Oracle test: what Hugin is TOLD about passwords.
#
# WHAT IT GUARDS. Three defects shipped in 2.45, all in the payload rather than the model:
#
#   1. `password_public` is a TINYINT display flag (show the password on the public world
#      card). It was redacted as if it were a credential, and since both "0" and "1" are
#      !== "", it reported "(set — redacted)" EITHER WAY. That destroys the boolean and
#      invents a second password. A live model duly told an operator there was "a separate
#      password for the public/spectator view". No such thing exists in Valheim.
#   2. The redaction guarded with isset(), which is false for NULL — the column default —
#      so the commonest case skipped redaction entirely and went out as `"password": null`
#      while an empty string became "(not set)". Same fact, two spellings.
#   3. A password is applied to VANILLA worlds only; startWorld.sh never passes -password
#      on a modded world. Nothing in the payload said so, and the rule lived only in the
#      OPERATING PROCEDURES block, which is omitted for a model that cannot act.
#
# WHY IT ASSERTS ON THE JSON. Counting keys in the PHP source would pass on a file that
# emits them with the wrong values — which is exactly defect 1, where the key was present
# and correct-looking on every world. So every assertion here reads the string the tool
# actually returns, for a world whose DB state the test set itself.
#
# MUTATION CHECK: reverting the aiToolGetWorld redaction block to the 2.45 original must
# turn PW3, PW4, PW5 and PW6 red. If it does not, this test cannot see the bug it names.
#
#   dev_tools/test-ai-password-context.sh [container]

set -u
C="${1:-phvalheim-dev}"
DB=aipwtest
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
pass=0; fail=0

ok()   { pass=$((pass+1)); printf "  PASS  %s\n" "$1"; }
bad()  { fail=$((fail+1)); printf "  FAIL  %s\n         wanted: %s\n         got:    %s\n" "$1" "$2" "$3"; }
check(){ [ "$2" = "$3" ] && ok "$1" || bad "$1" "$2" "$3"; }

docker exec "$C" mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;
  GRANT ALL ON $DB.* TO 'phvalheim_user'@'localhost'; FLUSH PRIVILEGES;" || exit 1
docker exec "$C" sh -c "mysqldump --no-data --skip-add-drop-table phvalheim | mysql $DB" || exit 1

# Four worlds that between them cover every branch. Both Vanilla and Modded carry the SAME
# password, so any assertion that conflates "stored" with "in effect" fails on one of them.
docker exec "$C" mysql "$DB" -e "
INSERT INTO worlds (name,port,status,vanilla,crossplay,listed,public,password,password_public,seed) VALUES
 ('Vanilla',  25901,'Running',1,0,0,0,'HammerTime7',1,'s'),
 ('Modded',   25902,'Running',0,0,0,0,'HammerTime7',0,'s'),
 ('NullPass', 25903,'Running',1,0,0,0,NULL,1,'s'),
 ('EmptyPass',25904,'Running',1,0,0,0,'',0,'s');" || exit 1

docker exec "$C" rm -rf /tmp/aipw && docker exec "$C" mkdir -p /tmp/aipw
docker cp "$REPO/container/nginx/www/includes/." "$C:/tmp/aipw/" >/dev/null

# -i, or docker exec does not forward this heredoc and the file lands EMPTY -- which fails
# every assertion below for a reason that has nothing to do with the code under test.
docker exec -i "$C" sh -c "cat > /tmp/aipw/probe.php" <<'PHP'
<?php
$pdo = new PDO('mysql:host=localhost;dbname=aipwtest','phvalheim_user','phvalheim_secretpassword',
               [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_once '/tmp/aipw/aicontext.php';
$mode = $argv[1];
if ($mode === 'list') { echo aiToolListWorlds($pdo); exit; }
if ($mode === 'get')  { echo aiToolGetWorld($pdo, $argv[2]); exit; }
if ($mode === 'flag') { // prove the display flag still round-trips both values
    $pdo->exec("UPDATE worlds SET password_public=" . (int)$argv[2] . " WHERE name='Vanilla'");
    $j = json_decode(aiToolGetWorld($pdo,'Vanilla'), true);
    echo $j['show_password_on_public_card'];
}
PHP

get()  { docker exec "$C" php /tmp/aipw/probe.php get "$1"; }
jqf()  { python3 -c "import json,sys;print(json.dumps(json.load(sys.stdin).get(sys.argv[1]),default=str))" "$1"; }

echo "== aiToolGetWorld =="
check "PW1  a real password is redacted, never emitted" \
      '"(set \u2014 redacted)"' "$(get Vanilla | jqf password)"
check "PW2  a modded world still reports its stored password" \
      '"(set \u2014 redacted)"' "$(get Modded | jqf password)"
# NULL is the column DEFAULT. isset() is false for it, so the 2.45 code skipped the
# redaction and shipped a bare null while an empty string became "(not set)".
check "PW3  a NULL password reads the same as an empty one" \
      '"(not set)"' "$(get NullPass | jqf password)"
check "PW4  an empty password reads as not set" \
      '"(not set)"' "$(get EmptyPass | jqf password)"

# The heart of it: the display flag must NOT look like a credential.
check "PW5  password_public is gone from the payload" \
      'null' "$(get Vanilla | jqf password_public)"
check "PW6  the display flag survives as a number, not a redaction" \
      '1' "$(docker exec "$C" php /tmp/aipw/probe.php flag 1)"
check "PW7  ...and 0 stays 0 (it reported set--redacted for BOTH before)" \
      '0' "$(docker exec "$C" php /tmp/aipw/probe.php flag 0)"

echo "== vanilla-only application =="
check "PW8  a vanilla world with a password has it IN EFFECT" \
      'true' "$(get Vanilla | jqf password_in_effect)"
# startWorld.sh:85-88 -- the modded branch passes -public 0 and no -password at all.
check "PW9  a MODDED world has a password stored but NOT in effect" \
      'false' "$(get Modded | jqf password_in_effect)"
check "PW10 a passwordless vanilla world is not in effect either" \
      'false' "$(get NullPass | jqf password_in_effect)"

echo "== aiToolListWorlds (the tool the prompt says to call first) =="
L=$(docker exec "$C" php /tmp/aipw/probe.php list)
lw() { echo "$L" | python3 -c "
import json,sys
for w in json.load(sys.stdin):
    if w['name']==sys.argv[1]: print(json.dumps(w.get(sys.argv[2])))" "$1" "$2"; }
# Before this, the summary carried access-control state and NO password state, so a model
# asked how the server was secured could only answer from the half it was shown.
check "PW11 the summary reports whether a password exists"      'true'  "$(lw Vanilla has_password)"
check "PW12 the summary reports whether it is in effect"        'true'  "$(lw Vanilla password_in_effect)"
check "PW13 a modded world: has one, but it does nothing"       'true'  "$(lw Modded has_password)"
check "PW14 ...and the summary says so"                         'false' "$(lw Modded password_in_effect)"
check "PW15 no password is not the same as having one"          'false' "$(lw NullPass has_password)"

echo "== the rule reaches a READ-ONLY Hugin too =="
# It lived only in OPERATING PROCEDURES, which aiSystemPrompt omits when $withActions is
# false -- so the Hugin that cannot act was never told, and said a modded world was
# password protected. Assert on the prompt built with actions OFF.
docker exec -i "$C" sh -c "cat > /tmp/aipw/prompt.php" <<'PHP'
<?php
$pdo = new PDO('mysql:host=localhost;dbname=aipwtest','phvalheim_user','phvalheim_secretpassword');
require_once '/tmp/aipw/aicontext.php';
echo aiSystemPrompt($pdo, '', false);
PHP
P=$(docker exec "$C" php /tmp/aipw/prompt.php)
check "PW16 read-only prompt states passwords are vanilla-only" \
      '1' "$(echo "$P" | grep -c 'A PASSWORD ONLY APPLIES TO A VANILLA WORLD')"
check "PW17 read-only prompt denies a second password exists" \
      '1' "$(echo "$P" | grep -c 'There is no second password')"

docker exec "$C" sh -c "mysql -e \"DROP DATABASE IF EXISTS $DB\"; rm -rf /tmp/aipw"
echo
echo "passed $pass, failed $fail"
[ "$fail" -eq 0 ]
