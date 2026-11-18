# Install with Portainer

Deploy PhValheim as a Compose stack on a **Linux x86-64 (amd64) Docker Standalone**
environment managed by Portainer. This guide does not cover Docker Swarm, Kubernetes,
Windows containers or ARM hosts.

## 1. Review the stack and inputs

The stack file is `portainer/docker-compose.yml` in the server repository. It pins the
stable **2.62** image to this exact digest:

```text
theoriginalbrian/phvalheim-server:2.62@sha256:925c68966570452658a22eeda3d1aa74a7eddabd27a6ee7d5ac5e73d15842009
```

It mounts two project-scoped named volumes: `data` at `/opt/stateful` for the database,
worlds, mods and settings, and `backups` at `/opt/stateful/backups` for world archives.
With a stack named `phvalheim`, these are normally `phvalheim_data` and
`phvalheim_backups`. Keep that stack name and both volumes for future updates.

The four optional stack environment variables have these defaults. The optional app
template exposes the same four inputs:

| Variable | Default | Meaning |
| --- | --- | --- |
| `PHV_PUBLIC_PORT` | `8080` | Host TCP port for the public player website; maps to container port 8080. |
| `PHV_ADMIN_PORT` | `8081` | Host TCP port for admin; bound to `127.0.0.1` and mapped to container port 8081. |
| `PHV_GAME_PORT_START` | `25000` | First game UDP port; must be even. |
| `PHV_GAME_PORT_END` | `25019` | Last game UDP port; must be odd and greater than the start. |

The UDP range must be **identical on the host and container**. The Compose file uses
the same inputs for both Docker's published range and the server's port allocator.
Each world needs two consecutive UDP ports: the default `25000–25019` provides
**10 world pairs**, from `25000/25001` to `25018/25019`. Choose unused host ports;
if you change the range, change both inputs and the matching firewall/router rules.

## 2. Deploy the stack

### From the repository

Select your **Docker Standalone** environment, then **Stacks → Add stack → Repository**
(called **Git Repository** in current Portainer documentation). Name the stack, for
example `phvalheim`, and enter:

| Field | Value |
| --- | --- |
| Repository URL | `https://github.com/brianmiller/phvalheim-server` |
| Repository reference | `refs/heads/master` |
| Compose path | `portainer/docker-compose.yml` |

If Portainer asks for a **Source**, select or create a Git source for that public
repository URL. No repository credentials are needed for this public repository.
Review the Compose file at the revision you will deploy. Leave GitOps automatic
updates disabled if you want to review each stack change before redeploying.

Under **Environment variables**, enter any overrides from the table above, or leave
them unset to use the defaults. Select **Deploy the stack** and wait for the container
and its database initialization to finish. See Portainer's
[stack deployment instructions](https://docs.portainer.io/user/docker/stacks/add).

### Web editor alternative

Copy `portainer/docker-compose.yml` from the server repository. In the
same Docker Standalone environment, choose **Stacks → Add stack → Web editor**, give
the stack a name, and paste that file unchanged. Review the image pin, private admin
binding, UDP range and both named volumes, set any input overrides, then select
**Deploy the stack**.

## 3. Complete the setup wizard

On the Docker host, open **http://localhost:8081**. For a remote Docker host, run this
on your own computer, substituting your SSH user and Docker host:

```bash
ssh -N -L 8081:127.0.0.1:8081 your-user@your-docker-host
```

Keep the tunnel running and open **http://localhost:8081** locally. The SSH destination
must be the Docker host, which may be different from the machine running Portainer.
If you changed `PHV_ADMIN_PORT`, use that host port as the tunnel destination; for
example, a host admin port of 18081 uses `-L 8081:127.0.0.1:18081`.
Keep the admin UI private.

Enter your **Steam API key** and **Game DNS** (game connection hostname) in the
PhValheim setup UI, rather than adding them to the four stack inputs.
[Create a Steam API key](https://steamcommunity.com/dev/apikey) if needed.
The wizard's **Detected Host** comes from your browser's address; open the player
website using its public hostname when it is ready. The stack manages the wizard's
read-only **Base Port** from `PHV_GAME_PORT_START`; change the UDP range through the
stack inputs.

Allow or forward your chosen UDP range through the host firewall and router to the
same ports on the Docker host. Players also need access to the public player website
through `PHV_PUBLIC_PORT` or your HTTPS proxy. Follow the
[ports and public HTTPS reference](../reference/networking.md); website HTTPS does
not forward game UDP traffic.

[Create your first world →](first-world.md)

## Optional: use your own app-template feed

You can deploy the stack directly using the steps above while keeping your current
Portainer catalog. Changing the **App Templates URL replaces the selected catalog**;
it does not append PhValheim to the existing catalog. Record your existing URL before
changing it.

The server repository includes `portainer/templates.json`, which declares feed
`"version": "3"` and a Compose stack template for **PhValheim Server**. Its raw feed
URL is:

```text
https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/portainer/templates.json
```

As a Portainer administrator, open **Settings → General → App Templates**, enter
the feed URL and save the settings. Then select your Docker Standalone environment,
open **App Templates**, select **PhValheim Server**, choose the stack name and review
the four inputs before deploying. Follow the setup wizard steps above afterward.
See Portainer's current [App Templates settings documentation](https://docs.portainer.io/admin/settings/general#app-templates).

Official Portainer catalog acceptance is **PENDING**, tracked in
[portainer/templates #291](https://github.com/portainer/templates/issues/291).
Use the direct stack route or your own feed as described here.

## Retain data and backups

The `backups` volume provides the separate mount required for automatic backups.
Review scheduling and retention in the [backup guide](../host/backups.md).
Retain both `data` and `backups` when updating or recreating the stack.

A separate named volume on the same disk does not protect against host or disk loss.
Keep an off-host copy of both volumes, including a recovery copy before upgrades.
World archives alone do not preserve the database and all server settings. If you
want backups on a separate physical disk, review and change the Compose source to
replace `backups:/opt/stateful/backups` with a bind mount to your chosen destination,
as shown in the backup guide. Keep an off-host copy even with a separate disk.

## Update the existing stack

Keep the **same stack name** and retain its existing `data` and `backups` volumes
mounted at `/opt/stateful` and `/opt/stateful/backups`. A new stack name normally
creates different volumes and looks like a fresh installation. Check both current
volume names in Portainer before updating.

Read the [release notes](https://github.com/brianmiller/phvalheim-server/releases)
and review the intended image tag **and digest** in the Compose source. The existing
digest pin does not automatically follow `latest`, even when you re-pull the image.
An image upgrade requires intentionally changing the pin to the reviewed release.

- **Repository stack:** review the source changes, including the new image pin,
  before they reach the configured reference. Open the existing stack and choose
  **Pull and redeploy**, enabling **Re-pull image and redeploy** to fetch the selected
  image. A repository-managed Compose file must be changed in Git.
- **Web editor stack:** open the existing stack's **Editor**, apply the reviewed
  image pin or updated Compose file while retaining both volumes and input values,
  then choose **Update the stack** and enable image re-pulling when offered.

See Portainer's [stack update instructions](https://docs.portainer.io/user/docker/stacks/edit).
Wait for startup migrations to finish, verify your worlds and settings are still
present, and follow any release-specific [world and client update steps](../host/updating.md#update-worlds-and-clients-too).
