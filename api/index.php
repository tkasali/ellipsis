<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* ───────── transport ───────── */
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($o = cfg('allowed_origin')) {
  header('Access-Control-Allow-Origin: ' . $o);
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

set_exception_handler(function (Throwable $e) {
  error_log('[ellipsis] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  fail('Server error', 500);
});

$M = $_SERVER['REQUEST_METHOD'];
$path = trim((string)($_GET['r'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
$path = preg_replace('#^(.*?/)?api/?#', '', $path);
$P = $path === '' ? [] : explode('/', $path);
$route = $M . ' ' . preg_replace('/\b\d+\b/', ':id', $path);
$ID = (int)(array_values(array_filter($P, 'ctype_digit'))[0] ?? 0);
$ID2 = (int)(array_values(array_filter($P, 'ctype_digit'))[1] ?? 0);

$INSTALLED = is_file(cfg('data_dir') . '/.installed');
if (!$INSTALLED && $route !== 'GET setup' && $route !== 'GET health') fail('Not installed — visit /api/setup?key=YOUR_SETUP_KEY', 503, ['installed' => false]);

switch ($route) {

/* ───────── install ───────── */
case 'GET setup': {
  if (cfg('secret') === 'CHANGE-ME-to-a-long-random-string' || cfg('setup_key') === 'CHANGE-ME-setup-key') fail('Edit api/config.php: set secret and setup_key first', 412);
  if (!hash_equals((string)cfg('setup_key'), (string)($_GET['key'] ?? ''))) fail('Wrong setup key', 403);
  @mkdir(cfg('data_dir'), 0750, true); @mkdir(cfg('upload_dir'), 0750, true);
  @file_put_contents(cfg('data_dir') . '/.htaccess', "Require all denied\n");
  migrate();
  $seeded = 0;
  if (!(int)val('SELECT COUNT(*) FROM users')) { require __DIR__ . '/seed.php'; $seeded = seed_all(); }
  file_put_contents(cfg('data_dir') . '/.installed', (string)now());
  out(['ok' => true, 'driver' => is_sqlite() ? 'sqlite' : 'mysql', 'seeded_users' => $seeded,
       'next' => 'Delete nothing. Register at /app/ — emails listed in admin_emails become admins.']);
}

case 'GET health':
  out(['ok' => true, 'installed' => $INSTALLED, 'service' => 'ellipsis', 'version' => '1.0.0', 'time' => now(), 'driver' => is_sqlite() ? 'sqlite' : 'mysql',
       'providers' => ['translate' => (bool)cfg('deepl_key'), 'payouts' => cfg('stripe_key') ? 'stripe' : 'manual', 'turnstile' => (bool)cfg('turnstile_secret')]]);

/* ───────── human verification ───────── */
case 'POST human/challenge': {
  limit('challenge', 20, 600);
  $id = bin2hex(random_bytes(16)); $bpm = random_int(84, 118);
  q('INSERT INTO challenges (id, bpm, created, ip) VALUES (?,?,?,?)', [$id, $bpm, now(), ip()]);
  out(['ok' => true, 'id' => $id, 'bpm' => $bpm, 'taps' => 4]);
}
case 'POST human/verify': {
  limit('verify', 12, 600);
  $c = one('SELECT * FROM challenges WHERE id = ?', [str_in('id', 40, true)]);
  if (!$c || $c['used']) fail('Challenge expired — start again', 410);
  q('UPDATE challenges SET used = 1 WHERE id = ?', [$c['id']]);
  $age = now() - (int)$c['created'];
  if ($age < 1 || $age > 120) fail('Challenge expired — start again', 410);
  $taps = array_map('floatval', (array)(body()['taps'] ?? []));
  if (count($taps) < 4 || count($taps) > 12) fail('Tap four times on the beat', 422);
  $gaps = []; for ($i = 1; $i < count($taps); $i++) $gaps[] = $taps[$i] - $taps[$i - 1];
  $target = 60000 / (int)$c['bpm'];
  $mean = array_sum($gaps) / count($gaps);
  $sd = sqrt(array_sum(array_map(fn($g) => ($g - $mean) ** 2, $gaps)) / count($gaps));
  // Humans land near the pulse with a few ms of natural jitter. Scripts are either perfectly even or nowhere near it.
  $onBeat = abs($mean - $target) / $target < 0.24;
  $humanJitter = $sd > 3.5 && $sd < $target * 0.34;
  if (cfg('turnstile_secret')) {
    [$code, $r] = http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => cfg('turnstile_secret'), 'response' => str_in('turnstile', 2048), 'remoteip' => ip()]);
    if (!($r['success'] ?? false)) fail('Verification failed', 403);
  }
  if (!$onBeat || !$humanJitter) { audit(null, 'human_fail', ['mean' => round($mean), 'sd' => round($sd, 1), 'target' => round($target)]); fail($onBeat ? 'Too even to be a person — tap along naturally' : 'Off the beat — listen to the pulse and try again', 403); }
  $tok = bin2hex(random_bytes(24));
  q('INSERT INTO human_tokens (token, created) VALUES (?,?)', [$tok, now()]);
  out(['ok' => true, 'human_token' => $tok, 'expires_in' => 600]);
}

/* ───────── accounts ───────── */
case 'POST auth/register': {
  limit('register', 6, 3600);
  consume_human(str_in('human_token', 64, true));
  $email = strtolower(str_in('email', 191, true));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email', 422);
  $pw = (string)(body()['password'] ?? '');
  if (strlen($pw) < 10) fail('Use at least 10 characters for your password', 422);
  if (!(body()['age_ok'] ?? false)) fail('Confirm you are 13 or older and accept the terms', 422);
  $name = str_in('name', 120, true);
  $handle = strtolower(preg_replace('/[^a-z0-9_]/i', '', str_in('handle', 40) ?: $name));
  if (strlen($handle) < 3) fail('Choose a handle with at least 3 letters', 422);
  if (val('SELECT 1 FROM users WHERE email = ?', [$email])) fail('That email already has an account', 409);
  if (val('SELECT 1 FROM users WHERE handle = ?', [$handle])) $handle .= random_int(10, 99);
  $role = in_array($email, cfg('admin_emails'), true) ? 'admin' : 'user';
  q('INSERT INTO users (email, pass, name, handle, place, cc, tz, lang, roles, bio, role, human_at, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
    $email, password_hash($pw, PASSWORD_DEFAULT), $name, $handle, str_in('place', 80), strtoupper(str_in('cc', 4)),
    int_in('tz', -12, 14, 0), str_in('lang', 8) ?: 'en', str_in('roles', 255), str_in('bio', 500), $role, now(), now()]);
  $uid = lastid(); audit($uid, 'register');
  out(['ok' => true, 'token' => new_session($uid), 'user' => me_payload($uid)], 201);
}
case 'POST auth/login': {
  limit('login', 10, 900);
  $u = one('SELECT * FROM users WHERE email = ?', [strtolower(str_in('email', 191, true))]);
  if (!$u || !password_verify((string)(body()['password'] ?? ''), $u['pass'])) fail('Email or password is wrong', 401);
  if ($u['status'] !== 'active') fail('This account is suspended', 403);
  if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) q('UPDATE users SET pass = ? WHERE id = ?', [password_hash((string)body()['password'], PASSWORD_DEFAULT), $u['id']]);
  audit((int)$u['id'], 'login');
  out(['ok' => true, 'token' => new_session((int)$u['id']), 'user' => me_payload((int)$u['id'])]);
}
case 'POST auth/logout': { if ($t = bearer()) q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $t)]); out(['ok' => true]); }
case 'GET me': out(['ok' => true, 'user' => me_payload((int)user()['id'])]);
case 'DELETE me': {
  $u = user();
  // Rights records (signed splits, published releases, payouts, reports) are retained; personal data is removed.
  q('UPDATE users SET email = ?, name = ?, handle = ?, pass = ?, bio = NULL, status = ? WHERE id = ?', ['deleted-' . $u['id'] . '@invalid', 'Deleted user', 'deleted' . $u['id'], '!', 'deleted', $u['id']]);
  q('DELETE FROM sessions WHERE user_id = ?', [$u['id']]); q('DELETE FROM messages WHERE user_id = ?', [$u['id']]); q('DELETE FROM follows WHERE follower_id = ? OR followee_id = ?', [$u['id'], $u['id']]);
  audit((int)$u['id'], 'account_deleted'); out(['ok' => true]);
}

