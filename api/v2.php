<?php
/* Ellipsis API v2 — the endpoints the current web app and admin console use.
   Included from index.php for any /api/v2/... request. Everything is same-origin JSON. */
declare(strict_types=1);

const V2_PERMS = ['dash','revenue','export','users','suspend','ban','plans','moderate','terms','verify','rights','roles','audit'];
const V2_ROLES = [
  'admin'     => V2_PERMS,
  'moderator' => ['dash','users','suspend','moderate','terms','audit'],
  'support'   => ['dash','users','suspend','plans'],
  'finance'   => ['dash','revenue','export','plans','audit'],
  'verifier'  => ['dash','users','verify'],
  'analyst'   => ['dash','revenue','export'],
];

function v2_schema(): void {
  $flag = cfg('data_dir') . '/.v2';
  if (is_file($flag)) return;
  $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
  $big = is_sqlite() ? 'TEXT' : 'MEDIUMTEXT';
  foreach ([
    "CREATE TABLE IF NOT EXISTS profiles (user_id INTEGER PRIMARY KEY, type VARCHAR(12) DEFAULT 'creator', plan VARCHAR(12) DEFAULT 'free', company VARCHAR(120) DEFAULT '', title VARCHAR(120) DEFAULT '', genres VARCHAR(255) DEFAULT '', verified INTEGER DEFAULT 0, exec_verified VARCHAR(12) DEFAULT 'pending', strikes INTEGER DEFAULT 0, mute_until INTEGER DEFAULT 0, ref_by INTEGER DEFAULT 0, data $big)",
    "CREATE TABLE IF NOT EXISTS user_state (user_id INTEGER PRIMARY KEY, json $big, updated INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS events (id $pk, user_id INTEGER, name VARCHAR(40) NOT NULL, props TEXT, day INTEGER NOT NULL, created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS mod_queue (id $pk, user_id INTEGER, project VARCHAR(160), text TEXT, cat VARCHAR(30), status VARCHAR(16) DEFAULT 'held', created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS mod_terms (term VARCHAR(80) PRIMARY KEY, created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS notifications (id $pk, user_id INTEGER NOT NULL, kind VARCHAR(30), title VARCHAR(200), body VARCHAR(500), link VARCHAR(200), seen INTEGER DEFAULT 0, created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS subscriptions (user_id INTEGER PRIMARY KEY, plan VARCHAR(12), status VARCHAR(20), provider VARCHAR(20), customer VARCHAR(80), sub_id VARCHAR(80), amount_micros INTEGER DEFAULT 0, current_end INTEGER DEFAULT 0, updated INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS blocks (user_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL, created INTEGER NOT NULL, PRIMARY KEY (user_id, blocked_id))",
    "CREATE TABLE IF NOT EXISTS appeals (id $pk, user_id INTEGER NOT NULL, text TEXT, status VARCHAR(16) DEFAULT 'open', created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS public_songs (id VARCHAR(40) PRIMARY KEY, user_id INTEGER, title VARCHAR(160), artist VARCHAR(160), genre VARCHAR(60), sounds_like VARCHAR(120), art VARCHAR(40), isrc VARCHAR(20) DEFAULT '', created INTEGER NOT NULL)",
    "CREATE TABLE IF NOT EXISTS admin_roles (role VARCHAR(20) PRIMARY KEY, perms TEXT)",
  ] as $sql) db()->exec($sql);
  @file_put_contents($flag, (string)now());
}
v2_schema();
(function () {
  $flag = cfg('data_dir') . '/.v2b'; if (is_file($flag)) return;
  db()->exec("CREATE TABLE IF NOT EXISTS stripe_events (id VARCHAR(80) PRIMARY KEY, type VARCHAR(60), created INTEGER NOT NULL)");
  db()->exec("CREATE TABLE IF NOT EXISTS stripe_customers (user_id INTEGER PRIMARY KEY, customer VARCHAR(80) NOT NULL)");
  @file_put_contents($flag, (string)now());
})();

