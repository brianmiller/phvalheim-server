<p align="center">
  <img src="https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/container/nginx/www/images/phvalheim_tech_visual.svg" width="280" alt="PhValheim">
</p>

<h1 align="center">PhValheim Server</h1>

<p align="center">
  <strong>The Valheim world and mod manager that keeps everyone in sync.</strong>
</p>

<p align="center">
  <a href="https://www.docker.com/"><img src="https://img.shields.io/badge/Docker-2496ed?style=flat-square&logo=docker&logoColor=white" alt="Docker"></a>
  <a href="https://kubernetes.io/"><img src="https://img.shields.io/badge/Kubernetes-326ce5?style=flat-square&logo=kubernetes&logoColor=white" alt="Kubernetes"></a>
  <a href="https://www.unraid.net/"><img src="https://img.shields.io/badge/Unraid-f4672d?style=flat-square&logo=unraid&logoColor=white" alt="Unraid"></a>
  <a href="https://store.steampowered.com/"><img src="https://img.shields.io/badge/Steam_Login-000000?style=flat-square&logo=steam&logoColor=white" alt="Steam"></a>
  <a href="https://en.wikipedia.org/wiki/Open-source_software"><img src="https://img.shields.io/badge/Open_Source-green?style=flat-square&logo=opensourceinitiative&logoColor=white" alt="Open Source"></a>
  <a href="https://discord.gg/8RMMrJVQgy"><img src="https://img.shields.io/badge/Discord-5865f2?style=flat-square&logo=discord&logoColor=white" alt="Discord"></a>
</p>

---

## The Problem

Modding Valheim is easy. Keeping mods perfectly in sync across your server and every player? Nearly impossible. Mod managers like Thunderstore work great individually, but they don't coordinate across a group — eventually someone's client drifts out of sync and the session breaks.

## The Solution

PhValheim is a two-part system (server + client) that locks server and client mod configurations together. Deploy worlds with any combination of Thunderstore mods, and every player automatically gets the exact same files when they connect. No more "which version do you have?" conversations.

Not every world needs mods, though. PhValheim also hosts **vanilla worlds** — stock Valheim, zero mods, joined with the ordinary Valheim client — so you can run plain servers alongside your modded ones without managing a second stack.

---

## Features

| | |
|---|---|
| **One-Click Worlds** | Deploy unique Valheim worlds with any combination of Thunderstore mods at the click of a button. |
| **Vanilla Servers** | Run stock, zero-mod worlds alongside your modded ones. Password protected and optionally listed in the public Valheim server browser. Players join with the ordinary Valheim client — no PhValheim client needed. |
| **Crossplay** | Let Xbox / Microsoft Store players join. Available on every world, modded or unmodded. |
| **Automatic Mod Sync** | Server and client mods stay in lock-step. Players always have the right files. |
| **Setup Wizard** | Guided first-run configuration — just start the container and follow the steps. No environment variables required. |
| **Steam Authentication** | Players log in with their Steam account. Per-world access control lists manage who can see and join each world. |
| **Citizens, Admins & Banned** | Per-world allow list, admin list granting in-game admin commands, and ban list — all rendered from the database at every world start. |
| **Custom Launch Parameters** | Append your own arguments to any world's Valheim server command line. |
| **Thunderstore Integration** | Full Thunderstore mod catalog synced every 12 hours. Search, select, and deploy mods with dependency resolution built in. |
| **Backup System** | Activity-aware scheduled backups with compression (gzip/zstd), tiered retention, one-click restore, and per-world overrides. Supports separate backup volumes. |
| **Live Monitoring** | Real-time CPU, memory, and load metrics for every running world, visible in both the admin and public UIs. |
| **AI Helper** | Built-in AI-powered log analysis (OpenAI, Gemini, Claude, or self-hosted Ollama). Identifies mod errors, missing dependencies, and server health issues. |
| **Custom Configs** | Push custom configuration files to clients, or keep server-only configs that persist across updates. |
| **Single Container** | Everything runs in one Docker container — NGINX, PHP, MariaDB, Supervisor, and the PhValheim engine. |

