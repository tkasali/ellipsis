<?php
// Copy this file to  /home/uXXXXXXXXX/domains/ellipsismusic.net/ellipsis-data/config.local.php
// (next to public_html, NOT inside it) and fill it in. Git deploys never touch it.
return [
  'secret'       => 'paste-a-long-random-string-here-64-characters-or-more',
  'setup_key'    => 'paste-a-second-random-string-here',
  'admin_emails' => ['you@ellipsismusic.net'],
  // MySQL instead of SQLite (optional):
  // 'db_dsn'  => 'mysql:host=localhost;dbname=u123456789_ellipsis;charset=utf8mb4',
  // 'db_user' => 'u123456789_ellipsis',
  // 'db_pass' => '...',
];