/* ── helpers ── */
function v2_profile(int $uid): array {
  $p = one('SELECT * FROM profiles WHERE user_id = ?', [$uid]);
  if (!$p) { q('INSERT INTO profiles (user_id) VALUES (?)', [$uid]); $p = one('SELECT * FROM profiles WHERE user_id = ?', [$uid]); }
  return $p;
}
function v2_perms(string $role): array {
  if ($role === 'user') return [];
  $row = one('SELECT perms FROM admin_roles WHERE role = ?', [$role]);
  return $row ? (array)json_decode((string)$row['perms'], true) : (V2_ROLES[$role] ?? []);
}
function v2_need(string $perm): array { $u = user(); if (!in_array($perm, v2_perms((string)$u['role']), true)) fail('Your role cannot do that', 403); return $u; }
function v2_me(int $uid): array {
  $u = one('SELECT * FROM users WHERE id = ?', [$uid]); $p = v2_profile($uid);
  return ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'handle' => $u['handle'], 'city' => $u['place'], 'bio' => (string)$u['bio'],
    'type' => $p['type'], 'plan' => $p['plan'], 'company' => $p['company'], 'title' => $p['title'], 'genres' => array_values(array_filter(explode(',', (string)$p['genres']))),
    'verified' => (bool)$p['verified'], 'execVerified' => $p['exec_verified'], 'staffRole' => $u['role'], 'perms' => v2_perms((string)$u['role']), 'status' => $u['status'],
    'muteUntil' => (int)$p['mute_until'], 'profile' => json_decode((string)($p['data'] ?? 'null'), true), 'refCode' => 'E' . base_convert((string)($uid * 7919), 10, 36)];
}
function v2_event(?int $uid, string $name, array $props = []): void {
  q('INSERT INTO events (user_id, name, props, day, created) VALUES (?,?,?,?,?)', [$uid, substr($name, 0, 40), json_encode($props), intdiv(now(), 86400), now()]);
}
function v2_notify(int $uid, string $kind, string $title, string $body = '', string $link = ''): void {
  q('INSERT INTO notifications (user_id, kind, title, body, link, created) VALUES (?,?,?,?,?,?)', [$uid, $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 500), $link, now()]);
  $u = one('SELECT email, name FROM users WHERE id = ?', [$uid]);
  if ($u && cfg('mail_from')) {
    $site = (string)cfg('site_url', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ellipsismusic.net'));
    @mail($u['email'], $title, $body . "\n\n" . $site . '/app/' . "\n\nTurn these off in Profile → Notifications.", 'From: ' . cfg('mail_from') . "\r\nContent-Type: text/plain; charset=utf-8");
  }
}
function v2_stripe(string $path, array $fields = [], string $method = 'POST', ?string $idem = null): array {
  $key = (string)cfg('stripe_secret'); if (!$key) fail('Payments are not configured yet', 503);
  $url = 'https://api.stripe.com/v1/' . $path . ($method === 'GET' && $fields ? '?' . http_build_query($fields) : '');
  $ch = curl_init($url);
  $hdr = ['Stripe-Version: 2024-06-20'];
  if ($idem) $hdr[] = 'Idempotency-Key: ' . $idem;
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERPWD => $key . ':', CURLOPT_HTTPHEADER => $hdr];
  if ($method === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = http_build_query($fields); }
  curl_setopt_array($ch, $opt);
  $r = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
  if (!empty($r['error'])) fail('Payment provider: ' . ($r['error']['message'] ?? 'error'), 502);
  return $r;
}
function v2_customer(array $u): string {
  $c = val('SELECT customer FROM stripe_customers WHERE user_id = ?', [$u['id']]);
  if ($c) return (string)$c;
  $r = v2_stripe('customers', ['email' => $u['email'], 'name' => $u['name'], 'metadata[user_id]' => (string)$u['id']], 'POST', 'cust-' . $u['id']);
  q('INSERT INTO stripe_customers (user_id, customer) VALUES (?,?)', [$u['id'], $r['id']]);
  return (string)$r['id'];
}
function v2_sync_sub(array $sub): void {
  // Single source of truth: the Subscription object. Called from every subscription/invoice webhook.
  $uid = (int)($sub['metadata']['user_id'] ?? 0);
  if (!$uid) $uid = (int)val('SELECT user_id FROM stripe_customers WHERE customer = ?', [$sub['customer'] ?? '']);
  if (!$uid) return;
  $plan = (string)($sub['metadata']['plan'] ?? '');
  if (!$plan) { $pid = (string)($sub['items']['data'][0]['price']['id'] ?? ''); $plan = $pid && $pid === cfg('stripe_price_industry') ? 'industry' : 'premium'; }
  $status = (string)($sub['status'] ?? 'canceled');
  $entitled = in_array($status, ['active', 'trialing', 'past_due'], true);   // past_due keeps access during Stripe's retry window
  v2_set_plan($uid, $entitled ? $plan : 'free', $status, ['customer' => $sub['customer'] ?? '', 'sub_id' => $sub['id'] ?? '', 'end' => (int)($sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? 0))]);
}
function v2_set_plan(int $uid, string $plan, string $status, array $extra = []): void {
  q('UPDATE profiles SET plan = ? WHERE user_id = ?', [$plan, $uid]);
  q('UPDATE users SET premium = ? WHERE id = ?', [$plan === 'free' ? 0 : 1, $uid]);
  $row = ['user_id' => $uid, 'plan' => $plan, 'status' => $status, 'provider' => $extra['provider'] ?? 'stripe', 'customer' => $extra['customer'] ?? '', 'sub_id' => $extra['sub_id'] ?? '', 'amount_micros' => $plan === 'industry' ? 49000000 : ($plan === 'premium' ? 5990000 : 0), 'current_end' => $extra['end'] ?? 0, 'updated' => now()];
  q('DELETE FROM subscriptions WHERE user_id = ?', [$uid]);
  q('INSERT INTO subscriptions (user_id, plan, status, provider, customer, sub_id, amount_micros, current_end, updated) VALUES (?,?,?,?,?,?,?,?,?)', array_values($row));
}

