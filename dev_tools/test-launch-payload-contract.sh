#!/bin/bash
#
# The launch string is a positional '?'-separated format with THREE readers in TWO repos:
#
#   writer  phvalheim-server  includes/db_gets.php      phvBuildLaunchString()
#   reader  phvalheim-client  Arguments.cs              argHandler()
#   reader  phvalheim-companion LaunchPayload.cs        Parse()
#
# Nothing connects them. Add a field in the middle, or reorder two, and every one of them
# still compiles, still runs, and starts reading the wrong value out of the right slot -- a
# port where a hostname should be. There is no exception and no log line; the join just fails.
#
# This test pins the field order in all three places and fails when they disagree.
#
# It checks POSITIONS, not just presence. A test that only asserted "joinCode appears in all
# three files" would pass happily while one of them read it from index 8 and another from 9.

set -u

cd "$(dirname "$0")/.." || exit 1

SERVER_PHP="container/nginx/www/includes/db_gets.php"
CLIENT_CS="../phvalheim-client/Arguments.cs"
COMPANION_CS="../phvalheim-companion/LaunchPayload.cs"

# The contract, as of server 2.53. Index 0 is the "launch" literal.
EXPECTED="launch world password gameDNS port phvalheimHost httpScheme vanilla crossplay joinCode"

fail=0
checked=0

pass() { checked=$((checked+1)); printf '  ok    %s\n' "$1"; }
bad()  { checked=$((checked+1)); fail=$((fail+1)); printf '  FAIL  %s -- %s\n' "$1" "$2"; }

printf '== preconditions ==\n'

for f in "$SERVER_PHP" "$CLIENT_CS" "$COMPANION_CS"; do
	if [ ! -f "$f" ]; then
		# Exiting non-zero rather than skipping: a missing reader is how the contract gets
		# broken silently in the first place, and "file not found" must never read as a pass.
		printf '  FAIL  %s is missing -- cannot verify the contract\n' "$f"
		exit 1
	fi
done
pass "all three files present"

# ---------------------------------------------------------------------------
# 1. The writer. Extract the field order straight out of the string it builds.
# ---------------------------------------------------------------------------

printf '\n== writer: %s ==\n' "$SERVER_PHP"

# phvBuildLaunchString's body is the base64_encode of the interpolated string. Pull the
# $-variables out in the order they appear, which IS the wire order.
writer_order=$(
	sed -n '/function phvBuildLaunchString/,/^}/p' "$SERVER_PHP" \
		| grep -o '\$[a-zA-Z_][a-zA-Z0-9_]*' \
		| sed 's/^\$//' \
		| awk '!seen[$0]++'
)

# The function signature lists the parameters first, so the interpolated order has to be read
# from the "launch?..." string itself rather than from the whole body.
writer_fields=$(
	sed -n '/function phvBuildLaunchString/,/^}/p' "$SERVER_PHP" \
		| tr -d '\n' \
		| grep -o '"launch?[^"]*"[^;]*' \
		| grep -o '\$[a-zA-Z_][a-zA-Z0-9_]*' \
		| sed 's/^\$//' \
		| tr '\n' ' '
)
writer_actual="launch $writer_fields"
writer_actual=$(printf '%s' "$writer_actual" | tr -s ' ' | sed 's/ *$//')

if [ "$writer_actual" = "$EXPECTED" ]; then
	pass "field order matches the contract"
else
	bad "field order" "expected [$EXPECTED] but phvBuildLaunchString builds [$writer_actual]"
fi

# ---------------------------------------------------------------------------
# 2+3. The readers. Each assigns argumentsPassed[N] / f[N] to a named variable,
#      so the index-to-name mapping can be read straight out of the source.
# ---------------------------------------------------------------------------

# Maps the reader's own variable names onto the contract's names. The readers are allowed to
# call a field whatever they like locally; what must not drift is which INDEX it comes from.
reader_expect_index() {
	case "$1" in
		world)         echo 1 ;;
		password)      echo 2 ;;
		gameDNS)       echo 3 ;;
		port)          echo 4 ;;
		phvalheimHost) echo 5 ;;
		httpScheme)    echo 6 ;;
		vanilla)       echo 7 ;;
		crossplay)     echo 8 ;;
		joinCode)      echo 9 ;;
		*)             echo "?" ;;
	esac
}

