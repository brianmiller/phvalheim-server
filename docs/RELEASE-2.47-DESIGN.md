# Release 2.47 — Player counts, and automatic game + mod updates

Two features ship together. They are **separable on purpose**: the player count is useful on
its own and is what the update gate stands on, so it is built, shipped and watched first.

- **Player counts** (not an issue — requested directly): show how many people are on each
  world, in the admin UI always, and on the public UI per-world by opt-in.
- **Automatic updates** (issue #87): per-world opt-in that applies Valheim server updates and
  mod updates once a world is idle.

---

## 1. Player detection

There is no reliable live player count in Valheim. Everything below was verified against real
players on a production server on 2026-09-15, not inferred from documentation.

### What does NOT work

| Approach | Why not |
|---|---|
| `ss` / socket enumeration | The server serves all peers from one unconnected UDP socket. Nothing per-peer to enumerate. |
| conntrack | `/proc/net/nf_conntrack` absent (netlink-only); the `conntrack` CLI ships nowhere; and a *bridged* container's conntrack table does not hold the host's DNAT entries. Reading them needs `--network=host` or `NET_ADMIN`. PhValheim ships to strangers on Unraid/K8s/plain Docker — we cannot require that. Also lags: `nf_conntrack_udp_timeout` is 30s. |
| TickMonitor's `player_count` | Real (`ZNet.GetNrOfPlayers()`, already emitted to `tick_stats.json`), but the plugin only lands on **modded** worlds. Vanilla worlds have no BepInEx, so no plugin, ever. |
| `Connections N` as a universal signal | Reads **0** on crossplay worlds with a player connected. Verified: a player joined a crossplay world at 14:51:40 and the 15:00:37 heartbeat still said `Connections 0`. |

### What DOES work

Detection splits by the world's `crossplay` flag.

**crossplay = 1** — PlayFab emits an absolute count on every join and every leave:

```
Player joined server "<world>" that has join code <code>, now N player(s)
Player connection lost server "<world>" that has join code <code>, now N player(s)
New session server "<world>" that has join code <code>, now N player(s)
```

Verified 2026-09-15 15:31–15:34. Present only on crossplay worlds — across every production
log, every world emitting this line has `crossplay=1`.

**crossplay = 0** — the periodic server heartbeat, every 10 minutes:

```
  Connections N ZDOS:<n>  sent:<n> recv:<n>
```

Verified 2026-09-15: a non-crossplay world read `Connections 0` while empty, a Steam-direct player joined at
15:50:14, and the 15:58:09 heartbeat read `Connections 1` with `recv:699`. A rarer bare
` Connections N` shape (no `ZDOS:` tail) carries the same number — accept both.

Between heartbeats, Steam-direct worlds also give immediate socket events:

```
Got connection SteamID <id>      → a peer arrived
Closing socket <id>              → a peer left
```

**`Closing socket` is always logged twice** for one departure (every timestamp in the sample
appears exactly 2×). Dedupe on (timestamp, id) or the count goes negative.

### Traps this design must survive

1. **A nonzero count can stick after everyone has left.** Verified: a crossplay world logged
   `Player connection lost … now 1 player(s)` at 14:52:48 and then nothing for 39 minutes —
   the last count line said `1` while the world was empty. **Rule: a nonzero count older than
   the idle threshold is treated as idle, not as occupied.** Without this, one bad teardown
   parks a world in "waiting for players to leave" forever and auto-update never fires.
2. **Reconnect flapping.** The same session logged three join/lost pairs inside two minutes
   during a PlayFab wobble. The idle threshold must be long enough to absorb this; it is why
   the default is 30 minutes and not 5.
3. **Never say "empty".** A 10-minute heartbeat is not a live count. UI says *"no players at
   last check (HH:MM)"*, never *"the world is empty"*.
4. **Do not parse `RPC_Disconnect` as an event.** In production logs, every occurrence was a
   stack-trace frame from a `PhValheimCompanion` exception, not a disconnect record.
5. **Grep for what is there, not what you expect.** The `now N player(s)` line was missed on
   three passes because the searches enumerated anticipated vocabulary. Read the raw window.

---

## 2. Schema (`dbUpdate_2.47.sh`)

Object-by-object idempotent, per the 2.40/2.43/2.45 precedent: this ships as an RC first, so
later revisions of this same script must run on servers that already ran an earlier revision.

### `worlds` — player counts

| Column | Type | Meaning |
|---|---|---|
| `player_count` | `INT DEFAULT 0` | Last observed count |
| `player_count_at` | `DATETIME NULL` | When that observation was made — drives the staleness rule |
| `player_count_source` | `VARCHAR(16) DEFAULT 'none'` | `playfab` \| `heartbeat` \| `socket` \| `none` — shown in the admin UI so a wrong number is diagnosable |
| `show_players_public` | `TINYINT DEFAULT 0` | Opt-in to exposing the count on the public UI |

### `worlds` — auto-update overrides (mirrors `backup_*`)

| Column | Type |
|---|---|
| `autoupdate_use_global` | `TINYINT DEFAULT 1` |
| `autoupdate_mode` | `TINYINT DEFAULT 0` |
| `autoupdate_scope` | `VARCHAR(8) DEFAULT 'both'` (`game`\|`mods`\|`both`) |
| `autoupdate_idle_minutes` | `INT DEFAULT 30` |
| `autoupdate_max_wait_hours` | `INT DEFAULT 24` |
| `autoupdate_on_timeout` | `VARCHAR(8) DEFAULT 'wait'` (`wait`\|`force`) |
| `autoupdate_backup_first` | `TINYINT DEFAULT 1` |
| `autoupdate_window_start` | `INT DEFAULT -1` (−1 = any time) |
| `autoupdate_window_hours` | `INT DEFAULT 0` |

### `worlds` — auto-update state

| Column | Type | Meaning |
|---|---|---|
| `update_available_game` | `TINYINT DEFAULT 0` | Installed buildid ≠ available buildid |
| `update_available_mods` | `INT DEFAULT 0` | Count of unpinned mods with a newer version |
| `update_checked_at` | `DATETIME NULL` | |
| `update_pending_since` | `DATETIME NULL` | Start of the max-wait clock |
| `update_state` | `VARCHAR(16) DEFAULT 'idle'` | `idle`\|`pending`\|`updating`\|`failed` |
| `update_last_result` | `TEXT NULL` | Last outcome, shown in the UI |
| `installed_buildid` | `VARCHAR(32) NULL` | From the world's `appmanifest_896660.acf` |

### `settings` — globals (camelCase, per existing convention)

`autoUpdateMode`, `autoUpdateScope`, `autoUpdateCheckIntervalHours` (default 6),
`autoUpdateIdleMinutes` (30), `autoUpdateMaxWaitHours` (24), `autoUpdateOnTimeout` (`wait`),
`autoUpdateBackupFirst` (1), `autoUpdateWindowStart` (−1), `autoUpdateWindowHours` (0).

**Inheritance follows the backup system exactly**: `autoupdate_use_global=1` means the world
takes every global value. A global "on" does **not** override a world that has explicitly
opted out via its own override block — same semantics as `backup_use_global`.

---

## 3. Engine

### `tools/playerMonitor` (cron `*/2`)

Per running world, tail new log bytes from a high-water mark (the `worldActivityMonitor`
pattern, whose markers it reuses) and resolve a count:

1. If the world is crossplay, take the **last** `now N player(s)` in the whole log.
   Source = `playfab`.
2. Otherwise take the **last** `Connections N`. Source = `heartbeat`.
3. For non-crossplay worlds, apply deduped `Got connection SteamID` / `Closing socket` events
   that are **newer than** that heartbeat to interpolate. Source = `socket`.
4. Apply the staleness rule: if the count is nonzero and `player_count_at` is older than the
   world's effective idle threshold, report the world as idle (the stored count is kept and
   flagged stale, not silently zeroed).
