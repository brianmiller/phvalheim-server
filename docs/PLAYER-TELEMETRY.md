# Player telemetry and idle-update decisions

The server-side Companion heartbeat was implemented in 2.58. It is not a proposed
replacement still waiting to be built. This document explains the runtime path;
[issue #99](https://github.com/brianmiller/phvalheim-server/issues/99) holds validation
scope, acceptance criteria, and current work status.

On a dedicated server, Companion `PlayerTelemetry.cs` writes
`BepInEx/data/PhValheim.Companion/player_stats.json` every five seconds using
`ZNet.GetPlayerList()`, the server's own roster. Server
[`playerMonitor`](../container/engine/tools/playerMonitor) reads the file and records
the count and observation time while its age and count checks accept it.
Missing, malformed, or stale data becomes unknown (`player_count_at=NULL`, source
`none`). Vanilla worlds without Companion remain unknown; log events are not a
fallback source for authoritative player counts.

[`updateApplier.isIdle()`](../container/engine/tools/updateApplier) requires an observed,
sufficiently recent zero. Nonzero and unknown counts do not authorize idle updates.
Automatic updates default to off. An operator's configured force-on-timeout policy
is a separate path, checked before the idle decision.

A periodic observation is not proof of race-free live gameplay behavior. Use issue
#99 for freshness boundaries, failures, join timing, and real Steam/crossplay evidence.
Do not build a duplicate heartbeat based on obsolete release-era plans. The 2.55
Beta-label description is historical: the current idle-gate test checks that those
UI badges were removed in 2.58; labels are not evidence of runtime validation.

Relevant checks:

- [`test-world-player-telemetry.sh`](../dev_tools/test-world-player-telemetry.sh)
- [`test-playerMonitor.sh`](../dev_tools/test-playerMonitor.sh)
- [`test-autoupdate-idle-gate.sh`](../dev_tools/test-autoupdate-idle-gate.sh)
- [`test-updateApplier.sh`](../dev_tools/test-updateApplier.sh)
