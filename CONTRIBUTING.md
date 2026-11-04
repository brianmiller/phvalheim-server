# Contributing to PhValheim Server

Thanks for helping. This is the only page you need to read to contribute. It is written for
people and for AI coding assistants alike. If something here is wrong or missing, fixing this
page is itself a welcome contribution.

## The 30-second version

1. Fork the repo and branch from `master`.
2. Make one change.
3. Run `bash dev_tools/run-oracles.sh --standalone`. It must pass.
4. Open a pull request. Fill in the checklist the template gives you.
5. The maintainer reviews, merges and releases. You never build, tag or publish an image.

## Which repo?

PhValheim is three repositories. Pick the right one before you start.

| You want to change | Repo |
| --- | --- |
| The server container: admin UI, public UI, engine, mod catalogue, backups, the docs website | **phvalheim-server** (this one) |
| The launcher players install on Windows, Linux or macOS | [phvalheim-client](https://github.com/brianmiller/phvalheim-client) |
| The in-game mod shipped with every world (join flow, player roster) | [phvalheim-companion](https://github.com/brianmiller/phvalheim-companion) |

Each repo has its own `CONTRIBUTING.md` with the same shape as this one.

## Set up

You need Docker, `git`, PHP CLI, Python 3 and Node. The tests run on those four runtimes.

```bash
git clone git@github.com:<you>/phvalheim-server.git
cd phvalheim-server
bash dev_tools/run-oracles.sh --list        # shows every test and which ones need a container
```

To run the server itself, build the image and start it the way the install guide describes:

```bash
docker build -t phvalheim-server:dev .
```

Then follow [the install guide](https://brianmiller.github.io/phvalheim-server/start/install/)
using `phvalheim-server:dev` as the image. The admin UI is on port 8081, the public UI on 8080.
`dev_tools/devUp.sh` does this for the maintainer's machine and is a good example to read.

## Where the code lives

| Path | What it is |
| --- | --- |
| `container/nginx/www/` | PHP. Public UI, admin UI, `adminAPI.php`, `db_gets.php` / `db_sets.php` |
| `container/engine/` | The engine. Bash and Python: world lifecycle, mod sync, backups, cron |
| `container/engine/dbUpdates/` | Database migrations, one script per version |
| `container/games/valheim/custom_plugins/` | The Companion DLL the image bundles |
| `website/docs/` | The public documentation site. See `website/README.md` |
| `dev_tools/test-*.sh` | The tests. `run-oracles.sh` runs them |
| `docs/` | Internal design notes, release notes, validation reports. `docs/README.md` is the map |
| `CLAUDE.md` | Architecture, data model, and the tables and scripts that are dead and must not be touched |

Read the **Mods** and **Notes** sections of `CLAUDE.md` before touching anything in the mod
catalogue or the world tables. Several columns exist only as rollback records and return a
plausible zero instead of an error when read.

## Make a change

- **One change per pull request.** A fix and a refactor are two PRs.
- **Do not bump the version** in the `Dockerfile`, and do not add `whatsnew.php`, `CHANGELOG.md`
  or release-notes entries. The maintainer does that when cutting a release. Instead, describe
  in the PR what an operator or player will see differently, in one or two plain sentences, so it
  can be copied in.
- **Database changes** go in a new `container/engine/dbUpdates/dbUpdate_<version>.sh`. Ask on
  Discord which version your change will land in before you name it. The file must be
  executable (`chmod +x`), or the migration runs as nothing and nobody notices.
- **New world states or modes** need the engine, both admin label maps and the CSS rule. Grep for
  an existing mode such as `update` to find every site.
- **Do not reach for the dead code.** `tsmods`, `worlds.thunderstore_mods`, `tsSync*.sh`,
  `modLookup.sh` and friends are listed in `CLAUDE.md`. Reading them gives wrong answers quietly.

## Test it

```bash
bash dev_tools/run-oracles.sh --standalone     # what CI runs on your PR
bash dev_tools/run-oracles.sh --container      # the rest; needs a running dev container
```

Add a test for your change as `dev_tools/test-<what-it-checks>.sh`. The rules are short:

- **It must be able to fail.** Break the code on purpose, watch the test go red, then fix the
  code. A test that was never red proves nothing. CI mutates three load-bearing lines on every
  run to make sure the suite can still fail.
- **Assert on the outcome, not on a summary.** Check the file, the database row or the HTTP
  response. Never assert on the script's own "done" message.
- **Carry a control.** When you assert "X is absent", also assert something that IS present, so
  an empty result cannot pass by accident.
- If it needs a live container, put `# requires: container` in the header. If it needs a state
  no runner can create, `# requires: manual`.

## Open a pull request

The PR template is the checklist. In short:

- [ ] One change, described in plain words: what changes for an operator or a player.
- [ ] `bash dev_tools/run-oracles.sh --standalone` passes.
- [ ] A test that fails without the change.
- [ ] No version bump, no What's New, no changelog entry.
- [ ] No secrets, internal hostnames, IPs, SteamIDs or pasted logs. See below.
- [ ] What you did **not** test, stated plainly. "Built and tests pass, not run against a live
      world" is a fine answer. Silence is not.

## Rules that protect everyone

**This repository is public.** Before every commit:

```bash
git diff --cached | grep -E '^\+' | grep -niE \
  'password|secret|api[_-]?key|token|bearer|([0-9]{1,3}\.){3}[0-9]{1,3}'
```

- Never commit `.claude/settings.local.json`. It records shell commands verbatim, including
  passwords. It is gitignored. Leave it that way.
- Never paste world logs or database dumps into an issue or PR. They carry player SteamIDs.
- A force-push does not unpublish anything. A pushed secret is a disclosed secret.

**Releases are the maintainer's job.** Do not run `dev_tools/buildRcDetached.sh`, push images,
create tags or GitHub releases. The `:rc` and `:latest` image tags are live channels that real
operators pull. The procedure lives in `docs/RELEASING.md` and is there for the maintainer and
for reviewers who want to understand what happens after merge.

## Using an AI coding assistant

Claude Code reads `CLAUDE.md` automatically; Codex, Cursor and similar tools read `AGENTS.md`.
Both point here, so the assistant is working from the same rules you are. A prompt that works:

> Read CONTRIBUTING.md and follow it. Make this change: <describe it>. Add a test under
> dev_tools that fails without the change, run `bash dev_tools/run-oracles.sh --standalone`,
> and tell me exactly what you did not test.

Review what it produces against the checklist above before you open the PR. You are the author.

## Where to ask

- [Discord](https://discord.gg/8RMMrJVQgy) for questions, design discussion and "which version
  will this land in".
- [GitHub issues](https://github.com/brianmiller/phvalheim-server/issues) for bugs and feature
  requests.

PhValheim is MIT licensed. By contributing you agree your contribution is too.
