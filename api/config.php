<?php
// Ellipsis API configuration. Values can also come from environment variables.
// On Hostinger: hPanel → Files → File Manager → public_html/api/config.php → edit once, then open /api/setup?key=YOUR_SETUP_KEY
// public_html lives at /home/uXXXXXXXXX/domains/YOURDOMAIN/public_html, so data goes to …/YOURDOMAIN/ellipsis-data (outside the web root).
$env = fn($k, $d = null) => (getenv($k) !== false && getenv($k) !== '') ? getenv($k) : $d;
$data = $env('ELLIPSIS_DATA_DIR', dirname(__DIR__, 2) . '/ellipsis-data'); // outside public_html when possible
// Secrets live in ellipsis-data/config.local.php (outside the repo and web root), so Git deploys never overwrite them.
$local = is_file($data . '/config.local.php') ? (array)(require $data . '/config.local.php') : [];

return array_replace([
  // Database — SQLite needs no setup. For MySQL (hPanel → Databases → Management) Hostinger prefixes names with your user id:
  // 'db_dsn' => 'mysql:host=localhost;dbname=u123456789_ellipsis;charset=utf8mb4', 'db_user' => 'u123456789_ellipsis', 'db_pass' => '…'
  'db_dsn'  => $env('ELLIPSIS_DB_DSN', 'sqlite:' . $data . '/ellipsis.sqlite'),
  'db_user' => $env('ELLIPSIS_DB_USER', null),
  'db_pass' => $env('ELLIPSIS_DB_PASS', null),

  'data_dir'    => $data,
  'upload_dir'  => $data . '/stems',
  'max_upload'  => 60 * 1024 * 1024,           // 60 MB per stem
  'allowed_audio' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/flac', 'video/webm'],

  'secret'     => $env('ELLIPSIS_SECRET', 'CHANGE-ME-to-a-long-random-string'),
  'setup_key'  => $env('ELLIPSIS_SETUP_KEY', 'CHANGE-ME-setup-key'),
  'admin_emails' => array_filter(explode(',', (string)$env('ELLIPSIS_ADMINS', ''))),
  'session_days' => 30,
  'allowed_origin' => $env('ELLIPSIS_ORIGIN', ''), // blank = same-origin only

  // Economics (all money in micro-units of USD)
  'creator_share'     => 0.70,
  'premium_price'     => 5990000,  // $5.99 / month
  'default_cpm'       => 14000000, // $14 CPM on audio ads
  'payout_min'        => 1000000,  // $1.00 minimum cash-out
  'fx' => ['USD' => 1, 'EUR' => 0.92, 'GBP' => 0.79, 'NGN' => 1580, 'BRL' => 5.1, 'JPY' => 148, 'INR' => 83],

  // Optional providers — leave blank and the API degrades gracefully
  'turnstile_secret' => $env('ELLIPSIS_TURNSTILE_SECRET', ''), // Cloudflare Turnstile, layered on the rhythm check
  'deepl_key'        => $env('ELLIPSIS_DEEPL_KEY', ''),        // chat auto-translation
  'stripe_key'       => $env('ELLIPSIS_STRIPE_KEY', ''),       // Stripe Connect transfers for payouts
  'dev_premium'      => false,                                  // true only on a test install: lets anyone flip Premium on
], $local);
