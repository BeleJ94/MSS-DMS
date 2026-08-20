<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\View;

function expectDriverMulti(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
$pdo=Database::connection();$user=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();if(!$user){throw new RuntimeException('Utilisateur actif requis.');}Session::put('auth_user_id',(int)$user);
$base=['scheduled_at'=>date('Y-m-d H:i:s'),'priority'=>'Normale','company_name'=>'Client test','site_name'=>'Destination test','city'=>'Lubumbashi','destination_count'=>2,'delivered_destination_count'=>0];
$missions=[array_merge($base,['id'=>901,'reference'=>'TEST-901','status'=>'Chargée']),array_merge($base,['id'=>902,'reference'=>'TEST-902','status'=>'En transit']),array_merge($base,['id'=>903,'reference'=>'TEST-903','status'=>'Arrivée','delivered_destination_count'=>1])];
$html=View::render('driver-app/index',['title'=>'Mes missions','driver'=>['first_name'=>'Test','last_name'=>'Chauffeur'],'missions'=>$missions,'activeMissionId'=>902,'baseUrl'=>''],'layouts/driver-app');
expectDriverMulti(substr_count($html,'data-mission-id=')===3,'chaque livraison possède son propre identifiant d’action');
expectDriverMulti(strpos($html,'data-mission-id="901"')!==false&&strpos($html,'data-mission-id="902"')!==false,'plusieurs livraisons affectées sont pilotables séparément');
expectDriverMulti(strpos($html,'data-mission-action="start"')!==false,'une livraison chargée peut être démarrée depuis la liste');
expectDriverMulti(strpos($html,'data-mission-action="arrive"')!==false,'une livraison en transit peut être marquée arrivée depuis la liste');
expectDriverMulti(strpos($html,'Faire signer')!==false,'une livraison arrivée ouvre sa preuve de livraison');
echo "DRIVER_MULTI_MISSION_PAGE_OK\n";
