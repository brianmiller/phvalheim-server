# 2.60 — development validation

## 100-round defaults and easier controls — 2026-10-28

This supersedes the prior Vibes dev candidate below. The default is 100 rounds, still
configurable from 1 to 200. Turn and response defaults remain 15 minutes. Saved values
are preserved, including 40 rounds; Standard followed by Save limits adopts the new defaults.

Turn limits now has its own Settings tab and a shortcut beside Send. Times are edited in
minutes, with Standard (100 / 15 / 15), Long task (200 / 30 / 15), and Slow model
(100 / 60 / 30) presets: rounds / turn minutes / response minutes. Presets fill fields but
do not save until requested. The panel explains the effective wait when the turn is shorter.
Changes apply to future turns across Vibes; running turns retain their original limits.

A regression run exposed a cURL timer boundary: a request can time out just before PHP's
wall-clock comparison, leaving a generic error instead of the limit reason. Actual cURL
timeouts now receive a 10-millisecond comparison tolerance; normal progress checks and
other transport failures do not. A connection timeout well before the budget remains a
transport failure. Both boundary tests fail against the prior transport source.

- Current dev digest: `sha256:c2857341b9feabdbb5904fa3cf1f0f418ae44541aad78356c2d7ac57774e772e`
- Image ID: `sha256:953bcc5242eeb597c73a9e0bf02fab63299ea05025ee92112621f0eb5d026f6c`
- Registry digest independently verified. Local dev recreated with original data volume,
  port bindings and development login setting; six changed runtime files byte-identical.
  All five services running, no active worlds/jobs before recreation.
- Image verification passed, including 100-round/default, separate-panel, minute-field and
  timeout-precision markers. All 952 numeric printed expectations match; Scout critical/high zero.
- Settings merge: 34 checks passed. Browser: 26 checks passed, including every preset,
  explicit save, preserving other settings, minute conversion, exact round-trip of existing
  31/61-second values, mobile bounds, composer shortcut and stopped-session recovery.
  Browser tests restore prior agent settings. Desktop and mobile screenshots reviewed.
- Full timeout/round-limit fixture: 41 checks passed, including real 30-second cutoffs.
  The earlier 32/1 quick run is retained as evidence of the timing issue, not counted as a pass.
- Standalone suites: 74 passed, zero failures. Zero-delta streaming regression passed.
  Predictor: 791 passed, zero failures, 232 image-only checks skipped. Syntax/whitespace passed.
- No client changes, live model session, or live gameplay test. Existing config/launcher
  evidence below remains unchanged. Independently checked rc/latest remain at 2.59.

Evidence: `/tmp/phv260-limits-easy-{backend-image,ui-image,standalone,predict-final,mutation,zero}.log`,
`/tmp/phvalheim-rc-build.log`, and `/tmp/phv260-turn-limits-{desktop,mobile}.png`.
Private dev snapshot: `/tmp/phv260-limits-easy-dev-backup/` (0700 directory, files 0600).

## Vibes limits update — 2026-10-28

The current dev candidate adds separate model-response timing, remaining-turn enforcement
during model calls and compaction, cancellation during silent requests, and distinct
round/time/Stop messages. Completed file writes survive a stop; partial interrupted tool
calls are discarded. No automatic build or commit starts after a limit or cancellation.
Tools retain their own bounded timeouts; turn time is checked between tools.

- Current registry digest: `sha256:99f225356f02e3b4a9f0b750ff586c7e8c68cf6cb8345f67c4c725083dbd0f42`
- Image ID: `sha256:af5af4911e86420b0db2ce6499e7f752c22b2455ca5e72fdbdd2ee877acb28fa`
- Registry digest independently inspected. Local dev uses this image and its original
  data volume; all seven changed runtime files match the checkout byte for byte. Five
  services are running. No worlds or Vibes jobs were active before recreation.
- `IMAGE VERIFY OK`, including all four new limits markers; Scout critical/high: zero.
  The older join-code marker's gate correctly expects three; its stale printed “want 4”
  label was corrected to match the existing gate. No verification condition was relaxed.
- Browser: 16 checks passed, including persistence, range rejection, preserving independent
  controls, mobile visibility, stopped status after reload, and a later completed turn.
  Settings were restored. Transcript fixtures were browser-local; no external model ran.
