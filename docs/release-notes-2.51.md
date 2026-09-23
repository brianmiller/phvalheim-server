## A Flatpak download for the client, and a download menu that says what it gives you

Player-facing only. No engine, database or API change; nothing on an existing player's machine
changes, and operators have nothing to do after upgrading.

### A Flatpak for SteamOS, Bazzite and other immutable systems

The Linux entry in the download menu offered three packages — the universal `.tar.gz`, a `.deb`
and an `.rpm`. There is now a fourth.

This is the package that matters on a system whose OS is read-only. On SteamOS and Bazzite
there is nowhere to install a `.deb` or an `.rpm` at all, so until now the only route was the
plain tarball and setting it up by hand. The Flatpak also carries its own runtime, which covers
the `Couldn't find a valid ICU package` failure a minimal install hits — the `.deb` and `.rpm`
declare no dependencies, and a self-contained .NET build still needs the system libicu.

The link appears only for client releases that actually ship one, which is **client 2.0.13 and
newer**. Every earlier tag has the other three packages and no `.flatpak`, so an ungated link
would have been a dead link for each of them. If your players are on an older client release
the menu looks exactly as it did before.

### The Flatpak icon opens instructions, not a download

The other three packages explain themselves — double-click the `.deb`, extract the tarball. A
`.flatpak` bundle does not. It has to be installed with a command, and on a bare window manager
(Hyprland, Sway, i3) there is a second one-off command without which **clicking a world's launch
link does nothing at all, silently**. Handing someone the file on its own reproduces exactly
that failure, so the icon opens a dialog instead:

1. **Install the bundle** — the `flatpak install --user` line, with the filename already correct
   for the release being offered.
2. **Bare window managers only** — the `XDG_DATA_DIRS` one-off. GNOME and KDE skip it. Clearly
   marked, with what goes wrong if it is missed.
3. **Check it worked** — `gio mime`, and what a correct answer looks like.
4. **Launch without desktop wiring** — `flatpak run` with a world link, which works regardless.

Every command has a copy button, each step has a one-line explanation of why it exists, and the
download button is at the bottom of the same dialog. Ctrl-click and *Save Link As* on the icon
still download the file directly, for anyone who does not want the dialog.

### The icons say what they are

All four Linux icons said "Download". They now say **Universal**, **Ubuntu**, **Fedora** and
**Flatpak**, the Windows icon says **Windows**, and there is a little more space between them.

### Known-good and not-yet-confirmed

The client-side Flatpak itself was confirmed working on real hardware at client 2.0.13 — a
modded world synced, launched and loaded its mods on Arch + Hyprland. It has **not** been
confirmed on SteamOS or Bazzite specifically; reports welcome.
