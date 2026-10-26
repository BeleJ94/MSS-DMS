<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Env;
use App\Core\Mailer;
use App\Core\PodPdf;
use App\Core\NotificationEmail;
use PDO;
use Throwable;

/**
 * Database triggers persist business events in the delivery transaction.
 * The worker resolves client/site recipients and sends mail without rendering a PDF.
 */
final class ClientNotification
{
    public const EVENTS=['dispatch'=>'Départ','arrival'=>'Arrivée','delivery'=>'Livraison'];
    private const MAX_ATTEMPTS=5;

    /** Shared by the queue and the test screen. General contacts plus only the relevant sites. */
    public static function recipients(PDO $pdo,int $deliveryId,string $event,?int $destinationId=null): array
    {
        if(!isset(self::EVENTS[$event])){return [];}
        $s=$pdo->prepare('SELECT client_id FROM deliveries WHERE id=?');$s->execute([$deliveryId]);$clientId=(int)$s->fetchColumn();
        if(!$clientId){return [];}
        if($event!=='dispatch' && $destinationId){$s=$pdo->prepare('SELECT COUNT(*) FROM delivery_destinations WHERE id=? AND delivery_id=?');$s->execute([$destinationId,$deliveryId]);if(!(int)$s->fetchColumn()){return [];}}
        $r=$pdo->prepare('SELECT full_name,email,notify_on FROM client_notification_recipients WHERE client_id=? AND notify_email=1 AND email IS NOT NULL AND email<>"" ORDER BY id');$r->execute([$clientId]);$recipients=[];
        foreach($r->fetchAll() as $recipient){
            $email=mb_strtolower(trim((string)$recipient['email']));
            if(filter_var($email,FILTER_VALIDATE_EMAIL) && in_array($event,array_map('trim',explode(',',(string)$recipient['notify_on'])),true)){$recipient['email']=$email;$recipient['source']='client';$recipients[$email]=$recipient;}
        }
        $sql='SELECT dd.label,CASE WHEN s.id IS NOT NULL THEN s.notification_emails ELSE dd.notification_emails END emails FROM delivery_destinations dd JOIN deliveries d ON d.id=dd.delivery_id LEFT JOIN client_sites s ON s.id=dd.client_site_id AND s.client_id=d.client_id WHERE dd.delivery_id=? AND dd.status<>"Annulée"';
        $params=[$deliveryId];if($event!=='dispatch'){$sql.=' AND dd.id=?';$params[]=$destinationId??0;}
        $s=$pdo->prepare($sql);$s->execute($params);
        foreach($s->fetchAll() as $site){foreach(\App\Core\EmailRecipients::parse($site['emails']) as $email){if(!isset($recipients[$email])){$recipients[$email]=['full_name'=>$site['label'],'email'=>$email,'notify_on'=>'dispatch,arrival,delivery','source'=>'destination'];}}}
        return array_values($recipients);
    }

    /** Read-only preparation of a clearly labelled test, never queued for client contacts. */
    public static function prepareTest(PDO $pdo,int $deliveryId,string $event,?int $destinationId,bool $withAttachment=false): array
    {
        if(!isset(self::EVENTS[$event])){throw new \RuntimeException('Choisissez une notification de départ, d’arrivée ou de livraison.');}
        $s=$pdo->prepare('SELECT d.id,d.reference,d.client_id,c.company_name,dr.first_name,dr.last_name,dr.phone driver_phone,v.registration_number FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE d.id=:id');
        $s->execute(['id'=>$deliveryId]);$delivery=$s->fetch();
        if(!$delivery){throw new \RuntimeException('Cette livraison est introuvable. Sélectionnez une livraison existante.');}
        $destination=null;$podId=null;
        if($event!=='dispatch'){
            if(!$destinationId){throw new \RuntimeException('Choisissez la destination concernée par le test d’arrivée ou de livraison.');}
            $s=$pdo->prepare('SELECT * FROM delivery_destinations WHERE id=:id AND delivery_id=:delivery');
            $s->execute(['id'=>$destinationId,'delivery'=>$deliveryId]);$destination=$s->fetch()?:null;
            if(!$destination){throw new \RuntimeException('La destination sélectionnée n’appartient pas à cette livraison. Actualisez la liste et recommencez.');}
            if($event==='delivery'){
                $s=$pdo->prepare('SELECT id FROM delivery_pods WHERE delivery_id=:delivery AND destination_id=:destination');
                $s->execute(['delivery'=>$deliveryId,'destination'=>$destinationId]);$podId=(int)$s->fetchColumn()?:null;
            }
        }
        [$subject,$body]=self::compose($pdo,$event,$delivery,$destination,$podId);
        $attachmentName=$podId?'Bon-de-livraison-'.$delivery['reference'].'.pdf':null;
        if($event==='delivery' && !$podId){$body=str_replace('Le bon de livraison est joint à ce message.','Aucun bon de livraison n’est disponible pour cette destination : ce test ne contient pas de pièce jointe.',$body);}
        $attachments=[];
        if($withAttachment && $podId){
            $pdf=self::podPdf($deliveryId,(int)$destinationId,$podId);
            if($pdf===null){throw new \RuntimeException('Le bon de livraison existe mais son PDF ne peut pas être préparé. Vérifiez la preuve de livraison avant de recommencer.');}
            $attachments[]=['name'=>$attachmentName,'mime'=>'application/pdf','data'=>$pdf];
        }
        $subject='[TEST] '.$subject;
        $body="MESSAGE DE TEST — cet envoi ne confirme aucun changement de statut de la livraison.\n\nBonjour,\n\n".$body;
        return ['subject'=>$subject,'body'=>$body,'html'=>NotificationEmail::render($subject,$body,$event,$delivery['reference']),'attachment_name'=>$attachmentName,'attachments'=>$attachments,'reference'=>$delivery['reference']];
    }

