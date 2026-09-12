#!/bin/bash
# Regression test: dedicated backup volume detection (issue: Unraid false negative)
#
# The scheduler used to decide "is there a dedicated backup volume?" by comparing
# df's source column for $backupDir and /opt/stateful. That is device identity,
# and device identity cannot see a bind mount: on Unraid every /mnt/user share is
# the same FUSE 'shfs' device, so a correctly mounted 11T backup share compared
# equal to the 932G appdata share and automatic backups silently disabled
# themselves — while the admin UI (which asks mountpoint) showed "dedicated volume".
#
# The failing condition is reproduced exactly: a real bind mount whose df source
# string is IDENTICAL to its parent's. The old check calls that shared; the new
# check must call it dedicated.
#
# Run: ./dev_tools/test-backup-volume-detection.sh    (no root needed, uses unshare -r)

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF="$REPO/container/engine/includes/phvalheim-static.conf"
WORLDBACKUP="$REPO/container/engine/tools/worldBackup"
PHPGETS="$REPO/container/nginx/www/includes/db_gets.php"

PASS=0
FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

# --- Phase 2: runs inside the mount namespace ----------------------------------
if [ "$1" = "--inner" ]; then
	# shellcheck disable=SC1090
	source "$CONF"

	T=$(mktemp -d)
	mkdir -p "$T/stateful/backups" "$T/src"

	# NEGATIVE: plain directory, nothing mounted -> not dedicated
	if isBackupPathMounted "$T/stateful/backups"; then
		no "plain directory must NOT count as a dedicated volume"
	else
		ok "plain directory is not a dedicated volume"
	fi

	# Build the Unraid-shaped condition: a real bind mount on the same device.
	if ! mount --bind "$T/src" "$T/stateful/backups" 2>/dev/null; then
		echo "  SKIP  cannot bind mount in this namespace"
		exit 99
	fi

	# Guard: the test only means something if df really does report the same
	# source for both paths, i.e. we actually reproduced the Unraid condition.
	dfBackups=$(df "$T/stateful/backups" 2>/dev/null | tail -1 | tr -s ' ' | cut -d' ' -f1)
	dfStateful=$(df "$T/stateful" 2>/dev/null | tail -1 | tr -s ' ' | cut -d' ' -f1)
	if [ "$dfBackups" != "$dfStateful" ]; then
		echo "  SKIP  could not reproduce same-device condition (df: $dfBackups vs $dfStateful)"
		exit 99
	fi
	ok "reproduced the failing condition (both paths report df source '$dfBackups')"

	# THE ORACLE: old logic says shared, new logic must say dedicated.
	if isBackupPathMounted "$T/stateful/backups"; then
		ok "bind-mounted backup dir IS a dedicated volume despite identical df source"
	else
		no "bind-mounted backup dir reported as shared — the Unraid bug is back"
	fi

	# mountpoint-less fallback must reach the same verdict via /proc/self/mountinfo.
	# Hide mountpoint by running with a PATH that holds only awk.
	mkdir -p "$T/bin"
	ln -sf "$(command -v awk)" "$T/bin/awk"
	PATH="$T/bin" "$BASH" -c "source '$CONF'; isBackupPathMounted '$T/stateful/backups'" 2>/dev/null
	fallback=$?
	if [ "$fallback" = "0" ]; then
		ok "mountinfo fallback agrees when mountpoint is unavailable"
	else
		no "mountinfo fallback disagrees with mountpoint (exit $fallback)"
	fi

	echo "INNER:$PASS:$FAIL"
	[ "$FAIL" -eq 0 ]
	exit $?
fi

# --- Phase 1: source guards, then re-exec into a namespace ---------------------
echo "Backup volume detection"

# The scheduler must not gate backups on device identity ever again.
if grep -qE 'df .*(backupDir|/opt/stateful).*cut -d' "$WORLDBACKUP"; then
	no "worldBackup still compares df device strings to gate automatic backups"
else
	ok "worldBackup does not gate backups on df device identity"
fi

if grep -q 'isBackupPathMounted' "$WORLDBACKUP"; then
	ok "worldBackup uses the shared isBackupPathMounted oracle"
else
	no "worldBackup does not use the shared isBackupPathMounted oracle"
fi

# Both languages must ask the mount table, so UI and scheduler cannot disagree.
if grep -q 'mountpoint -q' "$PHPGETS" && grep -q 'mountinfo' "$PHPGETS"; then
	ok "PHP isBackupPathMounted asks the mount table (mountpoint + mountinfo)"
else
	no "PHP isBackupPathMounted no longer asks the mount table"
fi

if grep -q 'mountpoint -q' "$CONF" && grep -q 'mountinfo' "$CONF"; then
	ok "bash isBackupPathMounted asks the mount table (mountpoint + mountinfo)"
else
	no "bash isBackupPathMounted no longer asks the mount table"
fi

# Functional half needs a private mount namespace.
if ! unshare -rm --propagation private true 2>/dev/null; then
	echo "  SKIP  unshare unavailable — functional bind-mount checks skipped"
else
	out=$(unshare -rm --propagation private "$BASH" "${BASH_SOURCE[0]}" --inner 2>&1)
	rc=$?
	echo "$out" | grep -vE '^INNER:'
	if [ $rc -eq 99 ]; then
		:
	else
		inner=$(echo "$out" | grep '^INNER:')
		PASS=$((PASS + $(echo "$inner" | cut -d: -f2)))
		FAIL=$((FAIL + $(echo "$inner" | cut -d: -f3)))
	fi
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
