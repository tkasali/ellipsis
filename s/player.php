<?php
// Inline player for X (twitter:player). Plays inside the post.
declare(strict_types=1);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$site = 'https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'ellipsismusic.net'));
$t = mb_substr(trim((string)($_GET['t'] ?? '')), 0, 120); $a = mb_substr(trim((string)($_GET['a'] ?? '')), 0, 120);
$au = (string)($_GET['au'] ?? '');
if (!preg_match('#^/(app/assets/[A-Za-z0-9_./-]+\.(wav|mp3|m4a|ogg|webm)|api/media\?[A-Za-z0-9_=&%.-]+)$#', $au)) $au = '';
header('Content-Security-Policy: frame-ancestors https://x.com https://twitter.com https://*.twitter.com https://*.x.com');
?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{margin:0;height:100vh;display:flex;align-items:center;gap:14px;padding:0 16px;box-sizing:border-box;background:#0C1318;color:#fff;font-family:system-ui,sans-serif}
.a{flex:none;width:88px;height:88px;border-radius:16px;background:linear-gradient(135deg,#00D9A6,#7B5CFF);display:flex;align-items:center;justify-content:center;font-size:34px}
.m{flex:1;min-width:0}b{display:block;font-size:17px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}span{display:block;font-size:13px;color:#9BA9B4;margin-bottom:8px}audio{width:100%}
</style></head><body><div class="a">♪</div><div class="m"><b><?= $h($t ?: 'Ellipsis') ?></b><span><?= $h($a ?: 'Made together on Ellipsis') ?></span>
<?php if ($au): ?><audio controls autoplay src="<?= $h($site . $au) ?>"></audio><?php endif; ?></div></body></html>
