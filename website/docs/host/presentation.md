# Public themes and Steam overlay

These controls are in development and have not been added to the published stable releases.

## Choose the player portal's appearance

In **Server Settings**, choose **Public website theme** for the player world dashboard and **Login screen theme** for the page before sign-in. Each selector offers **Classic**, **Classic Reforged**, **The Gate**, and **Ember**. Save, then reload the public website. The two choices are independent; neither changes the admin theme.

**Classic Reforged is the default for both pages.** Existing saved choices survive upgrades. The login screens use Classic’s purple accents, Reforged’s compact styling, The Gate’s portal backdrop, or Ember’s warm landscape and colors.

Classic retains the original world dashboard. Classic Reforged provides compact, searchable world selectors and a single panel for the selected world. Click a world to show its status, mod library, connection information and boss progress; **Join code** and **Password** are directly accessible without expanding **World details**; expand that section for additional information. Offline worlds remain selectable. Search keeps the current selection when it matches, and a clear-search action appears when no worlds match. The Gate puts a world chooser below the selected world; Ember puts it beside the world on desktop and above it on mobile. The three new themes share seven boss heads and the landing page's Hugin artwork. Undefeated boss heads are greyed out; defeated heads retain their color. Checkmarks and the live progression count also identify defeated bosses. Vanilla worlds retain their connection details rather than showing modded progression.

All four themes include a compact Steam sign-in card and a matching client download panel. The panel identifies package types and published versions, with installation guidance for Flatpak and macOS and a link to all releases. Sign-in remains a direct Steam form; the About section also works without JavaScript.

Themes preserve access rules, launch behavior, password visibility, client downloads and live world updates.

## Optional overlay troubleshooting

The overlay is enabled by default in the updated client. An operator can select **Server Settings → Disable Steam overlay**. Under a world's **Settings → World Options → Steam overlay**, choose **Use server default**, **Enabled**, or **Disabled**. A world override wins over the server default. Changes apply on the next client launch and require no world restart.

This control applies only to **direct modded Linux launches**, including the Linux Flatpak path. Windows, macOS and vanilla launches use Steam's own overlay settings. Overlay controls require **PhValheim Client 2.0.18 or newer**. Client 2.0.18 is prepared as a draft for Windows, Linux and macOS testing and is not yet publicly released.

Players can always opt out locally with `PHVALHEIM_NO_OVERLAY=1`. This takes precedence even when the operator enables the overlay. For Flatpak:

```sh
flatpak override --user --env=PHVALHEIM_NO_OVERLAY=1 com.phvalheim.Client
```

To remove the local opt-out:

```sh
flatpak override --user --unset-env=PHVALHEIM_NO_OVERLAY com.phvalheim.Client
```

Disabling the overlay is an optional diagnostic step for hangs or lockups. A hang alone does not establish the cause; compare otherwise identical runs and collect logs before attributing it to the overlay or a mod. Launch tests verify the environment and settings, not GPU behavior or the cause of a game hang.
