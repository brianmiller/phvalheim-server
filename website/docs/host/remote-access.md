# Remote Access (development build)

Remote Access is available for testing in the `:dev` image. It connects the player
website using your own Cloudflare or Tailscale account. It does not open game ports
or expose the admin interface. Existing player/world permissions still apply.

Cloudflare and Tailscale are **built into the PhValheim container**. Update your
existing instance to `theoriginalbrian/phvalheim-server:dev`, keeping its existing
data volume, environment, and port mappings. Then open **Remote Access** in admin.
No additional container, volume, published port, TUN device, or Docker capability
is required. Both clients stay stopped until you choose to connect or sign in.

The controller starts automatically under Supervisor. Credentials and device
identity persist privately in `/opt/stateful/remote-access` on the existing data
volume. Client processes run as a separate unprivileged user with no effective
capabilities. They cannot open the controller's admin socket; the web/application
user cannot read their credentials. Tailscale uses userspace networking.

## Account and network boundary

Use a provider account you control and a dedicated tunnel for this PhValheim
instance. Set Cloudflare's route to the **exact origin shown in the modal**. Its
fixed gateway serves the public player UI; it does not forward admin paths. Do not
add routes to the admin port or other services. Protect the provider account and
limit who can change its tunnel configuration.

The built-in clients share the container's network. A Cloudflare account owner
can change remotely managed routes; an unprivileged Unix user is **not a network
sandbox**. This integration does not claim to block arbitrary account-side routes
from reaching the LAN or other services. Host/network policy remains the boundary
for deployments that require that protection. The UI creates only the supported
player-website route and does not offer arbitrary forwarding targets.

## Open Remote Access

Open your usual PhValheim admin address on your trusted network and choose
**Remote Access**. Setup works from a LAN IP address or hostname, using the same
HTTP or HTTPS access you already use for admin. No terminal or port forwarding
is required. Use **Website** to choose Direct connection, Cloudflare, or Tailscale.
Use **Game connections** to see a world’s UDP ports and crossplay mode. The modal
shows only the selected method; connection details and troubleshooting expand on demand.

The admin interface remains a trusted-network interface; do not expose its port
to the internet. HTTP admin traffic, including entered credentials, is not
encrypted. Use your existing HTTPS admin address where available. The public
player website's TLS is separate from the connection to admin.

## Direct connection: your router and firewall

Select **Website → Direct connection** for the manual route. Publish container
TCP port 8080 to a host port in Docker, Unraid, or TrueNAS. Point your public
DNS/DDNS name at your public IP. For HTTPS, configure a reverse proxy targeting
that published host port, then forward TCP 443 on your router to the proxy and
allow it through the host firewall. Keep admin port 8081 private. See the
[networking guide](../reference/networking.md) for reverse proxy examples.

Host networking uses the service port directly. If you deliberately use plain
HTTP, forward the chosen external TCP port to the website's host port; that
traffic is unencrypted. A CGNAT connection generally cannot receive direct
inbound traffic; use Cloudflare or ask the ISP for a public address.

**Check local website** probes only the local player website. It cannot inspect
Docker host mappings or the router. Verify internet access by opening the website
from a phone with Wi-Fi off, then test a native-client download.

## Game connections: UDP or crossplay

Open **Game connections** and select a world. The view reads its assigned game
port and the next UDP port, never a generic default pair. It labels whether the
crossplay mode comes from the current running session, launch settings, or saved
settings. If saved crossplay differs from running evidence, restart is required.

For a **direct UDP** world:

1. Publish both UDP ports in Docker, Unraid, or TrueNAS, keeping host and container
   ports the same. With host networking, skip container port publishing.
2. Reserve the server's LAN IP in the router. Forward the world's two external UDP
   ports to that IP using the same ports. Allow both through the host firewall.
3. Start the world and test an actual join from another network using your public
   game address and the first port. Repeat the mapping for each direct world.

The modal shows your configured Game DNS and provides a copyable forwarding rule.
If game DNS is managed by Cloudflare, use a **DNS-only** record for game traffic.
Cloudflare's website proxy and the built-in private Tailscale Serve route do not
carry Valheim UDP. Website tests do not prove a game connection works.

