<?php
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';
function cfg(string $k, $d = null) { global $CFG; return $CFG[$k] ?? $d; }

/* ───────── responses ───────── */
function out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void { out(['ok' => false, 'error' => $msg] + $extra, $code); }
function body(): array {
  static $b = null;
  if ($b !== null) return $b;
  $raw = file_get_contents('php://input') ?: '';
  $b = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
  if (!$b && $_POST) $b = $_POST;
  return $b;
}
function str_in(string $k, int $max = 500, bool $req = false): string {
  $v = trim((string)(body()[$k] ?? ''));
  if ($req && $v === '') fail("Missing $k", 422);
  if (mb_strlen($v) > $max) fail("$k is too long", 422);
  return $v;
}
function int_in(string $k, int $min, int $max, ?int $def = null): int {
  $v = body()[$k] ?? $def;
  if ($v === null || !is_numeric($v)) fail("Missing $k", 422);
  $v = (int)$v;
  if ($v < $min || $v > $max) fail("$k must be between $min and $max", 422);
  return $v;
}

/* ───────── database ───────── */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = cfg('db_dsn');
  if (str_starts_with($dsn, 'sqlite:')) {
    $dir = dirname(substr($dsn, 7));
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
  }
  $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  if (is_sqlite()) { $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 4000'); }
  return $pdo;
}
function is_sqlite(): bool { return str_starts_with((string)cfg('db_dsn'), 'sqlite:'); }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
function lastid(): int { return (int)db()->lastInsertId(); }
function now(): int { return time(); }
function upsert_ignore(): string { return is_sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE'; }

function migrate(): void {
  $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
  $sql = str_replace('{PK}', $pk, file_get_contents(__DIR__ . '/schema.sql'));
  foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if (!is_sqlite() && str_starts_with($stmt, 'CREATE INDEX')) {
      // MySQL has no CREATE INDEX IF NOT EXISTS — create and ignore "duplicate key name"
      try { db()->exec(str_replace('IF NOT EXISTS ', '', $stmt)); } catch (PDOException $e) { if ($e->errorInfo[1] != 1061) throw $e; }
      continue;
    }
    db()->exec($stmt);
  }
}

/* ───────── auth ───────── */
function bearer(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h && function_exists('apache_request_headers')) { $hs = apache_request_headers(); $h = $hs['Authorization'] ?? $hs['authorization'] ?? ''; }
  return preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,})$/', $h, $m) ? $m[1] : null;
}
function user(bool $required = true): ?array {
  static $u = false;
  if ($u === false) {
    $u = null;
    if ($t = bearer()) {
      $u = one('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires > ? AND u.status = ?',
        [hash('sha256', $t), now(), 'active']);
    }
  }
  if ($required && !$u) fail('Sign in required', 401);
  return $u;
}
function admin(): array { $u = user(); if ($u['role'] !== 'admin') fail('Admins only', 403); return $u; }
function new_session(int $uid): string {
  $t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  q('INSERT INTO sessions (token_hash, user_id, created, expires) VALUES (?,?,?,?)', [hash('sha256', $t), $uid, now(), now() + 86400 * (int)cfg('session_days')]);
  return $t;
}

/* ───────── abuse controls ───────── */
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64); }
function limit(string $bucket, int $max, int $window): void {
  $k = $bucket . ':' . ip() . ':' . (user(false)['id'] ?? 0);
  $row = one('SELECT n, reset FROM rate WHERE k = ?', [$k]);
  if (!$row || $row['reset'] < now()) { q('DELETE FROM rate WHERE k = ?', [$k]); q('INSERT INTO rate (k, n, reset) VALUES (?,?,?)', [$k, 1, now() + $window]); return; }
  if ($row['n'] >= $max) fail('Slow down — try again in ' . ($row['reset'] - now()) . 's', 429);
  q('UPDATE rate SET n = n + 1 WHERE k = ?', [$k]);
}
function audit(?int $uid, string $action, $detail = null): void {
  q('INSERT INTO audit (user_id, action, detail, created) VALUES (?,?,?,?)', [$uid, $action, $detail === null ? null : json_encode($detail), now()]);
}

