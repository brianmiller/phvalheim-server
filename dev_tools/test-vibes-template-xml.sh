#!/bin/bash
# Oracle test: every XML file a Vibes template ships is well-formed (2.59).
#
# THE BUG. The first real Build Pak install on the dev box got all the way through download,
# verify, extract, harvest, publicize and decompile, then failed its smoke compile with
# MSB4025: "An XML comment cannot contain '--'". Three comments in the basic-plugin template's
# csproj and Directory.Build.props used ' -- ' as prose punctuation. The same trap shipped an
# undrawable SVG icon in 2.51 (a '--' in its XML comment); MSBuild is just as strict.
#
# HOW. Parses every .csproj / .props / .targets / .xml / .svg under templates/ with a real XML
# parser, with the {{Token}} placeholders substituted so a token inside an attribute cannot
# mask a different error. A template that fails here fails every operator's first install.
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$REPO/container/engine/tools/vibes/templates"
pass=0; fail=0
while IFS= read -r f; do
	if python3 - "$f" <<'PY'
import sys, re, xml.dom.minidom
s = open(sys.argv[1]).read()
s = re.sub(r'\{\{[A-Za-z]+\}\}', 'Token', s)
xml.dom.minidom.parseString(s)
PY
	then echo "  PASS  ${f#$T/} is well-formed XML"; pass=$((pass+1))
	else echo "  FAIL  ${f#$T/} is not well-formed XML"; fail=$((fail+1)); fi
done < <(find "$T" -type f \( -name '*.csproj' -o -name '*.props' -o -name '*.targets' -o -name '*.xml' -o -name '*.svg' \) | sort)
[ "$((pass+fail))" -gt 0 ] || { echo "  FAIL  no XML files found under templates/"; fail=1; }
echo "=== $pass passed, $fail failed ==="
[ "$fail" = "0" ]
