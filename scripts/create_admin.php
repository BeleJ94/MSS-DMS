<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$name = $argv[1] ?? 'Administrateur MSS-DMS';
$email = mb_strtolower(trim($argv[2] ?? 'admin@mss-dms.local'));
$password = $argv[3] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
    fwrite(STDERR, "Usage : php scripts/create_admin.php \"Nom\" email mot-de-passe (10 caractères minimum)\n");
    exit(1);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password) ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), is_active = 1');
    $statement->execute(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
    $find = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $find->execute(['email' => $email]);
    $userId = (int) $find->fetchColumn();
    $assign = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE slug = :slug');
    $assign->execute(['user_id' => $userId, 'slug' => 'administrateur']);
    $pdo->commit();
    echo "Compte administrateur prêt : {$email}\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $exception;
}
