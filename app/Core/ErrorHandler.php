<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function register(bool $debug, string $logFile): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception) use ($debug, $logFile): void {
            $entry = sprintf("[%s] %s in %s:%d\n%s\n", date('c'), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString());
            @error_log($entry, 3, $logFile);
            http_response_code(500);

            $title = 'Erreur interne';
            $message = $debug ? $exception->getMessage() : 'Une erreur inattendue est survenue. Réessayez plus tard.';
            try {
                echo View::render('errors/500', compact('title', 'message'), 'layouts/auth');
            } catch (Throwable $renderException) {
                @error_log(sprintf("[%s] Error page rendering failed: %s\n", date('c'), $renderException->getMessage()), 3, $logFile);
                echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Erreur interne</title><body><h1>Erreur interne</h1><p>Une erreur inattendue est survenue.</p></body></html>';
            }
        });
    }
}