/* ───────── people ───────── */
case 'GET users/:id': {
  $u = one('SELECT * FROM users WHERE id = ? AND status = ?', [$ID, 'active']) ?? fail('Not found', 404);
  $me = user(false);
  out(['ok' => true, 'user' => user_card($u) + [
    'bio' => $u['bio'], 'roles' => array_filter(explode(',', (string)$u['roles'])),
    'followers' => (int)val('SELECT COUNT(*) FROM follows WHERE followee_id = ?', [$ID]),
    'following' => (int)val('SELECT COUNT(*) FROM follows WHERE follower_id = ?', [$ID]),
    'credits' => (int)val('SELECT COUNT(*) FROM contributors WHERE user_id = ? AND removed = 0', [$ID]),
    'youFollow' => $me ? (bool)val('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?', [$me['id'], $ID]) : false,
  ], 'tracks' => array_map(fn($t) => track_card($t, $me['id'] ?? null), all('SELECT t.* FROM tracks t JOIN contributors c ON c.track_id = t.id WHERE c.user_id = ? AND c.removed = 0 ORDER BY t.created DESC LIMIT 24', [$ID]))]);
}
case 'POST users/:id/follow': {
  $u = user(); if ($ID === (int)$u['id']) fail('You cannot follow yourself', 422);
  if (val('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?', [$u['id'], $ID])) { q('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?', [$u['id'], $ID]); out(['ok' => true, 'following' => false]); }
  q('INSERT INTO follows (follower_id, followee_id, created) VALUES (?,?,?)', [$u['id'], $ID, now()]); out(['ok' => true, 'following' => true]);
}
case 'GET search': {
  $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string)($_GET['q'] ?? ''))) . '%';
  if (strlen($s) < 4) out(['ok' => true, 'people' => [], 'tracks' => []]);
  out(['ok' => true,
    'people' => array_map('user_card', all('SELECT * FROM users WHERE status = ? AND (name LIKE ? OR handle LIKE ? OR place LIKE ? OR roles LIKE ?) LIMIT 8', ['active', $s, $s, $s, $s])),
    'tracks' => array_map(fn($t) => track_card($t), all('SELECT * FROM tracks WHERE title LIKE ? OR genre LIKE ? OR like_ref LIKE ? OR mood LIKE ? LIMIT 8', [$s, $s, $s, $s]))]);
}

