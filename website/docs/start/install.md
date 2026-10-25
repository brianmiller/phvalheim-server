# Set up your server

You'll need a host that can run Docker and Valheim's dedicated server, space for your worlds and mods, and a Steam API key for player sign-in. More worlds and larger mod collections need more resources.

This guide uses Docker Compose. [Using Unraid or Kubernetes instead?](other-platforms.md)

## 1. Create your Compose file

Make a folder for PhValheim and save this as `compose.yaml`. Replace the two host paths with real directories. Put backups on a separate disk if you have one.

```yaml
services:
  phvalheim:
    image: theoriginalbrian/phvalheim-server:latest
    container_name: phvalheim
    ports:
      - "8080:8080/tcp"                     # Player website
      - "127.0.0.1:8081:8081/tcp"           # Admin website: this host only
      - "25000-26000:25000-26000/udp"       # World connections
    volumes:
      - /path/to/data:/opt/stateful:Z
      - /path/to/backups:/opt/stateful/backups:Z
    restart: unless-stopped
```

!!! important "Keep your data when replacing the container"
    `/opt/stateful` contains your database, worlds and configuration. Keep this volume through upgrades. The admin website controls your server; keep port 8081 private.

## 2. Start PhValheim

From the folder containing the file:

```bash
docker compose up -d
```

The first start prepares the database and services. Give it time to finish before opening the setup wizard.

## 3. Open the setup wizard

On the Docker host, open **http://localhost:8081**.

If Docker runs on another machine, tunnel the private admin port from your computer:

```bash
ssh -L 8081:127.0.0.1:8081 your-user@your-server
```

Keep that terminal open, then visit **http://localhost:8081** on your computer.

The wizard asks for:

- **Steam API key:** used for Steam player sign-in. [Create a key with Steam](https://steamcommunity.com/dev/apikey).
- **PhValheim Host:** the hostname players use for your website.
- **Game DNS:** the hostname players use for world connections.
- **Base Port:** the first world port; keep it inside the range published by Docker.

These settings live in the admin UI. New installations do not need a collection of environment variables.

## 4. Make it reachable

Players use the public website on port **8080** and the world's game connection. Forward the appropriate game UDP ports through your firewall/router. A reverse proxy can give the public website HTTPS on your domain.

[Ports, storage and HTTPS →](../reference/networking.md)

## Next: create a world

You should now see the admin dashboard. [Create your first world and invite someone →](first-world.md)
