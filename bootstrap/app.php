<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) App\Core\Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'));

App\Core\Session::start();

$debug = filter_var(App\Core\Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
App\Core\ErrorHandler::register($debug, BASE_PATH . '/storage/logs/app.log');

return new App\Core\Application(BASE_PATH);
