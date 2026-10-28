<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class MailHistory
{
    public const STATUSES=['pending'=>'En attente','sent'=>'Envoyé','failed'=>'Échec','processed'=>'Préparé'];

    public static function filters(array $input): array
    {
        $f=['kind'=>($input['kind']??'')==='events'?'events':'mails','q'=>'','status'=>'','event'=>'','from'=>'','to'=>'','p'=>1];
        foreach(['q','status','event','from','to'] as $key){$f[$key]=is_string($input[$key]??null)?mb_substr(trim($input[$key]),0,190):'';}
        $f['q']=mb_substr($f['q'],0,100);
        if($f['status']!=='' && !in_array($f['status'],$f['kind']==='events'?['pending','processed','failed']:['pending','sent','failed'],true)){throw new RuntimeException('Choisissez un statut proposé dans la liste.');}
        if($f['event']!=='' && !isset(ClientNotification::EVENTS[$f['event']])){throw new RuntimeException('Choisissez un événement proposé dans la liste.');}
        foreach(['from','to'] as $key){if($f[$key]!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key]);if(!$date || $date->format('Y-m-d')!==$f[$key]){throw new RuntimeException('Saisissez une date valide au format année-mois-jour.');}}}
        if($f['from']!=='' && $f['to']!=='' && $f['from']>$f['to']){throw new RuntimeException('La date de début doit précéder la date de fin.');}
        $f['p']=max(1,(int)filter_var($input['p']??1,FILTER_VALIDATE_INT));
        return $f;
    }

    public static function search(array $filters): array
    {
        $pdo=Database::connection();$events=$filters['kind']==='events';
        $table=$events?'notification_events':'notification_outbox';$date=$events?'n.occurred_at':'n.created_at';
        $join=' FROM '.$table.' n JOIN deliveries d ON d.id=n.delivery_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=n.destination_id';
        $where=[];$params=[];
        if($filters['q']!==''){$columns=['d.reference','c.company_name','dd.label'];if(!$events){$columns[]='n.recipient_address';$columns[]='n.subject';}$parts=[];foreach($columns as $i=>$column){$key='q'.$i;$parts[]=$column.' LIKE :'.$key;$params[$key]='%'.strtr($filters['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}$where[]='('.implode(" ESCAPE '!' OR ",$parts)." ESCAPE '!')";}
        foreach(['status','event'] as $key){if($filters[$key]!==''){$where[]='n.'.$key.'=:'.$key;$params[$key]=$filters[$key];}}
        if($filters['from']!==''){$where[]=$date.'>=:from_date';$params['from_date']=$filters['from'].' 00:00:00';}
        if($filters['to']!==''){$where[]=$date.'<:to_date';$params['to_date']=(new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
        $sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare('SELECT n.status,COUNT(*) total'.$join.$sqlWhere.' GROUP BY n.status');$s->execute($params);$counts=array_fill_keys($events?['pending','processed','failed']:['pending','sent','failed'],0);foreach($s->fetchAll() as $row){$counts[$row['status']]=(int)$row['total'];}
        $total=array_sum($counts);$pages=max(1,(int)ceil($total/25));$page=min($pages,$filters['p']);
        $columns=$events?'n.id,n.delivery_id,n.destination_id,n.event,n.status,n.attempts,n.last_error,n.available_at,n.occurred_at created_at,n.processed_at finished_at,NULL recipient_address,NULL subject':'n.id,n.delivery_id,n.destination_id,n.event,n.status,n.attempts,n.last_error,n.available_at,n.created_at,n.sent_at finished_at,n.recipient_address,n.subject';
        $s=$pdo->prepare('SELECT '.$columns.',d.reference,c.company_name,dd.label destination_label,dd.stop_order'.$join.$sqlWhere.' ORDER BY '.$date.' DESC,n.id DESC LIMIT 25 OFFSET '.(($page-1)*25));$s->execute($params);
        return ['rows'=>$s->fetchAll(),'counts'=>$counts,'total'=>$total,'pages'=>$pages,'currentPage'=>$page];
    }

    public static function detail(int $id,string $kind): ?array
    {
        $events=$kind==='events';$table=$events?'notification_events':'notification_outbox';
        $s=Database::connection()->prepare('SELECT n.*,d.reference,c.company_name,dd.label destination_label,dd.stop_order FROM '.$table.' n JOIN deliveries d ON d.id=n.delivery_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations dd ON dd.id=n.destination_id WHERE n.id=?');$s->execute([$id]);return $s->fetch()?:null;
    }
}
