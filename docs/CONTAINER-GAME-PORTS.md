# Container managed game ports

Server 2.60 optionally accepts `PHVALHEIM_GAME_PORT_START` and
`PHVALHEIM_GAME_PORT_END`. A container platform can derive these from the same
settings that publish its UDP ports, so operators enter the range once.

Set both variables to decimal port numbers. The start must be even, the end must
include the second port of the last world pair, and neither may exceed 65535.
For example, `31000` through `31019` holds ten world pairs. Publish identical
host and container UDP port numbers. Invalid or partially configured ranges stop
the engine with a configuration error rather than allocating unforwarded ports.

When configured, the setup wizard and Server Settings display the container's
Base Port read-only. The API rejects attempts to change it independently. New
worlds reserve complete port pairs within the range; if it is full, creation
fails with an explanation and leaves no new world row. Concurrent requests
serialize allocations to avoid reserving the same pair.

The configured range never rewrites existing world ports. Server Settings,
Server Health and the New World page flag worlds outside it. Expand the range
and its published ports to include those worlds. A world still awaiting initial
allocation is assigned within the range; an existing out-of-range port is never
silently reassigned during creation/deployment.

Without these variables, existing Docker and Unraid behavior is unchanged:
the saved Base Port remains editable and the existing allocation path applies.
The saved value is retained while a managed range is active, so removing both
variables restores it. No schema migration or Unraid template change is needed.
The older `basePort` variable remains a one-time pre-2.31 migration setting;
do not use it as the new container integration.

This feature does not configure routers or inspect Docker's port mappings. A
platform integrating it must publish the same range that it passes to PhValheim.
TrueNAS catalog publication still needs a released versioned image containing
the feature. The current `:dev` candidate is for prerelease testing.
