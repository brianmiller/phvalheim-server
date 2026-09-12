# Changelog

## v2.43

### Mods can come from Hexium as well as Thunderstore
A world may draw on either catalogue or both. Search results carry a coloured pill showing
each mod's origin (Thunderstore blue, Hexium purple), and buttons above the list show or hide
a catalogue. Dependencies resolve **across** sources, so a Hexium mod needing a
Thunderstore-only dependency now works.

Identity is `(source, owner, name)` — never the source's UUID. Hexium mirrors Thunderstore
packages carrying their original `uuid4`, so 600 package UUIDs exist in both catalogues and a
UUID can never identify a mod.

### Version pinning
Every published version of every mod is stored, not just the newest — each with its own
download URL, file size and release date. Pick a version from the dropdown in a mod's row to
freeze it there; "Latest (auto)" keeps following new releases. A pinned version is kept in the
database even if the source delists it.

### The catalogue sync was rebuilt
A full cold build of both catalogues — 11,600+ mods and 91,000+ versions including all
history — takes about 30 seconds instead of hours. A routine check that finds nothing changed
takes about a second: Thunderstore answers a conditional request with a bodiless `304`,
Hexium's body is hashed and compared, and every row carries a content hash so only genuinely
changed rows are written.

Both catalogues are synced at **every server start** as well as on the configured interval, so
a restarted server comes back current rather than waiting up to six hours.

Sync & Maintenance shows a live panel per catalogue — phase, packages and versions seen, what
was added/changed/removed, timing, and a comparison with the previous run — plus a per-catalogue
**live sync log** naming what moved, and a per-mod detail toggle.

Server Settings → Mod Catalogues enables/disables each catalogue, sets the interval, and
accepts an API key. **Neither catalogue needs one** — both are public.

### Removed
The sidebar's **Thunderstore Sync** button, its confirm dialog and stop endpoint; the
**Thunderstore Local Sync** and **Thunderstore Chunk Size** settings; the whole pre-2.43 sync
(`tsSync*.sh`, `tsPrune.sh`, `tsModDepGetter.sh`, `modLookup.sh`) and the 14 MB
`tsmods_seed.sql` GitHub seed. A fresh install now builds the catalogue from the live APIs.

### Fixed
- Two mods whose names differed only in capitalisation (`Iron_ModPack` vs `Iron_Modpack`) were
  treated as one and overwrote each other on every sync. 22 such pairs exist on Thunderstore;
  all are now stored separately.
- Dependency strings containing hyphens in the author or version (`LVH-IT`, `sinai-dev`,
  `2.0.6-beta.1`) matched the wrong package or none. Resolution is by longest known
  `owner-name` prefix.
- A world drawing on both catalogues installed **two copies of the same mod** — usually
  BepInEx, which both catalogues publish. Only one was ever installed, but the mod list showed
  it twice and the count was one too high. One copy per `(owner, name)` is now selected,
  installed and displayed, and the UI says which copy it kept.
- World cards and the mod editor reported **0 mods** for every world; they read the pre-2.43
  columns the new catalogue no longer writes. Backup manifests recorded empty mod lists for the
  same reason.
- In the mod picker, ticking a checkbox sent the list back to page 1 — every time. The redraw
  now holds the current page and scroll position. Checkboxes are smaller.

### Upgrading
Existing mod selections migrate automatically on first start. The previous Thunderstore tables
are left untouched, so nothing is discarded. If a world had selected a mod since delisted, the
engine names that world at startup.

## v2.42

### "What's New" modal after every upgrade
The admin UI now shows a one-shot modal listing what changed, the first time it is opened
after an upgrade. Dismissing it records the running version; it stays gone until the next
upgrade. Skipping a release does not skip its notes — upgrading 2.42 → 2.44 shows both.

Fresh installs do not see it (there is no "before" to describe), and it queues behind the
setup wizard and the settings-migration notice rather than stacking on them.

Release notes live in `container/nginx/www/includes/whatsnew.php`, one entry per version.
**Every release must add one**: `dev_tools/check-whatsnew.sh` fails if the version in the
Dockerfile has no entry, because a missing entry is invisible at runtime — the modal just
silently shows nothing.

The stored state is a version string rather than a "shown" boolean deliberately. Every
script in `dbUpdates/` runs on every boot, so a boolean would need re-arming each upgrade
and an unconditional `UPDATE` would re-raise the modal after every restart. Comparing
stored version against running version is self-arming and needs no migration for 2.43+.

### Automatic backups stayed disabled on Unraid despite a dedicated backup volume
The admin UI reported `✓ dedicated volume` and manual backups worked, but `backups.log`
repeated every scheduled run:

```
[WARN : phvalheim] Backup path (/opt/stateful/backups) is on the same volume as
/opt/stateful — no dedicated backup volume mounted. Automatic backups disabled.
```

