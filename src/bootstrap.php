<?php
declare(strict_types=1);
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
    $path = getenv('WATCH_DB') ?: dirname(__DIR__).'/var/watch.sqlite';
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
