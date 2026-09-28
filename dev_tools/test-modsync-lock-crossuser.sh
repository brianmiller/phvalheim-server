#!/bin/bash
# Oracle test: the mod-sync lock must be takeable by BOTH uids that run modSync.py.
#
# THE BUG THIS CATCHES (2.52, shipped broken in 2.47-2.51). modSync.py runs as two
# different users: root at boot (the engine, via syncModCatalogue) and phvalheim from
# cron and from the admin UI's forced per-catalogue sync. take_global_lock() opened its
# lock files with open(path, "w"), which needs write permission in order to truncate.
# The boot sync runs first and always, so root created /tmp/phvalheim-modsync.*.lock as
# root:root 0644; /tmp is sticky, so every later phvalheim run could neither truncate the
# file nor delete it and died with PermissionError [Errno 13]. The forced link showed a
# traceback; cron failed hourly into a log nobody reads. Catalogues stopped updating
# except at container restart.
#
# WHY THIS TEST CAN SEE IT, AND dev_tools/test-modsync-lock.py CANNOT. That test drives
# the real flock calls, but every case runs as ONE uid in ONE process -- by construction
# it cannot observe a cross-uid permission failure, so it passed green throughout all
# five broken releases. The whole bug is the uid split, so the test has to be a container
# with two real uids in it.
#
# Case 3 is the control that matters most: a lock you can always take is not a lock. The
# incident take_global_lock() exists to prevent (two forced syncs deadlocking mod_deps,
# 668 versions' dependency edges lost permanently) would come straight back if the "fix"
# were simply to make the lock always succeed.
#
# It mounts the REPO's files into a container userland, so it tests the working tree and
# a mutation is just an edit + re-run. Case 4 stubs two things and nothing else: SQL(),
# and the tool at the modSync path -- because the real modSync.py calls get_settings()
# (MariaDB) before it ever reaches the lock, and case 4's assertion is about WHICH UID
# the boot path launches, not about what modSync does afterwards. The real end-to-end
# boot path is checked against a live RC container separately.
#
# Usage: dev_tools/test-modsync-lock-crossuser.sh [image]
set -uo pipefail
IMAGE="${1:-theoriginalbrian/phvalheim-server:2.51}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "SKIP: image $IMAGE not present locally. Pass one as \$1."
    exit 0
fi

INNER=$(mktemp /tmp/crossuser-inner.XXXXXX.sh)
trap 'rm -f "$INNER"' EXIT
cat > "$INNER" <<'INNER_EOF'
set -u
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  PASS  $1"; }
no() { fail=$((fail+1)); echo "  FAIL  $1${2:+ -- $2}"; }

TOOLS=/opt/stateless/engine/tools
# open_lock() is the function under test; take() re-implements nothing, it calls it.
take() {  # take <asuser> <path> -- prints TAKEN if the lock was taken
    local asuser="$1" path="$2"
    # Via a FILE, not nested -c quoting: an earlier revision of this helper lost the
    # python to quote mangling and reported a clean path as a failure, which looks
    # exactly like the product bug it is meant to detect.
    local pyf=/tmp/take.$$.py
    cat > "$pyf" <<PY
import sys; sys.path.insert(0, "$TOOLS")
import fcntl, modSync
fh = modSync.open_lock("$path")
fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
print("TAKEN")
PY
    chmod 0644 "$pyf"
    if [ "$asuser" = root ]; then python3 "$pyf"
    else su "$asuser" -s /bin/sh -c "python3 $pyf"
    fi
    local rc=$?
    rm -f "$pyf"
    return $rc
}

echo
echo "=== 1. root creates the lock, phvalheim must still take it (the reported bug) ==="
rm -f /tmp/xu1.lock
out=$(take root /tmp/xu1.lock 2>&1 | tail -1)
[ "$out" = TAKEN ] || no "root could not create its own lock" "$out"
ls -l /tmp/xu1.lock | awk '{print "        created as: " $3 ":" $4 " " $1}'
out=$(take phvalheim /tmp/xu1.lock 2>&1 | tail -1)
if [ "$out" = TAKEN ]; then ok "phvalheim took a root-created lock"
else no "phvalheim could NOT take a root-created lock" "$out"; fi

