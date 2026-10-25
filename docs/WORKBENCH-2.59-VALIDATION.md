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

## Image and harness integration

The final runtime is built from server commit `740665d7`, with Companion commit `511d77b`.
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
mod was uploaded. Private-repository authentication and a remote push also need account-level
validation; public clone/fetch/pull were exercised. No force-push operation is provided.

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

Final artifact and whole-suite results for this follow-up will be recorded after the image build.
