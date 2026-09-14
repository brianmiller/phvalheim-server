#!/bin/bash
# A streamed reply must survive being cut on byte boundaries.
#
# Found by looking at a screenshot. Hugin's reply read:
#
#   "I have put that to you as a change ts happened yet."
#
# instead of "...as a change to confirm — nothing has happened yet." Two deltas had
# vanished with no error anywhere.
#
# json_encode() returns FALSE on malformed UTF-8, and `'data: ' . false` is `'data: '` --
# a frame with an empty payload. The browser's JSON.parse throws, its catch does
# `continue`, and the text is simply gone. Nothing logs, nothing warns; the sentence just
# has a hole in it.
#
# A provider is entitled to end a chunk mid-character, so this is not hypothetical.
# aiUtf8Carry() holds an incomplete trailing sequence back for the next chunk, and sse()
# substitutes rather than dropping if anything still slips through.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/container/nginx/www/admin/aiStream.php"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

printf '\n\033[1mStreaming multibyte text through byte-boundary cuts\033[0m\n'

# Chunk at many different sizes: a bug that only shows at one split width is still a bug.
result=$(php -r '
$src = file_get_contents($argv[1]);
preg_match("/function aiUtf8Carry.*?\n}/s", $src, $m);

$answer = "Restart — nothing has happened yet. Ærlig talt: 日本語 — ok? Ω≈ç√∫˜µ";
$bad = 0; $checked = 0;

foreach ([3,4,5,7,9,11,13,16,23] as $size) {
    // Fresh static state per run, or the carry leaks between cases.
    $fn = "aiUtf8Carry_$size";
    eval(str_replace("function aiUtf8Carry", "function $fn", $m[0]));

    $out = ""; $dropped = 0;
    foreach (str_split($answer, $size) as $frag) {
        $safe = $fn($frag);
        if ($safe === "") continue;
        $j = json_encode(["text" => $safe]);
        if ($j === false) { $dropped++; continue; }
        $out .= json_decode($j, true)["text"];
    }
    $checked++;
    if ($out !== $answer || $dropped) { $bad++; echo "size $size: LOST (dropped $dropped) -> $out\n"; }
}
echo "checked=$checked bad=$bad\n";
' "$SRC")

echo "$result" | grep -q 'bad=0' \
  && ok "reply survives every chunk size tested ($(echo "$result" | grep -oP 'checked=\K[0-9]+') widths, em dash + Æ + CJK + symbols)" \
  || bad "text was lost: $(echo "$result" | head -3)"

# --- sse() must never emit an empty frame ---------------------------------------------
#
# Capture the PROCESS's stdout, not an output buffer: sse() calls ob_flush(), so an
# ob_start() wrapper here is flushed straight past and ob_get_clean() returns "" --
# which reads exactly like the bug being tested for. Let the shell do the capturing.
frame=$(php -r '
$src = file_get_contents($argv[1]);
preg_match("/function sse\(.*?\n}/s", $src, $m);
eval($m[0]);
sse(["type" => "delta", "text" => "bad \xE2\x80 byte"]);   // deliberately truncated em dash
' "$SRC" | tr -d '\n')
payload="${frame#data: }"
if [ -n "$payload" ] && [ "$payload" != "false" ]; then
	ok "a malformed frame still carries a payload: $payload"
else
	bad "sse() emitted an empty frame — the browser will silently drop it"
fi

# --- mutation: the guard must be load-bearing -----------------------------------------
MUT="$(mktemp)"
sed 's/if (\$need > \$i) {/if (false) {/' "$SRC" > "$MUT"
# Sweep widths here too. A single chunk size proves nothing: at size 7 this particular
# sentence happens to keep the em dash whole, so the mutated code "passed" and the test
# claimed the guard was dead weight when it was the fixture that was wrong.
mutres=$(php -r '
$src = file_get_contents($argv[1]);
preg_match("/function aiUtf8Carry.*?\n}/s", $src, $m);
$answer = "Restart — nothing has happened yet. Ærlig talt: 日本語";
$lost = 0;
foreach ([3,4,5,7,9,11,13,16,23] as $size) {
    $fn = "aiUtf8Carry_$size";
    eval(str_replace("function aiUtf8Carry", "function $fn", $m[0]));
    $out = "";
    foreach (str_split($answer, $size) as $frag) {
        $safe = $fn($frag);
        if ($safe === "") continue;
        $j = json_encode(["text" => $safe]);
        if ($j === false) continue;
        $out .= json_decode($j, true)["text"];
    }
    if ($out !== $answer) $lost++;
}
echo $lost > 0 ? "LOST" : "INTACT";
' "$MUT")
rm -f "$MUT"
[ "$mutres" = LOST ] \
  && ok "removing the carry check reintroduces the loss — the guard is load-bearing" \
  || bad "the carry check can be removed with no effect; this test proves nothing"

printf '\n'
if [ "$FAIL" -eq 0 ]; then
	printf '\033[32mStreamed text is byte-split safe\033[0m (%s checks)\n' "$PASS"; exit 0
fi
printf '\033[31m%s failure(s)\033[0m\n' "$FAIL"
exit 1
