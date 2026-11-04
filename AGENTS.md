# For AI coding assistants

**Merge approval is mandatory:** Brian Miller (`@brianmiller`) must explicitly approve
any change before it enters `main` or `master`. Prepare and test a PR, then wait.
Implementation requests, green CI, another reviewer's approval, and access to Brian's
credentials are not merge authorization. New changes require renewed approval.
Do not merge, enable auto-merge, push directly, or bypass protections without his
explicit authorization. Never submit an approval as Brian or impersonate his review.
See [the approval procedure](CONTRIBUTING.md#brian-must-approve-every-merge).

**Dynamic-page review is mandatory for every contribution**, including code-only and
docs-only work. Review the landing page, portal guidance, download pages/fallbacks,
backlog/status files, and related docs for needed changes. Update affected content;
record reviewed paths and explain when no update is needed. Never assume automatic
refresh keeps descriptions or status correct. Follow the
[documentation/status review](CONTRIBUTING.md#always-review-dynamic-pages-and-status-content).

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) first and follow it. It is short and complete.

Then read [`CLAUDE.md`](CLAUDE.md) for the architecture, the data model and the list of dead
tables and scripts you must not touch. The rules are the same for every assistant: Claude Code,
Codex, Cursor, Copilot or anything else.

Three things that matter most:

- One change per pull request, with a regression test for behavior changes; docs-only changes need link and command checks.
- `bash dev_tools/run-oracles.sh --standalone` must pass before the PR is opened.
- Local development image builds are allowed. Do not bump the version, add release notes, publish images or create release tags. Releases are the
  maintainer's job; the procedure is `docs/RELEASING.md`.