    /** Never throws: a notification problem must not block the driver. */
    public static function queue(PDO $pdo,int $deliveryId,string $event,?int $destinationId=null,?int $podId=null,bool $strict=false,?string $occurredAt=null): int
    {
        try {
            if(!isset(self::EVENTS[$event])){return 0;}
            $s=$pdo->prepare('SELECT d.id,d.reference,d.client_id,c.company_name,dr.first_name,dr.last_name,dr.phone driver_phone,v.registration_number FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE d.id=:id');
            $s->execute(['id'=>$deliveryId]);$delivery=$s->fetch();if(!$delivery){return 0;}
            $recipients=self::recipients($pdo,$deliveryId,$event,$destinationId);
            if($recipients===[]){return 0;}
            $destination=null;
            if($destinationId){$d=$pdo->prepare('SELECT * FROM delivery_destinations WHERE id=:id AND delivery_id=:delivery');$d->execute(['id'=>$destinationId,'delivery'=>$deliveryId]);$destination=$d->fetch()?:null;}
            [$subject,$body]=self::compose($pdo,$event,$delivery,$destination,$podId,$occurredAt);
            $insert=$pdo->prepare('INSERT IGNORE INTO notification_outbox (delivery_id,destination_id,destination_key,event,channel,recipient_name,recipient_address,subject,body,pod_id) VALUES (:delivery,:destination,:destination_key,:event,"email",:name,:address,:subject,:body,:pod)');
            $count=0;
            foreach($recipients as $recipient){
                $insert->execute(['delivery'=>$deliveryId,'destination'=>$destinationId,'destination_key'=>(int)$destinationId,'event'=>$event,'name'=>$recipient['full_name'],'address'=>mb_strtolower(trim($recipient['email'])),'subject'=>$subject,'body'=>'Bonjour '.$recipient['full_name'].",\n\n".$body,'pod'=>$podId]);
                $count+=$insert->rowCount();
            }
            return $count;
        } catch(Throwable $e) {
            if($strict){throw $e;}
            error_log('Notification client non préparée (livraison '.$deliveryId.', '.$event.') : '.$e->getMessage());
            return 0;
        }
    }

    /** First stop not yet delivered or cancelled: the one the driver is heading to. */
    public static function currentDestinationId(PDO $pdo,int $deliveryId): ?int
    {
        $s=$pdo->prepare('SELECT id FROM delivery_destinations WHERE delivery_id=:id AND status NOT IN ("Livrée","Annulée") ORDER BY stop_order,id LIMIT 1');$s->execute(['id'=>$deliveryId]);$id=$s->fetchColumn();return $id?(int)$id:null;
    }

