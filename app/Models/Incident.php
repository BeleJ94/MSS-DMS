<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class Incident
{
    public const TYPES = ['panne','accident','retard','client absent','marchandise refusée','quantité manquante','marchandise endommagée','problème documentaire','autre'];
    public const STATUSES = ['Ouvert','En traitement','Résolu'];

    public static function reportOwned(int $deliveryId, array $data, array $photos): int
    {
        $type = trim((string)($data['incident_type'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if (!in_array($type, self::TYPES, true)) { throw new RuntimeException('Sélectionnez un type d’incident valide.'); }
        if (mb_strlen($description) < 10 || mb_strlen($description) > 3000) { throw new RuntimeException('Décrivez précisément l’incident (10 caractères minimum).'); }
        [$latitude,$longitude,$accuracy] = self::validateGps($data);
        $pdo = Database::connection(); $pdo->beginTransaction();
        try {
            $statement=$pdo->prepare('SELECT d.*,dr.user_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.id=:id FOR UPDATE');$statement->execute(['id'=>$deliveryId]);$delivery=$statement->fetch();
            if(!$delivery||(int)$delivery['user_id']!==(int)Auth::id()){throw new RuntimeException('Mission introuvable ou non autorisée.');}
            if(in_array($delivery['status'],['Incident','Livrée','Clôturée','Annulée'],true)){throw new RuntimeException('Un incident ne peut pas être signalé dans ce statut.');}
            $reference='INC-'.date('Y').'-'.str_pad((string)((int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM driver_incidents')->fetchColumn()),5,'0',STR_PAD_LEFT);
            $insert=$pdo->prepare('INSERT INTO driver_incidents (driver_id,delivery_id,incident_reference,incident_type,occurred_at,severity,status,description,latitude,longitude,accuracy_m,reported_by) VALUES (:driver,:delivery,:reference,:type,NOW(),:severity,"Ouvert",:description,:latitude,:longitude,:accuracy,:user)');
            $insert->execute(['driver'=>$delivery['driver_id'],'delivery'=>$deliveryId,'reference'=>$reference,'type'=>$type,'severity'=>in_array($type,['accident','panne','marchandise endommagée'],true)?'Majeur':'Mineur','description'=>$description,'latitude'=>$latitude,'longitude'=>$longitude,'accuracy'=>$accuracy,'user'=>Auth::id()]);
            $incidentId=(int)$pdo->lastInsertId();$photoInsert=$pdo->prepare('INSERT INTO incident_photos (incident_id,photo_mime,photo_data) VALUES (:incident,:mime,:data)');
            foreach($photos as $photo){$photoInsert->bindValue(':incident',$incidentId,PDO::PARAM_INT);$photoInsert->bindValue(':mime',$photo['mime']);$photoInsert->bindValue(':data',$photo['data'],PDO::PARAM_LOB);$photoInsert->execute();}
            $pdo->prepare('UPDATE deliveries SET status="Incident",status_before_incident=:previous,updated_by=:user WHERE id=:id')->execute(['previous'=>$delivery['status'],'user'=>Auth::id(),'id'=>$deliveryId]);
            $pdo->prepare('INSERT INTO delivery_status_history (delivery_id,from_status,to_status,comment,changed_by) VALUES (:delivery,:previous,"Incident",:comment,:user)')->execute(['delivery'=>$deliveryId,'previous'=>$delivery['status'],'comment'=>$reference.' · '.$type.' · '.$description,'user'=>Auth::id()]);
            if($delivery['driver_id']){$pdo->prepare('UPDATE drivers SET status="Indisponible",updated_by=:user WHERE id=:id')->execute(['user'=>Auth::id(),'id'=>$delivery['driver_id']]);}
            if($delivery['vehicle_id']){$pdo->prepare('UPDATE vehicles SET status="Indisponible",updated_by=:user WHERE id=:id')->execute(['user'=>Auth::id(),'id'=>$delivery['vehicle_id']]);}
            $pdo->commit();return $incidentId;
        }catch(Throwable $exception){if($pdo->inTransaction()){$pdo->rollBack();}throw $exception;}
    }

    public static function listing(array $filters=[]): array
    {
        $where=['1=1'];$params=[];
        if(($filters['status']??'')!==''){$where[]='i.status=:status';$params['status']=$filters['status'];}
        if(($filters['type']??'')!==''){$where[]='i.incident_type=:type';$params['type']=$filters['type'];}
        if(($filters['search']??'')!==''){$term='%'.$filters['search'].'%';$where[]='(i.incident_reference LIKE :s1 OR i.description LIKE :s2 OR d.reference LIKE :s3 OR c.company_name LIKE :s4 OR CONCAT(dr.first_name," ",dr.last_name) LIKE :s5)';foreach(['s1','s2','s3','s4','s5'] as $key){$params[$key]=$term;}}
        $sql='SELECT i.id,i.incident_reference,i.incident_type,i.occurred_at,i.severity,i.status,i.description,i.responsible_user_id,i.resolved_at,d.id delivery_id,d.reference delivery_reference,c.company_name,CONCAT(dr.first_name," ",dr.last_name) driver_name,u.name responsible_name,(SELECT COUNT(*) FROM incident_photos p WHERE p.incident_id=i.id) photo_count FROM driver_incidents i JOIN drivers dr ON dr.id=i.driver_id LEFT JOIN deliveries d ON d.id=i.delivery_id LEFT JOIN clients c ON c.id=d.client_id LEFT JOIN users u ON u.id=i.responsible_user_id WHERE '.implode(' AND ',$where).' ORDER BY FIELD(i.status,"Ouvert","En traitement","Résolu"),i.occurred_at DESC,i.id DESC';
        $statement=Database::connection()->prepare($sql);$statement->execute($params);return $statement->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $sql='SELECT i.*,d.reference delivery_reference,d.status delivery_status,d.status_before_incident,c.company_name,s.name site_name,s.address_line1 site_address,s.city site_city,CONCAT(dr.first_name," ",dr.last_name) driver_name,dr.phone driver_phone,v.registration_number,ru.name responsible_name,rbu.name reporter_name,resu.name resolver_name FROM driver_incidents i JOIN drivers dr ON dr.id=i.driver_id LEFT JOIN deliveries d ON d.id=i.delivery_id LEFT JOIN clients c ON c.id=d.client_id LEFT JOIN client_sites s ON s.id=d.client_site_id LEFT JOIN vehicles v ON v.id=d.vehicle_id LEFT JOIN users ru ON ru.id=i.responsible_user_id LEFT JOIN users rbu ON rbu.id=i.reported_by LEFT JOIN users resu ON resu.id=i.resolved_by WHERE i.id=:id';
        $statement=Database::connection()->prepare($sql);$statement->execute(['id'=>$id]);$incident=$statement->fetch();if(!$incident){return null;}
        $photos=Database::connection()->prepare('SELECT id,created_at FROM incident_photos WHERE incident_id=:id ORDER BY id');$photos->execute(['id'=>$id]);$incident['photos']=$photos->fetchAll();return $incident;
    }

    public static function update(int $id,array $data): void
    {
        $incident=self::find($id);if(!$incident){throw new RuntimeException('Incident introuvable.');}if($incident['status']==='Résolu'){throw new RuntimeException('Cet incident est déjà résolu.');}
        $responsible=!empty($data['responsible_user_id'])?(int)$data['responsible_user_id']:null;$action=trim((string)($data['corrective_action']??''));$status=($data['status']??'Ouvert')==='En traitement'?'En traitement':'Ouvert';
        if($responsible){$check=Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id=:id AND is_active=1');$check->execute(['id'=>$responsible]);if(!(int)$check->fetchColumn()){throw new RuntimeException('Responsable invalide.');}}
        Database::connection()->prepare('UPDATE driver_incidents SET responsible_user_id=:responsible,corrective_action=:action,status=:status WHERE id=:id')->execute(['responsible'=>$responsible,'action'=>$action?:null,'status'=>$status,'id'=>$id]);
    }

    public static function resolve(int $id,array $data): void
    {
        $resolution=trim((string)($data['resolution']??''));$action=trim((string)($data['corrective_action']??''));if(mb_strlen($action)<5){throw new RuntimeException('Renseignez l’action corrective appliquée.');}if(mb_strlen($resolution)<5){throw new RuntimeException('Décrivez la résolution de l’incident.');}
        $pdo=Database::connection();$pdo->beginTransaction();try{$s=$pdo->prepare('SELECT i.*,d.status delivery_status,d.status_before_incident,d.driver_id delivery_driver_id,d.vehicle_id,d.reference delivery_reference FROM driver_incidents i LEFT JOIN deliveries d ON d.id=i.delivery_id WHERE i.id=:id FOR UPDATE');$s->execute(['id'=>$id]);$incident=$s->fetch();if(!$incident){throw new RuntimeException('Incident introuvable.');}if($incident['status']==='Résolu'){throw new RuntimeException('Cet incident est déjà résolu.');}
            $pdo->prepare('UPDATE driver_incidents SET status="Résolu",corrective_action=:action,resolution=:resolution,resolved_at=NOW(),resolved_by=:user WHERE id=:id')->execute(['action'=>$action,'resolution'=>$resolution,'user'=>Auth::id(),'id'=>$id]);
            if($incident['delivery_id']&&$incident['delivery_status']==='Incident'){$target=$incident['status_before_incident']?:'En transit';$pdo->prepare('UPDATE deliveries SET status=:status,status_before_incident=NULL,updated_by=:user WHERE id=:id')->execute(['status'=>$target,'user'=>Auth::id(),'id'=>$incident['delivery_id']]);$pdo->prepare('INSERT INTO delivery_status_history (delivery_id,from_status,to_status,comment,changed_by) VALUES (:delivery,"Incident",:status,:comment,:user)')->execute(['delivery'=>$incident['delivery_id'],'status'=>$target,'comment'=>'Incident '.$incident['incident_reference'].' résolu · '.$resolution,'user'=>Auth::id()]);self::restoreResources($pdo,$incident,$target);}
            $pdo->commit();
        }catch(Throwable $exception){if($pdo->inTransaction()){$pdo->rollBack();}throw $exception;}
    }

    public static function photo(int $incidentId,int $photoId): ?array{$statement=Database::connection()->prepare('SELECT photo_mime,photo_data FROM incident_photos WHERE id=:photo AND incident_id=:incident');$statement->execute(['photo'=>$photoId,'incident'=>$incidentId]);$photo=$statement->fetch();if($photo&&is_resource($photo['photo_data'])){$photo['photo_data']=stream_get_contents($photo['photo_data']);}return $photo?:null;}
    public static function users(): array{return Database::connection()->query('SELECT id,name,email FROM users WHERE is_active=1 ORDER BY name')->fetchAll();}
    public static function openCount(): int{return (int)Database::connection()->query('SELECT COUNT(*) FROM driver_incidents WHERE status<>"Résolu"')->fetchColumn();}
    public static function dashboardOpen(int $limit=5): array{$limit=max(1,min(10,$limit));return Database::connection()->query('SELECT i.id,i.incident_reference,i.incident_type,i.occurred_at,i.status,i.severity,d.reference delivery_reference,c.company_name FROM driver_incidents i LEFT JOIN deliveries d ON d.id=i.delivery_id LEFT JOIN clients c ON c.id=d.client_id WHERE i.status<>"Résolu" ORDER BY FIELD(i.status,"Ouvert","En traitement"),i.occurred_at DESC LIMIT '.$limit)->fetchAll();}
    private static function validateGps(array $data): array{$lat=$data['latitude']??null;$lng=$data['longitude']??null;$accuracy=$data['accuracy']??null;if(!is_numeric($lat)||(float)$lat < -90||(float)$lat > 90||!is_numeric($lng)||(float)$lng < -180||(float)$lng > 180||!is_numeric($accuracy)||(float)$accuracy<0||(float)$accuracy>10000){throw new RuntimeException('La position GPS de l’incident est invalide.');}return[(float)$lat,(float)$lng,(float)$accuracy];}
    private static function restoreResources(PDO $pdo,array $incident,string $status): void{$driverStatus=in_array($status,['Partie','En transit','Arrivée'],true)?'En mission':(in_array($status,['Livrée','Clôturée','Annulée'],true)?'Disponible':'Affecté');$vehicleStatus=in_array($status,['Partie','En transit','Arrivée'],true)?'En livraison':(in_array($status,['Livrée','Clôturée','Annulée'],true)?'Disponible':'Affecté');if($incident['delivery_driver_id']){$pdo->prepare('UPDATE drivers SET status=:status,updated_by=:user WHERE id=:id')->execute(['status'=>$driverStatus,'user'=>Auth::id(),'id'=>$incident['delivery_driver_id']]);}if($incident['vehicle_id']){$pdo->prepare('UPDATE vehicles SET status=:status,assigned_driver_id=:driver,updated_by=:user WHERE id=:id')->execute(['status'=>$vehicleStatus,'driver'=>$vehicleStatus==='Disponible'?null:$incident['delivery_driver_id'],'user'=>Auth::id(),'id'=>$incident['vehicle_id']]);}}
}
