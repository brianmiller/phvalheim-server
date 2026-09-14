#!/bin/bash
# Hugin telemetry: the counters are real, and they carry nothing private.
#
# The privacy bar is the point. This repo is public, the operators are self-hosters, and
# the existing rule already says a provider's base URL is "an internal hostname and none of
# our business". Usage counters must clear the same bar, and the only way to know is to
# build a real payload on a server that has actually used Hugin and then grep it for things
# that must never be in it.
#
# Runs the WHOLE pusher, not a reimplementation of it: a test that builds its own payload
# proves only that the test is careful.

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
NAME="phv-telemetry-test-$$"

DATA="$(TMPDIR=/var/tmp mktemp -d)"; chmod 755 "$DATA"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

cleanup() {
	docker rm -f "$NAME" >/dev/null 2>&1
	docker run --rm -v "$DATA:/d" --entrypoint sh alpine -c 'rm -rf /d/..?* /d/.[!.]* /d/*' >/dev/null 2>&1
	rmdir "$DATA" 2>/dev/null || \
		docker run --rm -v /var/tmp:/vt --entrypoint sh alpine -c "rm -rf '/vt/$(basename "$DATA")'" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM

# --network none is NOT belt-and-braces; it is the whole safety property.
#
# `--disabled` does NOT mean "build the payload but do not send it". It means "send one
# final payload saying analytics is now off", and it POSTs to the real
# https://analytics.phvalheim.com/api/ingest. An earlier version of this test used it
# expecting a dry run and registered several fake installations, with fake UUIDs, in the
# production analytics database.
#
# There is no dry-run flag and the URL is hardcoded, so the only reliable way to make a
# test that exercises the real pusher is to put it somewhere it cannot reach a network.
# Everything the script needs -- mysql, jq -- is local to the container.
echo "Booting $IMAGE (no network at all)..."
docker run -d --name "$NAME" --network none -v "$DATA:/opt/stateful" "$IMAGE" >/dev/null || exit 1
sql() { docker exec "$NAME" mysql --skip-column-names -uroot --database=phvalheim -e "$1" 2>/dev/null; }

for i in $(seq 1 60); do
	sql "DESCRIBE ai_providers" >/dev/null 2>&1 && sql "SELECT vanilla FROM worlds LIMIT 0" >/dev/null 2>&1 && break
	sleep 3
done

docker cp "$ROOT/container/engine/dbUpdates/dbUpdate_2.45.sh" "$NAME:/opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh" >/dev/null
docker cp "$ROOT/container/engine/tools/pushAnalytics.sh"     "$NAME:/opt/stateless/engine/tools/pushAnalytics.sh"   >/dev/null
docker exec "$NAME" bash /opt/stateless/engine/dbUpdates/dbUpdate_2.45.sh >/dev/null 2>&1

# A server that has genuinely used Hugin: named worlds, a real provider, and counters that
# mention tools and error classes.
SECRET_WORLD="Ravenscroft"
SECRET_MODEL="acme-internal-llm-v9"
SECRET_HOST="llm.internal.example.lan"
SECRET_KEY="sk-supersecret-000"

sql "DELETE FROM worlds; INSERT INTO worlds (name, status, mode, port, seed, vanilla)
     VALUES ('$SECRET_WORLD','running','',25000,'s',0);"
sql "DELETE FROM ai_providers;
     INSERT INTO ai_providers (kind,label,base_url,api_key,model,enabled,tool_capability)
     VALUES ('openai_compatible','My Gateway','https://$SECRET_HOST/v1','$SECRET_KEY','$SECRET_MODEL',1,'tools');"
sql "DELETE FROM ai_usage;
     INSERT INTO ai_usage (metric,subkey,day,count) VALUES
       ('chats','',CURDATE(),7),
       ('tool','get_diagnostics',CURDATE(),11),
       ('tool','stop_world',CURDATE(),2),
       ('action_proposed','stop_world',CURDATE(),2),
       ('action_applied','stop_world',CURDATE(),1),
       ('action_rejected','set_world_access',CURDATE(),3),
       ('action_dismissed','',CURDATE(),1),
       ('error','http_400',CURDATE(),4),
       ('rounds','3',CURDATE(),5);"

echo "Building a real payload..."

# The script deletes its payload file on the way out, so keep a copy. This is the ONLY
# change made to it: the builder under test is otherwise the shipped one, because a test
# that reimplements the payload proves only that the test is careful.
docker exec "$NAME" sed -i 's|^rm -f /tmp/phvalheim_analytics.tmp "$payload_file"|cp -f "$payload_file" /tmp/kept.json 2>/dev/null; &|' \
	/opt/stateless/engine/tools/pushAnalytics.sh

runPush() {
	docker exec "$NAME" rm -f /tmp/kept.json >/dev/null 2>&1
	docker exec "$NAME" bash /opt/stateless/engine/tools/pushAnalytics.sh --disabled >/dev/null 2>&1
	docker exec "$NAME" cat /tmp/kept.json 2>/dev/null
}
payload=$(runPush)

printf '\n\033[1mHugin telemetry\033[0m\n'

# Assert the isolation rather than trusting the flag. If this container CAN reach the
# internet, every run below is publishing test data.
if docker exec "$NAME" getent hosts analytics.phvalheim.com >/dev/null 2>&1; then
	bad "the test container can resolve the analytics host — it is NOT isolated, refusing to continue"
	printf '\n\033[31m%s failure(s)\033[0m\n' "$FAIL"; exit 1
else
	ok "the container has no network, so nothing can reach the live analytics service"
fi

if [ -z "$payload" ]; then
	bad "no payload was produced at all"
	printf '\n\033[31m%s failure(s)\033[0m\n' "$FAIL"; exit 1
fi

echo "$payload" | jq . >/dev/null 2>&1 \
  && ok "the payload is valid JSON ($(echo -n "$payload" | wc -c) bytes)" \
  || bad "payload is not valid JSON: $(echo "$payload" | head -c 200)"

# --- the counters are actually there and correct --------------------------------------
for pair in "ai_chats:7" "ai_tool_calls:13" "ai_actions_proposed:2" "ai_actions_applied:1" \
            "ai_actions_rejected:3" "ai_actions_dismissed:1"; do
	k="${pair%%:*}"; want="${pair##*:}"
	got=$(echo "$payload" | jq -r ".$k // \"missing\"")
	[ "$got" = "$want" ] && ok "  $k = $got" || bad "  $k is '$got', expected $want"
done

got=$(echo "$payload" | jq -r '.ai_tools_used.get_diagnostics // "missing"')
[ "$got" = 11 ] && ok "  per-tool tallies survive (get_diagnostics = 11)" || bad "  ai_tools_used wrong: $got"

got=$(echo "$payload" | jq -r '.ai_errors.http_400 // "missing"')
[ "$got" = 4 ] && ok "  error CLASSES are counted (http_400 = 4)" || bad "  ai_errors wrong: $got"

got=$(echo "$payload" | jq -r '.ai_capability.tools // "missing"')
[ "$got" = 1 ] && ok "  capability is reported (tools = 1)" || bad "  ai_capability wrong: $got"

# --- THE PRIVACY BAR -------------------------------------------------------------------
#
# Everything below is present on the server and must NOT be in what leaves it. The world
# name is the interesting one: it is in the payload legitimately, under `worlds`, because
# that predates this work -- so the check is scoped to the ai_* fields.
ai_only=$(echo "$payload" | jq -c 'with_entries(select(.key | startswith("ai_")))')

leaks=""
for secret in "$SECRET_MODEL" "$SECRET_HOST" "$SECRET_KEY" "My Gateway" "$SECRET_WORLD"; do
	case "$ai_only" in *"$secret"*) leaks="$leaks '$secret'" ;; esac
done
[ -z "$leaks" ] \
  && ok "no model id, endpoint, key, label or world name in any ai_* field" \
  || bad "TELEMETRY LEAK in ai_* fields:$leaks"

# The provider KIND is the one thing about a provider we do send, and that is deliberate.
echo "$payload" | jq -e '.ai_providers | index("openai_compatible")' >/dev/null 2>&1 \
  && ok "the provider KIND is still reported (deliberate, and the only provider detail sent)" \
  || bad "provider kind went missing"

# --- a server that has never used Hugin still sends valid JSON -------------------------
sql "DELETE FROM ai_usage; DELETE FROM ai_providers;"
empty=$(runPush)
if echo "$empty" | jq -e '.ai_chats == 0 and (.ai_tools_used | type) == "object"' >/dev/null 2>&1; then
	ok "an installation that never used Hugin still sends well-formed zeros, not empty fields"
else
	bad "unused install produced a malformed payload: $(echo "$empty" | jq -c '{ai_chats,ai_tools_used}' 2>&1 | head -c 120)"
fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mTelemetry is accurate and carries nothing private\033[0m (%s checks)\n' "$PASS"; exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