- Limits/transport: 37 checks passed under the final image's PHP runtime, loading the
  byte-verified source. Coverage includes all three adapters, partial streams, silent
  cancellation, shared retry deadlines, initial compaction, saved file preservation,
  last-round completion, and real 30-second response/turn cutoffs. A ready Build Pak
  fixture ensures the stopped-turn auto-build check would detect an unwanted epilogue.
- Existing Forge integration: seven checks passed on the running image, including nine
  real tool calls through a deterministic OpenAI-compatible fixture, automatic compaction,
  and the final answer. Disposable provider/project records were removed by the test.
- Standalone regression: 74 suites passed; marker prediction: 787 passed, zero failed,
  232 image-only checks skipped. PHP/JS/shell syntax and whitespace checks passed.
- Mutation control: removing the interrupted-HTTP response guard made the partial Gemini
  response test fail as intended. The shipping source was not mutated.

Limits and provider tests use controlled HTTP responses, not a live Gemini account. Brian
reported the affected model as “Flash 3.8”; its exact API ID and original transcript were
not supplied. The reported message establishes a round stop, not a timeout or its cause.
No client package changed. No live gameplay was tested. Independently inspected `:rc` and
`:latest` remain at the 2.59 digest below; no stable or public prerelease promotion occurred.

Evidence: `/tmp/phv260-vibes-{limits-image,ui,forge-image,standalone,predict,mutation,zero}.log`
and `/tmp/phvalheim-rc-build.log`. The private pre-recreation snapshot is under
`/tmp/phv260-vibes-dev-backup/` (0700 directory, 0600 files), outside the repository.

## Initial config delivery validation — 2026-10-27

Validated on 2026-10-27 for Brian's requested development test. The server image was
built and pushed to `theoriginalbrian/phvalheim-server:dev` and the local
`phvalheim-dev` container was recreated with its existing `phvalheim-dev-data` volume.
All seven existing worlds remain stopped. No production deployment or stable release.

- Registry digest: `sha256:7c7c21eeb279b3091d5f760aea31e076647070e38318d419a17f9793040c4a9f`
- Image ID: `sha256:1799def298dce759a8dbb95cdbe88a4af1a278a6ac9ede635f22eda51d3a6f1b`
- Registry inspection independently confirmed the digest. The 16 changed runtime files
  match the running image byte for byte. All five services are running.
- In-image verification: `IMAGE VERIFY OK`, including the 2.60 gates.
- Docker Scout: zero critical/high findings. `:rc` and `:latest` still resolve to the
  2.59 digest `sha256:1b5ded166fb691d4f6888b474e6062c21e598a803ab0b9b3cc7a0bddc1d14405`.
- Source changes and release notes are being saved to GitHub for draft prerelease
  preparation. The exact tested Windows and Linux installers are the candidate assets;
  no client release has been published. Unrelated release 2.55 notes and TrueNAS submission
  files remain untouched. See [release notes](RELEASE-NOTES-2.60.md) for the publication checklist.

## Behavior to test

Install Client 2.0.17 before updating/repackaging a world. Config Server defaults on;
Config Client defaults off, including upgraded worlds. Saved operator settings remain in
the database. Saving a shared setting enables Client for its matched mod while preserving
Server delivery and installation. Server-only edits, resets, and rejected edits do not
enable Client. The save summary reports any automatically enabled delivery.

Untouched settings are Overridable. Editing a value clears the flag; the operator can recheck
it afterward to offer a suggested default. With Overridable on, a missing player setting
receives the supplied default and an existing personal value wins. Clearing Overridable reapplies the operator's value before
each launch, including launches without a download. Server only excludes the entire entry
from the client policy. Removing an enforced rule clears its unchanged enforced value so
the mod can regenerate its default; player edits are retained.

Client-only mods must run once on a player's machine; their generated `.cfg` can be imported
into the editor to discover settings. Importing does not automatically distribute that
player's values. See [the design](CONFIG-POLICY-2.60.md) for the full model, including
non-cfg files and mods with their own runtime synchronization.

## Executed checks

