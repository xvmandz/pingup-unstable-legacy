<?php
declare(strict_types=1);
// Optional TURN configuration. Leave as-is for direct voice connections.
const VMESTE_ICE_SERVERS = [['urls'=>'stun:stun.l.google.com:19302']];

$apiRequest = isset($_GET['api']);
if (!extension_loaded('pdo_sqlite') || !extension_loaded('mbstring')) {
    http_response_code(503);
    header('Content-Type: '.($apiRequest ? 'application/json' : 'text/plain').'; charset=utf-8');
    $message = 'В настройках PHP включите расширения pdo_sqlite и mbstring (PHP 8.2 или новее).';
    echo $apiRequest ? json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE) : $message;
    exit;
}
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_name('vmeste');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['identity'] ??= bin2hex(random_bytes(16));
$identity = $_SESSION['identity'];
$csrf = $_SESSION['csrf'];
session_write_close(); // Polling must never lock another request from this browser.
function fail(int $status, string $message): never { http_response_code($status); echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE); exit; }
function database(): PDO {
    $path = getenv('WATCH_DB');
    if (!$path) {
        // Keep the database outside the website: uploading index.php is enough.
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).'/vmeste-'.substr(hash('sha256', __FILE__), 0, 24);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create private data directory.');
        }
        $path = $directory.'/watch.sqlite';
    }
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $db->exec('CREATE TABLE IF NOT EXISTS rooms (id TEXT PRIMARY KEY, video TEXT, position REAL NOT NULL DEFAULT 0, playing INTEGER NOT NULL DEFAULT 0, updated REAL NOT NULL, version INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS members (room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE, identity TEXT NOT NULL, id TEXT NOT NULL, nick TEXT NOT NULL, seen REAL NOT NULL, last_message REAL NOT NULL DEFAULT 0, last_control REAL NOT NULL DEFAULT 0, PRIMARY KEY(room,identity));
    CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE, member TEXT NOT NULL, nick TEXT NOT NULL, body TEXT NOT NULL, created REAL NOT NULL);
    CREATE INDEX IF NOT EXISTS messages_room ON messages(room,id);
    CREATE TABLE IF NOT EXISTS limits (identity TEXT PRIMARY KEY, created REAL NOT NULL);');
    // Additive migration preserves rooms and chat from the single-file v1.
    $db->exec('BEGIN IMMEDIATE');
    $columns=[
        'rooms'=>['source'=>"TEXT NOT NULL DEFAULT 'youtube'",'title'=>"TEXT NOT NULL DEFAULT ''",'waiting'=>'INTEGER NOT NULL DEFAULT 0','resume'=>'INTEGER NOT NULL DEFAULT 0','chooser'=>'TEXT','media_seq'=>'INTEGER NOT NULL DEFAULT 0'],
        'members'=>['ready'=>'INTEGER NOT NULL DEFAULT 1','voice'=>"TEXT NOT NULL DEFAULT ''",'last_signal'=>'REAL NOT NULL DEFAULT 0','last_extra'=>'REAL NOT NULL DEFAULT 0','last_reaction'=>'REAL NOT NULL DEFAULT 0']
    ];
    foreach ($columns as $table=>$definitions) {
        $existing=array_column($db->query("PRAGMA table_info($table)")->fetchAll(),'name');
        foreach ($definitions as $name=>$definition) if (!in_array($name,$existing,true)) $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
    }
    $db->exec('CREATE TABLE IF NOT EXISTS queue(id INTEGER PRIMARY KEY AUTOINCREMENT,room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,video TEXT NOT NULL,source TEXT NOT NULL,title TEXT NOT NULL,nick TEXT NOT NULL,sort REAL NOT NULL);
    CREATE TABLE IF NOT EXISTS bookmarks(id INTEGER PRIMARY KEY AUTOINCREMENT,room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,video TEXT NOT NULL,source TEXT NOT NULL,title TEXT NOT NULL,position REAL NOT NULL,note TEXT NOT NULL,nick TEXT NOT NULL,created REAL NOT NULL);
    CREATE TABLE IF NOT EXISTS history(id INTEGER PRIMARY KEY AUTOINCREMENT,room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,video TEXT NOT NULL,source TEXT NOT NULL,title TEXT NOT NULL,nick TEXT NOT NULL,created REAL NOT NULL);
    CREATE TABLE IF NOT EXISTS reactions(id INTEGER PRIMARY KEY AUTOINCREMENT,room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,nick TEXT NOT NULL,emoji TEXT NOT NULL,created REAL NOT NULL);
    CREATE TABLE IF NOT EXISTS signals(id INTEGER PRIMARY KEY AUTOINCREMENT,room TEXT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,sender TEXT NOT NULL,target TEXT NOT NULL,payload TEXT NOT NULL,created REAL NOT NULL);
    CREATE INDEX IF NOT EXISTS queue_room ON queue(room,sort,id);
    CREATE INDEX IF NOT EXISTS bookmarks_room ON bookmarks(room,id);
    CREATE INDEX IF NOT EXISTS history_room ON history(room,id);
    CREATE INDEX IF NOT EXISTS reactions_room ON reactions(room,id);
    CREATE INDEX IF NOT EXISTS signals_room ON signals(room,target,id);');
    $db->exec('COMMIT');
    return $db;
}
function query(PDO $db, string $sql, array $params=[]): PDOStatement { $s=$db->prepare($sql); $s->execute($params); return $s; }
function textField(array $data, string $key, int $min, int $max): string {
    $s=$data[$key] ?? null;
    if (!is_string($s) || !mb_check_encoding($s, 'UTF-8')) fail(422, 'Некорректный текст.');
    $pattern = $key === 'body' ? '/(?!\n)[\p{Cc}\p{Cf}]/u' : '/[\p{Cc}\p{Cf}]/u';
    // Preserve message line breaks; reject invisible control characters elsewhere.
    $s=trim(preg_replace($pattern, '', $s));
    if (mb_strlen($s)<$min || mb_strlen($s)>$max) fail(422, "Допустимая длина: $min–$max символов.");
    return $s;
}
function videoId(string $url): ?string {
    if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $url)) return $url;
    $p=parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme']??''), ['http','https'], true)) return null;
    $host=strtolower($p['host']??''); $path=$p['path']??''; $id=null;
    if ($host==='youtu.be') $id=trim($path, '/');
    elseif (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com','www.youtube-nocookie.com','youtube-nocookie.com'], true)) {
        if ($path==='/watch') { parse_str($p['query']??'', $q); $id=$q['v']??null; }
        elseif (preg_match('~^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) $id=$m[1];
    }
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
}

function media(array $d): array {
    $url=textField($d,'url',1,2048);
    $id=videoId($url);
    $source=$id ? 'youtube' : 'file';
    if (!$id) {
        $p=parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || !filter_var($url,FILTER_VALIDATE_URL) || !preg_match('/\.(mp4|webm)$/i',$p['path']??'')) fail(422,'Нужна ссылка YouTube или прямая HTTPS-ссылка на .mp4 / .webm.');
        $id=$url;
    }
    $title=isset($d['title']) && $d['title']!=='' ? textField($d,'title',1,120) : ($source==='youtube' ? 'YouTube · '.$id : basename(parse_url($url,PHP_URL_PATH)));
    return ['video'=>$id,'source'=>$source,'title'=>$title];
}
function position(array $s, float $now): float { return (float)$s['position']+($s['playing'] ? max(0,$now-(float)$s['updated']) : 0); }
function loadMedia(PDO $db, string $room, array $m, float $now, string $nick, float $position=0, bool $play=false): void {
    query($db,'UPDATE rooms SET video=?,source=?,title=?,position=?,playing=?,updated=?,version=version+1,media_seq=media_seq+1 WHERE id=?',[$m['video'],$m['source'],$m['title'],$position,(int)$play,$now,$room]);
    query($db,'INSERT INTO history(room,video,source,title,nick,created) VALUES(?,?,?,?,?,?)',[$room,$m['video'],$m['source'],$m['title'],$nick,$now]);
    query($db,'DELETE FROM history WHERE room=? AND id NOT IN (SELECT id FROM history WHERE room=? ORDER BY id DESC LIMIT 100)',[$room,$room]);
}
function chooser(array $state, array $me): void {
    if ($state['chooser'] && $state['chooser']!==$me['id']) fail(403,'Сейчас видео выбирает другой участник.');
}
function iceServers(): array {
    // Optional: define VMESTE_ICE_SERVERS here for your own TURN service.
    // TURN credentials are visible to room participants, as required by WebRTC.
    $value=getenv('WATCH_ICE_SERVERS');
    $servers=$value ? json_decode($value,true) : VMESTE_ICE_SERVERS;
    return is_array($servers) && array_is_list($servers) ? $servers : [];
}

function integerField(array $d,string $key,?int $default=null): int {
    $value=$d[$key]??$default;
    if (!is_int($value) || $value<0) fail(422,'Некорректный идентификатор.');
    return $value;
}
function numberField(array $d,string $key): float {
    $v=$d[$key]??null;
    if (!is_numeric($v) || !is_finite((float)$v) || $v<0 || $v>604800) fail(422,'Некорректная позиция.');
    return (float)$v;
}

