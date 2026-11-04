# Analytics enrollment transport

This transport is included in the **2.62 development branch**. The existing 2.61
release does not contain it. The receiver accepts the protocol from version 2.61
or newer, but a version label alone does not add enrollment support.

Analytics-enabled installations enroll automatically with the compatible receiver.
The receiver requires version 2.61+ and this enrollment protocol. A version number
alone does not establish that an installation uses a particular executable.

The sender creates a fresh UUID and 256-bit credential in
`/opt/stateful/analytics/credential.json`, owned by the phvalheim account, mode 0600.
The old publicly reported database UUID is not reused as a secret. The private
credential persists across upgrades; include this directory in protected backups.
A copied credential represents the same installation, so do not clone it to a new
server. A new installation must generate its own private state.

Enrollment is idempotent, protected by a local file lock and atomic credential
writes. The receiver binds the UUID to a credential digest and refuses replacement
by another sender. Subsequent reports use a Bearer credential over HTTPS, with no
HTTP fallback, redirects, credential arguments, or response-body logging.

Disabling analytics before enrollment makes no remote enrollment. Disabling an
enrolled installation sends the final disabled report with its existing identity.
A 401 does not silently generate a new identity. An operator can rotate the key in
the analytics admin UI and install the replacement UUID/token in the private JSON
file (`enrolled: true`). Stop concurrent analytics jobs during manual recovery and
preserve service-account ownership and mode 0600.

The receiver limits payloads to 2 MiB, 128 worlds, 4,096 mods across worlds, and
bounded field lengths. Rejected reports are visible as a failed Analytics check in
Server Health. The test suite covers enrollment retry, persistent identity, private
file permissions, redirect refusal, opt-out, old versions, and authentication
failure. No game runtime or client changes are needed for this transport.