$sub = substr($path, 3);
$r2 = $M . ' ' . preg_replace('/\b\d+\b/', ':id', $sub);

switch ($r2) {

/* ───── config the client needs ───── */
case 'GET config':
  out(['ok' => true, 'billing' => (bool)cfg('stripe_secret'), 'mail' => (bool)cfg('mail_from'), 'terms' => array_column(all('SELECT term FROM mod_terms'), 'term')]);

/* ───── accounts ───── */
case 'POST register': {
  limit('v2register', 8, 3600);
  $email = strtolower(str_in('email', 191, true)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email doesn’t look right', 422);
  $pw = (string)(body()['password'] ?? ''); if (strlen($pw) < 8) fail('Use at least 8 characters for your password', 422);
  if (val('SELECT 1 FROM users WHERE email = ?', [$email])) fail('An account with that email already exists — sign in instead', 409);
  $name = str_in('name', 120, true);
  $base = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?: 'artist'; $handle = $base; $n = 1;
  while (val('SELECT 1 FROM users WHERE handle = ?', [$handle])) $handle = $base . (++$n);
  $role = in_array($email, array_map('strtolower', (array)cfg('admin_emails')), true) ? 'admin' : 'user';
  q('INSERT INTO users (email, pass, name, handle, role, status, human_at, created) VALUES (?,?,?,?,?,?,?,?)', [$email, password_hash($pw, PASSWORD_DEFAULT), $name, $handle, $role, 'active', now(), now()]);
  $uid = lastid();
  $type = str_in('type', 12) === 'exec' ? 'exec' : 'creator';
  $ref = (string)(body()['ref'] ?? ''); $refBy = 0;
  if (preg_match('/^E([0-9a-z]+)$/', $ref, $mm)) { $rid = (int)base_convert($mm[1], 36, 10); if ($rid % 7919 === 0 && val('SELECT 1 FROM users WHERE id = ?', [$rid / 7919])) $refBy = (int)($rid / 7919); }
  q('INSERT INTO profiles (user_id, type, plan, company, title, genres, exec_verified, ref_by) VALUES (?,?,?,?,?,?,?,?)', [$uid, $type, 'free', str_in('company', 120), str_in('title', 120), implode(',', array_slice(array_map('strval', (array)(body()['genres'] ?? [])), 0, 10)), $type === 'exec' ? 'pending' : 'n/a', $refBy]);
  v2_event($uid, 'signup', ['type' => $type, 'ref' => $refBy ? 1 : 0]);
  if ($refBy) v2_notify($refBy, 'referral', $name . ' joined Ellipsis with your invite', 'When they release their first song you both get a month of Premium.');
  audit($uid, 'v2_register', ['type' => $type]);
  out(['ok' => true, 'token' => new_session($uid), 'me' => v2_me($uid)], 201);
}
case 'POST login': {
  limit('v2login', 12, 900);
  $u = one('SELECT * FROM users WHERE email = ?', [strtolower(str_in('email', 191, true))]);
  if (!$u || !password_verify((string)(body()['password'] ?? ''), (string)$u['pass'])) fail('Wrong email or password', 401);
  if ($u['status'] !== 'active') fail($u['status'] === 'banned' ? 'This account has been banned. You can appeal at support@ellipsismusic.net.' : 'This account is suspended while we review a report.', 403, ['status' => $u['status']]);
  v2_event((int)$u['id'], 'login');
  out(['ok' => true, 'token' => new_session((int)$u['id']), 'me' => v2_me((int)$u['id'])]);
}
case 'POST logout': { if ($t = bearer()) q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $t)]); out(['ok' => true]); }
case 'GET me': { $u = user(); if ($u['status'] !== 'active') fail('Account ' . $u['status'], 403, ['status' => $u['status']]); v2_event((int)$u['id'], 'active'); out(['ok' => true, 'me' => v2_me((int)$u['id'])]); }
case 'PUT me': {
  $u = user(); $b = body();
  if (isset($b['name'])) q('UPDATE users SET name = ? WHERE id = ?', [mb_substr(trim((string)$b['name']), 0, 120) ?: $u['name'], $u['id']]);
  if (isset($b['city'])) q('UPDATE users SET place = ? WHERE id = ?', [mb_substr((string)$b['city'], 0, 80), $u['id']]);
  if (isset($b['bio'])) q('UPDATE users SET bio = ? WHERE id = ?', [mb_substr((string)$b['bio'], 0, 240), $u['id']]);
  if (isset($b['profile'])) { $j = json_encode($b['profile']); if (strlen($j) > 900000) fail('Photo too large — try a smaller one', 413); v2_profile((int)$u['id']); q('UPDATE profiles SET data = ? WHERE user_id = ?', [$j, $u['id']]); }
  out(['ok' => true, 'me' => v2_me((int)$u['id'])]);
}
case 'GET me/export': {
  $u = user(); $uid = (int)$u['id'];
  header('Content-Disposition: attachment; filename="ellipsis-my-data.json"');
  out(['exported' => date('c'), 'account' => v2_me($uid), 'state' => json_decode((string)(val('SELECT json FROM user_state WHERE user_id = ?', [$uid]) ?? 'null'), true),
       'notifications' => all('SELECT kind, title, body, created FROM notifications WHERE user_id = ?', [$uid]), 'subscription' => one('SELECT plan, status, current_end FROM subscriptions WHERE user_id = ?', [$uid])]);
}
case 'DELETE me': {
  $u = user(); $uid = (int)$u['id'];
  q("UPDATE users SET email = ?, name = 'Deleted user', bio = '', status = 'deleted', pass = '' WHERE id = ?", ['deleted-' . $uid . '@invalid', $uid]);
  foreach (['user_state', 'notifications', 'blocks', 'sessions'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$uid]);
  audit($uid, 'v2_delete_self'); out(['ok' => true]);
}

