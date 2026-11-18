# Local contributor environment

Use an x86-64 Linux host or VM with Docker Compose v2+, Git, Bash, PHP CLI 8.1+,
Python 3, Node.js, jq, zip and unzip. Start with the clone and standalone tests in
[CONTRIBUTING.md](../CONTRIBUTING.md). No production data or credentials are needed
for the setup and disposable integration checks below.

## Build and start your checkout

From the repository root:

```bash
chmod 755 dev_tools/fixtures/analytics-disabled.sh
docker compose -f compose.dev.yaml build
docker compose -f compose.dev.yaml up -d --wait --wait-timeout 360
docker compose -f compose.dev.yaml ps
```

The image is `phvalheim-contributor:local`, built from this checkout. After changing
runtime files, repeat **both** build and up commands; source is not bind-mounted.
Usage analytics is blocked by a read-only test script mounted before startup.
The contributor Compose stack never enrolls or uploads development telemetry.
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
All disposable server launchers mount `dev_tools/fixtures/analytics-disabled.sh`
read-only over the scheduled analytics sender **before** the engine starts. This
also protects tests against older images, restart/recreation, and later fixture
changes that enable analytics in the database. `dev_tools/devUp.sh` applies the
same block to the local development server. The retained Remote Access browser
fixture is inspected before use; recreate an older fixture if it lacks the mount.
Python harnesses use `fixture_analytics.docker_args()`; shell harnesses source
`fixture-analytics.sh` and pass `"${PHV_TEST_ANALYTICS_ARGS[@]}"` to Docker.
Use `--mount` (which refuses a missing source), not a post-start SQL setting.

Analytics-specific tests must stub the receiver or run with `--network none`;
they must never use the production receiver. Do not run fixture tests against
operator installations. Normal released servers keep their analytics preference.

These suites cover public security and port allocation, not all runtime behavior.

The legacy `run-oracles.sh --container` bucket also includes tests that expect
maintainer-specific fixtures. It is not a clean-checkout recipe and must not be
pointed at production. For cross-repo tests, clone the client and Companion as
siblings named `phvalheim-client` and `phvalheim-companion`, install .NET 9, and run
`bash dev_tools/run-oracles.sh --crossrepo`. Record all three commit IDs. Missing
Companion game assemblies leave the relevant reachability check unverified.

## Portainer packaging validation

From the repository root, use Docker with permission to run a privileged
Docker-in-Docker (dind) fixture, Git and Python 3. The integration harness uses
Python's standard library and repository helpers; no Python packages are needed.
Pull the prerequisite images matching the versions in the harness:

```bash
docker pull portainer/portainer-ce:2.45.3
docker pull docker:29.3.0-dind
python3 dev_tools/test-portainer-integration.py --keep
```

Portainer manages a new isolated dind daemon on a unique bridge; it never receives
the host Docker socket. The harness mounts the analytics blocker read-only before
application startup. A temporary v3 feed changes only the repository transport URL;
its temporary Git repository adds only that analytics mount to the production
Compose file. The checked-in feed and Compose file remain unchanged.

The harness pulls and checks the exact application image
`theoriginalbrian/phvalheim-server:2.62@sha256:925c68966570452658a22eeda3d1aa74a7eddabd27a6ee7d5ac5e73d15842009`
inside dind. Checks cover template retrieval and stack creation, synthetic setup
and database rows, bounded port mappings/allocation (including odd-start
rejection), and settings, data and backup markers surviving restart and forced
recreation. The engine is stopped before synthetic world operations. These checks
do not validate live Steam sign-in or gameplay and do not deploy to production.

The command prints its `/tmp/phv-portainer-<run>/evidence.json` path. Wait for the
full integration run to exit zero and report `"status": "passed"` before browser
review; replace the placeholder below with that printed path. `--keep` is required
to leave the fixture and helper processes running, even on failure.

Browser review requires Playwright for Python and its matching Chromium binaries
in the environment used by `python3`. If missing, use the official
[library installation](https://playwright.dev/python/docs/library) and
[browser installation](https://playwright.dev/python/docs/browsers) commands in
your activated Python environment:

```bash
pip install playwright
playwright install chromium
```

```bash
python3 dev_tools/portainer-browser.py '/tmp/phv-portainer-<run>/evidence.json' --public
```

This checks the visible template fields/guidance and existing stack, writing
`browser-evidence.json` and screenshots beside the integration evidence.
`--public` also attempts the public portal; read its separate `public_portal`
status because an unavailable portal does not fail the overall browser check.
Report the actual statuses from both evidence files.

Without `--keep`, conservative default cleanup stops only this run's containers
and helper processes, preserving containers, volumes, network, evidence and data.
After a kept review, read `evidence.json` for the owned `host_containers`,
`nested_containers` and `helper_pids`; verify ownership before stopping only those
fixtures and helpers. Retain evidence/data and never touch operator resources.
Read the evidence's `cleanup` status when reporting cleanup; manually stopping a
kept fixture does not update its recorded `kept_running` value.

## Inspect and clean up

```bash
docker compose -f compose.dev.yaml logs --tail=100
docker compose -f compose.dev.yaml down
```

`down` preserves the development data. To intentionally discard this project's
test data and start fresh, use `docker compose -f compose.dev.yaml down --volumes`.
Never substitute an operator's data volume. Do not publish logs containing personal
Steam IDs, credentials or world passwords.
