<?php
// Maintenance for hPanel → Advanced → Cron Jobs. Runs from the command line only.
// Suggested schedule: every 10 minutes
//   /usr/bin/php /home/uXXXXXXXXX/domains/YOURDOMAIN/public_html/api/cron.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/lib.php';

$t = now();
$n = [
  'rate'       => q('DELETE FROM rate WHERE reset < ?', [$t])->rowCount(),
  'presence'   => q('DELETE FROM presence WHERE seen < ?', [$t - 600])->rowCount(),
  'sessions'   => q('DELETE FROM sessions WHERE expires < ?', [$t])->rowCount(),
  'challenges' => q('DELETE FROM challenges WHERE created < ?', [$t - 3600])->rowCount(),
  'tokens'     => q('DELETE FROM human_tokens WHERE created < ?', [$t - 3600])->rowCount(),
];
// keep the audit log for 7 years (rights records), everything else is pruned above
$n['audit_pruned'] = q('DELETE FROM audit WHERE created < ?', [$t - 86400 * 365 * 7])->rowCount();
if (is_sqlite() && (int)date('G') === 4 && (int)date('i') < 10) { db()->exec('PRAGMA optimize'); $n['optimized'] = true; }
echo json_encode(['ok' => true, 'at' => date('c'), 'pruned' => $n]), "\n";
