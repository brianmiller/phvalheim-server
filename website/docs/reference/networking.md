# Ports, storage and HTTPS

## Ports

| Port | Purpose |
| --- | --- |
| `8080/tcp` | Public player website |
| `8081/tcp` | Private admin website |
| `25000–26000/udp` | Default published range for world connections |

Keep the configured world base port inside the game range you publish. The admin UI should be reachable only through your trusted network or a tunnel. The [Compose guide](../start/install.md) binds it to localhost.

## Persistent storage

| Container path | What lives there |
| --- | --- |
| `/opt/stateful` | Database, worlds, mods, configuration and Vibes projects/toolchain |
| `/opt/stateful/backups` | World backup archives; a separate disk is recommended |

Keep both mounts through container replacement. A backup archive of a world is not a complete server-data backup. On Unraid, [pin appdata to the pool](../start/other-platforms.md#unraid).

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