---

## Screenshots

### Admin Dashboard
<img alt="Admin Home" src="https://github.com/user-attachments/assets/65207d7a-7ac8-4cdb-91a8-b3eaae8be13c">

### World & Mod Editor
<img alt="World and Mods Editor" src="https://github.com/user-attachments/assets/3bc9dfbc-83a8-4cb9-a766-a3a7f2bb29e8">

### Citizens Editor
<img width="652" alt="Citizens Editor" src="https://github.com/user-attachments/assets/7a72a465-7049-4482-a2d9-9e1faa1f8067">

### Log Viewer & AI Analysis
<img width="922" alt="Engine Logs" src="https://github.com/user-attachments/assets/a91d718b-7163-4a35-b3eb-a6a838fdf7a1">

<img width="811" alt="image" src="https://github.com/user-attachments/assets/e592748c-361e-450b-8307-1284f6f3e157" />

<img width="811" alt="AI Helper" src="https://github.com/user-attachments/assets/2a6526c0-0a20-4d73-b6a3-a7e2993b6d37">

<img width="811" alt="image" src="https://github.com/user-attachments/assets/984916c9-664e-41a9-8ed6-72f0bff4b90b" />


---

## Quick Start

### Docker Compose (Recommended)

```yaml
services:
  phvalheim:
    image: theoriginalbrian/phvalheim-server:latest
    container_name: phvalheim
    ports:
      - "8080:8080/tcp"    # Public UI
      - "8081:8081/tcp"    # Admin UI (do NOT expose publicly)
      - "25000-26000:25000-26000/udp"  # Game ports
    volumes:
      - /path/to/data:/opt/stateful:Z
      - /path/to/backups:/opt/stateful/backups:Z   # ideally a separate disk
    restart: unless-stopped
```

That's it. Start the container and open `http://your-host:8081` — the **Setup Wizard** will walk you through configuration.

### Docker CLI

```bash
docker create \
  --name phvalheim \
  -p 8080:8080/tcp \
  -p 8081:8081/tcp \
  -p 25000-26000:25000-26000/udp \
  -v /path/to/data:/opt/stateful:Z \
  -v /path/to/backups:/opt/stateful/backups:Z \
  theoriginalbrian/phvalheim-server:latest

docker start phvalheim
```

### Unraid

<img src="https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/container/nginx/www/images/phvalheim_unraid_icon.svg" alt="PhValheim Unraid Icon" width="48" style="vertical-align:middle;"> Search for **PhValheim** in the Community Apps store.

### Kubernetes / K3s (Helm)

A Helm chart is included in the repo at `helm/phvalheim/`.

```bash
# Minimal install
helm install phvalheim ./helm/phvalheim/

# With Ingress for the public UI
helm install phvalheim ./helm/phvalheim/ \
  --set ingress.public.enabled=true \
  --set ingress.public.hosts[0].host=phvalheim.example.com \
  --set ingress.public.hosts[0].paths[0].path=/ \
  --set ingress.public.hosts[0].paths[0].pathType=Prefix

# With an existing PVC
helm install phvalheim ./helm/phvalheim/ \
  --set persistence.data.existingClaim=my-phvalheim-pvc
```

**How it works:**

- The pod runs with `hostNetwork: true` by default so Valheim's UDP game ports (25000-26000) bind directly to the node — no NodePort or LoadBalancer gymnastics required.
- Two ClusterIP Services are created: one for the **public UI** (8080) and one for the **admin UI** (8081). Each has an optional Ingress resource (disabled by default).
- A 20Gi PersistentVolumeClaim is created for `/opt/stateful`. An optional separate PVC for backups can be enabled with `persistence.backups.enabled=true`.
- Without Ingress enabled, access the admin UI via port-forward to run the Setup Wizard:

  ```bash
  kubectl port-forward svc/phvalheim-admin 8081:8081
  # Then open http://localhost:8081
  ```

