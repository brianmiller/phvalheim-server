**Pre-release.** `:latest` does not move — if you pull `latest` you are unaffected. Pull by tag
or digest to try this. Read "What has not been verified" before you do.

Pairs with **phvalheim-client 2.0.14**, also a pre-release.

## Crossplay now works on modded worlds

The crossplay switch used to be offered on unmodded worlds only. It is now available on every
world, in the same place — a world's **Settings → Options**, and on the create-world form.
Existing worlds are unchanged until you turn it on.

**Read this before you enable it.** Only Steam players can load mods. Xbox, PlayStation and
Switch players *can* join a modded crossplay world, but the mod loader does not run on those
platforms, so they will be playing unmodded against a modded server. Whether that works at all
depends entirely on which mods you run: server-side mods are fine, but anything adding items,
creatures or recipes, or changing how the game talks over the network, can make the world
unplayable for them or disconnect them as they join. Server-side networking mods are a good fit.
Most content mods are not.

**Turning crossplay on removes direct connection by IP address entirely**, for every player on
that world. Valheim hosts a crossplay world differently and it has no address to connect to:
everyone joins with the world's **join code**, and the UDP port you forwarded stops being used.

## QuickConnect is retired; the Companion does the joining

QuickConnect was the mod that put your worlds into a player's in-game server list. It cannot
reach a crossplay world at all — there is no address to list — so the PhValheim Companion,
which was already installed on every modded world, has taken the job over.

**The Companion now ships inside the server image** rather than being downloaded from
Thunderstore. You will notice it has disappeared from your worlds' mod lists; that is deliberate,
and the upgrade removes the old entry for you. It is no longer something you can select,
deselect or pin, because it is part of the server rather than a mod you chose.

**Nothing changes until you update a world, and nothing breaks if you never do.** Until then your
worlds keep QuickConnect exactly as they have it. Each existing modded world needs updating once
to move across. **Updating a world stops it**, and it stays stopped until you start it again, so
pick a quiet moment. Afterwards, players should download that world's client payload again.

On a modded crossplay world, **Launch now takes the player all the way in**: it installs the
mods, starts Valheim, and the Companion offers a **Connect** button on the main menu. The player
picks their own character and joins, with no code to copy. The join code is still shown for
anyone joining by hand or from a platform that cannot run the Companion.

## Choose where each mod installs — Server, Client, or both

Every mod in the picker now has **Server** and **Client** switches, in a new *Installs on*
column. Both are on for every mod you select, which is what has always happened, so **nothing
changes until you deliberately turn one off.**

This is what makes a server-side-only world possible. A networking mod that only ever needed to
run on the server can be set to **Server** alone, and your players will not download or load it.
Set *every* mod on a world to Server-only and your players need no mods at all — they still click
**Launch** and still join in one step, because the Companion is always installed for them.

A dependency inherits the **union** of its parents' destinations: if any mod that needs it
installs on the client, it installs on the client.

## Smaller things

- Vanilla crossplay worlds now show their join code on the dashboard, with a **Launch** button
  that opens the same how-to-join dialog the public page uses. A vanilla world has no mod loader,
  so it has no Companion — the join code is the way in, by design.
- The dashboard's world table no longer wraps world names onto two lines.
- A world's log states plainly whether crossplay is on, and repeats the
  console-players-cannot-load-mods caveat for a modded world, so it is on record after the dialog
  is closed.

## What has not been verified

This is why it is a pre-release:

What **has** been confirmed, on a real client: joining a crossplay world through the Companion's
Connect button, the Connect dialog itself, and disconnecting from a world cleanly. That was done
on **Linux, with the Flatpak client**.

Still open:

- **Only the Linux client has been exercised.** The Windows and macOS clients are the same code
  and the same builders, but neither has been run against this release.
- **Joining a world by IP:PORT through the Companion is built but not separately signed off.**
  It shares the Connect path that does work, but it was not tested on its own.
- **No console player has actually joined a modded crossplay world.** The server side is
  exercised; the thing the warning above is about is not.

## Upgrading

Nothing is required of you. The migration runs at startup. If you never update a world, every
world keeps behaving exactly as it does today.
