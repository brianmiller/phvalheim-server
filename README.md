# PhValheim Server

[![made-with-Docker](https://img.shields.io/badge/Made%20with-Docker-2496ed.svg)](https://www.docker.com/)
[![made-with-Docker](https://img.shields.io/badge/Templatized%20for-Unraid-f4672d.svg)](https://www.unraid.net/)
[![made-with-BASH](https://img.shields.io/badge/Made%20with-BASH-a32c29.svg)](https://www.gnu.org/software/bash/)
[![made-with-PHP](https://img.shields.io/badge/Made%20with-PHP-7a86b8.svg)](https://www.php.net/)
[![made-with-MariaDB](https://img.shields.io/badge/Made%20with-MariaDB-013545.svg)](https://mariadb.org/)
[![login-with-Steam](https://img.shields.io/badge/Login%20with-Steam-5d7e0f.svg)](https://store.steampowered.com/)
[![Open Source Love png1](https://badges.frapsoft.com/os/v1/open-source.png?v=103)](https://en.wikipedia.org/wiki/Open-source_software)
<br><br>
Need help?<br>
[![join-discord](https://img.shields.io/badge/Join%20Our-Discord-5865f2)](https://discord.gg/8RMMrJVQgy)
<hr>

##### Navigation
[![jump-to-features](https://img.shields.io/badge/Jump%20To-features-da70d6)](https://github.com/brianmiller/phvalheim-server#what-are-the-features-of-phvalheim)
<br>
[![jump-to-howitworks](https://img.shields.io/badge/Jump%20To-how%20it%20works-98FB98)](https://github.com/brianmiller/phvalheim-server#how-does-it-work)
<br>
[![jump-to-client](https://img.shields.io/badge/Jump%20To-PhvValheim%20client-da70d6)](https://github.com/brianmiller/phvalheim-server#phvalheim-client)
<br>
[![jump-to-architecture](https://img.shields.io/badge/Jump%20To-architecture-98FB98)](https://github.com/brianmiller/phvalheim-server#architecture)
<br>
[![jump-to-screenshots](https://img.shields.io/badge/Jump%20To-screenshots-da70d6)](https://github.com/brianmiller/phvalheim-server#screenshots)
<br>
[![jump-to-containerdeployment](https://img.shields.io/badge/Jump%20To-container%20deployment-98FB98)](https://github.com/brianmiller/phvalheim-server#deployment)
<br>
[![jump-to-reverseproxy](https://img.shields.io/badge/Jump%20To-reverse%20proxy%20example-da70d6)](https://github.com/brianmiller/phvalheim-server#reverse-proxy-config-example)
<br>
[![jump-to-variables](https://img.shields.io/badge/Jump%20To-variables-98FB98)](https://github.com/brianmiller/phvalheim-server#container-variables)
<br>
[![jump-to-volumes](https://img.shields.io/badge/Jump%20To-volumes-da70d6)](https://github.com/brianmiller/phvalheim-server#container-volumes-and-persistent-storage)
<br>
[![jump-to-variables](https://img.shields.io/badge/Jump%20To-ports-98FB98)](https://github.com/brianmiller/phvalheim-server#container-ports)
<br>
[![jump-to-steamapikeyt](https://img.shields.io/badge/Jump%20To-Steam%20API%20Key-da70d6)](https://github.com/brianmiller/phvalheim-server/blob/master/README.md#generate-your-steam-api-key)



#### What is it?
PhValheim is a two-part world and client manager for Valheim (with aspirations of becoming game agnostic), it keeps server and client files in lock-step, ensuring all players have the same experience.

#### <i>PhValheim is constently being improved. Make sure you maintain backups of your stateful data, beyond what PhValheim already backs up.</i>

#### Why?
Valheim is a fantastic game and the more we play the more we want. Modding Valheim is simple to do but difficult to manage across all players. Keeping the remote server and clients in-sync are nearly impossible without something like PhValheim.  While mod managers work well (Thunderstore and Nexus Mods), they don't work in a federated manner, eventaully resulting in clients being out of sync with each other and the remote server. PhValheim's primary goal is to solve this problem.

#### PSA
Mods are great and PhValheim makes it stupid simple to install them but keep in mind that the primary reason your game may not be working is due to the combination of mods you're using. Not all mods are made equal and most mods become "broken" after a major game update. If you have issues with your game running, please be sure to deploy a vanilla world (no mods selected) before submitted an issue.

#### What are the features of PhValheim?
- Runs in a single Docker container
- Login with Steam (SteamAPIKey is required)
- Quickly deploy unique worlds at the click of a button, with any combination of mods.
- Deploy any world with a specified Seed, or NULL will deploy a "default" Seed, provided during container deployment.
- Automatically deploys "required" mods, ensuring mandatory mods are always running.
- Manage a unique "allowlist" of users for each world.
- Global and unique world logs files for every aspect of PhValheim and its running processes.
- Update a world and all linked mods at the click of a button.
- Stores copies of recently downloaded mods for reuseability.
- Automatically backs up all world files every 30 minutes (can be pointed to disparate disks to ensure storage diversity).
- The Public web interface displays current MD5SUM of world client payload, created and last updated timestamps, active memory that the world is consuming, "PhValheim Client Download Link", and an instant "Launch!" link.
- The Admin web interface provides access to all manager features, which are completely isolated from the public interface.

#### How does it work?
#### Server
As mentioned above, PhValheim Server runs in a docker container.  Out-of-the-box the container runs a few services:
 - PhValheim Engine
    - The engine is responsible for all communication and execution between the supporting services mentioned below and the game's engine.
        - Listens for engine commands (create, start, stop, update, delete)
        - Builds client payloads after world creation and world updates.
 - CRON (scheduler)
    - tsStoreStync
      - Syncs Thunderstore's entire Valheim Mod database every 12hrs (just the metadata)
    - worldBackup
      - Backs up all worlds every 30 minutes (default is 10 backups to keep, configurable)
    - utilizationMonitor
      - Brings real-time utilization of each world and process. Currently only provides real-time memory utilization for each world which is displayed on the public interface.
 - Supervisor
   - The process watcher and executor. Supervisor manages all PhValheim processes, including every world deployed.
 - NGINX
   - All Public and Admin interfaces are published via NGINX.
 - MariaDB
   - All stateful (minus the Valheim and Steam binaries) are stored in MariaDB.

# PhValheim Client
All of this is great but useless without a way to ensure the client behaves as expected.  This is where PhValheim Client comes in. PhValheim Client is a mandatory companion application that runs on all Windows clients.  It's a small C# application that registers a new "phvalheim://" URL to your Windows Registry.  This allows your computer to recgonize and understand PhValheim Server payload URLs. When a PhValheim URL is clicked, the PhValheim Client pulls the URL into memory and decodes the information, instructing your PC what to do.
#### Here's the workflow (after the client has been installed)
1. Click a "phvalheim://" URL
2. Windows will send the URL to PhValheim Client's binary.
3. PhValheim Client will decode the URL, extracting the necessary information to:
   - Compare the remote world's MD5SUM (from PHValehim Server's database) with the local copy. If the local copy matches, proceed. Otherwise, download the new payload.
     - This payload includes all necessary mods, configs and depedencies to match the remote world and your fellow player's world.
   - Instruct PhValheim Client which PhValheim Server to connect to and which world endpoint to connect to.
#### Note: PhValheim Client installs all related files to %appdata%/PhValheim, in addition to modifying your local install of Valheim's Steam directory to include the necessary bootstrap libraries for BepInEx to properly run (all mod managers do this already).

# Architecture
## Highlevel
![PhValheim-overall](https://user-images.githubusercontent.com/342276/197665349-c1ac282a-2a59-47ef-ae77-fee6e6f90094.png#gh-dark-mode-only)
![PhValheim-overall-w](https://user-images.githubusercontent.com/342276/197665660-c6053d79-2bb2-4258-b9cb-da6e2571ada8.png#gh-light-mode-only)


## Authentication and Authorization
Access to each world is controlled by the PhValheim database. We associate the SteamID of each player to every world we want to grant access to.  The PhValheim Server will store SteamIDs to grant access to both the public web UI (library of worlds) and the allowList.txt file associated with each world.  This ensures only allowed "citizens" can see the world in the UI and make a logical connection to the world itself (UDP).
#### Here is a swimlane depiction of the authentication and authorization workflow:
![PhValheim-steam](https://user-images.githubusercontent.com/342276/197627136-32a342fe-60e2-4d08-843d-049b47c776de.png#gh-dark-mode-only)
![PhValheim-steam-w](https://user-images.githubusercontent.com/342276/197627971-06511677-5126-4db7-9e8a-96e5b9665fc4.png#gh-light-mode-only)
 

# Screenshots
### Public Home
![public_home_screenshot](https://user-images.githubusercontent.com/342276/197672941-2e765e9f-a609-46fa-ab56-40eb5dff0264.png)

### Admin Home
<i>http://[dockerhost]:8081</i>
<br>
![admin_home_screenshot](https://user-images.githubusercontent.com/342276/197673053-a2010a69-75e4-4981-a573-a14cc81a188b.png)

### New World
![admin_newworld_screenshot](https://user-images.githubusercontent.com/342276/197673105-81e41a69-6a8b-48e4-b8c0-3ee8bb946a94.png)

### Mods Editor
![admin_modeditor_screenshot](https://user-images.githubusercontent.com/342276/197673179-0130fad0-2b17-42c2-88c5-a8d92738ee3b.png)

### Citizens Editor
![admin_citizenseditor_screenshot](https://user-images.githubusercontent.com/342276/197673205-290730dd-c92a-4769-aedf-916283d3b906.png)

### Engine Logs
![admin_enginelogs_screenshot](https://user-images.githubusercontent.com/342276/197674357-40c91ec3-cb06-4a94-ab15-342f6b9ca37c.png)

### World Log
![admin_worldlogs_screenshot](https://user-images.githubusercontent.com/342276/197674387-8e0877c1-d15f-4604-919a-4d970965f1f3.png)

### File Manager
![admin_filemanager_screenshot](https://user-images.githubusercontent.com/342276/197674464-445eaa0b-1f29-40c8-b7f3-e75f990aa8ec.png)


# Deployment
### Unraid (search for PhValheim in the community app store)
![unraid_deploy](https://user-images.githubusercontent.com/342276/197680052-109f4145-192e-4e97-a3fb-6aa950c9a128.png)

### Docker Command Line
```
docker create \
       --name='myPhValheim-server1' \
               -p '8080:8080/tcp' \
               -p '8081:8081/tcp' \
               -p '25000-26000:25000-26000/udp' \
               -e 'basePort'='25000' \
               -e 'defaultSeed'='szN8qp2lBn' \
               -e 'backupsToKeep'='10' \
               -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
               -e 'gameDNS'='37648-dev1.phospher.com' \
               -e 'steamAPIKey'='0123456789' \
               -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
               -v '/mnt/docker_persistent/phvalheim':'/opt/stateful':Z \
               -v '/mnt/phvalheim_backups/':'/opt/stateful/backups':Z \
               theoriginalbrian/phvalheim-server:latest``

docker start myPhValheim-server1
```
### Container Variables
#### <i>all variables are mandatory</i>
| Variable | Description |
| --- | -- |
| basePort | The port your first world will use. This must be the beginning of the port range passed to the container on start. |
| defaultSeed | The seed used when a seed isn't provided during world creation. |
| backupsToKeep | How many backups to keep on disk before deleting the oldest. |
| phvalheimHost | The FQDN of your PhValheim server your users will connect to. |
| gameDNS | The FQDN your users will connected their Valheim client to. This can be the same as phvalheimHost, if needed. |
| steamAPIKey | Your SteamAPI key used for player identification and authorization. See [Generate Steam API Key](https://github.com/brianmiller/phvalheim-server/blob/master/README.md#generate-your-steam-api-key) for help. |
| phvalheimClientURL | The hosted URL for PhValheim Client downloads. You can change this if you prefer to host your own client files. |


### Container Volumes and Persistent Storage
#### <i>all paths are mandatory</i>
| Container Path | Description |
| --- | -- |
| /opt/stateful | This volume stores all persistent data for your worlds, mods, PhValheim database, and configuration files. |
| /opt/stateful/backups | This volume is the destination of world backups. It's a good idea to set this to a different disk for disaster recovery. |


### Container Ports
#### <i>all ports are mandatory</i>
| Container Port | Description |
| --- | -- |
| 8080/tcp | This is the port the internal web service listens on and is the interface you want exposed to the public.  Without this, nothing works. |
| 8081/tcp | This is the port the admin interface listens on. This should ``` not ``` be accessible from the public internet. ``` Do not ``` forward this port in your firewall/router unless you know what you're doing. |
| 25000-26000/udp | This is the port range used by PhValheim worlds. |


### Reverse Proxy Config Example
#### <i>Optional: Here's the config I use for my reverse proxy. It's a standard proxy pass config that helps with TLS termination.</i>

```
server {
        listen 80;
        server_name phvalheim.phospher.com;
        rewrite ^ https://$http_host$request_uri? permanent;
        server_tokens off;
}

server {
        listen 443 ssl;
        server_name phvalheim.phospher.com phvalheim;

        ssl_certificate /mnt/certs/live/phospher.com/fullchain.pem;
        ssl_certificate_key /mnt/certs/live/phospher.com/privkey.pem;
        ssl_session_cache shared:SSL:10m;

        ssl_stapling on;
        ssl_stapling_verify on;
        #ssl_verify_client off;

        real_ip_header     X-Forwarded-For;
        real_ip_recursive  on;

        location / {
                proxy_pass http://37648-dev1.phospher.com:8080;
                proxy_set_header Host $host;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Host $server_name;
                proxy_set_header X-Forwarded-Proto https;
                proxy_request_buffering off;
                access_log /config/nginx/logs/phvalheim.access.log;
                error_log /config/nginx/logs/phvalheim.error.log;
                proxy_read_timeout 1200s;
                client_max_body_size 0;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection $connection_upgrade;
        }
}
```

### Reference Material
#### Generate your Steam API Key
 - Navigate to the [Register Steam Web API Key](https://steamcommunity.com/dev/apikey) portal to generate your key.  Enter your PhValheim's external DNS hostname and copy your key. This is the key you must pass to PhValheim's "SteamAPIKey" variable on start.
#### <i>Do not share your key. The one below has been revoked ;)</i>
![image](https://user-images.githubusercontent.com/342276/198714634-2595eeb6-fb6a-458f-a951-60e81154a087.png)
![image](https://user-images.githubusercontent.com/342276/198714723-107f95db-b66f-433f-8d23-dc8df6cb1a67.png)

