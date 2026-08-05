<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\PodUpload;
use App\Core\Session;
use App\Models\Incident;

$pdo=Database::connection();$deliveryId=null;$incidentId=null;$driverState=null;$vehicleState=null;$reference='TEST-INC-'.date('YmdHis');
function expectIncident(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
function incidentTestPhoto():array{$image=imagecreatetruecolor(640,420);$bg=imagecolorallocate($image,235,239,244);$red=imagecolorallocate($image,180,67,81);imagefill($image,0,0,$bg);imagefilledrectangle($image,180,100,460,320,$red);ob_start();imagejpeg($image,null,85);$data=(string)ob_get_clean();imagedestroy($image);return PodUpload::signature('data:image/jpeg;base64,'.base64_encode($data));}
try{
    $resource=$pdo->query('SELECT dr.id driver_id,dr.user_id,dr.status driver_status,v.id vehicle_id,v.status vehicle_status,v.assigned_driver_id,d.client_id,d.client_site_id,d.client_contact_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN vehicles v ON v.id=d.vehicle_id WHERE dr.user_id IS NOT NULL LIMIT 1')->fetch();if(!$resource){throw new RuntimeException('Aucune ressource affectée disponible.');}
    $adminId=(int)$pdo->query('SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug="administrateur" AND u.is_active=1 LIMIT 1')->fetchColumn();if(!$adminId){throw new RuntimeException('Aucun administrateur actif.');}
    $driverState=['id'=>$resource['driver_id'],'status'=>$resource['driver_status']];$vehicleState=['id'=>$resource['vehicle_id'],'status'=>$resource['vehicle_status'],'assigned_driver_id'=>$resource['assigned_driver_id']];Session::put('auth_user_id',(int)$resource['user_id']);
    $insert=$pdo->prepare('INSERT INTO deliveries (reference,client_id,client_site_id,client_contact_id,scheduled_at,priority,driver_id,vehicle_id,status,created_by,updated_by) VALUES (:reference,:client,:site,:contact,NOW(),"Normale",:driver,:vehicle,"En transit",:created_user,:updated_user)');$insert->execute(['reference'=>$reference,'client'=>$resource['client_id'],'site'=>$resource['client_site_id'],'contact'=>$resource['client_contact_id'],'driver'=>$resource['driver_id'],'vehicle'=>$resource['vehicle_id'],'created_user'=>$resource['user_id'],'updated_user'=>$resource['user_id']]);$deliveryId=(int)$pdo->lastInsertId();
    $incidentId=Incident::reportOwned($deliveryId,['incident_type'=>'panne','description'=>'Panne moteur constatée pendant le trajet de test.','latitude'=>-11.6647,'longitude'=>27.4794,'accuracy'=>7.2],[incidentTestPhoto()]);
    $incident=Incident::find($incidentId);expectIncident($incident&&$incident['incident_type']==='panne','le type et la description sont enregistrés');expectIncident(count($incident['photos'])===1,'les photos sont enregistrées de façon sécurisée');expectIncident(abs((float)$incident['latitude']-(-11.6647))<.00001,'le GPS est capturé');
    $delivery=$pdo->query('SELECT status,status_before_incident FROM deliveries WHERE id='.(int)$deliveryId)->fetch();expectIncident($delivery['status']==='Incident'&&$delivery['status_before_incident']==='En transit','la livraison est suspendue avec son statut précédent');
    expectIncident($pdo->query('SELECT status FROM drivers WHERE id='.(int)$resource['driver_id'])->fetchColumn()==='Indisponible','le chauffeur est marqué indisponible');expectIncident($pdo->query('SELECT status FROM vehicles WHERE id='.(int)$resource['vehicle_id'])->fetchColumn()==='Indisponible','le véhicule est marqué indisponible');
    expectIncident(Incident::openCount()>=1,'le dashboard compte l’incident non résolu');
    Session::put('auth_user_id',$adminId);Incident::update($incidentId,['responsible_user_id'=>$adminId,'corrective_action'=>'Diagnostic et remplacement de la pièce défectueuse.','status'=>'En traitement']);$updated=Incident::find($incidentId);expectIncident($updated['status']==='En traitement'&&(int)$updated['responsible_user_id']===$adminId,'le responsable et l’action corrective sont enregistrés');
    Incident::resolve($incidentId,['corrective_action'=>'Pièce remplacée et essai routier effectué.','resolution'=>'Véhicule fonctionnel, reprise de la mission autorisée.']);$resolved=Incident::find($incidentId);expectIncident($resolved['status']==='Résolu'&&!empty($resolved['resolved_at']),'la résolution est horodatée et attribuée');
    expectIncident($pdo->query('SELECT status FROM deliveries WHERE id='.(int)$deliveryId)->fetchColumn()==='En transit','la livraison reprend son statut précédent');expectIncident($pdo->query('SELECT status FROM drivers WHERE id='.(int)$resource['driver_id'])->fetchColumn()==='En mission','le chauffeur reprend sa mission');expectIncident($pdo->query('SELECT status FROM vehicles WHERE id='.(int)$resource['vehicle_id'])->fetchColumn()==='En livraison','le véhicule reprend la livraison');echo "INCIDENT_FLOW_OK\n";
}finally{if($incidentId){$pdo->prepare('DELETE FROM driver_incidents WHERE id=:id')->execute(['id'=>$incidentId]);}if($deliveryId){$pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id'=>$deliveryId]);}if($driverState){$pdo->prepare('UPDATE drivers SET status=:status WHERE id=:id')->execute($driverState);}if($vehicleState){$pdo->prepare('UPDATE vehicles SET status=:status,assigned_driver_id=:assigned_driver_id WHERE id=:id')->execute($vehicleState);}}
