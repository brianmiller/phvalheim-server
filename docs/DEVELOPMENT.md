# Local contributor environment

Use an x86-64 Linux host or VM with Docker Compose v2+, Git, Bash, PHP CLI 8.1+,
Python 3, Node.js, jq, zip and unzip. Start with the clone and standalone tests in
[CONTRIBUTING.md](../CONTRIBUTING.md). No production data or credentials are needed
for the setup and disposable integration checks below.

## Build and start your checkout

From the repository root:

```bash
docker compose -f compose.dev.yaml build
docker compose -f compose.dev.yaml up -d --wait --wait-timeout 360
docker compose -f compose.dev.yaml ps
```

The image is `phvalheim-contributor:local`, built from this checkout. After changing
runtime files, repeat **both** build and up commands; source is not bind-mounted.
The initial build needs internet access for Ubuntu packages and SteamCMD. Startup
may synchronize public mod catalogues; no world is created automatically.

Open the admin setup at <http://localhost:18081> and the player portal at
<http://localhost:18080>. The setup wizard is the expected fresh-install screen.
Steam sign-in and live gameplay require your own Steam configuration and game;
they are not prerequisites for the disposable tests below.

The `phvalheim-contributor` Compose project has its own `data` volume. Web and game
ports bind to localhost. On a remote development machine, forward web ports through
SSH instead of exposing the admin interface. For simultaneous checkouts, choose a
different Compose project and unused ports, for example:

```bash
PHV_DEV_PUBLIC_PORT=18090 PHV_DEV_ADMIN_PORT=18091 \
PHV_DEV_GAME_START=25200 PHV_DEV_GAME_END=25209 \
docker compose -p phvalheim-contributor-second -f compose.dev.yaml up -d --wait
```

Use those same variables and project name for subsequent commands. The game range
must start on an even port and contain complete pairs. All projects use the local
image tag; build and recreate deliberately when switching source checkouts.

## Disposable HTTP and database fixtures

After building, these commands create unique containers and volumes, seed synthetic
worlds, inspect real HTTP/database behavior and remove their fixtures on exit:

```bash
PHV_SEC_TEST_IMAGE=phvalheim-contributor:local \
  python3 dev_tools/test-public-security-integration.py
PHV_PORT_TEST_IMAGE=phvalheim-contributor:local \
  python3 dev_tools/test-game-port-integration.py
```

They do not use the Compose data volume or download/start a game. A successful run
exits zero and prints its check totals. The security test requires outbound HTTPS
to Steam to prove a forged assertion is rejected by Steam rather than by a network
failure. Do not set the source-overlay variables when verifying a built image.
The CI container job runs these same two suites against an image built from the PR.
These suites cover public security and port allocation, not all runtime behavior.

The legacy `run-oracles.sh --container` bucket also includes tests that expect
maintainer-specific fixtures. It is not a clean-checkout recipe and must not be
pointed at production. For cross-repo tests, clone the client and Companion as
siblings named `phvalheim-client` and `phvalheim-companion`, install .NET 9, and run
`bash dev_tools/run-oracles.sh --crossrepo`. Record all three commit IDs. Missing
Companion game assemblies leave the relevant reachability check unverified.

## Inspect and clean up

```bash
docker compose -f compose.dev.yaml logs --tail=100
docker compose -f compose.dev.yaml down
```

`down` preserves the development data. To intentionally discard this project's
test data and start fresh, use `docker compose -f compose.dev.yaml down --volumes`.
Never substitute an operator's data volume. Do not publish logs containing personal
Steam IDs, credentials or world passwords.
