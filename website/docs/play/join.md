# Join a world

Install **Valheim through Steam**, keep Steam running, and ask your host for their
PhValheim website address.

## Get the client

Choose your platform. The client syncs your world's mods and settings, then starts Valheim.

<div class="phv-downloads">
  <a class="phv-download" data-client-package="windows" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-x86_64.msi"><img src="../assets/platforms/windows.svg" alt="" width="28" height="28"><span>Windows<small>MSI installer</small></span></a>
  <a class="phv-download" data-client-package="macos" data-install-dialog="mac-install" aria-haspopup="dialog" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-macos-universal.pkg"><img src="../assets/platforms/apple.svg" alt="" width="28" height="28"><span>macOS<small>Intel &amp; Apple Silicon</small></span></a>
  <a class="phv-download" data-client-package="deb" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-x86_64.deb"><img src="../assets/platforms/ubuntu.svg" alt="" width="28" height="28"><span>Ubuntu / Debian<small>DEB package</small></span></a>
  <a class="phv-download" data-client-package="rpm" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-x86_64.rpm"><img src="../assets/platforms/fedora.svg" alt="" width="28" height="28"><span>Fedora / RHEL<small>RPM package</small></span></a>
  <a class="phv-download" data-client-package="flatpak" data-install-dialog="flatpak-install" aria-haspopup="dialog" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-x86_64.flatpak"><img src="../assets/platforms/flatpak.svg" alt="" width="28" height="28"><span>Flatpak<small>SteamOS, Bazzite &amp; Linux</small></span></a>
  <a class="phv-download" data-client-package="linux" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-universal-x86_64.tar.gz"><img src="../assets/platforms/linux.svg" alt="" width="28" height="28"><span>Other Linux<small>Tarball · manual install</small></span></a>
</div>

<p class="phv-download-note">Client <span data-client-version>2.0.17</span> · Windows and Linux: x86_64 · <a href="https://github.com/brianmiller/phvalheim-client#installation">All install instructions</a></p>

<noscript>macOS and Flatpak: follow the <a href="https://github.com/brianmiller/phvalheim-client#installation">install instructions</a> after downloading.</noscript>

<div class="phv-install-container">
<dialog class="phv-install" id="mac-install" aria-labelledby="mac-install-title">
  <form method="dialog"><button class="phv-dialog-close" aria-label="Close macOS instructions">×</button></form>
  <h2 id="mac-install-title">Install on macOS</h2>
  <p>Works on Intel and Apple Silicon Macs.</p>
  <ol>
    <li>Open <strong>Terminal</strong> (Applications → Utilities).</li>
    <li>Copy and run this command. Enter your Mac password when prompted.</li>
  </ol>
  <div class="phv-command"><code id="mac-command">curl -fsSL https://raw.githubusercontent.com/brianmiller/phvalheim-client/master/macinstall.sh | bash</code><button type="button" data-copy-command="mac-command">Copy command</button></div>
  <p>The installer downloads the latest client and registers world launch links. When it finishes, return to your host's website and launch a world.</p>
  <p><a href="https://github.com/brianmiller/phvalheim-client#macos">Full macOS guide</a> · <a data-client-package="macos" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-macos-universal.pkg">Download PKG instead</a></p>
  <p class="phv-copy-status" role="status" aria-live="polite"></p>
</dialog>
<dialog class="phv-install" id="flatpak-install" aria-labelledby="flatpak-install-title">
  <form method="dialog"><button class="phv-dialog-close" aria-label="Close Flatpak instructions">×</button></form>
  <h2 id="flatpak-install-title">Install with Flatpak</h2>
  <p>For SteamOS, Bazzite and other Linux desktops with Flatpak installed.</p>
  <ol>
    <li><a class="md-button md-button--primary" data-client-package="flatpak" href="https://github.com/brianmiller/phvalheim-client/releases/download/2.0.17/phvalheim-client-2.0.17-x86_64.flatpak">Download the Flatpak</a></li>
    <li>Open a terminal in your <strong>Downloads</strong> folder and run:</li>
  </ol>
  <div class="phv-command"><code id="flatpak-command">flatpak install --user ./phvalheim-client-2.0.17-x86_64.flatpak</code><button type="button" data-copy-command="flatpak-command">Copy command</button></div>
  <p>Then return to your host's website and launch a world. GNOME and KDE register launch links automatically.</p>
  <details class="note">
    <summary>Using Hyprland, Sway or i3?</summary>
    <p>Run this once, then <strong>log out and back in</strong> so your browser can find Flatpak launch links:</p>
    <div class="phv-command"><code id="flatpak-path-command">mkdir -p ~/.config/environment.d &amp;&amp; printf 'XDG_DATA_DIRS=/usr/local/share:/usr/share:/var/lib/flatpak/exports/share:%s/.local/share/flatpak/exports/share\n' "$HOME" &gt; ~/.config/environment.d/flatpak.conf</code><button type="button" data-copy-command="flatpak-path-command">Copy command</button></div>
  </details>
  <p><a href="https://github.com/brianmiller/phvalheim-client#steamos--bazzite-and-other-linux-flatpak">Full Flatpak guide</a></p>
  <p class="phv-copy-status" role="status" aria-live="polite"></p>
</dialog>
</div>

## Join a modded world

1. **Open your host's website** and sign in with Steam.
2. **Choose your world** and click its launch link. Allow your browser to open PhValheim.
3. **Let the client sync**, then follow the connection dialog in Valheim.

Launch from the website each time to get your host's latest mods and shared settings.
Your personal settings are preserved. [How settings work →](../host/mods.md#change-configurations)

## Join a vanilla world

Use ordinary Valheim. The world's card on your host's website shows a **join code** or
**server address and port**. Enter the password if asked. You don't need the PhValheim client.

??? question "Having trouble joining?"

    - **World missing?** Check your Steam account and ask the host whether the world is running and you have access.
    - **Launch link does nothing?** Recheck the client installation and allow your browser to open `phvalheim://` links.
    - **Join code not ready?** Let the in-game dialog finish checking. If the launch expires, launch again from the website.
    - **Incompatible mods?** Launch again to sync, then ask your host to check the world's logs.
    - **Updating the client?** Install the newer package when prompted; the client does not update itself. Config policy worlds require client **2.0.17 or newer**.

    [Client documentation](https://github.com/brianmiller/phvalheim-client) · [Ask on Discord](https://discord.gg/8RMMrJVQgy)

**No host yet?** Try [the community server](https://pub.phvalheim.com/) or [run your own](../start/install.md).
