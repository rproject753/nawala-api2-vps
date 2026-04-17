<?php
declare(strict_types=1);

/**
 * Updater terpisah dari API checker.
 * Jadwalkan lewat cron / Task Scheduler, contoh tiap 30–60 menit:
 *
 *   php C:\xampp\htdocs\nawala-api\cron\update_sources.php
 *
 * Opsi:
 *   --force   Unduh ulang semua file blocklist + ABSPositif penuh (abaikan sidik HEAD).
 */

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Hanya untuk CLI. Gunakan: php cron/update_sources.php\n");
  exit(1);
}

require_once dirname(__DIR__) . '/api/source_updater.php';

$force = in_array('--force', $argv, true);
$result = nawala_run_all_source_syncs($force);

$out = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
fwrite(STDOUT, (string)$out . "\n");

exit(($result['ok'] ?? false) ? 0 : 2);
