# Vanilla worlds and crossplay

A world can be created as **vanilla**: stock Valheim with zero mods and no BepInEx. Players join with the ordinary Valheim client, so no PhValheim client install is required.

Tick **Vanilla world (no mods)** when creating a world, or flip it later in the world's **Settings** modal (the world needs an update/restart to apply).

| Option | Notes |
|---|---|
| **Server Password** | Minimum 5 characters, and it cannot appear inside the world name — Valheim refuses to start otherwise. |
| **Crossplay** | Lets Xbox / Microsoft Store players join. **Not vanilla-only** — this setting is available on every world from its **Settings** modal. Note that players on those platforms cannot install mods, so a heavily modded world may not be joinable for them. |
| **List in server browser** | Publishes the world to the public Valheim community server list. Valheim requires a password for this. |
| **Show password on public UI** | On by default. Turn it off and the password row is removed from the world card entirely, for worlds whose password you share another way. |

Players see a dedicated card on the public UI with the server address, the password (**show** to reveal, **copy** to put it on the clipboard), and a **Join** button. They can also connect from Valheim's own *Join IP* screen using the address shown.

> **Note:** Valheim has no way to pre-fill a server password from a launch argument, so players type it at the prompt — which is why the card shows it.

Because these worlds run no mods, a few PhValheim features that depend on the companion mod do not apply to them: boss progression ("hung heads"), player join/leave events, and tick-health metrics — so the **HEALTH** bar is hidden for them in the admin UI.

**Seeds.** Valheim's dedicated server has no seed argument — the seed is fixed when the world is first generated. Choosing one needs the CustomSeed mod, which a vanilla world does not run, so the seed control is hidden when creating one. PhValheim reads the seed Valheim actually generated out of the world's `.fwl` after first start and displays that; until then the card shows *generated on first start*.

If you want a **specific** seed on a vanilla world, generate the world in the Valheim client (where you can type a seed) and use **Import World** — no mod required.

A vanilla world cannot have mods: **Edit Mods** is disabled for it, and turning the vanilla switch on clears any existing mod selection. Turn the switch back off to make it a modded world again, then use **Edit Mods** and run an **Update**.

Modded worlds are unaffected by any of this — they continue to be gated by the **Citizens** list.
