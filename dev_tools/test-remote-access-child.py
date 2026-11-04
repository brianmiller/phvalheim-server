#!/usr/bin/env python3
"""Prove built-in providers survive request-thread exit, but not controller death.

Includes a negative control without the supervisor wrapper, which stays alive.
"""
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time

WRAPPER=Path(__file__).resolve().parents[1]/'container/remote-access/child.py'
def running(pid):
    try:
        return Path(f'/proc/{pid}/stat').read_text().split()[2] != 'Z'
    except FileNotFoundError:
        return False

def trial(wrapped):
    child_pid=None
    with tempfile.TemporaryDirectory() as d:
        marker=Path(d)/'pid'
        script=f'''import os,subprocess,sys,threading,time
args=[sys.executable,'-c',"import os,time;from pathlib import Path;Path({str(marker)!r}).write_text(str(os.getpid()));time.sleep(30)"]
if {wrapped!r}: args=[sys.executable,{str(WRAPPER)!r},str(os.getpid())]+args
def request(): subprocess.Popen(args,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
t=threading.Thread(target=request);t.start();t.join()
time.sleep(30)
'''
        parent=subprocess.Popen([sys.executable,'-c',script])
        try:
            deadline=time.monotonic()+5
            while not marker.exists() and time.monotonic()<deadline: time.sleep(.05)
            child_pid=int(marker.read_text())
            time.sleep(.3)
            assert running(child_pid),'request thread exit unexpectedly stopped provider'
            parent.kill();parent.wait()
            deadline=time.monotonic()+7
            while running(child_pid) and time.monotonic()<deadline: time.sleep(.05)
            assert running(child_pid)==(not wrapped),'controller death cleanup did not match expected result'
        finally:
            if parent.poll() is None: parent.kill();parent.wait()
            if child_pid and running(child_pid): os.kill(child_pid,signal.SIGKILL)
trial(True)
print('PASS: request thread may exit; controller death stops provider')
trial(False)
print('PASS: negative control without wrapper leaves provider alive (then cleaned up)')
