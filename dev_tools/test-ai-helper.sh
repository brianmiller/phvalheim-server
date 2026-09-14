#!/bin/bash
#
# Guards for the 2.45 AI Helper.
#
# The headline guard is NO HARDCODED MODEL IDS. Issue #83 was a retired
# `gemini-2.0-flash` sitting in a PHP array; the fix is architectural, and an
# architectural fix needs a guard or it erodes the first time someone adds a
# "sensible default". Every check below fails loudly if its fault is reintroduced --
# there is a negative-control run at the end that proves exactly that, because a check
# that passes whether or not the bug is present is worse than no check.
#
# Needs only `php` and standard tools. No database, no network.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WWW="$ROOT/container/nginx/www"
PASS=0
FAIL=0

# The PHP block below is a heredoc, so it cannot see shell variables -- it reads these
# two out of the environment instead. Set them HERE rather than expecting a caller to:
# an unset PHV_RESULT makes the `read` at the end silently yield an empty $F, and the
# suite then reports success no matter how many PHP checks failed.
export PHV_WWW="$WWW"
PHV_RESULT="$(mktemp)"
export PHV_RESULT
trap 'rm -f "$PHV_RESULT"' EXIT

ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------------
head_ "1. No hardcoded model identifiers in the AI sources"

# Model-id shapes across the providers we support. Deliberately broad: the point is to
# catch a "sensible default" before it becomes next year's #83.
MODEL_RX='gpt-[0-9o]|claude-(opus|sonnet|haiku|fable|mythos|[0-9])|gemini-[0-9]|llama-?[0-9]|mistral-[a-z]|deepseek-[a-z]|grok-[0-9]|text-embedding-'

