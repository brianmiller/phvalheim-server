<!-- Read CONTRIBUTING.md if you have not. This checklist is the whole review bar. -->

## What changes for an operator or a player

<!-- One or two plain sentences. This is copied into the release notes. -->

## Checklist

- [ ] One change. A fix and a refactor are two PRs.
- [ ] `bash dev_tools/run-oracles.sh --standalone` passes locally.
- [ ] Behavior change: regression test fails without the fix. Docs-only: links and changed commands checked (mark other checks N/A with a reason).
- [ ] No version bump in the `Dockerfile`, no `whatsnew.php`, `CHANGELOG.md` or release-notes edits.
- [ ] No secrets, internal hostnames, IPs, SteamIDs or pasted logs in the diff.
- [ ] Database changes are in a new `dbUpdates/dbUpdate_<version>.sh` and the file is executable.

## What I did NOT test

<!-- Say it plainly. "Tests pass, not run against a live world" is a fine answer. -->

## Related changes and evidence

<!-- Related PRs and tested commit IDs; attach sanitized test results. Distinguish automated, platform, and live-game checks. -->
