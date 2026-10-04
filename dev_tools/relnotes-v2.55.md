**Pre-release.** Two paths in here have not been watched end to end by the maintainer: a real
PhValheim client performing the new small config download, and an **older** client talking to a
2.55 server. The second matters more, and the server side of it is deliberately built so that
an old client behaves exactly as it does today — but no old client has actually been run against
this build. `:latest` has not moved, so if you pull `latest` you are unaffected.

Nothing here is off by default, and nothing requires a client update. The config editor works on
any world; the small-download optimisation simply does not engage until a player updates their
client.

## You can now edit any mod's settings from the admin UI, and your changes survive a mod update

Open a world's **Edit Mods** and use the new **Config** button on a mod's row, or go to **Mod
Configs** to see every config that world has in one place. Each setting is shown with its
description, its type, and the mod author's own default, so you can see what you have changed.

**Why this matters:** until now a mod update wiped its config. PhValheim rebuilds a world's mod
folder from scratch on every update, so the only way to keep a setting was to copy the whole
config file into `custom_configs/` by hand. That worked, but it froze the file — a setting the
new version of the mod *added* never appeared, and one it *removed* stayed forever. PhValheim
now remembers the **individual settings you changed** and re-applies just those, so new settings
arrive at the author's new default while your choices still stick.

On a real world, 670 settings across 11 files turned out to have only **51** that actually
differed from their documented default. One file held 332 entries in `custom_configs/` against
862 live — so the old whole-file copy would have erased **548 settings** on that world's next
update.

**Your existing `custom_configs/` and `custom_configs_secure/` files have been imported for
you.** On first start, PhValheim read every config file in those folders, compared each setting
against the default the mod documents in the file itself, and brought across only the ones you
had actually changed. The originals are kept in a `.imported-pre-2.55` folder beside them, so
nothing has been deleted.

Some imported settings may be marked **needs review** — the file did not say what the mod's
default was, so PhValheim could not tell whether the value was yours or the mod's factory
setting. It was kept either way; have a look and either leave it or press **reset**.

If a mod has never run on a world it has **no settings to show yet**. Most mods write their
config file the first time they load, so start the world once and its settings will be listed.

Mod configs are **no longer edited in the file browser** — an edit there was overwritten the
next time the world started, so the file browser now points you at the editor. You can still
mark an individual setting **server only** if it should never be sent to players, which is what
`custom_configs_secure/` used to be for. And you can **paste a config file someone sent you**:
PhValheim compares it against what your world has installed and offers only the settings that
actually differ, so you do not quietly inherit someone else's defaults.

## Applying a config change no longer stops your world

For a **server-side** mod, restart the world — you no longer need a full world update to change
a value.

For a mod that runs on **players' clients** (anything they see or interact with, such as a HUD or
a clock), press the new **Apply to players** button on the Mod Configs page.

Until now the only way to push a config change to players was a full world update — which stops
the world, re-verifies the game with Steam, wipes the mod folder and reinstalls every mod,
disconnecting everyone, to change a few lines of text. **Apply to players** rebuilds only the
download players receive. Nobody is kicked off and the world keeps running throughout. On a
large modpack it took about 18 seconds.

It refuses while a world is mid-update, and it tells you so rather than failing quietly.

## Players download only the configuration when only the configuration changed

You do not have to do anything to get this. PhValheim now builds a small second file containing
just the config, so a settings change costs your players a download of a few tens of kilobytes
instead of the entire modpack. Measured on a real world: **80,174 bytes instead of 577,604,696**
— roughly 7,200× less.

This needs the **latest PhValheim client** (2.0.15, also a pre-release). An older client still
works exactly as before and simply downloads the whole payload — no player is forced to upgrade,
and the minimum client version has not been raised.

## Upgrading

Nothing to do beyond pulling the image. The database migration adds one column and is safe to
re-run. If you are coming from the first 2.55 release candidate, note that it applied no
overrides at all on a world update; this build fixes that, and the CHANGELOG records why.