/* ───────── tracks, feed, charts ───────── */
case 'GET tracks': {
  $me = user(false); $f = (string)($_GET['filter'] ?? 'all'); $w = []; $p = [];
  if ($f === 'open') $w[] = "state IN ('open','seed')";
  if ($f === 'released') $w[] = "state = 'released'";
  if ($like = trim((string)($_GET['like'] ?? ''))) { $w[] = 'like_ref = ?'; $p[] = $like; }
  $lim = min(60, max(1, (int)($_GET['limit'] ?? 46)));
  $rows = all('SELECT * FROM tracks' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY created DESC LIMIT ' . $lim, $p);
  out(['ok' => true, 'tracks' => array_map(fn($t) => track_card($t, $me['id'] ?? null), $rows)]);
}
case 'GET charts': {
  $days = ['week' => 7, 'year' => 365, 'all' => 36500][$_GET['period'] ?? 'week'] ?? 7;
  $kind = ($_GET['kind'] ?? 'song') === 'instrumental' ? 'instrumental' : 'song';
  $p = [intdiv(now(), 86400) - $days, $kind]; $w = '';
  if ($like = trim((string)($_GET['like'] ?? ''))) { $w = ' AND t.like_ref = ?'; $p[] = $like; }
  $rows = all("SELECT t.*, (SELECT COUNT(*) FROM spikes s WHERE s.track_id = t.id AND s.day >= ?) + t.spike_base AS score
               FROM tracks t WHERE t.state = 'released' AND t.kind = ?$w ORDER BY score DESC LIMIT 20", $p);
  out(['ok' => true, 'chart' => array_map(fn($t) => track_card($t) + ['score' => (int)$t['score']], $rows)]);
}
case 'POST tracks': {
  $u = user(); limit('create', 20, 3600);
  $needs = array_slice(array_map(fn($x) => mb_substr((string)$x, 0, 40), (array)(body()['needs'] ?? [])), 0, 4);
  q('INSERT INTO tracks (owner_id, title, genre, mood, like_ref, bpm, music_key, state, needs, offer_pct, kind, file, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
    $u['id'], str_in('title', 160, true), str_in('genre', 40), str_in('mood', 40), str_in('like', 80), int_in('bpm', 40, 220, 90),
    str_in('key', 16), $needs ? 'open' : 'seed', json_encode($needs), int_in('offer_pct', 0, 60, 0), str_in('kind', 16) === 'instrumental' ? 'instrumental' : 'song',
    preg_replace('/[^a-z]/', '', str_in('file', 20)) ?: 'lofi', now()]);
  $tid = lastid();
  q('INSERT INTO contributors (track_id, user_id, pct, role, joined) VALUES (?,?,?,?,?)', [$tid, $u['id'], 100, 'Owner', now()]);
  q('INSERT INTO versions (track_id, user_id, label, note, created) VALUES (?,?,?,?,?)', [$tid, $u['id'], 'v1', 'Started from a starter pack', now()]);
  audit((int)$u['id'], 'track_create', ['track' => $tid]);
  out(['ok' => true, 'track' => track_card(track_or_404($tid), (int)$u['id'])], 201);
}
case 'POST tracks/:id/spike': {
  $u = user(); track_or_404($ID); limit('spike', 120, 3600); $day = intdiv(now(), 86400);
  if (val('SELECT 1 FROM spikes WHERE track_id = ? AND user_id = ? AND day = ?', [$ID, $u['id'], $day])) { q('DELETE FROM spikes WHERE track_id = ? AND user_id = ? AND day = ?', [$ID, $u['id'], $day]); $on = false; }
  else { q('INSERT INTO spikes (track_id, user_id, day, created) VALUES (?,?,?,?)', [$ID, $u['id'], $day, now()]); $on = true; }
  out(['ok' => true, 'spiked' => $on, 'spikes' => track_card(track_or_404($ID))['spikes']]);
}
case 'POST tracks/:id/join': {
  $u = user(); $t = track_or_404($ID);
  if (!in_array($t['state'], ['open', 'seed', 'building'], true)) fail('This track is released — send an offer instead', 409);
  q(upsert_ignore() . ' INTO contributors (track_id, user_id, pct, role, joined) VALUES (?,?,?,?,?)', [$ID, $u['id'], 0, 'Contributor', now()]);
  q('UPDATE contributors SET removed = 0 WHERE track_id = ? AND user_id = ?', [$ID, $u['id']]);
  q("UPDATE tracks SET state = 'building' WHERE id = ? AND state IN ('open','seed')", [$ID]);
  reset_signatures($ID); audit((int)$u['id'], 'join', ['track' => $ID]);
  out(['ok' => true, 'joined' => true]);
}
case 'POST plays': {
  limit('plays', 600, 3600); $u = user(false); $tid = int_in('track_id', 1, PHP_INT_MAX);
  q('INSERT INTO plays (track_id, user_id, premium, ms, created) VALUES (?,?,?,?,?)', [$tid, $u['id'] ?? null, (int)($u['premium'] ?? 0), int_in('ms', 0, 3600000, 0), now()]);
  if (!($u['premium'] ?? 0)) q('INSERT INTO ad_impressions (track_id, user_id, cpm_micros, created) VALUES (?,?,?,?)', [$tid, $u['id'] ?? null, (int)cfg('default_cpm'), now()]);
  out(['ok' => true]);
}

/* ───────── the collaboration room ───────── */
case 'GET rooms/:id': {
  $u = user(); $t = track_or_404($ID); $since = (int)($_GET['since'] ?? 0);
  q('DELETE FROM presence WHERE seen < ?', [now() - 600]);
  $msgs = all('SELECT m.id, m.text, m.lang, m.translated, m.created, m.user_id, u.name, u.place FROM messages m JOIN users u ON u.id = m.user_id WHERE m.track_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200', [$ID, $since]);
  out(['ok' => true, 'track' => track_card($t, (int)$u['id']),
    'member' => is_member($ID, (int)$u['id']), 'isOwner' => (int)$t['owner_id'] === (int)$u['id'],
    'messages' => array_map(fn($m) => ['id' => (int)$m['id'], 'me' => (int)$m['user_id'] === (int)$u['id'], 'who' => $m['name'] . ' · ' . $m['place'], 'text' => $m['translated'] ?: $m['text'], 'orig' => $m['translated'] ? $m['text'] : null, 'lang' => $m['lang'], 'at' => (int)$m['created']], $msgs),
    'presence' => array_map(fn($p) => ['user' => user_card($p), 'lane' => (int)$p['lane'], 'pos' => (float)$p['pos'], 'voice' => (bool)$p['voice'], 'speaking' => (bool)$p['speaking']],
      all('SELECT p.lane, p.pos, p.voice, p.speaking, u.* FROM presence p JOIN users u ON u.id = p.user_id WHERE p.track_id = ? AND p.seen > ?', [$ID, now() - 20])),
    'splits' => array_map(fn($c) => ['userId' => (int)$c['user_id'], 'name' => $c['name'], 'place' => $c['place'], 'pct' => (int)$c['pct'], 'signed' => (bool)$c['signed'], 'removed' => (bool)$c['removed']], contributors($ID)),
    'stems' => all('SELECT s.id, s.name, s.version, s.hold, s.bytes, s.created, u.name AS owner FROM stems s JOIN users u ON u.id = s.user_id WHERE s.track_id = ? ORDER BY s.id', [$ID]),
    'offers' => all("SELECT o.id, o.note, o.ask_pct, o.created, u.name, u.place FROM offers o JOIN users u ON u.id = o.user_id WHERE o.track_id = ? AND o.status = 'pending'", [$ID]),
    'branches' => all("SELECT b.id, b.name, b.from_version, u.name AS by_name FROM branches b JOIN users u ON u.id = b.user_id WHERE b.track_id = ? AND b.status = 'open'", [$ID]),
    'versions' => all('SELECT v.label, v.note, v.created, u.name FROM versions v JOIN users u ON u.id = v.user_id WHERE v.track_id = ? ORDER BY v.id DESC', [$ID]),
    'now' => now()]);
}
case 'POST rooms/:id/presence': {
  $u = user(); track_or_404($ID);
  $args = [int_in('lane', -1, 64, -1), max(0, min(1, (float)(body()['pos'] ?? 0))), (int)(bool)(body()['voice'] ?? 0), (int)(bool)(body()['speaking'] ?? 0), now(), $ID, $u['id']];
  $upd = q('UPDATE presence SET lane = ?, pos = ?, voice = ?, speaking = ?, seen = ? WHERE track_id = ? AND user_id = ?', $args);
  if (!$upd->rowCount()) q('INSERT INTO presence (lane, pos, voice, speaking, seen, track_id, user_id) VALUES (?,?,?,?,?,?,?)', $args);
  out(['ok' => true]);
}
case 'POST rooms/:id/messages': {
  $u = user(); track_or_404($ID); limit('chat', 40, 60);
  if (!is_member($ID, (int)$u['id'])) fail('Join the track to talk in the room', 403);
  $text = str_in('text', 2000, true); $lang = str_in('lang', 8) ?: (string)$u['lang'];
  $tr = null;
  if (cfg('deepl_key') && $lang !== 'en') {
    [$code, $r] = http_post('https://api-free.deepl.com/v2/translate', ['text' => $text, 'target_lang' => 'EN'], ['Authorization: DeepL-Auth-Key ' . cfg('deepl_key')]);
    $tr = $r['translations'][0]['text'] ?? null;
  }
  q('INSERT INTO messages (track_id, user_id, text, lang, translated, created) VALUES (?,?,?,?,?,?)', [$ID, $u['id'], $text, $lang, $tr, now()]);
  out(['ok' => true, 'id' => lastid()], 201);
}
case 'POST rooms/:id/stems': {
  $u = user(); track_or_404($ID); limit('upload', 30, 3600);
  if (!is_member($ID, (int)$u['id'])) fail('Join the track before adding stems', 403);
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Upload failed', 422);
  if ($f['size'] > cfg('max_upload')) fail('Stems are limited to ' . (cfg('max_upload') >> 20) . ' MB', 413);
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
  if (!in_array($mime, cfg('allowed_audio'), true)) fail('That file is not audio we accept (' . $mime . ')', 415);
  $sha = hash_file('sha256', $f['tmp_name']);
  // exact-duplicate fingerprint: the same bytes already exist under someone else's name → hold for review
  $dupe = one('SELECT s.track_id, s.user_id FROM stems s WHERE s.sha256 = ? AND s.user_id <> ? LIMIT 1', [$sha, $u['id']]);
  @mkdir(cfg('upload_dir') . '/' . $ID, 0750, true);
  $dest = cfg('upload_dir') . '/' . $ID . '/' . $sha . '.' . (explode('/', $mime)[1] ?? 'bin');
  if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Could not store the file', 500);
  $n = (int)val('SELECT COUNT(*) FROM versions WHERE track_id = ?', [$ID]) + 1;
  q('INSERT INTO stems (track_id, user_id, name, path, mime, bytes, sha256, version, hold, created) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$ID, $u['id'], mb_substr(str_in('name', 120) ?: pathinfo($f['name'], PATHINFO_FILENAME), 0, 120), $dest, $mime, (int)$f['size'], $sha, 'v' . $n, $dupe ? 1 : 0, now()]);
  $sid = lastid();
  q('INSERT INTO versions (track_id, user_id, label, note, created) VALUES (?,?,?,?,?)', [$ID, $u['id'], 'v' . $n, 'Added a stem', now()]);
  if ($dupe) q('INSERT INTO reports (reporter_id, target_type, target_id, reason, note, created) VALUES (?,?,?,?,?,?)', [0, 'stem', $sid, 'fingerprint_match', 'Identical audio exists on track ' . $dupe['track_id'], now()]);
  audit((int)$u['id'], 'stem_upload', ['track' => $ID, 'stem' => $sid, 'hold' => (bool)$dupe]);
  out(['ok' => true, 'stem' => ['id' => $sid, 'version' => 'v' . $n, 'held' => (bool)$dupe]], 201);
}
case 'GET stems/:id': {
  $u = user(); $s = one('SELECT * FROM stems WHERE id = ?', [$ID]) ?? fail('Not found', 404);
  if (!is_member((int)$s['track_id'], (int)$u['id']) && $u['role'] !== 'admin') fail('Only collaborators can download stems', 403);
  header('Content-Type: ' . $s['mime']); header('Content-Length: ' . filesize($s['path'])); header('Content-Disposition: attachment; filename="stem-' . $s['id'] . '"');
  readfile($s['path']); exit;
}
case 'PUT rooms/:id/splits': {
  $u = user(); $t = track_or_404($ID);
  if ((int)$t['owner_id'] !== (int)$u['id']) fail('Only the owner edits the split sheet', 403);
  $shares = (array)(body()['shares'] ?? []); $sum = 0;
  foreach ($shares as $sh) { $p = (int)($sh['pct'] ?? -1); if ($p < 0 || $p > 100) fail('Each share must be 0–100', 422); $sum += $p; }
  if ($sum !== 100) fail('Splits must total exactly 100% (now ' . $sum . '%)', 422);
  db()->beginTransaction();
  foreach ($shares as $sh) {
    $uid = (int)$sh['userId'];
    if (!val('SELECT 1 FROM contributors WHERE track_id = ? AND user_id = ?', [$ID, $uid])) { db()->rollBack(); fail('Everyone on the sheet must be a contributor', 422); }
    q('UPDATE contributors SET pct = ? WHERE track_id = ? AND user_id = ?', [(int)$sh['pct'], $ID, $uid]);
  }
  reset_signatures($ID); db()->commit();
  audit((int)$u['id'], 'splits_set', ['track' => $ID, 'shares' => $shares]);
  out(['ok' => true, 'signaturesReset' => true]);
}
case 'POST rooms/:id/splits/sign': {
  $u = user(); track_or_404($ID);
  if (!is_member($ID, (int)$u['id'])) fail('Only contributors sign', 403);
  if ((int)val('SELECT SUM(pct) FROM contributors WHERE track_id = ? AND removed = 0', [$ID]) !== 100) fail('The sheet does not total 100% yet', 409);
  q('UPDATE contributors SET signed = 1 WHERE track_id = ? AND user_id = ?', [$ID, $u['id']]);
  audit((int)$u['id'], 'splits_signed', ['track' => $ID]);
  out(['ok' => true, 'allSigned' => !(int)val('SELECT COUNT(*) FROM contributors WHERE track_id = ? AND removed = 0 AND signed = 0', [$ID])]);
}
case 'POST rooms/:id/offers': {
  $u = user(); $t = track_or_404($ID); limit('offer', 20, 3600);
  if (is_member($ID, (int)$u['id'])) fail('You are already on this track', 409);
  q('INSERT INTO offers (track_id, user_id, note, ask_pct, created) VALUES (?,?,?,?,?)', [$ID, $u['id'], str_in('note', 300, true), int_in('ask_pct', 1, 60), now()]);
  out(['ok' => true, 'id' => lastid()], 201);
}
case 'POST offers/:id/accept':
case 'POST offers/:id/decline': {
  $u = user(); $o = one('SELECT * FROM offers WHERE id = ?', [$ID]) ?? fail('Not found', 404);
  $t = track_or_404((int)$o['track_id']);
  if ((int)$t['owner_id'] !== (int)$u['id']) fail('Only the owner decides on offers', 403);
  if ($o['status'] !== 'pending') fail('Already decided', 409);
  $accept = str_ends_with($route, 'accept');
  q('UPDATE offers SET status = ? WHERE id = ?', [$accept ? 'accepted' : 'declined', $ID]);
  if ($accept) {
    // the owner gives up the asked share; every signature resets because the sheet changed
    $ownerPct = (int)val('SELECT pct FROM contributors WHERE track_id = ? AND user_id = ?', [$t['id'], $t['owner_id']]);
    if ($ownerPct < (int)$o['ask_pct']) fail('Not enough unassigned share — rebalance the sheet first', 409);
    q('UPDATE contributors SET pct = pct - ? WHERE track_id = ? AND user_id = ?', [$o['ask_pct'], $t['id'], $t['owner_id']]);
    q(upsert_ignore() . ' INTO contributors (track_id, user_id, pct, role, joined) VALUES (?,?,?,?,?)', [$t['id'], $o['user_id'], 0, 'Contributor', now()]);
    q('UPDATE contributors SET pct = ?, removed = 0 WHERE track_id = ? AND user_id = ?', [$o['ask_pct'], $t['id'], $o['user_id']]);
    reset_signatures((int)$t['id']);
  }
  audit((int)$u['id'], $accept ? 'offer_accept' : 'offer_decline', ['offer' => $ID]);
  out(['ok' => true, 'signaturesReset' => $accept]);
}
case 'POST rooms/:id/branches': {
  $u = user(); track_or_404($ID); if (!is_member($ID, (int)$u['id'])) fail('Join first', 403);
  $from = (string)(val('SELECT label FROM versions WHERE track_id = ? ORDER BY id DESC LIMIT 1', [$ID]) ?? 'v1');
  q('INSERT INTO branches (track_id, user_id, name, from_version, created) VALUES (?,?,?,?,?)', [$ID, $u['id'], str_in('name', 120, true), $from, now()]);
  out(['ok' => true, 'id' => lastid(), 'from' => $from], 201);
}
case 'POST branches/:id/merge':
case 'POST branches/:id/discard': {
  $u = user(); $b = one('SELECT * FROM branches WHERE id = ?', [$ID]) ?? fail('Not found', 404);
  if (!is_member((int)$b['track_id'], (int)$u['id'])) fail('Join first', 403);
  $merge = str_ends_with($route, 'merge');
  q('UPDATE branches SET status = ? WHERE id = ?', [$merge ? 'merged' : 'discarded', $ID]);
  if ($merge) { $n = (int)val('SELECT COUNT(*) FROM versions WHERE track_id = ?', [$b['track_id']]) + 1;
    q('INSERT INTO versions (track_id, user_id, label, note, created) VALUES (?,?,?,?,?)', [$b['track_id'], $u['id'], 'v' . $n, 'Merged “' . $b['name'] . '”', now()]); }
  out(['ok' => true]);
}
case 'POST tracks/:id/publish': {
  $u = user(); $t = track_or_404($ID);
  if ((int)$t['owner_id'] !== (int)$u['id']) fail('Only the owner publishes', 403);
  consume_human(str_in('human_token', 64, true));
  $tags = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 32), (array)(body()['tags'] ?? [])))));
  $decl = (array)(body()['declarations'] ?? []);
  $problems = [];
  if (count($tags) !== 5) $problems[] = 'Exactly five tags';
  if (count(array_filter($decl)) < 4) $problems[] = 'All four rights declarations';
  if ((int)val('SELECT SUM(pct) FROM contributors WHERE track_id = ? AND removed = 0', [$ID]) !== 100) $problems[] = 'Splits totalling 100%';
  if ((int)val('SELECT COUNT(*) FROM contributors WHERE track_id = ? AND removed = 0 AND signed = 0', [$ID])) $problems[] = 'Every contributor signed';
  if ((int)val('SELECT COUNT(*) FROM stems WHERE track_id = ? AND hold = 1', [$ID])) $problems[] = 'No stems held by fingerprint review';
  if ($problems) fail('Not ready to publish', 409, ['missing' => $problems]);
  q("UPDATE tracks SET state = 'released', tags = ?, published = ? WHERE id = ?", [json_encode($tags), now(), $ID]);
  audit((int)$u['id'], 'publish', ['track' => $ID, 'tags' => $tags, 'declarations' => $decl, 'ip' => ip()]);
  out(['ok' => true, 'track' => track_card(track_or_404($ID), (int)$u['id'])]);
}

