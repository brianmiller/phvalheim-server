#!/bin/bash
# Oracle test: an operator's manual match decides which mod owns a config file -- and the two
# places that resolve ownership agree on it.
#
# THE REQUEST (Discord, 2026-10-19), right after a ValheimPlus fork's config sat under
# "Unmatched config files": "we detected an orphaned config file, does it match one of your
# mods?" Learning plugin GUIDs at boot covers most such files; a manual match covers the rest.
#
# A match is stored as a MANUAL row in mod_plugin_guids, keyed on the file's stem, and ownership
# is decided by ONE function, modConfigs.resolve_guid_owners(). It is reached two ways: from the
# DB (guid_owners(), the engine's boot re-attribution) and from JSON the admin UI hands over
# (catalogue_from_json(), the live editor). If those two ever disagree, a file is filed under one
# mod in the editor and another in the stored rows -- which nobody would notice.
#
# Pinned here:
#   1. a learned GUID owns its file                                    (unchanged behaviour)
#   2. two learned claims cancel out -> no owner                       (unchanged: never guess)
#   3. a MANUAL claim wins, including over 2's tie -- the case it exists for
#   4. a manual claim beats a single learned claim for another mod     (explicit beats inferred)
#   5. a row that is BOTH learned and manual counts as both
#   6. a row with neither flag owns nothing
#   7. the JSON path (live editor) reaches exactly the answers of the claims path
#   8. attribute() files a file by its stem through a manual claim -- the end-to-end question
#
# Mutation controls: the resolver is copied with one decision broken, and the case it covers
# must fail. A control that silently does not apply is reported, not passed.
#
#   ./test-config-manual-match.sh
set -u

MC="$(cd "$(dirname "$0")/.." && pwd)/container/engine/tools/modConfigs.py"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; echo "        $2"; fail=$((fail+1)); }

# The pluginGuids.py that modConfigs.py loads by path must sit beside the copy under test.
cp "$(dirname "$MC")/pluginGuids.py" "$TMP/pluginGuids.py"

# Runs every case against one modConfigs.py and prints "<case> <answer>" lines.
run_cases() {
	cp "$1" "$TMP/modConfigs.py"
	python3 - "$TMP" <<'PY'
import importlib.util, json, sys
t = sys.argv[1]
spec = importlib.util.spec_from_file_location("mc", f"{t}/modConfigs.py")
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
R = mc.resolve_guid_owners

def show(name, v): print(name, "None" if v is None else v)

show("c1", R([("com.a.A", 1, 1, 0)]).get("com.a.A"))
show("c2", R([("dup", 1, 1, 0), ("dup", 2, 1, 0)]).get("dup"))
show("c3", R([("dup", 1, 1, 0), ("dup", 2, 1, 0), ("dup", 2, 0, 1)]).get("dup"))
show("c4", R([("valheim_plus", 1, 1, 0), ("valheim_plus", 3, 0, 1)]).get("valheim_plus"))
show("c5", R([("both", 5, 1, 1)]).get("both"))
show("c6", R([("neither", 6, 0, 0)]).get("neither"))

# 7. the same claims through the JSON the admin UI sends
rows = [
    {"id": 1, "name": "A", "full_name": "x-A", "guids": ["dup", "valheim_plus", "com.a.A"]},
    {"id": 2, "name": "B", "full_name": "x-B", "guids": ["dup"], "manual_guids": ["dup"]},
    {"id": 3, "name": "C", "full_name": "x-C", "manual_guids": ["valheim_plus"]},
]
p = f"{t}/cat.json"; json.dump(rows, open(p, "w"))
_, owners = mc.catalogue_from_json(p)
claims = R([("dup", 1, 1, 0), ("valheim_plus", 1, 1, 0), ("com.a.A", 1, 1, 0),
            ("dup", 2, 1, 0), ("dup", 2, 0, 1), ("valheim_plus", 3, 0, 1)])
print("c7", "same" if owners == claims else f"DIFFER json={owners} claims={claims}")

# 8. end to end: a file named after nothing any DLL declares, matched by hand
names, guids = mc.catalogue_from_json(p)
show("c8", mc.attribute("valheim_plus.cfg", {}, names, guids))
PY
}

declare -A want=([c1]=1 [c2]=None [c3]=2 [c4]=3 [c5]=5 [c6]=None [c7]=same [c8]=3)
declare -A label=(
	[c1]="1. a learned GUID owns its file"
	[c2]="2. two learned claims cancel out (never guess)"
	[c3]="3. a manual claim breaks the tie in 2"
	[c4]="4. a manual claim beats a learned claim for another mod"
	[c5]="5. a row both learned and manual counts"
	[c6]="6. a row with neither flag owns nothing"
	[c7]="7. the live editor's JSON path reaches the same answers as the DB path"
	[c8]="8. attribute() files valheim_plus.cfg under the manually matched mod"
)

echo
echo "manual config matching: one ownership rule, two ways in"
out=$(run_cases "$MC" 2>&1)
for c in c1 c2 c3 c4 c5 c6 c7 c8; do
	got=$(printf '%s\n' "$out" | awk -v c="$c" '$1==c{ $1=""; sub(/^ /,""); print }')
	[ "$got" = "${want[$c]}" ] && ok "${label[$c]}" || bad "${label[$c]}" "want '${want[$c]}', got '$got'"
done

echo
echo "mutation controls -- each must break the case it covers"
mutate() {  # label, find, replace, case, the answer that case must STOP giving
	local lbl="$1" find="$2" repl="$3" c="$4" n
	n=$(python3 -c 'import sys; print(open(sys.argv[1]).read().count(sys.argv[2]))' "$MC" "$find")
	if [ "$n" != "1" ]; then bad "mutation applies exactly once: $lbl" "found $n -- the control is vacuous"; return; fi
	python3 -c 'import sys; s=open(sys.argv[1]).read(); open(sys.argv[2],"w").write(s.replace(sys.argv[3], sys.argv[4]))' \
		"$MC" "$TMP/mut.py" "$find" "$repl"
	got=$(run_cases "$TMP/mut.py" 2>&1 | awk -v c="$c" '$1==c{ $1=""; sub(/^ /,""); print }')
	[ "$got" != "${want[$c]}" ] && ok "$lbl => case $c breaks (now '$got')" \
		|| bad "$lbl => case $c breaks" "still '${want[$c]}'"
}
mutate "manual claims are not applied over learned ones" \
	'    out.update({g: m for g, m in manual.items() if m is not None})' \
	'    pass' c3
mutate "learned and manual share one bucket (a manual claim joins the tie)" \
	'for flag, bucket in ((is_learned, learned), (is_manual, manual)):' \
	'for flag, bucket in ((is_learned, learned), (is_manual, learned)):' c3
mutate "the JSON path ignores manual_guids" \
	'for key, learned, manual in (("guids", 1, 0), ("manual_guids", 0, 1)):' \
	'for key, learned, manual in (("guids", 1, 0),):' c8

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ]
