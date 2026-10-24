#!/bin/bash
# Oracle test: Hugin's get_backup_status reads the backups TABLE, not a directory (2.59).
# requires: container
#
# THE BUG. An operator asked Hugin to "list all backups" and was told every world had 0 backups
# and 0 MB; the Backups tab showed dozens. aiToolBackups() globbed /opt/stateful/backups/<world>/,
# a directory that has never existed (archives are flat files named valheimworld_<World>-<stamp>),
# and never looked at the `backups` table the engine writes and the Backups tab reads. Hugin
# trusted the tool, was contradicted, and went scraping worldBackups.log for ids -- exactly the
# behaviour the prompt now forbids: the database is the system of record for PhValheim state.
#
# HOW. Plants two rows in `backups` for a real world (the tool iterates the worlds table), calls
# the real tool inside the container, and asserts the count, the size, both ids and the newest
# stamp come back. The baseline run before planting is the control: the delta is what is
# measured, so a dev box that already has backups still passes. Rows are removed on exit.
#
#   $1 = container (default phvalheim-dev)   $2 = a world name (default midgard)

C="${1:-phvalheim-dev}"; W="${2:-midgard}"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; fail=$((fail+1)); }
sql() { docker exec -i "$C" mysql -N phvalheim -e "$1" 2>&1; }

docker exec "$C" true 2>/dev/null || { echo "HARNESS: container $C not running"; exit 2; }
[ "$(sql "SELECT COUNT(*) FROM worlds WHERE name='$W'")" = "1" ] || { echo "HARNESS: no world named $W"; exit 2; }

tool() {  # prints the tool's JSON for $W
	docker exec -i -e W="$W" "$C" php 2>&1 <<'PHP'
<?php
require '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
require '/opt/stateless/nginx/www/includes/db_gets.php';
require_once '/opt/stateless/nginx/www/includes/aiproviders.php';
require_once '/opt/stateless/nginx/www/includes/aicontext.php';
echo aiRunTool($pdo, 'get_backup_status', ['world' => getenv('W')]);
PHP
}
field() { python3 -c "import json,sys; d=json.load(sys.stdin); w=d[0]; print($1)"; }

echo "=== Hugin get_backup_status reads the backups table ==="
before=$(tool); echo "$before" | grep -q '"backup_count"' || { bad "the tool answers" "$(echo "$before" | head -3)"; echo "=== $pass passed, $fail failed ==="; exit 1; }
n0=$(echo "$before" | field "w['backup_count']"); mb0=$(echo "$before" | field "w['total_size_mb']")
ok "control: baseline for $W is $n0 backup(s), $mb0 MB (whatever the box has)"

# --- fixture: two rows, 3 MiB + 5 MiB, one compressed, timestamps a minute apart -------------
sql "INSERT INTO backups (world_name, created_at, type, file_path, file_size, uncompressed_size, compressed, compression_type, orphaned, metadata)
     VALUES ('$W', '2031-01-02 03:04:05', 'scheduled', '/opt/stateful/backups/valheimworld_${W}-2031-01-02T03:04:05+0000.tar',     3145728, 3145728, 0, NULL,  0, JSON_OBJECT('oracle', 'backups-from-db')),
            ('$W', '2031-01-02 03:05:05', 'manual',    '/opt/stateful/backups/valheimworld_${W}-2031-01-02T03:05:05+0000.tar.zst', 5242880, 9000000, 1, 'zstd', 0, JSON_OBJECT('oracle', 'backups-from-db'))" >/dev/null
ids=$(sql "SELECT GROUP_CONCAT(id) FROM backups WHERE world_name='$W' AND created_at LIKE '2031-01-02%'")
cleanup() { sql "DELETE FROM backups WHERE world_name='$W' AND created_at LIKE '2031-01-02%'" >/dev/null; }
trap cleanup EXIT
id1=${ids%%,*}; id2=${ids##*,}

after=$(tool)
n1=$(echo "$after" | field "w['backup_count']"); mb1=$(echo "$after" | field "w['total_size_mb']")
[ "$n1" = "$((n0+2))" ] && ok "backup_count rose by exactly the two planted rows ($n0 -> $n1)" || bad "backup_count" "before=$n0 after=$n1"
python3 -c "import sys; sys.exit(0 if abs(float('$mb1')-float('$mb0')-8.0)<0.11 else 1)" && ok "total_size_mb rose by 8.0 MB (3 + 5 MiB from file_size)" || bad "total_size_mb" "before=$mb0 after=$mb1"
echo "$after" | field "'yes' if {$id1,$id2} <= {b['id'] for b in w['backups']} else 'no'" | grep -qx yes && ok "both planted ids are in the backups list (the id is what a restore needs)" || bad "ids listed"
[ "$(echo "$after" | field "w['newest']")" = "2031-01-02 03:05:05" ] && ok "newest is the later planted stamp" || bad "newest" "$(echo "$after" | field "w['newest']")"
echo "$after" | field "[b for b in w['backups'] if b['id']==$id2][0]['compression']" | grep -qx zstd && ok "compression is reported from the row (zstd)" || bad "compression"
echo "$after" | field "w['by_type'].get('manual',0) >= 1 and w['by_type'].get('scheduled',0) >= 1" | grep -qx True && ok "by_type counts manual and scheduled" || bad "by_type"
echo "$after" | field "w['source']" | grep -q "backups table" && ok "the result names its source: the backups table" || bad "source named"

# --- the retired reader must be gone, by its text ----------------------------------------------
f="$(dirname "$0")/../container/nginx/www/includes/aicontext.php"
grep -q 'glob("$dir/\*")' "$f" && bad "the directory glob is still in aiToolBackups" || ok "no directory glob in aiToolBackups"
grep -q "THE DATABASE IS THE SYSTEM OF RECORD" "$f" && ok "the prompt states the database is the system of record" || bad "prompt rule missing"

echo "=== $pass passed, $fail failed ==="
[ "$fail" = "0" ]