The UI and the scheduler were asking two different questions. `isBackupPathMounted()`
(admin UI) asks the **mount table** — is `/opt/stateful/backups` a mount point? The
scheduler in `worldBackup` instead compared **device identity**, the source column of
`df` for the backup path and for `/opt/stateful`.

Device identity cannot see a bind mount. On Unraid every `/mnt/user/<share>` is served by
one FUSE mount, so `df` reports the source as the literal string `shfs` for *all* of them
while each share still reports its own size. A user mapping `/mnt/user/appdata/...` to
`/opt/stateful` and an 11T `/mnt/user/backups/...` to `/opt/stateful/backups` got
`shfs == shfs` — read as "same volume", so automatic backups disabled themselves at every
run while the UI correctly showed two volumes of different sizes. This affected every
Unraid install using the standard `/mnt/user` share layout.

Both callers now use one shared oracle that reads the mount table (`mountpoint`, falling
back to `/proc/self/mountinfo`), so the admin UI and the backup scheduler can no longer
reach opposite verdicts. Covered by `dev_tools/test-backup-volume-detection.sh`, which
reproduces the failing condition with a real bind mount whose `df` source is identical to
its parent's.

## v2.41 — Crossplay join codes, access-list safety, world card rework

### Analytics push no longer fails on larger servers
`pushAnalytics.sh` built the worlds array in a shell variable and handed it to `jq` as
`--argjson worlds "$worlds_json"`. Linux caps a **single** argv entry at 128 KiB
(`MAX_ARG_STRLEN`), separately from the much larger total `ARG_MAX` — so once a server had enough
worlds x mods to cross that line, `jq` never ran:

```
pushAnalytics.sh: line 146: /usr/bin/jq: Argument list too long
[WARN : phvalheim] Failed to build analytics payload
```

Every push failed from then on, with that one WARN line as the only symptom. Worlds and mods are
now accumulated in temp files and passed with `--slurpfile`, which has no such limit (measured
in-container: `--argjson` accepts 129,025 bytes and fails at 201,601; the same data via
`--slurpfile` is fine at 512 KB). Temp files are removed by an `EXIT` trap.

### The OPEN pill matches the others
It was styled muted grey while every other access pill is accent-coloured, so it read as a
different kind of thing on the same row and was hard to see against the card. It is a normal
pill now; the tooltip still explains that "open" means no access list and no password.
Measured across DPR 1/1.25/1.5/2 its text is the best-centred on the row (within 0.25px by both
ink-bounding-box and ink-centroid). `access list` and `crossplay` remain 2-5 device px left of
centre -- monospace glyph side bearing, not yet addressed.

### Crossplay: Launch! now explains how to join
A crossplay world can no longer be launched from the public card, because it never really
worked. Valheim's `-joincode` argument is real, so the button looked correct — but the client's
handler resolves the code and joins immediately without ever selecting a character. Players
arrived in the world as Valheim's built-in `Odev (Developer)` profile and found a character in
their list they had not created.

Clicking **Launch!** on a crossplay world now opens a short modal with the current join code and
three steps: start Valheim, pick your character, use *Join by code*. The code is read at click
time, so a world that restarts and is reissued a code never hands out a stale one. Non-crossplay
vanilla worlds (`+connect`) and modded worlds (`phvalheim://`) are unchanged.

The modal also needed a stacking fix: this page carries a hand-rolled `.modal` rule from before
Bootstrap that makes `.modal` itself the dim overlay at `z-index: 1050`. Bootstrap puts its
backdrop at 1050 too, so the backdrop painted over the dialog — it looked dimmed and Close could
not be clicked. Scoped to `#crossplayJoinModal`, matching what the macOS install modal already
does.

