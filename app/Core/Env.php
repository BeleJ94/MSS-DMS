<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\n\r\0\x0B\"'");
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $value = getenv($key);
        $resolved = $value !== false ? $value : (self::$values[$key] ?? $default);
        if ($key === 'APP_URL' && ($resolved === '' || mb_strtolower((string) $resolved) === 'auto')) {
            return self::requestBasePath();
        }
        return $resolved;
    }

    private static function requestBasePath(): string
    {
        $configured = trim((string) (self::$values['APP_BASE_PATH'] ?? ''));
        if ($configured !== '') {
            return $configured === '/' ? '' : '/' . trim($configured, '/');
        }
        if (PHP_SAPI === 'cli' && PHP_SAPI !== 'cli-server') {
            return '';
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = str_replace('\\', '/', dirname($script));
        if ($directory === '/' || $directory === '.' || $directory === '\\') {
            return '';
        }
        return '/' . trim($directory, '/');
    }
}
