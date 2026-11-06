#!/usr/bin/env python3
"""Exercise PID reuse recovery through real CLI flow, SQL and kernel locks.

Standalone uses SQLite for history and a lightweight Run recorder. Set
PHV_MODSYNC_TEST_MYSQL=1 inside a disposable migrated container to exercise the
real MariaDB history, Run and catalogue writes. Only the upstream feed is stubbed.
"""
import contextlib
import fcntl
import importlib.util
import io
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile

source = Path(os.environ.get('PHV_MODSYNC_TEST_MODULE',
    Path(__file__).resolve().parents[1] / 'container/engine/tools/modSync.py'))
spec = importlib.util.spec_from_file_location('modSync', source)
ms = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ms)
mysql = os.environ.get('PHV_MODSYNC_TEST_MYSQL') == '1'
if not mysql:
    db = sqlite3.connect(':memory:')
    db.executescript('''CREATE TABLE mod_sync_runs (
        id INTEGER PRIMARY KEY, source TEXT, status TEXT, phase TEXT, pid INTEGER,
        finished TEXT, error TEXT);''')
    def sql(query, fetch=False):
        result = db.execute(query.replace('NOW()', 'CURRENT_TIMESTAMP'))
        db.commit()
        return '\n'.join('\t'.join(str(c) for c in row) for row in result.fetchall()) if fetch else ''
    ms.sql = sql
    class Run:
        def __init__(self, *a): self.id = 100
        def phase(self, *a, **kw): pass
        def finish(self, *a, **kw): pass
        def flush(self): pass
        def record(self, *a, **kw): pass
    ms.Run = Run
    ms.last_good = lambda source: (None, None)

ms.get_settings = lambda: {'modSyncIntervalHours': '0'}
fetches = []
def fetch(source, cfg, key, sha, lm, force):
    fetches.append((source, force))
    # Real integration processes an empty upstream feed through all catalogue writes.
    # Standalone takes the unchanged branch after exercising the busy guard.
    return (b'[]', 200, None, False) if mysql else (None, 304, None, True)
ms.fetch = fetch

def history():
    return ms.rows('SELECT id,source,status,phase,COALESCE(finished,\'\'),COALESCE(error,\'\') FROM mod_sync_runs WHERE id IN (1,2,3) ORDER BY id;')

def seed(pid):
    ms.sql('DELETE FROM mod_sync_runs;')
    for rid, source, status in [(1,'thunderstore','running'),(2,'hexium','running'),(3,'thunderstore','ok')]:
        ms.sql(f"INSERT INTO mod_sync_runs (id,source,status,phase,pid) VALUES ({rid},'{source}','{status}','fetching',{pid});")

def run(*args):
    sys.argv = ['modSync.py', *args]
    with contextlib.redirect_stdout(io.StringIO()): return ms.main()

with tempfile.TemporaryDirectory(prefix='phv-sync-pid-') as tmp:
    ms.RUN_LOCK = str(Path(tmp) / 'run.lock')
    ms.WAIT_LOCK = str(Path(tmp) / 'wait.lock')
    sleeper = subprocess.Popen(['sleep','120'])
    try:
        for trigger, force in [('cron',False),('manual',True)]:
            seed(sleeper.pid); before = history()[2]; fetches.clear()
            assert run('--trigger',trigger, *(['--force'] if force else [])) == 0
            assert fetches == [('thunderstore',force),('hexium',force)], fetches
            after = history()
            assert all(r[2:4] == ['stale','done'] and r[4] and r[5] for r in after[:2]), after
            assert after[2] == before, (before,after[2])
            assert sleeper.poll() is None, 'reused PID owner must never be signalled'
            if mysql:
                assert ms.rows("SELECT source,status FROM mod_sync_runs WHERE id>3 ORDER BY id;") == [['thunderstore','ok'],['hexium','ok']]
            print(f'PASS: {trigger} force={force} refreshes both sources, closes interrupted history, preserves completed history and unrelated process')

        seed(sleeper.pid); before=history();fetches.clear()
        with ms.open_lock(ms.RUN_LOCK) as holder:
            fcntl.flock(holder,fcntl.LOCK_EX|fcntl.LOCK_NB)
            assert run('--trigger','cron') == 0
            assert run('--trigger','boot') == 0
            # A manual run with an existing queued waiter must not touch history.
            with ms.open_lock(ms.WAIT_LOCK) as waiter:
                fcntl.flock(waiter,fcntl.LOCK_EX|fcntl.LOCK_NB)
                assert run('--trigger','manual','--force') == 0
            assert history()==before and not fetches
        print('PASS: real lock contention prevents recovery and fetching; manual queue remains bounded')

        owner = subprocess.Popen([sys.executable, '-c',
            "import fcntl,sys,time; f=open(sys.argv[1]); fcntl.flock(f,fcntl.LOCK_EX); print('locked',flush=True); time.sleep(120)",
            ms.RUN_LOCK], stdout=subprocess.PIPE, text=True)
        try:
            assert owner.stdout.readline().strip() == 'locked'
            seed(owner.pid); before=history(); fetches.clear()
            assert run('--trigger','cron') == 0
            assert history()==before and not fetches
            owner.kill(); owner.wait()
            assert run('--trigger','manual','--force') == 0
            assert fetches==[('thunderstore',True),('hexium',True)]
            assert all(r[2]=='stale' for r in history()[:2])
        finally:
            if owner.poll() is None: owner.kill(); owner.wait()
            owner.stdout.close()
        print('PASS: active writer is protected; killed writer releases kernel lock and next refresh recovers')

        seed(sleeper.pid); before=history()
        assert run('--dry-run','--force') == 0
        assert history()==before
        print('PASS: dry run leaves interrupted and completed history untouched')

        # Recovery must be inside the lock-release finally block.
        real_sql=ms.sql
        def broken_sql(query, **kw):
            if 'UPDATE mod_sync_runs' in query: raise RuntimeError('injected database failure')
            return real_sql(query,**kw)
        ms.sql=broken_sql
        try:
            try: run('--trigger','manual','--force')
            except RuntimeError as e: assert str(e)=='injected database failure'
            else: raise AssertionError('recovery failure was hidden')
        finally: ms.sql=real_sql
        assert history()==before
        with ms.open_lock(ms.RUN_LOCK) as holder: fcntl.flock(holder,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print('PASS: database failure is visible, history preserved and lock released')
    finally:
        sleeper.terminate();sleeper.wait()
print('PASS: stale PID recovery (' + ('MariaDB' if mysql else 'standalone') + ')')
