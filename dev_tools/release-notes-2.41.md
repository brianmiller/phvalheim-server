PhValheim 2.41 is a follow-up to 2.40 focused on the public world card, crossplay joining, access-list safety, and one bug that silently disabled analytics on larger servers.

```
docker pull theoriginalbrian/phvalheim-server:2.41
```

## Crossplay worlds are joined by code, and the UI now says so

Enabling crossplay makes Valheim open a **PlayFab** server rather than a Steam one. A PlayFab server is reached by join code and cannot be joined by IP at all — but the card offered `steam://…+connect <host>:<port>` regardless, which asks for a direct connection the server is not serving. It failed silently while the in-game browser worked fine.

Clicking **Launch!** on a crossplay world now opens a short modal with the current join code and three steps: start Valheim, **pick your character**, use *Join by code*.

That middle step is the point. Valheim does have a `-joincode` launch argument, and using it looked correct — but the client resolves the code and joins immediately without ever selecting a character, so players arrived in the world as Valheim's own built-in `Odev (Developer)` profile and found a character in their list they had never created. There is no launch argument that stops at character selection, so the card explains the join instead of automating it. The code is read at click time, so a world that restarts and is reissued a code never hands out a stale one.

**Crossplay is a vanilla-only option for now.** The PhValheim client reaches a modded world through QuickConnect, which connects by host and port — which a PlayFab world does not have. The option is hidden for modded worlds and enforced at world start.

## Access lists: an empty one is not a locked door

Valheim applies `permittedlist.txt` **only when it has entries**. An empty one is not "nobody may join" — it is no restriction at all. A world could therefore sit with *Use Access List* switched on, no players in the list, and be joinable by anyone, while the Access tab showed it as restricted.

- Creating a restricted world now requires a first SteamID64, checked at the endpoint as well as in the form.
- Saving an enforced-but-empty list is refused, naming both repairs. It is refused rather than silently corrected: "let anyone in" and "let these people in" are one click apart and mean opposite things.
- A world already in that state (restored backup, direct database edit) logs a loud warning at **every** world start — the only code that can see the condition.
- Access IDs are stored and displayed in Valheim's canonical `V_` form everywhere. A bare SteamID64 matches nothing and the player is refused with a misleading "Banned". Pasting a bare id still works; it is upgraded automatically.

## A vanilla world may now run without a password

Leave the password blank and anyone who can reach the server may join. The one thing a passwordless world cannot do is appear in the public server browser — Valheim refuses to start a listed server with no password (*"bad password: the password is too short"*) — so the listing toggle is disabled while the password is empty, and says why. It is also unticked rather than merely greyed, since a disabled-but-ticked box still submits.

## The world card

- **Every card says how you get in.** Modded worlds are always gated by the citizens list and were the one kind whose card never mentioned it. Pills read **ACCESS LIST**, **PASSWORD**, **OPEN**, **PUBLISHED**, **CROSSPLAY**, each with a hover explanation written for players rather than operators. An enforced-but-empty list is shown as **OPEN**, because that is what it is.
- **A running world is described by what it is running, not by what was saved.** Toggling crossplay on a live world used to light the CROSSPLAY pill immediately while the Launch link correctly stayed a direct connect — one card, two sources of truth. Settings that take effect at the next restart no longer change what players see; the admin Worlds table marks the world **restart pending** instead, naming which settings are waiting.
- **Cards are about a fifth shorter** — a modded card 309px → 248px at the same width — and set in JetBrains Mono, which ships with the container. The page previously asked for Lucida Console, which exists only on Windows, so almost everyone got Courier New at `line-height: normal`. No Google Fonts request, so it works with no outbound internet and tells nobody who visited.
- Values line up across every card; online cards no longer sit wider-spaced than offline ones; the crossplay hint and boss trophies no longer hang off the bottom edge.
- **Players can find their own player ID** — under the welcome line, click to copy. Previously this required a third-party lookup site.

## Analytics push no longer fails on larger servers

`pushAnalytics.sh` handed the worlds array to `jq` as `--argjson worlds "$worlds_json"`. Linux caps a **single** argv entry at 128 KiB (`MAX_ARG_STRLEN`), separately from the much larger total `ARG_MAX` — so once a server had enough worlds × mods to cross that line, `jq` never ran:

```
pushAnalytics.sh: line 146: /usr/bin/jq: Argument list too long
[WARN : phvalheim] Failed to build analytics payload
```

Every push failed from then on, with that one WARN line as the only symptom. Worlds and mods now accumulate in temp files and are passed with `--slurpfile`, which has no such limit. Measured in-container: `--argjson` accepts 129,025 bytes and fails at 201,601; the same data via `--slurpfile` is fine at 512 KB.

## Also fixed

- **A newly created modded world installed no mods at all.** `unzip -d` creates only the last component of a path and fails when a parent is missing. The BepInEx pack ships `BepInEx/config/` and `core/` but not `plugins/`, so on a fresh world every plugin extraction failed — silently, because the command discarded its output — and the world came up vanilla while every log line read "Installing…". It looked intermittent because a world that started got `plugins/` created by BepInEx itself, so a retry appeared to fix it.
- **A modded world is never published with zero plugins.** Mod installation failures now leave the world stopped and marked `failed`, with its client payload and md5 untouched, rather than shipping an empty modpack to players.
- Creating a vanilla world explains the password rules as you type, against the password field, instead of failing after submit with a message about custom seed mods.
- Resource readouts are cleared when a world stops, instead of keeping their last drawn numbers.
- Admin toggle switches are one size (32×18) rather than two, with the clickable area kept at 36×24.
- World cards are ordered online first, then alphabetically, instead of by a stale cron-updated memory column.

## Upgrading

No action beyond pulling the image. Database migrations run automatically at startup.

Note for operators: worlds started before this build have no running-options snapshot and fall back to their saved settings until their next restart.

See `CHANGELOG.md` for the full list.
