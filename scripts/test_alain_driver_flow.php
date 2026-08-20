<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Models\DeliveryPod;
use App\Models\DriverMission;
use App\Models\GpsTracking;

function expectAlain(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
$pdo=Database::connection();$user=$pdo->prepare('SELECT id FROM users WHERE email=:email AND is_active=1');$user->execute(['email'=>'alainr@gmail.com']);$userId=(int)$user->fetchColumn();if(!$userId){throw new RuntimeException('Compte Alain introuvable.');}Session::put('auth_user_id',$userId);
$driver=DriverMission::driver();expectAlain($driver!==null&&$driver['first_name']==='Alain','le compte est relié au chauffeur Alain');
$missions=DriverMission::listing();expectAlain(count($missions)>=1,'les missions affectées sont chargées');
$index=View::render('driver-app/index',['title'=>'Mes missions','driver'=>$driver,'missions'=>$missions,'activeMissionId'=>GpsTracking::activeMissionId(),'baseUrl'=>''],'layouts/driver-app');expectAlain(strpos($index,'Mes missions')!==false,'la liste chauffeur est rendue sans erreur');
foreach($missions as $row){$mission=DriverMission::findOwned((int)$row['id']);expectAlain($mission!==null,'la mission '.$row['reference'].' est accessible');$html=View::render('driver-app/show',['title'=>$mission['reference'],'mission'=>$mission,'pod'=>DeliveryPod::summary((int)$mission['id']),'pods'=>DeliveryPod::summaries((int)$mission['id']),'driver'=>$driver,'activeMissionId'=>in_array($mission['status'],GpsTracking::ACTIVE_STATUSES,true)?(int)$mission['id']:GpsTracking::activeMissionId(),'baseUrl'=>''],'layouts/driver-app');expectAlain(strpos($html,htmlspecialchars($mission['reference'],ENT_QUOTES,'UTF-8'))!==false,'la fiche '.$row['reference'].' est rendue complètement');}
echo "ALAIN_DRIVER_FLOW_OK\n";
