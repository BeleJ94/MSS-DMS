<?php
declare(strict_types=1);
// All business data is rolled back; restricted to fixture delivery; no SMTP traffic.
ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';
use App\Core\Database;
use App\Models\ClientNotification;
$pdo=Database::connection();$checks=0;$suffix=bin2hex(random_bytes(5));
function triggerCheck(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException($label);}echo 'OK - '.$label.PHP_EOL;$checks++;}
function triggerInsert(string $table,array $data):int{global $pdo;$s=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$pdo->lastInsertId();}
function eventCount(int $delivery,string $event):int{global $pdo;$s=$pdo->prepare('SELECT COUNT(*) FROM notification_events WHERE delivery_id=? AND event=?');$s->execute([$delivery,$event]);return (int)$s->fetchColumn();}
putenv('MAIL_DRIVER=log');putenv('MAIL_FROM_ADDRESS=audit@example.test');
$pdo->beginTransaction();
try{
 $client=triggerInsert('clients',['code'=>'TRIGGER-'.$suffix,'company_name'=>'Audit triggers']);
 triggerInsert('client_notification_recipients',['client_id'=>$client,'full_name'=>'Contact général','email'=>'general-'.$suffix.'@example.test','notify_email'=>1,'notify_on'=>'delivery']);
 $delivery=triggerInsert('deliveries',['reference'=>'TRIGGER-'.$suffix,'client_id'=>$client,'status'=>'Chargée','scheduled_at'=>date('Y-m-d H:i:s')]);
 $stops=[];for($i=1;$i<=3;$i++){$stops[]=triggerInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>$i,'label'=>'Destination '.$i,'address_line'=>'Adresse '.$i,'notification_emails'=>'site'.$i.'-'.$suffix.'@example.test']);}
 triggerCheck(eventCount($delivery,'dispatch')===0,'pas de notification avant le changement de statut');
 $pdo->exec('SAVEPOINT rollback_test');$pdo->exec('UPDATE deliveries SET status="Partie" WHERE id='.$delivery);
 triggerCheck(eventCount($delivery,'dispatch')===1,'un UPDATE SQL direct déclenche le départ sans interface');
 $pdo->exec('ROLLBACK TO SAVEPOINT rollback_test');$pdo->exec('RELEASE SAVEPOINT rollback_test');
 triggerCheck(eventCount($delivery,'dispatch')===0,'un rollback annule aussi le déclenchement');
 $pdo->exec('UPDATE deliveries SET status="En transit" WHERE id='.$delivery);
 triggerCheck(eventCount($delivery,'dispatch')===1,'un départ direct en transit est détecté');
 $pdo->exec('UPDATE deliveries SET status="Arrivée" WHERE id='.$delivery);$pdo->exec('UPDATE delivery_destinations SET status="Arrivée" WHERE id='.$stops[0]);
 triggerCheck(eventCount($delivery,'arrival')===1,'arrivée mission et destination ne créent qu’un événement');
 $pdo->exec('UPDATE delivery_destinations SET status="Livrée",delivered_at=NOW() WHERE id='.$stops[0]);
 triggerCheck(eventCount($delivery,'delivery')===1,'la première destination déclenche son mail avant la fin de tournée');
 $pdo->exec('UPDATE delivery_destinations SET status="Livrée" WHERE id='.$stops[0]);
 triggerCheck(eventCount($delivery,'delivery')===1,'une écriture répétée ne redéclenche rien');
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery)->fetchColumn()===0,'aucun appel PHP métier nécessaire au déclenchement');
 triggerCheck(ClientNotification::processEvents(50,$delivery)===3,'le worker matérialise les trois événements');
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery.' AND event="delivery"')->fetchColumn()===2,'la livraison cible le contact général et uniquement le site concerné');
 triggerCheck(ClientNotification::processEvents(50,$delivery)===0,'un événement traité ne produit aucun doublon');
 $pdo->exec('UPDATE notification_events SET status="pending" WHERE delivery_id='.$delivery);
 ClientNotification::processEvents(50,$delivery);
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery)->fetchColumn()===6,'rejouer les événements conserve une seule notification par destinataire');
 [$sent,$failed]=ClientNotification::processQueue(50,$delivery);
 triggerCheck($sent===6&&$failed===0,'les confirmations sans preuve et sans PDF sont envoyées en mode journal');
 $log=(string)file_get_contents(BASE_PATH.'/storage/logs/mail.log');
 // Decode the real text MIME body to inspect the outgoing attachment wording.
 $last=substr($log,strrpos($log,'==== '));preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)(?=--|$)/',$last,$parts);
 $decoded=implode('',array_map('base64_decode',$parts[1]));
 triggerCheck(strpos($decoded,'Cette confirmation est envoyée sans pièce jointe.')!==false && strpos($decoded,'Le bon de livraison est joint')===false,'le mail sans PDF ne promet pas de pièce jointe');
 triggerCheck(strpos($last,'Content-Disposition: attachment')===false,'aucune pièce jointe fictive');
 $resource=$pdo->query('SELECT driver_id,vehicle_id,created_by FROM deliveries WHERE driver_id IS NOT NULL AND vehicle_id IS NOT NULL AND created_by IS NOT NULL LIMIT 1')->fetch();
 $proofData=['delivery_id'=>$delivery,'recipient_name'=>'Audit','latitude'=>0,'longitude'=>0,'accuracy_m'=>5,'captured_at'=>date('Y-m-d H:i:s'),'driver_id'=>$resource['driver_id'],'vehicle_id'=>$resource['vehicle_id'],'created_by'=>$resource['created_by']];
 $pod=triggerInsert('delivery_pods',$proofData+['destination_id'=>$stops[0]]);
 triggerInsert('delivery_pod_documents',['pod_id'=>$pod,'pdf_data'=>"%PDF-1.4\nlate test PDF\n%%EOF"]);
 triggerCheck(ClientNotification::processQueue(50,$delivery)===[0,0],'un PDF créé après l’envoi ne provoque aucun second mail');
 $pdo->exec('UPDATE deliveries SET status="En transit" WHERE id='.$delivery);$pdo->exec('UPDATE deliveries SET status="Incident" WHERE id='.$delivery);$pdo->exec('UPDATE deliveries SET status="En transit" WHERE id='.$delivery);
 triggerCheck(eventCount($delivery,'dispatch')===1,'la reprise après incident ne renvoie pas le départ');
 $pdo->exec('UPDATE delivery_destinations SET status="Arrivée" WHERE id='.$stops[1]);$pdo->exec('UPDATE delivery_destinations SET status="Livrée" WHERE id='.$stops[1]);
 triggerCheck(eventCount($delivery,'arrival')===2 && eventCount($delivery,'delivery')===2,'la deuxième destination a ses propres événements');
 $pdo->exec('UPDATE delivery_destinations SET status="Annulée" WHERE id='.$stops[2]);$pdo->exec('UPDATE deliveries SET status="Livrée" WHERE id='.$delivery);
 triggerCheck(eventCount($delivery,'delivery')===2,'la clôture de tournée ne duplique pas les mails et exclut les arrêts annulés');
 $pdo->exec('UPDATE deliveries SET status="Clôturée" WHERE id='.$delivery);
 triggerCheck(eventCount($delivery,'delivery')===2,'la clôture administrative ne renvoie pas la confirmation');
 $pod=triggerInsert('delivery_pods',$proofData+['destination_id'=>$stops[1]]);
 $pdfData="%PDF-1.4\navailable test PDF\n%%EOF";triggerInsert('delivery_pod_documents',['pod_id'=>$pod,'pdf_data'=>$pdfData]);
 ClientNotification::processEvents(50,$delivery);
 $size=filesize(BASE_PATH.'/storage/logs/mail.log');
 triggerCheck(ClientNotification::processQueue(50,$delivery)===[3,0],'un PDF disponible est joint sans nouvelle génération');
 clearstatcache();$contents=substr((string)file_get_contents(BASE_PATH.'/storage/logs/mail.log'),$size);
 triggerCheck(substr_count($contents,'Content-Disposition: attachment')===2 && strpos($contents,base64_encode($pdfData))!==false,'les deux destinataires reçoivent le PDF déjà stocké');
 $pdo->exec('UPDATE notification_outbox SET status="pending",attempts=0,sent_at=NULL WHERE delivery_id='.$delivery.' AND destination_id='.$stops[1]);
 putenv('MAIL_DRIVER=smtp');putenv('MAIL_HOST=127.0.0.1');putenv('MAIL_PORT=1');putenv('MAIL_ENCRYPTION=none');
 [$sent,$failed]=ClientNotification::processQueue(50,$delivery);
 triggerCheck($sent===0&&$failed===3,'un refus SMTP est conservé pour les trois messages de la deuxième destination');
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery.' AND status="pending" AND attempts=1 AND available_at>NOW() AND last_error IS NOT NULL')->fetchColumn()===3,'les échecs sont explicités et reprogrammés');
 $pdo->exec('UPDATE notification_outbox SET available_at=NOW(),attempts=4 WHERE delivery_id='.$delivery.' AND status="pending"');
 ClientNotification::processQueue(50,$delivery);
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery.' AND status="failed" AND attempts=5')->fetchColumn()===3,'le cinquième refus devient un échec définitif');
 $bad=triggerInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>4,'label'=>'Destinataires invalides','address_line'=>'Audit','notification_emails'=>'invalid']);
 $pdo->exec('UPDATE delivery_destinations SET status="Livrée" WHERE id='.$bad);
 triggerCheck(ClientNotification::processEvents(50,$delivery)===0,'une erreur de préparation ne perd pas l’événement');
 $row=$pdo->query('SELECT status,attempts,last_error,available_at>NOW() later FROM notification_events WHERE destination_id='.$bad)->fetch();
 triggerCheck($row['status']==='pending' && (int)$row['attempts']===1 && $row['last_error'] && (int)$row['later']===1,'la préparation en échec est journalisée et reprogrammée');
 triggerCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE destination_id='.$bad)->fetchColumn()===0,'aucun mail partiel après une préparation échouée');
 $pdo->exec('UPDATE delivery_destinations SET notification_emails="corrected@example.test" WHERE id='.$bad);$pdo->exec('UPDATE notification_events SET available_at=NOW() WHERE destination_id='.$bad);
 triggerCheck(ClientNotification::processEvents(50,$delivery)===1,'corriger les destinataires permet de reprendre le même événement');
 $emptyClient=triggerInsert('clients',['code'=>'EMPTY-'.$suffix,'company_name'=>'Sans destinataire']);
 $emptyDelivery=triggerInsert('deliveries',['reference'=>'EMPTY-'.$suffix,'client_id'=>$emptyClient,'status'=>'Chargée','scheduled_at'=>date('Y-m-d H:i:s')]);
 $pdo->exec('UPDATE deliveries SET status="Partie" WHERE id='.$emptyDelivery);
 triggerCheck(ClientNotification::processEvents(50,$emptyDelivery)===1 && (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$emptyDelivery)->fetchColumn()===0,'un événement sans abonné est traité sans inventer de destinataire');
 echo 'NOTIFICATION_TRIGGERS_OK: '.$checks.' checks; rolled back; no real email'.PHP_EOL;
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
