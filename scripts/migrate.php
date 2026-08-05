<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$pdo = Database::connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT UNSIGNED NOT NULL, executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$executed = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
$batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
$count = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $executed, true)) {
        echo "Déjà exécutée : {$name}\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec((string) file_get_contents($file));
        $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
        $statement->execute(['migration' => $name, 'batch' => $batch]);
        $pdo->commit();
        $count++;
        echo "Migrée : {$name}\n";
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

echo $count === 0 ? "Base à jour.\n" : "{$count} migration(s) exécutée(s).\n";

