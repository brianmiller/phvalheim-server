<p align="center">
  <img src="https://github.com/brianmiller/docker-templates/raw/master/phvalheim-server/phvalheim-server.png" alt="PhValheim Logo" width="150">
</p>

<h1 align="center">PhValheim Server</h1>

<p align="center">
  <strong>The Ultimate Valheim World & Mod Manager</strong><br>
  Keep your server and all players perfectly in sync — automatically.
</p>

<p align="center">
  <a href="https://discord.gg/8RMMrJVQgy"><img src="https://img.shields.io/badge/Discord-Join%20Community-5865F2?style=for-the-badge&logo=discord&logoColor=white" alt="Discord"></a>
  <a href="https://store.steampowered.com/"><img src="https://img.shields.io/badge/Steam-Login%20Integration-000000?style=for-the-badge&logo=steam&logoColor=white" alt="Steam"></a>
  <a href="https://www.unraid.net/"><img src="https://img.shields.io/badge/Unraid-Template%20Available-F15A2C?style=for-the-badge" alt="Unraid"></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white" alt="Docker">
  <img src="https://img.shields.io/badge/PHP-8.1-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MariaDB-Database-003545?logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/Open%20Source-MIT-green" alt="Open Source">
</p>

---

## What is PhValheim?

PhValheim is a **two-part system** (server + client) that solves the biggest headache in modded Valheim: **keeping everyone in sync**.

While mod managers like Thunderstore work great for individuals, they don't coordinate across players. Eventually, someone's client drifts out of sync, mods break after updates, and chaos ensues.

**PhValheim fixes this.** When you click "Launch" on a world, the PhValheim Client automatically downloads exactly the right mods, configs, and dependencies — matching the server and every other player perfectly.

---

## Key Features

| Feature | Description |
|---------|-------------|
| **One-Click Worlds** | Deploy new worlds instantly with any combination of mods |
| **Automatic Sync** | Client automatically downloads matching mod payloads |
| **Steam Login** | Secure authentication via Steam — no separate accounts |
| **Per-World Access** | Control which players can see and join each world |
| **Thunderstore Integration** | Browse and add mods directly from the Thunderstore catalog |
| **Auto-Backups** | World files backed up every 30 minutes |
| **Boss Progress Tracking** | Visual trophy display shows which bosses have been defeated |
| **Real-Time Monitoring** | Live memory usage and world status in the admin UI |

---

## How It Works

### The Big Picture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           PhValheim Server                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │
│  │   World 1   │  │   World 2   │  │   World 3   │  │     ...     │    │
│  │  + Mods A   │  │  + Mods B   │  │  Vanilla    │  │             │    │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘    │
│                              │                                          │
│              ┌───────────────┴───────────────┐                         │
│              │     PhValheim Engine          │                         │
│              │  • Builds client payloads     │                         │
│              │  • Manages world lifecycles   │                         │
│              │  • Syncs Thunderstore mods    │                         │
│              └───────────────────────────────┘                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
            ┌──────────────┐                ┌──────────────┐
            │   Player 1   │                │   Player 2   │
            │ PhValheim    │                │ PhValheim    │
            │   Client     │                │   Client     │
            └──────────────┘                └──────────────┘
```

### Player Workflow

1. **Login** — Authenticate with your Steam account
2. **Browse** — See all worlds you have access to
3. **Click Launch** — PhValheim Client handles everything:
   - Compares your local files with the server
   - Downloads updates if needed (mods, configs, dependencies)
   - Launches Valheim and connects you automatically

That's it. No manual mod management. No version mismatches. Just play.

---

## Screenshots

### Admin Dashboard
*Modern dark theme with real-time world monitoring, resource usage, and quick actions.*

<!-- TODO: Add screenshot of new admin dashboard here -->
<!-- ![Admin Dashboard](screenshots/admin-dashboard.png) -->

### World Management
*Create, configure, and manage worlds with an intuitive interface.*

<!-- TODO: Add screenshot of world management here -->
<!-- ![World Management](screenshots/world-management.png) -->

### Mod Selection
*Browse the complete Thunderstore catalog and add mods with one click.*

<!-- TODO: Add screenshot of mod browser here -->
<!-- ![Mod Browser](screenshots/mod-browser.png) -->

### Player Portal
*Clean, simple interface for players to see their worlds and launch the game.*

<!-- TODO: Add screenshot of player portal here -->
<!-- ![Player Portal](screenshots/player-portal.png) -->

### Citizens Management
*Control access per-world with Steam ID integration.*

<!-- TODO: Add screenshot of citizens editor here -->
<!-- ![Citizens Editor](screenshots/citizens-editor.png) -->

---

## Quick Start

### Prerequisites

- Docker installed on your host system
- A [Steam API Key](#getting-a-steam-api-key)
- Port forwarding configured for game traffic (UDP)

### Deploy with Docker

```bash
docker create \
  --name phvalheim-server \
  -p 8080:8080/tcp \
  -p 8081:8081/tcp \
  -p 25000-26000:25000-26000/udp \
  -e basePort=25000 \
  -e defaultSeed=szN8qp2lBn \
  -e backupsToKeep=10 \
  -e phvalheimHost=phvalheim.example.com \
  -e gameDNS=game.example.com \
  -e steamAPIKey=YOUR_STEAM_API_KEY \
  -e phvalheimClientURL=https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe \
  -v /path/to/data:/opt/stateful:Z \
  -v /path/to/backups:/opt/stateful/backups:Z \
  theoriginalbrian/phvalheim-server:latest

