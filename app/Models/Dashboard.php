<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTime;

final class Dashboard
{
    public static function data(): array
    {
        $pdo=Database::connection();
        $kpis=$pdo->query("SELECT
            (SELECT COUNT(*) FROM deliveries WHERE DATE(scheduled_at)=CURDATE() AND status<>'Annulée') deliveries_today,
            (SELECT COUNT(*) FROM deliveries WHERE status='À préparer') to_prepare,
            (SELECT COUNT(*) FROM deliveries WHERE status='Prête') ready,
            (SELECT COUNT(*) FROM deliveries WHERE status='En transit') in_transit,
            (SELECT COUNT(*) FROM deliveries WHERE DATE(delivered_at)=CURDATE() AND status IN ('Livrée','Clôturée')) delivered_today,
            (SELECT COUNT(*) FROM deliveries WHERE scheduled_at<NOW() AND status NOT IN ('Livrée','Clôturée','Annulée')) overdue,
            (SELECT COUNT(*) FROM driver_incidents WHERE status<>'Résolu') open_incidents,
            (SELECT COUNT(*) FROM drivers WHERE is_active=1 AND status='Disponible') available_drivers,
            (SELECT COUNT(*) FROM vehicles WHERE is_active=1 AND status='Disponible') available_vehicles")->fetch();
        return ['kpis'=>$kpis,'period'=>self::period(14),'punctuality'=>self::punctuality(),'incidents'=>self::incidents(),'performance'=>self::performance(),'attention'=>self::attention(),'today'=>self::today()];
    }

    private static function period(int $days): array
    {
        $end=new DateTime('today');$start=(clone $end)->modify('-'.($days-1).' days');$last=(clone $end)->modify('+1 day');$period=new DatePeriod($start,new DateInterval('P1D'),$last);$rows=[];
        foreach($period as $date){$key=$date->format('Y-m-d');$rows[$key]=['label'=>$date->format('d/m'),'planned'=>0,'delivered'=>0];}
        $pdo=Database::connection();$planned=$pdo->prepare('SELECT DATE(scheduled_at) day,COUNT(*) total FROM deliveries WHERE scheduled_at>=:start AND scheduled_at<:end AND status<>"Annulée" GROUP BY DATE(scheduled_at)');$planned->execute(['start'=>$start->format('Y-m-d 00:00:00'),'end'=>$last->format('Y-m-d 00:00:00')]);foreach($planned->fetchAll() as $row){if(isset($rows[$row['day']]))$rows[$row['day']]['planned']=(int)$row['total'];}
        $delivered=$pdo->prepare('SELECT DATE(delivered_at) day,COUNT(*) total FROM deliveries WHERE delivered_at>=:start AND delivered_at<:end GROUP BY DATE(delivered_at)');$delivered->execute(['start'=>$start->format('Y-m-d 00:00:00'),'end'=>$last->format('Y-m-d 00:00:00')]);foreach($delivered->fetchAll() as $row){if(isset($rows[$row['day']]))$rows[$row['day']]['delivered']=(int)$row['total'];}
        return array_values($rows);
    }

    private static function punctuality(): array
    {
        $row=Database::connection()->query('SELECT COUNT(*) total,SUM(delivered_at<=scheduled_at) on_time,SUM(delivered_at>scheduled_at) late FROM deliveries WHERE delivered_at IS NOT NULL AND delivered_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)')->fetch();$total=(int)$row['total'];$onTime=(int)$row['on_time'];return ['total'=>$total,'on_time'=>$onTime,'late'=>(int)$row['late'],'rate'=>$total?round($onTime/$total*100,1):0];
    }

    private static function incidents(): array
    {
        $types=array_fill_keys(Incident::TYPES,0);$rows=Database::connection()->query('SELECT incident_type,COUNT(*) total FROM driver_incidents WHERE occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY incident_type ORDER BY total DESC')->fetchAll();foreach($rows as $row){$types[$row['incident_type']]=(int)$row['total'];}arsort($types);return array_map(function($type,$total){return ['type'=>$type,'total'=>$total];},array_keys($types),array_values($types));
    }

    private static function performance(): array
    {
        $pdo=Database::connection();$counts=$pdo->query("SELECT
            (SELECT COUNT(*) FROM deliveries WHERE scheduled_at<=NOW() AND scheduled_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND status<>'Annulée') due,
            (SELECT COUNT(*) FROM deliveries WHERE delivered_at IS NOT NULL AND scheduled_at<=NOW() AND scheduled_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND status<>'Annulée') completed,
            (SELECT COUNT(*) FROM drivers WHERE is_active=1) drivers_total,
            (SELECT COUNT(*) FROM drivers WHERE is_active=1 AND status='Disponible') drivers_available,
            (SELECT COUNT(*) FROM vehicles WHERE is_active=1) vehicles_total,
            (SELECT COUNT(*) FROM vehicles WHERE is_active=1 AND status='Disponible') vehicles_available")->fetch();$punctuality=self::punctuality();
        return ['labels'=>['Livraisons réalisées','Livraisons à temps','Chauffeurs disponibles','Véhicules disponibles'],'values'=>[self::rate((int)$counts['completed'],(int)$counts['due']),$punctuality['rate'],self::rate((int)$counts['drivers_available'],(int)$counts['drivers_total']),self::rate((int)$counts['vehicles_available'],(int)$counts['vehicles_total'])]];
    }

    private static function attention(): array
    {
        $sql="SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,c.company_name,dd.label site_name,dd.city,CONCAT(COALESCE(dr.first_name,''),' ',COALESCE(dr.last_name,'')) driver_name,TIMESTAMPDIFF(MINUTE,d.scheduled_at,NOW()) delay_minutes FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ('Livrée','Annulée') ORDER BY dx.stop_order LIMIT 1) LEFT JOIN drivers dr ON dr.id=d.driver_id WHERE d.scheduled_at<NOW() AND d.status NOT IN ('Livrée','Clôturée','Annulée') ORDER BY FIELD(d.priority,'Urgente','Haute','Normale','Basse'),d.scheduled_at LIMIT 6";return Database::connection()->query($sql)->fetchAll();
    }

    private static function today(): array
    {
        $sql="SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,c.company_name,dd.label site_name,dd.city,CONCAT(COALESCE(dr.first_name,''),' ',COALESCE(dr.last_name,'')) driver_name,v.registration_number FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id ORDER BY dx.stop_order LIMIT 1) LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE DATE(d.scheduled_at)=CURDATE() AND d.status<>'Annulée' ORDER BY d.scheduled_at,d.id LIMIT 10";return Database::connection()->query($sql)->fetchAll();
    }

    private static function rate(int $value,int $total): float{return $total>0?round($value/$total*100,1):0;}
}
