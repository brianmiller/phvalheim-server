#!/usr/bin/env bash
# Oracle test: admin/vibesAPI.php answers JSON for every action, and fails as JSON with a 4xx.
# requires: container
#
# WHAT IT PROVES. For every action in the table (docs/HUGINS-VIBES-DESIGN.md 10.6):
#   - a POST action asked with GET is a 405 JSON {success:false}
#   - a GET action without its required parameter is a 400 JSON {success:false}
#   - a POST action with an empty body is a 4xx JSON {success:false} (never a 500, never HTML)
#   - no response body ever contains a PHP warning/notice/fatal
#   - `state` carries the keys the page picks its state from; `settings` masks tokens;
#     an unknown action is a 404 JSON; the SSE endpoint answers a missing job with error + eos.
#
# It uses the container's own curl via docker exec (the admin UI is on 8081 inside the
# container), and the host's python3 to parse JSON so a non-JSON body is a loud failure.
# It mutates NOTHING except dismissNotice, whose flag it restores to the prior value.
#
#   argv: container name (default phvalheim-dev)

CONTAINER="${1:-phvalheim-dev}"
BASE="http://127.0.0.1:8081"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; [ -n "$2" ] && echo "        $2"; fail=$((fail+1)); }

# curl inside the container; prints "<status>\n<body>"
req() {   # method action [json-body]
	local m="$1" a="$2" body="${3:-}"
	if [ "$m" = "GET" ]; then
		docker exec "$CONTAINER" curl -s -w '\n%{http_code}' "$BASE/vibesAPI.php?action=$a"
	else
		docker exec "$CONTAINER" curl -s -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' --data "$body" "$BASE/vibesAPI.php?action=$a"
	fi
}
status_of() { echo "$1" | tail -n1; }
body_of()   { echo "$1" | sed '$d'; }
# python3 reads the body from stdin: prints "json" when it parses with success:false, else "notjson"/"success"
jsonfail()  { python3 -c 'import sys,json
try:
    d=json.load(sys.stdin)
except Exception:
    print("notjson"); sys.exit()
print("fail" if d.get("success") is False and isinstance(d.get("error"),str) and d["error"] else "ok" if d.get("success") else "shape")'; }

sql() { docker exec "$CONTAINER" mysql -N -e "$1" phvalheim; }

if ! docker exec "$CONTAINER" true 2>/dev/null; then echo "HARNESS: container $CONTAINER not running"; exit 2; fi

check_fail() {   # label method action body expected-status-regex
	local r; r=$(req "$2" "$3" "${4:-}")
	local st; st=$(status_of "$r"); local b; b=$(body_of "$r"); local j; j=$(echo "$b" | jsonfail)
	if echo "$b" | grep -q 'Warning:\|Notice:\|Fatal error\|Deprecated:'; then bad "$1: PHP noise in body" "$(echo "$b" | head -c 200)"; return; fi
	if [[ "$st" =~ $5 ]] && [ "$j" = "fail" ]; then ok "$1 -> $st JSON success:false"; else bad "$1" "status=$st parse=$j body=$(echo "$b" | head -c 160)"; fi
}

echo "-- unknown / missing action"
check_fail "unknown action is 404"   GET nope ''                 '^404$'
check_fail "missing action is 404"   GET ''   ''                 '^404$'

echo "-- POST-only actions refuse GET (405)"
for a in install uninstall harvest pakUpdate gc createProject updateProject deleteProject newSession renameSession turn cancel saveFile build package publish unpublish push saveNotes uploadAsset deleteAsset saveSettings testGitHub testGitLab dismissNotice; do
	check_fail "GET $a" GET "$a" '' '^405$'
done

echo "-- GET actions without their required parameter (400)"
for a in project exportProject sessions transcript job jobLog file gitLog gitDiff gitStatus notes asset aiModels; do
	check_fail "GET $a (no params)" GET "$a" '' '^400$'
done

echo "-- POST actions with an empty body (4xx, never 5xx)"
for a in createProject updateProject deleteProject newSession renameSession turn cancel saveFile build package publish unpublish push saveNotes uploadAsset deleteAsset saveSettings testGitHub testGitLab; do
	check_fail "POST $a {}" POST "$a" '{}' '^4[0-9][0-9]$'
done
check_fail "POST turn with a bad JSON body" POST turn '{not json' '^400$'

echo "-- successful reads have the right shape"
r=$(req GET state); st=$(status_of "$r"); b=$(body_of "$r")
missing=$(echo "$b" | python3 -c 'import sys,json
d=json.load(sys.stdin)
need=["installed","noticeShown","pak","libs","ai","disk","jobs","runner","worldsWithServerFiles","projectCount","root","success"]
print(" ".join(k for k in need if k not in d))
sub={"pak":["version","present","compatible","problem"],"libs":["present","gameVersion"],"ai":["ok","reason"],"disk":["free","required","ok"],"runner":["ok","missing"]}
for k,v in sub.items():
    if isinstance(d.get(k),dict): print(" ".join(k+"."+x for x in v if x not in d[k]), end=" ")' 2>&1)
if [ "$st" = "200" ] && [ -z "$(echo $missing)" ]; then ok "state has every key the router reads"; else bad "state keys" "status=$st missing=[$missing]"; fi

r=$(req GET settings); st=$(status_of "$r"); b=$(body_of "$r")
masked=$(echo "$b" | python3 -c 'import sys,json
d=json.load(sys.stdin); s=d.get("settings",{})
t1=s.get("github",{}).get("token",""); t2=s.get("gitlab",{}).get("token","")
okmask=lambda t: t=="" or t.startswith("••••")
print("ok" if s.get("schema")==1 and okmask(t1) and okmask(t2) and isinstance(d.get("providers"),list) and "uploadMaxBytes" in d else "bad")')
if [ "$st" = "200" ] && [ "$masked" = "ok" ]; then ok "settings: schema 1, tokens masked, providers + upload limit present"; else bad "settings shape" "status=$st $masked"; fi

r=$(req GET projects); st=$(status_of "$r"); b=$(body_of "$r")
shape=$(echo "$b" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("ok" if d.get("success") and isinstance(d.get("projects"),list) else "bad")')
if [ "$st" = "200" ] && [ "$shape" = "ok" ]; then ok "projects is a list"; else bad "projects shape" "status=$st"; fi

r=$(req GET jobs); st=$(status_of "$r"); b=$(body_of "$r")
shape=$(echo "$b" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("ok" if isinstance(d.get("open"),list) and isinstance(d.get("recent"),list) else "bad")')
if [ "$st" = "200" ] && [ "$shape" = "ok" ]; then ok "jobs has open + recent lists"; else bad "jobs shape" "status=$st"; fi

echo "-- dismissNotice sets the flag (and is restored)"
before=$(sql "SELECT vibesNoticeShown FROM settings LIMIT 1")
r=$(req POST dismissNotice '{}'); st=$(status_of "$r")
after=$(sql "SELECT vibesNoticeShown FROM settings LIMIT 1")
if [ "$st" = "200" ] && [ "$after" = "1" ]; then ok "dismissNotice -> vibesNoticeShown=1 (was $before)"; else bad "dismissNotice" "status=$st after=$after"; fi
sql "UPDATE settings SET vibesNoticeShown=${before:-0}"
[ "$(sql "SELECT vibesNoticeShown FROM settings LIMIT 1")" = "${before:-0}" ] && ok "vibesNoticeShown restored to ${before:-0}" || bad "restore vibesNoticeShown"

echo "-- the SSE endpoint"
s=$(docker exec "$CONTAINER" timeout 4 curl -s -N "$BASE/vibesStream.php?job=999999" 2>/dev/null)
if echo "$s" | grep -q '"type":"error"' && echo "$s" | grep -q '"type":"eos"'; then ok "stream: missing job -> error + eos frames"; else bad "stream missing job" "$(echo "$s" | head -c 200)"; fi
s=$(docker exec "$CONTAINER" timeout 4 curl -s -N "$BASE/vibesStream.php" 2>/dev/null)
if echo "$s" | grep -q 'Give ?job'; then ok "stream: no target -> error frame"; else bad "stream no target" "$(echo "$s" | head -c 200)"; fi
# X-Accel-Buffering is consumed by nginx (that is its whole job) and never reaches a client, so
# it cannot be asserted from outside; what CAN be seen is that the answer is chunked, i.e. streamed.
hdr=$(docker exec "$CONTAINER" timeout 4 curl -s -N -D - -o /dev/null "$BASE/vibesStream.php?job=999999" 2>/dev/null)
if echo "$hdr" | grep -qi 'content-type: text/event-stream' && echo "$hdr" | grep -qi 'transfer-encoding: chunked'; then ok "stream: text/event-stream, chunked (streamed, not buffered)"; else bad "stream headers" "$(echo "$hdr" | tr '\n' ' ' | head -c 300)"; fi
grep -q "X-Accel-Buffering: no" "$(dirname "$0")/../container/nginx/www/admin/vibesStream.php" && ok "stream source sends X-Accel-Buffering: no (nginx strips it on the way out)" || bad "vibesStream.php lacks X-Accel-Buffering: no"

echo
echo "vibes API: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
