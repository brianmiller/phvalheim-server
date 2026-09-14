#!/bin/bash
# A no-argument tool call must replay as {}, never [].
#
# Found through a LiteLLM gateway. Every question died immediately AFTER the tools ran:
#
#   litellm.InternalServerError: OpenAIException - 'list' object has no attribute 'items'
#
# `arguments` is a JSON string that the spec says decodes to an OBJECT. Our no-arg tool
# calls carried an empty PHP array, and json_encode([]) is "[]" -- a list. LiteLLM decodes
# it and calls .items(), which only a dict has. api.openai.com happens to tolerate "[]",
# which is exactly why this survived every test against OpenAI directly and only appeared
# through a gateway: the lenient endpoint hid a spec violation the strict one enforces.
#
# The stub below is deliberately strict in the same way, so this fails loudly on a
# regression instead of waiting for someone to point a gateway at it.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [ -z "$PHV_IN_CONTAINER" ] && ! php -m 2>/dev/null | grep -qi '^curl$'; then
	IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
	echo "  (host PHP has no curl — re-running inside $IMAGE)"
	exec docker run --rm -e PHV_IN_CONTAINER=1 -v "$ROOT:$ROOT" -w "$ROOT" \
		--entrypoint bash "$IMAGE" dev_tools/test-ai-toolargs.sh
fi

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

WORK="$(mktemp -d)"
PORT=18799
trap 'kill %1 2>/dev/null; rm -rf "$WORK"' EXIT

# Turn 1: answer with a tool call that takes NO arguments.
# Turn 2: the replay arrives -- validate `arguments` the way a strict gateway does.
cat > "$WORK/stub.php" <<'PHPEOF'
<?php
$body = json_decode(file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');

$replayed = null;
foreach (($body['messages'] ?? []) as $m) {
    foreach (($m['tool_calls'] ?? []) as $tc) {
        $replayed = $tc['function']['arguments'] ?? null;
    }
}

if ($replayed === null) {
    // First call: ask for a no-argument tool.
    echo json_encode(['model' => 'stub', 'choices' => [['message' => [
        'role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'get_system_health', 'arguments' => '{}']]],
    ], 'finish_reason' => 'tool_calls']]]);
    exit;
}

file_put_contents(__DIR__ . '/replayed', $replayed);

// The strict check, mirroring what LiteLLM does: decode, then require a dict.
$decoded = json_decode($replayed, true);
if (!is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))
    || ($replayed !== '{}' && $decoded === [])) {
    http_response_code(500);
    echo json_encode(['error' => ['message' =>
        "InternalServerError: OpenAIException - 'list' object has no attribute 'items'"]]);
    exit;
}
echo json_encode(['model' => 'stub', 'choices' => [['message' =>
    ['role' => 'assistant', 'content' => 'All good.'], 'finish_reason' => 'stop']]]);
PHPEOF

php -S "127.0.0.1:$PORT" "$WORK/stub.php" >/dev/null 2>&1 &
for i in $(seq 1 40); do
	php -r "exit(@fsockopen('127.0.0.1', $PORT) ? 0 : 1);" && break
	sleep 0.25
done

printf '\n\033[1mReplaying a no-argument tool call through a strict gateway\033[0m\n'
out="$(php -r "
	define('AI_LOG_DIR', '$WORK');
	require '$ROOT/container/nginx/www/includes/aiproviders.php';
	\$p = ['kind' => 'openai_compatible', 'base_url' => 'http://127.0.0.1:$PORT',
	       'api_key' => 'k', 'model' => 'stub', 'headers' => []];
	// The exact shape aiConverse replays: an assistant turn holding a tool call with NO
	// arguments, followed by its result.
	\$msgs = [
		['role' => 'user', 'content' => 'how is the host?'],
		['role' => 'assistant', 'content' => '',
		 'tool_calls' => [['id' => 'call_1', 'name' => 'get_system_health', 'arguments' => []]]],
		['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{\"load\":1}'],
	];
	\$r = aiChat(\$p, \$msgs, 'sys',
		[['name' => 'get_system_health', 'description' => 'd',
		  'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]],
		null, 64);
	echo (\$r['success'] ? 'OK: ' . \$r['content'] : 'ERR: ' . \$r['error']);
" 2>&1)"

sent="$(cat "$WORK/replayed" 2>/dev/null)"
printf '  arguments sent on the wire: %s\n  result: %s\n' "${sent:-<none>}" "$out"

if [ "$sent" = "{}" ]; then
	ok "an empty argument set is replayed as {} (a JSON object)"
else
	bad "replayed as '$sent' — the spec requires an object, and a strict gateway rejects a list"
fi
case "$out" in
	OK*) ok "the gateway accepted the replay and the conversation continued" ;;
	*)   bad "still failing: $out" ;;
esac

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mTool-argument shape OK\033[0m (%s checks)\n' "$PASS"
	exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
