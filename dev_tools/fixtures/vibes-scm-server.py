#!/usr/bin/env python3
"""Isolated GitHub/GitLab API fixture backed by REAL git-http-backend repositories.

Only used inside test-vibes-scm-e2e.sh's disposable container. Authentication is
checked on every request; credentials never enter the request journal.
"""
import base64
import json
import os
from pathlib import Path
import ssl
import subprocess
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlsplit, parse_qs

ROOT = Path('/tmp/vibes-scm-fixture')
PAK = '/opt/stateful/vibes/buildpak/current'
GIT = PAK + '/git/usr/bin/git'
ENV = dict(os.environ, GIT_EXEC_PATH=PAK+'/git/usr/lib/git-core',
           LD_LIBRARY_PATH=PAK+'/git/usr/lib/x86_64-linux-gnu:'+PAK+'/git/usr/lib',
           GIT_AUTHOR_NAME='SCM Fixture', GIT_AUTHOR_EMAIL='fixture@example.invalid',
           GIT_COMMITTER_NAME='SCM Fixture', GIT_COMMITTER_EMAIL='fixture@example.invalid')


def git(*args, cwd=None, env=None):
    return subprocess.check_output([GIT, *args], cwd=cwd, env=env or ENV,
                                   stderr=subprocess.PIPE).decode().strip()


def repo_path(which, path):
    assert which in ('github', 'gitlab')
    assert all(p and p not in ('.', '..') for p in path.split('/'))
    return ROOT/'repos'/which/(path+'.git')


def create(which, path):
    repo = repo_path(which, path)
    repo.parent.mkdir(parents=True, exist_ok=True)
    git('init', '--bare', '--initial-branch=main', str(repo))
    git('-C', str(repo), 'config', 'http.receivepack', 'true')
    return repo


