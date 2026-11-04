<!-- Read CONTRIBUTING.md if you have not. Brian must approve the reviewed revision before merge. -->

## What changes for an operator or a player

<!-- One or two plain sentences. This is copied into the release notes. -->

## Checklist

- [ ] I understand that Brian Miller (`@brianmiller`) must approve the reviewed revision before merge; this checkbox is not approval.

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

## Brian's merge approval

Pending Brian's review. Green checks and this template do not authorize merging.
Any changes after approval require Brian to approve the new revision.

<!-- Do not claim approval on Brian's behalf. Link his actual review when available.
For PRs opened under Brian's account, follow CONTRIBUTING.md's self-approval procedure. -->
