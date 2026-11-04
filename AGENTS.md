# For AI coding assistants

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) first and follow it. It is short and complete.

Then read [`CLAUDE.md`](CLAUDE.md) for the architecture, the data model and the list of dead
tables and scripts you must not touch. The rules are the same for every assistant: Claude Code,
Codex, Cursor, Copilot or anything else.

Three things that matter most:

- One change per pull request, with a test under `dev_tools/` that fails without it.
- `bash dev_tools/run-oracles.sh --standalone` must pass before the PR is opened.
- Do not bump the version, add release notes, build images or create tags. Releases are the
  maintainer's job; the procedure is `docs/RELEASING.md`.
