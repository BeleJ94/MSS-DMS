<?php

declare(strict_types=1);

// Prépare les événements des triggers puis envoie les notifications en attente, sans attendre de PDF.
// À planifier chaque minute :
// * * * * * php /chemin/vers/MSS-DMS/scripts/send_notifications.php >> storage/logs/notifications.log 2>&1

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Models\ClientNotification;

// Un seul envoi à la fois, même si cron chevauche deux exécutions.
$lock = fopen(BASE_PATH . '/storage/cache/notifications.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$limit = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 50;
[$sent, $failed] = ClientNotification::processQueue($limit);
if ($sent + $failed > 0) {
    echo date('Y-m-d H:i:s') . " Notifications envoyées : {$sent}, en échec : {$failed}\n";
}

flock($lock, LOCK_UN);
fclose($lock);
