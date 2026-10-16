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
import sqlite3
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
        request = urllib.request.Request(BASE + 'index.php?api=1', body, {'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
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

    def test_wait_for_both_and_preserve_position(self):
        self.b.call('join', nick='Маша')
        self.a.call('control', op='load', url='dQw4w9WgXcQ', version=0)
        time.sleep(.17)
        self.a.call('control', op='play', version=1)
        time.sleep(.2)
        waiting = self.a.call('control', op='wait', version=2)
        self.assertTrue(waiting['state']['waiting'])
        self.assertFalse(waiting['state']['playing'])
        self.assertTrue(all(not m['ready'] for m in waiting['members']))
        one = self.a.call('ready')
        self.assertTrue(one['state']['waiting'])
        self.b.call('control', expected=409, op='play', version=3)
        both = self.b.call('ready')
        self.assertFalse(both['state']['waiting'])
        self.assertTrue(both['state']['playing'])
        self.assertAlmostEqual(both['state']['position'], waiting['state']['position'])
        time.sleep(.17)
        self.a.call('control', op='wait', version=4)
        time.sleep(.17)
        cancelled = self.a.call('control', op='wait_cancel', version=5)
        self.assertFalse(cancelled['state']['waiting'])
        self.assertFalse(cancelled['state']['playing'])

    def test_queue_reorder_random_and_auto_advance(self):
        self.b.call('join', nick='Маша')
        q1 = self.a.call('queue', op='add', url='dQw4w9WgXcQ', title='Первое')['queue'][0]
        q2 = self.b.call('queue', op='add', url='https://example.com/second.webm', title='Второе')['queue'][1]
        time.sleep(.21)
        moved = self.b.call('queue', op='up', id=q2['id'])
        self.assertEqual(moved['queue'][0]['title'], 'Второе')
        chosen = self.a.call('control', op='queue_play', id=q1['id'], version=0)
        self.assertEqual(len(chosen['queue']), 1)
        time.sleep(.17)
        self.a.call('control', op='play', version=1)
        time.sleep(.17)
        self.a.call('control', op='seek', position=5, version=2)
        time.sleep(.17)
        advanced = self.a.call('control', op='ended', duration=5, version=3)
        self.assertEqual(advanced['state']['source'], 'file')
        self.assertTrue(advanced['state']['playing'])
        self.assertEqual(advanced['queue'], [])
        self.assertEqual(len(advanced['history']), 2)
        self.b.call('control', expected=409, op='ended', duration=5, version=3)

    def test_bookmarks_history_and_room_isolation(self):
        self.a.call('control', op='load', url='dQw4w9WgXcQ', title='Наш ролик', version=0)
        time.sleep(.17)
        self.a.call('control', op='seek', position=25.5, version=1)
        saved = self.a.call('bookmark', note='<b>Смешной момент</b>')
        bookmark = saved['bookmarks'][0]
        self.assertEqual(bookmark['position'], 25.5)
        self.assertEqual(bookmark['note'], '<b>Смешной момент</b>')
        time.sleep(.17)
        self.a.call('control', op='load', url='https://example.com/movie.mp4', version=2)
        time.sleep(.17)
        restored = self.a.call('control', op='bookmark', id=bookmark['id'], version=3)
        self.assertEqual(restored['state']['video'], 'dQw4w9WgXcQ')
        self.assertEqual(restored['state']['position'], 25.5)
        self.assertEqual(len(restored['history']), 3)
        c = Client()
        other = c.call('create', nick='Другой')
        self.assertEqual(other['bookmarks'], [])
        c.call('control', expected=404, op='bookmark', id=bookmark['id'], version=0)

    def test_pass_choice_enforced_on_server(self):
        b = self.b.call('join', nick='Маша')
        self.a.call('control', op='pass', target=b['me']['id'], version=0)
        time.sleep(.17)
        self.a.call('control', expected=403, op='load', url='dQw4w9WgXcQ', version=1)
        self.b.call('control', op='load', url='dQw4w9WgXcQ', version=1)
        time.sleep(.17)
        self.b.call('control', op='pass', target=None, version=2)
        self.a.call('control', op='load', url='dQw4w9WgXcQ', version=3)

    def test_reactions_and_validation(self):
        self.b.call('join', nick='Маша')
        sent = self.a.call('reaction', emoji='❤️')
        self.assertEqual(self.b.call('state')['reactions'], sent['reactions'])
        self.a.call('reaction', expected=429, emoji='😂')
        self.a.call('reaction', expected=422, emoji='<script>')
        self.a.call('queue', expected=422, op='add', url='http://example.com/movie.mp4')
        self.a.call('queue', expected=422, op='add', url='https://name:pass@example.com/movie.mp4')
        self.a.call('queue', expected=422, op='add', url='https://example.com/script.php')
        file = self.a.call('control', op='load', url='https://example.com/movie.mp4?download=1', version=0)
        self.assertEqual(file['state']['source'], 'file')

    def test_voice_signals_are_scoped_and_cursor_based(self):
        self.b.call('join', nick='Маша')
        pa, pb = 'a'*32, 'b'*32
        self.a.call('voice', op='join', peer=pa)
        self.b.call('voice', op='join', peer=pb)
        sent = self.a.call('signal', peer=pa, target=pb, payload={'type':'offer','sdp':'v=0'})
        self.assertEqual(sent['signals'], [])
        received = self.b.call('state')['signals']
        self.assertEqual(received[0]['payload'], {'type':'offer','sdp':'v=0'})
        self.assertEqual(self.b.call('state', signalAfter=received[0]['id'])['signals'], [])
        time.sleep(.02)
        self.a.call('signal', expected=403, peer='c'*32, target=pb, payload={'type':'offer','sdp':'v=0'})
        self.a.call('signal', expected=422, peer=pa, target=pb, payload={'type':'script','sdp':'x'})
        self.b.call('voice', op='leave', peer=pb)
        self.assertEqual(self.b.call('state')['signals'], [])
        self.a.call('signal', expected=404, peer=pa, target=pb, payload={'type':'offer','sdp':'v=0'})

    def test_upgrade_keeps_existing_room(self):
        c = Client()
        c.room = 'f'*32
        upgraded = c.call('join', nick='После обновления')
        self.assertEqual(upgraded['state']['video'], 'dQw4w9WgXcQ')
        self.assertEqual(upgraded['state']['source'], 'youtube')
        self.assertEqual(upgraded['state']['position'], 17.5)
        self.assertEqual(upgraded['queue'], [])

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
        DOCROOT = Path(temp)/'site'
        DOCROOT.mkdir()
        (DOCROOT/'index.php').write_bytes((ROOT/'index.php').read_bytes())
        with sqlite3.connect(temp + '/test.sqlite') as db:
            db.execute('CREATE TABLE rooms (id TEXT PRIMARY KEY,video TEXT,position REAL NOT NULL DEFAULT 0,playing INTEGER NOT NULL DEFAULT 0,updated REAL NOT NULL,version INTEGER NOT NULL DEFAULT 0)')
            db.execute('INSERT INTO rooms VALUES(?,?,?,?,?,?)', ('f'*32,'dQw4w9WgXcQ',17.5,0,time.time(),0))
        env = dict(os.environ, WATCH_DB=temp + '/test.sqlite', PHP_CLI_SERVER_WORKERS='4')
        log = open(temp + '/server.log', 'w+')
        process = subprocess.Popen([os.environ.get('PHP_BIN', 'php'), '-S', f'127.0.0.1:{port}', '-t', str(DOCROOT)], env=env, stdout=log, stderr=log, start_new_session=True)
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
