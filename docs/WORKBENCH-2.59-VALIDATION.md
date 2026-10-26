# 2.59 workbench and Companion validation

## Implemented

Companion 1.0.6 refreshes connection status using a world-scoped capability appended to the launch
payload. The first ten fields and the client application's pass-through remain compatible.
Capabilities expire after at most 24 hours. The status endpoint returns no password. Polling is
asynchronous, menu-only, with a ten-second request timeout and retry delay. The dialog updates
its join code and port and reports readiness; crossplay Connect waits for a missing code.
Worlds must update and players must sync their client to receive the DLL.

Vibes gains local publish/unpublish, ZIP downloads, hosted publishing settings, GitHub/GitLab
import, remote status/fetch/fast-forward pull/push, collapsible panels, a searchable model field,
activity in chat, scroll-back, Hugin animation, estimated context and provider token totals.
Compaction bounds summary inputs and runs at tool boundaries without orphaning call results.
Context estimates are deliberately conservative, not a model-specific tokenizer. Configure the
budget to fit the selected model. Small budgets that cannot hold the system/tools/latest request
fail with actionable feedback rather than silently discarding that request.

## Research

- [Thunderstore package format](https://wiki.thunderstore.io/mods/creating-a-package)
- [Thunderstore CLI upload protocol](https://github.com/thunderstore-io/thunderstore-cli)
  (`Models/PublishModels.cs`, `API/ApiHelper.cs`, `Commands/PublishCommand.cs`).
- [Hexium package format](https://hexium.gg/packaging)
- [Hexium API reference](https://valheim.hexium.gg/api/docs/), backed by
  [its OpenAPI document](https://valheim.hexium.gg/api/openapi.json).

Both catalogues accept the same ZIP structure, so the two download links serve the same archive.
Hosted uploads use initiate-upload, chunk PUTs with MD5/ETag, finish-upload, then submit. Tokens
are sent only to the selected API origin; signed storage URLs receive no API credentials.
Settings require the corresponding team and token. Each version is immutable upstream.

## Completed checks

- Companion Release build: zero errors/warnings; dialog layout and live-refresh state tests pass.
- Scoped status token checks: valid, wrong world/key, expired, tampered and absent; live endpoint
  accepts its world token and rejects unauthenticated requests, with no password in the response.
- Compaction: ten assertions, including tool arguments, output reserve, latest-request retention,
  paired-tool removal, bounded summary request and failed-summary excerpts.
- Hosted upload/SCM URL checks: nineteen assertions, including both protocol sequences, multipart
  completion, metadata, aborted failed upload, masked credentials and unsafe URL rejection.
- Mutation controls: bypassing token validation, disabling round compaction, or omitting upload
  abort each makes the corresponding test fail. Reverting the completion-scroll fix also makes
  the browser test fail.
- Browser: real template build, Package click, ZIP download, local publish/unpublish, model pill,
  panel toggles, scroll-back during output and after session refresh, and 390-pixel layout;
  no browser exceptions.
- Public GitHub import, remote comparison, fetch and fast-forward pull exercised through real jobs.
- Standalone suite: 74 files pass. Launch transport contract: 19 assertions pass. Streaming zero
  regression: 7 assertions pass. Evaluable image markers: 764 pass, none fail.

## Initial image and harness integration

The initial validated runtime was built from server commit `740665d7`, with Companion commit `511d77b`.
The `:dev` registry digest is
`sha256:fdcaf654467382358cf4008064ebc136c3a9d652f3815ec004bf2fcefca5020b`.
Verification inside that image reports `IMAGE VERIFY OK`; Docker Scout reports zero critical
or high findings. The development container runs image `6a025f4f067c` on its original volume.

`test-vibes-forge-loop.sh` drives the real harness and OpenAI-compatible streaming adapter
through nine file reads, a real summary request, automatic in-turn compaction, and a final reply.
All seven integration assertions pass, including usage accounting and output headroom on every
model request. It creates its own provider and project and removes both afterward.

## Catalogue toggle regression

Commit `740665d7` fixes a saved-filter mismatch and avoids reconstructing the catalogue's
thousands of HTML rows on every toggle. Both world pickers attach source/selection metadata to
cached DataTables rows; changing a catalogue redraws the filtered rows while keeping the search
and ordering. Buttons derive their pressed state from the saved filter and confirm changes in
an accessible status line. Selected mods remain visible, consistent with the existing picker
contract. Attempting to hide the last catalogue explains how to enable another first.

`test-catalogue-toggles.js` passes 32 assertions across Edit World and New World. It covers a
saved Thunderstore-only filter, each of the three sources, retained selection, rapid double
clicks and preserved query/order. Replacing the editor with the original code makes this test
fail. Additional affected checks pass: multi-source picker 22 assertions, All tab 11, picker
position 11. The standalone bucket remains 74/74.

The full container run on the preceding runtime image exercised 87 test files: 82 passed and
five needed harness updates. Three assumed only two catalogues; their checks now compare the
actual API catalogue definitions, including Vibes. The rebuild-badge test now reads the fresh
operation's outcome rather than a historical process status. The isolated Vibes UI test now
uses the scratch container's admin port (18081), rather than its public port (18080). All five
passed their corrected reruns. One catalogue-sync rerun briefly hit the development database's
connection limit; its clean rerun passed all 23 assertions, including live log growth and no
browser errors. The installed workbench browser oracle and the real streaming Forge oracle
also passed on the final pushed image. After recreating the development container on that
exact image, catalogue toggles (32 assertions), the installed-workbench browser workflow and
the streaming Forge integration (7 assertions) all passed again. Only `:dev` moved; release
channels remain on 2.58.

## Operator tests still required

A real Valheim client must exercise the menu across a late crossplay code, backend outage,
server restart and expired capability. The build and reflected layout/state checks cannot prove
Unity interaction or a successful PlayFab connection.

Hosted publishing has protocol-level simulated transport tests, not a real credentialed upload.
Test with each catalogue's actual team/token and a version intended for release. No public test
mod was uploaded. The live GitHub import/push/fetch/pull check described below now verifies
actual GitHub write access. Real private-repository imports and GitLab hosted creation/write permissions still
depend on the operator's account and token; the automated SCM fixture requires authentication
on private repositories. No force-push operation is provided.

## Release preparation: SCM end-to-end regression

`dev_tools/test-vibes-scm-e2e.sh` boots a disposable server from the exact image under test,
borrowing only the installed Build Pak and harvested libraries through read-only mounts. It
runs the real browser, API, detached job runner and Build Pak Git against an isolated HTTPS
GitHub/GitLab API fixture backed by real `git-http-backend` repositories. The fixture checks
authentication on every private request, uses a temporary trusted CA, generates fresh test
credentials and journals request paths without credentials. It never writes to hosted accounts.

The matrix covers authenticated private and anonymous public imports; Settings connection tests;
GitHub user/organization and GitLab personal/subgroup repository creation; first and repeated
pushes including annotated tags; current-branch and non-`main` imports; ahead/behind counters;
browser Fetch/Pull/Push; dirty and diverged worktrees; detached HEAD; unsafe and missing URLs;
symlinks and untrusted import metadata; duplicate identities; job exclusion; reload recovery;
mobile layout; credential masking and absence from Git configuration and job logs; and temporary
checkout cleanup. API fixtures follow the
[GitHub repository API](https://docs.github.com/en/rest/repos/repos) and
[GitLab project API](https://docs.gitlab.com/api/projects/).

The first run reproduced a real failure: first-publishing an imported `master` repository tried
to push a nonexistent `main` ref. First push now uses `HEAD:refs/heads/<current branch>` plus
tags, and its status text describes the current branch. The regression failed on the original
image and passes with the fix. In-image markers assert the new refspec and absence of the old
hardcoded branch. The actual application's connection helper also authenticated against GitHub
and the available self-hosted GitLab account without storing their credentials in test settings.

The follow-up runtime is built from commit `d59346fd`; later commits extend tests only.
The `:dev` registry digest is
`sha256:32103daedb9f2d6b7510142ab215b344dfdf9b453655a15e3a4cdd96c1ad8b5a`,
image `62977ae275d1`. It reports `IMAGE VERIFY OK`, including both new SCM markers, and zero
critical/high Docker Scout findings. The development container runs that exact image on its
original volume.

On that image, all **58 SCM assertions** pass, including import of an actual template repository,
compilation, packaging and ZIP download. Standalone tests pass **74/74**, cross-repository tests
**4/4**, and the marker predictor reports **766 pass, zero fail** (231 image-only or otherwise
unevaluable markers are explicitly skipped; the actual image gate passes). The original-image
full run also completed **88/88 with zero failures or unrunnable tests**. The expanded container run on the new image completed **88 passed, one failed, zero unrunnable**.
The sole failure was `test-proposal-ui.sh`, which timed out at page navigation waiting for
`networkidle`. Its corrected rerun passed all **20 assertions** (11 notice checks and nine
proposal/apply checks). All **89 current container test files therefore have passing results
on the final image**; this is the full run plus that focused rerun, not a claim of a single
89/0 run after the harness correction. Across the three automated buckets, **167 files** have
passing results. The one historical pre-2.53 comparison remains explicitly manual and is not
counted as passed.

The proposal driver now waits for DOM readiness and the visible Hugin control, consistent with
[Playwright's page-readiness guidance](https://playwright.dev/docs/api/class-page#page-goto).
Its fresh fixture also supplies the required server settings: otherwise a delayed settings
prompt covers Apply. The corrected test still proves that no world changes before consent,
Apply updates the database, the applied card settles, and Apply cannot be clicked twice. These
are test-harness changes only; the runtime image is unchanged.

Logs on the development host:

- `/tmp/phv-release-e2e-container.log`: original-image full run, 88/0.
- `/tmp/phv-release-e2e-container-final.log`: final-image full run, 88/1/0.
- `/tmp/phv-release-e2e-proposal-recheck.log`: corrected proposal workflow, 20/0.
- `/tmp/phv-release-e2e-scm-image.log` and `.oracle-logs/test-vibes-scm-e2e.sh.log`:
  independent and integrated final-image SCM runs, each 58/0.
- `/tmp/phv-release-e2e-github-live.log`: live GitHub workflow, 7/0.
- `/tmp/phv-release-e2e-standalone-final.log` and `/tmp/phv-release-e2e-crossrepo-final.log`:
  74/0 and 4/0.
- `/tmp/phvalheim-rc-build.log`: final pushed-image verification and security scan.

A separate live GitHub check used the actual application import job and synchronization helper
against the Companion repository: import, authenticated push to a temporary branch, fetch of
an upstream commit, and fast-forward pull all passed. The branch and imported project were
removed afterward, and the Companion `main` SHA was unchanged (**7 assertions**). Credentials
were supplied to the helper through stdin and never saved to test settings. No GitLab hosted
repository was changed: the available project-scoped bot belongs to a separate project. Its
actual connection test passed; write operations use the authenticated HTTPS fixture above.


At completion the original `StaminaWhisper` project remains, no Vibes jobs or worlds are running,
and the disposable SCM, proposal and CSS-probe containers are removed. Only `:dev` moved;
`:rc`, `:latest` and `:2.58` retain their existing 2.58 digest.

## Post-release issue #92: Database Browser NULL handling

Reproduced on the published 2.59 image (digest `0a35da5335c399ce029a72618dfaaf55300fe81c1b754db27ac2793ed08aca95`)
using the actual browser grid and a disposable table created with `CREATE TABLE ... LIKE worlds`.
Changing only `trophytheelder` from 0 to 1 while `update_phase_at` was NULL returned HTTP 500:
`Incorrect datetime value: '' for column 'update_phase_at' at row 1`.
The grid submitted the other selected fields too, replacing the NULL timestamp with `''`.
No player/world records were changed for this reproduction.

The fix uses GridPHP's documented `isnull` column option, enabled from live schema metadata
for nullable date/time, numeric and JSON columns. It does not weaken strict SQL mode or change
the schema, the bundled grid library, trophy telemetry or empty-string text semantics.

`dev_tools/test-database-browser-null.cjs` drives inline and dialog edits in Playwright, bulk
edits through the same session and real endpoint, and independently checks MySQL results.
All 15 checks passed with the fix: full-row trophy edit, NULL fields, retained dates, zero,
empty text, clearing values, dialog/bulk saves and rejection of malformed/required dates.
Restoring the old grid page made this same suite fail at its first save (the full-field case
encounters `date_deployed` before `update_phase_at`), proving it detects the regression.
Fixture tables are dropped in `finally`. The test is registered with the container runner.

The issue's initial missing-trophy observation is separate: Companion's `HungHeads` hook is
on `ItemStand.DelayedPowerActivation`, with `TrophyTheElder` registered by the server. A kill
alone does not trigger that hook. This task does not claim a live Unity/Steam trophy interaction
was tested or that an independent trophy-reporting defect was reproduced.
