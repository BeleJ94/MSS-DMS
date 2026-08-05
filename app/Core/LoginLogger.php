<?php

declare(strict_types=1);

namespace App\Core;

final class LoginLogger
{
    public static function write(?int $userId, string $email, bool $successful, string $action = 'login'): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO login_logs (user_id, email_attempted, action, ip_address, user_agent, successful) VALUES (:user_id, :email, :action, :ip, :user_agent, :successful)'
        );
        $statement->execute([
            'user_id' => $userId,
            'email' => mb_strtolower(trim($email)),
            'action' => $action,
            'ip' => substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
            'successful' => $successful ? 1 : 0,
        ]);
    }
}
