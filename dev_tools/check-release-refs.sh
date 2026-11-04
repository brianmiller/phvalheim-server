#!/bin/bash
# Release gate: everything PUBLIC-FACING that names a release must name THIS one.
#
# 2.61 shipped while the GitHub README and the landing page (website/docs/index.md, deployed to
# GitHub Pages by the Documentation workflow on every master push) still said "New in 2.59",
# and the updating guide still used `:2.59` as its example tag. Nothing in the release path
# read those files, so nothing noticed. This does. Like check-whatsnew.sh, it reads the
# version from the Dockerfile and fails the release when a reference is missing or stale.
#
# What is checked, and why each anchor:
#   README.md                       links releases/tag/v<ver>   (the "Latest" callout)
#   website/docs/index.md           "New in <ver>" callout, and ONLY that version in it;
#                                   links releases/tag/v<ver>
#   website/docs/host/updating.md   `:<ver>` as the example tag; a "**For <ver>:**" note so the
#                                   upgrade guide says what this release asks of operators
#
# Run: ./dev_tools/check-release-refs.sh        (exit 0 only when every check passes)

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKERFILE="$REPO/Dockerfile"
README="$REPO/README.md"
INDEX="$REPO/website/docs/index.md"
UPDATING="$REPO/website/docs/host/updating.md"

version=$(grep -oP 'ENV phvalheimVersion=\K.*' "$DOCKERFILE" | tr -d '"' | tr -d "'" | head -1)
if [ -z "$version" ]; then
	echo "FAIL  could not read phvalheimVersion from $DOCKERFILE"
	exit 1
fi

fail=0
check() { # label, got, want
	if [ "$2" = "$3" ]; then echo "PASS  $1"
	else echo "FAIL  $1 (got $2, want $3)"; fail=1; fi
}

check "README.md links the v$version release notes" \
	"$(grep -c "releases/tag/v$version" "$README")" 1
check "landing page carries a 'New in $version' callout" \
	"$(grep -c "New in $version" "$INDEX")" 1
check "landing page carries NO other 'New in' callout" \
	"$(grep -c 'New in [0-9]' "$INDEX")" 1
check "landing page links the v$version release notes" \
	"$(grep -c "releases/tag/v$version" "$INDEX")" 1
check "landing page links no OLDER release notes" \
	"$(grep -oE 'releases/tag/v[0-9.]+' "$INDEX" | grep -vc "v$version\$")" 0
check "updating guide uses :$version as its example tag" \
	"$(grep -c ":$version\`" "$UPDATING")" 1
check "updating guide has a 'For $version:' note for operators" \
	"$(grep -c "^\*\*For $version:\*\*" "$UPDATING")" 1

[ "$fail" -eq 0 ] && echo "OK    public references all name $version" || echo "FAIL  update the public references for $version before releasing"
exit $fail