- **Crossplay is a vanilla-only option again, for now.** Enabling it makes Valheim open a PlayFab server, which is reached by join code and has no host:port — but the PhValheim client reaches a modded world through QuickConnect, which connects by host and port. A modded crossplay world therefore starts normally and simply cannot be joined by the client. The option is hidden for modded worlds and enforced at world start, so a world whose flag was already set stops opening a PlayFab server on its next restart. This will be revisited once the client can launch with a join code.
- **A crossplay world now shows its join code instead of a Launch button that cannot work.** Enabling crossplay makes Valheim open a *PlayFab* server rather than a Steam one; the world is reached by join code and cannot be joined by IP at all. The card offered `steam://…+connect <host>:<port>` regardless, which asks for a direct connection the server is not serving — so it failed silently while the in-game browser worked fine. Crossplay cards now show the code with a copy button, and the hint no longer tells players to use *Join IP*. Non-crossplay vanilla worlds keep their Launch button. The code is read from the world log at render time rather than stored: it is reissued on every restart, so a cached one would keep advertising a code that no longer works.
- **The admin dashboard's Launch button now respects a crossplay join link too.** The public card was fixed for this, but the admin one still offered `+connect <host>:<port>` for every vanilla world — which a PlayFab-hosted crossplay server never accepts, so it failed silently. Both of the dashboard's launch paths (the PHP render on load, and the poll payload that re-renders it seconds later) now share one helper with the public card. A crossplay world that is up but has not registered its join code yet shows a disabled "starting…" rather than a link with nothing to pass.
- **Resource readouts are cleared when a world stops.** Memory and tick-health bars stayed on screen for worlds that were offline or mid-update. Both APIs were already correct — they return only running worlds — but the way they report a stopped world is to *omit* it, and the dashboard only ever iterated what was in the payload, so those worlds were never visited again and kept their last drawn numbers.
- **An access list that is enabled but empty is now refused.** Valheim only applies `permittedlist.txt` when it has entries — an empty one is not "nobody may join", it is no restriction at all. A world could therefore sit with *Use Access List* switched on, no players in the list, and be joinable by anyone, while the Access tab showed it as restricted. Saving that combination now fails with an error naming both repairs (add a player, or switch the list off). It is refused rather than silently corrected: "let anyone in" and "let these people in" are one click apart and mean opposite things.
- **"Use Access List" can no longer be switched on with nobody on the list.** Valheim enforces `permittedlist.txt` only when it has entries, so an access list that is on but empty produced a wide open server whose Access tab claimed otherwise. Creating a restricted world now requires a first player ID, and saving an empty enforced list is refused — both checked server-side, not only in the browser. Any world that reaches that state another way (a restored backup, a direct database edit) now logs a loud warning at every world start.
- **A restricted world now needs a first player before it can be created.** Choosing *Only players on the access list* reveals a Steam ID field and the world will not be created without a valid 17-digit SteamID64, so it is genuinely restricted from the moment it exists rather than restricted-with-an-empty-list. Validated at the endpoint as well as in the form, since the endpoint is reachable directly.
- **Access IDs are now shown and stored in the `V_` form everywhere.** Valheim matches only the prefixed form, so a bare 17-digit SteamID64 in an access list matches nothing and the player is refused with a misleading "Banned". Previously the prefix appeared only in the file on disk, while the database, the Access tab and the player page all showed the bare id. Citizens, Admins and Banned now all store and display the canonical form. Pasting a bare SteamID64 still works — it is upgraded automatically — and an already-prefixed id is left alone.
- **World cards space their rows consistently.** A card stretches to match the tallest card in its row, and the leftover height was shared across every row — so an online modded card next to a taller vanilla one had its rows spread twice as far apart as identical offline cards beside it. The leftover now collects in a single empty row at the bottom, so row spacing no longer depends on a card's neighbours. The crossplay hint sits at the bottom of the card, where a modded card shows its boss trophies, instead of jammed under the last row, and there is more space under the world name.
- **Creating a vanilla world explains the password rules where you break them.** Valheim will not start a world whose password appears anywhere inside its name, and it will not list a world in the server browser without one. Both were only enforced after you pressed Create, so the form spun, bounced, and printed the reason in a box at the far end of the page — next to a warning that opened by talking about custom seed mods. Creating `test123132131` with the password `test123` therefore looked like it was being blocked over seeds. The rules are now checked as you type, against the password field, naming both offending values; the note about seeds has been moved out of the password panel to the seed control that was already explaining it.
- **Admin toggle switches are smaller, and there is now only one size of them.** The settings modals overrode the switch to 44×24 while the rest of the admin UI used 36×20, so the loudest thing in a panel of quiet rows was a checkbox. Everything is 32×18 now. The reason the modal ones were enlarged — that the smaller switch was under the minimum size for a pointer target — was a fair point, and it is answered properly instead: the clickable area stays 36×24 while the drawn pill shrinks.
- **A vanilla world can run without a password.** The password field is optional now; leave it blank and anyone who can reach the server may join, which is what the card's **OPEN** pill has always meant. The one thing a passwordless world cannot do is appear in the public server browser — Valheim refuses to start a listed server with no password (*"bad password: the password is too short"*) — so the listing toggle is disabled, and says so, while the password is empty. It is also unticked rather than merely greyed, since a disabled-but-ticked box still submits. Both the create form and the world Settings modal enforce it, and the server re-checks on save.
- **Every world card says how you get in, and every pill says what it means.** Modded worlds are always gated by the citizens list, and were the one kind of world whose card never mentioned it — they have an Access row now, built by the same code as the vanilla one. The pills read **ACCESS LIST** or **OPEN**, each with a hover explanation written for players rather than server operators. A world whose access list is switched on but empty is shown as **OPEN**, because that is what it is: Valheim ignores an empty list and anyone who can reach the server can join. The tooltip says so, and says what to ask the owner for.
- **The "IN SERVER BROWSER" pill is gone.** It answered a question no player has — you are already looking at the world's card, so how it was discovered is the operator's business.
- **Access pills are a third smaller, and their text is centred.** They stood 23px against 20px data rows, making the Access row the tallest on the card, and the last letter's tracking pushed every label off-centre inside its pill.
- **A running world is described by what it is running, not by what has been saved.** Enabling crossplay on a live world lit the CROSSPLAY pill on the public card immediately, while the Launch button correctly stayed a direct-connect link — one card, two sources of truth, and the pill advertising a crossplay world Valheim was not serving. Settings that only take effect at the next restart no longer change what players see. The world is marked **restart pending** in the admin Worlds table instead, naming which settings are waiting (crossplay, server browser listing, password, world type), and that mark clears the moment the world restarts. A stopped world is still shown with its saved settings — there is nothing running to contradict them.
- **The Launch button is right from the moment a world comes up.** Valheim only announces which backend it opened about 30 seconds into starting, and until then the button fell back to the saved crossplay column — which is wrong for exactly the world that has just been restarted into a different mode. It now falls back to what the world was actually started with.
- **The join code lines up with the rest of the card.** Its label was one character shorter than every other label, and since the label column sizes itself to its widest entry, the code sat 8px right of every other value.
- **An online world card no longer sits wider-spaced than an offline one.** Collapsed table borders were set on the *offline* card rule only, so a live card kept the browser's default 2px border-spacing: every row 2px further apart and the card about 14px taller than an identical offline card beside it. The row heights matched the whole time — it was the space between them that differed, which is why online cards looked like they had gaps.
- **World cards are shorter.** A modded card goes from 309px to 248px tall against the same 400px width — a fifth off — so it reads as a card rather than a square; a vanilla one goes from 338px to 275px. Line spacing of 1.5 is the right figure for body text and is what fixed the cramped look, but it was being applied to single-line headings and to fixed tabular rows as well, which cost every card about 25px of pure leading. The world name and the Launch line take a normal heading figure now, and the data rows sit at 1.43 — still inside the range for dense rows, and a whole number of pixels so the rhythm stays exact. Most of what came off was a 50px bottom padding — and that padding turned out to be the only thing keeping the boss trophies on the card at all. The trophy row is a sibling of the card's full-height table, so it was always being laid out past the bottom edge; the padding just hid the overflow. The card stacks its contents properly now, which means nothing hangs off it and the height is whatever is genuinely in it. The spacer row under the Launch line was also malformed HTML — a `<td>` whose tag never closed — and is a real cell now.
- **The player page is set in a real terminal font now.** It asked for Lucida Console, which exists only on Windows, so almost everyone got Courier New — or on Linux its clone, Liberation Mono. Those are thin, wide, small-on-the-body faces, and the page packed them at `line-height: normal`, about 1.17, where the readable range is 1.4–1.6. The result read as tiny and cramped whatever size it was set at. JetBrains Mono now ships with the container (no Google Fonts request, so it works on a server with no outbound internet and tells nobody who visited), line spacing is 1.5, card rows are 14px instead of 12px, and labels are muted so the value you actually came for is the brighter half of the row. Row spacing goes from 14px to 21px. See `docs/TYPOGRAPHY.md`.
- **Card values line up across every card.** The label column took whatever width was left over, so a world with no MD5 sum and no dates yet pushed its values more than twice as far from the colon as a world with a full hash — identical labels, different gap on every card. The labels now size to themselves, which is the same width everywhere.
- **A world restarted into crossplay shows the right join method straight away.** Valheim logs which backend it opened about 30 seconds after starting, and until it did, the card read the *previous* session's line — so a world restarted into crossplay spent that window offering a direct-connect link that could not work. The join code had the same flaw and could hand out the previous session's code, which is already dead.
- **Crossplay cards no longer show a Server address.** It is the value you type into Valheim's *Join IP* screen, and a crossplay server does not accept direct IP connections at all.
- **Fixed the crossplay hint being cut off at the bottom of vanilla cards.** It was a sibling of the card's full-height table, so it was laid out past the bottom of the card. It is part of the table now and cannot be pushed out.
- **World cards are ordered online first, then alphabetically.** The list was ordered by a cron-updated memory column, which is stale for a world that has just started or stopped and meaningless for one that has never run — so the order looked arbitrary, and could disagree with the online/offline state the card itself displayed. (The 5-second refresh updates cards in place and does not reorder, so a world that starts or stops while the page is open keeps its position until reload.)
- **Players can find their own player ID.** It now appears under the welcome line on the player page in small magenta, click to copy — the value a server owner has to ask every player for, which previously required a third-party lookup site.
- **Opening Settings on a world with an enforced but empty access list now says so.** A modal explains that Valheim ignores an empty permitted list, with a button that goes straight to the Access tab. It tracks the live condition rather than being permanently dismissible, so it disappears when the list is fixed.
- **New worlds ask who can join.** The create path never set the access flag at all, so every world inherited the schema default — access list enforced, list empty — and came up open while its Access tab said restricted. The new-world form now has an explicit *Who can join* choice, defaulting to restricted, and the endpoint treats an absent value as restricted so an older client or a replayed request cannot create an open world by omission.
- **Worlds already in that state now announce it at every start.** The save-time guard cannot help a world that was created before it, because nothing re-saves one. `syncAccessLists.sh` renders the lists on every world start and is the only code that can see the condition, so it now logs a `WARNING` saying the world is open despite showing as restricted. It does not change anyone's access.
- **Fixed: a newly created modded world installed no mods at all.** `unzip -d` creates only the last component of a path and fails when a parent is missing. The BepInEx pack ships `BepInEx/config/` and `BepInEx/core/` but not `plugins/`, so on a fresh world every plugin extraction failed — silently, because the command discarded its output — and the world came up vanilla while every log line read "Installing…". It looked intermittent because a world that started got `plugins/` created by BepInEx itself, so a retry appeared to fix it. The directories are now created first, and a failed plugin extraction is logged instead of ignored.
- **A modded world is never published with zero plugins installed.** A failed mod download left the world starting anyway with an empty `BepInEx/plugins`, so a world created with mods selected could come up vanilla. Mod installation failures now leave the world stopped and marked `failed`, with its client payload and md5 untouched, rather than shipping an empty modpack to players.

