<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class Planning
{
    private const EDITABLE = ['Brouillon', 'Affectée'];
    private const OCCUPYING = ['Brouillon', 'Affectée', 'À préparer', 'Prête', 'Chargement', 'Chargée', 'Partie', 'En transit', 'Arrivée', 'Déchargement', 'Incident'];

    public static function entries(array $filters): array
    {
        $start = self::date((string)($filters['start'] ?? ''), 'Date de début invalide.');
        $end = self::date((string)($filters['end'] ?? ''), 'Date de fin invalide.');
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 86400 * 62) {
            throw new RuntimeException('La période doit être comprise entre 1 et 62 jours.');
        }
        $where = ['d.scheduled_at>=:start', 'd.scheduled_at<:end'];
        $params = ['start'=>$start->format('Y-m-d H:i:s'), 'end'=>$end->format('Y-m-d H:i:s')];
        foreach (['client_id'=>'client','driver_id'=>'driver','vehicle_id'=>'vehicle'] as $column=>$key) {
            $value = (int)($filters[$key] ?? 0);
            if ($value > 0) {$where[] = 'd.'.$column.'=:'.$key; $params[$key]=$value;}
        }
        foreach (['status','priority'] as $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {$where[]='d.'.$key.'=:'.$key; $params[$key]=$value;}
        }
        $occupying = "'".implode("','", self::OCCUPYING)."'";
        $sql = "SELECT d.id,d.reference,d.scheduled_at,d.planning_duration_minutes,d.priority,d.status,d.driver_id,d.vehicle_id,
                c.company_name,dd.label site_name,dd.city,dd.address_line address_line1,
                CONCAT_WS(' ',dr.first_name,dr.last_name) driver_name,v.registration_number,
                (d.driver_id IS NULL OR d.vehicle_id IS NULL) is_unassigned,
                (d.scheduled_at<NOW() AND d.status NOT IN ('Livrée','Clôturée','Annulée')) is_overdue,
                (d.status IN ($occupying) AND EXISTS(SELECT 1 FROM deliveries x WHERE x.id<>d.id AND x.driver_id=d.driver_id AND d.driver_id IS NOT NULL
                    AND x.status IN ($occupying) AND x.scheduled_at<DATE_ADD(d.scheduled_at,INTERVAL d.planning_duration_minutes MINUTE)
                    AND DATE_ADD(x.scheduled_at,INTERVAL x.planning_duration_minutes MINUTE)>d.scheduled_at)) driver_conflict,
                (d.status IN ($occupying) AND EXISTS(SELECT 1 FROM deliveries x WHERE x.id<>d.id AND x.vehicle_id=d.vehicle_id AND d.vehicle_id IS NOT NULL
                    AND x.status IN ($occupying) AND x.scheduled_at<DATE_ADD(d.scheduled_at,INTERVAL d.planning_duration_minutes MINUTE)
                    AND DATE_ADD(x.scheduled_at,INTERVAL x.planning_duration_minutes MINUTE)>d.scheduled_at)) vehicle_conflict
            FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id ORDER BY dx.stop_order LIMIT 1)
            LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id
            WHERE ".implode(' AND ',$where)." ORDER BY d.scheduled_at,FIELD(d.priority,'Urgente','Haute','Normale','Basse'),d.id";
        $statement=Database::connection()->prepare($sql);$statement->execute($params);return $statement->fetchAll();
    }

    public static function filterOptions(): array
    {
        $pdo=Database::connection();
        return [
            'clients'=>$pdo->query("SELECT id,company_name label FROM clients WHERE status='Actif' ORDER BY company_name")->fetchAll(),
            'drivers'=>$pdo->query("SELECT id,CONCAT_WS(' ',first_name,last_name) label FROM drivers WHERE is_active=1 ORDER BY last_name,first_name")->fetchAll(),
            'vehicles'=>$pdo->query("SELECT id,CONCAT(registration_number,' · ',brand,' ',model) label FROM vehicles WHERE is_active=1 ORDER BY registration_number")->fetchAll()
        ];
    }

    public static function resources(int $deliveryId, string $scheduledAt, int $duration): array
    {
        $pdo=Database::connection();$delivery=self::delivery($pdo,$deliveryId,false);
        if (!$delivery) {throw new RuntimeException('Livraison introuvable.');}
        $start=self::date($scheduledAt,'Date planifiée invalide.');self::validateDuration($duration);
        $drivers=$pdo->query("SELECT id,first_name,last_name,status,phone,license_category,license_expires_at FROM drivers WHERE is_active=1 ORDER BY last_name,first_name")->fetchAll();
        $vehicles=$pdo->query("SELECT id,registration_number,brand,model,status,vehicle_type,capacity_value,capacity_unit FROM vehicles WHERE is_active=1 ORDER BY registration_number")->fetchAll();
        foreach($drivers as &$row){$row['conflict']=self::conflict($pdo,'driver_id',(int)$row['id'],$deliveryId,$start,$duration);$row['blocked']=!in_array($row['status'],['Disponible','Affecté'],true);}
        unset($row);
        foreach($vehicles as &$row){$row['conflict']=self::conflict($pdo,'vehicle_id',(int)$row['id'],$deliveryId,$start,$duration);$row['blocked']=!in_array($row['status'],['Disponible','Affecté'],true);}
        unset($row);
        return ['delivery'=>$delivery,'drivers'=>$drivers,'vehicles'=>$vehicles];
    }

    public static function update(int $deliveryId, string $scheduledAt, int $duration, ?int $driverId, ?int $vehicleId, string $comment): array
    {
        $start=self::date($scheduledAt,'Date et heure planifiées invalides.');self::validateDuration($duration);
        if (($driverId===null)!==($vehicleId===null)) {throw new RuntimeException('Sélectionnez ensemble le chauffeur et le véhicule.');}
        $pdo=Database::connection();$pdo->beginTransaction();
        try {
            $delivery=self::delivery($pdo,$deliveryId,true);
            if(!$delivery){throw new RuntimeException('Livraison introuvable.');}
            if(!in_array($delivery['status'],self::EDITABLE,true)){throw new RuntimeException('Cette livraison a déjà quitté la phase de planification.');}
            if($driverId!==null){self::validateResource($pdo,'drivers',$driverId,['Disponible','Affecté'],'chauffeur');self::assertNoConflict($pdo,'driver_id',$driverId,$deliveryId,$start,$duration,'chauffeur');}
            if($vehicleId!==null){self::validateResource($pdo,'vehicles',$vehicleId,['Disponible','Affecté'],'véhicule');self::assertNoConflict($pdo,'vehicle_id',$vehicleId,$deliveryId,$start,$duration,'véhicule');}
            $oldDriver=$delivery['driver_id']?(int)$delivery['driver_id']:null;$oldVehicle=$delivery['vehicle_id']?(int)$delivery['vehicle_id']:null;
            $newStatus=$driverId!==null?'Affectée':'Brouillon';$pdo->prepare('UPDATE deliveries SET scheduled_at=:scheduled,planning_duration_minutes=:duration,driver_id=:driver,vehicle_id=:vehicle,status=:status,updated_by=:user WHERE id=:id')->execute(['scheduled'=>$start->format('Y-m-d H:i:s'),'duration'=>$duration,'driver'=>$driverId,'vehicle'=>$vehicleId,'status'=>$newStatus,'user'=>Auth::id(),'id'=>$deliveryId]);
            if($driverId!==null){$pdo->prepare("UPDATE drivers SET status='Affecté',updated_by=:user WHERE id=:id")->execute(['user'=>Auth::id(),'id'=>$driverId]);$pdo->prepare("UPDATE vehicles SET status='Affecté',assigned_driver_id=:driver,updated_by=:user WHERE id=:id")->execute(['driver'=>$driverId,'user'=>Auth::id(),'id'=>$vehicleId]);}
            if($oldDriver&&$oldDriver!==$driverId){self::release($pdo,'drivers','driver_id',$oldDriver,$deliveryId);}
            if($oldVehicle&&$oldVehicle!==$vehicleId){self::release($pdo,'vehicles','vehicle_id',$oldVehicle,$deliveryId);}
            $type=($oldDriver!==$driverId||$oldVehicle!==$vehicleId)?'Planification et affectation':'Replanification';
            $pdo->prepare('INSERT INTO delivery_planning_history (delivery_id,previous_scheduled_at,scheduled_at,previous_duration_minutes,duration_minutes,previous_driver_id,driver_id,previous_vehicle_id,vehicle_id,change_type,comment,changed_by) VALUES (:delivery,:previous_scheduled,:scheduled,:previous_duration,:duration,:previous_driver,:driver,:previous_vehicle,:vehicle,:type,:comment,:user)')->execute(['delivery'=>$deliveryId,'previous_scheduled'=>$delivery['scheduled_at'],'scheduled'=>$start->format('Y-m-d H:i:s'),'previous_duration'=>(int)$delivery['planning_duration_minutes'],'duration'=>$duration,'previous_driver'=>$oldDriver,'driver'=>$driverId,'previous_vehicle'=>$oldVehicle,'vehicle'=>$vehicleId,'type'=>$type,'comment'=>mb_substr(trim($comment),0,500),'user'=>Auth::id()]);
            if($driverId!==null&&($oldDriver!==$driverId||$oldVehicle!==$vehicleId)){$pdo->prepare('INSERT INTO delivery_assignment_history (delivery_id,previous_driver_id,driver_id,previous_vehicle_id,vehicle_id,assigned_by) VALUES (:delivery,:previous_driver,:driver,:previous_vehicle,:vehicle,:user)')->execute(['delivery'=>$deliveryId,'previous_driver'=>$oldDriver,'driver'=>$driverId,'previous_vehicle'=>$oldVehicle,'vehicle'=>$vehicleId,'user'=>Auth::id()]);}
            if($delivery['status']!==$newStatus){$pdo->prepare('INSERT INTO delivery_status_history (delivery_id,from_status,to_status,comment,changed_by) VALUES (:delivery,:from_status,:to_status,:comment,:user)')->execute(['delivery'=>$deliveryId,'from_status'=>$delivery['status'],'to_status'=>$newStatus,'comment'=>$newStatus==='Affectée'?'Mission transmise au chauffeur affecté':'Affectation retirée avant acceptation','user'=>Auth::id()]);}
            $pdo->commit();return ['scheduled_at'=>$start->format('Y-m-d H:i:s'),'duration'=>$duration];
        }catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function conflict(PDO $pdo,string $column,int $resourceId,int $deliveryId,DateTimeImmutable $start,int $duration): ?array
    {
        $end=$start->modify('+'.$duration.' minutes');$active="'".implode("','",self::OCCUPYING)."'";
        $sql='SELECT reference,scheduled_at,status FROM deliveries WHERE '.$column.'=:resource AND id<>:delivery AND status IN ('.$active.') AND scheduled_at<:ending AND DATE_ADD(scheduled_at,INTERVAL planning_duration_minutes MINUTE)>:starting ORDER BY scheduled_at LIMIT 1';
        $s=$pdo->prepare($sql);$s->execute(['resource'=>$resourceId,'delivery'=>$deliveryId,'ending'=>$end->format('Y-m-d H:i:s'),'starting'=>$start->format('Y-m-d H:i:s')]);$row=$s->fetch();return $row?:null;
    }

    private static function assertNoConflict(PDO $pdo,string $column,int $resourceId,int $deliveryId,DateTimeImmutable $start,int $duration,string $label): void
    { $c=self::conflict($pdo,$column,$resourceId,$deliveryId,$start,$duration);if($c){throw new RuntimeException('Conflit '.$label.' : déjà réservé pour '.$c['reference'].' le '.date('d/m/Y à H:i',strtotime($c['scheduled_at'])).'.');} }
    private static function validateResource(PDO $pdo,string $table,int $id,array $allowed,string $label): void
    { $s=$pdo->prepare('SELECT status,is_active FROM '.$table.' WHERE id=:id FOR UPDATE');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r||!(int)$r['is_active']){throw new RuntimeException(ucfirst($label).' introuvable ou inactif.');}if(!in_array($r['status'],$allowed,true)){throw new RuntimeException(ucfirst($label).' indisponible (statut « '.$r['status'].' »).');} }
    private static function delivery(PDO $pdo,int $id,bool $lock): ?array
    { $s=$pdo->prepare('SELECT d.*,c.company_name,dd.label site_name,dd.city FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id ORDER BY dx.stop_order LIMIT 1) WHERE d.id=:id'.($lock?' FOR UPDATE':''));$s->execute(['id'=>$id]);$r=$s->fetch();return $r?:null; }
    private static function release(PDO $pdo,string $table,string $column,int $id,int $deliveryId): void
    { $active="'".implode("','",self::OCCUPYING)."'";$s=$pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE '.$column.'=:resource AND id<>:delivery AND status IN ('.$active.')');$s->execute(['resource'=>$id,'delivery'=>$deliveryId]);if(!(int)$s->fetchColumn()){$extra=$table==='vehicles'?',assigned_driver_id=NULL':'';$pdo->prepare("UPDATE $table SET status='Disponible'$extra,updated_by=:user WHERE id=:id")->execute(['user'=>Auth::id(),'id'=>$id]);} }
    private static function date(string $value,string $message): DateTimeImmutable
    { $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',str_replace('T',' ',trim($value)));if(!$date||$date->format('Y-m-d H:i:s')!==str_replace('T',' ',trim($value))){throw new RuntimeException($message);}return $date; }
    private static function validateDuration(int $duration): void
    { if($duration<15||$duration>1440){throw new RuntimeException('La durée doit être comprise entre 15 minutes et 24 heures.');} }
}
