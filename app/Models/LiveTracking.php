<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class LiveTracking
{
    public static function positions(): array
    {
        $sql='SELECT d.id,d.reference,d.status,d.priority,d.scheduled_at,c.company_name,dd.label site_name,dd.address_line site_address,dd.city site_city,dd.latitude destination_latitude,dd.longitude destination_longitude,dr.first_name driver_first_name,dr.last_name driver_last_name,dr.phone driver_phone,v.registration_number,v.brand vehicle_brand,v.model vehicle_model,gp.latitude,gp.longitude,gp.accuracy_m,gp.captured_at,gp.received_at,TIMESTAMPDIFF(SECOND,gp.captured_at,UTC_TIMESTAMP()) position_age_seconds FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ("Livrée","Annulée") ORDER BY dx.stop_order LIMIT 1) LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id LEFT JOIN delivery_gps_positions gp ON gp.id=(SELECT p.id FROM delivery_gps_positions p WHERE p.delivery_id=d.id ORDER BY p.captured_at DESC,p.id DESC LIMIT 1) WHERE d.status IN ("Partie","En transit","Arrivée","Incident") ORDER BY FIELD(d.status,"Incident","En transit","Partie","Arrivée"),d.scheduled_at';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function route(int $deliveryId): ?array
    {
        $pdo=Database::connection();$delivery=$pdo->prepare("SELECT d.id,d.reference,dd.latitude destination_latitude,dd.longitude destination_longitude FROM deliveries d LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ('Livrée','Annulée') ORDER BY dx.stop_order LIMIT 1) WHERE d.id=:id AND d.status IN ('Partie','En transit','Arrivée','Incident')");$delivery->execute(['id'=>$deliveryId]);$row=$delivery->fetch();if(!$row){return null;}$positions=$pdo->prepare('SELECT latitude,longitude,accuracy_m,captured_at,received_at FROM delivery_gps_positions WHERE delivery_id=:id ORDER BY captured_at,id LIMIT 5000');$positions->execute(['id'=>$deliveryId]);$points=$positions->fetchAll();return ['delivery'=>$row,'points'=>$points,'point_count'=>count($points)];
    }
}
