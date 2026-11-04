# Join a world

Someone you know runs a PhValheim server. You need two things to play on it: the PhValheim
client, and the address of their PhValheim website.

## Get the client

[Download the PhValheim client](https://github.com/brianmiller/phvalheim-client/releases/latest){ .md-button .md-button--primary }

The client keeps your mods, configs and dependencies identical to the world you are joining,
then starts Valheim for you. Pick the package for your computer from the latest release:

| Platform | Package |
| --- | --- |
| Windows | `.msi` installer |
| Linux | `.deb`, `.rpm`, `.flatpak`, or a `.tar.gz` for any distribution |
| macOS | `.pkg` installer, or a `.tar.gz` with `macinstall.sh` |

Install it once. It updates itself from then on, and it is the same client for every
PhValheim server you ever join. You also need **Valheim** installed through Steam.

Your host will tell you which client version their worlds need; the client checks this for
you and says so if it is too old.

## Join a modded world

1. **Open your host's PhValheim website** and **sign in with Steam**. The site is run by
   whoever runs the server, and it only lists the worlds you have been given access to.
2. **Choose your world** and click its launch link. Allow the browser to open the PhValheim
   client.
3. **Let the client sync.** It checks the world's payload and downloads changed mods, configs
   and dependencies.
4. **Follow the connection dialog** when Valheim starts.

Your host controls the mod list. After they update a world, launch it again from the
website so your files match.

## Join a vanilla world

Use ordinary Valheim. You do not need the PhValheim client for a vanilla world. The world's
card on your host's website has the connection information; enter its password when
Valheim asks.

## If the join code isn't ready

With **Companion 1.0.6**, the in-game menu can poll the server for a late join code and
report connection readiness, retries and expiry. Let the dialog finish checking. If its
capability expires, launch again from the website to get a fresh one.

Your host must update the world and you must sync your client to receive the new Companion.
Vanilla worlds do not run the Companion.

## If you cannot see or join a world

- Ask the host whether the world is running and whether you are on its access list.
- Check that you signed in with the intended Steam account.
- If a launch link does nothing, check the client installation and your browser's permission
  to open `phvalheim://` links.
- If the game reports incompatible mods, sync again and ask the host to check the world's
  logs.

## Client files

| Platform | Configuration folder |
| --- | --- |
| Windows | `%appdata%\PhValheim` |
| Linux | `~/.config/PhValheim` |
| macOS | `~/Library/Application Support/PhValheim` |

[Client source and documentation →](https://github.com/brianmiller/phvalheim-client)

## No host yet?

PhValheim is self-hosted: the normal way to play is on a server a friend runs, or
[one you run yourself](../start/install.md). The project also keeps a small community
instance at [pub.phvalheim.com](https://pub.phvalheim.com/) if you want to try the player
experience first; access to its worlds is managed on [Discord](https://discord.gg/8RMMrJVQgy).