echo
echo "=== 2. phvalheim creates the lock, root must still take it (reverse order) ==="
rm -f /tmp/xu2.lock
out=$(take phvalheim /tmp/xu2.lock 2>&1 | tail -1)
[ "$out" = TAKEN ] || no "phvalheim could not create its own lock" "$out"
out=$(take root /tmp/xu2.lock 2>&1 | tail -1)
if [ "$out" = TAKEN ]; then ok "root took a phvalheim-created lock"
else no "root could NOT take a phvalheim-created lock" "$out"; fi

echo
echo "=== 3. CONTROL: the lock must still be exclusive ==="
rm -f /tmp/xu3.lock
out=$(su phvalheim -s /bin/sh -c "python3 -c \"
import sys, subprocess; sys.path.insert(0, '$TOOLS')
import fcntl, modSync
fh = modSync.open_lock('/tmp/xu3.lock')
fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)      # holder keeps this alive
r = subprocess.run(['python3','-c','''
import sys; sys.path.insert(0, \\\"$TOOLS\\\")
import fcntl, modSync
fh = modSync.open_lock(\\\"/tmp/xu3.lock\\\")
try:
    fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    print(\\\"GOT-IT\\\")
except OSError:
    print(\\\"BLOCKED\\\")
'''], capture_output=True, text=True)
print((r.stdout or r.stderr).strip().splitlines()[-1])
\"" 2>&1 | tail -1)
if [ "$out" = BLOCKED ]; then ok "a second holder was refused while the lock was held"
elif [ "$out" = GOT-IT ]; then no "THE LOCK IS NOT A LOCK — two holders at once"
else no "exclusion control did not run" "$out"; fi

echo
echo "=== 4. boot path must launch the sync as phvalheim, not root ==="
rm -f /tmp/phvalheim-modsync.run.lock /tmp/phvalheim-modsync.wait.lock
mkdir -p /opt/stateful/logs
# Stub standing in for modSync.py: creates both locks the way open_lock() does, so the
# only thing this case measures is which uid the launch line in 0-functions.sh used.
cat > "$TOOLS/modSync.py" <<'STUB'
#!/usr/bin/env python3
import os
for p in ("/tmp/phvalheim-modsync.run.lock", "/tmp/phvalheim-modsync.wait.lock"):
    os.close(os.open(p, os.O_RDONLY | os.O_CREAT, 0o666))
STUB
chmod 0755 "$TOOLS/modSync.py"
SQL() { echo 0; }                         # the modCount probe; nothing else is stubbed
# shellcheck disable=SC1091
source /opt/stateless/engine/includes/0-functions.sh >/dev/null 2>&1
syncModCatalogue >/dev/null 2>&1
for i in $(seq 1 40); do
    [ -e /tmp/phvalheim-modsync.run.lock ] && break
    sleep 0.25
done
if [ ! -e /tmp/phvalheim-modsync.run.lock ]; then
    no "boot path never created a lock file (did the launch line fail outright?)"
else
    owner=$(stat -c %U /tmp/phvalheim-modsync.run.lock)
    mode=$(stat -c %a /tmp/phvalheim-modsync.run.lock)
    if [ "$owner" = phvalheim ]; then ok "boot sync ran as phvalheim (lock $owner $mode)"
    else no "boot sync ran as $owner — the uid split is back" "lock owned by $owner $mode"; fi
fi

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
INNER_EOF

echo "=== mod-sync cross-user lock ($IMAGE) ==="
docker run --rm \
    -v "$REPO/container/engine/tools/modSync.py:/opt/stateless/engine/tools/modSync.py.src:ro" \
    -v "$REPO/container/engine/includes/0-functions.sh:/opt/stateless/engine/includes/0-functions.sh:ro" \
    -v "$INNER:/inner.sh:ro" \
    --entrypoint /bin/bash "$IMAGE" -c \
    'cp /opt/stateless/engine/tools/modSync.py.src /opt/stateless/engine/tools/modSync.py &&
     chmod 0755 /opt/stateless/engine/tools/modSync.py && bash /inner.sh'
