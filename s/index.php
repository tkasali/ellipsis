<?php
// Public, playable share page for a released song. Rich previews on Facebook, Threads, X (inline player), WhatsApp, iMessage.
declare(strict_types=1);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$site = 'https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'ellipsismusic.net'));
$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$s = null;
try { require_once dirname(__DIR__) . '/api/lib.php'; $s = one('SELECT * FROM public_songs WHERE id = ?', [$id]); } catch (Throwable $e) { $s = null; }
$clip = fn($k, $n) => mb_substr(trim((string)($_GET[$k] ?? '')), 0, $n);
$title = $s['title'] ?? $clip('t', 120); $artist = $s['artist'] ?? $clip('a', 120); $genre = $s['genre'] ?? $clip('g', 40);
$audio = (string)($s['audio'] ?? $clip('au', 300));
if (!preg_match('#^/(app/assets/[A-Za-z0-9_./-]+\.(wav|mp3|m4a|ogg|webm)|api/media\?[A-Za-z0-9_=&%.-]+)$#', $audio)) $audio = '';
$audioUrl = $audio ? $site . $audio : '';
$name = $title !== '' ? $title . ($artist !== '' ? ' · ' . $artist : '') : 'Ellipsis — finish the song, together';
$desc = ($genre !== '' ? $genre . ' · ' : '') . 'Made by people, together, on Ellipsis. Tap to listen.';
$img = $site . '/icons/ios-appstore-1024.png';
$self = $site . '/s/?' . http_build_query(array_filter(['id' => $id, 't' => $title, 'a' => $artist, 'g' => $genre, 'au' => $audio]));
$player = $site . '/s/player.php?' . http_build_query(array_filter(['id' => $id, 't' => $title, 'a' => $artist, 'au' => $audio]));
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($name) ?></title><meta name="description" content="<?= $h($desc) ?>">
<meta property="og:site_name" content="Ellipsis"><meta property="og:type" content="music.song"><meta property="og:title" content="<?= $h($name) ?>"><meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="<?= $h($self) ?>"><meta property="og:image" content="<?= $h($img) ?>"><meta property="og:image:width" content="1024"><meta property="og:image:height" content="1024">
<?php if ($audioUrl): ?><meta property="og:audio" content="<?= $h($audioUrl) ?>"><meta property="og:audio:secure_url" content="<?= $h($audioUrl) ?>"><meta property="og:audio:type" content="audio/<?= $h(pathinfo(parse_url($audio, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'mpeg') ?>">
<meta name="twitter:card" content="player"><meta name="twitter:player" content="<?= $h($player) ?>"><meta name="twitter:player:width" content="480"><meta name="twitter:player:height" content="160"><meta name="twitter:player:stream" content="<?= $h($audioUrl) ?>">
<?php else: ?><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<meta name="twitter:title" content="<?= $h($name) ?>"><meta name="twitter:description" content="<?= $h($desc) ?>"><meta name="twitter:image" content="<?= $h($img) ?>">
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:radial-gradient(80% 60% at 20% 10%,rgba(0,217,166,.35),transparent 60%),radial-gradient(70% 60% at 90% 90%,rgba(123,92,255,.3),transparent 60%),#0C1318;color:#fff;font-family:system-ui,-apple-system,sans-serif}
.c{width:100%;max-width:420px;text-align:center}.art{width:220px;height:220px;margin:0 auto 22px;border-radius:28px;background:linear-gradient(135deg,#00D9A6,#7B5CFF);display:flex;align-items:center;justify-content:center;font-size:72px;box-shadow:0 30px 60px rgba(0,0,0,.45)}
h1{margin:0 0 6px;font-size:28px;letter-spacing:-.5px}p{margin:0 0 20px;color:#C9D4DB}audio{width:100%;margin:0 0 18px}a{display:inline-block;padding:14px 24px;border-radius:14px;background:#00D9A6;color:#06231B;font-weight:800;text-decoration:none}
small{display:block;margin-top:14px;color:#9BA9B4}
</style></head><body><div class="c">
<div class="art">♪</div><h1><?= $h($title !== '' ? $title : 'A song made on Ellipsis') ?></h1><p><?= $h($artist !== '' ? $artist : 'Ellipsis') ?><?= $genre !== '' ? ' · ' . $h($genre) : '' ?></p>
<?php if ($audioUrl): ?><audio controls preload="metadata" src="<?= $h($audioUrl) ?>"></audio><?php endif; ?>
<a href="<?= $h($site) ?>/app/">Open in Ellipsis</a><small>Human-made music, finished together.</small>
</div></body></html>
