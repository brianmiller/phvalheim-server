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

---

## Features

| | |
|---|---|
| **One-Click Worlds** | Deploy unique Valheim worlds with any combination of Thunderstore mods at the click of a button. |
| **Automatic Mod Sync** | Server and client mods stay in lock-step. Players always have the right files. |
| **Setup Wizard** | Guided first-run configuration — just start the container and follow the steps. No environment variables required. |
| **Steam Authentication** | Players log in with their Steam account. Per-world access control lists manage who can see and join each world. |
| **Thunderstore Integration** | Full Thunderstore mod catalog synced every 12 hours. Search, select, and deploy mods with dependency resolution built in. |
| **Automatic Backups** | All worlds backed up every 30 minutes. Configurable retention. Supports separate backup volumes for disaster recovery. |
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

## PhValheim Client

The server is only half the equation. **PhValheim Client** is a companion Windows app that registers a custom `phvalheim://` URL protocol. When a player clicks a launch link:

1. The client compares the remote world's checksum against the local copy.
2. If outdated, it downloads the new payload (mods, configs, dependencies).
3. It launches Valheim, connecting to the correct server and world automatically.

Client files install to `%appdata%/PhValheim` and add the BepInEx bootstrap to your Valheim Steam directory.

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
