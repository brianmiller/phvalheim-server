# Update PhValheim

Keep your existing data and backup volumes when replacing the container. Your worlds and database live there.

## Docker Compose

From your Compose folder:

```bash
docker compose pull
docker compose up -d
```

Use `theoriginalbrian/phvalheim-server:latest` to follow official releases, or a version such as `:2.62` to choose a specific release. Read the [release notes](https://github.com/brianmiller/phvalheim-server/releases) before upgrading.

The startup process applies database migrations. Large upgrades can take longer; let them finish before treating a temporarily unavailable UI as a failed upgrade.

## Update worlds and clients too

A new container does not mean every world's installed files have been rebuilt. Update your worlds from the dashboard when the release calls for it, then ask players to sync through the PhValheim client.

**For 2.62:** pull and recreate the container, then update each modded world to
receive the click-only Companion. Players must quit Valheim and launch from the website
again. [Client 2.0.18](https://github.com/brianmiller/phvalheim-client/releases/tag/2.0.18)
is needed for overlay controls and personal web settings; 2.0.17 remains the minimum.
Remote Access is not enabled by upgrading. See the
[2.62 release notes](https://github.com/brianmiller/phvalheim-server/releases/tag/v2.62)
for validation limits and the dependency security assessment.

**For 2.61:** a security update for the player portal. Pull and restart; no world update, client update or configuration change is needed, and signed-in players stay signed in. See the [2.61 release notes](https://github.com/brianmiller/phvalheim-server/releases/tag/v2.61).

**For 2.59:** world update plus client sync delivers Companion **1.0.6**, including its late join-code recovery dialog.

## Automatic world updates

Automatic updates are **off by default**. Configure global defaults in **Server Settings →
Updates & Mods** (**Automatic Updates** before 2.62), or override them in a world's **Settings**. These policies update
world files; they do not upgrade the container image or the desktop client application.

The normal automatic path waits for a recent report of zero players, the quiet period and
the configured update window. Vanilla worlds do not supply Companion player telemetry and
are not treated as idle just because no player count is available.

**Update anyway** permits a forced update after the maximum wait. In the current engine,
this check runs before both the idle check and the window check: it can restart a world
with players connected **outside its configured update window**. Use **Keep waiting** if
you do not want that forced behavior, and update manually when ready.

## Unraid or Kubernetes

Pull the desired image through your platform and recreate the container or roll out the workload with the same persistent storage. Keep the admin UI private and wait for migrations to finish.

!!! important "Keep a recovery copy"
    Preserve your persistent data and backups before an upgrade. A world backup is not a full copy of the database, settings or Vibes projects in `/opt/stateful`.

[Storage reference →](../reference/networking.md) · [Troubleshooting →](troubleshooting.md)