def control(data):
    which, path = data.get('which', 'github'), data.get('path', 'fixture/Demo')
    repo = repo_path(which, path)
    op = data['op']
    if op == 'create':
        create(which, path)
        return {'ok': True}
    if op == 'inspect':
        return {'head': git('--git-dir='+str(repo), 'rev-parse', data.get('ref', 'main')),
                'refs': git('--git-dir='+str(repo), 'show-ref')}
    if op == 'journal':
        return [json.loads(l) for l in (ROOT/'journal.jsonl').read_text().splitlines()]
    if op == 'delay':
        (ROOT/'delay.json').write_text(json.dumps({'path': '/'+path+'.git', 'seconds': data['seconds']}))
        return {'ok': True}
    work = ROOT/'writer'
    subprocess.run(['rm', '-rf', str(work)], check=True)
    git('clone', str(repo), str(work))
    branch = data.get('branch', 'main')
    if op == 'seed':
        if branch != 'main':
            git('checkout', '-b', branch, cwd=work)
        name = data.get('name', path.split('/')[-1])
        (work/'manifest.json').write_text(json.dumps({'name': name, 'version_number': '0.1.0',
            'description': 'SCM end-to-end fixture', 'website_url': '', 'dependencies': []}))
        (work/'README.md').write_text('SCM fixture\n')
        if data.get('symlink'):
            (work/'escape').symlink_to('/etc/passwd')
        if data.get('metadata'):
            (work/'.vibes').mkdir()
            (work/'.vibes'/'notes.md').write_text('Untrusted upstream metadata')
        git('add', '-A', cwd=work)
    else:
        git('checkout', branch, cwd=work)
        (work/data.get('file', 'remote.txt')).write_text(data.get('text', 'Remote change\n'))
        git('add', '-A', cwd=work)
    git('commit', '-m', data.get('message', op), cwd=work)
    git('push', 'origin', branch, cwd=work)
    if op == 'seed':
        git('--git-dir='+str(repo), 'symbolic-ref', 'HEAD', 'refs/heads/'+branch)
    return {'ok': True, 'head': git('rev-parse', 'HEAD', cwd=work)}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, code, body):
        raw = json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        self.dispatch()

    def do_POST(self):
        self.dispatch()

    def dispatch(self):
        u = urlsplit(self.path)
        host = self.headers.get('Host', '').split(':')[0]
        which = 'github' if host == 'github.com' or u.path.startswith('/github/') else 'gitlab'
        tokens = json.loads((ROOT/'tokens.json').read_text())
        token = tokens[which]
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length) if length else b''
        is_api = u.path.startswith(('/github/', '/api/v4/'))
        auth = self.headers.get('Authorization', '')
        expected = ('x-access-token' if which == 'github' else 'oauth2')+':'+token
        authorized = (auth == 'Bearer '+token if which == 'github' and is_api else
                      self.headers.get('PRIVATE-TOKEN') == token if is_api else
                      auth == 'Basic '+base64.b64encode(expected.encode()).decode())
        if not is_api and '/PublicSeed.git/' in u.path and (self.command == 'GET' or u.path.endswith('/git-upload-pack')):
            authorized = True
        with (ROOT/'journal.jsonl').open('a') as out:
            out.write(json.dumps({'method': self.command, 'path': u.path, 'which': which,
                                  'authorized': authorized})+'\n')
        if not authorized:
            return self.reply(401, {'message': 'Fixture requires the configured credential'})
        if not is_api:
            return self.smart_git(which, u, body)
        p = unquote(u.path)
        if p in ('/github/user', '/api/v4/user'):
            return self.reply(200, {'login': 'fixture', 'username': 'fixture',
                                   'html_url': 'https://github.com/fixture',
                                   'web_url': 'https://gitlab.fixture.test/fixture'})
        if p.startswith('/api/v4/groups/'):
            return self.reply(200, {'id': 12, 'full_path': p[len('/api/v4/groups/'):]})
        if self.command == 'POST':
            d = json.loads(body)
            owner = 'fixture' if which == 'github' or 'namespace_id' not in d else 'team/sub'
            if p.startswith('/github/orgs/'):
                owner = p.split('/')[3]
            path = owner+'/'+d.get('path', d['name'])
            create(which, path)
            return self.reply(201, self.metadata(which, path))
        prefix = '/github/repos/' if which == 'github' else '/api/v4/projects/'
        path = p[len(prefix):]
        return self.reply(200, self.metadata(which, path)) if repo_path(which, path).exists() else self.reply(404, {'message': 'Not Found'})

    def metadata(self, which, path):
        host = 'github.com' if which == 'github' else 'gitlab.fixture.test'
        url = 'https://'+host+'/'+path
        return {'id': 1, 'full_name': path, 'path_with_namespace': path,
                'html_url': url, 'web_url': url, 'clone_url': url+'.git', 'http_url_to_repo': url+'.git'}

    def smart_git(self, which, u, body):
        if (ROOT/'delay.json').exists():
            delay = json.loads((ROOT/'delay.json').read_text())
            if u.path.startswith(delay['path']):
                time.sleep(delay['seconds'])
        env = dict(ENV, GIT_PROJECT_ROOT=str(ROOT/'repos'/which), GIT_HTTP_EXPORT_ALL='1',
                   PATH_INFO=unquote(u.path), QUERY_STRING=u.query, REQUEST_METHOD=self.command,
                   CONTENT_TYPE=self.headers.get('Content-Type', ''), CONTENT_LENGTH=str(len(body)),
                   REMOTE_USER='fixture', REMOTE_ADDR='127.0.0.1')
        if self.headers.get('Git-Protocol'):
            env['HTTP_GIT_PROTOCOL'] = self.headers['Git-Protocol']
        p = subprocess.run([PAK+'/git/usr/lib/git-core/git-http-backend'], input=body,
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
        headers, _, content = p.stdout.partition(b'\r\n\r\n')
        pairs = [l.decode().split(':', 1) for l in headers.split(b'\r\n') if b':' in l]
        status = next((int(v.strip().split()[0]) for k, v in pairs if k.lower() == 'status'), 200)
        self.send_response(status)
        for k, v in pairs:
            if k.lower() not in ('status', 'content-length'):
                self.send_header(k, v.strip())
        self.send_header('Content-Length', str(len(content)))
        self.end_headers()
        self.wfile.write(content)


if __name__ == '__main__':
    if len(sys.argv) > 1:
        print(json.dumps(control(json.loads(sys.argv[1]))))
    else:
        server = ThreadingHTTPServer(('0.0.0.0', 443), Handler)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(ROOT/'cert.pem'), str(ROOT/'key.pem'))
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
        server.serve_forever()