| Check | Result |
|---|---|
| Actual launcher Syncer, HTTP downloads, archives, config merger | 26 passed |
| PHP saves, real database, materialiser, full/config archives, capability gate | 49 passed on final image |
| Browser: both pickers, defaults, bulk controls, flag-only saves, reload, mobile | 26 passed on final image |
| Initial 2.60 server archives consumed by the actual client ConfigPolicy class (unchanged contract) | 6 passed |
| Existing mod config editor | 51 passed |
| Existing save-summary database tests | 33 passed with the revised save code |
| Existing client sync-display tests | 25 passed |
| Standalone server suites | 74 passed, 0 failed, 0 unrunnable |
| Cross-repository suites | 4 passed, 0 failed, 0 unrunnable |
| Working-tree marker prediction | 783 passed, 0 failed, 232 unavailable outside image |
| PHP lint, shell parsing, diff whitespace check | Passed |
| Windows MSI artifact verification | 75 passed |
| Client executable smoke checks | 24 passed |
| MSI install/upgrade/UI behavior | 36 passed |

Mutation controls were rejected at the intended assertions: removing personal-config
preservation, ignoring enforced values, and allowing server-only values into the policy.
Disposable database/world fixtures were removed; zero `zpolicy_` worlds remain.

The final archive-to-client checks cover retained personal preferences, supplied defaults
on a fresh player, enforced values, enforcement without a download, server-only exclusion,
and config-only updates. These use the shipping code, not a reimplementation of it.

The first full-image verification found a stale archive-filename matcher (`cpw`) after
packaging was made atomic. That matcher was corrected and verification passed on the same
image digest. No test gate was waived. Earlier browser checks against copied development
files also exposed file permissions from `docker cp`; final-image browser checks use the
image's normal ownership and pass.

## Client test artifacts

In the sibling `phvalheim-client/builds/` directory:

- `phvalheim-client-2.0.17-x86_64.msi` — signed; SHA256
  `388671d2963f650c932c6eddfbd069173cf25b5b8623e2fbd948a2907df7a1bf`
- `phvalheim-client-2.0.17-universal-x86_64.tar.gz` — SHA256
  `c389c2c219b7cbd02939db95cde27448e01cac4c74b8538c0afc32e2f8c3ead3`

No live Unity/Steam player session, mod-specific runtime sync, or new macOS build was tested.
Those are the limits of this development handoff, not claimed successes.

## Automatic Client delivery refinement

Brian requested operator edits to enable Client and clear Overridable. The revised server
implements this on save, with an immediate checkbox change in the editor. A deliberate
Overridable selection after editing is preserved, including through blur/change events.
Undoing an edit restores the original flag and clean state. Pasted edits include their mod
identity, default to enforcement, and respect Server only. Existing saved policies are not
retroactively changed. No launcher rebuild was needed.

The 49 server checks and 26 browser checks were rerun on the exact revised image. They
include private/rejected/reset exceptions, preserving Server and installation choices,
changed-value enforcement, explicit suggested defaults, saved picker state, and save-summary
feedback. All 74 standalone suites pass. The client and cross-repository evidence above
remains from the initial implementation; those unchanged paths were not unnecessarily rerun.

## Evidence on the host

- Build and image verification: `/tmp/phvalheim-rc-build.log`
- Revised server/browser: `/tmp/phv260-auto-client-{server,ui}-final.log`
- Revised regression/summary/predictor: `/tmp/phv260-auto-client-{standalone,summary,predict}.log`
- Initial server/browser/summary: `/tmp/phv260-{server,ui,summary}-final.log`
- Launcher: `/tmp/phv260-client-tests.log`; interoperability: `/tmp/phv260-contract.log`
- Suites: `/tmp/phv260-{standalone,crossrepo,editor-tests,client-display,predict}.log`
- Mutations: `/tmp/phv260-mutation-{lose-personal,ignore-enforcement,secret}.log`
- Client build/signing/install checks: `/tmp/phv260-client-build.log`
- Browser captures: `/tmp/phv260-ui/`
- Actual server test archives: `/tmp/phv260-contract/`
- Private dev database snapshot before container recreation:
  `/tmp/phv260-dev-backup/phvalheim.sql` (directory 0700, file 0600).
