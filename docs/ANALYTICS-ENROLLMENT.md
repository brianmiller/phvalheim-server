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

Startup prepares this dedicated directory before dropping to the service account.
On TrueNAS ZFS with inherited NFSv4 ACLs, creation modes alone may produce 0770
directories and files, and `chmod` can retain named-user grants. The transport
replaces those grants on its own directory with a private, non-inheriting ACL,
then verifies 0700/0600 permissions before storing any credential. Other data and
dataset permissions are unchanged. Cron reuses the same identity as startup.
Existing credential files with unsafe permissions are rejected rather than silently
replaced. Filesystems that cannot retain private permissions still fail closed.
The XDR layout follows [TrueNAS's ACL tooling](https://github.com/truenas/nfs4xdr-acl-tools/blob/master/libnfs4acl/acl_nfs4_xattr_pack.c).

Enrollment is idempotent, protected by a local file lock and atomic credential
writes. The receiver binds the UUID to a credential digest and refuses replacement
by another sender. Subsequent reports use a Bearer credential over HTTPS, with no
HTTP fallback, redirects, credential arguments, or response-body logging.
Requests identify the sender as `PhValheim-Server/<version>`; the generic Python
user agent was rejected with HTTP 403 by the public service's edge filter.

Disabling analytics before enrollment makes no remote enrollment. Disabling an
enrolled installation sends the final disabled report with its existing identity.
A 401 does not silently generate a new identity. An operator can rotate the key in
the analytics admin UI and install the replacement UUID/token in the private JSON
file (`enrolled: true`). Stop concurrent analytics jobs during manual recovery and
preserve service-account ownership and mode 0600.

The receiver limits payloads to 2 MiB, 128 worlds, 4,096 mods across worlds, and
bounded field lengths. Reporting outcomes remain in Logs and the local analytics status record. Analytics
does not contribute to Server Health, dashboard warning banners or the Logs warning badge. The test suite covers enrollment retry, persistent identity, private
file permissions, redirect refusal, opt-out, old versions, and authentication
failure. No game runtime or client changes are needed for this transport.

`dev_tools/test-analytics-filesystem.py` exercises startup and subsequent sends
against a mounted filesystem with stubbed network requests, checks identity
persistence, and verifies that unrelated accounts cannot read the credential.
