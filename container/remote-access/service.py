#!/usr/bin/env python3
"""Built-in Remote Access controller. Unix control plane; fixed HTTP gateway; no shell.

Clients run unprivileged in the main container. Private credentials persist under
the existing data volume, outside served paths. See remote-access.md for the
operator-owned provider account trust boundary.
"""
import copy
import hashlib
import http.client
import http.server
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import socketserver
import ssl
import subprocess
import threading
import time
from urllib.parse import urlsplit

STATE = Path('/opt/stateful/remote-access')
LINK = Path('/run/phvalheim-remote')
RUN = Path('/tmp/remote')
ORIGIN = 'http://127.0.0.1:18080'
TS = ['/usr/local/bin/tailscale', '--socket=/tmp/remote/provider/tailscaled.sock']
STEPS = ('local', 'provider', 'dns', 'tls', 'route', 'transfer')


class UserError(Exception):
    pass


class PendingPublicError(UserError):
    pass


def website(value):
    if not isinstance(value, str) or len(value) > 253:
        raise UserError('Enter the full HTTPS website URL.')
    p = urlsplit(value.strip())
    try:
        port = p.port
    except ValueError:
        raise UserError('Use HTTPS on port 443.')
    host = p.hostname or ''
    if (p.scheme != 'https' or p.username or p.password or port not in (None, 443)
            or p.path not in ('', '/') or p.query or p.fragment
            or not re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?', host)
            or '.' not in host or '..' in host):
        raise UserError('Use an HTTPS hostname, without a path, password, or query.')
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise UserError('Use a hostname rather than an IP address.')
    for label in host.split('.'):
        if len(label) > 63 or label.startswith('-') or label.endswith('-'):
            raise UserError('This hostname is not valid.')
    return 'https://' + host.lower()


