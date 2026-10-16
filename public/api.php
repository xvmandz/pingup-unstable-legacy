<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__).'/src/bootstrap.php';
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
