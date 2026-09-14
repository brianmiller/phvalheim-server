#!/bin/bash
# OpenAI's newer models reject `max_tokens` and demand `max_completion_tokens`.
#
# Found on a live account: adding an OpenAI key passed discovery (130 models), passed model
# selection, then failed the chat round trip with
#
#   Unsupported parameter: 'max_tokens' is not supported with this model.
#   Use 'max_completion_tokens' instead.
#
# Which spelling a model wants is not in /models, and we are never allowed to keep a list of
# model ids (that IS issue #83), so the adapter asks one way and retries the other way on
# that specific refusal. This proves the retry happens when needed AND does not happen when
# it is not -- a retry that always fires would double every request on every provider.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# aiHttp() is curl-based and dev1's CLI PHP ships without the curl extension, so running
# this on the host fataled on curl_init() and every assertion failed for a reason unrelated
# to the code under test. Re-exec inside the image instead: it has php-curl, and testing the
# transport against the PHP that actually ships is the more honest test anyway. Both the
# stub server and the client run inside, so no host networking is involved.
if [ -z "$PHV_IN_CONTAINER" ] && ! php -m 2>/dev/null | grep -qi '^curl$'; then
	IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
	echo "  (host PHP has no curl — re-running inside $IMAGE)"
	exec docker run --rm -e PHV_IN_CONTAINER=1 -v "$ROOT:$ROOT" -w "$ROOT" \
		--entrypoint bash "$IMAGE" dev_tools/test-ai-max-tokens.sh
fi

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

WORK="$(mktemp -d)"
PORT=18797
trap 'kill %1 2>/dev/null; rm -rf "$WORK"' EXIT

# A stub OpenAI-compatible endpoint. MODE=strict rejects max_tokens the way the new models
# do; MODE=legacy accepts it the way the old ones do. Every request is appended to a log so
# the test can count calls and see which parameter each one carried.
cat > "$WORK/stub.php" <<'PHPEOF'
<?php
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$mode = trim(@file_get_contents(__DIR__ . '/mode') ?: 'strict');
$has  = isset($body['max_tokens']) ? 'max_tokens' : (isset($body['max_completion_tokens']) ? 'max_completion_tokens' : 'none');
$re   = array_key_exists('reasoning_effort', $body) ? $body['reasoning_effort'] : '-';
file_put_contents(__DIR__ . '/calls', $has . '/' . $re . "\n", FILE_APPEND);

header('Content-Type: application/json');

// Refuses function tools unless reasoning_effort is explicitly 'none'. Verbatim wording
// from a live gpt-5.6 account.
if (in_array($mode, ['reasoning', 'both'], true) && !empty($body['tools']) && $re !== 'none') {
    http_response_code(400);
    echo json_encode(['error' => [
        'message' => "Function tools with reasoning_effort are not supported for stub-model in /v1/chat/completions. To use function tools, use /v1/responses or set reasoning_effort to 'none'.",
        'type'    => 'invalid_request_error',
        'param'   => 'reasoning_effort',
    ]]);
    exit;
}
if ($mode === 'always400' || (in_array($mode, ['strict', 'both'], true) && $has === 'max_tokens')) {
    http_response_code(400);
    echo json_encode(['error' => [
        'message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
        'type'    => 'invalid_request_error',
        'param'   => 'max_tokens',
        'code'    => 'unsupported_parameter',
    ]]);
    exit;
}
echo json_encode([
    'model'   => 'stub-model',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
    'usage'   => ['total_tokens' => 5],
]);
PHPEOF

php -S "127.0.0.1:$PORT" "$WORK/stub.php" >/dev/null 2>&1 &
for i in $(seq 1 40); do
	php -r "exit(@fsockopen('127.0.0.1', $PORT) ? 0 : 1);" && break
	sleep 0.25
done

run_chat() {   # $1 = stub mode, $2 = "stream" for the SSE path, $3 = "tools" to send function tools
	printf '%s' "$1" > "$WORK/mode"
	: > "$WORK/calls"
	local delta='null'
	# Plain $t, not \$t: the value of $delta is substituted into the php -r string but is
	# NOT re-scanned for escapes, so a backslash here reaches PHP verbatim and is a parse error.
	[ "$2" = "stream" ] && delta='function ($t) { }'
	# The reasoning_effort refusal only happens when the request carries function tools, so a
	# tool-less probe can never reach it. That is precisely why the wizard's probe reported
	# "Provider test: OK" one line above a failing chat in ai.log -- the probe sends no tools.
	local tools='[]'
	[ "$3" = "tools" ] && tools='[["name" => "t", "description" => "d", "parameters" => ["type" => "object", "properties" => (object)[]]]]'
	php -r "
		define('AI_LOG_DIR', '$WORK');
		require '$ROOT/container/nginx/www/includes/aiproviders.php';
		\$p = ['kind' => 'openai_compatible', 'base_url' => 'http://127.0.0.1:$PORT',
		       'api_key' => 'k', 'model' => 'stub-model', 'headers' => []];
		\$r = aiChat(\$p, [['role' => 'user', 'content' => 'hi']], 'sys', $tools, $delta, 64);
		echo (\$r['success'] ? 'OK' : 'ERR:' . \$r['error']);
	" 2>&1
}