/* ───────── money ───────── */
case 'GET earnings': {
  $u = user(); $cur = strtoupper((string)($_GET['currency'] ?? 'USD')); $fx = cfg('fx')[$cur] ?? null; if ($fx === null) fail('Unsupported currency', 422);
  $rows = ledger((int)$u['id']);
  $total = array_sum(array_column($rows, 'micros'));
  $paid = (int)val("SELECT COALESCE(SUM(amount_micros),0) FROM payouts WHERE user_id = ? AND status IN ('queued','paid')", [$u['id']]);
  out(['ok' => true, 'currency' => $cur, 'fx' => $fx,
    'rows' => array_map(fn($r) => $r + ['local' => round($r['micros'] / 1e6 * $fx, 2)], $rows),
    'earned' => round($total / 1e6 * $fx, 2), 'paid' => round($paid / 1e6 * $fx, 2), 'available' => round(max(0, $total - $paid) / 1e6 * $fx, 2),
    'payouts' => all('SELECT amount_micros, currency, local_amount, status, provider_ref, created FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']])]);
}
case 'POST payouts': {
  $u = user(); limit('payout', 5, 3600);
  $cur = strtoupper(str_in('currency', 4) ?: 'USD'); $fx = cfg('fx')[$cur] ?? fail('Unsupported currency', 422);
  $earned = array_sum(array_column(ledger((int)$u['id']), 'micros'));
  $paid = (int)val("SELECT COALESCE(SUM(amount_micros),0) FROM payouts WHERE user_id = ? AND status IN ('queued','paid')", [$u['id']]);
  $avail = $earned - $paid;
  if ($avail < cfg('payout_min')) fail('Minimum cash-out is $' . number_format(cfg('payout_min') / 1e6, 2), 409);
  $status = 'queued'; $ref = 'manual-' . bin2hex(random_bytes(4));
  if (cfg('stripe_key') && $u['payout_account']) {
    [$code, $r] = http_post('https://api.stripe.com/v1/transfers', ['amount' => intdiv($avail, 10000), 'currency' => 'usd', 'destination' => $u['payout_account'], 'metadata[user]' => $u['id']], ['Authorization: Bearer ' . cfg('stripe_key')]);
    if ($code >= 200 && $code < 300) { $status = 'paid'; $ref = (string)($r['id'] ?? $ref); } else fail('The payout provider declined the transfer', 502);
  }
  q('INSERT INTO payouts (user_id, amount_micros, currency, local_amount, status, provider_ref, created) VALUES (?,?,?,?,?,?,?)', [$u['id'], $avail, $cur, round($avail / 1e6 * $fx, 2), $status, $ref, now()]);
  audit((int)$u['id'], 'payout', ['micros' => $avail, 'status' => $status]);
  out(['ok' => true, 'status' => $status, 'amount' => round($avail / 1e6 * $fx, 2), 'currency' => $cur, 'ref' => $ref], 201);
}
case 'POST premium': {
  // Store builds must use StoreKit / Play Billing, and the web needs a verified checkout webhook.
  // Until one is configured this only works when dev_premium is switched on (for testing), or for admins.
  $u = user();
  if (!cfg('dev_premium') && $u['role'] !== 'admin') fail('Premium is purchased through the App Store, Google Play or web checkout', 402);
  q('UPDATE users SET premium = 1 WHERE id = ?', [$u['id']]); audit((int)$u['id'], 'premium_on'); out(['ok' => true, 'premium' => true]);
}

