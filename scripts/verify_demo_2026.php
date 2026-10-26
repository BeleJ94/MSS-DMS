<?php

declare(strict_types=1);

ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\Dashboard;
use App\Models\Delivery;
use App\Models\DeliveryRouteHistory;
use App\Models\Dispatching;
use App\Models\DriverMission;
use App\Models\LiveTracking;
use App\Models\Planning;
use App\Models\Report;
use App\Models\ClientNotification;

$manifest=json_decode((string)file_get_contents(BASE_PATH.'/storage/cache/demo-2026-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=Database::connection();$checks=0;
function demoCheck(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);}$checks++;echo 'OK - '.$label.PHP_EOL;}
$backup=json_decode((string)gzdecode((string)file_get_contents($manifest['backup'])),true,512,JSON_THROW_ON_ERROR);
demoCheck($pdo->query('SELECT DATABASE()')->fetchColumn()===$backup['database'],'la vérification utilise la base sauvegardée');
$existingRows=0;
foreach($backup['tables'] as $table=>$snapshot){
    $pk=$pdo->prepare('SELECT column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND constraint_name="PRIMARY" ORDER BY ordinal_position');$pk->execute([$table]);$columns=$pk->fetchAll(PDO::FETCH_COLUMN);
    if(!$snapshot['rows']){continue;}
    demoCheck(count($columns)>0,'clé de vérification : '.$table);
    $lookup=$pdo->prepare('SELECT * FROM `'.$table.'` WHERE '.implode(' AND ',array_map(static function(string $column):string{return '`'.$column.'`=?';},$columns)));
    foreach($snapshot['rows'] as $row){
        $lookup->execute(array_map(static function(string $column) use($row){return base64_decode($row[$column]);},$columns));$current=$lookup->fetch();
        if(!$current){throw new RuntimeException('Une ligne préexistante est absente : '.$table);}
        foreach($row as $column=>$encoded){$value=$current[$column]===null?null:base64_encode((string)$current[$column]);if($value!==$encoded){throw new RuntimeException('Une donnée préexistante a changé : '.$table.'.'.$column);}}
        $existingRows++;
    }
}
demoCheck($existingRows>0,'toutes les '.$existingRows.' lignes préexistantes sont conservées à l’identique');
$range=$pdo->query('SELECT MIN(scheduled_at) first_date,MAX(scheduled_at) last_date,COUNT(*) total FROM deliveries WHERE reference LIKE "DEMO-2026-%"')->fetch();
demoCheck((int)$range['total']===$manifest['counts']['deliveries'],'le nombre de livraisons correspond au manifeste');
demoCheck(substr($range['first_date'],0,10)==='2026-01-01' && substr($range['last_date'],0,10)===$manifest['planning_until'],'la période historique et le planning sont couverts');
$dashboard=Dashboard::data();demoCheck((int)$dashboard['kpis']['deliveries_today']>0 && (int)$dashboard['kpis']['open_incidents']>0,'le tableau de bord contient de l’activité et des alertes');
$report=Report::operational(30);demoCheck(!empty($report),'le rapport opérationnel sur 30 jours est calculable');
$report=Report::operational(365);demoCheck(!empty($report),'le rapport annuel est calculable');
$entries=Planning::entries(['start'=>date('Y-m-d 00:00:00'),'end'=>date('Y-m-d 00:00:00',strtotime('+8 days'))]);demoCheck(!empty($entries),'le planning affiche les missions en cours et à venir');
demoCheck(count(Dispatching::board())>0,'le dispatching contient des missions à affecter');
$live=LiveTracking::positions();demoCheck(count($live)>0,'le suivi cartographique expose les missions actives');
$id=(int)$pdo->query('SELECT id FROM deliveries WHERE reference="'.$manifest['examples']['Clôturée'].'"')->fetchColumn();
$delivery=Delivery::find($id);demoCheck($delivery && $delivery['pods']!==[],'une livraison terminée expose ses preuves et son historique');
$test=ClientNotification::prepareTest($pdo,$id,'delivery',(int)$delivery['destinations'][0]['id'],true);
demoCheck(!empty($test['html']) && strpos($test['attachments'][0]['data'],'%PDF-')===0,'un test de notification peut préparer le message HTML et le bon PDF');
$routeId=(int)$pdo->query('SELECT d.id FROM deliveries d JOIN delivery_gps_positions g ON g.delivery_id=d.id WHERE d.reference LIKE "DEMO-2026-%" GROUP BY d.id ORDER BY d.id DESC LIMIT 1')->fetchColumn();
$route=DeliveryRouteHistory::forDelivery($routeId);demoCheck($route && $route['summary']['position_count']>=30,'un trajet GPS de démonstration est exploitable');
$driver=$pdo->prepare('SELECT u.id,d.id driver_id FROM users u JOIN drivers d ON d.user_id=u.id WHERE u.email=?');$driver->execute([$manifest['driver_login']]);$driver=$driver->fetch();Session::put('auth_user_id',(int)$driver['id']);
$missions=DriverMission::listing();demoCheck(count($missions)>0,'le compte chauffeur DEMO dispose de missions accessibles');
$todayMission=(int)$pdo->query('SELECT id FROM deliveries WHERE reference="'.$manifest['examples']['Affectée'].'"')->fetchColumn();demoCheck(DriverMission::findOwned($todayMission)!==null,'le chauffeur DEMO peut ouvrir sa mission affectée');
$pending=(int)$pdo->query('SELECT COUNT(*) FROM notification_outbox n JOIN deliveries d ON d.id=n.delivery_id WHERE d.reference LIKE "DEMO-2026-%" AND n.status="pending"')->fetchColumn();demoCheck($pending===0,'aucune notification DEMO ne reste en attente d’envoi réel');
echo 'DEMO_VERIFIED: '.$checks.' checks; existing_rows='.$existingRows.PHP_EOL;
echo 'STATUS_DISTRIBUTION '.json_encode($pdo->query('SELECT status,COUNT(*) total FROM deliveries WHERE reference LIKE "DEMO-2026-%" GROUP BY status ORDER BY total DESC')->fetchAll(),JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'DASHBOARD_KPIS '.json_encode($dashboard['kpis'],JSON_UNESCAPED_UNICODE).PHP_EOL;
Session::forget('auth_user_id');session_write_close();