5. Reset to 0 on world start — a fresh process has no players.

Writes `player_count`, `player_count_at`, `player_count_source`.

### `tools/updateChecker` (cron, `autoUpdateCheckIntervalHours`)

- **Game**: one anonymous `steamcmd +app_info_print 896660` for the whole server, compared
  against each world's `appmanifest_896660.acf` buildid. One check, N comparisons — not N
  downloads.
- **Mods**: unpinned rows only (`world_mods.pin_version_id IS NULL`) against `mod_versions`.
  A pinned mod is never counted and never updated.
- Never touches a world whose `mode` is not `running` — stopped worlds update on next start.

### `tools/updateApplier` (cron `*/5`)

State machine per world with an update available and auto-update on:

```
idle → pending (clock starts)
pending + idle-for-threshold + inside window → updating
pending + past max_wait → on_timeout=wait ? stay pending : updating
updating → backup (if enabled) → stop → apply → start → idle
failure → failed, update_last_result set, NO retry loop
```

---

## 4. UI

### Server Settings modal — new "Updates" section

After the Mods block (`ss-thunderstoreEnabled` / `ss-hexiumEnabled` / `ss-modSyncIntervalHours`),
since the version-check cadence belongs beside the mod-sync cadence.

`ss-autoUpdateMode` (Off / Per-world / On for all), `ss-autoUpdateScope`,
`ss-autoUpdateCheckIntervalHours`, `ss-autoUpdateIdleMinutes`, `ss-autoUpdateMaxWaitHours`,
`ss-autoUpdateOnTimeout`, `ss-autoUpdateWindowStart` + `ss-autoUpdateWindowHours`,
`ss-autoUpdateBackupFirst`.

Disclosure paragraph under the idle threshold:

> Player counts are derived from each world's server log on a best-effort basis. Valheim
> provides no reliable live count, so the number can lag a disconnect by up to ten minutes.
> Set the idle threshold high enough to absorb that.

### World Settings modal — new "Updates" tab

Beside `settingsTab` / `optionsTab` / `accessTab` / `backupsTab`.

**Status block (read-only):** installed build vs available build; unpinned mods with newer
versions; **pinned mods listed separately as "held"**; current state; and an **Update now**
button that stops → updates → restarts immediately.

**Settings:** `au-useGlobal` toggle revealing `au-overrideFields` — a direct clone of
`bk-useGlobal` / `bk-overrideFields`.

### Player count

- **World card (admin):** `Players: 3` with the source and anchor time on hover.
- **Update status line:** *"Update pending — no players at last check (14:50)"*. Never "empty".
- **World Settings → Options:** `show_players_public` toggle.
- **Public UI:** `3 players online · approximate`, only when opted in.

---

## 5. Release checklist

- `whatsnew.php` entry — `check-whatsnew.sh` fails the release without one (2.42 rule).
- CHANGELOG entry.
- `ENV phvalheimVersion=2.47` in the Dockerfile.
- Build → tag `:rc` → test → **rebuild** for the version tag. Never `docker tag` a tested
  `:rc` into a version; finishing the docs changes files inside the image (2.45 lesson).
- Public repo: no internal IPs or hostnames in any committed file.
