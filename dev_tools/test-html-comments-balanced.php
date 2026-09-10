<?php
# Oracle test: every HTML comment in the web tree is opened and closed.
#
# WHY THIS EXISTS (2026-09-10):
#
# I edited the world cards with a scripted patch, then "undid" it with a second script whose
# stop condition matched a line INSIDE the comment I was removing. It deleted the `<!--` opener
# and a few lines of the body, and left the rest -- including the `-->` -- sitting in the middle
# of the card markup. Six lines of my own explanatory prose rendered as visible text at the top
# of every world card on the public page.
#
# Nothing caught it. The PHP parsed fine. Every layout test passed, because those tests build
# their own copy of the card markup and never look at what this file actually emits. The
# source guards grepped for specific strings, and "no stray comment text" was not one of them.
#
# This checks the property directly: in each file, `<!--` and `-->` must alternate and balance.
# A dangling `-->` means an opener was destroyed and everything before it is now page content.
#
# Run:  php dev_tools/test-html-comments-balanced.php

$roots = [
    __DIR__ . '/../container/nginx/www/public',
    __DIR__ . '/../container/nginx/www/admin',
    __DIR__ . '/../container/nginx/www/includes',
];

$pass = 0; $fail = 0;
function check($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail ? " -- $detail" : '') . "\n"; }
}

$files = [];
foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    foreach (new DirectoryIterator($root) as $f) {
        if ($f->isFile() && preg_match('/\.(php|html)$/', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);
check('found web files to scan', count($files) > 0, count($files) . ' files');

# Only text that REACHES THE BROWSER can contain an HTML comment.
#
# Scanning the raw source both over- and under-reports. `while ($qe-->0)` -- the `$qe-- > 0`
# idiom, which appears in the minified Adminer bundle -- looks like a stray `-->` but is PHP
# code. Meanwhile the card markup I actually broke lives INSIDE a PHP echo string, so simply
# skipping PHP would miss the very bug this file exists for.
#
# PHP's own tokenizer draws the line exactly: inline HTML plus the contents of string literals
# is what gets emitted; everything else is code.
function emittedText($src) {
    $out = [];
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = [$tok[1], $tok[2]];   # text, starting line
            }
        }
    }
    return $out;
}

echo "\nHTML comments open and close, in order:\n";
foreach ($files as $file) {
    $short = basename($file);
    $problem = null;
    $depth = 0;

    foreach (emittedText(file_get_contents($file)) as [$text, $startLine]) {
        preg_match_all('/<!--|-->/', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $tok) {
            $line = $startLine + substr_count(substr($text, 0, $tok[1]), "\n");
            if ($tok[0] === '<!--') {
                # Nesting is not legal in HTML comments, so the only valid sequence is
                # open, close, open, close...
                if ($depth > 0) { $problem = "nested <!-- at line $line (previous comment never closed)"; break 2; }
                $depth++;
            } else {
                if ($depth === 0) {
                    $problem = "stray --> at line $line with no opener; the text above it renders as page content";
                    break 2;
                }
                $depth--;
            }
        }
    }
    if ($problem === null && $depth !== 0) {
        $problem = 'a <!-- is never closed';
    }
    check($short, $problem === null, $problem);
}

echo "\nCONTROL: the checker actually detects a broken comment\n";
# Without this, a checker whose regex never matched would report every file clean.
$broken = "<div>ok</div>\n  some prose that used to be a comment -->\n<div>more</div>";
preg_match_all('/<!--|-->/', $broken, $bm, PREG_OFFSET_CAPTURE);
$d = 0; $caught = false;
foreach ($bm[0] as $tok) {
    if ($tok[0] === '<!--') { $d++; }
    else { if ($d === 0) { $caught = true; break; } $d--; }
}
check('a dangling --> is reported', $caught);

$balanced = "<div><!-- a real comment --></div>";
preg_match_all('/<!--|-->/', $balanced, $gm);
check('and a well-formed comment is not', count($gm[0]) === 2);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
