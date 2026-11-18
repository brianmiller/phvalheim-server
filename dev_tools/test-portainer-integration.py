#!/usr/bin/env python3
# requires: container
"""Portainer CE 2.45.3 end-to-end catalog test; synthetic data, no game startup.

Uses Docker 29.3.0-in-Docker on a unique bridge. Portainer never receives the
host socket. Only the analytics sender mount is added to the production Compose
file; only the repository URL is changed in the temporary v3 feed.

API contracts: portainer/portainer tag 2.45.3, api/http/handler/{templates,
stacks,endpoints}. In that CE tag, dataservices/allowlist populates a missing
SSRF policy with ModeOff and there is no CE allowlist management handler. No
SSRF override is needed. HTTP/Git fixtures bind only this run's bridge gateway;
do not disable protection or allow a whole private subnet on another edition.

Default cleanup STOPS this run's containers/processes, retaining all data,
volumes, network and evidence. --keep retains running fixtures, including on
failure, for separate browser review. state.json contains disposable credentials
and is private (0600); stdout/evidence.json never contain passwords or JWTs.
The admin binding is inside the dind network namespace, not the outer host.
--use-host-docker-hub-auth opts into existing host Docker Hub credentials for
image pulls and a temporary fixture registry; cleanup clears that registry's
credentials even with --keep. Host credentials never enter evidence or state.
"""

import argparse
import base64
import binascii
import copy
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

from fixture_analytics import assert_isolated, docker_args as analytics_args


ROOT = Path(__file__).resolve().parents[1]
PORTAINER_IMAGE = 'portainer/portainer-ce:2.45.3'
DIND_IMAGE = 'docker:29.3.0-dind'
IMAGE = ('theoriginalbrian/phvalheim-server:2.62@sha256:'
         '925c68966570452658a22eeda3d1aa74a7eddabd27a6ee7d5ac5e73d15842009')
SENDER = '/opt/stateless/engine/tools/pushAnalytics.sh'
ENV = {'PHV_PUBLIC_PORT': '18080', 'PHV_ADMIN_PORT': '18081',
       'PHV_GAME_PORT_START': '31000', 'PHV_GAME_PORT_END': '31003'}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def command(args, timeout=45, check=True, **kwargs):
    result = subprocess.run(args, capture_output=True, text=True,
                            timeout=timeout, **kwargs)
    if check and result.returncode:
        # Do not dump argv, API payloads, SQL results or process output to stdout.
        raise RuntimeError(f'{args[0]} {args[1]} exited {result.returncode}')
    return result


def host_docker(*args, **kwargs):
    """Outer daemon: infrastructure owned by this run only."""
    return command(['docker', *args], **kwargs).stdout.strip()


def host_docker_hub_auth():
    directory = Path(os.environ.get('DOCKER_CONFIG') or Path.home() / '.docker')
    try:
        config = json.loads((directory / 'config.json').read_bytes())
        hub_keys = ('https://index.docker.io/v1/', 'docker.io', 'index.docker.io',
                    'registry-1.docker.io')
        helpers = config.get('credHelpers', {})
        if config.get('credsStore') or any(helpers.get(key) for key in hub_keys):
            raise RuntimeError('Host Docker Hub credential helpers are unsupported; '
                               'direct auth is required for the fixture registry')
        entries = config.get('auths', {})
        entry = next((entries[key] for key in hub_keys if key in entries), {})
        if entry.get('identitytoken'):
            raise RuntimeError('Host Docker Hub identity tokens are unsupported; '
                               'direct auth is required for the fixture registry')
        username, separator, password = base64.b64decode(
            entry.get('auth', ''), validate=True).decode('utf-8').partition(':')
        if not separator or not username or not password:
            raise ValueError
        return {'Username': username, 'Password': password}
    except (OSError, ValueError, TypeError, AttributeError, binascii.Error):
        raise RuntimeError('Existing host Docker Hub direct auth is missing or invalid') from None


def free_port(address):
    with socket.socket() as sock:
        sock.bind((address, 0))
        return sock.getsockname()[1]


