# Releasing PhValheim Server

Build, verify inside the image, tag, publish.

This file exists because the same handful of mistakes kept recurring, each one caught late or
by the maintainer rather than by the person shipping. **Every rule below is here because it
was broken on a real release** — the parenthetical is which one. They are not style
preferences; each cost a bad build, a wrong image on Docker Hub, or a false all-clear.

It is written to be followed by a person or by an agent. Claude Code users get it as the
`phvalheim-release` subagent, which reads this file — there is only ever one copy of the
procedure, so the two cannot drift apart.

**Before anything else, if you are contributing:** this repository is **public**. Read
[Secrets and safety](#secrets-and-safety) at the bottom before your first commit.

# The one-paragraph version

Build detached with `dev_tools/buildRcDetached.sh`. It builds, pushes `:rc`, then greps the
markers **inside the pushed image** and only promotes extra tags if that says
`IMAGE VERIFY OK`. Your job is to make sure the markers actually cover the release you are
shipping, that the user-facing notes describe what shipped, and that nothing reaches
`:latest` that the user has not watched run.

# Before you build

**1. Add What's New entries.** `container/nginx/www/includes/whatsnew.php`, keyed by version.
`dev_tools/check-whatsnew.sh` fails the release without them. Run it. A missing entry is
invisible at runtime — the modal just shows nothing — so the gate is the only thing that
catches it. (2.42)

**2. Add verify markers for THIS release to `dev_tools/buildRcDetached.sh`.** Do this even
if the code is finished and tested. A release with no markers verifies nothing, and the
script will still print `IMAGE VERIFY OK` — it is only checking the previous releases'
markers, all of which still pass. 2.47 shipped three release candidates that way before
anyone noticed the file had zero 2.47 checks in it.

Good markers:

- **Prefer negatives.** "The new function exists" passes on an image that also still has the
  old broken path beside it. Assert the old thing is *gone*.
- **Anchor on something only the real code has.** Counting a word that also appears in a
  comment four lines up gives a number that is right for the wrong reason.
- **Count by mode, not by name.** `grep -c "worldMods.py --world"` is a running total that
  every future release bumps; `grep -cE "--world .+(--resolve|--plan)"` asserts the specific
  calls the release cares about.
- **Each marker lives under the release that owns it.** When your change moves an older
  release's count, do NOT relax that older number. Split it: leave the old block asserting
  its own thing, and assert the new total in your block. Otherwise the old line stops
  asserting anything and future drift reads as unexplained. (2.47)

**3. Dry-run the markers against the repo tree first**, before spending a build. Map
`/opt/stateless/engine` → `container/engine`, `/opt/stateless/nginx` → `container/nginx`.

**This dry run cannot catch a wrong image path.** Cron entries live at `/etc/cron.d/` in the
image but `container/cron.d/` in the repo, so a check pointing at
`/opt/stateless/cron.d/` passes the dry run and fails the real verify. When a marker fails,
**check your probe before you believe the image is broken.** (2.47)

**4. The `sh -c` verify payload must contain exactly ONE apostrophe** — the opening quote.
One more anywhere inside, *including in a comment*, closes the string early; the rest of the
verify silently never runs and the leftover greps execute against the host. `bash -n` cannot
catch it because the result is still valid shell. The script self-checks this and refuses to
build; do not defeat it.

**5. Run the tests, and make sure they are oracles.** A test that answers the same whether or
not the bug is present is worse than none — it is a false all-clear. Mutation-check the new
ones: reintroduce the bug and confirm the suite goes red. Report honestly when a mutation
does *not* fail — it usually means the two versions are genuinely equivalent, not that the
test is good.

**6. Confirm which tests already fail on a clean tree** (`git stash`, run, `git stash pop`)
before reporting a failure as yours or as new.

**7. CHANGELOG entry must describe what SHIPPED**, not the design you had mid-release. A
section drafted early and left alone will document internals you replaced. Re-read it against
the final code. Publishing a changelog that documents removed internals is worse than
publishing none. (2.47)

**8. Version bumps are the user's call.** Do not decide a release is 2.48 instead of 2.47.
Ask.

# Building

```bash
rm -f /tmp/phvalheim-rc-build.log
setsid nohup dev_tools/buildRcDetached.sh > /dev/null 2>&1 &
```

**Always detached.** A build started as a tracked background task dies with
"context canceled" when the agent session recycles. (2.39)

Poll `/tmp/phvalheim-rc-build.log` for `=== done`. Then read:

- `IMAGE VERIFY OK` — the ONLY honest signal. The verify reports by echo, not exit status,
  deliberately, so the log always shows every marker.
- Every `(want N)` against its actual. Do not skim.
- The digest.

**`:rc` is pushed BEFORE the verify runs.** A failed verify does not unpush it. Say so if it
happens.

# Tagging and publishing

**Never `docker tag` a tested `:rc` into a version or into `:latest`.** Rebuild instead.
Retagging ships whatever `:rc` happened to contain, which is not necessarily the commit you
mean — finishing the docs changes files inside the image. (2.45)

```bash
EXTRA_TAGS="2.47"          setsid nohup dev_tools/buildRcDetached.sh >/dev/null 2>&1 &   # pre-release
EXTRA_TAGS="2.47 latest"   setsid nohup dev_tools/buildRcDetached.sh >/dev/null 2>&1 &   # full release
```

Same source, same build, all tags on one digest. The script pushes extra tags only after
grepping `IMAGE VERIFY OK` out of its own log. Confirm the digests match afterwards.

⚠️ **`dev_tools/promoteRCtoLatest.sh` does a bare retag and must not be used for a release.**
It survives only for hand-driven local work.

Then:

```bash
git tag -a vX.YZ -m "..." && git push origin vX.YZ
gh release create vX.YZ [--prerelease] --title "vX.YZ — <what changed>" --notes-file <file>
```

**Pre-release unless the user has watched the risky path run.** A pre-release does not move
`:latest`, so operators who pull `latest` are unaffected. Lead the notes with what is
untested and whether the feature is off by default.

Write notes to a file, not inline. Title style: `vX.YZ — <plain-language summary>`.

# Rules that are not about releasing but will bite you here

**After `docker cp` into a container, `chown phvalheim:phvalheim`** — not root. The tools run
as the `phvalheim` user and root-owned files with no world-read bit give "Permission denied"
that looks like a product bug. Restart `php-fpm8` (not `php-fpm`) after copying any `.php`,
or OPcache serves the old compile.

**Test as the user the code runs as.** steamcmd failing for lack of a writable `HOME` passed
every test and every manual run because they were done as root.

**Absence of data is not a passing result.** A `0`/false default silently doubles as a real
answer. Anything that can fail to run needs three states, and the unknown one belongs in the
schema, not reconstructed in the UI. This shipped three separate times in one release.

**Migrations are object-by-object idempotent** (`addColumn`), because the RC ships first and
later revisions re-run the same file. Backfill nothing you cannot actually know — a guessed
value wearing the costume of a fact is worse than NULL.

**Never write a literal NUL byte into source.** grep then treats the whole file as binary and
silently reports nothing, so searches for code you just wrote come back empty.

**Clean up after yourself on shared machines.** Remove synthetic test rows, test worlds and
downloaded artifacts from `phvalheim-dev` and production. Work left running has been reported
back as a product bug.

# Reporting

State the digest, the tag(s), and which of them moved. Distinguish "verified inside the
image" from "the tests pass" — they are different claims.

**Do not report a mid-flight status as a resolution.** If a build is still running, say it is
running.

**Say plainly what is still untested.** Check `docs/NEXT-STEPS-*.md` for the release you are
shipping — it records which paths have actually been exercised and which have only been
reasoned about. Anything still untested belongs in the release notes, not just in a message
to the maintainer, and is usually the reason to ship `--prerelease`.

# Secrets and safety

**This repository is public.** Everything below is enforced by `.gitignore`, but `.gitignore`
only helps for paths it already knows about.

- **Never commit `.claude/settings.local.json`.** It records approved shell commands verbatim,
  which routinely includes hostnames, usernames and plaintext passwords. It is gitignored, and
  the ignore rule lives in the repo rather than a global `~/.config/git/ignore` on purpose —
  a global rule does not travel with a clone, so it would protect you and nobody else.
- **API keys live in `secrets.cfg`, outside the repo**, sourced by `dev_tools/deployRc.sh`.
  Never inline a key into a script, a test, or a command you paste into an issue.
- **Scrub internal IPs, hostnames, container names and usernames** from code, comments, commit
  messages, test fixtures and release notes. Grep your own diff before committing:

  ```bash
  git diff --cached | grep -E '^\+' | grep -niE \
    'password|secret|api[_-]?key|token|bearer|([0-9]{1,3}\.){3}[0-9]{1,3}'
  ```

- **Logs and database dumps are not safe to paste.** World logs carry player SteamIDs.
- **A force-push does not unpublish anything.** Once a secret is pushed, treat it as disclosed
  and rotate it — old commits stay retrievable by SHA until GitHub garbage-collects them, and
  forks and caches may already have it.
