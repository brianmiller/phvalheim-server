# 2.56 — the join code a healthy world cannot show

Found in production on 2026-10-18, on `513-phvalheim1` (dooble), running `:rc`.

> **FIXED 2026-10-18 — the durable fix, not just the `.log.1` fallback.** A first pass read
> `.log.1` only; it was replaced the same day because it fails silently at the *second*
> rotation. What shipped:
>
> **One rule, two implementations, over a world's HISTORY (`.log.1` then the live log):**
>
> 1. last code after the last session start → that code
> 2. a session start with no code after it → NULL (not issued yet)
> 3. no session start visible → the code remembered in `worlds.join_code`
>
> - **Writer — `engine/tools/playerMonitor`** (every 2 min, already reads every running world's
>   log): remembers the code; **forgets it on seeing a new session start**; otherwise leaves it.
>   It cannot keep a dead code: that needs a session start to pass through TWO rotations
>   between two runs, and logRotater runs every 15 minutes.
> - **Reader — `getWorldJoinCode($pdo, $world)`**: live log (fast path, answers within a poll),
>   then the history, then the remembered code. Signature gained `$pdo`; all 7 callers updated,
>   marker `v56ci` fails the build on any leftover one-argument call.
> - **Keyed on what the log shows, not "clear on engine start".** Supervisor's autorestart
>   relaunches a crashed world without the engine knowing; a clear-on-start rule would serve
>   the dead code straight through it.
> - **`join_code_session` was designed and dropped.** With the history read on both sides the
>   reader never needs it — a column nothing reads is a trap.
> - **History read whole (4 MB cap), and `.log.1` joined on only when it adjoins the live
>   tail.** Without that guard a live log past the cap served the OLD session's code — I
>   predicted it would return NULL; the mutation run showed the dead code. Pinned to that.
> - `dbUpdate_2.56.sh`: `worlds.join_code VARCHAR(16) NULL`. Dockerfile → **2.56**.
>
> **Same root cause, worse consequence, also fixed in `playerMonitor`:** the player count was
> read from the live log alone. After a rotation the count line was gone, and the no-count
> branch wrote `player_count=0` while KEEPING the previous (nonzero) observation's
> `player_count_at` — a fresh, confident zero for an occupied world, which is exactly what
> `updateApplier`'s `isIdle()` accepts as permission to restart it. Counts now read the
> history, and an unreadable count sets `player_count_at=NULL` (unknown; `isIdle()` refuses it).
> Only matters with auto-update on, which is off by default.
>
> **Verification**
> - `dev_tools/test-joincode-survives-rotation.php` — 33/33: reader (8 scenarios incl. two
>   rotations, a restart with a dead code in BOTH `.log.1` and the DB, a live log past the
>   cap), writer (real playerMonitor, SQL stubbed), writer/reader agreement, a full lifecycle,
>   and 6 mutation controls (4 reader, 2 writer), each asserted to apply exactly once.
> - `dev_tools/test-playerMonitor.sh` — 12/12; the 3 new cases fail against v2.55's
>   playerMonitor. `test-vanilla-joincode.php` 35/35, `test-backend-session-scoped.sh` 9/9.
> - **Live, in a throwaway container from the real `:2.55` image** (verified byte-identical to
>   v2.55) with the 2.56 files overlaid: the real `dbUpdater.sh` added the column and was a
>   no-op the second time; real playerMonitor (as `phvalheim`) + real MariaDB + real
>   `getWorldJoinCode()` through the real `$pdo` walked start → rotate → rotate (served from the
>   DB; NULL with the DB cleared) → autorestart (NULL before AND after playerMonitor) → new
>   code. All 16 `v56c*` markers matched their predictions inside that served tree; of the 665
>   existing markers, none other moved.
>
> **Found while doing it, NOT fixed:** `dev_tools/test-admin-crossplay-launch.sh` fails 1/9 on
> clean HEAD — it still asserts the `-joincode` launch argument that was abandoned for the
> "Odev" profile. And every fresh install logs `Database update failed to apply` because
> `dbUpdate_2.27.sh` queries `tsmods`, which 2.43 stopped creating.

**Symptom:** the admin dashboard showed **"Crossplay world: waiting for its join code"** on
`VikingOutlaws` — a modded crossplay world that had been up and healthy for 35 hours. The chip
never resolved. The world was fine: saving every few minutes, refreshing its PlayFab entity
token every ~16 minutes, `Connections 0`.

**The code was `417250` the whole time.** Nothing was broken about the world. The UI simply had
no way to find out.

---

## What actually happens

1. `VikingOutlaws` started **Oct 17 01:21:41** and registered join code `417250` at **01:23:24**.
2. Valheim's startup is verbose — on a **modded** world, mod loading and world load alone
   exceeded the **1 MB** rotation threshold (`settings.maxLogSize`, default `1000000`).
   `logRotater.sh` rotated the log at **01:26** — *five minutes after the world started*.
3. Rotation is `cp` + truncate (`container/engine/tools/logRotater.sh`):

   ```bash
   cp -prfv $logFile $logFile.1  > /dev/null 2>&1
   echo "" > $logFile
   ```

   So every line written before 01:26 — including all five join-code mentions — moved to
   `valheimworld_VikingOutlaws.log.1`, and the live log restarted empty.

