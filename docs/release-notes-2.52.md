## Your mod catalogues stopped refreshing after the first start

If you have been on any release from **2.47 to 2.51**, your Thunderstore and Hexium catalogues
have only been refreshing when the container restarts. The hourly refresh, and the
per-catalogue refresh links on the Sync & Maintenance panel, have been failing that whole time.

**This repairs itself as soon as you upgrade.** There is nothing to clean up and no restart
beyond the upgrade itself. Nothing was lost or corrupted — your catalogue was simply only as
current as your last restart, and the mods already installed in your worlds were never
affected.

### What was actually wrong

PhValheim refreshes the catalogues from three places, and they do not all run as the same
account: the refresh at container start runs as `root`, while the hourly one and the
Sync & Maintenance links run as the `phvalheim` account.

To stop two refreshes running at once, PhValheim takes a lock file in `/tmp`. It was opening
that file in a mode that requires **write** permission. The start-up refresh runs first and
always, so the lock file was created owned by `root` — and `/tmp` is a shared directory where
one account cannot overwrite or delete another's files. Every later refresh was then refused.

The refresh links surfaced an error you could see. The hourly ones failed **silently**, into a
log nobody has reason to read. That combination is why this survived five releases: the
catalogue looked *almost* current rather than broken.

A lock file's contents are never actually read — the write permission was only ever needed to
blank the file out, which served no purpose. It is now opened read-only, which is all a lock
requires. That is also why existing servers heal themselves: the `root`-owned file already
sitting in `/tmp` is readable by everyone, so it can just be used as-is.

The start-up refresh now runs as the `phvalheim` account too, so all three paths finally agree
on who they are. That closes a second, related problem in the same area, where a leftover
record from a `root`-owned refresh could be misread as "a refresh is already running" and cause
the next one to be skipped.

### The same fault, swept out of four other places

This was a pattern rather than a one-off, so every other fixed-name scratch file in `/tmp`
written by something that can run as either account got the same treatment.

The **analytics push** wrote two files at fixed names. It cleaned them up on the normal path,
so it would only have jammed if a push had been killed partway — but the outcome would have
been the same permanent refusal. Both now use unique per-run filenames.

The lock files guarding **backups, restores and automatic updates** are only used by one
account today, so they were not broken. They have been moved to the same read-only lock anyway,
so that a future change cannot quietly reintroduce this. Two real bugs turned up while doing
it:

- **A restore could start while another restore was still running.** When you tried to start a
  second restore, the "another restore is already in progress" path deleted the *running*
  restore's lock file on its way out — leaving the next attempt free to start concurrently.
- **The backup lock could be held by two runs at once.** The lock file was removed when a
  backup finished, and removing a file another run is about to open leaves the two of them
  holding locks on different files. Backups also now release the lock properly if compression
  is still finishing.

### Under the hood

The obvious fix for the original bug was subtly incomplete, and the new test caught it. Linux
refuses to *create-or-open* an existing file in a shared directory like `/tmp` when it belongs
to another account — and this refusal applies to `root` as well. Because `/tmp` itself belongs
to `root`, only one of the two directions ever showed the problem, so a fix tested one way
round looked completely correct. The lock is now only ever created when it is genuinely
missing.

There is a matching test gap worth recording. The existing lock test passed throughout all five
broken releases and could not have failed: it runs everything as a single account, so a
permission difference *between* accounts is invisible to it. A new test now covers both
accounts in both directions, checks that the lock is still genuinely exclusive, and confirms
that two simultaneous refreshes of different catalogues still queue rather than collide.

No database migration. No configuration changes.
