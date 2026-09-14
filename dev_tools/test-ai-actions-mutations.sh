#!/bin/bash
# Mutation proof for the action guards.
#
# test-ai-actions.sh going green proves the happy paths work. It does NOT prove the guards
# are load-bearing -- a check that passes whether or not the guard is present is worth
# nothing. So: break one guard at a time, and require the suite to notice.
#
# A mutation that leaves the suite green is a finding, not a pass: it means either the test
# cannot see that guard, or the guard was never doing anything.
#
# Boots ONE container and re-runs the PHP suite per mutation, rather than a container per
# mutation -- same evidence, a tenth of the wall clock.

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
NAME="phv-mutate-test-$$"
SRC="$ROOT/container/nginx/www/includes/aiactions.php"

DATA="$(TMPDIR=/var/tmp mktemp -d)"; chmod 755 "$DATA"
WORK="$(TMPDIR=/var/tmp mktemp -d)"

cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1
	docker run --rm -v "$DATA:/d" --entrypoint sh alpine -c 'rm -rf /d/..?* /d/.[!.]* /d/*' >/dev/null 2>&1
	rmdir "$DATA" 2>/dev/null || docker run --rm -v /var/tmp:/vt --entrypoint sh alpine -c "rm -rf '/vt/$(basename "$DATA")'" >/dev/null 2>&1; rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

echo "Booting $IMAGE..."
docker run -d --name "$NAME" -v "$DATA:/opt/stateful" "$IMAGE" >/dev/null || exit 1

for i in $(seq 1 60); do
	docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	  -e "DESCRIBE ai_providers" >/dev/null 2>&1 && \
	docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim \
	  -e "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1 && break
	sleep 3
done

docker cp "$ROOT/container/nginx/www/includes/aicontext.php" "$NAME:/opt/stateless/nginx/www/includes/aicontext.php" >/dev/null
docker cp "$ROOT/container/nginx/www/admin/adminAPI.php"     "$NAME:/opt/stateless/nginx/www/admin/adminAPI.php"     >/dev/null
docker cp "$ROOT/container/engine/dbUpdates/dbUpdate_2.45.sh" "$NAME:/opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh" >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1
docker cp "$HERE/ai-e2e/actions.php" "$NAME:/tmp/actions.php" >/dev/null

# Each mutation: a label, one or two replacements, and the check that MUST go red.
#
# MODE=depth inverts the expectation: the suite is required to stay GREEN, because a second
# independent guard still covers the case. That is worth asserting explicitly -- it is the
# difference between "defence in depth" and "this test cannot see the guard at all", and
# they look identical unless you say which one you meant.
run_mutation() {
	label="$1"; find="$2"; repl="$3"; expect="$4"; find2="$5"; repl2="$6"; mode="${MODE:-caught}"

	python3 - "$SRC" "$WORK/mutated.php" "$find" "$repl" "${find2:-}" "${repl2:-}" <<'PY'
import sys
src, dst, find, repl = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
find2, repl2 = sys.argv[5], sys.argv[6]
s = open(src).read()
for f, r in [(find, repl)] + ([(find2, repl2)] if find2 else []):
    if f not in s:
        sys.stderr.write("MUTATION TARGET NOT FOUND: %r\n" % f[:70]); sys.exit(3)
    s = s.replace(f, r, 1)
open(dst, 'w').write(s)
PY
	if [ $? != 0 ]; then
		printf '  \033[31mBROKEN\033[0m  %-46s (mutation target no longer in the source)\n' "$label"
		BROKEN=$((BROKEN+1)); return
	fi

	docker cp "$WORK/mutated.php" "$NAME:/opt/stateless/nginx/www/includes/aiactions.php" >/dev/null
	out=$(docker exec "$NAME" php /tmp/actions.php 2>&1)

	hit=1; echo "$out" | grep -qF "$expect" || hit=0

	if [ "$mode" = depth ]; then
		if [ "$hit" = 0 ]; then
			printf '  \033[36mDEPTH \033[0m  %-46s (still refused by the second guard)\n' "$label"
			CAUGHT=$((CAUGHT+1))
		else
			printf '  \033[31mTHIN  \033[0m  %-46s (the second guard did NOT hold)\n' "$label"
			MISSED=$((MISSED+1))
		fi
		return
	fi

	if [ "$hit" = 1 ]; then
		printf '  \033[32mCAUGHT\033[0m  %-46s -> "%s"\n' "$label" "$(echo "$out" | grep -F "$expect" | head -1 | sed 's/.*FAIL[^ ]*  //' | cut -c1-60)"
		CAUGHT=$((CAUGHT+1))
	else
		printf '  \033[31mMISSED\033[0m  %-46s (suite stayed green -- the guard is not covered)\n' "$label"
		MISSED=$((MISSED+1))
	fi
}

