<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
ini_set('session.save_path',sys_get_temp_dir());
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Models\ClientNotification;

// Aucun e-mail réel : les messages sont écrits dans storage/logs/mail.log. Tout est annulé à la fin.
putenv('MAIL_DRIVER=log');putenv('MAIL_FROM_ADDRESS=notifications@example.com');
$pdo=Database::connection();$pdo->beginTransaction();
function expectNotification(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
try{
    $resource=$pdo->query('SELECT d.client_id,d.driver_id,d.vehicle_id,d.created_by FROM deliveries d WHERE d.driver_id IS NOT NULL AND d.vehicle_id IS NOT NULL LIMIT 1')->fetch();if(!$resource){throw new RuntimeException('Aucune livraison affectée disponible pour le test.');}
    $pdo->prepare('INSERT INTO client_notification_recipients (client_id,full_name,email,notify_email,notify_on) VALUES (:client,"Réception Test","reception.test@example.com",1,"dispatch,arrival,delivery"),(:client2,"Direction Test","direction.test@example.com",1,"delivery"),(:client3,"SMS seulement","sms.test@example.com",0,"dispatch,arrival,delivery")')->execute(['client'=>$resource['client_id'],'client2'=>$resource['client_id'],'client3'=>$resource['client_id']]);
    $pdo->prepare('INSERT INTO deliveries (reference,client_id,scheduled_at,priority,driver_id,vehicle_id,status,created_by,updated_by) VALUES (:reference,:client,NOW(),"Normale",:driver,:vehicle,"Chargée",:created_user,:updated_user)')->execute(['reference'=>'TEST-NOTIF-'.date('YmdHis'),'client'=>$resource['client_id'],'driver'=>$resource['driver_id'],'vehicle'=>$resource['vehicle_id'],'created_user'=>$resource['created_by'],'updated_user'=>$resource['created_by']]);$deliveryId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO delivery_destinations (delivery_id,stop_order,label,address_line,city) VALUES (:delivery,1,"Entrepôt Kasapa","Avenue des Usines 12","Lubumbashi")')->execute(['delivery'=>$deliveryId]);$destinationId=(int)$pdo->lastInsertId();

    expectNotification(ClientNotification::queue($pdo,$deliveryId,'dispatch')===1,'le départ notifie uniquement les destinataires abonnés à cet événement');
    expectNotification(ClientNotification::queue($pdo,$deliveryId,'dispatch')===0,'un même événement n’est jamais envoyé deux fois');
    expectNotification(ClientNotification::currentDestinationId($pdo,$deliveryId)===$destinationId,'l’arrivée vise la prochaine destination non livrée');
    expectNotification(ClientNotification::queue($pdo,$deliveryId,'arrival',$destinationId)===1,'l’arrivée est mise en file');
    expectNotification(ClientNotification::queue($pdo,$deliveryId,'delivery',$destinationId)===2,'la livraison notifie les deux destinataires e-mail, pas le contact SMS seul');
    $rows=ClientNotification::forDelivery($deliveryId);expectNotification(count($rows)===4,'l’historique de la livraison liste les 4 notifications');
    $body=(string)$pdo->query('SELECT body FROM notification_outbox WHERE delivery_id='.$deliveryId.' AND event="arrival"')->fetchColumn();expectNotification(strpos($body,'Entrepôt Kasapa, Avenue des Usines 12, Lubumbashi')!==false&&strpos($body,'Bonjour Réception Test')===0,'le message cite le destinataire et l’adresse de l’arrêt');

    [$sent,$failed]=ClientNotification::processQueue(50,$deliveryId);expectNotification($sent>=4&&$failed===0,'la file est envoyée sans erreur');
    expectNotification((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$deliveryId.' AND status="sent" AND sent_at IS NOT NULL')->fetchColumn()===4,'les notifications sont marquées envoyées');
    putenv('MAIL_DRIVER=smtp');putenv('MAIL_HOST=127.0.0.1');putenv('MAIL_PORT=1');putenv('MAIL_ENCRYPTION=none');
    ClientNotification::queue($pdo,$deliveryId,'arrival',null);$pdo->exec('UPDATE notification_outbox SET available_at=NOW() WHERE status="pending" AND delivery_id='.$deliveryId);
    [$sent,$failed]=ClientNotification::processQueue(50,$deliveryId);$retry=$pdo->query('SELECT attempts,status,last_error,available_at>NOW() later FROM notification_outbox WHERE delivery_id='.$deliveryId.' AND status<>"sent"')->fetch();
    expectNotification($failed>=1&&$retry&&$retry['status']==='pending'&&(int)$retry['attempts']===1&&$retry['last_error']&&(int)$retry['later']===1,'un échec SMTP est conservé et reprogrammé');
    echo "CLIENT_NOTIFICATIONS_OK\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
