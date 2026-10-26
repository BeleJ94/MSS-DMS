<?php

declare(strict_types=1);

/** Real routes and database, rolled back at the end. No queue worker or external mail. */
ini_set('session.save_path',sys_get_temp_dir());
$app=require dirname(__DIR__).'/bootstrap/app.php';
require BASE_PATH.'/routes/web.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Models\DriverMission;
use App\Models\Incident;
use App\Models\MobileMission;

$pdo=Database::connection();$checks=0;$suffix=bin2hex(random_bytes(4));
function driverCheck(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);}$checks++;echo 'OK - '.$label.PHP_EOL;}
function driverIdentity(?int $id):void{if($id===null){Session::forget('auth_user_id');}else{Session::put('auth_user_id',$id);}Session::forget('mobile_session_id');$property=new ReflectionProperty(Auth::class,'user');$property->setAccessible(true);$property->setValue(null,null);}
function driverResponse(string $method,string $path,array $body=[],array $files=[]):array{
    global $app;
    $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REQUEST_URI']='/MSS-DMS/public'.$path;$_SERVER['SCRIPT_NAME']='/MSS-DMS/public/index.php';$_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$_GET=[];$_POST=$body;$_FILES=$files;
    $level=ob_get_level();
    try{$response=$app->router()->dispatch(Request::capture());}catch(Throwable $e){while(ob_get_level()>$level){ob_end_clean();}throw $e;}
    $result=[];foreach(['status','content','headers'] as $key){$property=new ReflectionProperty($response,$key);$property->setAccessible(true);$result[$key]=$property->getValue($response);}
    $result['json']=json_decode($result['content'],true);return $result;
}
function driverInsert(string $table,array $data):int{global $pdo;$s=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$pdo->lastInsertId();}
function driverStatus(int $id):string{global $pdo;return (string)$pdo->query('SELECT status FROM deliveries WHERE id='.$id)->fetchColumn();}
function driverAction(int $id,string $action,array $data=[]):array{return driverResponse('POST','/api/driver-app/missions/'.$id.'/action',array_merge(['_token'=>Csrf::token(),'action'=>$action],$data));}