    private static function compose(PDO $pdo,string $event,array $delivery,?array $destination,?int $podId,?string $occurredAt=null): array
    {
        $eventTime=$occurredAt?strtotime($occurredAt):time();
        $app=(string)Env::get('APP_NAME','MSS-DMS');$reference=$delivery['reference'];
        $driver=trim($delivery['first_name'].' '.$delivery['last_name']);
        $vehicle=(string)$delivery['registration_number'];
        $place=$destination?self::place($destination):'';
        $signature="\n\nCordialement,\n".$app."\n\nCe message est envoyé automatiquement, merci de ne pas y répondre.";
        if($event==='dispatch'){
            $s=$pdo->prepare('SELECT stop_order,label,address_line,city FROM delivery_destinations WHERE delivery_id=:id ORDER BY stop_order');$s->execute(['id'=>$delivery['id']]);
            $stops=implode("\n",array_map(function(array $row):string{return '  '.$row['stop_order'].'. '.self::place($row);},$s->fetchAll()));
            return ['Livraison '.$reference.' en route','Nous vous confirmons le départ de votre livraison '.$reference.' le '.date('d/m/Y à H:i',$eventTime).". Les informations d’acheminement sont reprises ci-dessous.\n\nChauffeur : ".($driver?:'—').($delivery['driver_phone']?' ('.$delivery['driver_phone'].')':'')."\nVéhicule : ".($vehicle?:'—')."\n\nDestination(s) :\n".$stops.$signature];
        }
        if($event==='arrival'){
            return ['Livraison '.$reference.' : chauffeur arrivé','Nous vous informons de l’arrivée de notre chauffeur à destination le '.date('d/m/Y à H:i',$eventTime).' pour votre livraison '.$reference.".\n\nLieu : ".$place."\nChauffeur : ".($driver?:'—')."\n\nLe déchargement peut désormais être organisé. Nous vous remercions de prévoir un interlocuteur pour la réception des marchandises.".$signature];
        }
        $recipient='';$anomalies=[];
        if($podId){$p=$pdo->prepare('SELECT recipient_name,captured_at FROM delivery_pods WHERE id=:id');$p->execute(['id'=>$podId]);$pod=$p->fetch();if($pod){$recipient=(string)$pod['recipient_name'];}}
        if($destination){$g=$pdo->prepare('SELECT description_snapshot,quantity,delivered_quantity,unit,delivery_condition FROM delivery_goods WHERE destination_id=:id ORDER BY id');$g->execute(['id'=>$destination['id']]);foreach($g->fetchAll() as $row){if($row['delivery_condition']&&$row['delivery_condition']!=='Conforme'){$anomalies[]='  - '.$row['description_snapshot'].' : '.$row['delivery_condition'].' ('.self::quantity($row['delivered_quantity']).' / '.self::quantity($row['quantity']).' '.$row['unit'].')';}}}
        $body='Votre livraison '.$reference.' a été effectuée le '.date('d/m/Y à H:i',$eventTime).".\n\nLieu : ".$place.($recipient!==''?"\nRéceptionnée par : ".$recipient:'');
        $body.=$anomalies?"\n\nRéserves constatées à la livraison :\n".implode("\n",$anomalies):"\n\nAucune réserve n’a été enregistrée pour cette livraison.";
        $body.="\n\nLe bon de livraison est joint à ce message.";
        return ['Livraison '.$reference.' effectuée',$body.$signature];
    }

