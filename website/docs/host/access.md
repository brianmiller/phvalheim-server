# Manage who can join

Each world has three per-world player lists, all in the world's **Settings** modal:

- **Citizens** — who may join (`permittedlist.txt`). Setting a world **Public** here removes the restriction entirely and lets anyone join.
- **Admins** — who gets in-game admin commands (`adminlist.txt`).
- **Banned** — who is blocked from the world (`bannedlist.txt`). A ban applies even when the world is public.

### Getting a player's ID

**Have them join any public world and press `F2`.** The panel shows their **Platform User ID** — note it down and paste it in. Use the platform ID shown by the game; non-Steam players may not have a SteamID64.

A plain SteamID64 (17 digits) also works for Steam players. PhValheim stores what you type and converts it on write.

For Steam players you can also use **Look Up SteamID** in the Citizens editor — enter a Steam username and it returns the ready-to-paste `V_…` form. (Requires a Steam API key in Server Settings.)

Changes take effect **without a restart** — Valheim re-reads all three files while running. The one exception is admin status, which a connected client caches until it reconnects.

The **database is the source of truth**. All three files are regenerated from it every time a world starts, so a world that was restored from a backup or rebuilt converges back to what the admin UI shows instead of quietly keeping an older list.

> **Note:** **Use Access List** controls the Citizens gate only. It does *not* publish the world to the Valheim server browser — that is the separate **List in server browser** option on vanilla worlds.

**Use Access List** sits at the top of the Access tab, because it decides whether the Citizens list is consulted at all. On, only the players on the list may join. Off, anyone may join, so the editor is hidden — the list is kept, not cleared, and comes back when you switch it on again.
