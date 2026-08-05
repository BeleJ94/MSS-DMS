<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$currentEmail = mb_strtolower(trim($argv[1] ?? ''));
$newEmail = mb_strtolower(trim($argv[2] ?? ''));
$newPassword = $argv[3] ?? '';

if (!filter_var($currentEmail, FILTER_VALIDATE_EMAIL) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newPassword) < 10) {
    fwrite(STDERR, "Usage : php scripts/update_admin.php ancien@email nouvel@email nouveau-mot-de-passe\n");
    exit(1);
}

$pdo = Database::connection();
$statement = $pdo->prepare(
    'UPDATE users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id AND r.slug = :role
     SET u.email = :new_email, u.password_hash = :password, u.is_active = 1
     WHERE u.email = :current_email'
);
$statement->execute([
    'role' => 'administrateur',
    'new_email' => $newEmail,
    'password' => password_hash($newPassword, PASSWORD_DEFAULT),
    'current_email' => $currentEmail,
]);

if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "Compte administrateur introuvable ou mise à jour ambiguë.\n");
    exit(1);
}

echo "Compte administrateur mis à jour : {$newEmail}\n";