/* ───── recordings, uploads and bounces ───── */
case 'POST media': {
  $u = user(); limit('media', 200, 3600);
  $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['key'] ?? '')); if ($key === '') fail('Missing key', 422);
  $mime = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
  $EXT = ['audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/flac' => 'flac'];
  if (!isset($EXT[$mime])) fail('Unsupported audio type', 415);
  $raw = file_get_contents('php://input') ?: ''; if ($raw === '') fail('Empty file', 422); if (strlen($raw) > (int)cfg('max_upload', 62914560)) fail('File too large', 413);
  $dir = cfg('data_dir') . '/media/' . (int)$u['id']; if (!is_dir($dir)) @mkdir($dir, 0750, true);
  $f = (int)$u['id'] . '/' . $key . '.' . $EXT[$mime];
  if (file_put_contents(cfg('data_dir') . '/media/' . $f, $raw) === false) fail('Could not store the file', 500);
  out(['ok' => true, 'url' => '../api/v2/media?f=' . rawurlencode($f) . '&sig=' . substr(hash_hmac('sha256', $f, (string)cfg('secret')), 0, 32)], 201);
}
case 'GET media': {
  $f = (string)($_GET['f'] ?? '');
  if (!preg_match('#^\d+/[A-Za-z0-9_-]+\.(webm|ogg|m4a|aac|mp3|wav|flac)$#', $f, $m)) fail('Not found', 404);
  if (!hash_equals(substr(hash_hmac('sha256', $f, (string)cfg('secret')), 0, 32), (string)($_GET['sig'] ?? ''))) fail('Not found', 404);
  $p = cfg('data_dir') . '/media/' . $f; if (!is_file($p)) fail('Not found', 404);
  $T = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac'];
  $size = filesize($p); $start = 0; $end = $size - 1;
  header('Content-Type: ' . $T[$m[1]]); header('Accept-Ranges: bytes'); header('Cache-Control: private, max-age=31536000, immutable');
  if (preg_match('/bytes=(\d*)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $r)) { if ($r[1] !== '') $start = (int)$r[1]; if ($r[2] !== '') $end = min((int)$r[2], $end); if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int)$r[2]); $end = $size - 1; } http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
  header('Content-Length: ' . ($end - $start + 1));
  $fh = fopen($p, 'rb'); fseek($fh, $start); $left = $end - $start + 1; while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); echo $chunk; $left -= strlen($chunk); } fclose($fh); exit;
}

/* ───── cross-device sync ───── */
case 'GET state': { $u = user(); $r = one('SELECT json, updated FROM user_state WHERE user_id = ?', [$u['id']]); out(['ok' => true, 'state' => $r ? json_decode((string)$r['json'], true) : null, 'updated' => $r ? (int)$r['updated'] : 0]); }
case 'PUT state': {
  $u = user(); limit('state', 240, 3600);
  $raw = file_get_contents('php://input') ?: ''; if (strlen($raw) > 6000000) fail('Too much data to sync — remove some large images', 413);
  $j = json_encode(body()['state'] ?? null);
  q('DELETE FROM user_state WHERE user_id = ?', [$u['id']]); q('INSERT INTO user_state (user_id, json, updated) VALUES (?,?,?)', [$u['id'], $j, now()]);
  out(['ok' => true, 'updated' => now()]);
}