/* ───────── safety ───────── */
case 'POST reports': {
  $u = user(); limit('report', 20, 3600);
  $type = str_in('target_type', 20, true); if (!in_array($type, ['track', 'user', 'message', 'stem'], true)) fail('Unknown target', 422);
  q('INSERT INTO reports (reporter_id, target_type, target_id, reason, note, created) VALUES (?,?,?,?,?,?)', [$u['id'], $type, int_in('target_id', 1, PHP_INT_MAX), str_in('reason', 60, true), str_in('note', 500), now()]);
  out(['ok' => true, 'id' => lastid(), 'sla' => 'A human reviews reports within 24 hours'], 201);
}
case 'GET admin/stats': {
  admin(); $d = now() - 86400;
  out(['ok' => true, 'users' => (int)val('SELECT COUNT(*) FROM users'), 'active24h' => (int)val('SELECT COUNT(DISTINCT user_id) FROM presence WHERE seen > ?', [$d]),
    'tracks' => (int)val('SELECT COUNT(*) FROM tracks'), 'released' => (int)val("SELECT COUNT(*) FROM tracks WHERE state = 'released'"),
    'plays24h' => (int)val('SELECT COUNT(*) FROM plays WHERE created > ?', [$d]), 'adRevenue24h' => round((int)val('SELECT COALESCE(SUM(cpm_micros),0) FROM ad_impressions WHERE created > ?', [$d]) / 1000 / 1e6, 2),
    'openReports' => (int)val("SELECT COUNT(*) FROM reports WHERE status = 'open'"), 'humanFails24h' => (int)val("SELECT COUNT(*) FROM audit WHERE action = 'human_fail' AND created > ?", [$d])]);
}
case 'GET admin/reports': { admin(); out(['ok' => true, 'reports' => all("SELECT * FROM reports WHERE status = 'open' ORDER BY id DESC LIMIT 100")]); }
case 'POST admin/reports/:id/resolve': {
  $a = admin(); $r = one('SELECT * FROM reports WHERE id = ?', [$ID]) ?? fail('Not found', 404);
  $action = str_in('action', 20, true);
  if ($action === 'remove' && $r['target_type'] === 'track') q("UPDATE tracks SET state = 'removed' WHERE id = ?", [$r['target_id']]);
  if ($action === 'remove' && $r['target_type'] === 'message') q('DELETE FROM messages WHERE id = ?', [$r['target_id']]);
  if ($action === 'clear' && $r['target_type'] === 'stem') q('UPDATE stems SET hold = 0 WHERE id = ?', [$r['target_id']]);
  q('UPDATE reports SET status = ?, resolved_by = ? WHERE id = ?', [$action === 'clear' ? 'cleared' : 'actioned', $a['id'], $ID]);
  audit((int)$a['id'], 'report_resolve', ['report' => $ID, 'action' => $action]); out(['ok' => true]);
}
case 'POST admin/users/:id/suspend': {
  $a = admin(); q("UPDATE users SET status = 'suspended' WHERE id = ?", [$ID]); q('DELETE FROM sessions WHERE user_id = ?', [$ID]);
  audit((int)$a['id'], 'suspend', ['user' => $ID]); out(['ok' => true]);
}