For **crossplay**, Valheim uses the PlayFab relay and does not require inbound game
port forwarding. Start the world and share its join code or use the server list.
Outbound internet access is still required. Local and loopback IP joins are not
supported for crossplay. See [Valheim's dedicated server guide](https://www.valheimgame.com/support/a-guide-to-dedicated-servers/).

The view does not change firewall rules, world options, or port allocation. Port
range conflicts and unassigned ports are shown explicitly. A local check cannot
prove that UDP is reachable through a router; only an external game join verifies
that path. Double NAT may need forwarding at both routers; CGNAT generally needs
an ISP public address or crossplay.

## Cloudflare: public website

You need a Cloudflare account and a domain managed there.

1. In Cloudflare, open **Networking → Tunnels**, create a tunnel, and copy its
   connector token. PhValheim runs the connector, so do not run the installation
   command from Cloudflare.
2. Add a published application hostname on your domain. Select HTTP and use the
   origin shown in the modal: `http://127.0.0.1:18080`. This loopback-only gateway is built into
   PhValheim and forwards to the player website. Do not publish port 18080.
3. Select **Cloudflare** in Remote Access. After the route instructions, choose
   **I have my token**, enter the full HTTPS website URL and
   token, and choose **Connect and test**. Follow the failed check's instructions
   if DNS, TLS, routing, or provider connectivity needs attention.

Under **Save for later**, **Save without connecting** stores settings and keeps access off. Connect starts
the route immediately; tests can still be running or fail afterward. Use
**Disconnect** to stop it. Cloudflare account API keys are not needed.

The [official setup guide](https://developers.cloudflare.com/tunnel/get-started/)
describes the provider's dashboard. Account-side sign-in/bot challenges may work
in a browser while blocking the native PhValheim client's downloads; test both.

## Tailscale: private website

Players need Tailscale and permission to reach the server in your tailnet policy.

1. Select **Tailscale**, then choose **Sign in to Tailscale**.
   Open the generated link and approve the device in your account if required.
   An optional single-use auth key can be used instead of interactive sign-in.
2. Enable MagicDNS and HTTPS in Tailscale's DNS settings. Permit the intended
   devices/users to reach this device's HTTPS port 443 in your tailnet policy.
3. Choose **Connect and test**. Open the generated test link on another authorized
   Tailscale device, then return to PhValheim to see the browser test result.

PhValheim uses [Tailscale Serve](https://tailscale.com/docs/features/tailscale-serve),
not Funnel. It does not enable a game VPN, exit node, subnet routing, or SSH. The
device identity persists across container restarts and replacements. Re-enrollment may be needed
when the identity expires or the device is removed from the provider account.

## Read the connection checks

The modal polls real controller/provider observations. It separately reports the
connector, local website, DNS, certificate, correct-instance route challenge, and
a small diagnostic transfer. A running process alone never means the website is
ready. Failed checks provide the next action; stale evidence is labeled stale.

Cloudflare URL checks originate from the server. For an outside-network check,
open the test link from a phone on mobile data. Tailscale's private hostname must
be tested from another authorized device; the connector does not pretend a local
node test proves another user's permissions. A recorded browser test proves that
browser reached the route, not its network location, Steam login, or game access.

The small transfer is a diagnostic response, **not a full mod pack or archive-path
test**. Before inviting players, test Steam sign-in, a real native-client world
launch, and both config and full archive downloads. Provider archive-delivery
limits and policies still apply to operator-owned accounts.

Closing the modal leaves active connections and accepted tests running. **Cancel
test** cancels diagnostics only. **Disconnect** stops the connector and preserves
its saved settings. **Forget** also deletes local enrollment credentials/device
state. Remove unused DNS/tunnels or devices in the provider account separately.
Cloudflare token rotation alone does not disconnect existing connectors; follow
its [revocation guidance](https://developers.cloudflare.com/tunnel/reference/tunnel-tokens/)
if a token is compromised.

PhValheim world/config exports do not include Remote Access credentials. A host
backup of the entire data volume **does** include `/opt/stateful/remote-access`:
protect that backup or exclude this directory. A restored copy carries the same
device identity and must not run concurrently as an independent installation.
Disconnect and forget, then re-enroll a cloned installation instead.