/* ───── analytics ───── */
case 'POST events': {
  $u = user(false); limit('events', 600, 3600);
  foreach (array_slice((array)(body()['events'] ?? []), 0, 50) as $e) { $n = preg_replace('/[^a-z_]/', '', (string)($e['name'] ?? '')); if ($n) v2_event($u['id'] ?? null, $n, (array)($e['props'] ?? [])); }
  out(['ok' => true]);
}

/* ───── moderation, safety, appeals ───── */
case 'POST mod/flag': {
  $u = user(); limit('flag', 60, 3600);
  q('INSERT INTO mod_queue (user_id, project, text, cat, created) VALUES (?,?,?,?,?)', [$u['id'], str_in('project', 160), str_in('text', 1000, true), str_in('cat', 30), now()]);
  $p = v2_profile((int)$u['id']); $strikes = (int)$p['strikes'] + 1;
  q('UPDATE profiles SET strikes = ?, mute_until = ? WHERE user_id = ?', [$strikes, $strikes % 3 === 0 ? now() + 600 : (int)$p['mute_until'], $u['id']]);
  out(['ok' => true, 'strikes' => $strikes]);
}
case 'POST report': { $u = user(); limit('report', 20, 3600); q('INSERT INTO reports (reporter_id, target_type, target_id, reason, note, created) VALUES (?,?,?,?,?,?)', [$u['id'], str_in('target_type', 20, true), int_in('target_id', 0, PHP_INT_MAX, 0), str_in('reason', 60, true), str_in('note', 500), now()]); out(['ok' => true, 'sla' => 'A person reviews every report within 24 hours']); }
case 'POST block': { $u = user(); $bid = int_in('user_id', 1, PHP_INT_MAX); q(upsert_ignore() . ' INTO blocks (user_id, blocked_id, created) VALUES (?,?,?)', [$u['id'], $bid, now()]); out(['ok' => true]); }
case 'POST appeal': { $u = user(false); q('INSERT INTO appeals (user_id, text, created) VALUES (?,?,?)', [$u['id'] ?? 0, str_in('text', 2000, true), now()]); out(['ok' => true]); }

/* ───── notifications ───── */
case 'GET notifications': { $u = user(); out(['ok' => true, 'items' => all('SELECT id, kind, title, body, link, seen, created FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']])]); }
case 'POST notifications/seen': { $u = user(); q('UPDATE notifications SET seen = 1 WHERE user_id = ?', [$u['id']]); out(['ok' => true]); }
case 'POST notify': {
  // App-triggered notifications to another user (invite, spike back, signature request). Rate-limited.
  $u = user(); limit('notify', 120, 3600);
  $to = one('SELECT id FROM users WHERE email = ? OR handle = ?', [strtolower(str_in('to', 191, true)), str_in('to', 191)]);
  if ($to && !val('SELECT 1 FROM blocks WHERE user_id = ? AND blocked_id = ?', [$to['id'], $u['id']])) v2_notify((int)$to['id'], str_in('kind', 30), str_in('title', 200, true), str_in('body', 500), str_in('link', 200));
  out(['ok' => true, 'delivered' => (bool)$to]);
}

/* ───── releases & sharing ───── */
case 'POST publish': {
  $u = user(); $id = preg_replace('/[^A-Za-z0-9_-]/', '', str_in('id', 40, true));
  q('DELETE FROM public_songs WHERE id = ?', [$id]);
  $isrc = 'QZ' . 'ELP' . date('y') . str_pad((string)(val('SELECT COUNT(*) FROM public_songs') + 1), 5, '0', STR_PAD_LEFT);
  q('INSERT INTO public_songs (id, user_id, title, artist, genre, sounds_like, art, isrc, created) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $u['id'], str_in('title', 160, true), str_in('artist', 160), str_in('genre', 60), str_in('like', 120), str_in('art', 40), $isrc, now()]);
  v2_event((int)$u['id'], 'release', ['id' => $id]);
  $p = v2_profile((int)$u['id']);
  if ($p['ref_by'] && (int)val("SELECT COUNT(*) FROM events WHERE user_id = ? AND name = 'release'", [$u['id']]) === 1) { v2_notify((int)$p['ref_by'], 'reward', 'Your invite released a song — a month of Premium is yours', ''); }
  out(['ok' => true, 'share' => '/s/?id=' . $id, 'isrc' => $isrc]);
}
case 'GET song': { $s = one('SELECT * FROM public_songs WHERE id = ?', [preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['id'] ?? ''))]) ?? fail('Not found', 404); out(['ok' => true, 'song' => $s]); }