printf '\n\033[1mAn endpoint that rejects max_tokens\033[0m\n'
out="$(run_chat strict)"
calls="$(tr '\n' ' ' < "$WORK/calls" | sed 's/ *$//')"
printf '  result: %s\n  parameters sent, in order: %s\n' "$out" "$calls"

case "$out" in
	OK*) ok "the call succeeded despite the first attempt being refused" ;;
	*)   bad "still failing: $out" ;;
esac
if [ "$calls" = "max_tokens/- max_completion_tokens/-" ]; then
	ok "asked with max_tokens, then retried with max_completion_tokens"
else
	bad "wrong call sequence: [$calls]"
fi

printf '\n\033[1mAn endpoint that accepts max_tokens (negative control)\033[0m\n'
out="$(run_chat legacy)"
calls="$(tr '\n' ' ' < "$WORK/calls" | sed 's/ *$//')"
printf '  result: %s\n  parameters sent, in order: %s\n' "$out" "$calls"

case "$out" in
	OK*) ok "succeeds on the first attempt" ;;
	*)   bad "legacy endpoint broke: $out" ;;
esac
if [ "$calls" = "max_tokens/-" ]; then
	ok "exactly ONE request — the retry did not fire when it was not needed"
else
	bad "a needless retry doubles every request: [$calls]"
fi

printf '\n\033[1mThe SAME refusal, on the streaming path\033[0m\n'
# This is where it actually bit. curl hands a streamed body to the write callback instead of
# returning it, so aiHttp used to report body=''. Both retries inspect the body, so neither
# could fire on a streamed request, and aiErrorText had nothing to quote -- the panel said
# only "Request failed (HTTP 400)". ai.log showed "Provider test: OK" (non-streaming probe)
# one line above "Chat failed: Request failed (HTTP 400)" (streamed), which is the exact
# fingerprint of this bug.
out="$(run_chat strict stream)"
calls="$(tr '\n' ' ' < "$WORK/calls" | sed 's/ *$//')"
printf '  result: %s\n  parameters sent, in order: %s\n' "$out" "$calls"

case "$out" in
	OK*) ok "the streamed call recovered too" ;;
	*)   bad "streaming still fails: $out" ;;
esac
if [ "$calls" = "max_tokens/- max_completion_tokens/-" ]; then
	ok "the retry fires on the streaming path as well"
else
	bad "streaming retry did not happen: [$calls]"
fi

printf '\n\033[1mA streamed error must not be mute\033[0m\n'
# Force a refusal the retry cannot fix, and check the upstream text survives to the caller.
printf 'always400' > "$WORK/mode"
: > "$WORK/calls"
out="$(run_chat always400 stream)"
printf '  result: %s\n' "$out"
case "$out" in
	*"max_completion_tokens"*|*"Unsupported parameter"*)
		ok "the upstream message reaches the operator instead of a bare status code" ;;
	*"HTTP 400"*)
		bad "still reporting only the status code — the streamed body is being discarded" ;;
	*)  bad "unexpected: $out" ;;
esac

printf '\n\033[1mA model that refuses function tools unless reasoning_effort is none\033[0m\n'
out="$(run_chat reasoning stream tools)"
calls="$(tr '\n' ' ' < "$WORK/calls" | sed 's/ *$//')"
printf '  result: %s\n  sent (tokens/reasoning): %s\n' "$out" "$calls"
case "$out" in
	OK*) ok "recovered by setting reasoning_effort to none" ;;
	*)   bad "not handled: $out" ;;
esac

printf '\n\033[1mBOTH quirks at once — the composition is the real test\033[0m\n'
# Neither remedy alone is enough here. A pair of independent one-shot retries would fix one
# and then give up; the negotiation loop has to carry the first fix into the second attempt.
out="$(run_chat both stream tools)"
calls="$(tr '\n' ' ' < "$WORK/calls" | sed 's/ *$//')"
printf '  result: %s\n  sent (tokens/reasoning): %s\n' "$out" "$calls"
case "$out" in
	OK*) ok "recovered from two different refusals in one request" ;;
	*)   bad "composition not handled: $out" ;;
esac
case "$calls" in
	*"max_completion_tokens/none") ok "the final attempt carried BOTH adjustments" ;;
	*) bad "adjustments did not accumulate: [$calls]" ;;
esac

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mmax_tokens negotiation OK\033[0m (%s checks)\n' "$PASS"
	exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