for f in "$WWW/includes/aiproviders.php" \
         "$WWW/includes/aicontext.php" \
         "$WWW/includes/aidiagnose.php" \
         "$WWW/admin/aiStream.php" \
         "$WWW/admin/adminAPI.php"; do
	rel="${f#$ROOT/}"

	# Strip comments before matching, so prose ABOUT the bug (which every one of these
	# files contains, deliberately) does not trip the guard. Only live code counts.
	stripped="$(php -r '
		$t = token_get_all(file_get_contents($argv[1]));
		foreach ($t as $x) {
			if (is_array($x) && in_array($x[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
			echo is_array($x) ? $x[1] : $x;
		}
	' "$f" 2>/dev/null)"

	if printf '%s' "$stripped" | grep -Eiq "$MODEL_RX"; then
		bad "$rel contains a model identifier in live code:"
		printf '%s' "$stripped" | grep -Eio "$MODEL_RX[a-z0-9._-]*" | sort -u | sed 's/^/          /'
	else
		ok "$rel holds no model identifiers"
	fi
done

# The JS half has to obey the same rule, or the picker grows its own stale copy.
if grep -Eiq "$MODEL_RX" <(sed -n '/AI Helper client/,/Deep link from a log-viewer/p' "$WWW/admin/index.php"); then
	bad "admin/index.php AI client contains a model identifier"
else
	ok "admin/index.php AI client holds no model identifiers"
fi

# ---------------------------------------------------------------------------------
head_ "2. Behavioural checks"

php <<'PHP'
<?php
$root = getenv('PHV_WWW');
$pass = 0; $fail = 0;
function ok($m)  { global $pass; $pass++; printf("  \033[32mPASS\033[0m  %s\n", $m); }
function bad($m) { global $fail; $fail++; printf("  \033[31mFAIL\033[0m  %s\n", $m); }

// Point the log tools at a scratch directory before including them.
$tmp = sys_get_temp_dir() . '/phv-ai-test-' . getmypid();
@mkdir($tmp . '/logs', 0777, true);
file_put_contents($tmp . '/logs/valheimworld_Test.log',
    "line one\n[Message: BepInEx] BepInEx 5.4 - Valheim\n[Error  : BepInEx] Could not load [Foo]\nlast\n");
file_put_contents($tmp . '/secret.txt', 'should never be readable');
define('AI_LOG_DIR', $tmp . '/logs');

require $root . '/includes/aiproviders.php';
require $root . '/includes/aicontext.php';

/* ---- path containment ---------------------------------------------------------- */

if (aiResolveLogPath('valheimworld_Test.log') !== null) {
    ok('read_log resolves a real log inside the log directory');
} else {
    bad('read_log cannot resolve a log that exists');
}

$escapes = ['../secret.txt', '../../etc/passwd', '/etc/passwd', 'subdir/../../secret.txt'];
$leaked = [];
foreach ($escapes as $e) if (aiResolveLogPath($e) !== null) $leaked[] = $e;
if (!$leaked) {
    ok('read_log refuses every traversal attempt (' . count($escapes) . ' tried)');
} else {
    bad('read_log ESCAPED the log directory for: ' . implode(', ', $leaked));
}

// A symlink planted inside the log directory is the case basename() alone misses.
@symlink($tmp . '/secret.txt', $tmp . '/logs/sneaky.log');
if (is_link($tmp . '/logs/sneaky.log')) {
    if (aiResolveLogPath('sneaky.log') === null) {
        ok('read_log refuses a symlink pointing outside the log directory');
    } else {
        bad('read_log FOLLOWED a symlink out of the log directory');
    }
} else {
    echo "  SKIP  symlink could not be created on this filesystem\n";
}

/* ---- since-last-start ---------------------------------------------------------- */

$since = aiTailSinceLastStart($tmp . '/logs/valheimworld_Test.log', 100);
$blob  = implode('', $since);
if (strpos($blob, 'line one') === false && strpos($blob, 'Could not load') !== false) {
    ok('since_last_start drops pre-boot lines and keeps post-boot ones');
} else {
    bad('since_last_start returned the wrong slice: ' . json_encode($since));
}

/* ---- provider error text ------------------------------------------------------- */
//
// This is the path that let issue #83 be diagnosed at all: the reporter pasted the
// provider's own sentence. A generic "request failed" would have cost a round trip.

$cases = [
    ['{"error":{"message":"This model models/gemini-2.0-flash is no longer available."}}', 'no longer available'],
    ['{"error":{"type":"invalid_request_error","message":"model: unknown"}}',              'model: unknown'],
    ['{"detail":"Not Found"}',                                                             'Not Found'],
];
$missed = [];
foreach ($cases as $c) {
    $got = aiErrorText(['ok' => false, 'code' => 400, 'body' => $c[0], 'error' => '']);
    if (strpos($got, $c[1]) === false) $missed[] = $c[1] . ' -> ' . $got;
}
if (!$missed) {
    ok('provider error messages are surfaced verbatim (' . count($cases) . ' shapes)');
} else {
    bad('provider error text was swallowed: ' . implode(' | ', $missed));
}

/* ---- auth headers -------------------------------------------------------------- */

$h = aiAuthHeaders(['kind' => 'openai_compatible', 'api_key' => 'sk-x', 'headers' => []]);
$a = aiAuthHeaders(['kind' => 'anthropic',         'api_key' => 'sk-y', 'headers' => []]);
$g = aiAuthHeaders(['kind' => 'gemini',            'api_key' => 'sk-z', 'headers' => []]);
if (($h['Authorization'] ?? '') === 'Bearer sk-x'
    && ($a['x-api-key'] ?? '') === 'sk-y' && isset($a['anthropic-version'])
    && ($g['x-goog-api-key'] ?? '') === 'sk-z') {
    ok('each provider kind gets its own auth header shape');
} else {
    bad('auth headers are wrong: ' . json_encode([$h, $a, $g]));
}

// A self-hosted server behind --api-key was impossible before 2.45.
// openai_compatible, not the removed 'ollama' kind: a keyless local server that is put
// behind an authenticating proxy still needs its Bearer token forwarded.
$o = aiAuthHeaders(['kind' => 'openai_compatible', 'api_key' => 'tok', 'headers' => []]);
if (($o['Authorization'] ?? '') === 'Bearer tok') {
    ok('a keyed local server (vLLM/Ollama behind auth) sends its credential');
} else {
    bad('a local server with a key sends no credential');
}

// Header injection via a pasted value with a newline.
$inj = aiDecodeHeaders(json_encode(["X-Bad\r\nX-Evil" => "a\r\nb"]));
$flat = json_encode($inj);
if (strpos($flat, '\r') === false && strpos($flat, '\n') === false) {
    ok('extra headers are stripped of CR/LF (no header injection)');
} else {
    bad('extra headers preserved CR/LF: ' . $flat);
}

/* ---- SSE framing --------------------------------------------------------------- */

$seen = [];
$reader = aiSseReader(function ($p) use (&$seen) { $seen[] = $p; });
// Deliberately split mid-event: a real stream arrives in arbitrary chunks.
$reader("data: {\"a\":1}\n\ndata: {\"b\"");
$reader(":2}\n\n");
$reader("data: [DONE]\n\n");
if ($seen === ['{"a":1}', '{"b":2}']) {
    ok('SSE reader reassembles events split across chunks and drops [DONE]');
} else {
    bad('SSE reader mis-framed: ' . json_encode($seen));
}

/* ---- tool schemas are well formed ---------------------------------------------- */

$bad_tools = [];
foreach (aiToolDefinitions() as $t) {
    if (empty($t['name']) || empty($t['description']) || !isset($t['parameters']['type'])) {
        $bad_tools[] = $t['name'] ?? '(unnamed)';
    }
}
if (!$bad_tools) {
    ok(count(aiToolDefinitions()) . ' tool schemas are well formed');
} else {
    bad('malformed tool schemas: ' . implode(', ', $bad_tools));
}

// This check was REPLACED rather than deleted when Hugin gained actions.
//
// Before the action layer the rule was "no tool may mutate anything", and this asserted exactly that.
// Hugin now deliberately has actions, so the old assertion is obsolete -- but deleting it
// would leave the most dangerous surface in the product with no static guard at all.
//
// The rule it becomes: a mutating tool may exist ONLY if the catalogue declares it, and only
// a declared-safe action may skip the operator's confirmation. So a mutating tool added
// without a catalogue entry, or quietly marked 'safe', fails here.
// $root, not __DIR__: this block is fed to PHP on stdin, so __DIR__ is the caller's working
// directory rather than the repo.
require_once $root . '/includes/aiactions.php';

$catalogue = aiActionCatalogue();
// Reversible or purely additive. Everything else must go through a confirmation card.
$SAFE_OK   = ['start_world', 'create_backup', 'sync_mod_catalogue'];

$undeclared = [];
foreach (aiToolDefinitions() as $t) {
    if (!preg_match('/^(start|stop|delete|create|save|update|restart|write|install|remove|set|sync)_/', $t['name'])) continue;
    if (!isset($catalogue[$t['name']])) $undeclared[] = $t['name'];
}
if (!$undeclared) {
    ok('every mutating tool is declared in the action catalogue');
} else {
    bad('MUTATING TOOLS OUTSIDE THE CATALOGUE (no tier, no confirmation): ' . implode(', ', $undeclared));
}

$looseSafe = [];
foreach ($catalogue as $n => $a) {
    if (($a['tier'] ?? '') === 'safe' && !in_array($n, $SAFE_OK, true)) $looseSafe[] = $n;
}
if (!$looseSafe) {
    ok('only known-reversible actions skip the confirmation step');
} else {
    bad('ACTIONS MARKED SAFE THAT SHOULD NOT BE: ' . implode(', ', $looseSafe));
}

// Destroying data must additionally demand the world name be typed.
$untyped = [];
foreach (['delete_world', 'restore_backup', 'delete_backups'] as $n) {
    if (isset($catalogue[$n]) && empty($catalogue[$n]['typed'])) $untyped[] = $n;
}
if (!$untyped) {
    ok('irreversible actions require a typed confirmation');
} else {
    bad('IRREVERSIBLE WITHOUT A TYPED NAME: ' . implode(', ', $untyped));
}

/* ---- negative controls ---------------------------------------------------------
 * Prove the checks above can actually fail. A guard that cannot distinguish a broken
 * build from a healthy one is the most common way a test suite lies. */

$controls = 0; $caught = 0;

$controls++;
if (aiResolveLogPath('../secret.txt') === null) $caught++;   // must reject

$controls++;
$fake = aiErrorText(['ok' => false, 'code' => 0, 'body' => '', 'error' => '']);
if (strpos($fake, 'Request failed') !== false) $caught++;    // must fall back, not crash

$controls++;
$empty = aiSseReader(function () {});
$empty("garbage with no data prefix\n\n");
$caught++;                                                    // must not throw

if ($caught === $controls) {
    ok("negative controls behave as expected ($caught/$controls)");
} else {
    bad("negative controls FAILED ($caught/$controls) — the checks above may not be real");
}

@unlink($tmp . '/logs/sneaky.log');
@unlink($tmp . '/logs/valheimworld_Test.log');
@unlink($tmp . '/secret.txt');
@rmdir($tmp . '/logs');
@rmdir($tmp);

// Trailing newline matters: `read` returns non-zero on an unterminated final line, and
// the caller treats a failed read as "the block died".
file_put_contents(getenv('PHV_RESULT'), "$pass $fail\n");
PHP

# If the PHP block died before writing its tally, treat that as a failure rather than
# letting an empty $F read as zero.
if ! read -r P F < "$PHV_RESULT" 2>/dev/null || [ -z "${F:-}" ]; then
	bad "the PHP check block did not complete"
	P=0; F=0
fi

# ---------------------------------------------------------------------------------
head_ "3. The dead legacy columns have no live readers"

# The 2.43 world-card regression came from exactly this: a migration that changed no
# read sites. The legacy columns still hold plausible values, so a leftover reader
# returns a believable wrong answer instead of an error.
LEGACY='openaiApiKey|geminiApiKey|claudeApiKey|ollamaUrl'
hits="$(grep -rnE "$LEGACY" \
          --include='*.php' --include='*.sh' \
          "$ROOT/container" 2>/dev/null \
        | grep -v '/vendor/' \
        | grep -v 'dbUpdates/dbUpdate_2\.31\.sh' \
        | grep -v 'dbUpdates/dbUpdate_2\.45\.sh' \
        | grep -vE ':[0-9]+:\s*(#|//|\*)' \
        | grep -v 'no longer needed' \
        | grep -v 'basePort, backupsToKeep')"

if [ -z "$hits" ]; then
	ok "no live code reads the pre-2.45 AI settings columns"
else
	bad "live readers of the dead AI columns remain:"
	printf '%s\n' "$hits" | sed 's/^/          /'
fi

# ---------------------------------------------------------------------------------
head_ "3b. Every AI include loads on its own"

# The include graph runs ONE WAY: aidiagnose requires aicontext, never the reverse. A helper
# that lives on the aidiagnose side but gets called from the aicontext side is invisible to
# php -l and to the diagnostics path, and fatals only on the chat path -- which is how
# aiSystemPrompt() calling aiTruthy() shipped. Every message died with
# "Call to undefined function aiTruthy()", the SSE emitted its start event and nothing more,
# and the panel rendered an empty bubble with no error.
for inc in aiproviders aicontext aidiagnose; do
	out="$(php -r "
		define('AI_LOG_DIR', '/tmp');
		require '$ROOT/container/nginx/www/includes/$inc.php';
		// fetchAll MUST return at least one row. PHP only fatals on an undefined function
		// when the call actually executes, so an empty world list means every per-world
		// loop body is skipped and the missing symbol is never reached -- the first cut of
		// this guard passed cleanly against a deliberately broken tree for exactly that
		// reason. A fixture that cannot reach the code under test is not a test.
		class FS { function execute(\$a=null){return true;}
		           function fetchAll(\$m=null){ return [['id'=>1,'name'=>'GuardWorld','status'=>'Running',
		                                                 'public'=>1,'vanilla'=>1]]; }
		           function fetchColumn(\$i=0){return 0;} function fetch(\$m=null){return false;} }
		class FP { function prepare(\$s){return new FS();} function query(\$s){return new FS();} }
		// Touch the entry points the web tier actually calls, so an undefined symbol
		// surfaces here rather than at the first question an operator asks.
		if (function_exists('aiSystemPrompt')) aiSystemPrompt(new FP(), '');
		if (function_exists('aiToolSchemas'))  aiToolSchemas();
		if (function_exists('aiDiagnose'))     aiDiagnose(new FP(), '');
		if (function_exists('aiProviderKinds')) aiProviderKinds();
		echo 'OK';
	" 2>&1)"
	case "$out" in
		*OK*) ok "$inc.php loads standalone and its entry points resolve" ;;
		*)    bad "$inc.php cannot stand alone: $(printf '%s' "$out" | head -1)" ;;
	esac
done

# ---------------------------------------------------------------------------------
head_ "4. Nothing picks a model on the operator's behalf"

# The first cut of the wizard ran Test BEFORE Model, so the round trip had to invent
# something to probe with and reached for $disc['models'][0]['id']. On a real paid Gemini
# account that is `antigravity-preview-05-2026`, which refuses systemInstruction -- so a
# working key failed the wizard citing a model the operator had never chosen. That is
# issue #83's defect (code choosing the model) wearing a different hat, which is exactly
# why it slipped past the guards above: no model id is hardcoded, it is *selected*.
picks="$(grep -nE "models'?\]?\[0\]|models\[0\]" \
           "$ROOT/container/nginx/www/includes/aiproviders.php" \
           "$ROOT/container/nginx/www/admin/adminAPI.php" 2>/dev/null \
         | grep -vE ':[0-9]+:\s*(#|//|\*)')"
if [ -z "$picks" ]; then
	ok "no code path selects a model from the discovered list"
else
	bad "a model is being auto-selected from discovery:"
	printf '%s\n' "$picks" | sed 's/^/          /'
fi

# The ordering is the structural fix; without it the auto-pick grows back because the
# test step genuinely has nothing to run.
steps="$(grep -m1 'AI_WIZ_STEPS = ' "$ROOT/container/nginx/www/admin/index.php")"
case "$steps" in
	*"'Model', 'Test'"*) ok "the wizard asks for a model before it tests one" ;;
	*) bad "wizard step order puts Test before Model: $steps" ;;
esac

# ---------------------------------------------------------------------------------
printf '\n'
if [ "$FAIL" -eq 0 ] && [ "${F:-0}" -eq 0 ]; then
	printf '\033[32mAll AI Helper guards passed\033[0m (%s shell + %s php)\n' "$PASS" "${P:-0}"
	exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$((FAIL + ${F:-0}))"
exit 1
