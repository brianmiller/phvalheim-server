# Ports, storage and HTTPS

## Ports

| Port | Purpose |
| --- | --- |
| `8080/tcp` | Public player website |
| `8081/tcp` | Private admin website |
| `25000–26000/udp` | Range published in the Docker Compose example |

Each world uses its assigned UDP port and the next port. Publish both ports for every world;
keeping only the base port in range is not enough. The admin UI should be reachable only
through your trusted network or a tunnel. The [Compose guide](../start/install.md) binds it
to localhost.

## Persistent storage

| Container path | What lives there |
| --- | --- |
| `/opt/stateful` | Database, worlds, mods, configuration and Vibes projects/toolchain |
| `/opt/stateful/backups` | World backup archives; a separate disk is recommended |

Keep both mounts through container replacement. A backup archive of a world is not a complete server-data backup. On Unraid, [pin appdata to the pool](../start/other-platforms.md#unraid).

## Remote Access development preview

The `:dev` image includes built-in clients for Cloudflare Tunnel and
private Tailscale Serve. See [Remote Access setup](../host/remote-access.md) for
guided configuration, live checks, and current limits. No extra container or
mount is required. Game UDP
ports remain separate. This preview does not change the stable installation.

## Public website behind NGINX

This example terminates HTTPS for the **player website**, forwarding to port 8080. Replace the domain and certificate paths. Adjust the upstream if NGINX runs in a separate container or on a different host.

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

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 1200s;
        client_max_body_size 0;
    }
}
```

Set PhValheim's public hostname in Server Settings to match. Website HTTPS does not forward the game's UDP connections; configure those separately.
