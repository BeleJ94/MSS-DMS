<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private static $user;

    public static function attempt(string $email, string $password): bool
    {
        $statement = Database::connection()->prepare('SELECT id, name, email, password_hash, is_active FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch();

        if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update = Database::connection()->prepare('UPDATE users SET password_hash = :password WHERE id = :id');
            $update->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        Session::regenerate();
        Session::put('auth_user_id', (int) $user['id']);
        self::$user = null;
        return true;
    }

    public static function check(): bool { return self::id() !== null; }

    public static function id(): ?int
    {
        $id = Session::get('auth_user_id');
        return is_numeric($id) ? (int) $id : null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user ?: null;
        }
        if (self::id() === null) {
            self::$user = [];
            return null;
        }

        $sql = 'SELECT u.id, u.name, u.email, u.is_active, GROUP_CONCAT(DISTINCT r.name ORDER BY r.id SEPARATOR ", ") AS roles
                FROM users u
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE u.id = :id GROUP BY u.id';
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id' => self::id()]);
        $user = $statement->fetch();
        if (!$user || !(bool) $user['is_active']) {
            self::logout();
            return null;
        }
        self::$user = $user;
        return self::$user;
    }

    public static function can(string $permission): bool
    {
        if (self::id() === null) {
            return false;
        }
        $sql = 'SELECT COUNT(*) FROM user_roles ur
                INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE ur.user_id = :user_id AND p.name = :permission';
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['user_id' => self::id(), 'permission' => $permission]);
        return (int) $statement->fetchColumn() > 0;
    }

    public static function hasRole(string $slug): bool
    {
        if(self::id()===null){return false;}$statement=Database::connection()->prepare('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:user AND r.slug=:slug');$statement->execute(['user'=>self::id(),'slug'=>$slug]);return (int)$statement->fetchColumn()>0;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::invalidate();
    }
}
