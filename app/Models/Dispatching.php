<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class Dispatching
{
    private const ASSIGNABLE_STATUSES = ['Brouillon', 'À préparer', 'Prête', 'Chargement', 'Chargée'];
    private const ACTIVE_STATUSES = ['Brouillon', 'À préparer', 'Prête', 'Chargement', 'Chargée', 'Partie', 'En transit', 'Arrivée', 'Incident'];

    public static function board(array $filters = []): array
    {
        $where = ["d.status IN ('Brouillon','À préparer','Prête','Chargement','Chargée')"];
        $params = [];
        if (($filters['priority'] ?? '') !== '') {$where[] = 'd.priority=:priority'; $params['priority'] = $filters['priority'];}
        if (($filters['assignment'] ?? '') === 'unassigned') {$where[] = '(d.driver_id IS NULL OR d.vehicle_id IS NULL)';}
        if (($filters['assignment'] ?? '') === 'assigned') {$where[] = '(d.driver_id IS NOT NULL AND d.vehicle_id IS NOT NULL)';}
        if (($filters['date'] ?? '') !== '') {$where[] = 'DATE(d.scheduled_at)=:date'; $params['date'] = $filters['date'];}
        if (($filters['search'] ?? '') !== '') {
            $term = '%'.$filters['search'].'%';
            $where[] = '(d.reference LIKE :s1 OR c.company_name LIKE :s2 OR s.name LIKE :s3 OR s.city LIKE :s4)';
            foreach (['s1','s2','s3','s4'] as $key) {$params[$key] = $term;}
        }
        $sql = 'SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,d.driver_id,d.vehicle_id,c.company_name,s.name site_name,s.city,s.address_line1,dr.first_name driver_first_name,dr.last_name driver_last_name,dr.status driver_status,v.registration_number,v.brand,v.model,v.status vehicle_status FROM deliveries d JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE '.implode(' AND ', $where).' ORDER BY FIELD(d.priority,"Urgente","Haute","Normale","Basse"),d.scheduled_at,d.id';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function resources(int $deliveryId): array
    {
        $pdo = Database::connection();
        $delivery = self::delivery($pdo, $deliveryId);
        if (!$delivery) {throw new RuntimeException('Livraison introuvable.');}
        $drivers = $pdo->prepare("SELECT dr.id,dr.first_name,dr.last_name,dr.phone,dr.status,dr.license_category,dr.license_expires_at,CASE WHEN dr.id=:current THEN 1 ELSE 0 END is_current FROM drivers dr WHERE dr.is_active=1 AND (dr.status='Disponible' OR dr.id=:current2) ORDER BY is_current DESC,dr.last_name,dr.first_name");
        $drivers->execute(['current'=>(int)($delivery['driver_id'] ?: 0),'current2'=>(int)($delivery['driver_id'] ?: 0)]);
        $vehicles = $pdo->prepare("SELECT v.id,v.registration_number,v.brand,v.model,v.vehicle_type,v.capacity_value,v.capacity_unit,v.status,CASE WHEN v.id=:current THEN 1 ELSE 0 END is_current FROM vehicles v WHERE v.is_active=1 AND (v.status='Disponible' OR v.id=:current2) ORDER BY is_current DESC,v.registration_number");
        $vehicles->execute(['current'=>(int)($delivery['vehicle_id'] ?: 0),'current2'=>(int)($delivery['vehicle_id'] ?: 0)]);
        return ['delivery'=>$delivery,'drivers'=>$drivers->fetchAll(),'vehicles'=>$vehicles->fetchAll()];
    }

    public static function assign(int $deliveryId, int $driverId, int $vehicleId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $delivery = self::delivery($pdo, $deliveryId, true);
            if (!$delivery) {throw new RuntimeException('Livraison introuvable.');}
            if (!in_array($delivery['status'], self::ASSIGNABLE_STATUSES, true)) {throw new RuntimeException('Cette livraison a déjà quitté la phase d’affectation.');}
            $driver = self::lockResource($pdo, 'drivers', $driverId);
            $vehicle = self::lockResource($pdo, 'vehicles', $vehicleId);
            if (!$driver || !(int)$driver['is_active']) {throw new RuntimeException('Le chauffeur sélectionné est introuvable ou inactif.');}
            if (!$vehicle || !(int)$vehicle['is_active']) {throw new RuntimeException('Le véhicule sélectionné est introuvable ou inactif.');}
            self::assertAvailable($pdo, 'driver_id', $driverId, $deliveryId, (string)$driver['status'], 'chauffeur');
            self::assertAvailable($pdo, 'vehicle_id', $vehicleId, $deliveryId, (string)$vehicle['status'], 'véhicule');
            $oldDriver = $delivery['driver_id'] ? (int)$delivery['driver_id'] : null;
            $oldVehicle = $delivery['vehicle_id'] ? (int)$delivery['vehicle_id'] : null;
            if ($oldDriver && $oldDriver !== $driverId) {self::releaseIfUnused($pdo, 'drivers', 'driver_id', $oldDriver, $deliveryId);}
            if ($oldVehicle && $oldVehicle !== $vehicleId) {self::releaseIfUnused($pdo, 'vehicles', 'vehicle_id', $oldVehicle, $deliveryId);}
            $pdo->prepare('UPDATE deliveries SET driver_id=:driver,vehicle_id=:vehicle,updated_by=:user WHERE id=:id')->execute(['driver'=>$driverId,'vehicle'=>$vehicleId,'user'=>Auth::id(),'id'=>$deliveryId]);
            $pdo->prepare("UPDATE drivers SET status='Affecté',updated_by=:user WHERE id=:id")->execute(['user'=>Auth::id(),'id'=>$driverId]);
            $pdo->prepare("UPDATE vehicles SET status='Affecté',assigned_driver_id=:driver,updated_by=:user WHERE id=:id")->execute(['driver'=>$driverId,'user'=>Auth::id(),'id'=>$vehicleId]);
            $pdo->prepare('INSERT INTO delivery_assignment_history (delivery_id,previous_driver_id,driver_id,previous_vehicle_id,vehicle_id,assigned_by) VALUES (:delivery,:previous_driver,:driver,:previous_vehicle,:vehicle,:user)')->execute(['delivery'=>$deliveryId,'previous_driver'=>$oldDriver,'driver'=>$driverId,'previous_vehicle'=>$oldVehicle,'vehicle'=>$vehicleId,'user'=>Auth::id()]);
            $pdo->commit();
            return ['driver'=>$driver['first_name'].' '.$driver['last_name'],'vehicle'=>$vehicle['registration_number']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {$pdo->rollBack();}
            throw $e;
        }
    }

    private static function delivery(PDO $pdo, int $id, bool $lock = false): ?array
    {
        $statement = $pdo->prepare('SELECT d.*,c.company_name,s.name site_name,s.city FROM deliveries d JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id WHERE d.id=:id'.($lock?' FOR UPDATE':''));
        $statement->execute(['id'=>$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private static function lockResource(PDO $pdo, string $table, int $id): ?array
    {
        $columns = $table === 'drivers' ? 'id,first_name,last_name,status,is_active' : 'id,registration_number,status,is_active';
        $statement = $pdo->prepare('SELECT '.$columns.' FROM '.$table.' WHERE id=:id FOR UPDATE');
        $statement->execute(['id'=>$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private static function assertAvailable(PDO $pdo, string $column, int $resourceId, int $deliveryId, string $status, string $label): void
    {
        $active = "'".implode("','", self::ACTIVE_STATUSES)."'";
        $statement = $pdo->prepare('SELECT reference,scheduled_at,status FROM deliveries WHERE '.$column.'=:resource AND id<>:delivery AND status IN ('.$active.') ORDER BY scheduled_at LIMIT 1 FOR UPDATE');
        $statement->execute(['resource'=>$resourceId,'delivery'=>$deliveryId]);
        $conflict = $statement->fetch();
        if ($conflict) {throw new RuntimeException('Conflit '.$label.' : déjà affecté à '.$conflict['reference'].' ('.$conflict['status'].') prévue le '.date('d/m/Y H:i', strtotime($conflict['scheduled_at'])).'.');}
        if ($status !== 'Disponible') {
            $current = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE id=:delivery AND '.$column.'=:resource');
            $current->execute(['delivery'=>$deliveryId,'resource'=>$resourceId]);
            if (!(int)$current->fetchColumn()) {throw new RuntimeException('Conflit '.$label.' : statut actuel « '.$status.' ». Seules les ressources disponibles peuvent être affectées.');}
        }
    }

    private static function releaseIfUnused(PDO $pdo, string $table, string $column, int $resourceId, int $deliveryId): void
    {
        $active = "'".implode("','", self::ACTIVE_STATUSES)."'";
        $statement = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE '.$column.'=:resource AND id<>:delivery AND status IN ('.$active.')');
        $statement->execute(['resource'=>$resourceId,'delivery'=>$deliveryId]);
        if (!(int)$statement->fetchColumn()) {
            $extra = $table === 'vehicles' ? ',assigned_driver_id=NULL' : '';
            $pdo->prepare("UPDATE $table SET status='Disponible'{$extra},updated_by=:user WHERE id=:id")->execute(['user'=>Auth::id(),'id'=>$resourceId]);
        }
    }
}