docker start phvalheim-server
```

### Deploy with Unraid

Search for **PhValheim** in the Community Applications store and follow the template.

![Unraid Deploy](https://user-images.githubusercontent.com/342276/197680052-109f4145-192e-4e97-a3fb-6aa950c9a128.png)

---

## Configuration

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `basePort` | Yes | Starting port for worlds (e.g., `25000`). Each world uses the next available port. |
| `defaultSeed` | Yes | Default world seed when none is specified during creation. |
| `backupsToKeep` | Yes | Number of backup copies to retain per world. |
| `phvalheimHost` | Yes | Public FQDN for the web interface (e.g., `phvalheim.example.com`). |
| `gameDNS` | Yes | FQDN players connect to for game traffic. Can match `phvalheimHost`. |
| `steamAPIKey` | Yes | Your Steam Web API key for authentication. [Get one here](#getting-a-steam-api-key). |
| `phvalheimClientURL` | Yes | URL where players download the PhValheim Client. |

### Volumes

| Container Path | Purpose |
|----------------|---------|
| `/opt/stateful` | All persistent data: worlds, mods, database, configs |
| `/opt/stateful/backups` | World backups. **Tip:** Mount to a separate disk for redundancy. |

### Ports

| Port | Protocol | Purpose |
|------|----------|---------|
| `8080` | TCP | **Public web interface** — Expose this to players |
| `8081` | TCP | **Admin interface** — Keep this private! |
| `25000-26000` | UDP | **Game traffic** — Each world uses one port from this range |

> **Security Note:** Never expose port 8081 to the public internet. The admin interface has full control over your server.

---

## PhValheim Client

The client is a lightweight Windows application that makes the magic happen. It registers a custom `phvalheim://` URL protocol so clicking "Launch" in your browser opens the game with the right configuration.

### What It Does

1. Receives the launch URL from your browser
2. Compares your local mod payload with the server's MD5 hash
3. Downloads updates if needed
4. Configures BepInEx and mods in your Valheim installation
5. Launches the game and connects to the server

### Installation

Download the client from the button in the PhValheim web interface, or directly from the [PhValheim Client repository](https://github.com/brianmiller/phvalheim-client).

Files are installed to `%APPDATA%/PhValheim`. The client also adds the necessary BepInEx bootstrap files to your Valheim Steam directory.

---

## Advanced Configuration

### Reverse Proxy (Optional)

For HTTPS support, use a reverse proxy. Here's an example NGINX configuration:

<details>
<summary>Click to expand NGINX config</summary>

```nginx
server {
    listen 80;
    server_name phvalheim.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name phvalheim.example.com;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_session_cache shared:SSL:10m;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 1200s;
        client_max_body_size 0;
    }
}
```

</details>

### Custom Config Folders

| Folder | Purpose |
|--------|---------|
| `custom_configs/` | Configs that sync to all clients during updates |
| `custom_configs_secure/` | Server-only configs that persist but don't sync to clients |

---

## Getting a Steam API Key

1. Go to the [Steam Web API Key Registration](https://steamcommunity.com/dev/apikey) page
2. Enter your PhValheim server's public domain name
3. Copy the generated key

![Steam API Key](https://user-images.githubusercontent.com/342276/198714634-2595eeb6-fb6a-458f-a951-60e81154a087.png)

> **Keep your API key secret!** Never commit it to version control or share it publicly.

---

## Architecture Deep Dive

<details>
<summary>Click to expand technical architecture</summary>

### Container Services

| Service | Role |
|---------|------|
| **PhValheim Engine** | Core orchestrator — handles world lifecycle, builds client payloads, processes commands |
| **Supervisor** | Process manager — monitors and controls all services including individual world processes |
| **NGINX** | Web server — serves public (8080) and admin (8081) interfaces |
| **MariaDB** | Database — stores world configs, player access, mod selections |
| **Cron** | Scheduler — runs Thunderstore sync (12hr), backups (30min), utilization monitoring |

### Authentication Flow

```
Player                    PhValheim                 Steam
  │                          │                        │
  │  Click "Login with Steam"│                        │
  │ ────────────────────────>│                        │
  │                          │  OpenID Auth Request   │
  │                          │ ──────────────────────>│
  │                          │                        │
  │          Redirect to Steam Login                  │
  │ <─────────────────────────────────────────────────│
  │                          │                        │
  │        User authenticates with Steam              │
  │ ─────────────────────────────────────────────────>│
  │                          │                        │
  │          Redirect back with SteamID               │
  │ <─────────────────────────────────────────────────│
  │                          │                        │
  │  Verify SteamID          │                        │
  │ ────────────────────────>│  Validate via API     │
  │                          │ ──────────────────────>│
  │                          │        ✓ Valid         │
  │                          │ <──────────────────────│
  │      Show authorized worlds                       │
  │ <────────────────────────│                        │
```

</details>

---

## Troubleshooting

### Mods Not Working?

Before reporting issues, try deploying a **vanilla world** (no mods selected). Most problems come from mod conflicts or outdated mods after game updates.

### Common Issues

| Problem | Solution |
|---------|----------|
| Can't connect to world | Check that UDP ports are forwarded and `gameDNS` is correct |
| Steam login fails | Verify your `steamAPIKey` is valid and the domain matches |
| Client not launching | Ensure PhValheim Client is installed and the `phvalheim://` protocol is registered |
| Mods out of sync | Click "Update" on the world in admin to rebuild the client payload |

---

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests.

---

## Support

Need help? Join our [Discord community](https://discord.gg/8RMMrJVQgy) for support and discussion.

---

<p align="center">
  <sub>Made with passion for the Valheim community</sub>
</p>