def hostname(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?', value.strip()):
        raise UserError('Use 1–63 letters, numbers or hyphens; start and end with a letter or number. Enter only the name, not a URL.')
    return value.strip().lower()


def public_ip(value):
    ip = ipaddress.ip_address(value)
    return ip.version == 4 and ip.is_global and not ip.is_multicast


def provider_link(value):
    try:
        p = urlsplit(value)
        if p.scheme == 'https' and p.hostname in ('login.tailscale.com', 'login.tailscale.io') and not p.username and not p.password and p.port in (None, 443):
            return value
    except (ValueError, TypeError):
        pass
    return ''


def atomic(path, data, mode=0o600):
    tmp = path.with_name(path.name + '.' + secrets.token_hex(6))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    os.fchmod(fd, mode)  # Explicit sharing for non-secret challenges, despite umask 077.
    try:
        with os.fdopen(fd, 'w') as f:
            f.write(json.dumps(data) if not isinstance(data, str) else data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def read_json(path, default):
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError):
        return default


class UnixHTTP(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(str(LINK / 'public.sock'))


class PinnedHTTPS(http.client.HTTPSConnection):
    def __init__(self, host, address):
        super().__init__(host, 443, timeout=5, context=ssl.create_default_context())
        self.address = address

    def connect(self):
        sock = socket.create_connection((self.address, 443), timeout=5)
        try:
            self.sock = self._context.wrap_socket(sock, server_hostname=self.host)
        except Exception:
            sock.close()
            raise


def child_args(args):
    return ['/usr/bin/python3', '/opt/stateless/remote-access/child.py', str(os.getpid()), *args]


def run(args, timeout=5):
    # Output is interpreted only in memory; never include raw provider errors in UI/logs.
    return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          text=True, timeout=timeout, user=1001, group=1001, extra_groups=[],
                          env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': str(STATE / 'tailscale')})


class Controller:
    def __init__(self):
        self.lock = threading.RLock()
        self.config = read_json(STATE / 'config.json', {'provider': '', 'url': '', 'enabled': False, 'revision': 0})
        self.instance = read_json(STATE / 'identity.json', {}).get('id') or secrets.token_hex(16)
        atomic(STATE / 'identity.json', {'id': self.instance})
        self.proc = None
        self.login = None
        self.last_start = 0
        self.restarts = 0
        self.next_start = 0
        self.test = None
        self.cancel = threading.Event()
        self.last_test = 0
        self.error = ''
        self.events = []
        self.observed = {'state': 'Off', 'time': time.time(), 'details': {}}
        self.serving = False
        self.next_serve = 0
        self.enrolling = False
        self.gateway = None
        self.auth_url = ''
        self.ts_state = ''
        self.pending_test = bool(self.config.get('enabled'))
        self.verify_until = 0
        self.preparing = False
        self.next_hostname = 0
        self.shutting_down = False
        # Private Serve from earlier development builds is not permission to
        # publish on upgrade. Keep the node identity; require the public action.
        if self.config['provider'] == 'tailscale' and self.config['enabled'] and not self.config.get('publicAccess'):
            self.config['enabled'] = False
            self.persist()
            self.error = 'Tailscale now provides a public website. Sign in with the saved device, then select Publish website.'

    def event(self, message):
        self.events = (self.events + [{'time': time.time(), 'message': message}])[-30:]

    def persist(self):
        try:
            atomic(STATE / 'config.json', self.config)
        except OSError:
            self.config = read_json(STATE / 'config.json', {'provider':'', 'url':'', 'enabled':False, 'revision':0})
            raise UserError('Connection settings could not be saved. Check free space and private storage permissions.')

    def snapshot(self):
        with self.lock:
            if self.test and self.test.get('nonce'):
                device = read_json(LINK / 'device-test.json', {})
                if (device.get('nonce') == self.test['nonce'] and time.time() - device.get('time', 0) < 300):
                    self.test['device'] = 'A browser reached this website; its network location is not independently verified'
                    if self.test['state'] == 'Needs device test':
                        self.test['state'] = 'Browser reached website'
            c = copy.deepcopy(self.config)
            c['hostname'] = self.config.get('hostname') or (urlsplit(self.config.get('url', '')).hostname or '').split('.')[0] or 'phvalheim-' + self.instance[:8]
            c['enrolled'] = bool(self.config.get('enrolled') or (self.config.get('provider') == 'tailscale' and self.config.get('url')))
            c['tokenSaved'] = (STATE / 'cloudflare.token').exists()
            return {'success': True, 'available': True, 'config': c,
                    'observed': copy.deepcopy(self.observed), 'error': self.error,
                    'test': copy.deepcopy(self.test), 'testAvailableAt': self.last_test + 10,
                    'testRetryUntil': self.verify_until, 'events': copy.deepcopy(self.events),
                    'origin': ORIGIN, 'authUrl': self.auth_url, 'enrolling': self.enrolling,
                    'packaging': 'built-in', 'networkPolicy': 'operator-managed', 'now': time.time()}

    def stop_process(self, proc):
        if proc and proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=3)

    def stop(self):
        self.cancel.set()
        self.stop_process(self.login)
        self.login = None
        self.stop_process(self.proc)
        self.proc = None
        self.serving = False
        self.next_serve = 0
        self.enrolling = False
        self.ts_state = ''
        self.pending_test = False
        self.verify_until = 0
        self.preparing = False
        self.test = None
        self.auth_url = ''
        self.stop_process(self.gateway)
        self.gateway = None
        (LINK / 'challenge.json').unlink(missing_ok=True)
        self.observed = {'state': 'Off', 'time': time.time(), 'details': {}}

    def gateway_start(self, host):
        # Nginx supplies streaming/range semantics for native client archives.
        # The hostname has already been normalized; no user text is executable.
        conf = '''pid /tmp/remote/provider/nginx.pid;
error_log /dev/null crit;
events { worker_connections 128; }
http { access_log off; client_body_temp_path /tmp/remote/provider/body;
proxy_temp_path /tmp/remote/provider/proxy;
fastcgi_temp_path /tmp/remote/provider/fastcgi;
uwsgi_temp_path /tmp/remote/provider/uwsgi;
scgi_temp_path /tmp/remote/provider/scgi;
server { listen 127.0.0.1:18080 default_server; server_name _; return 421; }
server { listen 127.0.0.1:18080; server_name HOSTNAME;
client_max_body_size 1m;
location / { proxy_pass http://unix:/run/phvalheim-remote/public.sock:;
proxy_http_version 1.1; proxy_set_header Host HOSTNAME;
proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Forwarded-For "";
proxy_set_header X-Real-IP ""; proxy_set_header Connection "";
proxy_buffering off; proxy_read_timeout 120s; }
} }
'''.replace('HOSTNAME', host)
        atomic(RUN / 'nginx.conf', conf)
        os.chown(RUN / 'nginx.conf', 1001, 1001)
        if self.gateway and self.gateway.poll() is None:
            self.gateway.send_signal(signal.SIGHUP)
        else:
            self.gateway = subprocess.Popen(child_args(['/usr/sbin/nginx', '-c', str(RUN / 'nginx.conf'), '-g', 'daemon off;']),
                                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                            user=1001, group=1001, extra_groups=[])

    def spawn(self, args):
        return subprocess.Popen(child_args(args), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                user=1001, group=1001, extra_groups=[],
                                env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': str(STATE / 'tailscale')})

    def start(self):
        if self.proc and self.proc.poll() is None:
            return
        if self.config['provider'] == 'cloudflare':
            self.gateway_start(urlsplit(self.config['url']).hostname)
            args = ['/usr/local/bin/cloudflared', '--no-autoupdate', 'tunnel', '--metrics', '127.0.0.1:20241',
                    '--edge-ip-version', '4', 'run', '--token-file', str(STATE / 'cloudflare.token')]
        else:
            self.stop_process(self.gateway)
            self.gateway = None
            self.serving = False
            self.next_serve = 0
            args = ['/usr/local/bin/tailscaled', '--tun=userspace-networking',
                    '--state=' + str(STATE / 'tailscale/tailscaled.state'), '--statedir=' + str(STATE / 'tailscale'),
                    '--socket=/tmp/remote/provider/tailscaled.sock']
        self.proc = self.spawn(args)
        self.last_start = time.time()
        self.restarts += 1
        self.next_start = self.last_start + min(60, 2 ** min(self.restarts, 5))
        self.observed = {'state': 'Connecting', 'time': time.time(), 'details': {}}

    def action(self, request):
        with self.lock:
            action = request.get('action')
            if action == 'status':
                return self.snapshot()
            if action == 'save':
                if self.config['enabled'] or self.enrolling:
                    raise UserError('Disconnect before editing the connection.')
                provider = request.get('provider')
                if provider not in ('cloudflare', 'tailscale'):
                    raise UserError('Choose Cloudflare or Tailscale.')
                name = hostname(request.get('hostname', self.snapshot()['config']['hostname'])) if provider == 'tailscale' else ''
                url = website(request.get('url', '')) if provider == 'cloudflare' else (self.config.get('url', '') if self.config['provider'] == provider and name == self.snapshot()['config']['hostname'] else '')
                token = request.get('token', '')
                if not isinstance(token, str) or len(token) > 4096:
                    raise UserError('Paste only the tunnel token.')
                if provider == 'cloudflare':
                    if token:
                        if not re.fullmatch(r'[A-Za-z0-9_+/=-]{40,4096}', token):
                            raise UserError('Paste only the tunnel token, not the installation command.')
                        atomic(STATE / 'cloudflare.token', token)
                        os.chown(STATE / 'cloudflare.token', 1001, 1001)
                    if not (STATE / 'cloudflare.token').exists():
                        raise UserError('Paste the Cloudflare tunnel token.')
                self.cancel.set()
                self.test = None
                self.config.update(provider=provider, url=url, hostname=name, publicAccess=False, revision=self.config['revision'] + 1)
                self.persist()
                self.error = ''
                self.event('Connection settings saved; access remains off.')
            elif action == 'hostname':
                if self.config['provider'] != 'tailscale' or self.config['enabled'] or self.enrolling:
                    raise UserError('Disconnect Tailscale before changing its hostname.')
                name = hostname(request.get('hostname'))
                if name != self.snapshot()['config']['hostname']:
                    self.cancel.set()
                    self.test = None
                    (LINK / 'challenge.json').unlink(missing_ok=True)
                    self.config.update(enrolled=self.snapshot()['config']['enrolled'], hostname=name, url='', revision=self.config['revision'] + 1)
                    self.persist()
                elif self.config.get('hostname') != name:
                    self.config['hostname'] = name
                    self.persist()
                self.error = ''
                self.event('Hostname saved. Reconnect to apply it; the public URL may change.')
            elif action == 'connect':
                if not self.config['provider']:
                    raise UserError('Save the connection settings first.')
                if self.config['provider'] == 'tailscale' and request.get('publicAccess') is not True:
                    raise UserError('Choose Publish website to make this Tailscale URL public.')
                if self.config['enabled']:
                    return self.snapshot()
                self.config['enabled'] = True
                self.config['publicAccess'] = True
                self.config['revision'] += 1
                self.persist()
                self.error = ''
                self.start()
                self.event('Connection requested; saved device identity will be reused.')
                self.cancel.set()
                self.test = None
                self.pending_test = self.config['provider'] == 'tailscale'
                self.verify_until = 0
                if self.config['provider'] == 'cloudflare':
                    self.begin_test()
                if self.config['provider'] == 'tailscale' and not (self.login and self.login.poll() is None):
                    self.enrolling = True
                    self.preparing = True
                    threading.Thread(target=self.enroll, args=(False, self.config['revision']), daemon=True).start()
            elif action == 'enroll':
                if self.config['provider'] != 'tailscale' or self.config['enabled']:
                    raise UserError('Save Tailscale settings with access off before signing in.')
                if self.login and self.login.poll() is None:
                    raise UserError('Sign-in is already waiting in Tailscale.')
                key = request.get('authKey', '')
                if not isinstance(key, str) or (key and not re.fullmatch(r'tskey-auth-[A-Za-z0-9-]{10,300}', key)):
                    raise UserError('Use a Tailscale device enrollment auth key.')
                if key:
                    atomic(STATE / 'enrollment.key', key)
                    os.chown(STATE / 'enrollment.key', 1001, 1001)
                self.enrolling = True
                self.preparing = True
                self.start()
                self.error = ''
                threading.Thread(target=self.enroll, args=(bool(key), self.config['revision']), daemon=True).start()
                self.event('Tailscale sign-in requested; public website is still off.')
            elif action in ('disconnect', 'forget'):
                self.stop()
                self.config['enabled'] = False
                try:
                    self.persist()
                except UserError:
                    self.config['enabled'] = False
                    self.error = 'Processes stopped, but Off could not be saved. Fix private storage before restarting the connector.'
                    try:
                        (STATE / 'config.json').unlink(missing_ok=True)
                    except OSError:
                        pass
                    raise UserError(self.error)
                self.error = ''
                self.config['revision'] += 1
                if action == 'forget':
                    for name in ('cloudflare.token', 'enrollment.key'):
                        (STATE / name).unlink(missing_ok=True)
                    result = run(['/usr/bin/python3', '-c', 'import shutil; from pathlib import Path; base=Path("/opt/stateful/remote-access/tailscale"); [(shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink()) for p in base.iterdir()]'])
                    if result.returncode != 0:
                        raise UserError('Connection stopped, but device state could not be removed. Check private storage permissions and retry Forget.')
                    self.config.update(provider='', url='', hostname='', enrolled=False, publicAccess=False)
                    self.ts_state = ''
                    self.test = None
                self.persist()
                self.event('Local connection stopped.' if action == 'disconnect' else 'Local credentials forgotten; provider cleanup may still be needed.')
            elif action == 'test':
                self.begin_test()
            elif action == 'cancelTest':
                self.pending_test = False
                self.verify_until = 0
                self.cancel.set()
                if self.test:
                    self.test['state'] = 'Cancelled'
                (LINK / 'challenge.json').unlink(missing_ok=True)
            else:
                raise UserError('Unknown Remote Access action.')
            return self.snapshot()

    def enroll(self, key, generation=None):
        try:
            for _ in range(30):
                if not self.enrolling or (generation is not None and generation != self.config['revision']):
                    return
                status = run(TS + ['status', '--json'], 2).stdout
                if status:
                    break
                time.sleep(.2)
            with self.lock:
                if not self.enrolling or (generation is not None and generation != self.config['revision']):
                    return
                args = TS + ['up', '--accept-dns=false', '--accept-routes=false', '--ssh=false',
                             '--hostname=' + self.snapshot()['config']['hostname'], '--timeout=5m']
                if key:
                    args += ['--auth-key=file:' + str(STATE / 'enrollment.key')]
                # A fresh daemon has no netMap and cannot reset Serve until signed in.
                # The player gateway stays stopped until explicit public Connect.
                if json.loads(status or '{}').get('BackendState') == 'Running':
                    if run(TS + ['serve', 'reset'], 3).returncode != 0:
                        raise OSError('Could not clear the previous website route')
                self.login = self.spawn(args)
        except (OSError, ValueError, subprocess.SubprocessError):
            with self.lock:
                if generation is not None and generation != self.config['revision']:
                    return
                self.error = 'Could not start Tailscale sign-in. Retry or inspect the Remote Access service.'
                self.enrolling = False
                if self.config['enabled']:
                    self.config['enabled'] = False
                    self.persist()
        finally:
            with self.lock:
                if generation is None or generation == self.config['revision']:
                    self.preparing = False

    def observe(self):
        details = {'startedAt': self.last_start, 'reconnects': max(0, self.restarts - 1), 'origin': ORIGIN}
        state = 'Connecting'
        if self.config['provider'] == 'cloudflare':
            c = http.client.HTTPConnection('127.0.0.1', 20241, timeout=2)
            try:
                c.request('GET', '/metrics')
                r = c.getresponse()
                metrics = r.read(131072).decode('utf-8', 'replace')
                values = re.findall(r'^cloudflared_tunnel_ha_connections(?:\{[^\n]*\})?\s+([\d.]+)', metrics, re.M)
                count = int(sum(float(v) for v in values))
                details.update(edgeConnections=count, version='2026.10.7')
                state = 'Connected' if count else 'Connecting'
            finally:
                c.close()
        else:
            result = run(TS + ['status', '--json'], 3)
            data = json.loads(result.stdout)
            self.ts_state = data.get('BackendState', '')
            if self.ts_state != 'Running':
                self.auth_url = provider_link(data.get('AuthURL', ''))
            node = data.get('Self') or {}
            host = str(node.get('DNSName', '')).rstrip('.')
            details.update(device=node.get('HostName', ''), addresses=node.get('TailscaleIPs', []),
                           identityExpires=node.get('KeyExpiry'), version='1.104.5', backend=self.ts_state)
            if self.ts_state == 'Running':
                if not self.config.get('enrolled'):
                    self.config['enrolled'] = True
                    self.persist()
                if self.preparing:
                    self.observed = {'state':'Restoring saved device', 'time':time.time(), 'details':details}
                    return
                if self.config['enabled'] and (self.login is None or self.login.poll() is not None):
                    self.enrolling = False
                if not self.config['enabled']:
                    self.auth_url = ''
                desired = self.config.get('hostname')
                if desired and (node.get('HostName') != desired or not re.fullmatch(re.escape(desired) + r'(-[0-9]+)?', host.split('.')[0])):
                    if time.time() >= self.next_hostname:
                        self.next_hostname = time.time() + 10
                        if run(TS + ['set', '--hostname=' + desired], 3).returncode != 0:
                            raise UserError('Could not apply the hostname. Disconnect, check the name, and reconnect.')
                    self.observed = {'state':'Updating hostname', 'time':time.time(), 'details':details}
                    self.error = 'Waiting for Tailscale to assign the hostname. If it stays unchanged, check the device name and automatic naming in Tailscale Machines.'
                    return
                if self.error.startswith('Waiting for Tailscale to assign'):
                    self.error = ''
                (STATE / 'enrollment.key').unlink(missing_ok=True)
                if host.endswith('.ts.net'):
                    url = website('https://' + host)
                    if self.config['url'] != url:
                        self.config['url'] = url
                        self.persist()
                    if self.config['enabled'] and not self.serving and time.time() >= self.next_serve:
                        self.next_serve = time.time() + 10
                        if not self.config.get('publicAccess'):
                            raise UserError('Choose Publish website before enabling public access.')
                        # Replace any earlier private Serve or unrelated route.
                        if run(TS + ['serve', 'reset'], 3).returncode != 0:
                            raise UserError('Could not reset the website route. Reconnect Tailscale and retry.')
                        self.gateway_start(host)
                        try:
                            r = run(TS + ['funnel', '--bg', '--https=443', ORIGIN], 4)
                        except subprocess.TimeoutExpired as exc:
                            # Funnel prints the operator approval URL and waits.
                            # Preserve that actionable output when our bounded
                            # command timeout expires instead of losing all status.
                            def text(value):
                                return value.decode('utf-8', 'replace') if isinstance(value, bytes) else value or ''
                            r = subprocess.CompletedProcess(exc.cmd, 124, text(exc.stdout), text(exc.stderr))
                        self.serving = r.returncode == 0
                        if self.serving:
                            self.auth_url = ''
                            self.error = ''
                        else:
                            matches = re.findall(r'https://[^\s]+', r.stdout + r.stderr)
                            self.auth_url = next((provider_link(x) for x in matches if provider_link(x)), '')
                            self.error = ('Enable public access in Tailscale using the approval link below. PhValheim will retry automatically.'
                                          if self.auth_url else 'Public HTTPS could not start. Check Funnel permission in Tailscale; PhValheim will retry.')
                    if self.serving:
                        serve = json.loads(run(TS + ['serve', 'status', '--json'], 3).stdout)
                        endpoint = host + ':443'
                        route = (serve.get('Web') or {}).get(endpoint, {}).get('Handlers', {}).get('/', {})
                        # Serve being configured is not proof of public access.
                        self.serving = ((serve.get('AllowFunnel') or {}).get(endpoint) is True
                                        and (serve.get('TCP') or {}).get('443', {}).get('HTTPS') is True
                                        and route.get('Proxy') == ORIGIN)
                        if not self.serving:
                            self.error = 'The public website route is not active. PhValheim will retry.'
                state = 'Connected' if self.serving else 'Signed in — website off' if not self.config['enabled'] else 'Public access approval required' if self.auth_url else 'Connecting public website'
            else:
                state = 'Waiting for sign-in' if self.ts_state == 'NeedsLogin' else 'Device approval required' if self.ts_state == 'NeedsMachineAuth' else 'Connecting'
            if self.login and self.login.poll() is not None and self.ts_state != 'Running':
                self.error = 'Sign-in did not complete. Retry sign-in or check device approval in Tailscale.'
        if self.config['enabled'] and self.gateway and self.gateway.poll() is not None:
            state = 'Website gateway stopped'
        self.observed = {'state': state, 'time': time.time(), 'details': details}
        if state == 'Connected' and self.pending_test and time.time() - self.last_test >= 10:
            self.pending_test = False
            if not self.verify_until:
                self.verify_until = time.time() + 120
            self.begin_test()

    def monitor(self):
        while not self.shutting_down:
            with self.lock:
                try:
                    if self.config['enabled'] or self.enrolling:
                        if not self.proc or self.proc.poll() is not None:
                            if time.time() >= self.next_start:
                                self.start()
                            self.observed = {'state': 'Reconnecting', 'time': time.time(), 'details': {}}
                        else:
                            self.observe()
                    else:
                        self.observed = {'state': 'Off', 'time': time.time(), 'details': {}}
                except UserError as e:
                    self.error = str(e)
                except (OSError, ValueError, subprocess.SubprocessError, http.client.HTTPException):
                    self.observed = {'state': 'Waiting for provider status', 'time': time.time(), 'details': {}}
            time.sleep(2)

    def begin_test(self):
        if not self.config['enabled']:
            raise UserError('Connect before testing the remote website.')
        if self.test and self.test['state'] == 'Running':
            return
        if time.time() - self.last_test < 10:
            # Repeated clicks reuse the current result; expose the cooldown in status.
            return
        self.last_test = time.time()
        self.cancel.set()
        self.cancel = threading.Event()
        test = {'id': secrets.token_hex(12), 'revision': self.config['revision'], 'state': 'Running',
                'started': time.time(), 'source': 'This server', 'steps': {}, 'device': 'Not tested'}
        self.test = test
        for name in STEPS:
            test['steps'][name] = {'state': 'Waiting', 'message': '', 'time': time.time()}
        threading.Thread(target=self.diagnostics, args=(test, self.cancel), daemon=True).start()

    def step(self, test, name, state, message):
        with self.lock:
            if self.test is test and self.config['revision'] == test['revision']:
                test['steps'][name] = {'state': state, 'message': message, 'time': time.time()}

    def diagnostics(self, test, cancelled):
        nonce = secrets.token_hex(24)
        challenge = {'nonce': nonce, 'instance': self.instance, 'expires': time.time() + 300}
        path = '/remote-test.php?nonce=' + nonce
        current = 'local'
        try:
            with self.lock:
                if self.test is not test or cancelled.is_set():
                    return
                atomic(LINK / 'challenge.json', challenge, 0o644)
                test['nonce'] = nonce
            self.step(test, current, 'Checking', 'Checking the local player website.')
            c = UnixHTTP('localhost', timeout=5)
            try:
                c.request('GET', path)
                r = c.getresponse()
                body = r.read(16384)
                data = json.loads(body)
                if r.status != 200 or data.get('nonce') != nonce or data.get('instance') != self.instance:
                    raise UserError('The local player website did not return this instance’s test response.')
            finally:
                c.close()
            self.step(test, current, 'Passed', 'Local player website returned a fresh instance challenge.')
            current = 'provider'
            self.step(test, current, 'Checking', 'Waiting for the provider connection (up to 20 seconds).')
            until = time.monotonic() + 20
            while time.monotonic() < until and not cancelled.is_set():
                with self.lock:
                    ready = self.observed['state'] == 'Connected' and time.time() - self.observed['time'] < 15
                if ready:
                    break
                time.sleep(.5)
            if cancelled.is_set():
                return
            if not ready:
                raise UserError('Provider is not connected yet. Check the token, sign-in/approval, and outbound network access.')
            self.step(test, current, 'Passed', 'Provider reports an active connection.')
            with self.lock:
                url = self.config['url']
            host = urlsplit(url).hostname
            current = 'dns'
            self.step(test, current, 'Checking', 'Resolving the website from this server.')
            try:
                answers = {x[4][0] for x in socket.getaddrinfo(host, 443, socket.AF_INET, socket.SOCK_STREAM)}
            except socket.gaierror:
                raise PendingPublicError('Public DNS is not ready. For a new Tailscale Funnel, DNS can take up to 10 minutes; retry Test connection.')
            if not answers or not all(public_ip(a) for a in answers):
                raise UserError('Public DNS is not ready or returned a private address. For a new Tailscale Funnel, DNS can take up to 10 minutes; retry Test connection.')
            self.step(test, current, 'Passed', 'Public DNS answers found; destination pinned for this test.')
            current = 'tls'
            self.step(test, current, 'Checking', 'Validating hostname and certificate trust.')
            c = PinnedHTTPS(host, sorted(answers)[0])
            try:
                c.connect()
                expiry = c.sock.getpeercert().get('notAfter', 'Unavailable')
                self.step(test, current, 'Passed', 'Certificate verified; expires ' + expiry + '.')
                current = 'route'
                self.step(test, current, 'Checking', 'Requesting a fresh challenge through the public hostname.')
                started = time.monotonic()
                c.request('GET', path, headers={'Cache-Control': 'no-cache', 'Accept': 'application/json'})
                r = c.getresponse()
                body = r.read(16385)
                if r.status != 200 or len(body) > 16384:
                    raise UserError('Website returned HTTP ' + str(r.status) + '. Check the route and any provider sign-in/challenge page.')
                try:
                    actual = json.loads(body)
                except ValueError:
                    raise UserError('The website returned a page instead of the PhValheim challenge. Check the route and provider access policy.')
                if actual.get('nonce') != nonce or actual.get('instance') != self.instance:
                    raise UserError('The URL is not reaching this instance. Check the hostname and origin address.')
                self.step(test, current, 'Passed', 'Correct instance reached from this server in %d ms.' % ((time.monotonic() - started) * 1000))
                current = 'transfer'
                if actual.get('payload') != 'phvalheim' * 128:
                    raise UserError('The diagnostic transfer was incomplete. Retry before testing full client archives.')
                self.step(test, current, 'Passed',
                          'Small diagnostic transfer checked. Full client archives still require a real client test.')
            finally:
                c.close()
            with self.lock:
                if self.test is test:
                    test['state'] = 'URL verified from this server'
                    test['deviceUrl'] = url + path + '&browser=1'
        except (OSError, ValueError, UserError, http.client.HTTPException) as e:
            message = str(e) if isinstance(e, UserError) else 'Check failed (' + type(e).__name__ + '). Check DNS, TLS, provider access, and the origin address; then retry.'
            self.step(test, current, 'Failed', message)
            with self.lock:
                if self.test is test:
                    retryable = isinstance(e, PendingPublicError) or (current == 'tls' and isinstance(e, OSError) and not isinstance(e, ssl.SSLCertVerificationError))
                    if (self.config['provider'] == 'tailscale' and self.config['enabled'] and not cancelled.is_set()
                            and retryable and time.time() < self.verify_until):
                        self.pending_test = True
                        test['state'] = 'Retrying public website'
                        test['steps'][current].update(state='Retrying', message='Tailscale is preparing public DNS/HTTPS. Retrying automatically for up to two minutes.')
                    else:
                        test['state'] = 'Check failed'
        finally:
            with self.lock:
                if self.test is test:
                    if cancelled.is_set():
                        test['state'] = 'Cancelled'
                    for step in test['steps'].values():
                        if step['state'] in ('Waiting', 'Checking'):
                            step.update(state='Blocked', message='An earlier step did not complete.')
                    test['finished'] = time.time()


class ControlHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.connection.settimeout(10)
        try:
            line = self.rfile.readline(16385)
            if len(line) > 16384 or not line.endswith(b'\n'):
                raise UserError('Request too large.')
            request = json.loads(line)
            if not isinstance(request, dict):
                raise UserError('Invalid request.')
            result = self.server.controller.action(request)
        except (ValueError, UserError) as e:
            result = {'success': False, 'error': str(e) if isinstance(e, UserError) else 'Invalid request.'}
        except Exception:
            result = {'success': False, 'error': 'Remote Access operation failed; retry or check server status.'}
        self.wfile.write(json.dumps(result).encode() + b'\n')


class ControlServer(socketserver.ThreadingUnixStreamServer):
    daemon_threads = True


def main():
    os.umask(0o077)
    controller = Controller()
    sock = LINK / 'control.sock'
    sock.unlink(missing_ok=True)
    server = ControlServer(str(sock), ControlHandler)
    os.chown(sock, 0, 1000)
    os.chmod(sock, 0o660)
    server.controller = controller
    def shutdown(*_):
        controller.shutting_down = True
        with controller.lock:
            controller.stop()
        sock.unlink(missing_ok=True)
        os._exit(0)
    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    threading.Thread(target=controller.monitor, daemon=True).start()
    server.serve_forever()


if __name__ == '__main__':
    main()
