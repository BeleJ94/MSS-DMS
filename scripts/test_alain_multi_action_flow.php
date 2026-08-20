<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\DriverMission;

function expectAlainAction(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
$pdo=Database::connection();$deliveryIds=[];$references=[];$driverState=null;$vehicleState=null;
try{
    $resource=$pdo->query("SELECT u.id user_id,dr.id driver_id,dr.status driver_status,v.id vehicle_id,v.status vehicle_status,v.assigned_driver_id,d.client_id FROM users u JOIN drivers dr ON dr.user_id=u.id JOIN deliveries d ON d.driver_id=dr.id JOIN vehicles v ON v.id=d.vehicle_id WHERE u.email='alainr@gmail.com' LIMIT 1")->fetch();if(!$resource){throw new RuntimeException('Ressources d’Alain introuvables.');}
    Session::put('auth_user_id',(int)$resource['user_id']);$driverState=['id'=>$resource['driver_id'],'status'=>$resource['driver_status']];$vehicleState=['id'=>$resource['vehicle_id'],'status'=>$resource['vehicle_status'],'assigned_driver_id'=>$resource['assigned_driver_id']];
    $insert=$pdo->prepare('INSERT INTO deliveries (reference,client_id,scheduled_at,priority,driver_id,vehicle_id,status,created_by,updated_by) VALUES (:reference,:client,NOW(),"Normale",:driver,:vehicle,:status,:created_user,:updated_user)');$destination=$pdo->prepare('INSERT INTO delivery_destinations (delivery_id,stop_order,label,address_line,city,status) VALUES (:delivery,1,:label,"Adresse test","Lubumbashi","À livrer")');
    foreach(['Chargée','En transit'] as $index=>$status){$reference='TEST-ALAIN-ACTION-'.date('His').'-'.$index;$references[]=$reference;$insert->execute(['reference'=>$reference,'client'=>$resource['client_id'],'driver'=>$resource['driver_id'],'vehicle'=>$resource['vehicle_id'],'status'=>$status,'created_user'=>$resource['user_id'],'updated_user'=>$resource['user_id']]);$id=(int)$pdo->lastInsertId();$deliveryIds[]=$id;$destination->execute(['delivery'=>$id,'label'=>'Destination action '.($index+1)]);}
    $message=DriverMission::perform($deliveryIds[0],'start');expectAlainAction(strpos($message,'démarrée')!==false,'Alain démarre la première livraison qui lui est affectée');$status=$pdo->query('SELECT status FROM deliveries WHERE id='.(int)$deliveryIds[0])->fetchColumn();expectAlainAction($status==='En transit','seule la première livraison passe en transit');
    DriverMission::perform($deliveryIds[1],'arrive');$second=$pdo->query('SELECT status FROM deliveries WHERE id='.(int)$deliveryIds[1])->fetchColumn();$first=$pdo->query('SELECT status FROM deliveries WHERE id='.(int)$deliveryIds[0])->fetchColumn();expectAlainAction($second==='Arrivée','Alain confirme l’arrivée de la seconde livraison');expectAlainAction($first==='En transit','l’action sur la seconde ne modifie pas la première');
    $arrived=$pdo->query('SELECT COUNT(*) FROM delivery_destinations WHERE delivery_id='.(int)$deliveryIds[1].' AND status="Arrivée"')->fetchColumn();expectAlainAction((int)$arrived===1,'la destination exacte de la seconde livraison est mise à jour');
    echo "ALAIN_MULTI_ACTION_FLOW_OK\n";
}finally{foreach($deliveryIds as $id){$pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id'=>$id]);}foreach($references as $reference){$pdo->prepare('DELETE FROM vehicle_delivery_history WHERE delivery_reference=:reference')->execute(['reference'=>$reference]);$pdo->prepare('DELETE FROM driver_missions WHERE mission_reference=:reference')->execute(['reference'=>$reference]);}if($driverState){$pdo->prepare('UPDATE drivers SET status=:status WHERE id=:id')->execute($driverState);}if($vehicleState){$pdo->prepare('UPDATE vehicles SET status=:status,assigned_driver_id=:assigned_driver_id WHERE id=:id')->execute($vehicleState);}}
