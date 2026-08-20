<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\PodPdf;
use App\Core\PodUpload;
use App\Core\Session;
use App\Models\DeliveryPod;
use App\Models\Delivery;

$pdo = Database::connection();
$deliveryId = null; $reference = 'TEST-POD-'.date('YmdHis'); $driverState = null; $vehicleState = null;

function expectPod(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } echo "OK - {$message}\n"; }
function testImageDataUrl(): string { $image=imagecreatetruecolor(600,220);$white=imagecolorallocate($image,255,255,255);$blue=imagecolorallocate($image,22,58,103);imagefill($image,0,0,$white);imagesetthickness($image,5);imageline($image,80,150,220,70,$blue);imageline($image,220,70,420,145,$blue);ob_start();imagejpeg($image,null,88);$data=(string)ob_get_clean();imagedestroy($image);return 'data:image/jpeg;base64,'.base64_encode($data); }

try {
    $resource = $pdo->query('SELECT dr.id driver_id,dr.user_id,dr.status driver_status,v.id vehicle_id,v.status vehicle_status,v.assigned_driver_id,d.client_id,d.client_site_id,d.client_contact_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN vehicles v ON v.id=d.vehicle_id WHERE dr.user_id IS NOT NULL LIMIT 1')->fetch();
    if (!$resource) { throw new RuntimeException('Aucune affectation chauffeur/véhicule disponible pour le test.'); }
    $driverState = ['id'=>$resource['driver_id'],'status'=>$resource['driver_status']];
    $vehicleState = ['id'=>$resource['vehicle_id'],'status'=>$resource['vehicle_status'],'assigned_driver_id'=>$resource['assigned_driver_id']];
    Session::put('auth_user_id',(int)$resource['user_id']);
    $statement=$pdo->prepare('INSERT INTO deliveries (reference,client_id,client_site_id,client_contact_id,scheduled_at,priority,driver_id,vehicle_id,status,created_by,updated_by) VALUES (:reference,:client,:site,:contact,NOW(),"Normale",:driver,:vehicle,"Déchargement",:created_user,:updated_user)');
    $statement->execute(['reference'=>$reference,'client'=>$resource['client_id'],'site'=>$resource['client_site_id'],'contact'=>$resource['client_contact_id'],'driver'=>$resource['driver_id'],'vehicle'=>$resource['vehicle_id'],'created_user'=>$resource['user_id'],'updated_user'=>$resource['user_id']]);
    $deliveryId=(int)$pdo->lastInsertId();
    $destination=$pdo->prepare('INSERT INTO delivery_destinations (delivery_id,stop_order,label,address_line,city,status,arrived_at) VALUES (:delivery,:position,:label,:address,"Lubumbashi","Déchargement",NOW())');
    $destination->execute(['delivery'=>$deliveryId,'position'=>1,'label'=>'Destination Test 1','address'=>'Adresse libre 1']);$destinationOne=(int)$pdo->lastInsertId();
    $destination->execute(['delivery'=>$deliveryId,'position'=>2,'label'=>'Destination Test 2','address'=>'Adresse libre 2']);$destinationTwo=(int)$pdo->lastInsertId();
    $goods=$pdo->prepare('INSERT INTO delivery_goods (delivery_id,destination_id,description_snapshot,quantity,unit,delivered_quantity,delivery_condition,checked_at,checked_by) VALUES (:delivery,:destination,"Colis test",1,"pièce",1,"Conforme",NOW(),:user)');$goods->execute(['delivery'=>$deliveryId,'destination'=>$destinationOne,'user'=>$resource['user_id']]);$goods->execute(['delivery'=>$deliveryId,'destination'=>$destinationTwo,'user'=>$resource['user_id']]);
    $signature=PodUpload::signature(testImageDataUrl());$photo=$signature;
    DeliveryPod::createOwned($deliveryId,$destinationOne,['recipient_name'=>'Réceptionnaire Test 1','observations'=>'Premier arrêt reçu.','latitude'=>-11.6647,'longitude'=>27.4794,'accuracy'=>8.5],$signature,$photo,$photo);
    $delivery=$pdo->query('SELECT status,delivered_at FROM deliveries WHERE id='.(int)$deliveryId)->fetch();
    expectPod($delivery['status']==='En transit','le premier bon maintient la course en transit');
    expectPod(empty($delivery['delivered_at']),'la course reste ouverte après la première destination');
    $pdo->prepare('UPDATE deliveries SET status="Déchargement" WHERE id=:id')->execute(['id'=>$deliveryId]);$pdo->prepare('UPDATE delivery_destinations SET status="Déchargement",arrived_at=NOW() WHERE id=:id')->execute(['id'=>$destinationTwo]);
    DeliveryPod::createOwned($deliveryId,$destinationTwo,['recipient_name'=>'Réceptionnaire Test 2','observations'=>'Dernier arrêt reçu.','latitude'=>-11.6648,'longitude'=>27.4795,'accuracy'=>7.5],$signature,$photo,$photo);
    $delivery=$pdo->query('SELECT status,delivered_at FROM deliveries WHERE id='.(int)$deliveryId)->fetch();
    expectPod($delivery['status']==='Livrée','le dernier bon passe la course à Livrée');
    expectPod(!empty($delivery['delivered_at']),'la date de livraison est capturée automatiquement');
    $pod=DeliveryPod::findByDestination($deliveryId,$destinationTwo);
    expectPod($pod!==null&&$pod['recipient_name']==='Réceptionnaire Test 2','le réceptionnaire et la preuve du second arrêt sont enregistrés');
    expectPod(count(DeliveryPod::summaries($deliveryId))===2,'un bon distinct est créé pour chaque destination');
    expectPod(!empty($pod['signed_note_data']),'la photo facultative du bon signé est conservée');
    expectPod((int)$pod['driver_id']===(int)$resource['driver_id']&&(int)$pod['vehicle_id']===(int)$resource['vehicle_id'],'le chauffeur et le véhicule sont capturés');
    $listedPod = null;
    foreach (Delivery::listing(['search'=>$reference]) as $listedDelivery) { if ((int)$listedDelivery['id'] === $deliveryId) { $listedPod = $listedDelivery; break; } }
    expectPod($listedPod !== null && (int)$listedPod['has_pod'] === 1,'la liste des livraisons expose la disponibilité du PDF');
    expectPod(abs((float)$pod['latitude']-(-11.6648))<0.00001,'les coordonnées GPS du second bon sont enregistrées');
    $pdf=PodPdf::render($pod);
    expectPod(strncmp($pdf,'%PDF-1.4',8)===0&&strlen($pdf)>5000,'le PDF professionnel est généré');
    if (in_array('--keep-pdf', $argv, true)) { file_put_contents(sys_get_temp_dir().'/mss-dms-pod-test.pdf', $pdf); }
    $history=$pdo->query('SELECT COUNT(*) FROM delivery_status_history WHERE delivery_id='.(int)$deliveryId.' AND to_status="Livrée"')->fetchColumn();
    expectPod((int)$history===1,'la course est finalisée une seule fois après le dernier bon');
    echo "POD_FLOW_OK\n";
} finally {
    if ($deliveryId) { $pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id'=>$deliveryId]); }
    if ($driverState) { $pdo->prepare('UPDATE drivers SET status=:status WHERE id=:id')->execute($driverState); }
    if ($vehicleState) { $pdo->prepare('UPDATE vehicles SET status=:status,assigned_driver_id=:assigned_driver_id WHERE id=:id')->execute($vehicleState); }
    $pdo->prepare('DELETE FROM vehicle_delivery_history WHERE delivery_reference=:reference')->execute(['reference'=>$reference]);
    $pdo->prepare('DELETE FROM driver_missions WHERE mission_reference=:reference')->execute(['reference'=>$reference]);
}
