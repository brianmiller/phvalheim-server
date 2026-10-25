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

## Completed before the image build

- Companion Release build: zero errors/warnings; dialog layout and live-refresh state tests pass.
- Scoped status token checks: valid, wrong world/key, expired, tampered and absent; live endpoint
  accepts its world token and rejects unauthenticated requests, with no password in the response.
- Compaction: ten assertions, including tool arguments, output reserve, latest-request retention,
  paired-tool removal, bounded summary request and failed-summary excerpts.
- Hosted upload/SCM URL checks: nineteen assertions, including both protocol sequences, multipart
  completion, metadata, aborted failed upload, masked credentials and unsafe URL rejection.
- Mutation controls: bypassing token validation, disabling round compaction, or omitting upload
  abort each makes the corresponding test fail.
- Browser: real template build, Package click, ZIP download, local publish/unpublish, model pill,
  panel toggles, scroll-back during output and 390-pixel layout; no browser exceptions.
- Public GitHub import, remote comparison, fetch and fast-forward pull exercised through real jobs.
- Standalone suite: 74 files pass. Launch transport contract: 19 assertions pass. Streaming zero
  regression: 7 assertions pass. Evaluable image markers: 761 pass, none fail.

## Operator tests still required

A real Valheim client must exercise the menu across a late crossplay code, backend outage,
server restart and expired capability. The build and reflected layout/state checks cannot prove
Unity interaction or a successful PlayFab connection.

Hosted publishing has protocol-level simulated transport tests, not a real credentialed upload.
Test with each catalogue's actual team/token and a version intended for release. No public test
mod was uploaded. Private-repository authentication and a remote push also need account-level
validation; public clone/fetch/pull were exercised. No force-push operation is provided.