    /** Materialize committed database events; event and recipients are persisted atomically. */
    public static function processEvents(int $limit=50,?int $deliveryId=null): int
    {
        $pdo=Database::connection();$processed=0;
        $ids=$pdo->query('SELECT id FROM notification_events WHERE status="pending" AND available_at<=NOW()'.($deliveryId!==null?' AND delivery_id='.(int)$deliveryId:'').' ORDER BY id LIMIT '.max(1,$limit))->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){
            $owns=!$pdo->inTransaction();
            if($owns){$pdo->beginTransaction();}else{$pdo->exec('SAVEPOINT notification_event');}
            try{
                $s=$pdo->prepare('SELECT * FROM notification_events WHERE id=? AND status="pending" AND available_at<=NOW() FOR UPDATE');$s->execute([$id]);$event=$s->fetch();
                if($event){
                    $podId=null;
                    if($event['event']==='delivery'){$s=$pdo->prepare('SELECT id FROM delivery_pods WHERE delivery_id=? AND destination_id=?');$s->execute([$event['delivery_id'],$event['destination_id']]);$podId=(int)$s->fetchColumn()?:null;}
                    self::queue($pdo,(int)$event['delivery_id'],$event['event'],$event['destination_id']?(int)$event['destination_id']:null,$podId,true,$event['occurred_at']);
                    $pdo->prepare('UPDATE notification_events SET status="processed",processed_at=NOW(),last_error=NULL WHERE id=?')->execute([$id]);$processed++;
                }
                if($owns){$pdo->commit();}else{$pdo->exec('RELEASE SAVEPOINT notification_event');}
            }catch(Throwable $e){
                if($owns){if($pdo->inTransaction()){$pdo->rollBack();}}else{$pdo->exec('ROLLBACK TO SAVEPOINT notification_event');$pdo->exec('RELEASE SAVEPOINT notification_event');}
                $pdo->prepare('UPDATE notification_events SET status=IF(attempts>=4,"failed","pending"),attempts=attempts+1,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?')->execute([mb_substr($e->getMessage(),0,500),$id]);
                error_log('Préparation notification événement '.$id.' : '.$e->getMessage());
            }
        }
        return $processed;
    }

    /** Sends due messages. Returns [sent, failed]. */
    public static function processQueue(int $limit=20,?int $deliveryId=null): array
    {
        self::processEvents($limit,$deliveryId);
        $pdo=Database::connection();$sent=0;$failed=0;
        $s=$pdo->prepare('SELECT * FROM notification_outbox WHERE status="pending" AND available_at<=NOW()'.($deliveryId!==null?' AND delivery_id='.(int)$deliveryId:'').' ORDER BY id LIMIT '.max(1,$limit));$s->execute();
        foreach($s->fetchAll() as $message){
            try {
                $attachments=[];
                if($message['event']==='delivery'&&$message['destination_id']){
                    // Only an already stored PDF is attached. Never render or wait here.
                    try{$pdf=$pdo->prepare('SELECT pd.pdf_data FROM delivery_pods p JOIN delivery_pod_documents pd ON pd.pod_id=p.id WHERE p.delivery_id=? AND p.destination_id=? LIMIT 1');$pdf->execute([$message['delivery_id'],$message['destination_id']]);$data=$pdf->fetchColumn();if(is_resource($data)){$data=stream_get_contents($data);}if(is_string($data)&&strncmp($data,'%PDF-',5)===0){$attachments[]=['name'=>'Bon-de-livraison-'.self::reference($pdo,(int)$message['delivery_id']).'.pdf','mime'=>'application/pdf','data'=>$data];}}catch(Throwable $e){error_log('PDF facultatif indisponible : '.$e->getMessage());}
                    if($attachments===[]){$message['body']=str_replace('Le bon de livraison est joint à ce message.','Cette confirmation est envoyée sans pièce jointe.',$message['body']);}
                }
                $html=NotificationEmail::render($message['subject'],$message['body'],$message['event'],self::reference($pdo,(int)$message['delivery_id']));
                Mailer::send($message['recipient_address'],$message['recipient_name'],$message['subject'],$message['body'],$attachments,null,$html);
                $pdo->prepare('UPDATE notification_outbox SET status="sent",sent_at=NOW(),attempts=attempts+1,last_error=NULL WHERE id=:id')->execute(['id'=>$message['id']]);$sent++;
            } catch(Throwable $e) {
                $attempts=(int)$message['attempts']+1;$final=$attempts>=self::MAX_ATTEMPTS;
                // Retry after 5, 20, 45, 80 minutes.
                $pdo->prepare('UPDATE notification_outbox SET attempts=:attempts,status=:status,last_error=:error,available_at=DATE_ADD(NOW(),INTERVAL :delay MINUTE) WHERE id=:id')->execute(['attempts'=>$attempts,'status'=>$final?'failed':'pending','error'=>mb_substr($e->getMessage(),0,500),'delay'=>$attempts*$attempts*5,'id'=>$message['id']]);
                $failed++;
            }
        }
        return [$sent,$failed];
    }

    public static function forDelivery(int $deliveryId): array
    {
        $s=Database::connection()->prepare('SELECT n.id,n.event,n.recipient_name,n.recipient_address,n.status,n.attempts,n.last_error,n.sent_at,n.created_at,dd.stop_order,dd.label destination_label FROM notification_outbox n LEFT JOIN delivery_destinations dd ON dd.id=n.destination_id WHERE n.delivery_id=:id ORDER BY n.created_at DESC,n.id DESC');
        $s->execute(['id'=>$deliveryId]);return $s->fetchAll();
    }

    private static function podPdf(int $deliveryId,int $destinationId,?int $podId): ?string
    {
        $stored=$podId?MobileMission::document($podId):null;if($stored!==null){return $stored;}
        $pod=DeliveryPod::findByDestination($deliveryId,$destinationId);return $pod?PodPdf::render($pod):null;
    }

    private static function reference(PDO $pdo,int $deliveryId): string{$s=$pdo->prepare('SELECT reference FROM deliveries WHERE id=:id');$s->execute(['id'=>$deliveryId]);return (string)$s->fetchColumn();}
    private static function place(array $row): string{return implode(', ',array_filter([trim((string)$row['label']),trim((string)($row['address_line']??'')),trim((string)($row['city']??''))]));}
    private static function quantity($value): string{return $value===null?'0':rtrim(rtrim(number_format((float)$value,3,'.',''),'0'),'.');}
}