## v2.40 — Vanilla Servers, Admins, Custom Launch Parameters

Release candidate. Held at `:rc` pending the Valheim 1.0 (Deep North) boss trophy prefab name — see "Known gaps" below.

### Features
- **Vanilla (zero-mod) worlds** ([#81](https://github.com/brianmiller/phvalheim-server/issues/81)): A world can now be created as vanilla — stock Valheim, no BepInEx, no mods, no client payload. Players join with the ordinary Valheim client, so no PhValheim client install is needed. New per-world settings: **server password** and **list in the public server browser** — these are vanilla-only, since modded worlds are gated by the CITIZENS list exactly as before.
- **Crossplay**, on every world: lets Xbox / Microsoft Store players join. This is *not* a vanilla-only setting — it is orthogonal to whether a world runs mods, and lives in every world's **Settings** modal. (It was briefly scoped vanilla-only during development; that was a mistake.)
- **Bespoke public UI card for vanilla worlds**: shows the endpoint, a click-to-reveal password, access badges, and a Join button (`steam://run/892970//+connect`). Vanilla Valheim has no launch argument to pre-fill a server password, so showing it on the card is what makes the world joinable.
- **Custom launch parameters** per world, appended after everything PhValheim generates so they can override it. Validated in the admin UI and never `eval`'d by `startWorld.sh`.
- **ADMINS editor** under CITIZENS in the world Settings modal, writing `adminlist.txt`. Entries are validated as SteamID64 — Valheim silently ignores malformed ones, which previously looked like "I added an admin and nothing happened."
- **Boss registry** (`includes/bosses.php`): the boss list is now defined once and consumed by the API, the public card and the AJAX refresh. Adding a boss is one array entry, one DB column and one PNG.
- **Password visibility control**: a per-world toggle (`password_public`) removes the password row from the public card entirely. It is also stripped from the AJAX payload, not just hidden in the markup.
- **Copy button** next to Show on the vanilla card's password, with a non-secure-context fallback — `navigator.clipboard` is undefined over plain HTTP, which is how most self-hosted installs are reached on a LAN.

### Vanilla world refinements
- Card now reads `Type: unmodded`.
- The **HEALTH** bar is hidden for vanilla worlds in the admin UI. Tick health comes from the TickMonitor BepInEx plugin, which a vanilla world does not run, so the bar could only ever sit empty.
- **Edit Mods is disabled** for vanilla worlds, and `edit_world.php` refuses them server-side — the page is reachable by URL regardless of the button. Switching a world to vanilla now clears its mod selection, so flipping back later does not resurrect a list you thought you had removed.
- Offline vanilla cards grey out fully. The access badges set their own background, so they needed their colour **replaced** rather than faded — opacity alone left a tinted pill on an otherwise grey card.
- The seed control is hidden when creating a vanilla world, replaced with an explanation. Custom seeds require the CustomSeed BepInEx mod and Valheim itself has no seed argument, so the field could only ever be ignored.
- **Vanilla worlds no longer display a fabricated seed.** Creating one stored a seed that could never reach Valheim — the game invents its own when it first generates the `.fwl` — and the public card rendered that stored value as though it were the world's real seed. Nothing failed; the number was simply wrong. Vanilla worlds now store no seed at creation, the card shows *generated on first start*, and the engine reads the real seed back out of the `.fwl` once the world has started (same extraction `importWorld.sh` has always used on uploaded saves, verified against real Valheim saves).

### Access lists (CITIZENS / ADMINS / BANNED)
- **The CITIZENS and ADMINS editors could report a save that never reached the disk.** Both wrote their file with `file_put_contents()` and ignored the return value, so a write that failed — most commonly a list file left owned by `root` by an older engine or by a restore — returned `{"success":true,"message":"Saved successfully"}` while the file kept its previous contents. The database and the file then disagreed permanently: `permittedlist.txt` went on gating the world with a stale list, so players the operator had just added were refused, and `adminlist.txt` looked like it was never written at all. Writes are now atomic (temp file + `rename`, which needs permission on the *directory* rather than the target file, so it also repairs a root-owned file on the next save) and **every failure is reported instead of swallowed**.
- **Nothing ever rewrote these files from the database.** The admin UI was the only writer, at the moment Save was pressed. A world that was restored from a backup, or rebuilt, kept whatever list files came with it. `syncAccessLists.sh` now regenerates all three from the database at every world start, making the database the single source of truth and letting drift heal itself on the next restart.
- **New BANNED list**, editing `bannedlist.txt`, alongside CITIZENS and ADMINS in the world Settings modal. A ban applies even when the world is public.
- **`worldDirPrep` only ever created `permittedlist.txt`.** `adminlist.txt` and `bannedlist.txt` were left for Valheim to create on first boot, so the admin UI was editing files that did not exist yet. All three are now created with the world.
- **An imported world's admins existed only on disk.** `importWorld.sh` wrote `adminlist.txt` directly but never set the `admins` column, so the Settings modal showed no admins for an imported world — and under the new sync those admins would have been dropped at the next start. It now writes the database and renders the files from it.
- **A bare SteamID64 no longer matches anything in Valheim 1.0 — entries are now written as `V_<steamid64>`.** `ZNet.ListContainsId()` ends by looking up the *display-prefix* form of the ID (`Steam` → `V`) and **assigns** that result over the earlier bare/`Steam_` checks instead of OR-ing it, so the plain ID everyone knows can never match. This affected all three lists identically, and is why a world with the correct SteamID64 in `permittedlist.txt` still answered **"Banned"** — Valheim uses that same client message for "not on the allowlist". Found by decompiling the shipped assembly and confirmed on a live server. PhValheim now stores whatever the operator types and converts on write, in both the admin UI and the engine's world-start sync, so existing worlds repair themselves.
- **Console players are supported.** Xbox / PlayStation / Nintendo / GameCenter IDs (`X_`, `S_`, `N_`, `A_`) are accepted; those platforms are *number-filtered* by Valheim, so their ID cannot be derived and must be read from the player's F2 panel.
- **All three editors now explain how to get a player's ID**: join any public world and press **F2**. That is the only method that works for every platform.
- **The "restart the world for this to take effect" prompts are gone.** Valheim re-reads all three files while running — verified on a live server, where a player rejected at 18:39 was admitted at 18:41 with no restart in between. Admin status is the one exception, since a connected client caches it until it reconnects.
- Citizens are validated the same way admins already were. Valheim silently ignores anything else in these files, which is indistinguishable from "I added them and nothing happened".
- File headers now match what Valheim itself writes byte-for-byte, including the double space in the admin and banned headers.

Verified against the real Valheim dedicated server: it reads and writes all three lists in the `-savedir` root, creates any that are missing at startup, and does not overwrite entries written from outside — neither before it starts nor while it is running. `dev_tools/test-accesslists.sh` covers the above end to end against a live container.

### Fixes
- **`-public` was hardcoded to `0`** in `startWorld.sh`, and `-password` was never passed at all despite being accepted as an argument. No world has ever been listed or password protected.
- **`worlds.public` is not a "public server" flag.** It is the CITIZENS access-control flag — when set it blanks `permittedlist.txt`. Valheim's `-public` argument is now driven by a separate `listed` column, so worlds that were opened to all citizens are not silently published to the global server browser on upgrade.
- **Trophy tooltip drift**: the AJAX refresh said "The Seeker Queen" where the server-rendered card said "The Queen", so the tooltip changed on first refresh. Both now come from the registry.
- **SQL injection in `setHungHeads()`**: the world name and trophy column were interpolated into SQL from an unauthenticated POST body. Both are now bound/validated against the registry.

### Admin UI
- **The mod browser is one "All" list.** The old "Available" tab held only the mods you had *not* picked, so ticking one made it vanish from the list you were reading. "All" holds every mod with the selected ones pinned to the top, and it is the landing tab on both Create World and Edit World. "Selected" remains as a filtered view. Previously the pages opened on "Selected" — empty by definition on a world being created — which rendered "No data available in table" and hid the whole catalogue behind a tab.
- **Create World no longer offers mod selection for a vanilla world.** The whole card is hidden, not just the tables; the header and the clone-mods-from-another-world block used to stay on screen for a world that can hold no mods.
- **World Settings modal reorganised** into General / Options / Access / Backups tabs, with the create and edit pages given a single primary action and a sticky action bar.
- **Access tab**: the world access switch moved to the top — it decides whether the Citizens list is consulted at all — and gained its own **Save Access** button. Citizens keeps its own **Save Citizens**, which now hides along with the editor instead of sitting there answering "Citizens saved." for a list that was no longer on screen.
- **One-time notice explaining the access switch change.** Upgrading flips how every world's switch *looks* — `Public World: on` becomes `Use Access List: off` — so the Access tab explains it once, then never again. Armed only for servers that already have worlds, so a fresh install is never told about a change it never saw. If the id-format notice is also due, the two are shown one after the other rather than stacked.
- **One world that will not start no longer takes the engine down.** The start path called `exit 1`; supervisor then restarted the engine, whose pre-flight resets every world to `stopped` — so a single unstartable world became a restart loop that also clobbered `create` and `update` commands queued against the *other* worlds. It is now marked `broken` and the engine carries on. The wait that followed it was also unbounded, so a world that started and then died (a rejected password, a mod that aborts) parked the engine forever; that wait now gives up after 120s and marks the world `broken` too.
- **Access tab decluttered.** The same five-line "how to find a player's ID" note used to sit above each of Citizens, Admins and Banned. It now appears once, above all three, as a disclosure that starts closed — a screen reader was reading the whole procedure out three times per visit. Each list instead carries a one-line hint with a real example (`V_76561198012345678`), replacing a placeholder that vanished on focus and never showed the `V_` form. Each list heading now shows a live count, and **Look Up SteamID is on all three lists** — it used to exist only on Citizens and always pasted its result there, so there was no way to look up an ID while editing Admins or Banned. Banned gains a warning line noting that a ban applies even with the access list switched off.
- **"Public World" is now "Use Access List".** The switch never had anything to do with the Valheim server browser — it only decides whether `permittedlist.txt` is enforced — and calling it *Public* invited it to be read as the separate **List in server browser** option. The sense is inverted to match the new wording: **on** means the Citizens list is enforced. Nothing is stored differently, so a world's actual access is unchanged; a world that read "Public World: on" now reads "Use Access List: off".
- **Look Up SteamID returns the `V_` form** rather than a bare SteamID64. It pastes straight into an access list, so it was manufacturing the exact input Valheim 1.0 refuses. The same helper in the legacy `citizensEditor.php` is fixed too.
- Hint text spacing on the General and Options tabs; the "needs a world restart" note now shares a line with its Save button instead of sitting stranded below it.

### Upgrading from 2.39
- **Stored access IDs are converted to the `V_` form automatically** on first start, and the Access tab explains the change once. Idempotent; already-prefixed and console IDs are left alone, and anything unparseable is kept verbatim rather than dropped — silently deleting an unrecognised ID would lock someone out of their own world. Note this is a *display* fix: `syncAccessLists.sh` already canonicalised on the way out to the files, so an upgraded server was functionally correct before it ran. What it was not was legible — the Access tab showed bare IDs while the file Valheim reads said `V_…`.
- `container/mysql/tsmods_seed.sql` refreshed: **9,390 → 11,030 mods** (the committed seed was seven months old, so a fresh install started that far behind until the first 12-hourly sync).

### Client
- **No client update is required.** 2.0.12 remains current and works with both modded and vanilla worlds — vanilla worlds are joined from the public card's `steam://run/892970//+connect` button, which needs no client at all.

### Known gaps
- **The Deep North boss cannot be a hung head.** Valheim 1.0 shipped without a trophy for it: the assets carry 131 `Trophy*` tokens and only 7 `BossStone*`, none for Kall Fimbulbringer, and the seven existing stones are confirmed. The hung-head wall therefore stays at seven bosses. `public/api.php` still logs any unrecognised `Trophy*` POST, so if one ever appears it identifies itself. See `docs/RELEASE-2.40-DESIGN.md` §9.
- Custom seeds need a BepInEx mod, so vanilla worlds always generate a random seed.

## v2.39 — Modpack Rebuild Boot Fix

First stable release of the 2.38 backup system work, plus a fix for worlds failing to boot after a modpack rebuild.

### Fixes
- **World boot failure after modpack rebuild** ([#80](https://github.com/brianmiller/phvalheim-server/issues/80)): Mod zips packaged on Windows can store directories without the execute bit. `unzip` preserves that, leaving BepInEx unable to traverse the extracted plugin directories and aborting startup with a fatal `UnauthorizedAccessException`. `u+rwX` is now restored after Thunderstore extraction and after custom mods/configs/patchers installs (`cp -p` preserves the bad source permissions the same way).
- **Backup/restore progress behind Cloudflare Tunnel**: Replaced streaming progress with background jobs plus polling, which Cloudflare Tunnels do not buffer.
- **NGINX FastCGI buffering**: Disabled for streaming progress endpoints.
- **Backups table schema**: The `orphaned` column is now present in the initial table creation, not only in the migration path.

### Included from v2.38 (previously pre-release)
The full backup system modernization — activity-aware scheduling, tiered retention, compression, one-click restore, backup management UI, and startup reconciliation. See the v2.38 notes below for details.

---

## v2.38 — Backup System Modernization (Pre-release)

### Backup Engine
- **Activity-aware scheduling**: Backups only trigger when players have been online since the last backup (configurable)
- **Configurable intervals**: Set backup frequency per-server or per-world (default: 30 minutes)
- **Full world backups**: Archives the entire world directory (game data, mods, configs) instead of just Unity save files
- **Compression support**: Optional gzip or zstd compression with deferred scheduling (compress during off-peak hours)
- **Tiered retention**: Keep all backups for N hours, then daily, weekly, and monthly tiers — with per-world overrides
- **Performance tuning**: CPU priority (nice), I/O priority (ionice), and compression level controls to minimize impact on active players
- **Disk space preflight checks**: Backup and compression operations check available space before starting; graceful fallback to uncompressed if space is insufficient
- **Manual backups**: On-demand backup creation from the admin UI with real-time progress polling
- **Transitional state protection**: Backups and restores are blocked while a world is starting, stopping, updating, or being deleted

### Restore
- **One-click restore**: Restore any backup from the admin UI with live progress
- **Pre-restore safety backup**: Automatically creates a safety snapshot before overwriting world data
- **Legacy format support**: Detects and correctly restores backups from pre-2.38 (Unity save path format)
- **Disk space checks**: Warns if insufficient space for the safety backup; skips safety backup rather than failing the restore

### Backup Management UI
- **Backup history table**: View all backups per world with date, type (scheduled/manual), size, compression status
- **Bulk operations**: Select and delete multiple backups at once
- **Download**: Download any backup directly from the browser
- **View details**: Inspect backup metadata including mod list and world settings at time of backup
- **Per-world settings**: Override global backup settings (interval, retention, compression, performance) per world
- **Orphan detection**: Automatically detects and flags backup records whose files are missing from disk

### Dashboard
- **Storage card**: Consolidated volume status showing all mounted volumes (data, backups) with usage bars, mount paths, and capacity warnings
- **Dedicated volume detection**: Uses `mountpoint` detection for accurate bind-mount identification
- **Orphan warnings**: Dashboard shows count of orphaned backup records with a one-click cleanup button
- **Dynamic updates**: Storage card refreshes after backup create, delete, and restore operations

### Backup Reconciliation
- **Startup reconciliation**: On container start, scans for orphaned DB records (files missing) and untracked backup files (files on disk with no DB record)
- **Auto-import**: Discovers backup files on disk that aren't tracked in the database and imports them
- **Orphan recovery**: If a previously orphaned file reappears (e.g., volume remounted), the orphan flag is cleared automatically
- **API endpoints**: Trigger reconciliation or purge orphaned records from the admin UI

### Infrastructure
- **New scripts**: `worldBackupCompress`, `worldBackupRetention`, `worldBackupReconcile`, `worldActivityMonitor`, `worldRestore`
- **New cron jobs**: Activity monitor (every 5 min), backup (every 10 min, self-gating), compression (hourly), retention (hourly)
- **Database migration**: New `backups` table, backup settings columns in `settings` and `worlds` tables
- **CSS tooltips**: Replaced native `title` tooltips with JS-powered tooltips that work inside modals
- **Cloudflare Tunnel compatibility**: Backup/restore progress uses background jobs with polling instead of streaming, which Cloudflare Tunnels buffer

---

## v2.37 — macOS Apple Silicon BepInEx Fixes

- Ship patched MonoMod.RuntimeDetour.dll and BepInEx.Preloader.dll for macOS arm64
- Fixes MonoMod Harmony exceptions caused by MAP_JIT W^X enforcement on M-series chips
- All 8 BepInEx plugins now load with zero errors on macOS arm64

## v2.36 — macOS Client Support

- Cross-platform PhValheim Client (Windows, Linux, macOS)
- Universal `.pkg` installer for macOS (Intel + Apple Silicon)

## v2.35 — Setup Wizard Fix

- Fixed fresh install incorrectly showing settings modal instead of setup wizard
- Removed `TZ` from env var upgrade detection loop

## v2.34 — Anonymous Usage Analytics

- Optional anonymous usage analytics with opt-out
- Analytics dashboard at analytics.phvalheim.com

## v2.33 — Analytics Infrastructure

- Added analytics collection framework
- UUID-based installation tracking

## v2.31 — Setup Wizard & DB-Backed Settings

- All environment variables migrated to database settings
- Setup wizard for fresh installations
- Server Settings modal in admin UI
- Migration notice for upgraders

## v2.27 — Mod Pack Management

- Dependency resolution in PHP
- Two-table mod selection layout
- State-driven mod management architecture
