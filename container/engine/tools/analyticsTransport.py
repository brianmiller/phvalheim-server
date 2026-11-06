#!/usr/bin/env python3
"""Private, persistent enrollment and HTTPS transport for analytics protocol 2.61."""
import fcntl
import errno
import json
import os
import pwd as unix_accounts
import re
import secrets
import stat
import struct
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from uuid import UUID, uuid4

ENDPOINT = 'https://analytics.phvalheim.com'
STATE = Path('/opt/stateful/analytics')
MAX_BYTES = 2 * 1024 * 1024


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


class PrivateStorageError(ValueError):
    pass


def private_acl(fd, mode, tighten=False):
    # TrueNAS ZFS exposes NFSv4 ACLs through this XDR attribute. chmod alone
    # preserves named-user grants with aclmode=passthrough. The wire layout is
    # documented in truenas/nfs4xdr-acl-tools include/nfs4.h and
    # libnfs4acl/acl_nfs4_xattr_pack.c: flags/count, then 5 uint32s per ACE.
    attr = 'system.nfs4_acl_xdr'
    try:
        raw = os.getxattr(fd, attr)
    except OSError as exc:
        if exc.errno in (errno.ENODATA, errno.ENOTSUP):
            return
        raise
    metadata = 0x120088  # read attributes/ACL and synchronize; no file contents
    owner_rights = 0x1e01ff if mode == 0o700 else 0x1e019f
    entries = (0, 0, 1, owner_rights, 1,       # ALLOW OWNER@
               0, 0x40, 1, metadata, 2,       # ALLOW GROUP@ metadata only
               0, 0, 1, metadata, 3)          # ALLOW EVERYONE@ metadata only
    def matches(data):
        return len(data) == 68 and struct.unpack('>17I', data)[1:] == (3,) + entries
    if not matches(raw):
        if not tighten:
            raise PrivateStorageError('Analytics storage has additional ACL grants')
        # Only our dedicated directory/new files may be tightened. Root startup
        # prepares the directory before dropping privileges; cron only validates.
        os.setxattr(fd, attr, struct.pack('>17I', 2, 3, *entries))  # ACL_PROTECTED
        if not matches(os.getxattr(fd, attr)):
            raise PrivateStorageError('Filesystem did not retain the private ACL')


def private_descriptor(fd, kind, mode, tighten=False, owner=None):
    info = os.fstat(fd)
    if not kind(info.st_mode) or info.st_uid != (os.geteuid() if owner is None else owner):
        raise PrivateStorageError('Analytics storage must be owned by the service account')
    if tighten:
        # ZFS/NFSv4 inherited ACLs can override the requested creation mode.
        # Set it explicitly on the opened object, then verify before storing secrets.
        private_acl(fd, mode, tighten=True)
        os.fchmod(fd, mode)
        info = os.fstat(fd)
    private_acl(fd, mode)
    if stat.S_IMODE(info.st_mode) != mode:
        raise PrivateStorageError('Analytics storage must have private permissions')


