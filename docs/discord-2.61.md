# 🛡️ PhValheim 2.61 — security update, please upgrade

**This one is a security fix for the player portal. Update when you can.**

A community member reported that the portal's Steam sign-in took the player's identity from the sign-in link without checking it with Steam. That meant anyone could open the portal as any player and see that player's world list, any password you had chosen to show on the portal, and their Steam name. Valheim's own access list still checked the real Steam ID when someone tried to join, so this was information exposure, not a way into your games. The admin interface on port 8081 was not affected.

**2.61 fixes that, and a few things found while fixing it:**

- **Steam sign-in is verified with Steam** before a session exists. A sign-in Steam did not confirm goes back to the sign-in page.
- **The public download path only serves client payloads** now. It used to expose each world's access lists and save files to anyone who knew the path.
- **An unused API shortcut that returned any player's world list without signing in is gone.**
- Database lookups on the public side use bound parameters, Steam profile names are escaped before display, and the portal sends standard browser hardening headers.

**What you need to do:** pull `theoriginalbrian/phvalheim-server:2.61` (or `:latest`) and restart, as usual. Take a backup first. There is no configuration change and no setup step.

**What players need to do:** nothing. Signed-in players stay signed in, launching from the portal is unchanged, and every PhValheim Client version keeps syncing exactly as before. The Companion mod is unaffected.

Thank you to the reporter for the clear, responsible write-up.

Release notes: https://github.com/brianmiller/phvalheim-server/releases/tag/v2.61
