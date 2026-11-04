# Manage who can join

Each world has three per-world player lists, all in the world's **Settings** modal:

- **Citizens** — the player allowlist (`permittedlist.txt`). Turning **Use Access List** off removes this allowlist restriction; passwords and bans still apply.
- **Admins** — who gets in-game admin commands (`adminlist.txt`).
- **Banned** — who is blocked from the world (`bannedlist.txt`). A ban applies even when the world is public.

### Getting a player's ID

**Have them join any public world and press `F2`.** The panel shows their **Platform User ID** — note it down and paste it in. Use the platform ID shown by the game; non-Steam players may not have a SteamID64.

A plain SteamID64 (17 digits) also works for Steam players. PhValheim stores what you type and converts it on write.

For Steam players you can also use **Look Up SteamID** in the Citizens editor — enter a Steam username and it returns the ready-to-paste `V_…` form. (Requires a Steam API key in Server Settings.)

Changes take effect **without a restart** — Valheim re-reads all three files while running. The one exception is admin status, which a connected client caches until it reconnects.

The **database is the source of truth**. All three files are regenerated from it every time a world starts, so a world that was restored from a backup or rebuilt converges back to what the admin UI shows instead of quietly keeping an older list.

> **Note:** **Use Access List** controls the Citizens gate only. It does *not* publish the world to the Valheim server browser — that is the separate **List in server browser** option, available on both modded and vanilla worlds.

**Use Access List** sits at the top of the Access tab. Keep at least one player ID in the
list when enabling it: Valheim does not enforce an empty permitted list. PhValheim refuses
to save an empty enforced list. Turning the switch off hides the editor and preserves its
entries for when you enable it again. Passwords and bans are separate controls.
