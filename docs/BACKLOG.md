# Community contribution queue

Reviewed against the source and open GitHub issues on 2026-11-04. Choose one task,
read [CONTRIBUTING.md](../CONTRIBUTING.md), and link the issue in your PR. Implementation
is unassigned unless a contributor claims it in the issue. Maintainer review is Brian Miller.
Acceptance criteria live in the linked issues so discussion and the review checklist stay together.

## Scoped implementation and validation tasks

| Task | Current state | Done means |
| --- | --- | --- |
| [Configurable Hugin chat timeout #96](https://github.com/brianmiller/phvalheim-server/issues/96) | Regular chat still defaults to a fixed 120 seconds; Vibes has separate limits | Validated saved setting, default preserved, all provider paths covered, delayed mock/cancellation/proxy tests |
| [Player telemetry and idle-update validation #99](https://github.com/brianmiller/phvalheim-server/issues/99) | Heartbeat already implemented; live timing and failure-path validation remain | Freshness, malformed data, restart, monitor failure and joining-player scenarios tested; real Steam/crossplay evidence reviewed |
| [Public rate limiting #100](https://github.com/brianmiller/phvalheim-server/issues/100) | Outside the 2.61 security release | Tested limits, recovery, trusted-proxy behavior and normal client traffic |
| [Player-portal CSP #101](https://github.com/brianmiller/phvalheim-server/issues/101) | Outside the 2.61 security release | Enforced policy with working portal flows and a blocked-script negative control in Chromium/Firefox |

These are separate changes. No release dates are promised. Suspected new vulnerabilities
belong in the [private reporting route](../SECURITY.md), not public reproduction comments.

## Correction: the player heartbeat is already present

The earlier version of this backlog described adding a server-side Companion heartbeat as
not started. That became stale when 2.58 added `PlayerTelemetry.cs` to the Companion and
updated `container/engine/tools/playerMonitor` to consume it.

The dedicated-server Companion writes `player_stats.json` every five seconds using
`ZNet.GetPlayerList()`. The engine records the count and observation time only while the
file is fresh and its count valid. Missing, malformed or stale observations become unknown
(`player_count_at=NULL`, source `none`). Vanilla worlds have no Companion and remain
unknown; the old log-scraping player-count fallback is not used. Log parsing still serves
other monitoring purposes.

`updateApplier.isIdle()` requires an observed, sufficiently recent zero; unknown and nonzero
counts do not authorize idle updates. Automatic updates remain Beta and off by default.
An explicitly configured force-on-timeout policy is a separate operator choice.

Do not build a duplicate heartbeat or remove the Beta label based only on source inspection.
The remaining task is [#99](https://github.com/brianmiller/phvalheim-server/issues/99), including
join timing, freshness across the monitor/update cadence and real-game evidence.

Relevant code and existing checks:

- Companion `PlayerTelemetry.cs` and its registration in the plugin.
- `container/engine/tools/playerMonitor` and `container/engine/tools/updateApplier`.
- `dev_tools/test-world-player-telemetry.sh`, `test-playerMonitor.sh` and
  `test-autoupdate-idle-gate.sh`.

## Older reports needing current-version reproduction or scope

These remain open. This is triage, not a claim that a bug is fixed or reproducible today.
Use a disposable environment and sanitized fixtures; do not post real world saves or player logs.

| Report | Next useful contribution |
| --- | --- |
| [#91 database log warnings](https://github.com/brianmiller/phvalheim-server/issues/91) | Reproduce the redo-log pressure on a current image; measure workload and capacity before proposing tuning |
| [#79 Firefox download button](https://github.com/brianmiller/phvalheim-server/issues/79) | Reproduce the click/focus behavior with OS/browser version; release-asset URL repair alone does not prove this UI report fixed |
| [#67 restore a missing world](https://github.com/brianmiller/phvalheim-server/issues/67), [#48 import an existing save](https://github.com/brianmiller/phvalheim-server/issues/48), [#47 importWorld errors](https://github.com/brianmiller/phvalheim-server/issues/47) | Exercise current import/restore with disposable saves and document supported recovery steps |
| [#66 file browser size limit](https://github.com/brianmiller/phvalheim-server/issues/66) | Identify the failing size and layer with synthetic files; test failure reporting and recovery |
| [#63 mod version selection](https://github.com/brianmiller/phvalheim-server/issues/63) | Compare requested behavior with current version-pinning support and define the remaining rollback requirement |
| [#50 installed mod discrepancy](https://github.com/brianmiller/phvalheim-server/issues/50) | Capture a minimal mod selection and compare install plan, files and recorded installed versions |
| [#27 separate update/payload build](https://github.com/brianmiller/phvalheim-server/issues/27) | Compare the current rebuild workflow with the request and propose one bounded behavior change |
| [#9 gameDNS propagation](https://github.com/brianmiller/phvalheim-server/issues/9) | Change DNS in a disposable setup and inspect persisted endpoints and generated launch links |
| [Companion #2 out of memory](https://github.com/brianmiller/phvalheim-companion/issues/2) | Reproduce with current plugin and game versions; identify allocations before changing limits |

The old hardlink [PR #42](https://github.com/brianmiller/phvalheim-server/pull/42) needs a fresh
comparison with the current config-policy and payload layout before revival. Do not merge a
2023 filesystem change solely because its original diff looks small.

## Distribution follow-up

[TrueNAS PR #6264](https://github.com/truenas/apps/pull/6264) pins 2.61. Local catalog validation
and both installation profiles pass; upstream fork-workflow approval, review and publication
remain external requirements. An open catalog PR is not a store listing.
