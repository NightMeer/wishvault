<?php
declare(strict_types=1);
/**
 * Hintergrundjobs. Wird per Cron im Container ausgeführt (siehe docker/crontab):
 *   php /var/www/app/cron.php          normale Ausführung
 *   php /var/www/app/cron.php --force  Steam-Checks ohne Wartezeit für alle Spiele
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/bootstrap.php';

$lock = fopen(cfg('data_dir') . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . now() . "] läuft bereits, übersprungen\n";
    exit(0);
}
foreach (Jobs::runAll(in_array('--force', $argv, true)) as $line) {
    echo '[' . now() . "] {$line}\n";
}
flock($lock, LOCK_UN);