/* ───── payments (Stripe Checkout + webhook) ───── */
case 'POST billing/checkout': {
  $u = user(); $plan = str_in('plan', 12) === 'industry' ? 'industry' : 'premium';
  $price = (string)cfg($plan === 'industry' ? 'stripe_price_industry' : 'stripe_price_premium'); if (!$price) fail('Set the Stripe price id for ' . $plan . ' in config.local.php', 503);
  $site = (string)cfg('site_url', 'https://' . ($_SERVER['HTTP_HOST'] ?? ''));
  $cur = one('SELECT plan, status FROM subscriptions WHERE user_id = ?', [$u['id']]);
  if ($cur && in_array($cur['status'], ['active', 'trialing', 'past_due'], true) && $cur['plan'] === $plan) fail('You already have this plan — use Manage to change it', 409);
  $hadTrial = (int)val("SELECT COUNT(*) FROM events WHERE user_id = ? AND name = 'subscribe'", [$u['id']]) > 0;
  $f = ['mode' => 'subscription', 'customer' => v2_customer($u), 'client_reference_id' => (string)$u['id'],
    'line_items[0][price]' => $price, 'line_items[0][quantity]' => 1,
    'metadata[plan]' => $plan, 'metadata[user_id]' => (string)$u['id'],
    'subscription_data[metadata][plan]' => $plan, 'subscription_data[metadata][user_id]' => (string)$u['id'],
    'billing_address_collection' => 'auto', 'customer_update[address]' => 'auto', 'customer_update[name]' => 'auto',
    'allow_promotion_codes' => 'true',
    'success_url' => $site . '/app/?billing=success&session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => $site . '/app/?billing=cancel'];
  if (!$hadTrial) $f['subscription_data[trial_period_days]'] = $plan === 'industry' ? 14 : 7;   // one trial per person
  if (cfg('stripe_auto_tax')) $f['automatic_tax[enabled]'] = 'true';
  $s = v2_stripe('checkout/sessions', $f, 'POST', 'co-' . $u['id'] . '-' . $plan . '-' . intdiv(now(), 600));
  v2_event((int)$u['id'], 'checkout_start', ['plan' => $plan]);
  out(['ok' => true, 'url' => $s['url'] ?? null]);
}
case 'GET billing/confirm': {
  $u = user(); $sid = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['session_id'] ?? '')); if (!$sid) fail('Missing session', 422);
  $cs = v2_stripe('checkout/sessions/' . $sid, [], 'GET');
  if ((int)($cs['client_reference_id'] ?? 0) !== (int)$u['id']) fail('That checkout belongs to another account', 403);
  if (!empty($cs['subscription'])) v2_sync_sub(v2_stripe('subscriptions/' . $cs['subscription'], [], 'GET'));
  out(['ok' => true, 'me' => v2_me((int)$u['id'])]);
}
case 'POST billing/portal': {
  $u = user(); $c = val('SELECT customer FROM stripe_customers WHERE user_id = ?', [$u['id']]) ?: val('SELECT customer FROM subscriptions WHERE user_id = ?', [$u['id']]); if (!$c) fail('No subscription on file yet', 404);
  $site = (string)cfg('site_url', 'https://' . ($_SERVER['HTTP_HOST'] ?? ''));
  $s = v2_stripe('billing_portal/sessions', ['customer' => $c, 'return_url' => $site . '/app/']); out(['ok' => true, 'url' => $s['url'] ?? null]);
}
case 'POST billing/webhook': {
  $payload = file_get_contents('php://input') ?: ''; $sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''); $sec = (string)cfg('stripe_webhook_secret');
  if (!$sec) fail('Webhook secret not set', 503);
  $parts = []; foreach (explode(',', $sig) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $parts[$k][] = $v; }
  $t = (int)($parts['t'][0] ?? 0); $ok = false;
  foreach ($parts['v1'] ?? [] as $v1) if (hash_equals(hash_hmac('sha256', $t . '.' . $payload, $sec), $v1)) $ok = true;
  if (!$ok || abs(now() - $t) > 300) fail('Bad signature', 400);
  $e = json_decode($payload, true); $o = $e['data']['object'] ?? [];
  $eid = (string)($e['id'] ?? '');
  if ($eid && val('SELECT 1 FROM stripe_events WHERE id = ?', [$eid])) out(['ok' => true, 'duplicate' => true]);
  $type = (string)($e['type'] ?? '');
  if (str_starts_with($type, 'customer.subscription.')) v2_sync_sub($o);
  // Newer API versions moved invoice.subscription to invoice.parent.subscription_details.subscription — support both.
  $invSub = (string)($o['subscription'] ?? ($o['parent']['subscription_details']['subscription'] ?? ''));
  if (in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true) && $invSub) v2_sync_sub(v2_stripe('subscriptions/' . $invSub, [], 'GET'));
  if ($type === 'customer.subscription.trial_will_end') { $uid = (int)($o['metadata']['user_id'] ?? 0); if ($uid) v2_notify($uid, 'billing', 'Your free trial ends in 3 days', 'Your plan continues automatically. Change or cancel in Profile → Plan → Manage.'); }
  if ($eid) q('INSERT INTO stripe_events (id, type, created) VALUES (?,?,?)', [$eid, $type, now()]);
  if ($type === 'checkout.session.completed' && ($o['mode'] ?? '') === 'subscription') { $uid = (int)($o['client_reference_id'] ?? 0); $plan = $o['metadata']['plan'] ?? 'premium'; if ($uid && !empty($o['subscription'])) { v2_sync_sub(v2_stripe('subscriptions/' . $o['subscription'], [], 'GET')); v2_event($uid, 'subscribe', ['plan' => $plan]); v2_notify($uid, 'billing', 'Welcome to ' . ($plan === 'industry' ? 'Ellipsis Industry' : 'Premium'), 'Your plan is active.'); } }
  if (in_array($type, ['customer.subscription.deleted', 'customer.subscription.paused'], true)) { $uid = (int)val('SELECT user_id FROM subscriptions WHERE sub_id = ?', [$o['id'] ?? '']); if ($uid) v2_event($uid, 'churn'); }
  if ($type === 'invoice.payment_failed') { $uid = (int)val('SELECT user_id FROM subscriptions WHERE customer = ?', [$o['customer'] ?? '']); if ($uid) v2_notify($uid, 'billing', 'Your payment didn’t go through', 'Update your card in Profile → Plan → Manage.'); }
  out(['ok' => true]);
}

