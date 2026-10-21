<?php
declare(strict_types=1);

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

if ($apiRequest) {
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail(405, 'Используйте POST.');
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) fail(413, 'Слишком большой запрос.');
    if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail(403, 'Обновите страницу: сессия изменилась.');
    $input=file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($input)>8192) fail(413, 'Слишком большой запрос.');
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
    $me=query($db,'SELECT * FROM members WHERE room=? AND identity=?',[$room,$identity])->fetch();
    if (!$me) fail(401,'Сначала войдите в зал.');
    if ($action==='leave') {
        query($db,'DELETE FROM members WHERE room=? AND identity=?',[$room,$identity]);
        $db->exec('COMMIT'); echo '{"ok":true}'; exit;
    }
    if (!in_array($action,['create','join','state','message','control'],true)) fail(422,'Неизвестное действие.');
    query($db,'UPDATE members SET seen=? WHERE room=? AND identity=?',[$now,$room,$identity]);
    $state=query($db,'SELECT * FROM rooms WHERE id=?',[$room])->fetch();
    if ($action==='message') {
        $body=textField($d,'body',1,1000);
        if ($now-(float)$me['last_message']<0.6) fail(429,'Сообщения отправляются слишком часто.');
        query($db,'INSERT INTO messages(room,member,nick,body,created) VALUES(?,?,?,?,?)',[$room,$me['id'],$me['nick'],$body,$now]);
        query($db,'UPDATE members SET last_message=? WHERE room=? AND identity=?',[$now,$room,$identity]);
        query($db,'DELETE FROM messages WHERE room=? AND id NOT IN (SELECT id FROM messages WHERE room=? ORDER BY id DESC LIMIT 300)',[$room,$room]);
    }
    if ($action==='control') {
        if ($now-(float)$me['last_control']<0.15) fail(429,'Слишком частые команды плееру.');
        if (!isset($d['version']) || $d['version']!==(int)$state['version']) fail(409,'Состояние уже изменилось. Повторите действие.');
        $op=$d['op']??'';
        $position=(float)$state['position']+($state['playing'] ? max(0,$now-(float)$state['updated']) : 0);
        $video=$state['video']; $playing=(int)$state['playing'];
        if ($op==='load') { $video=videoId(textField($d,'url',1,2048)); if (!$video) fail(422,'Нужна ссылка на отдельное видео YouTube.'); $position=0; $playing=0; }
        elseif (!$video) fail(422,'Сначала добавьте видео.');
        elseif ($op==='play') $playing=1;
        elseif ($op==='replay') { $position=0; $playing=1; }
        elseif ($op==='pause') $playing=0;
        elseif ($op==='seek') { $p=$d['position']??null; if (!is_numeric($p) || !is_finite((float)$p) || $p<0 || $p>604800) fail(422,'Некорректная позиция.'); $position=(float)$p; }
        else fail(422,'Неизвестная команда плееру.');
        query($db,'UPDATE rooms SET video=?,position=?,playing=?,updated=?,version=version+1 WHERE id=?',[$video,$position,$playing,$now,$room]);
        query($db,'UPDATE members SET last_control=? WHERE room=? AND identity=?',[$now,$room,$identity]);
        $state=query($db,'SELECT * FROM rooms WHERE id=?',[$room])->fetch();
    }
    $after=filter_var($d['after']??0,FILTER_VALIDATE_INT);
    if ($after===false || $after<0) fail(422,'Некорректный курсор сообщений.');
    $messages=query($db,'SELECT id,member,nick,body,created FROM (SELECT id,member,nick,body,created FROM messages WHERE room=? AND id>? ORDER BY id DESC LIMIT 100) ORDER BY id',[$room,$after])->fetchAll();
    $members=query($db,'SELECT id,nick FROM members WHERE room=? AND seen>? ORDER BY nick',[$room,$now-20])->fetchAll();
    $db->exec('COMMIT');
    echo json_encode(['room'=>$room,'me'=>['id'=>$me['id'],'nick'=>$me['nick']], 'state'=>['video'=>$state['video'],'position'=>(float)$state['position'],'playing'=>(bool)$state['playing'],'updated'=>(float)$state['updated'],'version'=>(int)$state['version']], 'members'=>$members,'messages'=>$messages,'serverTime'=>$now], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://www.youtube.com https://s.ytimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com; img-src 'self' https://i.ytimg.com data:; style-src 'self' 'nonce-$nonce'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?=htmlspecialchars($csrf,ENT_QUOTES)?>"><meta name="theme-color" content="#111015"><title>Вместе — ваш маленький кинозал</title><style nonce="<?=htmlspecialchars($nonce,ENT_QUOTES)?>">
:root{color-scheme:dark;--bg:#111015;--panel:#1a191f;--line:#302e37;--muted:#93909e;--text:#f4f0f0;--accent:#c2a5f5;--peach:#edbea7}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,textarea{font:inherit}button,a,input,textarea{ -webkit-tap-highlight-color:transparent}button{cursor:pointer}button:disabled{opacity:.4;cursor:not-allowed}a{color:inherit;text-decoration:none}button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:4px}input:focus,textarea:focus{outline:none;border-color:var(--accent)!important}button{transition:background .2s,transform .2s}button:hover:enabled{filter:brightness(1.1)}[hidden]{display:none!important}.header{height:104px;border-bottom:1px solid #24222b;display:flex;align-items:center;justify-content:space-between;margin:0 5%;gap:24px}.brand{font-size:29px;font-weight:800;letter-spacing:-1.5px;display:flex;align-items:center;gap:11px}.brand-icon{font-size:15px;display:grid;place-items:center;width:35px;height:35px;background:var(--accent);color:#19131f;border-radius:11px;transform:rotate(-7deg)}.brand-dot{color:var(--accent);margin-left:-10px}.header-note{font-size:12px;color:var(--muted)}.header-tag{font-size:9px;letter-spacing:1.6px;color:#bdb8c7;border:1px solid var(--line);padding:8px 12px;border-radius:30px;white-space:nowrap}.header-tag i,.connection:before{display:inline-block;background:#b7d1a3;width:6px;height:6px;border-radius:50%;margin-right:8px;content:''}main{max-width:1440px;margin:auto;padding:0 5%}.lobby{display:grid;grid-template-columns:1fr 1fr;align-items:center;min-height:calc(100vh - 187px);gap:4%;padding:70px 0 65px}.eyebrow{font-size:9px;font-weight:600;letter-spacing:2px;color:var(--accent)}.intro h1{font-size:clamp(44px,4.6vw,68px);line-height:1.12;font-weight:550;letter-spacing:-3px;margin:25px 0 24px}.intro h1 em{color:var(--peach);font-family:Georgia,serif;font-weight:400}.intro>p{color:var(--muted);font-size:15px;line-height:1.8;margin-bottom:30px}.join-card{max-width:360px}.join-card label{display:block;font-size:12px;margin-bottom:9px}.name-input{display:flex;border:1px solid #3a3643;border-radius:9px;background:#1c1a22;padding:0 15px;align-items:center;gap:12px}.name-input:focus-within{border-color:var(--accent)}.name-input>span{font-size:23px;color:var(--muted)}input{color:var(--text);background:transparent;border:0;min-width:0}.name-input input{height:48px;width:100%;outline:0}.primary{border:0;background:var(--accent);color:#20172d;font-size:13px;font-weight:650;border-radius:8px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px}.join-card .primary{width:100%;margin-top:12px}.primary span{font-size:20px;line-height:1}.join-card small{display:block;font-size:10px;color:var(--muted);margin-top:11px}.intro-perks{display:flex;flex-wrap:wrap;gap:20px;font-size:10px;color:#aaa5b3;margin-top:33px}.illustration{height:490px;position:relative;display:flex;align-items:center;justify-content:center}.orbit{position:absolute;border:1px solid #292431;border-radius:50%;width:490px;height:490px;max-width:110%}.orbit-two{width:380px;height:380px;border-style:dashed;opacity:.7}.floating-note{position:absolute;top:21px;font:italic 15px Georgia,serif;color:#b5a6c5;transform:rotate(-7deg)}.cinema{width:94%;max-width:490px;border:1px solid #4a414f;border-radius:13px;background:#211d27;transform:rotate(-4deg);overflow:hidden;box-shadow:0 25px 90px #0008;z-index:1}.cinema-bar{padding:12px 17px;display:flex;justify-content:space-between;font-size:9px;color:#8c8097}.cinema-bar>span:first-child{letter-spacing:4px;color:#6f5b78}.cinema-screen{height:235px;position:relative;overflow:hidden;background:linear-gradient(#7c667c,#b88e8a 70%,#d4aa8b)}.sun{position:absolute;width:95px;height:95px;border-radius:50%;background:#efc9a7;left:52%;top:30px;box-shadow:0 0 90px #e8b4a366}.hill{position:absolute;width:120%;height:200px;border-radius:50% 50% 0 0;bottom:-115px;left:-10%;background:#4a505a;transform:rotate(-15deg)}.hill-back{bottom:-88px;left:15%;background:#70616b;transform:rotate(17deg)}.hill-front{background:#303c45}.big-play{position:absolute;left:calc(50% - 25px);top:90px;background:#fff2;border:1px solid #fff5;width:50px;height:50px;display:grid;place-items:center;border-radius:50%;font-size:18px;padding-left:4px}.screen-caption{position:absolute;bottom:22px;width:100%;text-align:center;font:italic 17px Georgia,serif;letter-spacing:1px;color:#efdfd1;z-index:1}.cinema-bottom{display:flex;align-items:center;gap:13px;padding:14px;color:#c8b8d1}.cinema-bottom small{font-size:8px;white-space:nowrap}.fake-progress{height:3px;background:#443c4e;flex:1;border-radius:3px}.fake-progress i{display:block;background:var(--accent);width:42%;height:3px}.bubble{position:absolute;z-index:2;display:flex;align-items:center;gap:10px;background:#29232f;border:1px solid #4a3e50;padding:11px 17px 11px 10px;border-radius:13px;font-size:12px;box-shadow:0 10px 30px #0004}.bubble-one{right:-12px;bottom:105px;transform:rotate(5deg)}.bubble-two{left:0;bottom:42px;transform:rotate(-3deg)}.avatar{display:inline-grid;place-items:center;border-radius:50%;width:30px;height:30px;font-size:11px;color:#201927;flex-shrink:0}.peach{background:var(--peach)}.lilac{background:var(--accent)}.together-badge{position:absolute;right:8px;bottom:-6px;font-size:9px;letter-spacing:1px;color:#b1a5b7}.together-badge span{color:var(--peach);font-size:17px;margin-right:6px}footer{max-width:calc(100% - 10%);margin:auto;border-top:1px solid #28242e;display:flex;justify-content:space-between;align-items:center;gap:15px;padding:24px 0;color:#6f6b79;font-size:10px}footer>span:first-child{font-size:12px;color:#b5acbc}.heart{color:var(--peach);margin-left:6px}footer>span:last-child{letter-spacing:1.5px;font-size:8px}.room{padding:36px 0 45px;min-height:calc(100vh - 187px)}.room-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px;gap:15px}.room-heading h1{font-size:28px;font-weight:550;letter-spacing:-1px;margin:5px 0 0}.room-heading h1 span{color:var(--accent)}.room-actions{display:flex;align-items:center;gap:12px}.connection{font-size:10px;color:#b7d1a3}.connection.offline{color:var(--peach)}.connection.offline:before{background:var(--peach)}.outline,.icon-button{border:1px solid var(--line);border-radius:7px;background:transparent;color:#d0c6dc;padding:9px 15px;font-size:12px}.icon-button{font-size:17px;padding:5px 12px}.room-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px}.video-shell{aspect-ratio:16/9;position:relative;background:#17151e;border:1px solid #332c40;border-radius:11px 11px 0 0;overflow:hidden}.video-shell iframe,#player{width:100%;height:100%;position:absolute;inset:0}.empty-player{position:absolute;inset:0;background:radial-gradient(ellipse at 50% 90%,#2d223b,#18151e 70%);display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;pointer-events:none}.empty-symbol{display:grid;place-items:center;width:62px;height:62px;border-radius:19px;background:#362840;border:1px solid #5a456b;color:var(--accent);font-size:24px;padding-left:4px;margin-bottom:23px;transform:rotate(-7deg)}.empty-player h2{font-size:24px;letter-spacing:-.7px;font-weight:500;margin:11px 0 5px}.empty-player p{font-size:12px;color:var(--muted);line-height:1.9}.player-controls{display:flex;align-items:center;gap:12px;padding:12px 15px;background:#201b29;border:1px solid #332c40;border-top:0;border-radius:0 0 11px 11px;font-size:10px;color:#aaa0b7}.play-button{color:var(--accent);background:transparent;border:0;font-size:18px;width:28px;padding:0}.player-controls input{flex:1;width:30px;accent-color:var(--accent);height:4px}.sound-button{background:transparent;border:1px solid #4b3e5c;border-radius:5px;font-size:10px;color:var(--accent);padding:5px 8px;white-space:nowrap}.player-status{color:var(--muted);font-size:10px;min-height:17px;margin:9px 0 24px}.video-form{background:#1c1922;border:1px solid var(--line);padding:19px;border-radius:11px}.video-form label{font-size:12px;display:block;margin-bottom:10px}.video-form>div{display:flex;align-items:center;border:1px solid #41374e;border-radius:7px;padding:4px 4px 4px 14px;gap:12px;background:#141118}.youtube-icon{background:#79608b;font-size:8px;border-radius:4px;padding:1px 5px;color:#e4d6eb}.video-form input{flex:1;width:40px;font-size:12px}.video-form .primary{padding:9px 14px;font-size:11px}.room-tip{display:flex;align-items:center;gap:15px;padding:24px 4px;color:#887d94}.room-tip>span:first-child{font-size:25px;color:var(--accent)}.room-tip p{font-size:10px;margin:0;line-height:1.9}.room-tip b{color:#b7abbf;font-weight:500}.tip-heart{font:32px Georgia,serif;margin-left:auto;color:#7b5e7c}.chat-panel{background:#1b1820;border:1px solid var(--line);border-radius:11px;display:flex;flex-direction:column;overflow:hidden;min-height:520px;max-height:730px;height:100%}.chat-header{padding:19px 18px 14px;display:flex;align-items:center;justify-content:space-between}.chat-header h2{font-size:16px;font-weight:550;margin:0}.chat-header h2 span{color:var(--peach);font-size:13px;margin-left:5px}.chat-header>span{font-size:10px;color:var(--muted)}.members{padding:0 18px 14px;border-bottom:1px solid var(--line);display:flex;gap:7px;flex-wrap:wrap}.member{font-size:10px;padding:3px 9px;background:#292330;border-radius:20px;color:#cdb9e1}.member:before{content:'';display:inline-block;border-radius:50%;width:5px;height:5px;background:#b7d1a3;margin-right:6px}.messages{padding:18px;overflow-y:auto;flex:1;min-height:260px;scrollbar-width:thin;scrollbar-color:#4a3a55 transparent}.chat-welcome{text-align:center;padding:60px 0 10px;color:var(--muted)}.chat-welcome>span{font-size:32px;filter:grayscale(.8)}.chat-welcome h3{font-size:12px;font-weight:500;color:#cdc1d6}.chat-welcome p{font-size:11px}.message{margin-bottom:18px;overflow-wrap:anywhere}.message-meta{display:flex;align-items:center;gap:7px;margin-bottom:4px;font-size:10px;color:var(--accent)}.message-meta time{margin-left:auto;font-size:9px;color:#706779}.message.mine .message-meta{color:var(--peach)}.message-body{font-size:12px;white-space:pre-wrap;color:#ddd2e3;line-height:1.7;background:#25202c;padding:9px 11px;border-radius:0 9px 9px 9px}.message.mine .message-body{background:#312638}.chat-form{display:flex;align-items:center;gap:8px;border:1px solid #44384e;border-radius:8px;margin:12px 13px 0;padding:7px 8px 7px 12px}.chat-form textarea{resize:none;max-height:100px;min-height:28px;width:100%;background:transparent;border:0;color:var(--text);font-size:11px;padding-top:5px}.chat-form button{width:30px;height:30px;border:0;border-radius:6px;background:var(--accent);color:#30203e;flex-shrink:0;font-size:21px}.chat-hint{text-align:center;font-size:8px;color:#79707f;padding:8px 0 14px}#toast{position:fixed;bottom:25px;left:50%;transform:translateX(-50%);background:#3c2b45;border:1px solid #8e6ea7;color:#f5e8ff;padding:13px 22px;border-radius:10px;max-width:90%;font-size:13px;z-index:20}dialog{color:var(--text);background:#201a29;border:1px solid #594369;border-radius:16px;padding:34px;max-width:440px;width:90%}dialog::backdrop{background:#09060dc9;backdrop-filter:blur(5px)}dialog h2{font-size:24px;line-height:1.3;margin:20px 0 10px}dialog p{font-size:12px;color:#b4a6c0}dialog input{width:100%;border:1px solid #594369;border-radius:6px;padding:12px;font-size:12px;margin:12px 0}dialog .primary{width:100%}.dialog-close{position:absolute;right:16px;top:12px;color:#c0a6cf;background:transparent;border:0;font-size:25px}#copy-status{font-size:11px;color:var(--accent);display:block;margin-top:10px}@media(min-width:1550px){.header,footer{max-width:1296px;margin:auto}.intro h1{font-size:68px}}@media(max-width:1050px){.header-note{display:none}.lobby{gap:20px}.illustration{height:430px}.cinema-screen{height:190px}.bubble-one{right:0;bottom:85px}.room-grid{grid-template-columns:minmax(0,1fr) 280px;gap:16px}.room-actions{gap:8px}.connection{max-width:100px}.sound-button{max-width:95px;white-space:normal;line-height:1.3}.video-form>div{gap:8px}.video-form .primary{padding:9px}.video-form .primary span{display:none}}@media(max-width:760px){.header{height:80px}.header-tag{font-size:7px;letter-spacing:1px;padding:6px 9px}.brand{font-size:25px}.lobby{grid-template-columns:1fr;padding:42px 0;gap:10px}.intro h1{font-size:54px}.intro>p{font-size:14px}.intro .eyebrow{font-size:8px}.join-card{max-width:100%}.intro-perks{justify-content:space-between;gap:8px}.illustration{height:390px;max-width:500px;width:100%;margin:30px auto 15px}.orbit{width:340px;height:340px}.orbit-two{width:270px;height:270px}.cinema{max-width:390px;width:90%}.cinema-screen{height:195px}.bubble{font-size:10px}.bubble-one{bottom:76px;right:0}.bubble-two{bottom:22px}.together-badge{bottom:-15px}.floating-note{top:0}.room{padding-top:25px}.room-heading{align-items:flex-start;flex-direction:column;gap:15px}.room-heading h1{font-size:26px}.room-actions{width:100%}.room-actions .connection{margin-right:auto;max-width:none}.room-grid{grid-template-columns:1fr}.chat-panel{height:480px;min-height:480px;max-height:480px}.empty-player h2{font-size:20px}.empty-symbol{width:42px;height:42px;margin-bottom:12px;font-size:19px;border-radius:12px}.empty-player .eyebrow{font-size:7px}.empty-player p{font-size:10px;margin:4px}.player-controls{gap:8px;padding:10px}.sound-button{max-width:95px;font-size:9px}.video-form{padding:14px}.video-form input{font-size:11px}.video-form .primary{font-size:10px}.room-tip{padding:20px 2px}footer{flex-wrap:wrap;font-size:9px;gap:10px}footer>span:nth-child(2){order:3;width:100%;font-size:9px}footer>span:last-child{margin-left:auto}.desktop{display:none}}@media(prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
/* Draw essential controls locally, without relying on emoji fonts. */
.brand-icon,.empty-symbol,.big-play,.youtube-icon{font-size:0}
.brand-icon:before,.empty-symbol:before,.big-play:before,.youtube-icon:before{content:'';display:block;width:0;height:0;border-top:5px solid transparent;border-bottom:5px solid transparent;border-left:8px solid currentColor}
.empty-symbol:before,.big-play:before{border-top-width:9px;border-bottom-width:9px;border-left-width:14px}.youtube-icon:before{border-top-width:3px;border-bottom-width:3px;border-left-width:5px}.youtube-icon{padding:5px 7px}
.primary>span{font-size:0;width:12px;height:12px;display:block;position:relative}.primary>span:after{content:'';position:absolute;right:1px;top:2px;width:8px;height:8px;border-top:1.5px solid currentColor;border-right:1.5px solid currentColor}.primary>span:before{content:'';position:absolute;width:12px;height:1.5px;background:currentColor;transform:rotate(-45deg);top:6px;left:0}.play-button{font-size:0;display:grid;place-items:center;height:24px}.play-button:before{content:'';border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid currentColor}.play-button.is-playing:before{height:14px;width:10px;border-top:0;border-bottom:0;border-left:3px solid currentColor;border-right:3px solid currentColor}

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
<div class="room-grid"><div class="watch-column"><div class="video-shell"><div id="player"></div><div id="empty-player" class="empty-player"><div class="empty-symbol">▶</div><span class="eyebrow">ЛУЧШЕ, КОГДА ВМЕСТЕ</span><h2>С чего начнём вечер?</h2><p>Добавьте ссылку на YouTube ниже.<br>Попкорн — по желанию.</p></div></div><div class="player-controls"><button id="play" class="play-button" disabled aria-label="Воспроизвести для всех">▶</button><span id="position">0:00</span><input type="range" id="seek" min="0" max="100" step="0.1" value="0" aria-label="Позиция видео для всех" disabled><span id="duration">0:00</span><button id="enable-sound" class="sound-button" disabled>Включить у меня</button></div><p id="player-status" class="player-status" role="status">Управление общее: пауза и перемотка меняются у всех.</p><form id="video-form" class="video-form"><label for="video-url">Что посмотрим?</label><div><span class="youtube-icon">▶</span><input id="video-url" type="text" maxlength="2048" placeholder="Вставьте ссылку на YouTube" required aria-label="Ссылка на видео YouTube"><button class="primary" type="submit">Смотреть <span>↗</span></button></div></form><div class="room-tip"><span>✧</span><p><b>У каждого свой экран. Момент — общий.</b><br>Отправьте ссылку на зал тому, с кем хочется разделить этот вечер.</p><span class="tip-heart">♡</span></div></div>
<aside class="chat-panel"><div class="chat-header"><h2>Чатик <span>✦</span></h2><span id="member-count">0 в зале</span></div><div id="members" class="members"></div><div id="messages" class="messages" role="log" aria-live="polite" aria-label="Сообщения чата"><div id="chat-welcome" class="chat-welcome"><span>☕</span><h3>Устраивайтесь поудобнее</h3><p>Здесь можно шептаться.<br>Никто не попросит потише.</p></div></div><form id="chat-form" class="chat-form"><textarea id="chat-text" rows="1" maxlength="1000" placeholder="Сказать что-нибудь…" aria-label="Сообщение" required></textarea><button type="submit" title="Отправить" aria-label="Отправить сообщение">↑</button></form><span class="chat-hint">Enter — отправить · Shift + Enter — новая строка</span></aside></div>
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
        const response = await fetch('index.php?api=1', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action, room:roomId, after:cursor, ...data}), signal:controller.signal});
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
        meta.append(name, time); el.append(meta, body); messages.append(el); cursor = Math.max(cursor, Number(m.id));
    }
    while (messages.children.length > 300) messages.firstChild.remove();
    if (nearBottom) messages.scrollTop = messages.scrollHeight;
    $('empty-player').hidden = Boolean(data.state.video);
    if (data.state.video) ensurePlayer();
    applyPlayback(); renderControls();
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
    if (!playerReady || !current?.state.video) return;
    const s = current.state, desired = targetPosition();
    if (video !== s.video) {
        video = s.video; playerError = false;
        $('player-status').textContent = 'Видео добавлено. Нажмите ▶, чтобы начать вместе.';
        player.cueVideoById({videoId:video, startSeconds:desired});
    }
    if (playerError) return;
    const state = player.getPlayerState();
    if (state === -1 || state === 5) {
        if (s.playing) player.playVideo();
        if (Math.abs((player.getCurrentTime() || 0) - desired) > 1.5) player.seekTo(desired, true);
        return;
    }
    const duration = player.getDuration();
    if (duration && desired >= duration - 0.3) { if (state === 1) player.pauseVideo(); return; }
    if (Math.abs((player.getCurrentTime() || 0) - desired) > 1.5) player.seekTo(desired, true);
    if (s.playing && state !== 1 && state !== 3) player.playVideo();
    if (!s.playing && (state === 1 || state === 3)) player.pauseVideo();
}
function formatTime(seconds) { seconds = Math.floor(Math.max(0, seconds || 0)); const h = Math.floor(seconds/3600); const m = Math.floor(seconds/60)%60; return (h ? `${h}:${String(m).padStart(2,'0')}` : String(m)) + ':' + String(seconds%60).padStart(2,'0'); }
function renderControls() {
    const stale = !lastGood || performance.now() - lastGood > 7000;
    if (stale && joined) { $('connection').textContent = 'Переподключаемся…'; $('connection').classList.add('offline'); }
    const hasVideo = Boolean(current?.state.video);
    $('play').disabled = !hasVideo || stale || busy;
    $('seek').disabled = !hasVideo || !playerReady || !player?.getDuration() || stale || busy;
    $('enable-sound').disabled = !hasVideo;
    const duration = playerReady ? player.getDuration() : 0;
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
        if ([401,403,404].includes(e.status)) { joined = false; clearTimeout(timer); toast(e.message); $('lobby').hidden = false; $('room').hidden = true; if (playerReady) player.pauseVideo(); }
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
    try { accept(await api('control', {op, version:current.state.version, ...extra})); return true; }
    catch (e) { toast(e.message); if (e.status === 409) { try { accept(await api('state')); } catch {} } return false; }
    finally { busy = false; renderControls(); }
}
$('video-form').addEventListener('submit', async e => {
    e.preventDefault(); if (busy) return;
    const input = $('video-url'), value = input.value;
    const btn = e.currentTarget.querySelector('button'); btn.disabled = true;
    try { if (await control('load', {url:value.trim()}) && input.value === value) input.value = ''; } finally { btn.disabled = false; }
});
$('play').addEventListener('click', async () => {
    if (playerReady) { player.unMute(); if (!current.state.playing) player.playVideo(); }
    const duration = playerReady ? player.getDuration() : 0;
    if (duration && targetPosition() >= duration - .3) { await control('replay'); }
    else await control(current.state.playing ? 'pause' : 'play');
});
$('seek').addEventListener('change', () => control('seek', {position:Number($('seek').value)}));
$('enable-sound').addEventListener('click', () => { if (!playerReady) { ensurePlayer(true); return; } if (playerError) { video = null; playerError = false; applyPlayback(); } player.unMute(); player.setVolume(100); if (current.state.playing) player.playVideo(); $('player-status').textContent = 'Звук включён на этом устройстве. Кнопка ▶ запускает видео для всех.'; });
$('chat-form').addEventListener('submit', async e => {
    e.preventDefault(); const input = $('chat-text'), body = input.value.trim(); if (!body) return;
    const btn = e.currentTarget.querySelector('button'); if (btn.disabled) return; btn.disabled = true;
    try { accept(await api('message', {body})); if (input.value.trim() === body) input.value = ''; $('messages').scrollTop = $('messages').scrollHeight; }
    catch(e) { toast(e.message); } finally { btn.disabled = false; input.focus(); }
});
$('chat-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('chat-form').requestSubmit(); } });
$('invite').addEventListener('click', () => { $('invite-url').value = location.href; $('copy-status').textContent = ''; $('invite-dialog').showModal(); });
$('copy-link').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('invite-url').value); $('copy-status').textContent = 'Ссылка скопирована. Отправьте её лично ♡'; } catch { $('invite-url').select(); $('copy-status').textContent = 'Выделили ссылку — скопируйте её вручную.'; } });
$('leave').addEventListener('click', async () => { if (busy) return; busy = true; try { await api('leave'); joined = false; clearTimeout(timer); if (playerReady) player.pauseVideo(); location.assign(location.pathname); } catch(e) { toast(e.message); busy = false; } });
document.addEventListener('visibilitychange', () => { if (!document.hidden && joined) { clearTimeout(timer); poll(); } });
window.addEventListener('online', () => { if (joined) { clearTimeout(timer); poll(); } });
setInterval(renderControls, 500);

</script>
</body></html>
