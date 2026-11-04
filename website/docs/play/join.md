# Join a world

To join a modded world, install the PhValheim client and ask your host for the address of
their PhValheim website. You also need **Valheim installed through Steam**. For a vanilla
world, use ordinary Valheim; see [Join a vanilla world](#join-a-vanilla-world) below.

## Get the client

[Download the PhValheim client](https://github.com/brianmiller/phvalheim-client/releases/latest){ .md-button .md-button--primary }

The client downloads the world's client mod files and shared settings, then starts Valheim
for you. With the config policy introduced in PhValheim 2.60 and client 2.0.17, personal
settings survive updates; the host can supply defaults or enforce individual settings.
[How shared and personal settings work →](../host/mods.md#change-configurations)

Pick the package for your computer from the latest release:

| Platform | Package |
| --- | --- |
| Windows (x86_64) | `.msi` installer |
| Linux (x86_64) | `.deb`, `.rpm`, `.flatpak`, or `.tar.gz`; see the [client installation instructions](https://github.com/brianmiller/phvalheim-client#installation) for your system |
| macOS (Intel and Apple Silicon) | `.pkg` installer, or a `.tar.gz` with `macinstall.sh` |

The same client installation can connect to different PhValheim servers. It checks for newer
stable client releases when you launch and warns if one is available. **The client does not
update itself:** download and install the newer package to upgrade.

Worlds using the config policy introduced in PhValheim 2.60 require **client 2.0.17 or newer**.
Older clients are blocked and may report this as "world not found". Ask your host if you are
unsure which version to install.

## Join a modded world

1. **Open your host's PhValheim website** and **sign in with Steam**. The site is run by
   whoever runs the server. It lists worlds where you are a citizen and worlds the host has
   made visible to all signed-in players. Visibility and permission to join are separate;
   check the world's access badges or ask your host.
2. **Choose your world** and click its launch link. Allow the browser to open the PhValheim
   client.
3. **Let the client sync.** It checks the world's payload and downloads changed mods, configs
   and dependencies.
4. **Follow the connection dialog** when Valheim starts.

Your host controls the mod list. After they update a world, launch it again from the
website to sync the updated client files and shared settings.

## Join a vanilla world

Use ordinary Valheim. You do not need the PhValheim client for a vanilla world. The world's
card on your host's website has the connection information. Use its join code for a crossplay
world, or its server address and port for a direct connection. If the world requires a
password, enter it when Valheim asks; ask your host if it is not shown on the card.

## If the join code isn't ready

With **Companion 1.0.6** and a fresh launch from **server 2.59 or newer**, the in-game menu
can poll the server for a late join code and report connection readiness and retries. Let
the dialog finish checking. If it says the launch has expired, launch again from the website.

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

PhValheim is self-hosted: join someone else's server or
[run your own](../start/install.md). To try the community instance, visit
[pub.phvalheim.com](https://pub.phvalheim.com/). Ask about community play on
[Discord](https://discord.gg/8RMMrJVQgy).
