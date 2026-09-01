<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;

function expectGpsSchema(bool $condition,string $message): void
{
    if(!$condition){throw new RuntimeException($message);}
    echo "OK - {$message}\n";
}

$pdo=Database::connection();
$table='gps_repair_test_'.bin2hex(random_bytes(4));

try{
    $pdo->exec("CREATE TABLE `{$table}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,delivery_id BIGINT UNSIGNED NOT NULL,driver_id BIGINT UNSIGNED NOT NULL,device_position_id VARCHAR(80) NOT NULL,captured_at DATETIME(3) NOT NULL,UNIQUE KEY uq_wrong_delivery (delivery_id),KEY idx_wrong_driver (driver_id)) ENGINE=InnoDB");
    $migration=(string)file_get_contents(dirname(__DIR__).'/database/migrations/2026_09_01_000024_repair_gps_position_unique_index.sql');
    $pdo->exec(str_replace('delivery_gps_positions',$table,$migration));
    $statement=$pdo->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') columns_list,SUM(sub_part IS NOT NULL) prefix_parts FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND non_unique=0 AND index_name<>'PRIMARY' GROUP BY index_name ORDER BY index_name");
    $statement->execute(['table'=>$table]);
    $indexes=$statement->fetchAll();
    expectGpsSchema(count($indexes)===1,'la migration retire l’unicité erronée sans supprimer la table');
    expectGpsSchema($indexes[0]['columns_list']==='delivery_id,driver_id,device_position_id'&&(int)$indexes[0]['prefix_parts']===0,'la migration installe exactement l’unicité par identifiant téléphone');
    $insert=$pdo->prepare("INSERT INTO `{$table}` (delivery_id,driver_id,device_position_id,captured_at) VALUES (1,2,:position,NOW(3))");
    $insert->execute(['position'=>'position-a']);
    $insert->execute(['position'=>'position-b']);
    expectGpsSchema((int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE delivery_id=1")->fetchColumn()===2,'deux positions distinctes de la même livraison sont acceptées');
    echo "GPS_SCHEMA_REPAIR_OK\n";
}finally{
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
