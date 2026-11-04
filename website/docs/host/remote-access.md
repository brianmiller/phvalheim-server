# Remote Access (development build)

Remote Access is available for testing in the `:dev` image. It connects the player
website using your own Cloudflare or Tailscale account. It does not open game ports
or expose the admin interface. Existing player/world permissions still apply.

The feature uses an optional **secure connector container** with the same image.
The extra container keeps provider routes away from the admin UI and your LAN.
Credentials stay in its private state volume; the containers share only local
sockets and short-lived connection-test data.

## Install the connector once

Use [the Compose example](https://github.com/brianmiller/phvalheim-server/blob/feature/remote-access-262/deploy/remote-access.compose.yml)
as a reference. **Merge its connector and socket-volume settings into your existing
deployment. Keep your existing data volume, environment, and game port mappings.**
Starting the example unchanged creates a separate empty instance.

1. Use the same `theoriginalbrian/phvalheim-server:dev` image for both containers.
2. Add a dedicated shared volume mounted at `/run/phvalheim-remote` in both.
3. Add a private volume mounted at `/var/lib/phvalheim-remote` in the connector only.
4. Use the connector entrypoint, capabilities, temporary filesystems, and separate
   bridge network from the example. Publish **no connector ports**. Do not use host
   networking, share the application's network namespace, or mount the Docker socket.
5. Recreate the containers with your deployment tool, then open **Remote Access** in
   PhValheim's admin navigation. It will report whether the connector is available.

The connector starts with network-administration permission only to install its
own firewall. It then permanently drops that permission. Its controller retains
only process identity/ownership/signal permissions, and provider processes run
under a separate user with no effective capabilities. They cannot open the admin
control socket. Only the player UI is reachable through the shared origin socket.

The initial transport supports IPv4 internet connections and blocks private,
loopback (except fixed internal services), link-local, metadata, and reserved
destinations. IPv6 transport is blocked to prevent bypasses. Tailscale can use public
relays when direct private-network peer paths are blocked. Public provider HTTPS,
DNS, and UDP traffic are allowed; this is not an allowlist of every provider IP.

This setup is validated with Docker on Linux. Unraid and TrueNAS require equivalent
separate-container settings; native platform installation flows are not yet
validated. Do not weaken isolation to work around a platform restriction. A missing
capability or failed firewall initialization leaves the connector stopped.

## Open admin securely

Enter tokens only through an HTTPS admin connection or a browser's loopback
connection. On the Docker host, use `http://localhost:8081`. From another computer,
forward the admin port through SSH:

```bash
ssh -L 8081:127.0.0.1:8081 USER@YOUR_SERVER
```

Replace the placeholders with your SSH login/server, keep that terminal open, and
open `http://localhost:8081` on your computer. If that local port is occupied, choose
another local port and use it in the browser. A public HTTPS tunnel does not secure
an existing plain HTTP session to the private admin UI.

The current admin interface is a trusted-network interface. Keep it bound to
loopback or protected by your existing private admin access controls. Forwarded
HTTPS headers alone do not authorize entering secrets into Remote Access.

## Cloudflare: public website

You need a Cloudflare account and a domain managed there.

1. In Cloudflare, open **Networking → Tunnels**, create a tunnel, and copy its
   connector token. PhValheim runs the connector, so do not run the installation
   command from Cloudflare.
2. Add a published application hostname on your domain. Select HTTP and use the
   origin shown in the modal: `http://127.0.0.1:8080`. This is inside the isolated
   connector, where a fixed gateway forwards to the player website.
3. Select **Cloudflare** in Remote Access, enter the full HTTPS website URL and
   token, and choose **Connect and test**. Follow the failed check's instructions
   if DNS, TLS, routing, or provider connectivity needs attention.

**Save without connecting** stores settings and keeps access off. Connect starts
the route immediately; tests can still be running or fail afterward. Use
**Disconnect** to stop it. Cloudflare account API keys are not needed.

The [official setup guide](https://developers.cloudflare.com/tunnel/get-started/)
describes the provider's dashboard. Account-side sign-in/bot challenges may work
in a browser while blocking the native PhValheim client's downloads; test both.

## Tailscale: private website

Players need Tailscale and permission to reach the server in your tailnet policy.

1. Select **Tailscale**, save the settings, then choose **Sign in to Tailscale**.
   Open the generated link and approve the device in your account if required.
   An optional single-use auth key can be used instead of interactive sign-in.
2. Enable MagicDNS and HTTPS in Tailscale's DNS settings. Permit the intended
   devices/users to reach this device's HTTPS port 443 in your tailnet policy.
3. Choose **Connect and test**. Open the generated test link on another authorized
   Tailscale device, then return to PhValheim to see the browser test result.

PhValheim uses [Tailscale Serve](https://tailscale.com/docs/features/tailscale-serve),
not Funnel. It does not enable a game VPN, exit node, subnet routing, or SSH. The
device identity persists across connector restarts. Re-enrollment may be needed
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

Keep connector state out of ordinary exports/backups. A restored copy of its
private volume carries the same device identity and must not be run concurrently
as an independent installation. Re-enroll a new installation instead.
