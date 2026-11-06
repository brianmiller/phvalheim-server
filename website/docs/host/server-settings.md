# Server Settings

!!! info "New in 2.62"
    Releases before 2.62 use the Server Settings dialog instead of this page.

Open **Server Settings** in the admin sidebar. Choose a category on the left, or use the category menu on a phone. **Find a setting** searches across categories, including advanced options.

| Category | Settings |
|---|---|
| **General** | Game address, first game port, timezone |
| **Appearance** | Independent login screen and player website themes |
| **Players & Steam** | Steam API key, session duration, Steam overlay default |
| **Backups** | Storage status, schedule, retention, compression, resource priorities |
| **Updates & Mods** | Automatic world updates, maintenance window, mod catalogues |
| **Hugin Setup** | Providers, live model refresh, connection tests, and runtime limits |
| **Advanced** | World log rotation size and usage analytics |

## Saving changes

Edits stay in place when you switch categories or search. The bottom bar shows the number of changed settings. **Save changes** saves all edited categories together; **Discard changes** restores the last saved values. Leaving with unsaved edits prompts for confirmation. If a save fails, your edits remain so you can correct the problem or retry.

**AI providers save separately:** adding, editing, deleting, or making a provider the default takes effect through its own controls. Those changes are not undone by the page's Discard button. See [Ask Hugin](hugin.md).

Session duration uses days, hours, minutes, or seconds; log size uses MB, KB, or bytes. Unusual saved values retain their exact precision. One MB is 1,000,000 bytes.

## Appearance

Choose a preview card for the **Login screen** and another for the **Player website**. The choices are independent and default to Classic Reforged. Save, then reload the player page to see the change. These choices do not change the admin interface. See [Public themes and Steam overlay](presentation.md) for client requirements and theme details.

## Maintenance

**Backups** shows storage status and a schedule summary. Compression and CPU/disk priorities are under **Advanced backup options**. Scheduled backups require a dedicated backup mount; the page keeps those controls unavailable until one is mounted. Manual backups are never automatically pruned. See [Back up and restore](backups.md).

**Updates & Mods** shows the schedule controls when automatic updates are enabled. Turning updates off preserves the schedule values. Search can still reveal those settings while updates are off. World-specific overrides remain in effect. See [Updating](updating.md) for player-count requirements and vanilla worlds.

## When changes apply

- **Game address:** update each world after saving so its client connection files receive the new address. The page displays a reminder.
- **Managed ports:** when a container port range is configured, the first port is read-only. Change the range in the container configuration. Existing world ports are not silently reassigned.
- **Themes:** players see them on their next page load.
- **Steam overlay:** applies on the next supported client launch; no world restart is needed.
- **Schedules and defaults:** subsequent scheduled work uses the saved settings. World-specific overrides remain in effect.

[Remote Access](remote-access.md) remains a separate admin destination for publishing and checking the player website.
