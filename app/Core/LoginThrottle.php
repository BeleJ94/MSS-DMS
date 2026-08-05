<?php

declare(strict_types=1);

namespace App\Core;

final class LoginThrottle
{
    private const ACCOUNT_IP_LIMIT = 5;
    private const IP_LIMIT = 20;

    public static function isBlocked(string $email): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) AS ip_failures,
                    SUM(CASE WHEN email_attempted = :email THEN 1 ELSE 0 END) AS account_ip_failures
             FROM login_logs
             WHERE ip_address = :ip
               AND action = "login"
               AND successful = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'ip' => substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45),
        ]);
        $attempts = $statement->fetch() ?: [];

        return (int) ($attempts['account_ip_failures'] ?? 0) >= self::ACCOUNT_IP_LIMIT
            || (int) ($attempts['ip_failures'] ?? 0) >= self::IP_LIMIT;
    }
}
