# PhValheim backlog

Work that is understood, wanted, and deliberately not in the current release. Each entry says
what the problem is, why the obvious fix is not the right one, and what "done" would look like.

---

## Accurate live player detection, via the server-side Companion

**Status:** not started. Raised 2026-10-16 after a Discord operator's players were
disconnected by an automatic update.

### The problem

Valheim's dedicated server exposes no live player count. There is no query port answer, no
admin RPC, no file. Everything PhValheim knows about who is on a world is scraped out of that
world's log by `engine/tools/playerMonitor`, and the two available signals are both weak:

| World type   | Signal                                          | Emitted |
|--------------|-------------------------------------------------|---------|
| `crossplay=1`| `... now N player(s)`                           | on join and on leave, and **never otherwise** |
| `crossplay=0`| `  Connections N ZDOS:...` heartbeat            | every ~10 minutes |

`RELEASE-2.47-DESIGN.md` records what was already ruled out and why: socket enumeration,
conntrack, and the TickMonitor plugin (which cannot work on a vanilla world, because there is
no BepInEx to load it).

The crossplay signal is the damaging one. A player who joins and then plays for two hours
produces **one** log line, two hours old, saying `1`. Nothing further is written until they
leave. So the count is correct and the *observation timestamp* is ancient, and those two facts
together are indistinguishable from the opposite situation — a player whose disconnect was
never logged, leaving a stale `1` on an empty world.

2.47 resolved that ambiguity in the dangerous direction: it treated a nonzero count as idle
once the observation aged past `autoupdate_idle_minutes` (default 30). Half an hour into a
normal session, a world with someone on it was declared quiet and stopped underneath them.

### What 2.55 did instead

Refused. `isIdle()` now returns idle **only** for a recently-observed zero; a nonzero count is
never idle regardless of age. That removes the disconnection, and it is honestly a worse
feature: a world whose count sticks nonzero will not auto-update on the idle path at all. The
operator's escape hatch is `autoupdate_max_wait_hours` + `autoupdate_on_timeout='force'`, which
is an explicit, opted-into decision to accept a disconnection after N hours.

Auto-updates are also now **marked Beta** in both places they can be switched on, and remain
**off by default** (`settings.autoUpdateMode` and `worlds.autoupdate_mode` both `DEFAULT 0`;
nothing in the tree sets either to 1).

This is a holding position, not a fix.

### What the real fix looks like

The Companion mod already runs on the client. A **server-side** Companion plugin can read the
player list directly out of the game — `ZNet.instance.GetConnectedPeers()` — which is the
authoritative answer rather than an inference, and report it on a timer.

Requirements that the log-scraping approach cannot meet and that any replacement must:

- **A heartbeat, not an event.** The count must be re-asserted on a fixed interval whether or
  not anyone joined or left. The whole 2.47 failure is an event-only signal being read as if
  it were a heartbeat. This is the single most important property.
- **Freshness must be distinguishable from emptiness.** "I observed zero 20 seconds ago" and
  "I have not heard anything for an hour" are different answers and must stay different in the
  schema, not collapse into `player_count=0`. `worlds.player_count_source` already carries the
  shape of this; see [[phvalheim_unknown_is_not_uptodate]] for the three times a 0/false
  default silently doubled as a real answer in this codebase.
- **A vanilla world still has no BepInEx.** So this cannot be the only source — the heartbeat
  parse has to stay for `vanilla=1` worlds, and the two paths must be clearly labelled in
  `player_count_source` so a bug in one is not read as data from the other.
- **Fail closed.** A Companion that stops reporting must make the world *ineligible* for an
  automatic update, not eligible. Unknown is not empty.

Done means: `isIdle()` can trust a nonzero count's timestamp, the idle path can be restored for
the common case, and the BETA badge comes off the two selects in `admin/index.php`.

### Where the current behaviour lives

- `container/engine/tools/updateApplier` — `isIdle()`, and the sweep that calls it
- `container/engine/tools/playerMonitor` — the log scraping and the `player_count_*` columns
- `container/nginx/www/admin/index.php` — the two BETA badges and warnings
- `dev_tools/test-autoupdate-idle-gate.sh` — the oracle that pins the refusal
- `docs/RELEASE-2.47-DESIGN.md` — what was ruled out when this was first built
