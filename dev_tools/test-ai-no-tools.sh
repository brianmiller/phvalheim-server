#!/bin/bash
# An endpoint that cannot do function calling must still answer -- and must say so.
#
# The operator brings their own LLM. A small local model on an endpoint that never
# implemented tools returns a hard 400 on every message, which before this was simply a
# dead assistant. Now the request negotiates it away like any other quirk: drop the tool
# surface, retry, answer, and LABEL the answer as toolless.
#
# The labelling is the part that matters. A degraded Hugin that looks identical to a full
# one is how an operator comes to trust something the model invented.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [ -z "$PHV_IN_CONTAINER" ] && ! php -m 2>/dev/null | grep -qi '^curl$'; then
	IMAGE="${PHV_IMAGE:-theoriginalbrian/phvalheim-server:rc}"
	echo "  (host PHP has no curl — re-running inside $IMAGE)"
	exec docker run --rm -e PHV_IN_CONTAINER=1 -v "$ROOT:$ROOT" -w "$ROOT" \
		--entrypoint bash "$IMAGE" dev_tools/test-ai-no-tools.sh
fi

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

WORK="$(mktemp -d)"; PORT=18801
trap 'kill %1 2>/dev/null; rm -rf "$WORK"' EXIT

# A stub that behaves like llama.cpp-server / an old vLLM: 400 on any request carrying
# `tools`, perfectly happy without it.
cat > "$WORK/stub.php" <<'PHPEOF'
<?php
$in = json_decode(file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');

if (isset($in['tools'])) {
    file_put_contents(__DIR__ . '/sawtools', '1');
    http_response_code(400);
    echo json_encode(['error' => ['message' =>
        "This model does not support the 'tools' parameter."]]);
    exit;
}
file_put_contents(__DIR__ . '/answered', '1');
echo json_encode(['model' => 'tiny', 'choices' => [['message' =>
    ['role' => 'assistant', 'content' => 'Two worlds are configured; one is running.']]]]);
PHPEOF

php -S "127.0.0.1:$PORT" "$WORK/stub.php" >/dev/null 2>&1 &
for i in $(seq 1 40); do php -r "exit(@fsockopen('127.0.0.1', $PORT) ? 0 : 1);" && break; sleep 0.25; done

printf '\n\033[1mAn endpoint that refuses the tools parameter\033[0m\n'

out=$(php -r "
	define('AI_LOG_DIR', '$WORK');
	require '$ROOT/container/nginx/www/includes/aiproviders.php';
	\$p = ['kind' => 'openai_compatible', 'base_url' => 'http://127.0.0.1:$PORT',
	       'api_key' => 'k', 'model' => 'tiny', 'headers' => []];
	\$tools = [['name' => 'list_worlds', 'description' => 'd',
	           'parameters' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]];
	\$r = aiChat(\$p, [['role' => 'user', 'content' => 'how many worlds?']], 'sys', \$tools, null, 64);
	echo (\$r['success'] ? 'OK' : 'ERR') . '|'
	   . (!empty(\$r['tools_dropped']) ? 'dropped' : 'kept') . '|'
	   . str_replace('|', ' ', \$r['success'] ? \$r['content'] : \$r['error']);
" 2>&1)

state="${out%%|*}"; rest="${out#*|}"; dropped="${rest%%|*}"; text="${rest#*|}"

[ -f "$WORK/sawtools" ] \
  && ok "the first attempt did offer tools (so the fallback is genuinely a fallback)" \
  || bad "tools were never sent — the test is not exercising the path it claims to"

[ "$state" = OK ] \
  && ok "the operator still gets an answer: \"$(echo "$text" | cut -c1-52)…\"" \
  || bad "no answer at all: $text"

[ "$dropped" = dropped ] \
  && ok "the reply is flagged tools_dropped, so the UI can label it" \
  || bad "the reply claims tools were used when they were not — a degraded answer would look identical to a real one"

# --- the label must reach the operator ------------------------------------------------
grep -q "aiDegradedNotice" "$ROOT/container/nginx/www/admin/index.php" \
  && grep -q "'degraded'" "$ROOT/container/nginx/www/admin/aiStream.php" \
  && ok "the stream carries 'degraded' and the panel renders a notice for it" \
  || bad "nothing surfaces the degradation to the operator"

# --- actions must NOT be offered to an endpoint known to be toolless ------------------
gated=$(php -r "
	define('AI_LOG_DIR', '$WORK');
	require '$ROOT/container/nginx/www/includes/aicontext.php';
	echo count(aiToolDefinitions(false)) . ',' . count(aiToolDefinitions(true));
")
ro="${gated%%,*}"; full="${gated##*,}"
[ "$full" -gt "$ro" ] \
  && ok "the read-only surface ($ro tools) is a strict subset of the full one ($full)" \
  || bad "action gating has no effect: read-only=$ro full=$full"

grep -q "in_array(\$cap, \['text', 'inert'\], true)" "$ROOT/container/nginx/www/includes/aicontext.php" \
  && ok "aiConverse withholds actions from a provider recorded 'text' or 'inert'" \
  || bad "aiConverse offers actions regardless of recorded capability"

# --- capability is DISCOVERED, never a model allowlist ---------------------------------
if grep -nE "(gpt-|claude-|gemini-|llama-|mistral)[a-z0-9.-]*'\s*=>\s*(true|false)" \
     "$ROOT/container/nginx/www/includes/aiproviders.php" \
     "$ROOT/container/nginx/www/includes/aicontext.php" >/dev/null 2>&1; then
	bad "a per-model capability table has appeared — that is 2.44's bug in a new costume"
else
	ok "no model-name capability table anywhere; it is negotiated from the refusal"
fi

# --- the playbooks must be present, and only when actions are ------------------------
#
# Tools without procedure produce a model that pokes at things. These rules are what
# separate someone who knows PhValheim from someone reading the schema, and losing them is
# invisible: the assistant still answers, just worse and more dangerously.
caps=$(php -r "
	define('AI_LOG_DIR', '$WORK');
	require '$ROOT/container/nginx/www/includes/aicontext.php';
	class P { public function query(\$s) { throw new Exception('no db'); } }
	\$with    = aiSystemPrompt(new P(), '', true);
	\$without = aiSystemPrompt(new P(), '', false);
	\$rules = ['DIAGNOSE BEFORE ACTING', 'NOT LIVE UNTIL THE WORLD IS REBUILT',
	           'NEVER INVENT A PLAYER ID', 'OPEN TO EVERYONE', 'MUST have a password',
	           'NOT Valheim', 'DISCONNECTS PLAYERS', 'PROPOSING IS NOT DOING'];
	\$missing = [];
	foreach (\$rules as \$r) if (strpos(\$with, \$r) === false) \$missing[] = \$r;
	echo count(\$rules) . '|' . implode(',', \$missing) . '|'
	   . (strpos(\$without, 'OPERATING PROCEDURES') === false ? 'absent' : 'present');
")
total="${caps%%|*}"; rest2="${caps#*|}"; missing="${rest2%%|*}"; roproc="${rest2##*|}"

[ -z "$missing" ] \
  && ok "all $total operating procedures are in the system prompt" \
  || bad "MISSING procedures: $missing"

[ "$roproc" = absent ] \
  && ok "the procedures are withheld when actions are not offered" \
  || bad "a toolless model is still told how to sequence changes it cannot make"

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mToolless endpoints degrade visibly\033[0m (%s checks)\n' "$PASS"; exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