/* ───── admin ───── */
case 'GET admin/metrics': {
  v2_need('dash'); $d = intdiv(now(), 86400);
  $cnt = fn($sql, $p = []) => (int)val($sql, $p);
  $series = all('SELECT day, COUNT(DISTINCT user_id) n FROM events WHERE day > ? GROUP BY day ORDER BY day', [$d - 30]);
  $funnel = []; foreach (['signup', 'profile', 'part_added', 'collab', 'release', 'spikeback'] as $ev) $funnel[$ev] = $cnt('SELECT COUNT(DISTINCT user_id) FROM events WHERE name = ? AND created > ?', [$ev, now() - 86400 * 30]);
  $mrr = (int)val("SELECT COALESCE(SUM(amount_micros),0) FROM subscriptions WHERE status = 'active'");
  out(['ok' => true, 'live' => true,
    'users' => $cnt("SELECT COUNT(*) FROM users WHERE status <> 'deleted'"), 'creators' => $cnt("SELECT COUNT(*) FROM profiles WHERE type = 'creator'"), 'execs' => $cnt("SELECT COUNT(*) FROM profiles WHERE type = 'exec'"),
    'premium' => $cnt("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND plan = 'premium'"), 'industry' => $cnt("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND plan = 'industry'"),
    'mrr' => $mrr / 1e6, 'signups7' => $cnt("SELECT COUNT(*) FROM events WHERE name = 'signup' AND created > ?", [now() - 604800]), 'signups30' => $funnel['signup'],
    'dau' => $cnt('SELECT COUNT(DISTINCT user_id) FROM events WHERE day = ?', [$d]), 'mau' => $cnt('SELECT COUNT(DISTINCT user_id) FROM events WHERE day > ?', [$d - 30]),
    'releases30' => $cnt("SELECT COUNT(*) FROM events WHERE name = 'release' AND created > ?", [now() - 86400 * 30]), 'spikebacks30' => $cnt("SELECT COUNT(*) FROM events WHERE name = 'spikeback' AND created > ?", [now() - 86400 * 30]),
    'signings30' => $cnt("SELECT COUNT(*) FROM events WHERE name = 'signed' AND created > ?", [now() - 86400 * 30]), 'held' => $cnt("SELECT COUNT(*) FROM mod_queue WHERE status = 'held'"),
    'openReports' => $cnt("SELECT COUNT(*) FROM reports WHERE status = 'open'"), 'execPending' => $cnt("SELECT COUNT(*) FROM profiles WHERE type = 'exec' AND exec_verified = 'pending'"),
    'funnel' => $funnel, 'dauSeries' => $series]);
}
case 'GET admin/users': {
  v2_need('users'); $s = '%' . trim((string)($_GET['q'] ?? '')) . '%'; $off = max(0, (int)($_GET['page'] ?? 0)) * 25;
  $rows = all("SELECT u.id, u.name, u.email, u.place city, u.status, u.role, p.type, p.plan, p.verified, p.exec_verified, p.strikes, p.company, p.title FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.status <> 'deleted' AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY u.id DESC LIMIT 25 OFFSET $off", [$s, $s]);
  out(['ok' => true, 'users' => $rows, 'total' => (int)val("SELECT COUNT(*) FROM users WHERE status <> 'deleted' AND (name LIKE ? OR email LIKE ?)", [$s, $s])]);
}
case 'POST admin/users/:id': {
  $b = body(); $done = [];
  if (isset($b['status'])) { $a = v2_need($b['status'] === 'banned' ? 'ban' : 'suspend'); $st = in_array($b['status'], ['active', 'suspended', 'banned'], true) ? $b['status'] : 'active'; q('UPDATE users SET status = ? WHERE id = ?', [$st, $ID]); if ($st !== 'active') q('DELETE FROM sessions WHERE user_id = ?', [$ID]); $done[] = 'status=' . $st; }
  if (isset($b['plan'])) { $a = v2_need('plans'); v2_set_plan($ID, in_array($b['plan'], ['free', 'premium', 'industry'], true) ? $b['plan'] : 'free', 'comped', ['provider' => 'admin']); $done[] = 'plan=' . $b['plan']; }
  if (isset($b['verified'])) { $a = v2_need('verify'); v2_profile($ID); q('UPDATE profiles SET verified = ? WHERE user_id = ?', [(int)(bool)$b['verified'], $ID]); if ($b['verified']) v2_notify($ID, 'verified', 'You’re verified on Ellipsis ✓', 'Your profile now shows the verified badge.'); $done[] = 'verified=' . (int)$b['verified']; }
  if (isset($b['exec'])) { $a = v2_need('verify'); $v = $b['exec'] === 'verified' ? 'verified' : 'rejected'; q('UPDATE profiles SET exec_verified = ? WHERE user_id = ?', [$v, $ID]); v2_notify($ID, 'verified', $v === 'verified' ? 'Your industry account is verified' : 'We couldn’t verify your industry account', $v === 'verified' ? 'Artists now see the verified label when you Spike Back.' : 'Reply to this email with proof of employment.'); $done[] = 'exec=' . $v; }
  if (isset($b['role'])) { $a = v2_need('roles'); $role = array_key_exists($b['role'], V2_ROLES) || $b['role'] === 'user' ? $b['role'] : 'user'; q('UPDATE users SET role = ? WHERE id = ?', [$role, $ID]); $done[] = 'role=' . $role; }
  if (!$done) fail('Nothing to change', 422);
  audit((int)($a['id'] ?? 0), 'admin_user', ['user' => $ID, 'changes' => $done]); out(['ok' => true]);
}
case 'GET admin/mod': { v2_need('moderate'); out(['ok' => true, 'queue' => all('SELECT m.*, u.name user, u.email FROM mod_queue m LEFT JOIN users u ON u.id = m.user_id ORDER BY m.id DESC LIMIT 200'), 'terms' => array_column(all('SELECT term FROM mod_terms ORDER BY term'), 'term'), 'appeals' => all("SELECT * FROM appeals WHERE status = 'open' ORDER BY id DESC LIMIT 50")]); }
case 'POST admin/mod/:id': { $a = v2_need('moderate'); $st = in_array(str_in('status', 16), ['released', 'removed'], true) ? str_in('status', 16) : 'removed'; q('UPDATE mod_queue SET status = ? WHERE id = ?', [$st, $ID]); audit((int)$a['id'], 'mod_' . $st, ['id' => $ID]); out(['ok' => true]); }
case 'POST admin/terms': { $a = v2_need('terms'); $t = strtolower(str_in('term', 80, true)); if (str_in('op', 8) === 'remove') q('DELETE FROM mod_terms WHERE term = ?', [$t]); else q(upsert_ignore() . ' INTO mod_terms (term, created) VALUES (?,?)', [$t, now()]); audit((int)$a['id'], 'terms_' . (str_in('op', 8) ?: 'add')); out(['ok' => true]); }
case 'GET admin/roles': { v2_need('dash'); $m = []; foreach (array_keys(V2_ROLES) as $r) $m[$r] = v2_perms($r); out(['ok' => true, 'roles' => $m, 'perms' => V2_PERMS, 'staff' => all("SELECT id, name, email, role FROM users WHERE role <> 'user' ORDER BY id")]); }
case 'PUT admin/roles': { $a = v2_need('roles'); $role = str_in('role', 20, true); if ($role === 'admin') fail('The admin role always has every permission', 422); $perms = array_values(array_intersect(V2_PERMS, (array)(body()['perms'] ?? []))); q('DELETE FROM admin_roles WHERE role = ?', [$role]); q('INSERT INTO admin_roles (role, perms) VALUES (?,?)', [$role, json_encode($perms)]); audit((int)$a['id'], 'roles_update', ['role' => $role, 'perms' => $perms]); out(['ok' => true]); }
case 'GET admin/audit': { v2_need('audit'); out(['ok' => true, 'log' => all('SELECT a.created, a.action, a.detail, u.name, u.role FROM audit a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')]); }

default: fail('No such endpoint: v2/' . $sub, 404);
}