See `helm/phvalheim/values.yaml` for the full set of configurable values.

---

## Configuration

All settings are configured through the **Admin UI** after first launch. No environment variables are needed for new installations.

> **Upgrading from an older version?** Your existing environment variables will be automatically migrated to the database on first boot. A one-time migration notice will confirm the imported values.

### Server Settings

| Setting | Description |
|---|---|
| **Steam API Key** | Required. Used for player authentication. [Get one here.](https://steamcommunity.com/dev/apikey) |
| **PhValheim Host** | Public FQDN for the web UI. |
| **Game DNS** | DNS name players use to connect to game servers. Can be the same as PhValheim Host. |
| **Base Port** | First UDP port for worlds (must match the container's port range). |
| **Backups to Keep** | Number of backup snapshots to retain per world. |
| **Client Download URL** | URL for the PhValheim Client installer. |

### Backups

PhValheim includes a full backup system with activity-aware scheduling, compression, tiered retention, and one-click restore.

#### Backup Storage

Mount a **separate volume** for backups to keep them isolated from game data:

```yaml
volumes:
  - /path/to/data:/opt/stateful:Z
  - /path/to/backups:/opt/stateful/backups:Z   # separate disk recommended
```

If no dedicated backup volume is detected, the admin UI will display a warning and automatic backups are disabled. Manual backups can still be created.

#### Scheduling

| Setting | Default | Description |
|---|---|---|
| **Backup Interval** | `30 min` | How often scheduled backups run. The cron runs every 10 minutes but self-gates based on this interval. |
| **Require Player Activity** | `Yes` | Only create backups when players have connected since the last backup. Prevents redundant backups of idle worlds. |

#### Compression

| Setting | Default | Description |
|---|---|---|
| **Compression** | `None` | Algorithm: `none` (uncompressed tar), `gzip`, or `zstd`. Zstd is faster with better compression ratios. |
| **Compression Schedule** | `3:00 AM` | Hour to run deferred compression. Set to `Immediate` to compress at backup time. Deferred mode reduces CPU impact during active hours. |
| **Compression Level** | `0` (default) | Higher levels = smaller files but more CPU. `0` uses each algorithm's default level. |

> **Disk space note:** Compression requires temporary space for both the uncompressed tar and the compressed output (~2x world size). If insufficient space is available, the backup is saved uncompressed with a warning.

#### Retention Policy

Backups are pruned automatically using a tiered retention policy. Manual backups are never auto-pruned.

| Tier | Default | Description |
|---|---|---|
| **Keep All** | `24 hours` | Every backup within this window is kept. |
| **Daily** | `7 days` | After the keep-all window, one backup per day is retained. |
| **Weekly** | `30 days` | After the daily tier, one backup per week is retained. |
| **Monthly** | `6 months` | After the weekly tier, one backup per month is retained. |

#### Performance Tuning (Backups)

These settings control how aggressively backup operations use system resources. Lower priority = less impact on active players.

| Setting | Default | Description |
|---|---|---|
| **CPU Priority** | `Low (10)` | `nice` value for tar and compression. `Normal (0)` = full speed, `Low (10)` = reduced, `Lowest (19)` = minimal. |
| **I/O Priority** | `Low` | `ionice` class. `Idle` = backups only use disk when the game server isn't reading/writing. `Normal` = no throttling. |

#### Per-World Overrides

Each world can override the global backup settings. In the world settings modal, switch to the **Backups** tab and uncheck **Use Global Defaults** to configure per-world intervals, retention, compression, and performance settings.

#### Restore

Restoring a backup replaces the current world directory with the backup contents:

1. A **pre-restore safety backup** is created automatically (unless disk space is insufficient).
2. The current world directory is cleared.
3. The backup is extracted. Legacy backups (pre-2.38) are detected and extracted to the correct path.
4. File ownership is fixed and the world is set to rebuild on next engine cycle.
5. The world process is restarted.

Restores are blocked while a world is in a transitional state (starting, stopping, updating, etc.).

#### Reconciliation

On startup, PhValheim reconciles backup records with files on disk:

- **Orphaned records**: DB entries pointing to missing files are flagged as orphaned.
- **Untracked files**: Backup files on disk with no DB record are discovered and imported.
- **Recovery**: If an orphaned file reappears (e.g., backup volume remounted), the orphan flag is cleared.

Orphaned records are shown in the dashboard Storage card and in the per-world backup table. Use the **Clean up** button to purge orphaned records.

---

### Performance Tuning

| Setting | Default | Description |
|---|---|---|
| **Thunderstore Chunk Size** | `1000` | Number of mods processed per batch during Thunderstore sync. |

The Thunderstore sync runs every 12 hours and processes the full Valheim mod catalog using parallel worker threads — one thread per chunk. The chunk size directly controls the parallelism:

- **Lower value** → more chunks → more parallel threads → higher CPU and MariaDB load, but faster sync on multi-core hosts.
- **Higher value** → fewer chunks → fewer threads → lower CPU pressure, more memory per thread.

**Thread count math** (based on ~9,400 mods in the Thunderstore Valheim catalog as of early 2026):

| Goal | Chunk Size | Threads spawned |
|---|---|---|
| 1 thread | `9400` | `9400 / 9400 = 1` |
| 2 threads | `4700` | `9400 / 4700 = 2` |
| 5 threads | `1880` | `9400 / 1880 = 5` |
| 10 threads | `940` | `9400 / 940 = 10` |
| Default | `1000` | `9400 / 1000 ≈ 10` |

The default of `1000` spawns roughly 10 parallel threads. On low-resource hosts (shared VMs, small cloud instances), raising the chunk size to `4700`–`9400` reduces the sync to 1–2 threads and keeps the host responsive during the sync window.

> **Note:** Running at a single thread (chunk size `9400`) serializes all mod processing through one worker. Depending on the single-core clock speed of your CPU, a full sync at this setting could take several hours to complete. A chunk size in the `2000`–`5000` range is generally a better balance for resource-constrained hosts — enough parallelism to finish in a reasonable time without saturating the CPU.

If you see this warning in `tsSync.log`, your chunk size is too aggressive for the host — increase the value:

```
WARNING: a previous thunderstore sync process is still running. This could mean
your thunderstore chunk size is too aggressive for your system. Consider
increasing the 'thunderstore_chunk_size' database value.
```

Adjust **Thunderstore Chunk Size** in the Admin UI under **Server Settings**.

---

### AI Helper (Optional)

Configure one or more AI providers in Server Settings to enable the built-in log analysis assistant.

| Provider | What you need |
|---|---|
| **OpenAI** | API key — enables GPT-4o models |
| **Google Gemini** | API key — enables Gemini 2.0 Flash and Gemini 1.5 Pro |
| **Anthropic Claude** | API key — enables Claude Haiku 4.5 and Claude Sonnet 4.5 |
| **Ollama** | URL of your self-hosted instance — models detected automatically |

---

## Volumes

| Container Path | Purpose |
|---|---|
| `/opt/stateful` | All persistent data — worlds, mods, database, configuration. |
| `/opt/stateful/backups` | World backups. Point this to a separate disk for safety. |

## Ports

| Port | Purpose |
|---|---|
| `8080/tcp` | Public web UI — expose this to your players. |
| `8081/tcp` | Admin web UI — **keep this private**. |
| `25000-26000/udp` | Game server port range for Valheim worlds. |

---

## Vanilla Worlds

A world can be created as **vanilla**: stock Valheim with zero mods and no BepInEx. Players join with the ordinary Valheim client, so no PhValheim client install is required.

Tick **Vanilla world (no mods)** when creating a world, or flip it later in the world's **Settings** modal (the world needs an update/restart to apply).

| Option | Notes |
|---|---|
| **Server Password** | Minimum 5 characters, and it cannot appear inside the world name — Valheim refuses to start otherwise. |
| **Crossplay** | Lets Xbox / Microsoft Store players join. **Not vanilla-only** — this setting is available on every world from its **Settings** modal. Note that players on those platforms cannot install mods, so a heavily modded world may not be joinable for them. |
| **List in server browser** | Publishes the world to the public Valheim community server list. Valheim requires a password for this. |
| **Show password on public UI** | On by default. Turn it off and the password row is removed from the world card entirely, for worlds whose password you share another way. |

Players see a dedicated card on the public UI with the server address, the password (**show** to reveal, **copy** to put it on the clipboard), and a **Join** button. They can also connect from Valheim's own *Join IP* screen using the address shown.

> **Note:** Valheim has no way to pre-fill a server password from a launch argument, so players type it at the prompt — which is why the card shows it.

Because these worlds run no mods, a few PhValheim features that depend on the companion mod do not apply to them: boss progression ("hung heads"), player join/leave events, and tick-health metrics — so the **HEALTH** bar is hidden for them in the admin UI.

**Seeds.** Valheim's dedicated server has no seed argument — the seed is fixed when the world is first generated. Choosing one needs the CustomSeed mod, which a vanilla world does not run, so the seed control is hidden when creating one. PhValheim reads the seed Valheim actually generated out of the world's `.fwl` after first start and displays that; until then the card shows *generated on first start*.

If you want a **specific** seed on a vanilla world, generate the world in the Valheim client (where you can type a seed) and use **Import World** — no mod required.

A vanilla world cannot have mods: **Edit Mods** is disabled for it, and turning the vanilla switch on clears any existing mod selection. Turn the switch back off to make it a modded world again, then use **Edit Mods** and run an **Update**.

Modded worlds are unaffected by any of this — they continue to be gated by the **Citizens** list.

---

## Citizens, Admins & Banned

Each world has three per-world player lists, all in the world's **Settings** modal:

- **Citizens** — who may join (`permittedlist.txt`). Setting a world **Public** here removes the restriction entirely and lets anyone join.
- **Admins** — who gets in-game admin commands (`adminlist.txt`).
- **Banned** — who is blocked from the world (`bannedlist.txt`). A ban applies even when the world is public.

### Getting a player's ID

**Have them join any public world and press `F2`.** The panel shows their **Platform User ID** — note it down and paste it in. This is the only method that works for every player: Xbox, PlayStation, Nintendo and GameCenter players have no SteamID64 at all.

A plain SteamID64 (17 digits) also works for Steam players. PhValheim stores what you type and converts it on write.

For Steam players you can also use **Look Up SteamID** in the Citizens editor — enter a Steam username and it returns the ready-to-paste `V_…` form. (Requires a Steam API key in Server Settings.)

> **Why the conversion:** since Valheim 1.0, a bare SteamID64 in these files **does not match**. `ZNet.ListContainsId()` finishes by looking up the *display-prefix* form of the ID (`Steam` → `V`) and **assigns** that result over the earlier checks rather than OR-ing it, so only `V_<steamid64>` can match. PhValheim writes that form for you. This is a bug in Valheim — it is also why Valheim's own `ban` console command writes an entry its own matcher cannot match, and why older "put your SteamID64 in permittedlist.txt" guides no longer work.

Changes take effect **without a restart** — Valheim re-reads all three files while running. The one exception is admin status, which a connected client caches until it reconnects.

The **database is the source of truth**. All three files are regenerated from it every time a world starts, so a world that was restored from a backup or rebuilt converges back to what the admin UI shows instead of quietly keeping an older list.

**Upgrading from 2.39 or earlier:** your stored IDs are converted to the `V_` form automatically on first start, and the Access tab explains it once. Entries that were already prefixed, and console IDs, are left alone; anything unrecognised is kept exactly as you left it rather than dropped.

> **Note:** A world's **Public** toggle controls the Citizens gate only. It does *not* publish the world to the Valheim server browser — that is the separate **List in server browser** option on vanilla worlds.

**Public World** sits at the top of the Access tab. Switching it on hides the Citizens editor, since the list is not consulted while a world is public — the list is kept, not cleared, and comes back when you switch it off.

---

## Custom Launch Parameters

Each world's **Settings** modal has a **Custom Launch Parameters** field, appended to the Valheim server command line after everything PhValheim generates, so it can override the defaults.

```
-saveinterval 900 -instanceid myserver
```

Shell metacharacters are rejected. Invalid Valheim arguments will stop the world from booting, with the reason only visible in the world log — change these one at a time.

---

## PhValheim Client

The server is only half the equation. **PhValheim Client** is a cross-platform companion app (Windows, Linux, macOS) that registers a custom `phvalheim://` URL protocol. When a player clicks a launch link:

1. The client compares the remote world's checksum against the local copy.
2. If outdated, it downloads the new payload (mods, configs, dependencies).
3. It launches Valheim, connecting to the correct server and world automatically.

Vanilla worlds skip steps 1 and 2 — there is no payload — and are joined directly, so players do not need the client for them at all.

| Platform | Installer | Config location |
|---|---|---|
| **Windows** | `.msi` | `%appdata%\PhValheim` |
| **Linux** | `.deb`, `.rpm`, or `.tar.gz` | `~/.config/PhValheim` |
| **macOS** | `macinstall.sh` (universal — Intel + Apple Silicon) | `~/Library/Application Support/PhValheim` |

> **Client repo:** [brianmiller/phvalheim-client](https://github.com/brianmiller/phvalheim-client)

---

## Architecture

### High Level
![PhValheim Architecture](https://user-images.githubusercontent.com/342276/197665349-c1ac282a-2a59-47ef-ae77-fee6e6f90094.png#gh-dark-mode-only)
![PhValheim Architecture](https://user-images.githubusercontent.com/342276/197665660-c6053d79-2bb2-4258-b9cb-da6e2571ada8.png#gh-light-mode-only)

### Authentication & Authorization
![PhValheim Steam Auth](https://user-images.githubusercontent.com/342276/197627136-32a342fe-60e2-4d08-843d-049b47c776de.png#gh-dark-mode-only)
![PhValheim Steam Auth](https://user-images.githubusercontent.com/342276/197627971-06511677-5126-4db7-9e8a-96e5b9665fc4.png#gh-light-mode-only)

Access to each world is controlled by the PhValheim database. Steam IDs are associated with each world, gating both the web UI (world visibility) and the game server allow-list.

---

## Reverse Proxy (Optional)

Example NGINX config for TLS termination:

```nginx
server {
    listen 80;
    server_name phvalheim.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name phvalheim.example.com;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 1200s;
        client_max_body_size 0;
    }
}
```

---

## Custom Config Folders

| Folder | Behavior |
|---|---|
| `custom_configs/` | Pushed to clients on world update. Use for shared game configs. |
| `custom_configs_secure/` | Server-only. Persists across updates but never sent to clients. |

---

## PSA: Mods & Stability

PhValheim makes mod management effortless, but not all mods play well together. If you're experiencing crashes or unexpected behavior, deploy a vanilla world (no mods) first to rule out mod conflicts. Most mod issues occur after major Valheim updates.

---

<p align="center">
  <a href="https://discord.gg/8RMMrJVQgy">Join the Discord</a> · <a href="https://github.com/brianmiller/phvalheim-server/issues">Report an Issue</a> · <a href="https://github.com/brianmiller/phvalheim-client">PhValheim Client</a>
</p>