default: fail('No such endpoint: ' . $route, 404);
}

/* ───────── helpers used above ───────── */
function consume_human(string $tok): void {
  $r = one('SELECT * FROM human_tokens WHERE token = ?', [$tok]);
  if (!$r || $r['used'] || $r['created'] < now() - 600) fail('Human check expired — tap the beat again', 403, ['needHuman' => true]);
  q('UPDATE human_tokens SET used = 1 WHERE token = ?', [$tok]);
}
function me_payload(int $uid): array {
  $u = one('SELECT * FROM users WHERE id = ?', [$uid]);
  return user_card($u) + ['email' => $u['email'], 'premium' => (bool)$u['premium'], 'role' => $u['role'], 'roles' => array_filter(explode(',', (string)$u['roles']))];
}
/* Per-track earnings: creator share of (ad revenue + that track's slice of the premium pool), times this user's split. */
function ledger(int $uid): array {
  $since = now() - 30 * 86400; $share = (float)cfg('creator_share');
  $premiumUsers = (int)val('SELECT COUNT(*) FROM users WHERE premium = 1');
  $pool = $premiumUsers * (int)cfg('premium_price') * $share;
  $premiumPlays = max(1, (int)val('SELECT COUNT(*) FROM plays WHERE premium = 1 AND created > ?', [$since]));
  $out = [];
  foreach (all('SELECT t.id, t.title, c.pct FROM contributors c JOIN tracks t ON t.id = c.track_id WHERE c.user_id = ? AND c.pct > 0', [$uid]) as $r) {
    $ads = (int)val('SELECT COALESCE(SUM(cpm_micros),0) FROM ad_impressions WHERE track_id = ? AND created > ?', [$r['id'], $since]) / 1000 * $share;
    $pp = (int)val('SELECT COUNT(*) FROM plays WHERE track_id = ? AND premium = 1 AND created > ?', [$r['id'], $since]);
    $subs = $pool * $pp / $premiumPlays;
    $plays = (int)val('SELECT COUNT(*) FROM plays WHERE track_id = ? AND created > ?', [$r['id'], $since]);
    $out[] = ['trackId' => (int)$r['id'], 'title' => $r['title'], 'share' => (int)$r['pct'], 'plays' => $plays,
              'micros' => (int)round(($ads + $subs) * (int)$r['pct'] / 100)];
  }
  usort($out, fn($a, $b) => $b['micros'] <=> $a['micros']);
  return $out;
}
