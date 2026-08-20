<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Report
{
    public static function details(string $type,int $days,string $value=''): array
    {
        $allowed=['planned','delivered','completion','punctuality','overdue','unassigned','incidents','discrepancies','day','client'];
        if(!in_array($type,$allowed,true)){throw new \InvalidArgumentException('Type de détail invalide.');}
        $pdo=Database::connection();$since=date('Y-m-d 00:00:00',strtotime('-'.($days-1).' days'));$params=['since'=>$since];
        if($type==='incidents'){$params=[];$sql="SELECT i.incident_reference reference,i.incident_type type,i.severity,i.status,i.occurred_at date,COALESCE(d.reference,'—') livraison FROM driver_incidents i LEFT JOIN deliveries d ON d.id=i.delivery_id WHERE i.status<>'Résolu' ORDER BY i.occurred_at DESC";$columns=self::columns(['reference'=>'Incident','type'=>'Type','severity'=>'Gravité','status'=>'Statut','date'=>'Date','livraison'=>'Livraison']);$title='Incidents ouverts';}
        else{
            $where=['d.scheduled_at>=:since'];$title='Courses planifiées';
            if($type==='delivered'||$type==='completion'){$where[]="d.status IN ('Livrée','Clôturée')";$title='Courses livrées';}
            elseif($type==='punctuality'){$where[]="d.delivered_at IS NOT NULL AND d.delivered_at<=d.scheduled_at";$title='Livraisons réalisées à temps';}
            elseif($type==='overdue'){$where=['d.scheduled_at<NOW()',"d.status NOT IN ('Livrée','Clôturée','Annulée')"];$params=[];$title='Livraisons en retard';}
            elseif($type==='unassigned'){$where=["d.driver_id IS NULL","d.status NOT IN ('Livrée','Clôturée','Annulée')"];$params=[];$title='Courses non affectées';}
            elseif($type==='discrepancies'){$where[]='EXISTS(SELECT 1 FROM delivery_goods gx WHERE gx.delivery_id=d.id AND gx.delivered_quantity IS NOT NULL AND ABS(gx.delivered_quantity-gx.quantity)>.0001)';$title='Livraisons avec écarts';}
            elseif($type==='day'){$where[]='DATE(d.scheduled_at)=:value';$params['value']=$value;$title='Activité du '.date('d/m/Y',strtotime($value));}
            elseif($type==='client'){$where[]='c.company_name=:value';$params['value']=$value;$title='Courses · '.$value;}
            $sql="SELECT d.reference,c.company_name client,COALESCE(dd.label,'—') destination,d.scheduled_at date_prevue,d.status,COALESCE(CONCAT(dr.first_name,' ',dr.last_name),'Non affecté') chauffeur,COALESCE(v.registration_number,'—') vehicule,(SELECT COUNT(*) FROM delivery_goods g WHERE g.delivery_id=d.id AND g.delivered_quantity IS NOT NULL AND ABS(g.delivered_quantity-g.quantity)>.0001) ecarts FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN drivers dr ON dr.id=d.driver_id LEFT JOIN vehicles v ON v.id=d.vehicle_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT x.id FROM delivery_destinations x WHERE x.delivery_id=d.id ORDER BY x.stop_order LIMIT 1) WHERE ".implode(' AND ',$where).' ORDER BY d.scheduled_at DESC LIMIT 500';
            $columns=self::columns(['reference'=>'Référence','client'=>'Client','destination'=>'Destination','date_prevue'=>'Date prévue','status'=>'Statut','chauffeur'=>'Chauffeur','vehicule'=>'Véhicule','ecarts'=>'Écarts']);
        }
        $statement=$pdo->prepare($sql);$statement->execute($params);return ['title'=>$title,'slug'=>$type,'columns'=>$columns,'rows'=>$statement->fetchAll(),'count'=>$statement->rowCount()];
    }

    private static function columns(array $columns): array{foreach($columns as $key=>$label){$result[]=['key'=>$key,'label'=>$label];}return $result??[];}

    public static function operational(int $days): array
    {
        $pdo = Database::connection();
        $since = date('Y-m-d 00:00:00', strtotime('-'.($days - 1).' days'));
        $until = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $previousSince = date('Y-m-d 00:00:00', strtotime('-'.($days * 2 - 1).' days'));
        $previousUntil = $since;
        $summaryRow = self::summary($since, $until);
        $previous = self::summary($previousSince, $previousUntil);
        foreach (['planned','delivered','completion_rate','punctuality_rate','planned_weight'] as $metric) {
            $summaryRow[$metric.'_change'] = self::change((float)$summaryRow[$metric], (float)$previous[$metric]);
        }

        $clients = $pdo->prepare("SELECT c.company_name,COUNT(*) deliveries,SUM(d.status IN ('Livrée','Clôturée')) delivered,
            ROUND(100*SUM(d.status IN ('Livrée','Clôturée'))/COUNT(*),1) completion_rate
            FROM deliveries d JOIN clients c ON c.id=d.client_id WHERE d.scheduled_at>=:since
            GROUP BY c.id,c.company_name ORDER BY deliveries DESC,c.company_name LIMIT 8");
        $clients->execute(['since' => $since]);

        $recent = $pdo->prepare("SELECT d.id,d.reference,d.delivered_at,c.company_name,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(COALESCE(g.delivered_quantity,g.quantity),3))), ' ', g.unit) SEPARATOR ' · ') FROM delivery_goods g WHERE g.delivery_id=d.id),'—') delivered_summary,
            (SELECT COUNT(*) FROM delivery_goods g WHERE g.delivery_id=d.id AND g.delivered_quantity IS NOT NULL AND ABS(g.delivered_quantity-g.quantity)>.0001) differences
            FROM deliveries d JOIN clients c ON c.id=d.client_id
            WHERE d.delivered_at IS NOT NULL AND d.delivered_at>=:since ORDER BY d.delivered_at DESC LIMIT 12");
        $recent->execute(['since' => $since]);

        $trend = $pdo->prepare("SELECT DATE(scheduled_at) day,COUNT(*) planned,SUM(status IN ('Livrée','Clôturée')) delivered FROM deliveries WHERE scheduled_at>=:since GROUP BY DATE(scheduled_at) ORDER BY day");
        $trend->execute(['since'=>$since]);
        $actionsStatement = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM deliveries WHERE scheduled_at<NOW() AND status NOT IN ('Livrée','Clôturée','Annulée')) overdue,
            (SELECT COUNT(*) FROM deliveries WHERE driver_id IS NULL AND status NOT IN ('Livrée','Clôturée','Annulée')) unassigned,
            (SELECT COUNT(*) FROM driver_incidents WHERE status<>'Résolu') incidents,
            (SELECT COUNT(DISTINCT g.delivery_id) FROM delivery_goods g JOIN deliveries d ON d.id=g.delivery_id WHERE d.scheduled_at>=:since AND g.checked_at IS NOT NULL AND g.delivered_quantity IS NOT NULL AND ABS(g.delivered_quantity-g.quantity)>.0001) discrepancies");
        $actionsStatement->execute(['since'=>$since]);$actions=$actionsStatement->fetch();
        $attention = $pdo->query("SELECT d.id,d.reference,d.scheduled_at,d.status,d.priority,c.company_name,dd.label destination,
            TIMESTAMPDIFF(MINUTE,d.scheduled_at,NOW()) delay_minutes
            FROM deliveries d JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=(SELECT x.id FROM delivery_destinations x WHERE x.delivery_id=d.id AND x.status NOT IN ('Livrée','Annulée') ORDER BY x.stop_order LIMIT 1)
            WHERE d.scheduled_at<NOW() AND d.status NOT IN ('Livrée','Clôturée','Annulée') ORDER BY FIELD(d.priority,'Urgente','Haute','Normale','Basse'),d.scheduled_at LIMIT 8")->fetchAll();
        $insights = self::insights($summaryRow, $actions, $clients->fetchAll());

        return ['summary'=>$summaryRow,'previous'=>$previous,'clients'=>$insights['clients'],'recentDeliveries'=>$recent->fetchAll(),'trend'=>$trend->fetchAll(),'actions'=>$actions,'attention'=>$attention,'insights'=>$insights['items']];
    }

    private static function summary(string $since,string $until): array
    {
        $summary = Database::connection()->prepare("SELECT COUNT(*) planned,
            SUM(status IN ('Livrée','Clôturée')) delivered,
            SUM(status='Annulée') cancelled,
            SUM(delivered_at IS NOT NULL AND delivered_at<=scheduled_at) on_time,
            SUM(delivered_at IS NOT NULL AND delivered_at>scheduled_at) late,
            COALESCE(SUM((SELECT SUM(g.quantity*g.unit_weight_kg) FROM delivery_goods g WHERE g.delivery_id=d.id)),0) planned_weight
            FROM deliveries d WHERE d.scheduled_at>=:since AND d.scheduled_at<:until");
        $summary->execute(['since'=>$since,'until'=>$until]);
        $summaryRow = $summary->fetch();
        $delivered = (int) $summaryRow['delivered'];
        $onTime = (int) $summaryRow['on_time'];
        $summaryRow['completion_rate'] = (int) $summaryRow['planned'] > 0 ? round($delivered / (int) $summaryRow['planned'] * 100, 1) : 0;
        $summaryRow['punctuality_rate'] = $delivered > 0 ? round($onTime / $delivered * 100, 1) : 0;

        return $summaryRow;
    }

    private static function change(float $current,float $previous): ?float
    {
        return $previous == 0.0 ? null : round(($current-$previous)/$previous*100,1);
    }

    private static function insights(array $summary,array $actions,array $clients): array
    {
        $items=[];
        $items[]=['tone'=>(float)$summary['completion_rate']>=90?'positive':'warning','title'=>'Taux de réalisation','text'=>number_format((float)$summary['completion_rate'],1,',',' ').' % des courses planifiées ont été livrées.'];
        if((int)$actions['overdue']>0){$items[]=['tone'=>'danger','title'=>'Retards à traiter','text'=>(int)$actions['overdue'].' course(s) ont dépassé leur horaire prévu et nécessitent une décision.'];}
        elseif((int)$actions['incidents']>0){$items[]=['tone'=>'warning','title'=>'Incidents ouverts','text'=>(int)$actions['incidents'].' incident(s) restent à résoudre.'];}
        else{$items[]=['tone'=>'positive','title'=>'Exploitation maîtrisée','text'=>'Aucune course en retard ni incident critique à signaler.'];}
        if($clients){$leader=$clients[0];$items[]=['tone'=>'neutral','title'=>'Client principal','text'=>$leader['company_name'].' concentre '.$leader['deliveries'].' course(s) sur la période.'];}
        return ['items'=>array_slice($items,0,3),'clients'=>$clients];
    }
}