check_reader() {
	local label="$1" file="$2" accessor="$3" shift_desc="$4"
	shift 4

	printf '\n== reader: %s ==\n' "$label"

	# Each remaining argument is "contractField=localName".
	for pair in "$@"; do
		local field="${pair%%=*}"
		local localname="${pair#*=}"
		local want
		want=$(reader_expect_index "$field")

		# Find the index this reader actually takes the field from.
		local got
		got=$(grep -oE "${localname}[[:space:]]*=[[:space:]]*${accessor}\[[0-9]+\]" "$file" \
			| grep -oE '\[[0-9]+\]' | tr -d '[]' | head -1)

		if [ -z "$got" ]; then
			bad "$field" "no '${localname} = ${accessor}[N]' assignment found in $file"
		elif [ "$got" = "$want" ]; then
			pass "$field <- ${accessor}[$got]"
		else
			bad "$field" "read from ${accessor}[$got], contract says [$want] ($shift_desc)"
		fi
	done
}

check_reader "phvalheim-client Arguments.cs" "$CLIENT_CS" "argumentsPassed" "fields after this one are shifted" \
	world=worldName \
	password=worldPassword \
	gameDNS=worldHost \
	port=worldPort \
	phvalheimHost=phvalheimHost \
	httpScheme=httpScheme

check_reader "phvalheim-companion LaunchPayload.cs" "$COMPANION_CS" "f" "fields after this one are shifted" \
	world=World \
	password=Password \
	gameDNS=Host \
	port=Port \
	phvalheimHost=PhValheimHost \
	httpScheme=HttpScheme

# The three optional tail fields are read with a length guard rather than a bare index, so
# they need their own pattern. Getting the GUARD wrong is its own bug: a field at index 7 needs
# Length >= 8, and an off-by-one here turns a present field into an absent one.
printf '\n== optional tail fields (added after the format shipped) ==\n'

check_guarded() {
	local file="$1" field="$2" idx="$3" guard="$4" pattern="$5"

	if ! grep -qE "$pattern" "$file"; then
		bad "$field in $(basename "$file")" "no length-guarded read matching /$pattern/"
		return
	fi
	pass "$field read from [$idx] behind a >= $guard length guard in $(basename "$file")"
}

# Client: only field 7 (vanilla). It predates crossplay and joinCode and is not expected to
# read them -- the client gets crossplay information from the public UI, not the payload.
check_guarded "$CLIENT_CS" "vanilla" 7 8 'Length >= 8'

# Companion: fields 7, 8 and 9.
check_guarded "$COMPANION_CS" "vanilla"   7 8 'f\.Length >= 8 && f\[7\]'
check_guarded "$COMPANION_CS" "crossplay" 8 9 'f\.Length >= 9 && f\[8\]'
check_guarded "$COMPANION_CS" "joinCode"  9 10 'f\.Length >= 10 \? f\[9\]'

# ---------------------------------------------------------------------------
# 4. The transport. The argument name has to match on both sides or the
#    Companion never sees a payload and silently shows no dialog.
# ---------------------------------------------------------------------------

printf '\n== transport: the argument name ==\n'

client_arg=$(grep -oE 'CompanionArgName = "[^"]+"' "$CLIENT_CS" | grep -oE '"[^"]+"' | tr -d '"')
companion_arg=$(grep -oE 'ArgName = "[^"]+"' "$COMPANION_CS" | grep -oE '"[^"]+"' | tr -d '"')

if [ -z "$client_arg" ]; then
	bad "client argument name" "no CompanionArgName constant found in $CLIENT_CS"
elif [ -z "$companion_arg" ]; then
	bad "companion argument name" "no ArgName constant found in $COMPANION_CS"
elif [ "$client_arg" = "$companion_arg" ]; then
	pass "both sides agree on \"$client_arg\""
else
	bad "argument name" "client sends \"$client_arg\" but the Companion looks for \"$companion_arg\""
fi

printf '\n== %d checks, %d failed ==\n' "$checked" "$fail"
[ "$fail" -eq 0 ] || exit 1
exit 0