def private_directory(path, owner=None):
    created = False
    try:
        path.mkdir(mode=0o700, parents=True)
        created = True
    except FileExistsError:
        pass
    fd = os.open(path, os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if created and owner is not None:
            os.fchown(fd, owner, -1)
        private_descriptor(fd, stat.S_ISDIR, 0o700, tighten=True, owner=owner)
    finally:
        os.close(fd)


def request(path, payload, token):
    data = json.dumps(payload, allow_nan=False, separators=(',', ':')).encode()
    if len(data) > MAX_BYTES:
        raise ValueError('Telemetry exceeds 2 MiB limit')
    req = urllib.request.Request(ENDPOINT + path, data=data, method='POST', headers={
        'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token,
        'User-Agent': 'PhValheim-Server/' + payload['version']})
    with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as response:
        raw = response.read(16385)
        if len(raw) > 16384:
            raise ValueError('Unexpected response size')
        result = json.loads(raw)
        if not isinstance(result, dict) or result.get('success') is not True:
            raise ValueError('Telemetry not accepted')
        return result


def private_file(path, flags, mode=0o600):
    fd = os.open(path, flags | os.O_NOFOLLOW, mode)
    try:
        # Only the non-secret lock uses O_CREAT. Existing credentials remain
        # strictly validated, never silently repaired or replaced.
        private_descriptor(fd, stat.S_ISREG, mode, tighten=bool(flags & os.O_CREAT))
    except BaseException:
        os.close(fd)
        raise
    return fd


def atomic_json(path, data):
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.analytics-')
    try:
        with os.fdopen(fd, 'w') as f:
            private_descriptor(f.fileno(), stat.S_ISREG, 0o600, tighten=True)
            json.dump(data, f); f.flush(); os.fsync(f.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def submit(payload, state=STATE):
    match = re.fullmatch(r'(\d{1,4})\.(\d{1,4})(?:\.\d{1,4})?', payload.get('version', ''))
    if not match or tuple(map(int, match.groups()[:2])) < (2, 61):
        raise ValueError('Analytics requires PhValheim Server 2.61+')
    private_directory(state)
    lock = private_file(state / 'enrollment.lock', os.O_CREAT | os.O_RDWR)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        credential_path = state / 'credential.json'
        try:
            fd = private_file(credential_path, os.O_RDONLY)
        except FileNotFoundError:
            # Opt-out must never enroll a previously unreported installation.
            if payload.get('analytics_disabled') is True:
                return {'success': True, 'skipped': True}
            credential = {'uuid': str(uuid4()), 'token': secrets.token_urlsafe(32), 'enrolled': False}
            # Persist BEFORE contacting the service; a lost response is retried with
            # the same identity/key rather than creating duplicate installations.
            atomic_json(credential_path, credential)
        else:
            with os.fdopen(fd) as f:
                credential = json.load(f)
        UUID(credential['uuid'])
        if not re.fullmatch(r'[A-Za-z0-9_-]{43}', credential['token']):
            raise ValueError('Invalid stored credential; operator recovery required')
        if not credential.get('enrolled'):
            result = request('/api/enroll', {'uuid': credential['uuid'], 'version': payload['version']}, credential['token'])
            if result.get('uuid') != credential['uuid']:
                raise ValueError('Enrollment identity mismatch')
            credential['enrolled'] = True
            atomic_json(credential_path, credential)
        payload = dict(payload, uuid=credential['uuid'])
        # A 401 never triggers key replacement or enrollment under another identity.
        return request('/api/ingest', payload, credential['token'])
    finally:
        os.close(lock)


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Usage: analyticsTransport.py PAYLOAD_FILE')
    # The startup caller may be root and own the temporary payload. Read that
    # file first, then use the same account as cron for durable credentials.
    with open(sys.argv[1], 'rb') as f:
        raw = f.read(MAX_BYTES + 1)
    if len(raw) > MAX_BYTES:
        raise SystemExit('Analytics payload exceeds 2 MiB limit')
    payload = json.loads(raw)
    code, ok = '000', False
    try:
        if os.geteuid() == 0:
            account = unix_accounts.getpwnam('phvalheim')  # Unix service account
            private_directory(STATE, owner=account.pw_uid)
            os.setgroups([]); os.setgid(account.pw_gid); os.setuid(account.pw_uid)
        submit(payload)
        code, ok = '200', True
    except urllib.error.HTTPError as exc:
        code = str(exc.code)
        print('Analytics request rejected (HTTP ' + code + ')', file=sys.stderr)
    except PrivateStorageError:
        print('Analytics private storage unavailable: /opt/stateful/analytics must support '
              'service-owned 0700 directories and 0600 files', file=sys.stderr)
    except (OSError, ValueError, KeyError, TypeError):
        # Do not log remote bodies, URLs, payloads, or credentials.
        print('Analytics request failed; check connectivity or private enrollment state', file=sys.stderr)
    print(code)  # Status only; credentials and remote bodies never reach stdout.
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
