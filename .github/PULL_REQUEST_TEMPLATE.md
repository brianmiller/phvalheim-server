<!-- Read CONTRIBUTING.md if you have not. Brian must approve the reviewed revision before merge. -->

## What changes for an operator or a player

<!-- One or two plain sentences. This is copied into the release notes. -->

## Checklist

- [ ] Dynamic pages and status content reviewed for needed changes, even for code-only/docs-only work; paths, updates or reasons for no changes, and validation are recorded below.

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

## Documentation and status review (required for every PR)

<!-- Review landing-page/website content, portal/admin guidance, download/install pages
and fallbacks, README/version references, contributor guides, GitHub Issues for work
status, including affected content in other repositories. Review is always required;
edit only what needs changing. Do not leave this blank or write unexplained N/A. -->

- Affected GitHub issues reviewed (links, acceptance criteria, status/evidence or reason none apply):
- Pages/files reviewed:
- Updates made, or specific reasons no changes are needed:
- Validation and any unverified checks:
- Related documentation PRs or follow-ups (owner and release/deployment dependency):

<!-- GitHub Issues is the source of truth for work tracking; do not maintain Markdown task lists.
Keep implemented, merged, released, and deployed status distinct. Do not advertise
unreleased behavior as available or claim an issue is complete without evidence. -->

## Brian's merge approval

Pending Brian's review. Green checks and this template do not authorize merging.
Any changes after approval require Brian to approve the new revision.

<!-- Do not claim approval on Brian's behalf. Link his actual review when available.
For PRs opened under Brian's account, follow CONTRIBUTING.md's self-approval procedure. -->
