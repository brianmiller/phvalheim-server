# Contribute to PhValheim

Help improve the server, launcher, or in-game Companion. Bug fixes, documentation,
reproductions, and testing reports are all welcome. These instructions are written
for contributors **and their LLM coding agents**.

## One link to give your coding agent

Share this page: **<https://brianmiller.github.io/phvalheim-server/contribute/>**

Your agent can read the instructions below directly. If it needs plain text, use
the [raw Markdown version](https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/website/docs/contribute.md).
Both versions come from the same source file; no login or JavaScript is needed to
read the instructions.

Copy this prompt into your coding agent and add your task:

```text
Read https://brianmiller.github.io/phvalheim-server/contribute/ and follow its
contributor workflow. Read the selected repository's AGENTS.md, CONTRIBUTING.md,
and CLAUDE.md before editing. Follow the linked references for the affected area.

Implement the task on a separate branch, using isolated test data. Run the
applicable checks and prepare a pull request with evidence and limitations.
Always review dynamic pages, landing/portal content, download fallbacks, backlog,
and related docs for needed changes. Update affected content and list reviewed
paths, updates, or specific reasons no changes are needed in the PR.
Wait for Brian Miller's explicit approval before merging to main or master.
Do not publish releases or deploy changes.

Task: [issue URL or description]
```

## Choose a repository

| Change | Repository and contributor guide | Default branch | Agent entry point (plain text) |
| --- | --- | --- | --- |
| Server container, web UI, engine, backups, or this website | [Server](https://github.com/brianmiller/phvalheim-server/blob/master/CONTRIBUTING.md) | `master` | [Server AGENTS.md](https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/AGENTS.md) |
| Windows, Linux, or macOS launcher | [Client](https://github.com/brianmiller/phvalheim-client/blob/master/CONTRIBUTING.md) | `master` | [Client AGENTS.md](https://raw.githubusercontent.com/brianmiller/phvalheim-client/master/AGENTS.md) |
| In-game join flow, UI, or player telemetry | [Companion](https://github.com/brianmiller/phvalheim-companion/blob/main/CONTRIBUTING.md) | `main` | [Companion AGENTS.md](https://raw.githubusercontent.com/brianmiller/phvalheim-companion/main/AGENTS.md) |

For a new mod of your own, start with [Make your first Vibe](vibes/first-mod.md).
The workflow below is for changes to PhValheim itself.

## Instructions for the contributor and agent

1. **Choose a scoped task.** Read the [community backlog](https://github.com/brianmiller/phvalheim-server/blob/master/docs/BACKLOG.md)
   or propose a change in an issue. The contributor should comment on the issue
   before starting so work can be coordinated. Use the issue's acceptance criteria.
2. **Work in a fork and a separate branch.** Start from the current default branch.
   Preserve existing local changes. Keep one focused change per PR.
3. **Read the repository instructions.** Read `AGENTS.md`, then `CONTRIBUTING.md`
   and `CLAUDE.md` in the checkout, plus the references for the affected code.
   Do this explicitly: tools differ in which files they discover automatically.
   The repository guides contain the detailed setup and test commands; use their
   current versions rather than guessing dependencies or APIs.
4. **Set up an isolated environment.** For the server, follow the
   [development recipe](https://github.com/brianmiller/phvalheim-server/blob/master/docs/DEVELOPMENT.md).
   It builds your checkout and uses separate data with web ports bound to localhost.
   Use disposable worlds and data. Client and Companion prerequisites are in their
   contributor guides; Companion needs your own prepared game references.
5. **Implement and verify.** Run the checks required for your type of change.
   For behavior changes, add a meaningful regression test and show that it detects
   the original problem. Docs-only changes need link and changed-command checks,
   not a game installation or new runtime tests.
6. **Prepare the PR.** Complete the mandatory [dynamic-page and status review](#always-review-dynamic-pages-and-status-content) below. Explain what changes for the user, link the issue, and report
   the commands run and their results. Identify the tested commit and any related
   repository revisions. Use the repository's PR template.
7. **Wait for Brian's approval.** Brian Miller (`@brianmiller`) must explicitly approve
   the revision before it enters `main` or `master`. Changes after approval require
   renewed approval. Green CI, another reviewer, an implementation request, and
   access to Brian's credentials do not authorize merging. Do not merge, enable
   auto-merge, push directly, or bypass protection without his explicit authorization.
   Never submit a review pretending to be Brian. Once Brian explicitly authorizes
   a merge, an agent may perform that merge on his behalf.

## Always review dynamic pages and status content

**Every contribution must include this review**, even code-only or docs-only changes.
Always inspect the landing page, portal/admin guidance, download/install pages and
fallback links, README/version references, contributor guides, backlog files, and
related issue status for needed changes. Include affected content in other repositories.
Reviewing these areas is required; editing content that is still accurate is not.

Update affected content with the change. In the PR, list the pages/files reviewed,
updates made, and specific reasons when no changes are needed. Identify linked
cross-repository documentation PRs or follow-ups with an owner, and report any checks
you could not perform. Do not use an unexplained "N/A" to skip the review.

Check the source and rendered output when dynamic behavior changes. A website rebuild
does not rewrite descriptions, a release API does not update static fallbacks, and
closing an issue does not update a backlog file. Validate changed links, builds, and
page behavior as appropriate; automated checks cannot establish that every description
is accurate.

Keep **implemented**, **merged**, **released**, and **deployed** status distinct.
Never describe unreleased work as available or mark a backlog task complete without
meeting its acceptance criteria. Keep published instructions accurate for released
behavior, label future behavior clearly, and record release-time updates for Brian.
This review does not authorize a release, deployment, version bump, or issue closure.

## Report what was actually tested

Separate automated checks, platform checks, and live-game testing. A stubbed Steam
or Valheim process is not a live gameplay test. Missing dependencies, unavailable
platforms, and skipped checks are **unverified**, not passes. If your agent cannot
access the linked files, run commands, or open a PR, it should state that limitation
and provide the next steps instead of claiming completion.

Review your agent's diff and results before submission. You remain responsible for
your contribution. Release version bumps, official packages, image publishing,
and production deployments belong to the maintainer's release workflow.

## Keep private material out of contributions

Do not commit credentials, certificates, personal data, private server details,
or game DLLs. Sanitize diagnostic evidence before sharing it.

Report vulnerabilities privately using **Security → Report a vulnerability** in the
affected repository: [Server](https://github.com/brianmiller/phvalheim-server/security/advisories/new),
[Client](https://github.com/brianmiller/phvalheim-client/security/advisories/new), or
[Companion](https://github.com/brianmiller/phvalheim-companion/security/advisories/new).
Do not post vulnerability details in public issues or Discord.

For ordinary contributor questions, [join Discord](https://discord.gg/8RMMrJVQgy).
