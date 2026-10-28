<?php

declare(strict_types=1);

// Windows Task Scheduler entry point for php-win.exe (no console window).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$notificationRoot = dirname(__DIR__);
$notificationLogPath = $notificationRoot . '/storage/logs/notifications.log';
ini_set('session.save_path', $notificationRoot . '/storage/cache');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $notificationLogPath);

// php-win has no terminal streams: write worker output directly to the journal.
ob_start(static function (string $output) use ($notificationLogPath): string {
    if ($output !== '') {
        file_put_contents($notificationLogPath, $output, FILE_APPEND | LOCK_EX);
    }
    return '';
});

try {
    require __DIR__ . '/send_notifications.php';
} catch (Throwable $exception) {
    error_log('Worker notifications : ' . $exception->getMessage());
    exit(1);
}