CAUGHT=0; MISSED=0; BROKEN=0

# Baseline: unmutated source must be green, or every result below is meaningless.
docker cp "$SRC" "$NAME:/opt/stateless/nginx/www/includes/aiactions.php" >/dev/null
baseline=$(docker exec "$NAME" php /tmp/actions.php 2>&1)
if [ $? = 0 ]; then
	echo "Baseline: clean source passes."
else
	echo "REFUSING: the unmutated suite does not pass, so nothing below would mean anything."
	echo "$baseline" | grep -E 'FAIL|Fatal|error' | head -10 | sed 's/^/    /'
	exit 1
fi

printf '\n\033[1mBreaking one guard at a time\033[0m\n'

# Replay is guarded twice: a status check, and an atomic claim that only succeeds while the
# row is still pending. Breaking either alone must NOT get a replay through.
MODE=depth run_mutation "replay: status check removed, claim intact" \
  "if (\$row['status']  !== 'pending')      return ['success' => false, 'error' => 'That change has already been dealt with.'];" \
  "" \
  "TOKEN REPLAYED"

MODE=depth run_mutation "replay: claim weakened, status check intact" \
  "WHERE id=? AND status='pending'\"" \
  "WHERE id=?\"" \
  "TOKEN REPLAYED"

# Both gone: now the suite MUST notice.
run_mutation "replay: both guards removed" \
  "if (\$row['status']  !== 'pending')      return ['success' => false, 'error' => 'That change has already been dealt with.'];" \
  "" \
  "TOKEN REPLAYED" \
  "WHERE id=? AND status='pending'\"" \
  "WHERE id=?\""

run_mutation "proposal expiry" \
  "if (strtotime(\$row['expires_at']) < time()) {" \
  "if (false) {" \
  "expired proposal still applied"

run_mutation "typed-name confirmation on delete" \
  "if (\$row['typed_name'] !== '' && trim((string)\$typedName) !== \$row['typed_name']) {" \
  "if (false) {" \
  "DELETED WITHOUT CONFIRMATION"

run_mutation "re-validation at apply time" \
  "if (isset(\$fresh['error'])) {" \
  "if (false) {" \
  "stale proposal applied anyway"

run_mutation "full-row prefill (partial write wipes fields)" \
  "'password'       => (string)(\$p['row']['password'] ?? '')," \
  "'password'       => ''," \
  "THE PASSWORD WAS WIPED"

run_mutation "vanilla-only settings on a modded world" \
  "if (!\$vanilla) {
                \$blocked" \
  "if (false) {
                \$blocked" \
  "proposed a change that would silently do nothing"

run_mutation "empty enforced access list" \
  "if (\$ids === '') {" \
  "if (false) {" \
  "EMPTY ENFORCED LIST NOT CAUGHT"

run_mutation "unknown world rejection" \
  "if (!\$row) {" \
  "if (false && !\$row) {" \
  "hallucinated world name was accepted"

run_mutation "listed vanilla world needs a password" \
  "if (\$vanilla && \$listed && trim((string)\$password) === '') {" \
  "if (false) {" \
  "listed-without-password trap was not caught"

run_mutation "token stays out of the model's view" \
  "'summary'  => \$summary,
        'note'" \
  "'summary'  => \$summary,
        'token' => \$token,
        'note'" \
  "TOKEN LEAKED"

# Restore, so a killed run cannot leave a mutated file staged.
docker cp "$SRC" "$NAME:/opt/stateless/nginx/www/includes/aiactions.php" >/dev/null

printf '\n'
printf 'caught %d   missed %d   broken %d\n' "$CAUGHT" "$MISSED" "$BROKEN"
if [ "$MISSED" = 0 ] && [ "$BROKEN" = 0 ]; then
	printf '\033[32mEVERY GUARD IS LOAD-BEARING\033[0m\n'; exit 0
fi
printf '\033[31mSome guards are not covered by the suite\033[0m\n'
exit 1