/* ───────── domain helpers ───────── */
function initials(string $n): string { $p = preg_split('/\s+/', trim($n)); return strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1)); }
const AVA = ['#0A6E58','#2C4A9E','#B3523E','#5B3E9E','#2F3E48','#7A5A10','#0B5E7A','#8C1E4B','#3E6B2F','#6B2F5E'];
function user_card(array $u): array {
  return [
    'id' => (int)$u['id'], 'name' => $u['name'], 'handle' => $u['handle'], 'initials' => initials($u['name']),
    'place' => $u['place'], 'cc' => $u['cc'], 'tz' => (int)$u['tz'], 'role' => explode(',', (string)$u['roles'])[0] ?: 'Musician',
    'avatar' => AVA[(int)$u['id'] % count(AVA)],
    'online' => (int)(val('SELECT MAX(seen) FROM presence WHERE user_id = ?', [$u['id']]) ?? 0) > now() - 90,
  ];
}
function contributors(int $tid): array {
  return all('SELECT c.*, u.name, u.handle, u.place, u.cc, u.tz, u.roles FROM contributors c JOIN users u ON u.id = c.user_id WHERE c.track_id = ? ORDER BY c.pct DESC', [$tid]);
}
function is_member(int $tid, int $uid): bool {
  return (bool)val('SELECT 1 FROM contributors WHERE track_id = ? AND user_id = ? AND removed = 0', [$tid, $uid]);
}
function track_or_404(int $id): array { $t = one('SELECT * FROM tracks WHERE id = ?', [$id]); if (!$t) fail('Track not found', 404); return $t; }
function reset_signatures(int $tid): void { q('UPDATE contributors SET signed = 0 WHERE track_id = ?', [$tid]); }

function track_card(array $t, ?int $me = null): array {
  $owner = one('SELECT * FROM users WHERE id = ?', [$t['owner_id']]);
  $crew = all('SELECT u.* FROM contributors c JOIN users u ON u.id = c.user_id WHERE c.track_id = ? AND c.user_id <> ? AND c.removed = 0 LIMIT 4', [$t['id'], $t['owner_id']]);
  $spikes = (int)val('SELECT COUNT(*) FROM spikes WHERE track_id = ?', [$t['id']]) + (int)$t['spike_base'];
  return [
    'id' => 't' . $t['id'], 'dbId' => (int)$t['id'], 'title' => $t['title'],
    'owner' => user_card($owner), 'crew' => array_map('user_card', $crew),
    'state' => $t['state'], 'needs' => json_decode((string)$t['needs'], true) ?: [], 'offer' => $t['offer_pct'] ? $t['offer_pct'] . '%' : '',
    'genre' => $t['genre'], 'mood' => $t['mood'], 'like' => $t['like_ref'], 'bpm' => (int)$t['bpm'], 'key' => $t['music_key'],
    'kind' => $t['kind'], 'file' => $t['file'], 'tags' => json_decode((string)$t['tags'], true) ?: [],
    'spikes' => $spikes, 'art' => (int)$t['id'] % 6, 'seed' => ((int)$t['id'] * 37) % 997 + 11,
    'mins' => max(1, (int)((now() - (int)$t['created']) / 60)),
    'spiked' => $me ? (bool)val('SELECT 1 FROM spikes WHERE track_id = ? AND user_id = ? AND day = ?', [$t['id'], $me, intdiv(now(), 86400)]) : false,
    'joined' => $me ? is_member((int)$t['id'], $me) : false,
  ];
}

/* ───────── outbound HTTP (optional providers) ───────── */
function http_post(string $url, array $fields, array $headers = []): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers]);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, $res ? (json_decode($res, true) ?: []) : []];
}
