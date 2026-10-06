<?php
// Public share page for a released song: rich previews on WhatsApp, iMessage, X, etc., then opens the app.
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib.php';
$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$s = null; try { $s = one('SELECT * FROM public_songs WHERE id = ?', [$id]); } catch (Throwable $e) {}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$title = $s ? $s['title'] . ' · ' . $s['artist'] : 'Ellipsis — finish the song, together';
$desc = $s ? ($s['genre'] ? $s['genre'] . ' · ' : '') . ($s['sounds_like'] ? 'Sounds like ' . $s['sounds_like'] . ' · ' : '') . 'Made by people on Ellipsis.' : 'Post an idea, get the parts it needs from people anywhere, release it together.';
$site = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ellipsismusic.net');
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title><meta name="description" content="<?= $h($desc) ?>">
<meta property="og:type" content="music.song"><meta property="og:title" content="<?= $h($title) ?>"><meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:image" content="<?= $h($site) ?>/icons/ios-appstore-1024.png"><meta property="og:url" content="<?= $h($site . '/s/?id=' . $id) ?>"><meta name="twitter:card" content="summary_large_image">
<style>body{margin:0;font-family:system-ui,sans-serif;background:#0C1318;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:24px}a{display:inline-block;margin-top:18px;padding:14px 24px;border-radius:12px;background:#00D9A6;color:#06231B;font-weight:800;text-decoration:none}</style>
</head><body><div><div style="font-size:40px;font-weight:800;color:#00D9A6">···</div><h1 style="margin:10px 0 6px"><?= $h($s ? $s['title'] : 'Ellipsis') ?></h1><p style="opacity:.8;margin:0"><?= $h($s ? $s['artist'] : 'Finish the song, together.') ?></p>
<a href="/app/?song=<?= $h($id) ?>">Listen on Ellipsis</a></div></body></html>
