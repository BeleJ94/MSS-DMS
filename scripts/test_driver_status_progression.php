<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Models\Delivery;
use App\Models\DeliveryPod;
use App\Models\DriverMission;
use App\Models\GpsTracking;

function expectDriverProgress(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}

$pdo=Database::connection();$deliveryId=null;$reference='';$driverState=null;$vehicleState=null;
try{
    $resource=$pdo->query("SELECT u.id user_id,dr.id driver_id,dr.status driver_status,v.id vehicle_id,v.status vehicle_status,v.assigned_driver_id,d.client_id FROM users u JOIN drivers dr ON dr.user_id=u.id JOIN deliveries d ON d.driver_id=dr.id JOIN vehicles v ON v.id=d.vehicle_id WHERE u.is_active=1 AND dr.is_active=1 LIMIT 1")->fetch();
    if(!$resource){throw new RuntimeException('Ressources chauffeur requises.');}
    Session::put('auth_user_id',(int)$resource['user_id']);$driverState=['id'=>$resource['driver_id'],'status'=>$resource['driver_status']];$vehicleState=['id'=>$resource['vehicle_id'],'status'=>$resource['vehicle_status'],'assigned_driver_id'=>$resource['assigned_driver_id']];
    $deliveryId=Delivery::create(['client_id'=>$resource['client_id'],'scheduled_at'=>date('Y-m-d\TH:i',time()+3600),'priority'=>'Normale','driver_id'=>$resource['driver_id'],'vehicle_id'=>$resource['vehicle_id'],'observations'=>'Test progression chauffeur','destinations'=>[['label'=>'Destination test','address'=>'Adresse test','city'=>'Lubumbashi']],'goods'=>[['destination_index'=>0,'description'=>'Colis test','quantity'=>1,'unit'=>'pièce','unit_weight_kg'=>2]]]);$initial=Delivery::find($deliveryId);$reference=(string)$initial['reference'];expectDriverProgress($initial['status']==='Affectée','une création avec chauffeur et véhicule transmet automatiquement la mission');
    $mission=DriverMission::findOwned($deliveryId);$html=View::render('driver-app/show',['title'=>$mission['reference'],'mission'=>$mission,'pod'=>DeliveryPod::summary($deliveryId),'pods'=>DeliveryPod::summaries($deliveryId),'driver'=>DriverMission::driver(),'activeMissionId'=>GpsTracking::activeMissionId(),'baseUrl'=>''],'layouts/driver-app');expectDriverProgress(strpos($html,'PROGRESSION DE LA MISSION')!==false&&strpos($html,'Accepter la mission')!==false,'la fiche chauffeur affiche la progression et une action contextuelle unique');expectDriverProgress(strpos($html,'mission-hero stage-assigned')!==false,'la mission affectée reçoit son accent visuel sobre');
    $steps=[['accept','À préparer'],['prepare','Prête'],['load','Chargement'],['loaded','Chargée'],['start','En transit'],['arrive','Arrivée'],['unload','Déchargement']];
    foreach($steps as $step){DriverMission::perform($deliveryId,$step[0]);$status=$pdo->query('SELECT status FROM deliveries WHERE id='.(int)$deliveryId)->fetchColumn();expectDriverProgress($status===$step[1],$step[0].' fait progresser la mission vers '.$step[1]);}
    $goods=$pdo->query('SELECT id,quantity FROM delivery_goods WHERE delivery_id='.(int)$deliveryId)->fetch();DriverMission::confirmUnloading($deliveryId,[['id'=>$goods['id'],'delivered_quantity'=>$goods['quantity'],'condition'=>'Conforme','note'=>'']]);$mission=DriverMission::findOwned($deliveryId);expectDriverProgress($mission['unloading_complete']===true,'le contrôle complet du déchargement autorise la confirmation de livraison');
    $history=(int)$pdo->query('SELECT COUNT(*) FROM delivery_status_history WHERE delivery_id='.(int)$deliveryId)->fetchColumn();expectDriverProgress($history===9,'la création et chaque transition, y compris Déchargement, sont journalisées');
    $rejected=false;try{DriverMission::perform($deliveryId,'loaded');}catch(RuntimeException $e){$rejected=true;}expectDriverProgress($rejected,'une action incohérente avec le statut courant est refusée');
    expectDriverProgress(DriverMission::nextAction('Chargement')['label']==='Confirmer le chargement'&&DriverMission::nextAction('Chargée')['label']==='Confirmer le départ','le chauffeur dispose explicitement des actions chargement et départ');
    echo "DRIVER_STATUS_PROGRESSION_OK\n";
}finally{
    if($deliveryId){$pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id'=>$deliveryId]);}$pdo->prepare('DELETE FROM vehicle_delivery_history WHERE delivery_reference=:reference')->execute(['reference'=>$reference]);$pdo->prepare('DELETE FROM driver_missions WHERE mission_reference=:reference')->execute(['reference'=>$reference]);if($driverState){$pdo->prepare('UPDATE drivers SET status=:status WHERE id=:id')->execute($driverState);}if($vehicleState){$pdo->prepare('UPDATE vehicles SET status=:status,assigned_driver_id=:assigned_driver_id WHERE id=:id')->execute($vehicleState);}
}
