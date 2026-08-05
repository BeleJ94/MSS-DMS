<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class LiveTracking
{
    public static function positions(): array
    {
        $sql='SELECT d.id,d.reference,d.status,d.priority,d.scheduled_at,c.company_name,s.name site_name,s.address_line1 site_address,s.city site_city,s.latitude destination_latitude,s.longitude destination_longitude,dr.first_name driver_first_name,dr.last_name driver_last_name,dr.phone driver_phone,v.registration_number,v.brand vehicle_brand,v.model vehicle_model,gp.latitude,gp.longitude,gp.accuracy_m,gp.captured_at,gp.received_at,TIMESTAMPDIFF(SECOND,gp.captured_at,UTC_TIMESTAMP()) position_age_seconds FROM deliveries d JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id LEFT JOIN delivery_gps_positions gp ON gp.id=(SELECT p.id FROM delivery_gps_positions p WHERE p.delivery_id=d.id ORDER BY p.captured_at DESC,p.id DESC LIMIT 1) WHERE d.status IN ("Partie","En transit","Arrivée","Incident") ORDER BY FIELD(d.status,"Incident","En transit","Partie","Arrivée"),d.scheduled_at';
        return Database::connection()->query($sql)->fetchAll();
    }
}
