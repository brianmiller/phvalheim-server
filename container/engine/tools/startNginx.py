#!/usr/bin/python3
"""Discard only a dead origin socket before nginx starts/restarts."""
import errno
import os
import socket
import stat

path = '/run/phvalheim-remote/public.sock'
try:
    if not stat.S_ISSOCK(os.lstat(path).st_mode):
        raise SystemExit('Remote Access origin path is not a socket.')
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as probe:
        probe.settimeout(1)
        try:
            probe.connect(path)
        except OSError as error:
            if error.errno not in (errno.ECONNREFUSED, errno.ENOENT):
                raise
            os.unlink(path)
        else:
            raise SystemExit('Remote Access origin is already active; use one socket volume per instance.')
except FileNotFoundError:
    pass
os.execv('/usr/sbin/nginx', ['/usr/sbin/nginx', '-g', 'daemon off;'])