4. `getWorldJoinCode()` (`container/nginx/www/includes/db_gets.php:531`) reads **only the live
   log**, via `phvCurrentSessionTail()` → `phvReadLogTail()` (`:497`), which opens
   `/opt/stateful/logs/valheimworld_<world>.log` and reads the last 256 KB.
5. Measured on the live box: live log **0** join-code mentions, `.log.1` **5**.
   `getWorldJoinCode()` returns `NULL`, and `admin/index.php:2455` renders the waiting chip.

**This is not the 256 KB window being too small.** The live log was only 175 KB — smaller than
the window. The lines are not out of reach; they are *in a different file*.

## Why only this world

`FreeRangeVikings` — also crossplay, same server — displayed its code correctly:

| world | type | live-log codes | rotated codes |
|---|---|---|---|
| VikingOutlaws | modded | **0** | 5 |
| FreeRangeVikings | vanilla | 18 | 13 |

Two reasons, and both have to be true to hit the bug:

- **Vanilla logs far less at startup**, so it takes much longer to reach 1 MB.
- **Valheim re-prints the code on every player join and leave.** Any traffic re-seeds the live
  log. `VikingOutlaws` had sat at `Connections 0`, so nothing re-printed it after the rotation.

**The trigger, stated precisely: a crossplay world whose log rotates past its startup lines and
which then has no player activity.** It never self-heals — not until someone joins, or the
world restarts. A modded crossplay world that nobody has joined yet is the worst case, and that
is exactly the world most likely to need its join code advertised.

## The fix

### Minimum: fall back to `.log.1`

In `getWorldJoinCode()`, when the live log yields no join-code mention **and** the current
session's `Starting to load scene: start.unity` marker is also absent from the live log, search
`<log>.1` as well.

That second condition is what keeps the existing "current session only" guarantee intact. The
reason `phvCurrentSessionTail()` exists at all is that **the code is reissued on every restart**,
so the last match in an unbounded history can be a dead code served with no indication it is
stale. The two cases are distinguishable:

- **no `start.unity` in the live log** → the running session began *before* the rotation, so
  `.log.1`'s last code belongs to the session that is running now. Safe to use.
- **`start.unity` IS in the live log** → the session started after the rotation. Anything in
  `.log.1` is a previous session. **Must not be used.**

Verified against the live box: `VikingOutlaws`' process started 01:21:41, rotation happened at
01:26, so the running session's startup is in `.log.1` and the live log has no `start.unity`
at all — the first case, exactly.

### Know what this does NOT fix

**Only one generation is kept.** The next rotation overwrites `.log.1` with the current live
log. A chatty world that rotates twice without a player joining loses the startup lines
entirely, and the fallback finds nothing either. The fallback narrows the window from "forever"
to "until the second rotation" — it is a mitigation, not a cure.

### Durable fix, if you want to spend a schema change

Persist the code when it is first observed. **There is no join-code column today** — `worlds`
has `crossplay` and nothing else code-related (checked on the live DB; `joincode` does not
exist). A `worlds.join_code` + `join_code_session` pair, written whenever a code is read from a
log and cleared on world start, would survive any amount of rotation.

That is a `dbUpdate_2.56.sh` and a version bump, so it is Brian's call. The `.log.1` fallback
needs neither and fixes the reported case.

## Oracle to write

`dev_tools/test-joincode-survives-rotation.sh`, with fixtures built as real files:

1. **The reported case** — live log with no code and no `start.unity`; `.log.1` holding the
   startup lines. Must return the code.
2. **The stale-code control, and it is the one that matters** — live log that DOES contain
   `start.unity` (a restart after rotation) and no code yet; `.log.1` holding an OLD session's
   code. **Must return NULL, not the old code.** A fallback that fails this is worse than the
   bug: it hands players a code that silently does not work, which is
   the defect the current "last mention wins" logic was written to fix (see the long comment at `db_gets.php:538`) by reading the last mention rather than
   the registration line.
3. **No regression** — live log with a code present. Must still prefer the live log.
4. **Neither file has a code** — must return NULL, not an empty string. "Waiting" and "no code"
   must stay distinguishable; an empty string renders as a code.
5. **Mutation control** — delete the fallback branch and require case 1 to fail. Without this,
   cases 3 and 4 pass against a function that never gained the fallback at all.

## Two unrelated things seen while diagnosing

- **Production runs `:rc`, not `:2.55`.** Same digest as of today, but `:rc` is a live channel
  that moves under it. Worth deciding whether prod should be pinned to a version tag.
- **`VikingOutlaws` carries `status=Down` while `mode=running`.** Cosmetic. `status` is a
  last-start verdict that nothing ever clears — `mode` is the running state. Noise, not a
  fault, and the same thing that has caused confusion before.

## Reproducing it without waiting 35 hours

```bash
# on a crossplay world that is up and has logged its code:
docker exec <container> sh -c '
  L=/opt/stateful/logs/valheimworld_<world>.log
  cp "$L" "$L.1"      # what logRotater does
  echo "" > "$L"      # ...and the truncate
'
```

The dashboard chip turns to "waiting for its join code" within one 5-second poll, with the
world still running and still joinable on the code it is no longer showing.
