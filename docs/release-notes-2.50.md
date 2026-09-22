## A failed Valheim install was reported as a success — and could delete a world

Three bugs in the world install and creation paths, all the same shape: a check whose answer
did not track the thing it claimed to measure.

### 1. "Valheim server installed successfully" on an install that had failed

When Steam only gets part of the way through a download it leaves the files on disk and marks
the app as still needing an update — state `0x6`. PhValheim decided a download had worked
purely by checking that the server program was present. In that failure it always is.

So a world with incomplete game files logged **"Valheim server installed successfully"** and
carried on, and the only sign anything was wrong was a line from Steam itself:

```
Error! App '896660' state is 0x6 after update job.
```

**That also silently disabled the retries.** PhValheim is meant to try a failed download up to
five times; because the first attempt was mistaken for a success, it only ever tried once. A
download that would have worked on the second attempt never got one.

PhValheim now asks Steam directly whether the update finished, and reports success only when
it did. If the check cannot be run at all, it says so rather than guessing either way.

### 2. Retries that could not have worked

The five retries ran the identical command against identical state, which can only produce the
identical failure. Each attempt now tries something more: first it resets Steam's working files
and repairs file ownership, then it discards a partial download, and only as a last resort does
it clear Steam's records and fetch the game again. Each step says what it did in the log.

**Your worlds and mods are never touched by any of that.** The repair is confined to Steam's
own bookkeeping folder — saves, backups, your CITIZENS list and everything under BepInEx are
out of scope at every step, and a test that proves it stays that way runs on every build.

When an install does fail every time, the log now also shows how much free space is left. A
full disk is the most common cause of a download stopping partway, and nothing pointed at it.

### 3. A new or cloned world could silently disappear while being created

PhValheim decided whether a world had deployed by checking whether it could set file ownership
across the whole world folder. If a single file resisted — for any reason — it concluded the
deployment had failed and **deleted the world, folder and all**. Nothing had actually gone
wrong. Most likely to bite when world data lives on network storage, and worst when cloning,
because a clone's folder already contains the copied save.

A new world is now judged on whether its folders were actually created, and a deployment that
genuinely fails is **marked broken and left completely alone** — no deletion — with the reason
in the log and the world still listed. If you want it gone, delete it yourself. A file
ownership problem is now reported as what it is, and no longer condemns anything.

## Also in this release

Documentation for Unraid operators: if the `appdata` share is allowed to move off your pool,
Unraid's Mover relocates it to the array, and a container bind-mounted to the pool path is then
pointing at an empty directory. Because `/opt/stateful` holds the database as well as the
worlds, the symptom is a completely blank dashboard that looks exactly like a fresh install —
though nothing has been deleted. See the Unraid section of the README.

## Upgrading

No database migration. Upgrade and rollback are both a straight image swap.

## What was tested

Verified end-to-end on a real container, not only in a harness: a healthy update, an induced
`0x6` failure, the full repair ladder across all five attempts, save and mod files intact
afterwards, and a failed deployment left listed and broken with its directory — and a cloned
save — untouched.

Covered by three new regression suites (`test-steamcmd-install-verdict.sh`,
`test-steamcmd-self-heal.sh`, `test-world-deploy-verdict.sh`), all mutation-checked: each was
confirmed to go red when its bug is put back.

**Not tested:** a live downgrade back to 2.49. There is no migration, so the rollback path is a
tag swap, but nobody has run it.
