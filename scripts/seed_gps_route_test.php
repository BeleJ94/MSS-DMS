<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\DeliveryRouteHistory;
use App\Models\GpsTracking;
use App\Models\LiveTracking;

$pdo = Database::connection();
$resource = $pdo->query(
    "SELECT dr.id driver_id,dr.user_id,v.id vehicle_id,d.client_id,d.client_site_id,d.client_contact_id
     FROM deliveries d
     JOIN drivers dr ON dr.id=d.driver_id
     JOIN vehicles v ON v.id=d.vehicle_id
     WHERE dr.user_id IS NOT NULL
     ORDER BY (SELECT u.email='alainr@gmail.com' FROM users u WHERE u.id=dr.user_id) DESC,d.id DESC
     LIMIT 1"
)->fetch();
if (!$resource) { throw new RuntimeException('Aucune affectation chauffeur/véhicule ne permet de créer le trajet de test.'); }

$reference = 'TEST-GPS-HIST-' . date('Ymd-His');
$pdo->prepare(
    "INSERT INTO deliveries
     (reference,client_id,client_site_id,client_contact_id,scheduled_at,planning_duration_minutes,priority,driver_id,vehicle_id,status,observations,created_by,updated_by)
     VALUES (:reference,:client,:site,:contact,NOW(),120,'Normale',:driver,:vehicle,'En transit',:observations,:user,:user2)"
)->execute([
    'reference'=>$reference,'client'=>$resource['client_id'],'site'=>$resource['client_site_id'],
    'contact'=>$resource['client_contact_id'],'driver'=>$resource['driver_id'],'vehicle'=>$resource['vehicle_id'],
    'observations'=>'Livraison persistante créée pour contrôler le positionnement GPS historique.',
    'user'=>$resource['user_id'],'user2'=>$resource['user_id']
]);
$deliveryId = (int)$pdo->lastInsertId();
Session::put('auth_user_id', (int)$resource['user_id']);

$coordinates = [
    [-10.7187550,25.4991965],[-10.7182100,25.5000800],[-10.7175300,25.5011200],
    [-10.7167200,25.5020400],[-10.7158800,25.5031800],[-10.7149100,25.5042200],
    [-10.7138200,25.5053600],[-10.7127600,25.5066100],[-10.7116400,25.5078200],
    [-10.7104800,25.5090900],[-10.7092600,25.5104100],[-10.7080300,25.5117600]
];
$baseTime = time() - ((count($coordinates)-1) * 60);
$positions=[];$batchKey=bin2hex(random_bytes(8));
foreach($coordinates as $index=>$point){
    $positions[]=[
        'position_id'=>'persistent-'.$deliveryId.'-'.$batchKey.'-'.($index+1),
        'latitude'=>$point[0],'longitude'=>$point[1],'accuracy'=>8.0+($index%4),
        'altitude'=>1504.0+($index*.15),'speed'=>4.5+($index*.12),'heading'=>44.0,
        'captured_at'=>gmdate('c',$baseTime+($index*60))
    ];
}
$result=GpsTracking::recordBatch($deliveryId,$positions);
$count=(int)$pdo->query('SELECT COUNT(*) FROM delivery_gps_positions WHERE delivery_id='.$deliveryId)->fetchColumn();
$route=DeliveryRouteHistory::forDelivery($deliveryId);
$visible=false;foreach(LiveTracking::positions() as $row){if((int)$row['id']===$deliveryId){$visible=true;break;}}
if($result['accepted']!==count($positions)||$count!==count($positions)||!$route||$route['summary']['position_count']!==count($positions)||!$visible){
    throw new RuntimeException('La vérification du trajet persistant a échoué. Livraison conservée pour diagnostic : '.$deliveryId);
}
echo "GPS_PERSISTENT_OK\n";
echo "delivery_id={$deliveryId}\nreference={$reference}\npositions={$count}\n";
echo 'distance_km='.number_format((float)$route['summary']['distance_km'],3,'.','')."\n";
echo "status=En transit\nlive_tracking=visible\n";

