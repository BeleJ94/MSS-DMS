<?php
declare(strict_types=1);
// Reinstalls the same notification triggers, without modifying business data.
ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';
$pdo=\App\Core\Database::connection();
$sql=(string)file_get_contents(BASE_PATH.'/database/migrations/2026_10_26_000030_notification_event_triggers.sql');
if(preg_match('/\b(BEGIN|DELIMITER)\b/i',$sql)){throw new RuntimeException('Cette migration doit rester importable avec le délimiteur standard.');}
// PDO migrator: entire file in one call.
$pdo->exec($sql);
// SQL importers: each semicolon ends one complete statement. Repeat to test partial-import recovery.
for($pass=1;$pass<=2;$pass++){
    foreach(explode(';',$sql) as $statement){if(trim($statement)!==''){$pdo->exec($statement);}}
}
$names=$pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('notification_delivery_updated','notification_destination_updated')")->fetchAll(PDO::FETCH_COLUMN);
if(count($names)!==2){throw new RuntimeException('Les deux triggers doivent être installés.');}
echo 'NOTIFICATION_TRIGGER_IMPORT_OK: PDO, standard-delimiter import, repeated import; 2 triggers installed'.PHP_EOL;