def serve_fixture_http(address, port, directory):
    directory = Path(directory).resolve()
    repositories = directory / 'repositories'
    if (not directory.name.startswith('phv-portainer-') or
            directory.parent != Path('/tmp') or
            repositories.resolve() != repositories or
            (repositories / 'fixture.git').is_symlink() or
            not (repositories / 'fixture.git').is_dir()):
        raise ValueError('HTTP helper requires a private fixture with a bare repository')
    if address != json.loads((directory / 'evidence.json').read_bytes()).get('gateway'):
        raise ValueError('HTTP helper must bind only its fixture bridge gateway')

    class Handler(BaseHTTPRequestHandler):
        timeout = 10

        def log_message(self, format, *args):
            # Never log request targets, headers or credentials.
            pass

        def reply(self, status, body, content_type='text/plain'):
            self.send_response(status)
            self.send_header('Content-Type', content_type)
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            self.serve()

        def do_POST(self):
            self.serve()

        def serve(self):
            target = urllib.parse.urlsplit(self.path)
            if target.scheme or target.netloc or target.fragment:
                self.reply(404, b'Not found\n')
                return
            feed = self.command == 'GET' and self.path == '/templates.json'
            refs = (self.command == 'GET' and self.path ==
                    '/fixture/info/refs?service=git-upload-pack')
            upload = self.command == 'POST' and self.path == '/fixture/git-upload-pack'
            if not (feed or refs or upload):
                self.reply(404, b'Not found\n')
                return
            lengths = self.headers.get_all('Content-Length', [])
            if (self.headers.get('Transfer-Encoding') is not None or len(lengths) > 1 or
                    (lengths and not re.fullmatch(r'[0-9]{1,7}', lengths[0]))):
                self.reply(400, b'Invalid request framing\n')
                return
            length = int(lengths[0]) if lengths else 0
            if length > 1024 * 1024 or (not upload and length):
                self.reply(413, b'Request body too large\n')
                return
            if upload and (not lengths or self.headers.get('Content-Type') !=
                           'application/x-git-upload-pack-request'):
                self.reply(400, b'Expected Git upload request\n')
                return
            try:
                body = self.rfile.read(length)
                if len(body) != length:
                    self.reply(400, b'Incomplete request body\n')
                    return
                if feed:
                    self.reply(200, (directory / 'http/templates.json').read_bytes(),
                               'application/json')
                    return
                # Portainer strips .git; map the whitelisted canonical routes to the bare repo.
                path_info = '/fixture.git' + target.path[len('/fixture'):]
                env = {'PATH': os.defpath, 'GIT_CONFIG_NOSYSTEM': '1',
                       'GIT_CONFIG_GLOBAL': os.devnull,
                       'GIT_PROJECT_ROOT': str(repositories), 'GIT_HTTP_EXPORT_ALL': '1',
                       'REQUEST_METHOD': self.command, 'PATH_INFO': path_info,
                       'QUERY_STRING': target.query, 'CONTENT_LENGTH': str(length),
                       'CONTENT_TYPE': self.headers.get('Content-Type', ''),
                       'REMOTE_ADDR': self.client_address[0]}
                # Files bound memory use; only upload-pack in this tiny fixture can run.
                with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
                    process = subprocess.Popen(['git', '-c', 'http.receivepack=false',
                                                'http-backend'], env=env,
                                               stdin=subprocess.PIPE, stdout=output,
                                               stderr=errors, start_new_session=True)
                    try:
                        process.communicate(body, timeout=20)
                    finally:
                        try:
                            os.killpg(process.pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                        process.wait(timeout=5)
                    if process.returncode or output.tell() > 8 * 1024 * 1024:
                        errors.seek(0)
                        print('Git HTTP backend failure:', process.returncode,
                              'response bytes:', output.tell(),
                              errors.read(8000).decode(errors='replace'), flush=True)
                        self.reply(502, b'Git backend failed; see private helper log\n')
                        return
                    output.seek(0)
                    headers, separator, data = output.read().partition(b'\r\n\r\n')
                    if not separator:
                        raise ValueError('Git backend returned invalid CGI headers')
                    fields = dict(line.decode().split(': ', 1)
                                  for line in headers.split(b'\r\n'))
                    status = int(fields.get('Status', '200').split()[0])
                    self.reply(status, data, fields.get('Content-Type', 'application/octet-stream'))
            except (OSError, ValueError, subprocess.TimeoutExpired) as error:
                print('HTTP fixture failure:', type(error).__name__, str(error)[:1000], flush=True)
                self.reply(502, b'Fixture request failed; see private helper log\n')

    def interrupted(signum, frame):
        raise SystemExit

    signal.signal(signal.SIGTERM, interrupted)
    # One request at a time bounds backend concurrency; each connection has a deadline.
    with HTTPServer((address, int(port)), Handler) as server:
        server.serve_forever()


class Fixture:
    def __init__(self, args):
        self.args = args
        self.directory = Path(tempfile.mkdtemp(prefix='phv-portainer-', dir='/tmp'))
        self.directory.chmod(0o700)
        self.name = self.directory.name
        self.dind = self.name + '-dind'
        self.portainer = self.name + '-ui'
        self.network = self.name + '-net'
        self.stack_name = self.name + '-stack'
        self.containers = []
        self.processes = []
        self.nested_containers = []
        self.token = ''
        self.base_url = ''
        self.endpoint_id = None
        self.stack_id = None
        self.app_id = None
        self.data_volume = None
        self.backup_volume = None
        self.docker_hub_registry_id = None
        self.docker_hub_registry_attempted = False
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        self.state = {'username': 'fixture-' + secrets.token_hex(4),
                      'password': secrets.token_urlsafe(32)}
        self.evidence = {
            'run': self.name, 'status': 'running', 'checks': [],
            'credentials_path': str(self.directory / 'state.json'),
            'portainer_image': PORTAINER_IMAGE, 'dind_image': DIND_IMAGE,
            'application_image': IMAGE, 'environment': ENV,
            'network': self.network, 'directory': str(self.directory),
            'claims': {'browser': False, 'steam_sign_in': False, 'gameplay': False},
            'ssrf': 'CE 2.45.3 default ModeOff; no policy override',
        }
        self.save()

    def save(self):
        self.state.update({'portainer_url': self.base_url, 'jwt': self.token})
        self.evidence.update({
            'host_containers': self.containers,
            'nested_containers': self.nested_containers,
            'helper_pids': [p.pid for p in self.processes],
            'endpoint_id': self.endpoint_id, 'stack_id': self.stack_id,
            'data_volume': self.data_volume,
            'backup_volume': self.backup_volume,
            'totals': {'passed': sum(c['passed'] for c in self.evidence['checks']),
                       'failed': sum(not c['passed'] for c in self.evidence['checks'])},
        })
        for filename, value in [('state.json', self.state), ('evidence.json', self.evidence)]:
            path = self.directory / filename
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, 'w') as output:
                json.dump(value, output, indent=2)
                output.write('\n')

    def ck(self, name, condition):
        self.evidence['checks'].append({'name': name, 'passed': bool(condition)})
        self.save()
        if not condition:
            raise RuntimeError(name)

    def nested(self, *args, **kwargs):
        """Application operations ALWAYS execute against the nested Unix socket."""
        return host_docker('exec', self.dind, 'docker',
                           '--host=unix:///var/run/docker.sock', *args, **kwargs)

    def app_exec(self, *args, container=None, **kwargs):
        return self.nested('exec', container or self.app_id, *args, **kwargs)

    def sql(self, query):
        return self.app_exec('mysql', '-N', '-uroot', 'phvalheim', '-e', query)

    def admin(self, action, payload=None):
        args = ['curl', '--max-time', '15', '-fsS',
                'http://127.0.0.1:8081/adminAPI.php?action=' + action]
        if payload is not None:
            args += ['-H', 'Content-Type: application/json',
                     '--data-binary', json.dumps(payload)]
        return json.loads(self.app_exec(*args, timeout=25))

    def api(self, method, path, payload=None, form=None, timeout=45):
        headers = {}
        data = None
        if payload is not None:
            data = json.dumps(payload).encode()
            headers['Content-Type'] = 'application/json'
        if form is not None:
            boundary = 'phv' + secrets.token_hex(12)
            data = b''.join(
                (f'--{boundary}\r\nContent-Disposition: form-data; '
                 f'name="{key}"\r\n\r\n{value}\r\n').encode()
                for key, value in form.items())
            data += f'--{boundary}--\r\n'.encode()
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        request = urllib.request.Request(self.base_url + '/api' + path,
                                         data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=timeout) as response:
                body = response.read()
                return json.loads(body) if body else None
        except urllib.error.HTTPError as error:
            # Responses to auth requests can contain secrets; never echo them.
            self.state['last_api_error'] = {
                'path': path, 'status': error.code,
                'body': ('Omitted while host Docker Hub authentication is enabled'
                         if self.args.use_host_docker_hub_auth else
                         error.read().decode(errors='replace')[:8000])}
            self.save()
            raise RuntimeError(f'Portainer {method} {path}: HTTP {error.code}') from None

    def configure_docker_hub_auth(self):
        credentials = host_docker_hub_auth()
        self.docker_hub_registry_attempted = True
        registry = self.api('POST', '/registries', {
            'Name': self.name + '-docker-hub', 'Type': 6, 'URL': 'docker.io',
            'Authentication': True, **credentials})
        self.docker_hub_registry_id = registry['Id']
        self.evidence.update({'image_pull_auth': 'authenticated_host_docker_hub',
                              'docker_hub_registry_id': self.docker_hub_registry_id})
        self.save()
        self.api('PUT', f'/endpoints/{self.endpoint_id}/registries/'
                 f'{self.docker_hub_registry_id}',
                 {'UserAccessPolicies': {}, 'TeamAccessPolicies': {}})
        registry = self.api('GET', f'/registries/{self.docker_hub_registry_id}')
        available = self.api('GET', f'/endpoints/{self.endpoint_id}/registries')
        self.ck('authenticated fixture Docker Hub registry is available to the nested endpoint',
                registry['Authentication'] and
                str(self.endpoint_id) in registry['RegistryAccesses'] and
                any(r['Id'] == self.docker_hub_registry_id and r['Authentication']
                    for r in available))

    def clear_docker_hub_auth(self):
        if not self.docker_hub_registry_attempted:
            return
        # A failed POST response may still have persisted the registry.
        registries = self.api('GET', '/registries', timeout=10)
        owned = [r for r in registries if r['Name'] == self.name + '-docker-hub'
                 and r['Type'] == 6 and r['URL'] == 'docker.io']
        if self.docker_hub_registry_id is not None and not any(
                r['Id'] == self.docker_hub_registry_id for r in owned):
            raise RuntimeError('Unable to verify fixture Docker Hub registry ownership')
        for registry in owned:
            path = f'/registries/{registry["Id"]}'
            self.api('PUT', path, {'Authentication': False, 'Username': '', 'Password': ''},
                     timeout=10)
            sanitized = self.api('GET', path, timeout=10)
            self.ck('fixture Docker Hub registry credentials cleared',
                    sanitized['Authentication'] is False and
                    all(not sanitized.get(key) for key in
                        ('Username', 'Password', 'AccessToken')) and
                    all(not (sanitized.get('ManagementConfiguration') or {}).get(key)
                        for key in ('Authentication', 'Username', 'Password')))
        self.docker_hub_registry_attempted = False

    def wait(self, name, probe, timeout=None, process=None):
        deadline = time.monotonic() + (timeout or self.args.ready_timeout)
        while time.monotonic() < deadline:
            if process is not None and process.poll() is not None:
                self.state['helper_failure'] = {'name': name, 'exit_code': process.returncode}
                self.save()
                raise RuntimeError(name + ' helper exited; see private state.json and helper log')
            try:
                value = probe()
                if value:
                    return value
            except (RuntimeError, subprocess.TimeoutExpired, urllib.error.URLError,
                    OSError, ValueError):
                pass
            time.sleep(2)
        raise RuntimeError('Timed out: ' + name)

    def helper(self, label, args):
        fd = os.open(self.directory / (label + '.log'),
                     os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'w') as output:
            process = subprocess.Popen(args, stdout=output, stderr=subprocess.STDOUT,
                                       start_new_session=True)
        self.processes.append(process)
        self.save()
        return process

    def infra(self):
        for image in (PORTAINER_IMAGE, DIND_IMAGE):
            host_docker('image', 'inspect', image)
        host_docker('network', 'create', '--label', 'phv.fixture=' + self.name, self.network)
        network = json.loads(host_docker('network', 'inspect', self.network))[0]
        self.gateway = network['IPAM']['Config'][0]['Gateway']
        self.evidence['gateway'] = self.gateway
        self.sender = ROOT / 'dev_tools/fixtures/analytics-disabled.sh'
        analytics_args()  # Check existence and container-readable permissions.
        self.sender_hash = sha(self.sender.read_bytes())
        volumes = [self.name + '-docker', self.name + '-portainer']
        for volume in volumes:
            host_docker('volume', 'create', '--label', 'phv.fixture=' + self.name, volume)
        self.evidence['host_volumes'] = volumes
        dind_id = host_docker(
            'create', '--privileged', '--name', self.dind, '--network', self.network,
            '--label', 'phv.fixture=' + self.name, '-e', 'DOCKER_TLS_CERTDIR=',
            '--mount', f'type=volume,source={volumes[0]},target=/var/lib/docker',
            '--mount', f'type=bind,source={self.sender},target={self.sender},readonly',
            DIND_IMAGE, '--tls=false')
        self.containers.append({'name': self.dind, 'id': dind_id})
        self.save()
        host_docker('start', dind_id)
        self.wait('nested Docker readiness', lambda: self.nested('info', '--format', '{{.ID}}'))
        self.ck('nested daemon is Docker 29.3.0',
                self.nested('version', '--format', '{{.Server.Version}}') == '29.3.0')
        sender_content = host_docker('exec', self.dind, 'cat', str(self.sender))
        self.ck('analytics block exists inside dind before any app starts',
                'PHV_TEST_ANALYTICS_DISABLED' in sender_content and
                sha((sender_content + '\n').encode()) == self.sender_hash)
        dind_inspect = json.loads(host_docker('inspect', dind_id))[0]
        sender_mounts = [m for m in dind_inspect['Mounts']
                         if m['Destination'] == str(self.sender)]
        self.ck('dind exposes only the read-only sender at its original source path',
                len(sender_mounts) == 1 and not sender_mounts[0]['RW'] and
                sender_mounts[0]['Source'] == str(self.sender))
        self.dind_ip = dind_inspect['NetworkSettings']['Networks'][self.network]['IPAddress']
        self.evidence['dind_ip'] = self.dind_ip
        self.evidence['review'] = {
            'public_url': f'http://{self.dind_ip}:{ENV["PHV_PUBLIC_PORT"]}',
            'admin_in_dind_namespace': f'http://127.0.0.1:{ENV["PHV_ADMIN_PORT"]}',
            'dind_pid': dind_inspect['State']['Pid'],
        }
        # No host socket, socket proxy, or host network reaches Portainer.
        ui_id = host_docker(
            'create', '--name', self.portainer, '--network', self.network,
            '--label', 'phv.fixture=' + self.name,
            '-p', f'127.0.0.1:{self.args.port}:9000',
            '--mount', f'type=volume,source={volumes[1]},target=/data',
            PORTAINER_IMAGE, '--http-enabled', '--no-setup-token')
        self.containers.append({'name': self.portainer, 'id': ui_id})
        self.save()
        ui = json.loads(host_docker('inspect', ui_id))[0]
        self.ck('Portainer has only its own data volume',
                len(ui['Mounts']) == 1 and ui['Mounts'][0]['Destination'] == '/data' and
                ui['Mounts'][0]['Type'] == 'volume')
        host_docker('start', ui_id)
        ui = json.loads(host_docker('inspect', ui_id))[0]
        binding = ui['NetworkSettings']['Ports']['9000/tcp']
        self.ck('Portainer HTTP binds only outer loopback',
                len(binding) == 1 and binding[0]['HostIp'] == '127.0.0.1')
        self.base_url = 'http://127.0.0.1:' + binding[0]['HostPort']
        self.evidence['portainer_url'] = self.base_url
        self.save()
        status = self.wait('Portainer API', lambda: self.api('GET', '/status'))
        self.ck('Portainer reports 2.45.3', status['Version'] == '2.45.3')
        self.api('POST', '/users/admin/init',
                 {'Username': self.state['username'], 'Password': self.state['password']})
        auth = self.api('POST', '/auth',
                        {'Username': self.state['username'], 'Password': self.state['password']})
        self.token = auth['jwt']
        self.save()
        endpoint = self.api('POST', '/endpoints', form={
            'Name': self.name, 'EndpointCreationType': '1', 'ContainerEngine': 'docker',
            'URL': f'tcp://{self.dind}:2375', 'TLS': 'false'})
        self.endpoint_id = endpoint['Id']
        endpoints = self.api('GET', '/endpoints')
        self.ck('Portainer connects exclusively to the unique nested daemon',
                len(endpoints) == 1 and endpoint['Type'] == 1 and
                endpoint['URL'] == f'tcp://{self.dind}:2375')

    def fixtures(self):
        production = (ROOT / 'portainer/docker-compose.yml').read_bytes()
        feed_bytes = (ROOT / 'portainer/templates.json').read_bytes()
        self.evidence['sha256'] = {'production_compose': sha(production),
                                   'production_feed': sha(feed_bytes),
                                   'analytics_sender': self.sender_hash}
        feed = json.loads(feed_bytes)
        self.ck('local feed is v3 with exactly the intended type-3 template',
                feed.get('version') == '3' and len(feed['templates']) == 1 and
                feed['templates'][0]['type'] == 3 and
                feed['templates'][0]['title'] == 'PhValheim Server')
        template = feed['templates'][0]
        self.ck('template exposes exactly the four deployment values',
                len(template['env']) == 4 and {e['name'] for e in template['env']} == set(ENV))
        text = production.decode()
        self.ck('Compose pins the released image digest',
                re.findall(r'^    image: (.+)$', text, re.M) == [IMAGE])
        anchor = '      - data:/opt/stateful\n'
        extra = f"      - '{self.sender}:{SENDER}:ro'\n"
        self.ck('Compose has one data mount insertion point', text.count(anchor) == 1)
        self.compose = text.replace(anchor, anchor + extra)
        self.ck('temporary Compose differs only by the analytics mount',
                self.compose.count(extra) == 1 and self.compose.replace(extra, '') == text)
        self.evidence['sha256']['test_compose'] = sha(self.compose.encode())
        repositories = self.directory / 'repositories'
        repository = repositories / 'fixture'
        compose_path = repository / template['repository']['stackfile']
        self.ck('template points to the intended stackfile',
                template['repository']['stackfile'] == 'portainer/docker-compose.yml')
        compose_path.parent.mkdir(parents=True)
        compose_path.write_text(self.compose)
        command(['git', 'init', '--initial-branch=master', str(repository)])
        command(['git', '-C', str(repository), 'add', '.'])
        command(['git', '-C', str(repository), '-c', 'user.name=Portainer fixture',
                 '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                 'commit', '-m', 'Disposable analytics-isolated Compose fixture'])
        self.evidence['fixture_commit'] = command(
            ['git', '-C', str(repository), 'rev-parse', 'HEAD']).stdout.strip()
        bare_repository = repositories / 'fixture.git'
        command(['git', 'clone', '--bare', str(repository), str(bare_repository)])
        http_port = free_port(self.gateway)
        self.git_url = f'http://{self.gateway}:{http_port}/fixture'

        def git_ready():
            result = command(['git', 'ls-remote', self.git_url, 'HEAD'], timeout=10,
                             check=False)
            if result.returncode:
                self.state['last_git_error'] = {'exit_code': result.returncode,
                                                'stdout': result.stdout[:8000],
                                                'stderr': result.stderr[:8000]}
                self.save()
                return None
            return result.stdout

        original = copy.deepcopy(feed)
        template['repository']['url'] = self.git_url
        restored = copy.deepcopy(feed)
        restored['templates'][0]['repository']['url'] = original['templates'][0]['repository']['url']
        self.ck('temporary feed changes only repository transport URL', restored == original)
        http_dir = self.directory / 'http'
        http_dir.mkdir()
        test_feed = json.dumps(feed).encode()
        (http_dir / 'templates.json').write_bytes(test_feed)
        self.evidence['sha256']['test_feed'] = sha(test_feed)
        http_process = self.helper('http', [
            sys.executable, str(Path(__file__).resolve()), '--serve-fixture-http',
            self.gateway, str(http_port), str(self.directory)])
        self.feed_url = f'http://{self.gateway}:{http_port}/templates.json'

        def download():
            with self.opener.open(self.feed_url, timeout=5) as response:
                return response.read()

        downloaded = self.wait('HTTP fixture', download, process=http_process)
        self.ck('downloaded feed matches isolated v3 type-3 feed',
                downloaded == test_feed and json.loads(downloaded)['version'] == '3' and
                json.loads(downloaded)['templates'][0]['type'] == 3)
        self.wait('SMART HTTP Git fixture', git_ready, process=http_process)
        self.ck('read-only SMART HTTP fixture helper is alive', http_process.poll() is None)
        self.evidence.update({'feed_url': self.feed_url, 'git_url': self.git_url})
        self.api('PUT', '/settings', {'TemplatesURL': self.feed_url})
        loaded = self.api('GET', '/templates')
        # Portainer serializes additional zero-valued Template fields.
        self.ck('Portainer downloads the v3 type-3 template',
                loaded['version'] == '3' and len(loaded['templates']) == 1 and
                all(loaded['templates'][0].get(key) == value
                    for key, value in template.items()))
        self.template = loaded['templates'][0]
        fetched = self.api('POST', f'/templates/{self.template["id"]}/file')
        self.ck('Portainer clones the feed repository and reads Compose',
                fetched['FileContent'] == self.compose)

    def stack_path(self, suffix=''):
        return f'/stacks/{self.stack_id}{suffix}?endpointId={self.endpoint_id}'

    def deployed(self):
        def active():
            stack = self.api('GET', self.stack_path())
            if stack['Status'] == 4:
                # Retain API diagnostics privately without echoing possible secrets.
                self.state['deployment_failure'] = (
                    'Omitted while host Docker Hub authentication is enabled'
                    if self.args.use_host_docker_hub_auth else stack.get('DeploymentStatus'))
                self.save()
                raise ValueError('Portainer deployment failed; see state.json')
            return stack if stack['Status'] == 1 else None

        self.wait('Portainer stack active (private diagnostics in state.json)', active)
        ids = self.nested('ps', '-aq', '--filter',
                          'label=com.docker.compose.project=' + self.stack_name,
                          '--filter', 'label=com.docker.compose.service=phvalheim').splitlines()
        self.ck('stack owns exactly one PhValheim container', len(ids) == 1)
        self.app_id = self.nested('inspect', '--format', '{{.Id}}', ids[0])
        if self.app_id not in self.nested_containers:
            self.nested_containers.append(self.app_id)
        self.save()

    def inspect_app(self, stage):
        inspection = json.loads(self.nested('inspect', self.app_id))[0]
        self.ck(stage + ': running pinned image', inspection['State']['Running'] and
                inspection['Config']['Image'] == IMAGE)
        image = json.loads(self.nested('image', 'inspect', IMAGE))[0]
        digest = IMAGE.split('@')[1]
        self.ck(stage + ': image digest and content ID match',
                inspection['Image'] == image['Id'] and
                any(d.endswith('@' + digest) for d in image['RepoDigests']))
        ports = inspection['HostConfig']['PortBindings']
        udp_ports = {f'{p}/udp' for p in range(31000, 31004)}
        self.ck(stage + ': exactly matching TCP and bounded UDP mappings',
                set(ports) == udp_ports | {'8080/tcp', '8081/tcp'} and
                all(len(ports[p]) == 1 and
                    ports[p][0]['HostPort'] == p.split('/')[0] and
                    ports[p][0]['HostIp'] in ('', '0.0.0.0') for p in udp_ports) and
                len(ports['8080/tcp']) == 1 and
                ports['8080/tcp'][0]['HostPort'] == ENV['PHV_PUBLIC_PORT'] and
                ports['8080/tcp'][0]['HostIp'] in ('', '0.0.0.0') and
                ports['8081/tcp'] == [{'HostIp': '127.0.0.1', 'HostPort': ENV['PHV_ADMIN_PORT']}])
        environment = dict(e.split('=', 1) for e in inspection['Config']['Env'])
        self.ck(stage + ': bounded allocator ENV matches published range',
                environment.get('PHVALHEIM_GAME_PORT_START') == '31000' and
                environment.get('PHVALHEIM_GAME_PORT_END') == '31003')
        mounts = [m for m in inspection['Mounts'] if m['Destination'] == '/opt/stateful']
        self.ck(stage + ': stack data volume is persistent',
                len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['RW'] and
                mounts[0]['Name'] == self.stack_name + '_data' and
                (self.data_volume is None or mounts[0]['Name'] == self.data_volume))
        self.data_volume = mounts[0]['Name']
        backups = [m for m in inspection['Mounts']
                   if m['Destination'] == '/opt/stateful/backups']
        self.ck(stage + ': stack backups volume is persistent',
                len(backups) == 1 and backups[0]['Type'] == 'volume' and backups[0]['RW'] and
                backups[0]['Name'] == self.stack_name + '_backups' and
                (self.backup_volume is None or backups[0]['Name'] == self.backup_volume))
        self.backup_volume = backups[0]['Name']
        self.analytics(stage, inspection)

    def analytics(self, stage, inspection=None, container=None):
        ident = container or self.app_id
        inspection = inspection or json.loads(self.nested('inspect', ident))[0]
        assert_isolated(inspection)
        marker = self.app_exec('cat', SENDER, container=ident)
        self.ck(stage + ': read-only analytics mount has the fixture marker',
                'PHV_TEST_ANALYTICS_DISABLED' in marker and
                sha((marker + '\n').encode()) == self.sender_hash)
        self.app_exec('sh', '-ec',
                      f'{SENDER}; {SENDER} --disabled; '
                      'test ! -e /opt/stateful/analytics/credential.json', container=ident)
        self.ck(stage + ': startup and explicit sender calls did not enroll', True)

    def ready(self):
        def probe():
            settings = self.admin('getServerSettings')
            self.app_exec('grep', '-q', '^phvalheimVersion=', '/etc/environment')
            return settings['settings'] if settings.get('success') else None

        settings = self.wait('real PHP settings and initialized environment', probe)
        self.app_exec('supervisorctl', 'stop', 'phvalheim')
        status = self.app_exec('supervisorctl', 'status', 'phvalheim', check=False)
        self.ck('fixture engine stopped before any synthetic world operation', 'STOPPED' in status)
        return settings

    def persistence(self, stage):
        settings = self.admin('getServerSettings')['settings']
        self.ck(stage + ': setup and managed range persist',
                settings['setupComplete'] == 2 and settings['basePort'] == 31000 and
                settings['gamePortRange']['enabled'] and settings['gamePortRange']['end'] == 31003 and
                settings['gameDNS'] == 'fixture.invalid' and settings['steamAPIKey'] == '0' * 32)
        self.ck(stage + ': DB world, reserved port and stopped state persist',
                self.sql("SELECT CONCAT(name, ':', port, ':', mode, ':', autostart) "
                         "FROM worlds WHERE name='portainerfixture'") ==
                'portainerfixture:31000:stopped:0' and
                self.sql('SELECT COUNT(*) FROM worlds') == '1')
        self.ck(stage + ': DB settings persist',
                self.sql("SELECT CONCAT(setupComplete, ':', gameDNS, ':', steamApiKey) FROM settings") ==
                '2:fixture.invalid:' + '0' * 32)
        self.ck(stage + ': data marker persists',
                self.app_exec('cat', '/opt/stateful/portainer-fixture-marker') == self.name + ':data')
        self.ck(stage + ': backup marker persists',
                self.app_exec('cat', '/opt/stateful/backups/portainer-fixture-backup-marker') ==
                self.name + ':backups')

    def exercise(self):
        # Pull only inside the new daemon. Give each pull at least 90 seconds.
        if self.args.use_host_docker_hub_auth:
            host_docker('--host', f'tcp://{self.dind_ip}:2375', 'pull', '--platform',
                        'linux/amd64', IMAGE, timeout=self.args.pull_timeout)
            self.configure_docker_hub_auth()
        else:
            self.nested('pull', '--platform', 'linux/amd64', IMAGE, timeout=self.args.pull_timeout)
        env = [{'name': key, 'value': value} for key, value in ENV.items()]
        stack = self.api('POST', f'/stacks/create/standalone/repository?endpointId={self.endpoint_id}', {
            'Name': self.stack_name, 'RepositoryURL': self.template['repository']['url'],
            'RepositoryReferenceName': 'refs/heads/master', 'RepositoryAuthentication': False,
            'ComposeFile': self.template['repository']['stackfile'],
            'FromAppTemplate': True, 'Env': env}, timeout=90)
        self.stack_id = stack['Id']
        self.save()
        self.ck('stack API targets the nested endpoint with four ENV values',
                stack['EndpointId'] == self.endpoint_id and stack['Type'] == 2 and
                stack['Env'] == env)
        self.deployed()
        self.inspect_app('initial boot')
        settings = self.ready()
        self.ck('fresh install requires setup and uses managed range',
                settings['setupComplete'] == 0 and settings['basePort'] == 31000 and
                settings['gamePortRange']['end'] == 31003)
        self.ck('real setup API accepts synthetic settings', self.admin('completeSetup', {
            'basePort': 31000, 'gameDNS': 'fixture.invalid', 'steamAPIKey': '0' * 32})['success'])
        self.ck('real admin API creates a synthetic world', self.admin('createWorld', {
            'world': 'portainerfixture', 'vanilla': 1, 'accessOpen': 1})['success'])
        self.ck('world row receives the first managed port',
                self.sql("SELECT port FROM worlds WHERE name='portainerfixture'") == '31000')
        self.sql("UPDATE worlds SET mode='stopped', autostart=0 WHERE name='portainerfixture'")
        self.app_exec('sh', '-ec',
                      'printf %s "$1" > /opt/stateful/portainer-fixture-marker; '
                      'printf %s "$2" > /opt/stateful/backups/portainer-fixture-backup-marker',
                      'marker', self.name + ':data', self.name + ':backups')
        self.persistence('before restart')
        self.analytics('before restart')
        before = self.app_id
        self.api('POST', f'/endpoints/{self.endpoint_id}/docker/containers/{before}/restart?t=20',
                 timeout=60)
        self.ready()
        self.deployed()
        self.ck('Docker restart via Portainer retains the same container ID', self.app_id == before)
        self.inspect_app('restart')
        self.persistence('restart')
        self.analytics('before recreation')
        self.api('PUT', self.stack_path('/git/redeploy'), {
            'RepositoryReferenceName': 'refs/heads/master', 'Env': env,
            'Prune': False, 'RepullImageAndRedeploy': True}, timeout=90)
        self.deployed()
        self.ck('forced Git stack redeploy changes the container ID', self.app_id != before)
        self.inspect_app('recreation')
        self.ready()
        self.persistence('recreation')
        self.evidence['current_container_id'] = self.app_id
        self.evidence['previous_container_id'] = before
        self.negative_control()
        self.ck('production Compose and feed were never modified',
                sha((ROOT / 'portainer/docker-compose.yml').read_bytes()) ==
                self.evidence['sha256']['production_compose'] and
                sha((ROOT / 'portainer/templates.json').read_bytes()) ==
                self.evidence['sha256']['production_feed'])

    def negative_control(self):
        volume = self.name + '-invalid-data'
        self.nested('volume', 'create', volume)
        self.evidence['negative_volume'] = volume
        ident = self.nested('create', '--name', self.name + '-invalid',
                            '--mount', f'type=volume,source={volume},target=/opt/stateful',
                            *analytics_args(), '-e', 'PHVALHEIM_GAME_PORT_START=31001',
                            '-e', 'PHVALHEIM_GAME_PORT_END=31003', IMAGE)
        self.nested_containers.append(ident)
        self.save()
        self.nested('start', ident)
        self.analytics('odd-start control', container=ident)

        def refused():
            status = self.app_exec('supervisorctl', 'status', 'phvalheim',
                                   container=ident, check=False)
            return status if 'FATAL' in status or 'EXITED' in status else None

        self.wait('odd-start fixture engine refuses startup', refused, timeout=90)
        result = self.app_exec('sh', '-c',
                               'timeout 15 /opt/stateless/engine/phvalheim; '
                               'code=$?; printf "\\nENGINE_EXIT=%s\\n" "$code"', container=ident)
        self.ck('odd-start managed range makes the actual engine exit 78',
                'ENGINE_EXIT=78' in result and 'even start' in result)
        self.nested('stop', '--time', '15', ident)

    def cleanup(self):
        failures = []
        for attempt in range(3):
            try:
                self.clear_docker_hub_auth()
                break
            except (Exception, KeyboardInterrupt):
                if attempt == 2:
                    failures.append('clear and verify fixture Docker Hub registry credentials')
        if not self.args.keep:
            # Never remove containers, networks, volumes, images or test data.
            if self.app_id:
                try:
                    ids = self.nested('ps', '-q').splitlines()
                    for ident in ids:
                        self.nested('stop', '--time', '15', ident, timeout=30)
                except (RuntimeError, subprocess.TimeoutExpired):
                    failures.append('stop nested fixture containers')
            for resource in reversed(self.containers):
                try:
                    host_docker('stop', '--time', '20', resource['id'], timeout=35)
                except (RuntimeError, subprocess.TimeoutExpired):
                    failures.append('stop ' + resource['name'])
            for process in self.processes:
                if process.poll() is None:
                    try:
                        os.killpg(process.pid, signal.SIGTERM)
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        os.killpg(process.pid, signal.SIGKILL)
                        process.wait(timeout=5)
                    except ProcessLookupError:
                        pass
        self.evidence['cleanup'] = {'kept_running': self.args.keep,
                                    'data_preserved': True, 'errors': failures}
        if failures or any(not check['passed'] for check in self.evidence['checks']):
            self.evidence['status'] = 'failed'
        self.save()


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--keep', action='store_true',
                        help='retain running isolated fixture and helper processes for review')
    parser.add_argument('--use-host-docker-hub-auth', action='store_true',
                        help='use existing host Docker Hub direct auth for nested image pulls; '
                             'clear fixture registry credentials during cleanup')
    parser.add_argument('--port', type=int, default=0,
                        help='unused outer loopback Portainer HTTP port (default: ephemeral)')
    parser.add_argument('--ready-timeout', type=int, default=360,
                        help='readiness deadline for each phase in seconds (default: 360)')
    parser.add_argument('--pull-timeout', type=int, default=180,
                        help='nested image pull deadline, 90-180 seconds (default: 180)')
    parser.add_argument('--serve-fixture-http', nargs=3, help=argparse.SUPPRESS)
    args = parser.parse_args()
    if args.serve_fixture_http:
        serve_fixture_http(*args.serve_fixture_http)
        return 0
    if not 0 <= args.port <= 65535 or args.ready_timeout <= 0 or not 90 <= args.pull_timeout <= 180:
        parser.error('port must be 0-65535, readiness positive, pull timeout 90-180')
    fixture = Fixture(args)
    print(json.dumps({'run': fixture.name, 'evidence': str(fixture.directory / 'evidence.json')}),
          flush=True)

    def interrupted(signum, frame):
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, interrupted)
    try:
        fixture.infra()
        fixture.fixtures()
        fixture.exercise()
        fixture.evidence['status'] = 'passed'
    except (Exception, KeyboardInterrupt) as error:
        fixture.evidence['status'] = 'failed'
        if not any(not check['passed'] for check in fixture.evidence['checks']):
            fixture.evidence['checks'].append({'name': 'fixture execution', 'passed': False})
        fixture.evidence['failure'] = {'type': type(error).__name__,
                                       'message': str(error) if isinstance(error, RuntimeError)
                                       else 'See phase/check evidence; no secret payloads logged'}
    finally:
        fixture.cleanup()
    print(json.dumps({'status': fixture.evidence['status'],
                      'totals': fixture.evidence['totals'],
                      'evidence': str(fixture.directory / 'evidence.json'),
                      'credentials_path': str(fixture.directory / 'state.json')}), flush=True)
    return 0 if fixture.evidence['status'] == 'passed' else 1


if __name__ == '__main__':
    sys.exit(main())