$resource=$pdo->query('SELECT dr.*,v.id vehicle_id FROM drivers dr JOIN vehicles v ON v.code="DEMO-VEH-01" WHERE dr.code="DEMO-CH-01"')->fetch();
if(!$resource){throw new RuntimeException('Le compte chauffeur DEMO 01 est requis pour cet audit local.');}
$actor=(int)$resource['user_id'];$admin=(int)$pdo->query('SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug="administrateur" AND u.is_active=1 LIMIT 1')->fetchColumn();
$originalDriver=$pdo->query('SELECT * FROM drivers WHERE id='.(int)$resource['id'])->fetch();
$originalVehicle=$pdo->query('SELECT * FROM vehicles WHERE id='.(int)$resource['vehicle_id'])->fetch();
$photoPath=tempnam(sys_get_temp_dir(),'driver-audit-');
$image=imagecreatetruecolor(640,420);imagefill($image,0,0,imagecolorallocate($image,225,235,245));imagefilledrectangle($image,100,100,540,320,imagecolorallocate($image,50,95,150));imagejpeg($image,$photoPath,85);imagedestroy($image);
$upload=['name'=>'preuve.jpg','type'=>'image/jpeg','tmp_name'=>$photoPath,'error'=>UPLOAD_ERR_OK,'size'=>filesize($photoPath)];
$browserViews=[];$browserPassword=json_decode((string)file_get_contents(BASE_PATH.'/storage/cache/demo-2026-manifest.json'),true)['password'];
$browserCandidates=$pdo->query('SELECT d.id,d.reference,d.status,u.email,u.password_hash FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN users u ON u.id=dr.user_id WHERE d.reference LIKE "DEMO-%" AND u.is_active=1 AND dr.is_active=1 ORDER BY d.id ASC')->fetchAll();
$validBrowserUsers=[];
foreach($browserCandidates as $candidate){
    if(isset($browserViews[$candidate['status']])){continue;}
    if(!array_key_exists($candidate['email'],$validBrowserUsers)){$validBrowserUsers[$candidate['email']]=password_verify($browserPassword,$candidate['password_hash']);}
    if(!$validBrowserUsers[$candidate['email']]){continue;}
    unset($candidate['password_hash']);$browserViews[$candidate['status']]=$candidate;
}
$browserViews=array_values($browserViews);unset($browserPassword,$browserCandidates);
file_put_contents(BASE_PATH.'/storage/cache/driver-audit-views.json',json_encode($browserViews,JSON_UNESCAPED_UNICODE));
copy($photoPath,BASE_PATH.'/storage/cache/driver-audit-photo.jpg');
if(in_array('--browser-fixtures-only',$argv,true)){unlink($photoPath);session_write_close();echo "DRIVER_BROWSER_FIXTURES_READY\n";exit(0);}
$pdo->beginTransaction();
try{
    driverIdentity(null);
    driverCheck(driverResponse('GET','/driver-app')['status']===302,'une visite anonyme demande une connexion');
    driverCheck(driverResponse('POST','/api/driver-app/missions/1/action',['action'=>'start'])['status']===401,'une action anonyme renvoie une erreur JSON 401');
    $office=(int)$pdo->query('SELECT id FROM users WHERE email="demo.dispatcher@example.test"')->fetchColumn();
    driverIdentity($office);driverCheck(driverResponse('GET','/driver-app')['status']===403,'un compte sans accès chauffeur ne peut pas ouvrir la PWA');
    driverIdentity($actor);driverCheck(driverResponse('GET','/driver-app')['status']===200,'la liste des missions du compte DEMO est rendue');
    $states=$pdo->query('SELECT d.id,d.status,dr.user_id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE d.reference LIKE "DEMO-%" GROUP BY d.status ORDER BY d.status')->fetchAll();
    foreach($states as $state){driverIdentity((int)$state['user_id']);$view=driverResponse('GET','/driver-app/missions/'.$state['id']);driverCheck($view['status']===200,'la fiche « '.$state['status'].' » est rendue sans erreur');if(in_array($state['status'],['Livrée','Clôturée'],true)){driverCheck(strpos($view['content'],'DERNIÈRE DESTINATION')!==false && strpos($view['content'],'pod-complete-card')!==false,'la mission terminée conserve ses destinations et bons PDF');}}
    driverIdentity($actor);
    driverCheck(driverResponse('GET','/driver-app/missions/999999999')['status']===404,'une mission inexistante dispose d’une vue 404');
    $foreign=(int)$pdo->query('SELECT d.id FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id WHERE dr.user_id<>'.$actor.' LIMIT 1')->fetchColumn();
    driverCheck(driverResponse('GET','/driver-app/missions/'.$foreign)['status']===404,'le chauffeur ne peut pas consulter la mission d’un collègue');
    driverCheck(driverAction($foreign,'load')['status']===422,'le chauffeur ne peut pas modifier la mission d’un collègue');
    driverCheck(driverResponse('POST','/api/driver-app/missions/'.$foreign.'/action',['action'=>'load'])['status']===419,'les actions sont protégées par CSRF');
    $client=driverInsert('clients',['code'=>'AUDIT-DR-'.$suffix,'company_name'=>'Client audit chauffeur']);
    $delivery=driverInsert('deliveries',['reference'=>'AUDIT-DR-'.$suffix,'client_id'=>$client,'scheduled_at'=>date('Y-m-d H:i:s'),'driver_id'=>$resource['id'],'vehicle_id'=>$resource['vehicle_id'],'status'=>'Affectée','created_by'=>$actor,'updated_by'=>$actor]);
    $stops=[];$goods=[];
    for($i=1;$i<=2;$i++){$stops[]=driverInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>$i,'label'=>'Destination audit '.$i,'address_line'=>'Adresse audit '.$i,'city'=>'Lubumbashi','contact_name'=>'Réceptionnaire','notification_emails'=>'test@example.test']);$goods[]=driverInsert('delivery_goods',['delivery_id'=>$delivery,'destination_id'=>$stops[$i-1],'description_snapshot'=>'Colis audit '.$i,'quantity'=>10,'unit'=>'colis']);}
    driverCheck(driverAction($delivery,'load')['status']===200 && driverStatus($delivery)==='Chargée','le chargement progresse de façon cohérente jusqu’à Chargée');
    driverCheck(driverAction($delivery,'start')['status']===422 && driverStatus($delivery)==='Chargée','un départ sans GPS est refusé sans changer le statut');
    $invalid=['position_id'=>'invalid-'.$suffix,'latitude'=>999,'longitude'=>27.4,'accuracy'=>8,'captured_at'=>gmdate(DATE_ATOM)];
    driverCheck(driverAction($delivery,'start',['initial_position'=>$invalid])['status']===422 && driverStatus($delivery)==='Chargée','un départ avec GPS invalide annule toutes les transitions');
    driverCheck((int)$pdo->query('SELECT COUNT(*) FROM delivery_status_history WHERE delivery_id='.$delivery.' AND to_status="Partie"')->fetchColumn()===0,'un échec de départ ne laisse pas de fausse trace de départ');
    driverCheck((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery)->fetchColumn()===0,'un départ refusé ne crée pas de notification client');
    $position=['position_id'=>'start-'.$suffix,'latitude'=>-11.6647,'longitude'=>27.4794,'accuracy'=>8,'captured_at'=>gmdate(DATE_ATOM)];
    driverCheck(driverAction($delivery,'start',['initial_position'=>$position])['status']===200 && driverStatus($delivery)==='En transit','un départ valide enregistre la première position et la mission en transit');
    $positions='/api/driver-app/missions/'.$delivery.'/positions';
    $r=driverResponse('POST',$positions,['_token'=>Csrf::token(),'positions'=>[$position]]);driverCheck($r['status']===200 && $r['json']['data']['duplicates']===1,'une position GPS réessayée est reconnue sans doublon');
    $r=driverResponse('POST',$positions,['_token'=>Csrf::token(),'positions'=>['invalid']]);driverCheck($r['status']===422,'un lot GPS mal formé reçoit une erreur de validation');
    $r=driverResponse('GET',$positions);driverCheck($r['status']===200 && $r['json']['data']['total_positions']===1,'l’historique confirme les positions réellement enregistrées');
    driverCheck(driverAction($delivery,'arrive')['status']===200 && driverStatus($delivery)==='Arrivée','l’arrivée met à jour la mission et la bonne destination');
    driverCheck(driverAction($delivery,'unload')['status']===200 && driverStatus($delivery)==='Déchargement','le déchargement démarre à la destination courante');
    $proof=['_token'=>Csrf::token(),'destination_id'=>$stops[0],'recipient_name'=>'Réception audit','latitude'=>-11.6647,'longitude'=>27.4794,'accuracy'=>8];
    driverCheck(driverResponse('POST','/api/driver-app/missions/'.$delivery.'/pod',$proof,['delivery_photo'=>$upload])['status']===422,'une preuve est refusée tant que les marchandises ne sont pas contrôlées');
    driverCheck(driverAction($delivery,'confirm_unloading',['goods'=>[['id'=>$goods[0],'delivered_quantity'=>8,'condition'=>'Partielle','note'=>'']]])['status']===422,'une livraison partielle exige une explication');
    driverCheck(driverAction($delivery,'confirm_unloading',['goods'=>[['id'=>$goods[0],'delivered_quantity'=>8,'condition'=>'Partielle','note'=>'Deux colis manquants au contrôle.']]])['status']===200,'le contrôle partiel et sa réserve sont enregistrés');
    $view=driverResponse('GET','/driver-app/missions/'.$delivery);driverCheck(strpos($view['content'],'id="podForm"')!==false && strpos($view['content'],'data-destination-id="'.$stops[0].'"')!==false,'la fiche propose la preuve pour la destination exacte après contrôle');
    driverCheck(driverResponse('POST','/api/driver-app/missions/'.$delivery.'/pod',$proof)['status']===422,'la PWA refuse une preuve sans photo');
    $r=driverResponse('POST','/api/driver-app/missions/'.$delivery.'/pod',$proof,['delivery_photo'=>$upload]);
    driverCheck($r['status']===201 && $r['json']['delivery_status']==='En transit','le premier bon relance la tournée sans terminer la mission');
    $pdf=driverResponse('GET','/deliveries/'.$delivery.'/destinations/'.$stops[0].'/pod.pdf');driverCheck($pdf['status']===200 && strpos($pdf['content'],'%PDF-')===0,'le PDF de la première destination est accessible au chauffeur');
    driverCheck(driverResponse('POST','/api/driver-app/missions/'.$delivery.'/pod',$proof,['delivery_photo'=>$upload])['status']===201 && (int)$pdo->query('SELECT COUNT(*) FROM delivery_pods WHERE delivery_id='.$delivery)->fetchColumn()===1,'réessayer la même preuve ne crée ni doublon ni livraison de l’arrêt suivant');
    driverCheck(driverAction($delivery,'arrive')['status']===200 && DriverMission::findOwned($delivery)['current_destination_id']==$stops[1],'la tournée passe à la deuxième destination');
    driverCheck(driverAction($delivery,'unload')['status']===200,'le second déchargement est disponible');
    driverCheck(driverAction($delivery,'confirm_unloading',['goods'=>[['id'=>$goods[1],'delivered_quantity'=>10,'condition'=>'Conforme','note'=>'']]])['status']===200,'le contrôle conforme est enregistré à la deuxième destination');
    $parallelDelivery=driverInsert('deliveries',['reference'=>'AUDIT-PAR-'.$suffix,'client_id'=>$client,'scheduled_at'=>date('Y-m-d H:i:s'),'driver_id'=>$resource['id'],'vehicle_id'=>$resource['vehicle_id'],'status'=>'En transit']);
    $proof['destination_id']=$stops[1];$r=driverResponse('POST','/api/driver-app/missions/'.$delivery.'/pod',$proof,['delivery_photo'=>$upload]);
    driverCheck($r['status']===201 && $r['json']['delivery_status']==='Livrée','la dernière destination termine la mission');
    driverCheck($pdo->query('SELECT status FROM drivers WHERE id='.(int)$resource['id'])->fetchColumn()==='En mission' && $pdo->query('SELECT status FROM vehicles WHERE id='.(int)$resource['vehicle_id'])->fetchColumn()==='En livraison','terminer une mission ne libère pas les ressources encore occupées');
    driverCheck(driverResponse('GET','/driver-app/missions/'.$delivery)['status']===200,'la fiche reste consultable immédiatement après la livraison');
    driverCheck((int)$pdo->query('SELECT COUNT(*) FROM delivery_pod_documents pd JOIN delivery_pods p ON p.id=pd.pod_id WHERE p.delivery_id='.$delivery)->fetchColumn()===1,'la seconde livraison est confirmée sans attendre son PDF');
    $pdf=driverResponse('GET','/deliveries/'.$delivery.'/destinations/'.$stops[1].'/pod.pdf');driverCheck($pdf['status']===200 && strpos($pdf['content'],'%PDF-')===0,'le second PDF est généré au téléchargement');
    driverCheck((int)$pdo->query('SELECT COUNT(*) FROM delivery_pod_documents pd JOIN delivery_pods p ON p.id=pd.pod_id WHERE p.delivery_id='.$delivery)->fetchColumn()===2,'les PDF téléchargés sont conservés en base');
    driverCheck(\App\Models\ClientNotification::processEvents(50,$delivery)===5 && (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$delivery)->fetchColumn()===5,'le parcours chauffeur crée cinq événements uniques traitables sans interface');
    driverCheck(driverAction($delivery,'arrive')['status']===422,'une mission terminée ne peut pas reprendre une progression opérationnelle');
    $native=driverInsert('deliveries',['reference'=>'AUDIT-NATIVE-'.$suffix,'client_id'=>$client,'scheduled_at'=>date('Y-m-d H:i:s'),'driver_id'=>$resource['id'],'vehicle_id'=>$resource['vehicle_id'],'status'=>'Chargée','created_by'=>$actor]);
    $nativeStop=driverInsert('delivery_destinations',['delivery_id'=>$native,'stop_order'=>1,'label'=>'Destination application native','address_line'=>'Adresse audit','notification_emails'=>'native@example.test']);
    driverInsert('delivery_goods',['delivery_id'=>$native,'destination_id'=>$nativeStop,'description_snapshot'=>'Colis natif','quantity'=>1,'unit'=>'colis']);
    $nativePosition=$position;$nativePosition['position_id']='native-'.$suffix;
    MobileMission::start($native,$nativePosition);
    driverCheck(driverStatus($native)==='En transit','le départ natif reste fonctionnel avec les triggers');
    $nativeProof=MobileMission::deliver($native,$nativeStop,$proof,\App\Core\PodUpload::photo($upload,true,'Photo audit'));
    driverCheck(driverStatus($native)==='Livrée' && !$nativeProof['already_delivered'] && MobileMission::document((int)$nativeProof['pod_id'])===null,'la livraison native est confirmée sans génération de PDF');
    driverCheck(MobileMission::deliver($native,$nativeStop,$proof,\App\Core\PodUpload::photo($upload,true,'Photo audit'))['already_delivered'],'réessayer une livraison native ne redéclenche pas la livraison');
    driverCheck(\App\Models\ClientNotification::processEvents(50,$native)===3 && (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE delivery_id='.$native)->fetchColumn()===3,'le départ et la livraison natifs produisent trois notifications uniques');
    $nativePdf=driverResponse('GET','/deliveries/'.$native.'/destinations/'.$nativeStop.'/pod.pdf');
    driverCheck($nativePdf['status']===200 && strpos($nativePdf['content'],'%PDF-')===0,'le bon natif reste téléchargeable et conservé après la confirmation');
    $incidentDelivery=driverInsert('deliveries',['reference'=>'AUDIT-INC-'.$suffix,'client_id'=>$client,'scheduled_at'=>date('Y-m-d H:i:s'),'driver_id'=>$resource['id'],'vehicle_id'=>$resource['vehicle_id'],'status'=>'En transit']);
    driverInsert('delivery_destinations',['delivery_id'=>$incidentDelivery,'stop_order'=>1,'label'=>'Arrêt incident','address_line'=>'Adresse incident']);
    $r=driverResponse('POST','/api/driver-app/missions/'.$incidentDelivery.'/incident',['_token'=>Csrf::token(),'incident_type'=>'panne','description'=>'Panne moteur pendant le contrôle fonctionnel.','latitude'=>-11.66,'longitude'=>27.48,'accuracy'=>10]);
    driverCheck($r['status']===201 && driverStatus($incidentDelivery)==='Incident','le signalement d’incident suspend la mission');$incidentId=$r['json']['id'];
    driverCheck(driverAction($incidentDelivery,'arrive')['status']===422,'un incident ouvert bloque les étapes suivantes');
    driverIdentity($admin);Incident::resolve((int)$incidentId,['corrective_action'=>'Intervention de démonstration effectuée.','resolution'=>'Contrôle terminé et reprise autorisée.']);driverIdentity($actor);
    driverCheck(driverStatus($incidentDelivery)==='En transit' && driverAction($incidentDelivery,'arrive')['status']===200,'la résolution par le bureau permet au chauffeur de reprendre');
    $pdo->prepare('UPDATE drivers SET is_active=0 WHERE id=?')->execute([$resource['id']]);
    driverCheck(driverResponse('GET','/driver-app/missions/'.$incidentDelivery)['status']===404 && driverAction($incidentDelivery,'unload')['status']===403,'une fiche chauffeur désactivée ne peut plus consulter ni faire progresser les missions');
    $pdo->prepare('UPDATE drivers SET is_active=1 WHERE id=?')->execute([$resource['id']]);
    $pdo->prepare('UPDATE drivers SET user_id=NULL WHERE id=?')->execute([$resource['id']]);
    $view=driverResponse('GET','/driver-app');driverCheck($view['status']===200 && strpos($view['content'],'Compte non associé')!==false,'un compte sans fiche chauffeur affiche une explication');
    $pdo->prepare('UPDATE drivers SET user_id=? WHERE id=?')->execute([$actor,$resource['id']]);
    $pdo->prepare('UPDATE deliveries SET driver_id=NULL WHERE driver_id=?')->execute([$resource['id']]);
    $view=driverResponse('GET','/driver-app');driverCheck($view['status']===200 && strpos($view['content'],'Aucune mission')!==false,'la vue sans mission est fonctionnelle');
    echo 'DRIVER_APP_AUDIT_OK: '.$checks.' checks (all data rolled back; no email sent)'.PHP_EOL;
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}unlink($photoPath);driverIdentity(null);session_write_close();
}
driverCheck($pdo->query('SELECT * FROM drivers WHERE id='.(int)$resource['id'])->fetch()===$originalDriver,'la fiche chauffeur utilisée par les tests est inchangée');
driverCheck($pdo->query('SELECT * FROM vehicles WHERE id='.(int)$resource['vehicle_id'])->fetch()===$originalVehicle,'le véhicule utilisé par les tests est inchangé');
