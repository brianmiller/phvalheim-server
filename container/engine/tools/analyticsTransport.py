#!/usr/bin/env python3
"""Private, persistent enrollment and HTTPS transport for analytics protocol 2.61."""
import fcntl
import json
import os
import pwd as unix_accounts
import re
import secrets
import stat
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


def request(path, payload, token):
    data = json.dumps(payload, allow_nan=False, separators=(',', ':')).encode()
    if len(data) > MAX_BYTES:
        raise ValueError('Telemetry exceeds 2 MiB limit')
    req = urllib.request.Request(ENDPOINT + path, data=data, method='POST', headers={
        'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
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
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        os.close(fd)
        raise ValueError('Analytics credential storage must be private and owned by the service account')
    return fd


def atomic_json(path, data):
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.analytics-')
    try:
        with os.fdopen(fd, 'w') as f:
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
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = state.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise ValueError('Analytics state directory is not private')
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
    if os.geteuid() == 0:
        account = unix_accounts.getpwnam('phvalheim')  # Unix service account
        os.setgroups([]); os.setgid(account.pw_gid); os.setuid(account.pw_uid)
    code, ok = '000', False
    try:
        submit(payload)
        code, ok = '200', True
    except urllib.error.HTTPError as exc:
        code = str(exc.code)
        print('Analytics request rejected (HTTP ' + code + ')', file=sys.stderr)
    except (OSError, ValueError, KeyError, TypeError):
        # Do not log remote bodies, URLs, payloads, or credentials.
        print('Analytics request failed; check connectivity or private enrollment state', file=sys.stderr)
    print(code)  # Status only; credentials and remote bodies never reach stdout.
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
