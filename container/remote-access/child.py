#!/usr/bin/env python3
"""Own a provider process until the controller process exits, including SIGKILL.

A pidfd watches the entire controller, not the short-lived HTTP thread that
spawned us. PR_SET_PDEATHSIG would incorrectly stop clients as that thread exits.
No credentials are passed to this wrapper; provider arguments use private files.
"""
import os
import select
import signal
import subprocess
import sys


def main():
    parent = os.pidfd_open(int(sys.argv[1]))
    poll = select.poll()
    poll.register(parent, select.POLLIN)
    if poll.poll(0):
        return 1
    child = subprocess.Popen(sys.argv[2:])
    stopping = False
    def forward(sig, _frame):
        nonlocal stopping
        if sig in (signal.SIGTERM, signal.SIGINT):
            stopping = True
        if child.poll() is None:
            child.send_signal(sig)
    for sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(sig, forward)
    try:
        while child.poll() is None and not stopping:
            if poll.poll(250):
                stopping = True
        if child.poll() is None:
            child.terminate()
            try:
                child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()
        return child.returncode
    finally:
        os.close(parent)


if __name__ == '__main__':
    sys.exit(main())
