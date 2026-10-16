"""Real HTTP integration checks with isolated SQLite and independent cookie jars.
Run: PHP_BIN=php python3 tests/integration.py
"""
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

class Client:
    def __init__(self):
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        html = self.http.open(BASE).read().decode()
        self.csrf = re.search(r'name="csrf-token" content="([a-f0-9]+)"', html)[1]
        self.room = None

    def call(self, action, expected=200, **data):
        body = json.dumps({'action': action, 'room': self.room, **data}).encode()
        request = urllib.request.Request(BASE + 'api.php', body, {'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
        try:
            response = self.http.open(request)
        except urllib.error.HTTPError as e:
            response = e
        result = json.loads(response.read())
        assert response.code == expected, (response.code, result, expected)
        if 'room' in result:
            self.room = result['room']
        return result

class WatchTests(unittest.TestCase):
    def setUp(self):
        self.a, self.b = Client(), Client()
        self.initial = self.a.call('create', nick='Саша')
        self.b.room = self.a.room

    def test_join_chat_and_restore(self):
        state = self.b.call('join', nick='Маша')
        self.assertEqual(len(state['members']), 2)
        sent = self.a.call('message', body='Привет!\n<script>alert(1)</script>')
        received = self.b.call('state')
        self.assertEqual(received['messages'], sent['messages'])
        self.assertIn('\n', received['messages'][0]['body'])
        after = received['messages'][-1]['id']
        self.assertEqual(self.b.call('state', after=after)['messages'], [])
        self.assertEqual(self.a.call('state')['me']['nick'], 'Саша')

    def test_play_pause_seek_and_late_join(self):
        self.a.call('control', op='load', url='https://youtu.be/dQw4w9WgXcQ', version=0)
        time.sleep(.17)
        self.a.call('control', op='play', version=1)
        time.sleep(.25)
        state = self.b.call('join', nick='Маша')
        self.assertTrue(state['state']['playing'])
        self.assertGreater(state['serverTime'] - state['state']['updated'], .2)
        paused = self.b.call('control', op='pause', version=2)
        self.assertFalse(paused['state']['playing'])
        self.assertGreater(paused['state']['position'], .2)
        time.sleep(.17)
        sought = self.b.call('control', op='seek', position=42.5, version=3)
        self.assertEqual(sought['state']['position'], 42.5)
        self.assertEqual(self.a.call('state')['state'], sought['state'])
        time.sleep(.17)
        replay = self.b.call('control', op='replay', version=4)
        self.assertEqual(replay['state']['position'], 0)
        self.assertTrue(replay['state']['playing'])

    def test_conflicting_simultaneous_commands(self):
        self.b.call('join', nick='Маша')
        def write(client):
            try:
                return client.call('control', op='load', url='dQw4w9WgXcQ', version=0)
            except AssertionError as e:
                self.assertIn('409', str(e))
                return None
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            results = list(pool.map(write, [self.a, self.b]))
        self.assertEqual(sum(x is not None for x in results), 1)
        self.assertEqual(self.a.call('state')['state']['version'], 1)

    def test_auth_csrf_and_room_isolation(self):
        self.b.call('state', expected=401)
        self.b.csrf = 'incorrect'
        self.b.call('join', expected=403, nick='Маша')
        c = Client()
        c.call('create', nick='Другой зал')
        self.a.call('message', body='Личное сообщение')
        self.assertEqual(c.call('state')['messages'], [])
        c.room = '0' * 32
        c.call('join', expected=404, nick='Гость')

    def test_validation_and_throttling(self):
        self.b.call('join', expected=409, nick='Саша')
        self.b.call('join', expected=422, nick=' ')
        self.a.call('control', expected=422, op='load', url='https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', version=0)
        self.a.call('control', expected=422, op='load', url='javascript:alert(1)', version=0)
        self.a.call('message', expected=422, body='x' * 1001)
        self.a.call('message', body='Первое')
        self.a.call('message', expected=429, body='Слишком быстро')
        self.a.call('create', expected=429, nick='Ещё зал')
        self.assertEqual(len(self.a.call('state')['messages']), 1)

    def test_video_urls(self):
        for version, url in enumerate(['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=20', 'https://m.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/live/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ']):
            state = self.a.call('control', op='load', url=url, version=version)
            self.assertEqual(state['state']['video'], 'dQw4w9WgXcQ')
            time.sleep(.17)

    def test_invalid_commands_do_not_modify_state(self):
        self.a.call('control', expected=422, op='play', version=0)
        self.a.call('control', op='load', url='dQw4w9WgXcQ', version=0)
        time.sleep(.17)
        self.a.call('control', expected=422, op='seek', position=-1, version=1)
        self.a.call('control', expected=409, op='play', version=0)
        self.a.call('state', expected=422, after=-1)
        self.assertEqual(self.a.call('state')['state']['version'], 1)

    def test_leave_and_rejoin(self):
        self.b.call('join', nick='Маша')
        self.b.call('leave')
        self.b.call('state', expected=401)
        self.assertEqual(len(self.a.call('state')['members']), 1)
        self.b.call('join', nick='Маша')
        self.assertEqual(len(self.a.call('state')['members']), 2)

    def test_production_document_root(self):
        for path in ['../var/watch.sqlite', 'src/bootstrap.php', 'var/watch.sqlite', '.git/config']:
            try:
                self.a.http.open(BASE + path)
                self.fail('Private file exposed: ' + path)
            except urllib.error.HTTPError as e:
                self.assertEqual(e.code, 404)

if __name__ == '__main__':
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    BASE = f'http://127.0.0.1:{port}/'
    with tempfile.TemporaryDirectory() as temp:
        env = dict(os.environ, WATCH_DB=temp + '/test.sqlite', PHP_CLI_SERVER_WORKERS='4')
        log = open(temp + '/server.log', 'w+')
        process = subprocess.Popen([os.environ.get('PHP_BIN', 'php'), '-S', f'127.0.0.1:{port}', '-t', str(ROOT/'public')], env=env, stdout=log, stderr=log, start_new_session=True)
        try:
            for _ in range(100):
                try:
                    urllib.request.urlopen(BASE, timeout=.2).close()
                    break
                except (OSError, urllib.error.URLError):
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP server failed to start')
            suite = unittest.defaultTestLoader.loadTestsFromTestCase(WatchTests)
            result = unittest.TextTestRunner(verbosity=2).run(suite)
        finally:
            os.killpg(process.pid, signal.SIGTERM)
            process.wait()
            log.seek(0)
            output = log.read()
            log.close()
        if 'Fatal error' in output or 'Warning:' in output:
            print(output)
            raise SystemExit(1)
        raise SystemExit(0 if result.wasSuccessful() else 1)
