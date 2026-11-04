# Vanilla worlds and crossplay

A world can be created as **vanilla**: stock Valheim with zero mods and no BepInEx. Players join with the ordinary Valheim client, so no PhValheim client install is required.

Tick **Vanilla world (no mods)** when creating a world, or flip it later in the world's **Settings** modal (the world needs an update/restart to apply).

| Option | Notes |
|---|---|
| **Server Password** | Optional unless the world is listed in the server browser. When set, use at least 5 characters; it cannot be part of the world name or known seed, or contain `?`. PhValheim validates these rules when saving. |
| **Crossplay** | Allows supported non-Steam platforms to join. This setting is available on both vanilla and modded worlds. Crossplay does not supply client mods: choose mods that work with unmodded clients if those players will join. |
| **List in server browser** | Publishes the world to the public Valheim community server list. Valheim requires a password for this. |
| **Show password on public UI** | On by default. Turn it off and the password row is removed from the world card entirely, for worlds whose password you share another way. |

Players see a dedicated card on the public UI with connection details. For crossplay worlds,
use the join code when it is ready. For a direct connection, use **Launch!** or Valheim's
*Join IP* screen with the server address and port. If the host allows the password to be
shown, the card offers **show** and **copy** controls; otherwise ask the host for it.

> **Note:** The vanilla launch link does not pre-fill a password. If the world requires one,
> players type it at Valheim's prompt.

Because these worlds run no mods, a few PhValheim features that depend on the companion mod do not apply to them: boss progression ("hung heads"), player join/leave events, and tick-health metrics — so the **HEALTH** bar is hidden for them in the admin UI.

**Seeds.** Valheim's dedicated server has no seed argument — the seed is fixed when the world is first generated. Choosing one needs the CustomSeed mod, which a vanilla world does not run, so the seed control is hidden when creating one. PhValheim reads the seed Valheim actually generated out of the world's `.fwl` after first start and displays that; until then the card shows *generated on first start*.

If you want a **specific** seed on a vanilla world, generate the world in the Valheim client (where you can type a seed) and use **Import World** — no mod required.

A vanilla world cannot have mods: **Edit Mods** is disabled for it, and turning the vanilla switch on clears any existing mod selection. Turn the switch back off to make it a modded world again, then use **Edit Mods** and run an **Update**.

Both modded and vanilla worlds can use the **Citizens** access list, passwords and bans.
[Manage who can join →](access.md)
