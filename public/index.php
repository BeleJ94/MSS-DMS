<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . ($path ?: '/');
    if ($path !== '/' && is_file($file)) { return false; }
}

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
require BASE_PATH . '/routes/web.php';

$app->run();
