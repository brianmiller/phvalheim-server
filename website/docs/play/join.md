# Join a world

Your host will give you the public PhValheim website. Open it to find the worlds you can join.

## Play on our servers

[Open our player portal](https://pub.phvalheim.com/){ .md-button .md-button--primary }

Our hosted instance lives at **pub.phvalheim.com**. Sign in with Steam to see the worlds available to you, then follow the steps below. World access is managed by the host; if you need access, [join us on Discord](https://discord.gg/8RMMrJVQgy).

## Joining a modded world

1. **Install Valheim** and the **[PhValheim client](https://github.com/brianmiller/phvalheim-client/releases/latest)** for your computer. The client supports Windows, Linux and macOS.
2. **Sign in with Steam** on your host's PhValheim website.
3. **Choose your world** and click its launch link. Allow the browser to open the PhValheim client.
4. **Let the client sync.** It checks the world's payload and downloads changed mods, configs and dependencies.
5. **Follow the connection dialog** when Valheim starts.

Your host controls the mod list. After they update a world, use the launcher again so your files match.

## Joining a vanilla world

Use ordinary Valheim. You do not need the PhValheim client for a vanilla world. The public world card has the connection information; enter its password when Valheim asks.

## If the join code isn't ready

With **Companion 1.0.6**, the menu can poll the backend for a late join code and report connection readiness, retries and expiry. Let the dialog finish checking. If its capability expires, launch again from the website to get fresh connection data.

Your host must update the world and you must sync your client to receive the new Companion. Vanilla worlds do not run the Companion.

## If you cannot see or join a world

- Ask the host whether the world is running and whether you are on its access list.
- Check that you signed in with the intended Steam account.
- If a launch link does nothing, check the client installation and your browser's permission to open `phvalheim://` links.
- If the game reports incompatible mods, sync again and ask the host to check the world's logs.

## Client files

| Platform | Configuration folder |
| --- | --- |
| Windows | `%appdata%\PhValheim` |
| Linux | `~/.config/PhValheim` |
| macOS | `~/Library/Application Support/PhValheim` |

[Client documentation and downloads →](https://github.com/brianmiller/phvalheim-client)