if ($apiRequest) {
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail(405, 'Используйте POST.');
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>32768) fail(413, 'Слишком большой запрос.');
    if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail(403, 'Обновите страницу: сессия изменилась.');
    $input=file_get_contents('php://input', false, null, 0, 32769);
    if (strlen($input)>32768) fail(413, 'Слишком большой запрос.');
    $d=json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($d)) fail(422, 'Некорректный запрос.');
    $action=$d['action']??'';
    if (!is_string($action)) fail(422, 'Некорректное действие.');
    $db=database();
    // Serialize all room writes, including state snapshots, across PHP workers.
    $db->exec('BEGIN IMMEDIATE');
    $now=microtime(true);
    if ($action==='create') {
        $nick=textField($d,'nick',1,24);
        $last=query($db,'SELECT created FROM limits WHERE identity=?',[$identity])->fetchColumn();
        if ($last!==false && $now-(float)$last<10) fail(429,'Подождите немного перед созданием нового зала.');
        $room=bin2hex(random_bytes(16));
        query($db,'INSERT INTO rooms(id,updated) VALUES(?,?)',[$room,$now]);
        query($db,'INSERT OR REPLACE INTO limits VALUES(?,?)',[$identity,$now]);
        query($db,'INSERT INTO members(room,identity,id,nick,seen) VALUES(?,?,?,?,?)',[$room,$identity,bin2hex(random_bytes(8)),$nick,$now]);
        // Bound abandoned room storage. Active rooms are retained.
        query($db,'DELETE FROM rooms WHERE updated < ? AND NOT EXISTS (SELECT 1 FROM members WHERE members.room=rooms.id AND seen>?)',[$now-604800,$now-604800]);
        query($db,'DELETE FROM limits WHERE created<?',[$now-604800]);
    } else {
        $room=$d['room']??'';
        if (!is_string($room) || !preg_match('/^[a-f0-9]{32}$/D',$room)) fail(404,'Зал не найден.');
        if (!query($db,'SELECT id FROM rooms WHERE id=?',[$room])->fetch()) fail(404,'Зал не найден или удалён после недели без активности.');
        if ($action==='join') {
            $nick=textField($d,'nick',1,24);
            $count=query($db,'SELECT COUNT(*) FROM members WHERE room=? AND seen>? AND identity<>?',[$room,$now-20,$identity])->fetchColumn();
            if ($count>=10) fail(409,'В зале уже 10 участников.');
            foreach (query($db,'SELECT nick FROM members WHERE room=? AND identity<>? AND seen>?',[$room,$identity,$now-20])->fetchAll() as $other) {
                if (mb_strtolower($other['nick'])===mb_strtolower($nick)) fail(409,'Этот ник уже занят.');
            }
            query($db,'INSERT INTO members(room,identity,id,nick,seen) VALUES(?,?,?,?,?) ON CONFLICT(room,identity) DO UPDATE SET nick=excluded.nick,seen=excluded.seen',[$room,$identity,bin2hex(random_bytes(8)),$nick,$now]);
        }
    }
    if ($action==='join' && query($db,'SELECT waiting FROM rooms WHERE id=?',[$room])->fetchColumn()) query($db,'UPDATE members SET ready=0 WHERE room=? AND identity=?',[$room,$identity]);
    $me=query($db,'SELECT * FROM members WHERE room=? AND identity=?',[$room,$identity])->fetch();
    if (!$me) fail(401,'Сначала войдите в зал.');
    if ($action==='leave') {
        query($db,'DELETE FROM members WHERE room=? AND identity=?',[$room,$identity]);
        $db->exec('COMMIT'); echo '{"ok":true}'; exit;
    }
    if (!in_array($action,['create','join','state','message','control','queue','ready','reaction','bookmark','voice','signal'],true)) fail(422,'Неизвестное действие.');
    query($db,'UPDATE members SET seen=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    $state=query($db,'SELECT * FROM rooms WHERE id=?',[$room])->fetch();
    if ($action==='message') {
        $body=textField($d,'body',1,1000);
        if ($now-(float)$me['last_message']<0.6) fail(429,'Сообщения отправляются слишком часто.');
        query($db,'INSERT INTO messages(room,member,nick,body,created) VALUES(?,?,?,?,?)',[$room,$me['id'],$me['nick'],$body,$now]);
        query($db,'UPDATE members SET last_message=? WHERE room=? AND identity=?',[$now,$room,$identity]);
        query($db,'DELETE FROM messages WHERE room=? AND id NOT IN (SELECT id FROM messages WHERE room=? ORDER BY id DESC LIMIT 300)',[$room,$room]);
    }
    if ($action==='queue') {
        $op=$d['op']??'';
        if ($now-(float)$me['last_extra']<0.2) fail(429,'Подождите немного.');
        if ($op==='add') {
            $m=media($d);
            if (query($db,'SELECT COUNT(*) FROM queue WHERE room=?',[$room])->fetchColumn()>=50) fail(422,'В очереди уже 50 видео.');
            $sort=(float)query($db,'SELECT COALESCE(MAX(sort),0)+1 FROM queue WHERE room=?',[$room])->fetchColumn();
            query($db,'INSERT INTO queue(room,video,source,title,nick,sort) VALUES(?,?,?,?,?,?)',[$room,$m['video'],$m['source'],$m['title'],$me['nick'],$sort]);
        } elseif ($op==='remove') query($db,'DELETE FROM queue WHERE room=? AND id=?',[$room,integerField($d,'id')]);
        elseif ($op==='shuffle') {
            chooser($state,$me);
            $items=query($db,'SELECT id FROM queue WHERE room=? ORDER BY sort,id',[$room])->fetchAll(); shuffle($items);
            foreach ($items as $i=>$item) query($db,'UPDATE queue SET sort=? WHERE id=?',[$i,$item['id']]);
        } elseif (in_array($op,['up','down'],true)) {
            $items=query($db,'SELECT id FROM queue WHERE room=? ORDER BY sort,id',[$room])->fetchAll();
            $ids=array_column($items,'id'); $index=array_search(integerField($d,'id'),array_map('intval',$ids),true);
            if ($index===false) fail(404,'Видео уже убрали из очереди.');
            $other=$index+($op==='up'?-1:1);
            if (isset($ids[$other])) [$ids[$index],$ids[$other]]=[$ids[$other],$ids[$index]];
            foreach ($ids as $i=>$id) query($db,'UPDATE queue SET sort=? WHERE id=?',[$i,$id]);
        } else fail(422,'Неизвестное действие очереди.');
        query($db,'UPDATE members SET last_extra=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    }
    if ($action==='ready') {
        if ($state['waiting']) {
            query($db,'UPDATE members SET ready=1 WHERE room=? AND identity=?',[$room,$identity]);
            $active=query($db,'SELECT ready FROM members WHERE room=? AND seen>?',[$room,$now-20])->fetchAll();
            if (count($active)>=2 && !in_array(0,array_map('intval',array_column($active,'ready')),true)) {
                query($db,'UPDATE rooms SET waiting=0,playing=resume,updated=?,version=version+1 WHERE id=?',[$now,$room]);
            }
        }
    }
    if ($action==='reaction') {
        $emoji=$d['emoji']??'';
        if (!in_array($emoji,['❤️','😂','😳','🥹','🔥','🍿'],true)) fail(422,'Неизвестная реакция.');
        if ($now-(float)$me['last_reaction']<0.5) fail(429,'Дайте реакции долететь :)');
        query($db,'INSERT INTO reactions(room,nick,emoji,created) VALUES(?,?,?,?)',[$room,$me['nick'],$emoji,$now]);
        query($db,'DELETE FROM reactions WHERE created<?',[$now-8]);
        query($db,'UPDATE members SET last_reaction=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    }
    if ($action==='bookmark') {
        if (($d['op']??'add')==='remove') query($db,'DELETE FROM bookmarks WHERE room=? AND id=?',[$room,integerField($d,'id')]);
        else {
            if (!$state['video']) fail(422,'Сначала выберите видео.');
            if ($now-(float)$me['last_extra']<0.2) fail(429,'Подождите немного.');
            $note=textField($d,'note',1,160);
            query($db,'INSERT INTO bookmarks(room,video,source,title,position,note,nick,created) VALUES(?,?,?,?,?,?,?,?)',[$room,$state['video'],$state['source'],$state['title'],position($state,$now),$note,$me['nick'],$now]);
            query($db,'DELETE FROM bookmarks WHERE room=? AND id NOT IN (SELECT id FROM bookmarks WHERE room=? ORDER BY id DESC LIMIT 100)',[$room,$room]);
            query($db,'UPDATE members SET last_extra=? WHERE room=? AND identity=?',[$now,$room,$identity]);
        }
    }
    if ($action==='voice') {
        $op=$d['op']??'';
        $peer=$d['peer']??'';
        if (!is_string($peer) || !preg_match('/^[a-f0-9]{32}$/D',$peer)) fail(422,'Некорректная голосовая сессия.');
        if ($op==='join') {
            $count=query($db,"SELECT COUNT(*) FROM members WHERE room=? AND identity<>? AND voice<>'' AND seen>?",[$room,$identity,$now-20])->fetchColumn();
            if ($count>=2) fail(409,'Голосовой разговор рассчитан на двоих.');
            query($db,'UPDATE members SET voice=? WHERE room=? AND identity=?',[$peer,$room,$identity]);
        } elseif ($op==='leave') query($db,"UPDATE members SET voice='' WHERE room=? AND identity=? AND voice=?",[$room,$identity,$peer]);
        else fail(422,'Неизвестное голосовое действие.');
    }
    if ($action==='signal') {
        if (($d['peer']??null)!==$me['voice'] || !$me['voice']) fail(403,'Голосовая сессия завершена.');
        if ($now-(float)$me['last_signal']<0.01) fail(429,'Слишком много сигналов.');
        $target=$d['target']??null;
        if (!is_string($target) || $target===$me['voice'] || !query($db,'SELECT 1 FROM members WHERE room=? AND voice=? AND seen>?',[$room,$target,$now-20])->fetchColumn()) fail(404,'Собеседник отключился.');
        $payload=$d['payload']??null;
        if (!is_array($payload)) fail(422,'Некорректный сигнал.');
        $type=$payload['type']??'';
        if (in_array($type,['offer','answer'],true)) {
            if (!is_string($payload['sdp']??null) || strlen($payload['sdp'])>16000) fail(422,'Некорректный SDP.');
            $payload=['type'=>$type,'sdp'=>$payload['sdp']];
        } elseif ($type==='candidate') {
            $candidate=$payload['candidate']??null;
            if (!is_array($candidate) || !is_string($candidate['candidate']??null) || strlen($candidate['candidate'])>4096 || (isset($candidate['sdpMid']) && (!is_string($candidate['sdpMid']) || strlen($candidate['sdpMid'])>32)) || (isset($candidate['sdpMLineIndex']) && (!is_int($candidate['sdpMLineIndex']) || $candidate['sdpMLineIndex']<0 || $candidate['sdpMLineIndex']>32))) fail(422,'Некорректный ICE-кандидат.');
            $payload=['type'=>'candidate','candidate'=>array_intersect_key($candidate,array_flip(['candidate','sdpMid','sdpMLineIndex']))];
        } else fail(422,'Неизвестный сигнал.');
        query($db,'INSERT INTO signals(room,sender,target,payload,created) VALUES(?,?,?,?,?)',[$room,$me['voice'],$target,json_encode($payload,JSON_THROW_ON_ERROR),$now]);
        query($db,'DELETE FROM signals WHERE created<?',[$now-60]);
        query($db,'UPDATE members SET last_signal=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    }
    if ($action==='control') {
        if ($now-(float)$me['last_control']<0.15) fail(429,'Слишком частые команды плееру.');
        if (!isset($d['version']) || $d['version']!==(int)$state['version']) fail(409,'Состояние уже изменилось. Повторите действие.');
        $op=$d['op']??'';
        if ($state['waiting'] && !in_array($op,['pause','pass','wait_cancel'],true)) fail(409,'Сначала подтвердите готовность обоих участников.');
        if (in_array($op,['load','queue_play','next','random','history','bookmark'],true)) chooser($state,$me);
        $position=position($state,$now); $playing=(int)$state['playing']; $loaded=false;
        if ($op==='load') { loadMedia($db,$room,media($d),$now,$me['nick']); $loaded=true; }
        elseif (in_array($op,['queue_play','next','random','ended'],true)) {
            if ($op==='ended') {
                $duration=numberField($d,'duration');
                if (!$state['playing'] || $state['waiting'] || $position<$duration-1 || $duration<1) fail(409,'Видео ещё не закончилось.');
                $position=$duration;
            }
            $sql='SELECT * FROM queue WHERE room=?'; $params=[$room];
            if ($op==='queue_play') { $sql.=' AND id=?'; $params[]=integerField($d,'id'); }
            $sql.=$op==='random' ? ' ORDER BY RANDOM() LIMIT 1' : ' ORDER BY sort,id LIMIT 1';
            $next=query($db,$sql,$params)->fetch();
            if (!$next && $op!=='ended') fail(404,'Очередь пуста или видео уже выбрано.');
            if ($next) { loadMedia($db,$room,$next,$now,$me['nick'],0,$op==='ended'); query($db,'DELETE FROM queue WHERE id=?',[$next['id']]); $loaded=true; }
            else $playing=0;
        } elseif (in_array($op,['history','bookmark'],true)) {
            $table=$op==='history' ? 'history' : 'bookmarks';
            $item=query($db,"SELECT * FROM $table WHERE room=? AND id=?",[$room,integerField($d,'id')])->fetch();
            if (!$item) fail(404,'Запись не найдена.');
            loadMedia($db,$room,$item,$now,$me['nick'],(float)($item['position']??0)); $loaded=true;
        } elseif ($op==='pass') {
            $target=$d['target']??null;
            if ($target!==null && (!is_string($target) || !query($db,'SELECT 1 FROM members WHERE room=? AND id=? AND seen>?',[$room,$target,$now-20])->fetchColumn())) fail(404,'Участник не в сети.');
            // Either participant may restore shared choice if a chooser went away.
            if ($state['chooser'] && $state['chooser']!==$me['id'] && query($db,'SELECT 1 FROM members WHERE room=? AND id=? AND seen>?',[$room,$state['chooser'],$now-20])->fetchColumn()) fail(403,'Выбор может передать только тот, чья сейчас очередь.');
            query($db,'UPDATE rooms SET chooser=? WHERE id=?',[$target,$room]);
        } elseif ($op==='wait_cancel') {
            query($db,'UPDATE rooms SET waiting=0,resume=0 WHERE id=?',[$room]);
            query($db,'UPDATE members SET ready=1 WHERE room=?',[$room]); $playing=0;
        } elseif (!$state['video']) fail(422,'Сначала добавьте видео.');
        elseif ($op==='play') $playing=1;
        elseif ($op==='replay') { $position=0; $playing=1; }
        elseif ($op==='pause') $playing=0;
        elseif ($op==='seek') $position=numberField($d,'position');
        elseif ($op==='wait') {
            query($db,'UPDATE rooms SET waiting=1,resume=? WHERE id=?',[$playing,$room]);
            query($db,'UPDATE members SET ready=0 WHERE room=?',[$room]); $playing=0;
        } else fail(422,'Неизвестная команда плееру.');
        if (!$loaded) query($db,'UPDATE rooms SET position=?,playing=?,updated=?,version=version+1 WHERE id=?',[$position,$playing,$now,$room]);
        query($db,'UPDATE members SET last_control=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    }
    $after=filter_var($d['after']??0,FILTER_VALIDATE_INT);
    if ($after===false || $after<0) fail(422,'Некорректный курсор сообщений.');
    $messages=query($db,'SELECT id,member,nick,body,created FROM (SELECT id,member,nick,body,created FROM messages WHERE room=? AND id>? ORDER BY id DESC LIMIT 100) ORDER BY id',[$room,$after])->fetchAll();
    $state=query($db,'SELECT * FROM rooms WHERE id=?',[$room])->fetch();
    $me=query($db,'SELECT id,nick,ready,voice FROM members WHERE room=? AND identity=?',[$room,$identity])->fetch();
    $members=query($db,'SELECT id,nick,ready,voice FROM members WHERE room=? AND seen>? ORDER BY nick',[$room,$now-20])->fetchAll();
    $queue=query($db,'SELECT id,video,source,title,nick FROM queue WHERE room=? ORDER BY sort,id',[$room])->fetchAll();
    $bookmarks=query($db,'SELECT id,video,source,title,position,note,nick,created FROM bookmarks WHERE room=? ORDER BY id DESC LIMIT 100',[$room])->fetchAll();
    $history=query($db,'SELECT id,video,source,title,nick,created FROM history WHERE room=? ORDER BY id DESC LIMIT 100',[$room])->fetchAll();
    $reactions=query($db,'SELECT id,emoji,nick,created FROM reactions WHERE room=? AND created>? ORDER BY id',[$room,$now-5])->fetchAll();
    $signalAfter=integerField($d,'signalAfter',0);
    $signals=$me['voice'] ? query($db,'SELECT id,sender,payload FROM signals WHERE room=? AND target=? AND id>? AND created>? ORDER BY id LIMIT 100',[$room,$me['voice'],$signalAfter,$now-60])->fetchAll() : [];
    foreach ($signals as &$signal) $signal['payload']=json_decode($signal['payload'],true); unset($signal);
    $db->exec('COMMIT');
    foreach (['version','media_seq'] as $key) $state[$key]=(int)$state[$key];
    foreach (['position','updated'] as $key) $state[$key]=(float)$state[$key];
    foreach (['playing','waiting'] as $key) $state[$key]=(bool)$state[$key];
    unset($state['id'],$state['resume']);
    echo json_encode(['room'=>$room,'me'=>$me,'state'=>$state,'members'=>$members,'messages'=>$messages,'queue'=>$queue,'bookmarks'=>$bookmarks,'history'=>$history,'reactions'=>$reactions,'signals'=>$signals,'iceServers'=>iceServers(),'serverTime'=>$now],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (JsonException $e) { fail(422,'Некорректный JSON.'); }
catch (Throwable $e) { error_log((string)$e); fail(503,'Сервис временно недоступен. Попробуйте ещё раз.'); }

exit;
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if (!in_array($path, [$base.'/', $base.'/index.php'], true)) {
    http_response_code(404);
    exit('Страница не найдена.');
}
$nonce = bin2hex(random_bytes(16));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://www.youtube.com https://s.ytimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com; img-src 'self' https://i.ytimg.com data:; style-src 'self' 'nonce-$nonce'; media-src 'self' https: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?=htmlspecialchars($csrf,ENT_QUOTES)?>"><meta name="theme-color" content="#111015"><title>Вместе — ваш маленький кинозал</title><style nonce="<?=htmlspecialchars($nonce,ENT_QUOTES)?>">
:root{color-scheme:dark;--bg:#111015;--panel:#1a191f;--line:#302e37;--muted:#93909e;--text:#f4f0f0;--accent:#c2a5f5;--peach:#edbea7}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,textarea{font:inherit}button,a,input,textarea{ -webkit-tap-highlight-color:transparent}button{cursor:pointer}button:disabled{opacity:.4;cursor:not-allowed}a{color:inherit;text-decoration:none}button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:4px}input:focus,textarea:focus{outline:none;border-color:var(--accent)!important}button{transition:background .2s,transform .2s}button:hover:enabled{filter:brightness(1.1)}[hidden]{display:none!important}.header{height:104px;border-bottom:1px solid #24222b;display:flex;align-items:center;justify-content:space-between;margin:0 5%;gap:24px}.brand{font-size:29px;font-weight:800;letter-spacing:-1.5px;display:flex;align-items:center;gap:11px}.brand-icon{font-size:15px;display:grid;place-items:center;width:35px;height:35px;background:var(--accent);color:#19131f;border-radius:11px;transform:rotate(-7deg)}.brand-dot{color:var(--accent);margin-left:-10px}.header-note{font-size:12px;color:var(--muted)}.header-tag{font-size:9px;letter-spacing:1.6px;color:#bdb8c7;border:1px solid var(--line);padding:8px 12px;border-radius:30px;white-space:nowrap}.header-tag i,.connection:before{display:inline-block;background:#b7d1a3;width:6px;height:6px;border-radius:50%;margin-right:8px;content:''}main{max-width:1440px;margin:auto;padding:0 5%}.lobby{display:grid;grid-template-columns:1fr 1fr;align-items:center;min-height:calc(100vh - 187px);gap:4%;padding:70px 0 65px}.eyebrow{font-size:9px;font-weight:600;letter-spacing:2px;color:var(--accent)}.intro h1{font-size:clamp(44px,4.6vw,68px);line-height:1.12;font-weight:550;letter-spacing:-3px;margin:25px 0 24px}.intro h1 em{color:var(--peach);font-family:Georgia,serif;font-weight:400}.intro>p{color:var(--muted);font-size:15px;line-height:1.8;margin-bottom:30px}.join-card{max-width:360px}.join-card label{display:block;font-size:12px;margin-bottom:9px}.name-input{display:flex;border:1px solid #3a3643;border-radius:9px;background:#1c1a22;padding:0 15px;align-items:center;gap:12px}.name-input:focus-within{border-color:var(--accent)}.name-input>span{font-size:23px;color:var(--muted)}input{color:var(--text);background:transparent;border:0;min-width:0}.name-input input{height:48px;width:100%;outline:0}.primary{border:0;background:var(--accent);color:#20172d;font-size:13px;font-weight:650;border-radius:8px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px}.join-card .primary{width:100%;margin-top:12px}.primary span{font-size:20px;line-height:1}.join-card small{display:block;font-size:10px;color:var(--muted);margin-top:11px}.intro-perks{display:flex;flex-wrap:wrap;gap:20px;font-size:10px;color:#aaa5b3;margin-top:33px}.illustration{height:490px;position:relative;display:flex;align-items:center;justify-content:center}.orbit{position:absolute;border:1px solid #292431;border-radius:50%;width:490px;height:490px;max-width:110%}.orbit-two{width:380px;height:380px;border-style:dashed;opacity:.7}.floating-note{position:absolute;top:21px;font:italic 15px Georgia,serif;color:#b5a6c5;transform:rotate(-7deg)}.cinema{width:94%;max-width:490px;border:1px solid #4a414f;border-radius:13px;background:#211d27;transform:rotate(-4deg);overflow:hidden;box-shadow:0 25px 90px #0008;z-index:1}.cinema-bar{padding:12px 17px;display:flex;justify-content:space-between;font-size:9px;color:#8c8097}.cinema-bar>span:first-child{letter-spacing:4px;color:#6f5b78}.cinema-screen{height:235px;position:relative;overflow:hidden;background:linear-gradient(#7c667c,#b88e8a 70%,#d4aa8b)}.sun{position:absolute;width:95px;height:95px;border-radius:50%;background:#efc9a7;left:52%;top:30px;box-shadow:0 0 90px #e8b4a366}.hill{position:absolute;width:120%;height:200px;border-radius:50% 50% 0 0;bottom:-115px;left:-10%;background:#4a505a;transform:rotate(-15deg)}.hill-back{bottom:-88px;left:15%;background:#70616b;transform:rotate(17deg)}.hill-front{background:#303c45}.big-play{position:absolute;left:calc(50% - 25px);top:90px;background:#fff2;border:1px solid #fff5;width:50px;height:50px;display:grid;place-items:center;border-radius:50%;font-size:18px;padding-left:4px}.screen-caption{position:absolute;bottom:22px;width:100%;text-align:center;font:italic 17px Georgia,serif;letter-spacing:1px;color:#efdfd1;z-index:1}.cinema-bottom{display:flex;align-items:center;gap:13px;padding:14px;color:#c8b8d1}.cinema-bottom small{font-size:8px;white-space:nowrap}.fake-progress{height:3px;background:#443c4e;flex:1;border-radius:3px}.fake-progress i{display:block;background:var(--accent);width:42%;height:3px}.bubble{position:absolute;z-index:2;display:flex;align-items:center;gap:10px;background:#29232f;border:1px solid #4a3e50;padding:11px 17px 11px 10px;border-radius:13px;font-size:12px;box-shadow:0 10px 30px #0004}.bubble-one{right:-12px;bottom:105px;transform:rotate(5deg)}.bubble-two{left:0;bottom:42px;transform:rotate(-3deg)}.avatar{display:inline-grid;place-items:center;border-radius:50%;width:30px;height:30px;font-size:11px;color:#201927;flex-shrink:0}.peach{background:var(--peach)}.lilac{background:var(--accent)}.together-badge{position:absolute;right:8px;bottom:-6px;font-size:9px;letter-spacing:1px;color:#b1a5b7}.together-badge span{color:var(--peach);font-size:17px;margin-right:6px}footer{max-width:calc(100% - 10%);margin:auto;border-top:1px solid #28242e;display:flex;justify-content:space-between;align-items:center;gap:15px;padding:24px 0;color:#6f6b79;font-size:10px}footer>span:first-child{font-size:12px;color:#b5acbc}.heart{color:var(--peach);margin-left:6px}footer>span:last-child{letter-spacing:1.5px;font-size:8px}.room{padding:36px 0 45px;min-height:calc(100vh - 187px)}.room-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px;gap:15px}.room-heading h1{font-size:28px;font-weight:550;letter-spacing:-1px;margin:5px 0 0}.room-heading h1 span{color:var(--accent)}.room-actions{display:flex;align-items:center;gap:12px}.connection{font-size:10px;color:#b7d1a3}.connection.offline{color:var(--peach)}.connection.offline:before{background:var(--peach)}.outline,.icon-button{border:1px solid var(--line);border-radius:7px;background:transparent;color:#d0c6dc;padding:9px 15px;font-size:12px}.icon-button{font-size:17px;padding:5px 12px}.room-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px}.video-shell{aspect-ratio:16/9;position:relative;background:#17151e;border:1px solid #332c40;border-radius:11px 11px 0 0;overflow:hidden}.video-shell iframe,#player{width:100%;height:100%;position:absolute;inset:0}.empty-player{position:absolute;inset:0;background:radial-gradient(ellipse at 50% 90%,#2d223b,#18151e 70%);display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;pointer-events:none}.empty-symbol{display:grid;place-items:center;width:62px;height:62px;border-radius:19px;background:#362840;border:1px solid #5a456b;color:var(--accent);font-size:24px;padding-left:4px;margin-bottom:23px;transform:rotate(-7deg)}.empty-player h2{font-size:24px;letter-spacing:-.7px;font-weight:500;margin:11px 0 5px}.empty-player p{font-size:12px;color:var(--muted);line-height:1.9}.player-controls{display:flex;align-items:center;gap:12px;padding:12px 15px;background:#201b29;border:1px solid #332c40;border-top:0;border-radius:0 0 11px 11px;font-size:10px;color:#aaa0b7}.play-button{color:var(--accent);background:transparent;border:0;font-size:18px;width:28px;padding:0}.player-controls input{flex:1;width:30px;accent-color:var(--accent);height:4px}.sound-button{background:transparent;border:1px solid #4b3e5c;border-radius:5px;font-size:10px;color:var(--accent);padding:5px 8px;white-space:nowrap}.player-status{color:var(--muted);font-size:10px;min-height:17px;margin:9px 0 24px}.video-form{background:#1c1922;border:1px solid var(--line);padding:19px;border-radius:11px}.video-form label{font-size:12px;display:block;margin-bottom:10px}.video-form>div{display:flex;align-items:center;border:1px solid #41374e;border-radius:7px;padding:4px 4px 4px 14px;gap:12px;background:#141118}.youtube-icon{background:#79608b;font-size:8px;border-radius:4px;padding:1px 5px;color:#e4d6eb}.video-form input{flex:1;width:40px;font-size:12px}.video-form .primary{padding:9px 14px;font-size:11px}.room-tip{display:flex;align-items:center;gap:15px;padding:24px 4px;color:#887d94}.room-tip>span:first-child{font-size:25px;color:var(--accent)}.room-tip p{font-size:10px;margin:0;line-height:1.9}.room-tip b{color:#b7abbf;font-weight:500}.tip-heart{font:32px Georgia,serif;margin-left:auto;color:#7b5e7c}.chat-panel{background:#1b1820;border:1px solid var(--line);border-radius:11px;display:flex;flex-direction:column;overflow:hidden;min-height:520px;max-height:730px;height:100%}.chat-header{padding:19px 18px 14px;display:flex;align-items:center;justify-content:space-between}.chat-header h2{font-size:16px;font-weight:550;margin:0}.chat-header h2 span{color:var(--peach);font-size:13px;margin-left:5px}.chat-header>span{font-size:10px;color:var(--muted)}.members{padding:0 18px 14px;border-bottom:1px solid var(--line);display:flex;gap:7px;flex-wrap:wrap}.member{font-size:10px;padding:3px 9px;background:#292330;border-radius:20px;color:#cdb9e1}.member:before{content:'';display:inline-block;border-radius:50%;width:5px;height:5px;background:#b7d1a3;margin-right:6px}.messages{padding:18px;overflow-y:auto;flex:1;min-height:260px;scrollbar-width:thin;scrollbar-color:#4a3a55 transparent}.chat-welcome{text-align:center;padding:60px 0 10px;color:var(--muted)}.chat-welcome>span{font-size:32px;filter:grayscale(.8)}.chat-welcome h3{font-size:12px;font-weight:500;color:#cdc1d6}.chat-welcome p{font-size:11px}.message{margin-bottom:18px;overflow-wrap:anywhere}.message-meta{display:flex;align-items:center;gap:7px;margin-bottom:4px;font-size:10px;color:var(--accent)}.message-meta time{margin-left:auto;font-size:9px;color:#706779}.message.mine .message-meta{color:var(--peach)}.message-body{font-size:12px;white-space:pre-wrap;color:#ddd2e3;line-height:1.7;background:#25202c;padding:9px 11px;border-radius:0 9px 9px 9px}.message.mine .message-body{background:#312638}.chat-form{display:flex;align-items:center;gap:8px;border:1px solid #44384e;border-radius:8px;margin:12px 13px 0;padding:7px 8px 7px 12px}.chat-form textarea{resize:none;max-height:100px;min-height:28px;width:100%;background:transparent;border:0;color:var(--text);font-size:11px;padding-top:5px}.chat-form button{width:30px;height:30px;border:0;border-radius:6px;background:var(--accent);color:#30203e;flex-shrink:0;font-size:21px}.chat-hint{text-align:center;font-size:8px;color:#79707f;padding:8px 0 14px}#toast{position:fixed;bottom:25px;left:50%;transform:translateX(-50%);background:#3c2b45;border:1px solid #8e6ea7;color:#f5e8ff;padding:13px 22px;border-radius:10px;max-width:90%;font-size:13px;z-index:20}dialog{color:var(--text);background:#201a29;border:1px solid #594369;border-radius:16px;padding:34px;max-width:440px;width:90%}dialog::backdrop{background:#09060dc9;backdrop-filter:blur(5px)}dialog h2{font-size:24px;line-height:1.3;margin:20px 0 10px}dialog p{font-size:12px;color:#b4a6c0}dialog input{width:100%;border:1px solid #594369;border-radius:6px;padding:12px;font-size:12px;margin:12px 0}dialog .primary{width:100%}.dialog-close{position:absolute;right:16px;top:12px;color:#c0a6cf;background:transparent;border:0;font-size:25px}#copy-status{font-size:11px;color:var(--accent);display:block;margin-top:10px}@media(min-width:1550px){.header,footer{max-width:1296px;margin:auto}.intro h1{font-size:68px}}@media(max-width:1050px){.header-note{display:none}.lobby{gap:20px}.illustration{height:430px}.cinema-screen{height:190px}.bubble-one{right:0;bottom:85px}.room-grid{grid-template-columns:minmax(0,1fr) 280px;gap:16px}.room-actions{gap:8px}.connection{max-width:100px}.sound-button{max-width:95px;white-space:normal;line-height:1.3}.video-form>div{gap:8px}.video-form .primary{padding:9px}.video-form .primary span{display:none}}@media(max-width:760px){.header{height:80px}.header-tag{font-size:7px;letter-spacing:1px;padding:6px 9px}.brand{font-size:25px}.lobby{grid-template-columns:1fr;padding:42px 0;gap:10px}.intro h1{font-size:54px}.intro>p{font-size:14px}.intro .eyebrow{font-size:8px}.join-card{max-width:100%}.intro-perks{justify-content:space-between;gap:8px}.illustration{height:390px;max-width:500px;width:100%;margin:30px auto 15px}.orbit{width:340px;height:340px}.orbit-two{width:270px;height:270px}.cinema{max-width:390px;width:90%}.cinema-screen{height:195px}.bubble{font-size:10px}.bubble-one{bottom:76px;right:0}.bubble-two{bottom:22px}.together-badge{bottom:-15px}.floating-note{top:0}.room{padding-top:25px}.room-heading{align-items:flex-start;flex-direction:column;gap:15px}.room-heading h1{font-size:26px}.room-actions{width:100%}.room-actions .connection{margin-right:auto;max-width:none}.room-grid{grid-template-columns:1fr}.chat-panel{height:480px;min-height:480px;max-height:480px}.empty-player h2{font-size:20px}.empty-symbol{width:42px;height:42px;margin-bottom:12px;font-size:19px;border-radius:12px}.empty-player .eyebrow{font-size:7px}.empty-player p{font-size:10px;margin:4px}.player-controls{gap:8px;padding:10px}.sound-button{max-width:95px;font-size:9px}.video-form{padding:14px}.video-form input{font-size:11px}.video-form .primary{font-size:10px}.room-tip{padding:20px 2px}footer{flex-wrap:wrap;font-size:9px;gap:10px}footer>span:nth-child(2){order:3;width:100%;font-size:9px}footer>span:last-child{margin-left:auto}.desktop{display:none}}@media(prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
/* Draw essential controls locally, without relying on emoji fonts. */
.brand-icon,.empty-symbol,.big-play,.youtube-icon{font-size:0}
.brand-icon:before,.empty-symbol:before,.big-play:before,.youtube-icon:before{content:'';display:block;width:0;height:0;border-top:5px solid transparent;border-bottom:5px solid transparent;border-left:8px solid currentColor}
.empty-symbol:before,.big-play:before{border-top-width:9px;border-bottom-width:9px;border-left-width:14px}.youtube-icon:before{border-top-width:3px;border-bottom-width:3px;border-left-width:5px}.youtube-icon{padding:5px 7px}
.primary>span{font-size:0;width:12px;height:12px;display:block;position:relative}.primary>span:after{content:'';position:absolute;right:1px;top:2px;width:8px;height:8px;border-top:1.5px solid currentColor;border-right:1.5px solid currentColor}.primary>span:before{content:'';position:absolute;width:12px;height:1.5px;background:currentColor;transform:rotate(-45deg);top:6px;left:0}.play-button{font-size:0;display:grid;place-items:center;height:24px}.play-button:before{content:'';border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid currentColor}.play-button.is-playing:before{height:14px;width:10px;border-top:0;border-bottom:0;border-left:3px solid currentColor;border-right:3px solid currentColor}

.evening-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px;padding:18px 20px;border:1px solid var(--line);border-radius:10px;background:linear-gradient(100deg,#292033,#19161f)}.evening-toolbar>div:first-child{min-width:0}.evening-toolbar strong{display:block;font-size:16px;font-weight:500;overflow-wrap:anywhere}.evening-toolbar #chooser-label{font-size:10px;color:var(--muted)}.tool-buttons,.voice-buttons{display:flex;gap:8px;flex-wrap:wrap}.tool-buttons button{font-size:11px}.waiting-banner{display:flex;flex-wrap:wrap;align-items:center;gap:15px;background:#3c2c2a;border:1px solid #8d6b56;border-radius:10px;padding:16px 20px;margin-bottom:20px}.waiting-banner b{font-size:13px}.waiting-banner span{font-size:11px;color:var(--peach);flex:1}.waiting-banner .primary{padding:9px 16px;font-size:11px}.video-shell video{width:100%;height:100%;object-fit:contain;background:#08070c;position:absolute;inset:0}#reactions-stage{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:4}.flying-reaction{position:absolute;bottom:10%;font-size:35px;animation:float-reaction 3s ease-out forwards;text-align:center;left:40%}.flying-reaction:nth-child(3n){left:65%}.flying-reaction:nth-child(2n){left:20%}.flying-reaction small{display:block;font-size:11px;text-shadow:0 1px 4px #000;color:white}.flying-reaction p{margin:0}@keyframes float-reaction{from{transform:translateY(0) rotate(-8deg);opacity:1}to{transform:translateY(-140px) rotate(10deg);opacity:0}}.local-controls{display:flex;align-items:center;gap:12px;font-size:10px;color:var(--muted);padding:0 2px 15px;flex-wrap:wrap}.local-controls input{accent-color:var(--accent);width:100px;height:4px}.local-controls select{max-width:55px}.local-controls button{margin-left:auto;font-size:10px;padding:5px 12px}select{background:#272030;color:#dfd2ea;border:1px solid #4a3b56;border-radius:6px;padding:7px;max-width:100%;font:inherit}.reactions-bar{display:flex;align-items:center;gap:10px;padding:8px 0 20px}.reactions-bar>span{font-size:10px;color:var(--muted);margin-right:4px}.reactions-bar button{border:1px solid #44364c;border-radius:50%;background:#251e2e;width:37px;height:37px;font-size:19px;padding:0}.voice-panel{background:#201a28;border:1px solid #3d3049;border-radius:10px;padding:18px;margin:18px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.voice-panel strong{font-size:12px;font-weight:500}.voice-panel p{font-size:10px;color:var(--muted);margin:5px 0}.voice-panel .voice-note{width:100%;font-size:9px}.voice-buttons button{font-size:10px;padding:7px 11px}#voice-start.active{background:#443052;color:#ecd7fc;border-color:#ae86cd}.choice-panel{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:11px;padding:5px 0 18px}.choice-panel>span{flex:1;min-width:140px;color:var(--muted)}.choice-panel select{max-width:160px;font-size:10px}.choice-panel button{font-size:10px;padding:7px 10px}.evening-panels{display:grid;grid-template-columns:1fr 1fr;gap:16px}.feature-panel{background:#1c1822;border:1px solid var(--line);border-radius:11px;padding:17px;min-width:0}.feature-panel h2{font-size:14px;font-weight:500;margin:0 0 12px}.feature-panel h2>span{color:var(--muted);font-size:11px;margin-left:5px}.panel-heading{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}.small-button{border:0;color:var(--accent);background:transparent;font-size:10px;padding:3px 4px}.feature-list{max-height:260px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#4b3a59 transparent;margin:10px 0}.feature-item{border-top:1px solid #322b3b;padding:10px 0;overflow-wrap:anywhere}.feature-item strong{display:block;font-size:11px;font-weight:500;color:#e1d6e9}.feature-item small{font-size:9px;color:var(--muted);display:block}.item-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}.item-actions button{font-size:10px;color:var(--accent);background:#2c2235;border:1px solid #42304e;border-radius:5px;padding:3px 8px}.feature-panel>.outline{font-size:10px;padding:6px 10px}.empty-note{color:var(--muted);font-size:10px;line-height:1.7}.history-panel{grid-column:1/-1}.history-day{font-size:11px;color:var(--peach);padding:10px 0 0}.history-panel .feature-list{max-height:240px}#bookmark-form{display:flex;gap:8px;flex-wrap:wrap}#bookmark-note{background:#15111a;border:1px solid #44354f;border-radius:6px;padding:9px;font-size:11px;width:100%;flex:1;min-width:110px}#bookmark-form button{font-size:10px;padding:7px 10px}.video-form .title-input{width:100%;font-size:11px;padding:10px 0 0}.video-form .media-help{font-size:9px;color:var(--muted);display:block;margin-top:9px}.video-submit-buttons{display:flex;gap:6px}.video-form .queue-add{background:#382b45;color:#dac2ed}.room-grid{align-items:start}.chat-panel{position:sticky;top:15px;max-height:680px;height:680px}.chat-panel.is-hidden{display:none}.room-grid.chat-closed{grid-template-columns:1fr}.room-grid.chat-closed .watch-column{max-width:100%}body.cinema .header,body.cinema footer{display:none}body.cinema main{max-width:100%;padding:0 2%}body.cinema .room{padding-top:16px}body.cinema .room-heading{margin-bottom:15px}body.cinema .video-shell{max-height:78vh;aspect-ratio:16/9}body.cinema .room-grid{grid-template-columns:minmax(0,1fr) 280px}body.cinema .room-grid.chat-closed{grid-template-columns:1fr}.watch-column:fullscreen{overflow:auto;background:#111015;padding:12px}.watch-column:fullscreen .video-shell{height:75vh;aspect-ratio:auto}.watch-column:fullscreen .evening-panels,.watch-column:fullscreen .room-tip{display:none}.player-controls{flex-wrap:nowrap}.player-controls input{min-width:20px}.video-form>div{flex-wrap:wrap}.video-form #video-url{min-width:100px}.video-submit-buttons .primary{gap:8px}.choice-panel select{width:160px}@media(max-width:1050px){.evening-panels{grid-template-columns:1fr}.history-panel{grid-column:auto}.tool-buttons{gap:5px}.tool-buttons button{padding:7px 9px}.evening-toolbar{align-items:flex-start;flex-direction:column}.video-submit-buttons{width:100%}.video-submit-buttons .primary{flex:1;justify-content:center}.video-form #video-url{padding:7px 0}.choice-panel>span{width:100%;flex-basis:100%}}@media(max-width:760px){.evening-toolbar{padding:14px;margin-bottom:14px}.evening-toolbar strong{font-size:14px}.tool-buttons{width:100%}.tool-buttons button{flex:1;font-size:10px;padding:7px}.waiting-banner{padding:13px;gap:8px}.waiting-banner b{width:100%}.room-heading h1{font-size:24px}.chat-panel{position:static;height:420px;min-height:420px;max-height:420px}.local-controls{gap:8px}.local-controls label{font-size:9px}.local-controls select{font-size:9px}.reactions-bar{gap:8px}.reactions-bar button{width:32px;height:32px;font-size:17px}.reactions-bar>span{font-size:9px}.voice-panel{padding:14px}.voice-buttons{width:100%}.evening-panels{gap:12px}.choice-panel{gap:7px}.feature-panel{padding:14px}body.cinema .room-grid{grid-template-columns:1fr}body.cinema .video-shell{max-height:none}.video-shell{min-height:200px}.watch-column:fullscreen .video-shell{height:60vh}.sound-button{max-width:84px}.video-form .title-input{padding-top:8px}body.cinema .chat-panel{height:350px;min-height:350px}.room-actions{gap:7px}}@media(prefers-reduced-motion:reduce){.flying-reaction{animation:none;position:static;display:inline-block;margin:15px;font-size:28px}}


@media(max-width:760px){.chat-panel{position:fixed;z-index:12;right:12px;left:12px;bottom:12px;top:auto;height:min(70dvh,520px);min-height:280px;max-height:70dvh;box-shadow:0 10px 80px #000b;border-color:#715181}.chat-panel .messages{min-height:100px}.chat-header{padding:13px 16px 10px}.chat-header #member-count{margin-left:auto;margin-right:10px}#chat-close{font-size:24px;line-height:1}body.cinema .chat-panel{height:min(70dvh,520px);min-height:280px}}
</style></head>
<body>
<header class="header"><a href="./" class="brand" aria-label="Вместе, главная"><span class="brand-icon">▶</span> вместе<span class="brand-dot">.</span></a><span class="header-note">Далеко друг от друга. Рядом в моменте.</span><span class="header-tag"><i></i> ВАШ ЛИЧНЫЙ КИНОЗАЛ</span></header>
<main>
<section id="lobby" class="lobby">
<div class="intro"><span class="eyebrow">ХОРОШИЕ ВЕЧЕРА СЛУЧАЮТСЯ ВМЕСТЕ</span><h1>Одно видео.<br>Два экрана.<br><em>Один момент.</em></h1><p>Смотрите YouTube, делитесь мыслями<br class="desktop"> и будьте чуть ближе — где бы вы ни были.</p><form id="join-form" class="join-card"><label for="nickname">Как вас называть?</label><div class="name-input"><span>☺</span><input id="nickname" name="nickname" autocomplete="nickname" maxlength="24" placeholder="Ваш ник" required></div><button class="primary" id="join-button" type="submit">Создать наш зал <span>↗</span></button><small id="join-hint">Без регистрации. Только вы и те, кого пригласите.</small></form><div class="intro-perks"><span>↔ Синхронный просмотр</span><span>♧ Живой чат</span><span>⌁ Без лишнего</span></div></div>
<div class="illustration" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="floating-note">Для ваших «а помнишь этот момент?»</div><div class="cinema"><div class="cinema-bar"><span>● ● ●</span><span>наш вечер / 01</span></div><div class="cinema-screen"><div class="sun"></div><div class="hill hill-back"></div><div class="hill hill-front"></div><div class="screen-caption">somewhere, together.</div><span class="big-play">▶</span></div><div class="cinema-bottom"><span>Ⅱ</span><div class="fake-progress"><i></i></div><small>12:48 / ∞</small><span>↗</span></div></div><div class="bubble bubble-one"><b class="avatar peach">А</b><span>Вот этот момент 🥹</span></div><div class="bubble bubble-two"><b class="avatar lilac">М</b><span>Я здесь. Смотрим ♡</span></div><div class="together-badge"><span>✦</span> расстояние не считается</div></div>
</section>
<section id="room" class="room" hidden>
<div class="room-heading"><div><span class="eyebrow">МЕСТО ДЛЯ ВАШЕГО ВЕЧЕРА</span><h1>Наш маленький зал<span>.</span></h1></div><div class="room-actions"><span id="connection" class="connection" role="status">Подключаемся…</span><button id="invite" class="outline">+ Пригласить</button><button id="leave" class="icon-button" title="Выйти из зала" aria-label="Выйти из зала">⏻</button></div></div>
<div class="evening-toolbar"><div><span class="eyebrow">НАШ ВЕЧЕР</span><strong id="now-title">Выберите видео на вечер</strong><span id="chooser-label">Выбираем вместе</span></div><div class="tool-buttons"><button id="wait-button" class="outline">☕ Жду тебя</button><button id="cinema-mode" class="outline" aria-pressed="false">Режим кино</button><button id="fullscreen" class="outline">На весь экран</button></div></div>
<div id="waiting-banner" class="waiting-banner" role="status" hidden><b>Никуда не спешим. Ждём друг друга ☕</b><span id="ready-list"></span><button id="ready-button" class="primary">Я готов / готова</button><button id="wait-cancel" class="small-button">Отменить ожидание</button></div>
<div class="room-grid"><div class="watch-column"><div class="video-shell"><div id="player"></div><video id="direct-video" playsinline preload="metadata" hidden></video><div id="reactions-stage" aria-live="polite" aria-label="Реакции участников"></div><div id="empty-player" class="empty-player"><div class="empty-symbol">▶</div><span class="eyebrow">ЛУЧШЕ, КОГДА ВМЕСТЕ</span><h2>С чего начнём вечер?</h2><p>Добавьте YouTube, MP4 или WebM ниже.<br>Попкорн — по желанию.</p></div></div><div class="player-controls"><button id="play" class="play-button" disabled aria-label="Воспроизвести для всех">▶</button><span id="position">0:00</span><input type="range" id="seek" min="0" max="100" step="0.1" value="0" aria-label="Позиция видео для всех" disabled><span id="duration">0:00</span><button id="enable-sound" class="sound-button" disabled>Включить у меня</button></div><div class="local-controls"><label for="volume">Громкость</label><input id="volume" type="range" min="0" max="100" value="80" aria-label="Громкость на этом устройстве"><button id="chat-toggle" class="outline" aria-pressed="false">Чат</button></div>
<div class="reactions-bar" aria-label="Реакции"><span>Без слов:</span><button type="button" data-reaction="❤️" aria-label="Сердце">❤️</button><button type="button" data-reaction="😂" aria-label="Смешно">😂</button><button type="button" data-reaction="😳" aria-label="Ого">😳</button><button type="button" data-reaction="🥹" aria-label="Трогательно">🥹</button><button type="button" data-reaction="🔥" aria-label="Огонь">🔥</button><button type="button" data-reaction="🍿" aria-label="Попкорн">🍿</button></div>
<p id="player-status" class="player-status" role="status">Управление общее: пауза и перемотка меняются у всех.</p><form id="video-form" class="video-form"><label for="video-url">Что посмотрим?</label><div><span class="youtube-icon">▶</span><input id="video-url" type="text" maxlength="2048" placeholder="Ссылка YouTube, MP4 или WebM" required aria-label="Ссылка на видео"><div class="video-submit-buttons"><button id="load-now" class="primary" type="submit">Смотреть <span>↗</span></button><button id="queue-add" class="primary queue-add" type="button">+ В очередь</button></div></div><input id="video-title" class="title-input" maxlength="120" placeholder="Название ролика — по желанию" aria-label="Название ролика"><small class="media-help">YouTube или прямая HTTPS-ссылка на MP4 / WebM. Файлы не загружаются на хостинг.</small></form><div class="voice-panel"><div><strong>Можно шептаться голосом</strong><p id="voice-status" role="status">Микрофон включается только по вашей кнопке.</p></div><div class="voice-buttons"><button id="voice-start" class="outline">Включить микрофон</button><button id="voice-mute" class="outline" hidden>Выключить звук</button><button id="voice-retry" class="outline" hidden>Пересоединить</button></div><audio id="remote-audio" autoplay playsinline></audio><p class="voice-note">Лучше в наушниках. Во время разговора видео станет тише. Для голоса нужен HTTPS; в некоторых сетях — TURN-сервер.</p></div>
<div class="choice-panel"><span id="choice-status">Следующий ролик выбираете вместе</span><select id="choose-person" aria-label="Кому передать выбор"><option value="">Выбираем вместе</option></select><button id="pass-choice" class="outline">Выбирай ты ♡</button></div>
<div class="evening-panels"><section class="feature-panel"><div class="panel-heading"><h2>Очередь на вечер <span id="queue-count">0</span></h2><div><button id="queue-shuffle" class="small-button">Перемешать</button><button id="queue-random" class="small-button">Случайное</button></div></div><div id="queue-list" class="feature-list"><p class="empty-note">Добавляйте ролики по очереди. Следующий начнётся автоматически.</p></div><button id="queue-next" class="outline">Следующее видео →</button></section><section class="feature-panel"><h2>Наши моменты ♡</h2><form id="bookmark-form"><input id="bookmark-note" maxlength="160" placeholder="Вот тут мы смеялись…" aria-label="Подпись к моменту" required><button class="outline" type="submit">Сохранить момент</button></form><div id="bookmark-list" class="feature-list"><p class="empty-note">Сохраните таймкод с вашей подписью.</p></div></section><section class="feature-panel history-panel"><h2>История наших вечеров</h2><p class="empty-note">Последние 100 запусков, сгруппированные по дате на вашем устройстве.</p><div id="history-list" class="feature-list"></div></section></div>
<div class="room-tip"><span>✧</span><p><b>У каждого свой экран. Момент — общий.</b><br>Отправьте ссылку на зал тому, с кем хочется разделить этот вечер.</p><span class="tip-heart">♡</span></div></div>
<aside class="chat-panel"><div class="chat-header"><h2>Чатик <span>✦</span></h2><span id="member-count">0 в зале</span><button id="chat-close" class="small-button" aria-label="Скрыть чат">×</button></div><div id="members" class="members"></div><div id="messages" class="messages" role="log" aria-live="polite" aria-label="Сообщения чата"><div id="chat-welcome" class="chat-welcome"><span>☕</span><h3>Устраивайтесь поудобнее</h3><p>Здесь можно шептаться.<br>Никто не попросит потише.</p></div></div><form id="chat-form" class="chat-form"><textarea id="chat-text" rows="1" maxlength="1000" placeholder="Сказать что-нибудь…" aria-label="Сообщение" required></textarea><button type="submit" title="Отправить" aria-label="Отправить сообщение">↑</button></form><span class="chat-hint">Enter — отправить · Shift + Enter — новая строка</span></aside></div>
</section>
</main><footer><span>вместе лучше <span class="heart">♡</span></span><span>Маленькое место для больших и маленьких моментов.</span><span>YOUTUBE WATCH PARTY</span></footer><div id="toast" role="alert" hidden></div><dialog id="invite-dialog"><form method="dialog"><button class="dialog-close" aria-label="Закрыть">×</button></form><span class="eyebrow">ВАШ ВЕЧЕР НАЧИНАЕТСЯ ЗДЕСЬ</span><h2>Позовите своего человека</h2><p>Любой, у кого есть эта ссылка, сможет войти в зал и управлять видео. Делитесь лично.</p><input id="invite-url" readonly aria-label="Ссылка на зал"><button id="copy-link" class="primary">Скопировать ссылку ↗</button><span id="copy-status" role="status"></span></dialog>
<script nonce="<?=htmlspecialchars($nonce,ENT_QUOTES)?>">
'use strict';
const $ = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const roomParam = new URL(location.href).searchParams.get('room');
let roomId = roomParam, current = null, cursor = 0, joined = false, player = null, playerReady = false;
let video = null, anchor = 0, timer = null, lastGood = 0, latestTime = 0, busy = false, pollBusy = false, ytPromise = null, toastTimer;
let playerError = false, playerLoading = false, playerAttempted = false;
function toast(message) { $('toast').textContent = message; $('toast').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true, 6000); }
function storedNick() { try { return localStorage.getItem('vmeste-nick') || ''; } catch { return ''; } }
$('nickname').value = storedNick();
if (roomParam) { $('join-button').firstChild.textContent = 'Войти в наш зал '; $('join-hint').textContent = 'Вас пригласили. Введите ник — и вы рядом.'; }
async function api(action, data = {}) {
    const started = performance.now();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch('index.php?api=1', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action, room:roomId, after:cursor, signalAfter:signalCursor, ...data}), signal:controller.signal});
        const result = await response.json();
        if (!response.ok) { const e = new Error(result.error || 'Не удалось выполнить запрос.'); e.status = response.status; throw e; }
        result.rtt = Math.min((performance.now() - started) / 1000, 2);
        return result;
    } catch (e) {
        if (e.status) throw e;
        throw new Error(action === 'state' ? 'Связь прервалась. Переподключаемся…' : 'Не получен ответ сервера. Проверьте связь: команда могла выполниться.');
    } finally { clearTimeout(timeout); }
}
function accept(data) {
    if (!data.state || data.serverTime < latestTime) return;
    latestTime = data.serverTime; current = data; roomId = data.room; lastGood = performance.now(); anchor = performance.now();
    $('connection').textContent = 'Вы на связи'; $('connection').classList.remove('offline');
    $('member-count').textContent = `${data.members.length} в зале`;
    $('members').replaceChildren(...data.members.map(member => { const el = document.createElement('span'); el.className = 'member'; el.textContent = member.nick + (member.id === data.me.id ? ' · вы' : ''); return el; }));
    const messages = $('messages'); const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 70;
    for (const m of data.messages) {
        if (Number(m.id) <= cursor) continue;
        $('chat-welcome')?.remove();
        const el = document.createElement('div'); el.className = 'message' + (m.member === data.me.id ? ' mine' : '');
        const meta = document.createElement('div'); meta.className = 'message-meta';
        const name = document.createElement('span'); name.textContent = m.nick;
        const time = document.createElement('time'); const date = new Date(m.created * 1000); time.dateTime = date.toISOString(); time.textContent = date.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});
        const body = document.createElement('div'); body.className = 'message-body'; body.textContent = m.body;
        if (document.querySelector('.chat-panel').classList.contains('is-hidden') && m.member!==data.me.id) $('chat-toggle').textContent='Чат · новое';
        meta.append(name, time); el.append(meta, body); messages.append(el); cursor = Math.max(cursor, Number(m.id));
    }
    while (messages.children.length > 300) messages.firstChild.remove();
    if (nearBottom) messages.scrollTop = messages.scrollHeight;
    $('empty-player').hidden = Boolean(data.state.video);
    if (data.state.video && data.state.source!=='file') ensurePlayer();
    applyPlayback(); renderControls(); renderFeatures(data);
}
function targetPosition() {
    const s = current.state;
    return Math.max(0, s.position + (s.playing ? Math.max(0, current.serverTime - s.updated) + current.rtt / 2 + (performance.now() - anchor) / 1000 : 0));
}
function loadYoutube() {
    if (window.YT?.Player) return Promise.resolve();
    if (ytPromise) return ytPromise;
    ytPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const timeout = setTimeout(() => { script.remove(); ytPromise = null; reject(new Error('YouTube не ответил. Проверьте доступ к YouTube и нажмите «Включить у меня».')); }, 12000);
        window.onYouTubeIframeAPIReady = () => { clearTimeout(timeout); resolve(); };
        script.src = 'https://www.youtube.com/iframe_api';
        script.onerror = () => { clearTimeout(timeout); script.remove(); ytPromise = null; reject(new Error('Не удалось загрузить YouTube. Проверьте блокировщик и доступ к сайту.')); };
        document.head.append(script);
    });
    return ytPromise;
}
async function ensurePlayer(retry = false) {
    if (player || playerLoading || (playerAttempted && !retry)) return;
    playerLoading = true; playerAttempted = true;
    try {
        await loadYoutube();
        player = new YT.Player('player', {host:'https://www.youtube-nocookie.com', width:'100%', height:'100%', playerVars:{controls:0, disablekb:1, playsinline:1, rel:0, origin:location.origin}, events:{
            onReady() { playerReady = true; playerLoading = false; applyPlayback(); },
            onError(e) { playerError = true; const errors = {2:'Некорректный идентификатор видео.',5:'Браузер не смог воспроизвести видео.',100:'Видео удалено или закрыто.',101:'Автор запретил встраивание этого видео.',150:'Автор запретил встраивание этого видео.',153:'YouTube отклонил проверку источника. Откройте зал на обычном HTTPS-домене.'}; $('player-status').textContent = errors[e.data] || 'Видео недоступно. Попробуйте другое.'; },
            onAutoplayBlocked() { $('player-status').textContent = 'Браузер ждёт вашего разрешения. Нажмите «Включить у меня».'; },
            onStateChange(e) { if (e.data === 1) $('player-status').textContent = 'Смотрим вместе · позиция сверяется с сервером каждую секунду'; }
        }});
    } catch (e) { playerLoading = false; $('player-status').textContent = e.message; }
}
function applyPlayback() {
    if (!current?.state.video) return;
    const s=current.state, desired=targetPosition();
    if (s.source==='file') {
        if (playerReady && player.getPlayerState()===1) player.pauseVideo();
        $('player').hidden=true;direct.hidden=false;
        const key=s.video+'|'+s.media_seq;
        if(fileKey!==key){fileKey=key;direct.src=s.video;direct.load();$('player-status').textContent='Прямое видео · управление общее, громкость ваша';}
        if(direct.readyState<1)return;
        const duration=fileAdapter.getDuration();
        if(Math.abs(direct.currentTime-desired)>(s.playing?1.5:.15))fileAdapter.seekTo(desired);
        if(duration && desired>=duration-.1){direct.pause();return;}
        if(s.playing && direct.paused)fileAdapter.playVideo();
        if(!s.playing && !direct.paused)direct.pause();
        setLocalVolume();return;
    }
    if(!direct.paused)direct.pause();direct.hidden=true;$('player').hidden=false;
    if(!playerReady){ensurePlayer();return;}
    if(video!==s.video+'|'+s.media_seq){
        video=s.video+'|'+s.media_seq;playerError=false;
        $('player-status').textContent='Видео добавлено. Нажмите ▶, чтобы начать вместе.';
        player.cueVideoById({videoId:s.video,startSeconds:desired});
    }
    if(playerError)return;
    const state=player.getPlayerState();
    setLocalVolume();
    if(state===-1||state===5){if(s.playing)player.playVideo();if(Math.abs((player.getCurrentTime()||0)-desired)>(s.playing?1.5:.15))player.seekTo(desired,true);return;}
    const duration=player.getDuration();
    if(duration && desired>=duration-.1){if(state===1)player.pauseVideo();return;}
    if(Math.abs((player.getCurrentTime()||0)-desired)>(s.playing?1.5:.15))player.seekTo(desired,true);
    if(s.playing && state!==1 && state!==3)player.playVideo();
    if(!s.playing && (state===1||state===3))player.pauseVideo();
}
function formatTime(seconds) { seconds = Math.floor(Math.max(0, seconds || 0)); const h = Math.floor(seconds/3600); const m = Math.floor(seconds/60)%60; return (h ? `${h}:${String(m).padStart(2,'0')}` : String(m)) + ':' + String(seconds%60).padStart(2,'0'); }
function renderControls() {
    const stale = !lastGood || performance.now() - lastGood > 7000;
    if (stale && joined) { $('connection').textContent = 'Переподключаемся…'; $('connection').classList.add('offline'); }
    const hasVideo = Boolean(current?.state.video);
    $('play').disabled = !hasVideo || stale || busy || Boolean(current?.state.waiting);
    $('seek').disabled = !hasVideo || Boolean(current?.state.waiting) || !activeReady() || !activePlayer()?.getDuration() || stale || busy;
    $('enable-sound').disabled = !hasVideo;
    const duration = activeReady() ? activePlayer().getDuration() : 0;
    const position = hasVideo ? targetPosition() : 0;
    const ended = duration > 0 && position >= duration - .3;
    $('play').textContent = current?.state.playing && !ended ? 'Ⅱ' : '▶';
    $('play').classList.toggle('is-playing', Boolean(current?.state.playing && !ended));
    $('play').setAttribute('aria-label', current?.state.playing && !ended ? 'Пауза для всех' : 'Воспроизвести для всех');
    $('position').textContent = formatTime(duration ? Math.min(position, duration) : position);
    $('duration').textContent = formatTime(duration);
    $('seek').max = duration || 100;
    if (document.activeElement !== $('seek')) $('seek').value = duration ? Math.min(position, duration) : 0;
}
async function poll() {
    if (!joined || pollBusy) return;
    pollBusy = true;
    try { accept(await api('state')); }
    catch (e) {
        $('connection').textContent = 'Переподключаемся…'; $('connection').classList.add('offline');
        if ([401,403,404].includes(e.status)) { joined = false; clearTimeout(timer); toast(e.message); $('lobby').hidden = false; $('room').hidden = true; activePlayer()?.pauseVideo(); stopVoice(false); }
    } finally { pollBusy = false; if (joined) timer = setTimeout(poll, document.hidden ? 4000 : 1000); }
}
$('join-form').addEventListener('submit', async e => {
    e.preventDefault(); const button = $('join-button'); button.disabled = true;
    try {
        const nick = $('nickname').value.trim();
        const data = await api(roomId ? 'join' : 'create', {nick});
        try { localStorage.setItem('vmeste-nick', nick); } catch {}
        roomId = data.room; history.replaceState(null, '', `?room=${roomId}`);
        joined = true; $('lobby').hidden = true; $('room').hidden = false; accept(data); poll();
    } catch(e) { toast(e.message); } finally { button.disabled = false; }
});
async function control(op, extra = {}) {
    if (!current || busy) return false;
    busy = true; renderControls();
    try {
        const cooldown=Math.max(0,180-(performance.now()-lastControlCompleted));
        if(cooldown)await new Promise(resolve=>setTimeout(resolve,cooldown));
        accept(await api('control', {op, version:current.state.version, ...extra})); return true;
    }
    catch (e) { toast(e.message); if (e.status === 409) { try { accept(await api('state')); } catch {} } return false; }
    finally { lastControlCompleted=performance.now(); busy = false; renderControls(); }
}
$('video-form').addEventListener('submit', async e => {
    e.preventDefault(); if (busy) return;
    const input = $('video-url'), value = input.value;
    const btn = e.currentTarget.querySelector('button'); btn.disabled = true;
    try { if (await control('load', {url:value.trim(), title:$('video-title').value.trim()}) && input.value === value) { input.value = ''; $('video-title').value = ''; } } finally { btn.disabled = false; }
});
$('play').addEventListener('click', async () => {
    if(activeReady()){activePlayer().unMute();if(!current.state.playing)activePlayer().playVideo();}
    const duration=activeReady()?activePlayer().getDuration():0;
    if(duration && targetPosition()>=duration-.3)await control('replay');
    else await control(current.state.playing?'pause':'play');
});
$('seek').addEventListener('change',()=>control('seek',{position:Number($('seek').value)}));
$('enable-sound').addEventListener('click',()=>{
    if(current?.state.source!=='file' && !playerReady){ensurePlayer(true);return;}
    if(playerError){video=null;playerError=false;applyPlayback();}
    activePlayer()?.unMute();setLocalVolume();
    if(current.state.playing)activePlayer()?.playVideo();
    $('player-status').textContent='Звук разрешён на этом устройстве. Кнопка ▶ запускает видео для всех.';
});
$('chat-form').addEventListener('submit', async e => {
    e.preventDefault(); const input = $('chat-text'), body = input.value.trim(); if (!body) return;
    const btn = e.currentTarget.querySelector('button'); if (btn.disabled) return; btn.disabled = true;
    try { accept(await api('message', {body})); if (input.value.trim() === body) input.value = ''; $('messages').scrollTop = $('messages').scrollHeight; }
    catch(e) { toast(e.message); } finally { btn.disabled = false; input.focus(); }
});
$('chat-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('chat-form').requestSubmit(); } });
$('invite').addEventListener('click', () => { $('invite-url').value = location.href; $('copy-status').textContent = ''; $('invite-dialog').showModal(); });
$('copy-link').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('invite-url').value); $('copy-status').textContent = 'Ссылка скопирована. Отправьте её лично ♡'; } catch { $('invite-url').select(); $('copy-status').textContent = 'Выделили ссылку — скопируйте её вручную.'; } });
$('leave').addEventListener('click', async () => { if (busy) return; busy = true; try { await stopVoice(); await api('leave'); joined = false; clearTimeout(timer); activePlayer()?.pauseVideo(); stopVoice(false); location.assign(location.pathname); } catch(e) { toast(e.message); busy = false; } });
document.addEventListener('visibilitychange', () => { if (!document.hidden && joined) { clearTimeout(timer); poll(); } });
window.addEventListener('online', () => { if (joined) { clearTimeout(timer); poll(); } });
setInterval(renderControls, 500);
// Personal evening features. All user strings are inserted as text, never HTML.
const direct = $('direct-video');
let lastControlCompleted = 0;
let fileKey = '', endedVersion = -1, reactionCursor = 0, signalCursor = 0;
let listsSignature = '', memberSignature = '', voiceQueue = Promise.resolve();
const fileAdapter = {
    getDuration: () => Number.isFinite(direct.duration) ? direct.duration : 0,
    getCurrentTime: () => direct.currentTime || 0,
    getPlayerState: () => direct.ended ? 0 : direct.paused ? 2 : 1,
    pauseVideo: () => direct.pause(),
    playVideo: () => direct.play().catch(() => { $('player-status').textContent = 'Нажмите «Включить у меня», чтобы разрешить воспроизведение.'; }),
    seekTo: p => { if (direct.readyState >= 1) direct.currentTime = Math.min(p, fileAdapter.getDuration() || p); },
    unMute: () => { direct.muted = false; },
    setVolume: v => { direct.volume = Math.min(1, Math.max(0, v / 100)); }
};
function activePlayer() { return current?.state.source === 'file' ? fileAdapter : player; }
function activeReady() { return current?.state.source === 'file' ? direct.readyState >= 1 : playerReady; }
function setLocalVolume() {
    const volume = Number($('volume').value) * (voice.stream && voice.pc?.connectionState === 'connected' ? .3 : 1);
    activePlayer()?.setVolume(volume);
}
function autoAdvance() {
    if (!joined || !current?.state.playing || current.state.waiting || busy || !activeReady() || performance.now()-lastGood>5000) return;
    const duration = activePlayer().getDuration();
    if (duration > 0 && targetPosition() >= duration - .1 && endedVersion !== current.state.version) {
        endedVersion = current.state.version;
        control('ended', {duration}).then(ok => { if (!ok) endedVersion = -1; });
    }
}
function el(tag, text, className) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (className) n.className = className; return n; }
function button(text, fn) { const b = el('button', text); b.type = 'button'; b.addEventListener('click', fn); return b; }
async function action(name, data = {}) {
    try { accept(await api(name, data)); return true; } catch (e) { toast(e.message); return false; }
}
function featureItem(title, subtitle, buttons) {
    const n = el('div', undefined, 'feature-item'); n.append(el('strong', title), el('small', subtitle));
    const actions = el('div', undefined, 'item-actions'); actions.append(...buttons); n.append(actions); return n;
}
function renderFeatures(data) {
    const s=data.state, mine=data.me.id;
    const chooser=data.members.find(m=>m.id===s.chooser);
    $('now-title').textContent=s.title || (s.video ? `YouTube · ${s.video}` : 'Выберите видео на вечер');
    $('chooser-label').textContent=s.chooser ? (chooser ? `Выбирает ${chooser.nick}` : 'Выбиравший участник отошёл — можно вернуть общий выбор') : 'Выбираем вместе';
    $('choice-status').textContent=$('chooser-label').textContent;
    $('waiting-banner').hidden=!s.waiting;
    $('ready-list').textContent=data.members.map(m=>`${m.nick}: ${Number(m.ready) ? 'готовы ✓' : 'ждём'}`).join(' · ') + (data.members.length<2 ? ' · ждём второго участника' : '');
    $('ready-button').disabled=Boolean(Number(data.me.ready));
    $('ready-button').textContent=Number(data.me.ready) ? 'Вы готовы ✓' : 'Я готов / готова';
    $('wait-button').disabled=!s.video || s.waiting;
    const selectedElsewhere=s.chooser && s.chooser!==mine && chooser;
    $('load-now').disabled=Boolean(selectedElsewhere || s.waiting || busy);
    $('queue-next').disabled=!data.queue.length || Boolean(selectedElsewhere || s.waiting || busy);
    $('queue-random').disabled=$('queue-next').disabled;
    $('queue-shuffle').disabled=data.queue.length<2 || Boolean(selectedElsewhere);
    $('bookmark-note').disabled=!s.video;
    $('bookmark-form').querySelector('button').disabled=!s.video;
    $('pass-choice').disabled=Boolean(selectedElsewhere);
    const ms=JSON.stringify(data.members.map(m=>[m.id,m.nick]));
    if (ms!==memberSignature) {
        memberSignature=ms; const select=$('choose-person'); const value=select.value;
        select.replaceChildren(new Option('Выбираем вместе',''), ...data.members.filter(m=>m.id!==mine).map(m=>new Option(m.nick,m.id)));
        if ([...select.options].some(o=>o.value===value)) select.value=value;
        if (!value && data.members.length===2) select.value=data.members.find(m=>m.id!==mine).id;
    }
    const signature=JSON.stringify([data.queue,data.bookmarks,data.history,Boolean(selectedElsewhere),s.waiting]);
    if (signature!==listsSignature) {
        listsSignature=signature;
        $('queue-count').textContent=String(data.queue.length);
        $('queue-list').replaceChildren(...data.queue.map((q,i)=>featureItem(`${i+1}. ${q.title}`,`${q.source==='file'?'Файл':'YouTube'} · добавил(а) ${q.nick}`,[
            button('Смотреть',()=>control('queue_play',{id:Number(q.id)})),button('↑',()=>action('queue',{op:'up',id:Number(q.id)})),button('↓',()=>action('queue',{op:'down',id:Number(q.id)})),button('Убрать',()=>action('queue',{op:'remove',id:Number(q.id)}))
        ])));
        if (!data.queue.length) $('queue-list').append(el('p','Здесь будет ваш план на вечер. Добавьте ролик кнопкой «В очередь».','empty-note'));
        $('bookmark-list').replaceChildren(...data.bookmarks.map(b=>featureItem(`${formatTime(Number(b.position))} · ${b.note}`,`${b.title} · ${b.nick}`,[button('К моменту',()=>control('bookmark',{id:Number(b.id)})),button('Удалить',()=>action('bookmark',{op:'remove',id:Number(b.id)}))])));
        if (!data.bookmarks.length) $('bookmark-list').append(el('p','Сохраните таймкод с вашей подписью.','empty-note'));
        const history=$('history-list'); history.replaceChildren(); let day='';
        for (const h of data.history) {
            const date=new Date(h.created*1000).toLocaleDateString('ru-RU',{day:'numeric',month:'long',year:'numeric'});
            if (date!==day) { history.append(el('div',date,'history-day')); day=date; }
            history.append(featureItem(h.title,`${new Date(h.created*1000).toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})} · ${h.nick}`,[button('Ещё раз',()=>control('history',{id:Number(h.id)}))]));
        }
        if (!data.history.length) history.append(el('p','Ваш первый вечер только начинается ♡','empty-note'));
        if (selectedElsewhere || s.waiting) {
            for (const list of [$('queue-list'),$('bookmark-list'),$('history-list')]) for (const item of list.querySelectorAll('.item-actions')) if (item.firstChild) item.firstChild.disabled=true;
        }
    }
    for (const r of data.reactions) {
        if (Number(r.id)<=reactionCursor) continue;
        reactionCursor=Number(r.id);
        const reaction=el('div',undefined,'flying-reaction'); reaction.append(el('p',r.emoji),el('small',r.nick)); $('reactions-stage').append(reaction); setTimeout(()=>reaction.remove(),3000);
    }
    voiceQueue=voiceQueue.then(()=>voiceTick(data)).catch(e=>{ clearTimeout(voice.timeout); $('voice-status').textContent='Голос: '+e.message; });
}
$('volume').addEventListener('input',setLocalVolume);
$('queue-add').addEventListener('click',async()=>{
    const value=$('video-url').value.trim(); if (!value) { $('video-url').reportValidity(); return; }
    $('queue-add').disabled=true;
    try { if (await action('queue',{op:'add',url:value,title:$('video-title').value.trim()})) { $('video-url').value=''; $('video-title').value=''; toast('Добавлено в очередь на вечер'); } } finally { $('queue-add').disabled=false; }
});
$('queue-next').addEventListener('click',()=>control('next'));
$('queue-random').addEventListener('click',()=>control('random'));
$('queue-shuffle').addEventListener('click',()=>action('queue',{op:'shuffle'}));
$('wait-button').addEventListener('click',()=>control('wait'));
$('wait-cancel').addEventListener('click',()=>control('wait_cancel'));
$('ready-button').addEventListener('click',async()=>{ $('ready-button').disabled=true; await action('ready'); });
$('pass-choice').addEventListener('click',()=>control('pass',{target:$('choose-person').value || null}));
$('bookmark-form').addEventListener('submit',async e=>{e.preventDefault();const note=$('bookmark-note').value.trim(); if (!note) return; const b=e.currentTarget.querySelector('button'); b.disabled=true;try {if(await action('bookmark',{note})) $('bookmark-note').value='';}finally{b.disabled=false;}});
for (const b of document.querySelectorAll('[data-reaction]')) b.addEventListener('click',()=>action('reaction',{emoji:b.dataset.reaction}));
$('cinema-mode').addEventListener('click',()=>{ const on=document.body.classList.toggle('cinema'); $('cinema-mode').setAttribute('aria-pressed',String(on)); $('cinema-mode').textContent=on?'Обычный режим':'Режим кино'; });
function setChatClosed(closed){
    document.querySelector('.chat-panel').classList.toggle('is-hidden',closed);
    document.querySelector('.room-grid').classList.toggle('chat-closed',closed);
    $('chat-toggle').setAttribute('aria-pressed',String(!closed));
    if(!closed){$('chat-toggle').textContent='Чат';$('messages').scrollTop=$('messages').scrollHeight;}
}
$('chat-toggle').addEventListener('click',()=>setChatClosed(!document.querySelector('.chat-panel').classList.contains('is-hidden')));
$('chat-close').addEventListener('click',()=>setChatClosed(true));
if(innerWidth<761)setChatClosed(true);

$('fullscreen').addEventListener('click',async()=>{try{if(document.fullscreenElement) await document.exitFullscreen();else if(document.querySelector('.watch-column').requestFullscreen) await document.querySelector('.watch-column').requestFullscreen();else if(current.state.source==='file' && direct.webkitEnterFullscreen) direct.webkitEnterFullscreen();else toast('Полноэкранный режим недоступен в этом браузере. Используйте режим кино.');}catch{toast('Браузер не разрешил полноэкранный режим.');}});
direct.addEventListener('loadedmetadata',()=>{applyPlayback();renderControls();setLocalVolume();});
direct.addEventListener('error',()=>{ if(current?.state.source==='file') $('player-status').textContent='Файл недоступен или формат не поддерживается. Нужна прямая доступная обоим HTTPS-ссылка на MP4 / WebM.'; });
direct.addEventListener('ended',autoAdvance);
setInterval(autoAdvance,700);

// Voice is opt-in, two-person WebRTC. PHP is only a short-lived signaling relay.
const voice={stream:null,pc:null,remote:null,peer:null,session:null,candidates:[],signalChain:Promise.resolve(),muted:false,starting:false,timeout:null,stopping:false};
function peerId(){return [...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');}
function resetConnection() {
    clearTimeout(voice.timeout);
    if (voice.pc) { voice.pc.onconnectionstatechange=null;voice.pc.onicecandidate=null;voice.pc.ontrack=null;voice.pc.close(); }
    voice.pc=null;voice.remote=null;voice.session=null;voice.candidates=[];
    $('remote-audio').srcObject=null;
    setLocalVolume();
}
function sendSignal(payload) {
    const peer=voice.peer,target=voice.remote;
    voice.signalChain=voice.signalChain.catch(()=>{}).then(async()=>{
        if (!voice.stream || peer!==voice.peer || target!==voice.remote) return;
        // Keep ICE signaling sequential and below the server's burst limit.
        await new Promise(resolve=>setTimeout(resolve,25));
        accept(await api('signal',{peer,target,payload}));
    });
    voice.signalChain.catch(e=>{if(voice.stream)$('voice-status').textContent='Не удалось передать голосовой сигнал: '+e.message;});
    return voice.signalChain;
}
async function connectVoice(remote,data) {
    resetConnection();voice.remote=remote;voice.session=voice.peer;
    const pc=new RTCPeerConnection({iceServers:data.iceServers||[]});voice.pc=pc;
    for(const track of voice.stream.getTracks()) pc.addTrack(track,voice.stream);
    pc.onicecandidate=e=>{if(e.candidate)sendSignal({type:'candidate',candidate:e.candidate.toJSON()});};
    pc.ontrack=e=>{ $('remote-audio').srcObject=e.streams[0];$('remote-audio').play().catch(()=>{$('voice-status').textContent='Нажмите «Слышать голос», чтобы включить звук собеседника.';$('voice-retry').hidden=false;$('voice-retry').textContent='Слышать голос';}); };
    pc.onconnectionstatechange=()=>{
        if(pc!==voice.pc)return;
        const state=pc.connectionState;
        if(state==='connected'){clearTimeout(voice.timeout);$('voice-status').textContent=voice.muted?'Вы на связи · ваш микрофон выключен':'Вы на связи · микрофон включён';$('voice-retry').hidden=true;}
        else if(state==='failed'||state==='disconnected'){$('voice-status').textContent='Голос прервался. Пересоединитесь; если не поможет, нужен TURN-сервер.';$('voice-retry').hidden=false;$('voice-retry').textContent='Пересоединить';}
        setLocalVolume();
    };
    $('voice-status').textContent='Соединяем голос…';
    voice.timeout=setTimeout(()=>{if(voice.pc===pc && pc.connectionState!=='connected'){$('voice-status').textContent='Голос не соединился. Попробуйте пересоединить; для этих сетей может потребоваться TURN.';$('voice-retry').hidden=false;}},18000);
    // Lexical ordering elects one offerer and prevents offer glare.
    if(voice.peer<remote){await pc.setLocalDescription(await pc.createOffer());await sendSignal({type:'offer',sdp:pc.localDescription.sdp});}
}
async function voiceTick(data) {
    if(!voice.stream || data.serverTime<current.serverTime)return;
    if(data.me.voice!==voice.peer){await stopVoice(false);$('voice-status').textContent='Голос включён в другой вкладке. В этой вкладке микрофон остановлен.';return;}
    const remote=data.members.find(m=>m.id!==data.me.id && m.voice);
    if(!remote){if(voice.pc)resetConnection();$('voice-status').textContent='Микрофон включён · ждём, когда второй участник тоже включит голос.';return;}
    if(voice.remote!==remote.voice)await connectVoice(remote.voice,data);
    const pc=voice.pc;
    for(const signal of data.signals){
        if(Number(signal.id)<=signalCursor)continue;signalCursor=Number(signal.id);
        if(signal.sender!==voice.remote || !pc)continue;
        const payload=signal.payload;
        if(payload.type==='offer'){
            if(voice.peer<voice.remote)continue;
            await pc.setRemoteDescription({type:'offer',sdp:payload.sdp});
            for(const candidate of voice.candidates.splice(0))await pc.addIceCandidate(candidate);
            await pc.setLocalDescription(await pc.createAnswer());await sendSignal({type:'answer',sdp:pc.localDescription.sdp});
        }else if(payload.type==='answer'){
            if(pc.signalingState!=='have-local-offer')continue;
            await pc.setRemoteDescription({type:'answer',sdp:payload.sdp});
            for(const candidate of voice.candidates.splice(0))await pc.addIceCandidate(candidate);
        }else if(payload.type==='candidate'){
            if(pc.remoteDescription)await pc.addIceCandidate(payload.candidate);else voice.candidates.push(payload.candidate);
        }
    }
}
async function stopVoice(notify=true) {
    if(voice.stopping)return;voice.stopping=true;
    const peer=voice.peer;
    if(voice.stream)voice.stream.getTracks().forEach(t=>t.stop());voice.stream=null;resetConnection();voice.peer=null;signalCursor=0;
    $('voice-start').textContent='Включить микрофон';$('voice-start').classList.remove('active');$('voice-mute').hidden=true;$('voice-retry').hidden=true;
    $('voice-status').textContent='Микрофон выключен.';
    try{if(notify&&peer&&joined)await action('voice',{op:'leave',peer});}finally{voice.stopping=false;}
}
async function startVoice(){
    if(voice.starting)return; if(voice.stream){await stopVoice();return;}
    if(!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.RTCPeerConnection){toast('Для голоса откройте сайт по HTTPS в браузере с поддержкой WebRTC.');return;}
    voice.starting=true;$('voice-start').disabled=true;
    try{
        voice.stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
        voice.peer=peerId();voice.muted=false;signalCursor=0;
        for(const track of voice.stream.getTracks()) track.onended=()=>{if(voice.stream){stopVoice();toast('Микрофон отключён браузером.');}};
        const result=await api('voice',{op:'join',peer:voice.peer});
        $('voice-start').textContent='Завершить голос';$('voice-start').classList.add('active');$('voice-mute').hidden=false;$('voice-mute').textContent='Выключить микрофон';
        accept(result);
    }catch(e){await stopVoice(false);toast(e.name==='NotAllowedError'?'Разрешите доступ к микрофону в настройках браузера.':e.message);}
    finally{voice.starting=false;$('voice-start').disabled=false;}
}
$('voice-start').addEventListener('click',startVoice);
$('voice-mute').addEventListener('click',()=>{voice.muted=!voice.muted;voice.stream?.getAudioTracks().forEach(t=>t.enabled=!voice.muted);$('voice-mute').textContent=voice.muted?'Включить микрофон':'Выключить микрофон';$('voice-status').textContent=voice.muted?'Ваш микрофон выключен · собеседника по-прежнему слышно':'Микрофон включён';});
$('voice-retry').addEventListener('click',async()=>{if($('voice-retry').textContent==='Слышать голос'){$('remote-audio').play().then(()=>$('voice-retry').hidden=true).catch(()=>toast('Браузер не разрешил звук.'));return;}await stopVoice();await startVoice();});
window.addEventListener('pagehide',()=>{
    voice.stream?.getTracks().forEach(t=>t.stop());
    if(voice.peer && joined)fetch('index.php?api=1',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'voice',op:'leave',room:roomId,peer:voice.peer})}).catch(()=>{});
});
window.addEventListener('pageshow',e=>{if(e.persisted && voice.stream)stopVoice();});

</script>
</body></html>
