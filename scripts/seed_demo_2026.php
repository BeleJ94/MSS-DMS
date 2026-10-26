<?php

declare(strict_types=1);

/** Additive local demonstration dataset. --apply commits; default mode rolls everything back. */
ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\PodPdf;
use App\Models\DeliveryPod;

$config=require BASE_PATH.'/config/database.php';
if(PHP_SAPI!=='cli' || !in_array($config['host'],['localhost','127.0.0.1','::1'],true)){throw new RuntimeException('Ce générateur est réservé à une base locale et à la ligne de commande.');}
$apply=in_array('--apply',$argv,true);
$pdo=Database::connection();
$key='demo_dataset_2026_v1';
$marker=$pdo->prepare('SELECT setting_value FROM application_settings WHERE setting_key=?');$marker->execute([$key]);
if($existing=$marker->fetchColumn()){echo "DEMO_ALREADY_EXISTS\n".$existing."\n";exit(0);}
if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='notification_outbox'")->fetchColumn()!==1){throw new RuntimeException('Exécutez les migrations avant le générateur.');}
foreach(['clients'=>'code','drivers'=>'code','vehicles'=>'code','deliveries'=>'reference'] as $table=>$column){if((int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.' LIKE "DEMO-%"')->fetchColumn()){throw new RuntimeException('Des données DEMO existent déjà sans manifeste. Aucune donnée ne sera écrasée.');}}

$stamp=date('Ymd-His');
$backupPath=null;
if($apply){
    $backup=['database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'created_at'=>date(DATE_ATOM),'encoding'=>'All non-null field values are base64 encoded','tables'=>[]];
    $pdo->beginTransaction();
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();
        foreach($rows as &$row){foreach($row as &$value){if($value!==null){$value=base64_encode((string)$value);}}unset($value);}unset($row);
        $backup['tables'][$table]=['schema'=>$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1],'rows'=>$rows];
    }
    $pdo->rollBack();
    $backupPath=BASE_PATH.'/storage/cache/demo-2026-backup-'.$stamp.'.json.gz';
    $encoded=gzencode((string)json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),6);
    if(file_put_contents($backupPath,$encoded)!==strlen($encoded)){throw new RuntimeException('La sauvegarde locale a échoué.');}
    chmod($backupPath,0600);unset($backup,$encoded);
}

$queries=[];$counts=[];
function demoInsert(string $table,array $row):int{
    global $pdo,$queries,$counts;
    $columns=array_keys($row);$sql='INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
    if(!isset($queries[$sql])){$queries[$sql]=$pdo->prepare($sql);}
    $queries[$sql]->execute(array_values($row));$counts[$table]=($counts[$table]??0)+1;return (int)$pdo->lastInsertId();
}
function demoTime(int $timestamp):string{return date('Y-m-d H:i:s',$timestamp);}
function demoAssert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}

mt_srand(20260101);
$now=time();$today=new DateTimeImmutable('today');$start=new DateTimeImmutable('2026-01-01');$created='2025-12-15 08:00:00';
$password='Demo-'.bin2hex(random_bytes(7)).'!';
$clients=[];$drivers=[];$vehicles=[];$goods=[];$examples=[];$sequence=0;
$pdo->beginTransaction();
try{
    // The unique settings key also protects against concurrent executions.
    demoInsert('application_settings',['setting_key'=>$key,'setting_value'=>'running']);
    $operator=demoInsert('users',['name'=>'DEMO · Dispatching','email'=>'demo.dispatcher@example.test','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'created_at'=>$created,'updated_at'=>$created]);
    $role=(int)$pdo->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetchColumn();demoAssert($role>0,'Rôle dispatcher absent.');demoInsert('user_roles',['user_id'=>$operator,'role_id'=>$role,'assigned_at'=>$created]);
    $driverRole=(int)$pdo->query("SELECT id FROM roles WHERE slug='chauffeur'")->fetchColumn();demoAssert($driverRole>0,'Rôle chauffeur absent.');
    $firstNames=['Alain','Patrick','Jean','David','Samuel','Joseph','Éric','Daniel','Moïse','André','Michel','Paul','Luc','Emmanuel','Serge','Christophe','Olivier','Benjamin','Marc','Pierre'];
    $lastNames=['Kabeya','Ilunga','Mwamba','Kasongo','Kalala','Mutombo','Kabila','Mbuyi','Nkulu','Tshibanda','Mukendi','Kanku','Lukusa','Banza','Katende','Mulumba','Muleka','Tshilombo','Kashala','Kisimba'];
    for($i=0;$i<20;$i++){
        $number=str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
        $user=demoInsert('users',['name'=>'DEMO · '.$firstNames[$i].' '.$lastNames[$i],'email'=>'demo.chauffeur'.$number.'@example.test','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'created_at'=>$created,'updated_at'=>$created]);
        demoInsert('user_roles',['user_id'=>$user,'role_id'=>$driverRole,'assigned_at'=>$created]);
        $id=demoInsert('drivers',['code'=>'DEMO-CH-'.$number,'user_id'=>$user,'first_name'=>$firstNames[$i],'last_name'=>$lastNames[$i].' [DEMO]','phone'=>'+243000001'.$number,'email'=>'demo.chauffeur'.$number.'@example.test','city'=>'Lubumbashi','license_number'=>'DEMO-PERMIS-'.$number,'license_category'=>'C, CE','license_issued_at'=>'2024-01-01','license_expires_at'=>$i===17?$today->modify('+20 days')->format('Y-m-d'):'2028-12-31','status'=>$i===18?'Indisponible':'Disponible','notes'=>'Jeu de démonstration 2026 — personne fictive.','created_by'=>$operator,'updated_by'=>$operator,'created_at'=>$created,'updated_at'=>$created]);
        $drivers[]=['id'=>$id,'user'=>$user,'name'=>$firstNames[$i].' '.$lastNames[$i]];
        demoInsert('driver_history',['driver_id'=>$id,'user_id'=>$operator,'action'=>'created','description'=>'DEMO : intégration du chauffeur fictif','created_at'=>$created]);
        $vehicle=demoInsert('vehicles',['code'=>'DEMO-VEH-'.$number,'registration_number'=>'DEMO-'.$number.'-HK','brand'=>['Mercedes-Benz','MAN','Isuzu','Volvo'][$i%4],'model'=>['Actros','TGS','FVR','FM'][$i%4],'vehicle_type'=>$i%4===2?'Camion porteur':'Camion plateau','manufacture_year'=>2021+$i%4,'capacity_value'=>$i%4===2?10:20,'capacity_unit'=>'tonnes','mileage_km'=>55000+$i*5700,'status'=>$i===18?'Maintenance':'Disponible','notes'=>'Véhicule fictif du jeu DEMO 2026.','created_by'=>$operator,'updated_by'=>$operator,'created_at'=>$created,'updated_at'=>$created]);
        $vehicles[]=$vehicle;
        demoInsert('vehicle_history',['vehicle_id'=>$vehicle,'user_id'=>$operator,'action'=>'created','description'=>'DEMO : intégration du véhicule fictif','created_at'=>$created]);
    }
    $companies=['Katanga Distribution','Cuivre & Services','Horizon Matériaux','Kivu Agro Distribution','Congo Équipements','Lualaba Industries','Énergie du Sud','Lubum Commerce','Atlas Construction','Savane Alimentaire','Central Médical','Orion Pièces Industrielles'];
    for($i=0;$i<count($companies);$i++){
        $number=str_pad((string)($i+1),2,'0',STR_PAD_LEFT);$lat=-11.66+($i%4)*.018;$lng=27.47+intdiv($i,4)*.025;
        $client=demoInsert('clients',['code'=>'DEMO-CLI-'.$number,'company_name'=>$companies[$i].' [DEMO]','email'=>'contact'.$number.'@example.test','phone'=>'+243000002'.$number,'address_line1'=>($i+10).' avenue de la Démonstration','city'=>'Lubumbashi','province'=>'Haut-Katanga','latitude'=>$lat,'longitude'=>$lng,'status'=>'active','notes'=>'Entreprise fictive ; contacts de notification désactivés.','created_by'=>$operator,'updated_by'=>$operator,'created_at'=>$created,'updated_at'=>$created]);
        $contact=demoInsert('client_contacts',['client_id'=>$client,'full_name'=>'Réception '.$companies[$i].' [DEMO]','job_title'=>'Responsable réception','email'=>'reception'.$number.'@example.test','phone'=>'+243000003'.$number,'is_primary'=>1,'created_at'=>$created,'updated_at'=>$created]);
        $sites=[];
        for($j=0;$j<3;$j++){
            $label=['Dépôt central','Agence Nord','Entrepôt industriel'][$j];$address=($i*10+$j+1).' avenue du Site '.($j+1);$siteLat=$lat+$j*.012;$siteLng=$lng+$j*.015;
            $siteId=demoInsert('client_sites',['client_id'=>$client,'name'=>$label,'site_code'=>'DEMO-SITE-'.$number.'-'.($j+1),'address_line1'=>$address,'city'=>'Lubumbashi','province'=>'Haut-Katanga','latitude'=>$siteLat,'longitude'=>$siteLng,'delivery_instructions'=>'DEMO : accès au quai de réception, contrôle des quantités avec le magasinier.','is_default'=>$j===0?1:0,'created_at'=>$created,'updated_at'=>$created]);
            $sites[]=['id'=>$siteId,'label'=>$label,'address'=>$address,'lat'=>$siteLat,'lng'=>$siteLng];
        }
        demoInsert('client_notification_recipients',['client_id'=>$client,'full_name'=>'Direction logistique [DEMO]','email'=>'notifications'.$number.'@example.test','notify_email'=>0,'notify_sms'=>0,'notify_on'=>'dispatch,arrival,delivery','created_at'=>$created,'updated_at'=>$created]);
        demoInsert('client_history',['client_id'=>$client,'user_id'=>$operator,'action'=>'created','description'=>'DEMO : création du client et de ses trois sites','created_at'=>$created]);
        $clients[]=['id'=>$client,'name'=>$companies[$i].' [DEMO]','contact'=>$contact,'sites'=>$sites];
    }
    $catalog=[['Ciment 42,5','sacs',50],['Riz conditionné','sacs',25],['Huile alimentaire','cartons',12],['Tubes PVC','lots',35],['Pièces mécaniques','caisses',80],['Matériel électrique','cartons',18],['Eau conditionnée','packs',12],['Farine de maïs','sacs',25],['Équipements de protection','cartons',15],['Carrelage','cartons',22],['Consommables médicaux','cartons',8],['Boulonnerie industrielle','caisses',30],['Peinture','seaux',20],['Outillage','caisses',45],['Pompes industrielles','unités',120]];
    foreach($catalog as $i=>$item){$id=demoInsert('goods',['reference'=>'DEMO-MAR-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),'designation'=>$item[0].' [DEMO]','description'=>'Marchandise fictive du jeu de démonstration.','unit'=>$item[1],'unit_weight_kg'=>$item[2],'created_by'=>$operator,'updated_by'=>$operator,'created_at'=>$created,'updated_at'=>$created]);$goods[]=['id'=>$id,'label'=>$item[0],'unit'=>$item[1],'weight'=>$item[2]];}

    $create=function(DateTimeImmutable $scheduled,string $status,?int $resource,int $stopCount,int $partial=0,bool $late=false,bool $incident=false,bool $live=false) use(&$sequence,&$examples,$clients,$drivers,$vehicles,$goods,$operator,$now,$created):int{
        global $pdo;
        $sequence++;$reference='DEMO-2026-'.str_pad((string)$sequence,5,'0',STR_PAD_LEFT);
        $client=$clients[($sequence*7)%count($clients)];$driver=$resource!==null?$drivers[$resource]:null;$vehicle=$resource!==null?$vehicles[$resource]:null;
        $terminal=in_array($status,['Livrée','Clôturée'],true);$cancelled=$status==='Annulée';
        $finish=$terminal?$scheduled->getTimestamp()+($late?45*60:-20*60):null;
        $begin=$live?$now-180*60:min($scheduled->getTimestamp()-150*60,$now-180*60);
        $departure=$begin+25*60;
        $createdAt=demoTime($begin-86400);$activeAt=demoTime(min($now,$begin+60*60));
        $delivery=demoInsert('deliveries',['reference'=>$reference,'client_id'=>$client['id'],'client_site_id'=>$client['sites'][0]['id'],'client_contact_id'=>$client['contact'],'scheduled_at'=>$scheduled->format('Y-m-d H:i:s'),'planning_duration_minutes'=>240,'priority'=>['Normale','Normale','Normale','Haute','Basse','Urgente'][$sequence%6],'driver_id'=>$driver['id']??null,'vehicle_id'=>$vehicle,'status'=>$status,'status_before_incident'=>$status==='Incident'?'En transit':null,'observations'=>'DEMO 2026 — simulation persistante, sans envoi réel. '.($late?'Scénario de retard. ':'').($stopCount>1?'Tournée à plusieurs destinations.':''),'cancellation_reason'=>$cancelled?'DEMO : commande reportée par le client.':null,'delivered_at'=>$finish?demoTime($finish):null,'closed_at'=>$status==='Clôturée'?demoTime($finish+15*60):null,'created_by'=>$operator,'updated_by'=>$operator,'created_at'=>$createdAt,'updated_at'=>$terminal?demoTime($finish+($status==='Clôturée'?15*60:0)):($cancelled?demoTime($begin+15*60):$activeAt)]);
        $events=[[$begin-86400,'Brouillon','Création de la commande DEMO']];
        $flow=['Affectée','À préparer','Prête','Chargement','Chargée','Partie','En transit'];
        $stage=$terminal||$live&&in_array($status,['Arrivée','Déchargement','Incident','En transit','Partie'],true)?($status==='Partie'?5:6):array_search($status,$flow,true);
        if($driver){foreach($flow as $n=>$step){if($stage!==false && $n<=$stage){$events[]=[$begin+$n*5*60,$step,'Progression du scénario DEMO'];}}
            demoInsert('delivery_assignment_history',['delivery_id'=>$delivery,'driver_id'=>$driver['id'],'vehicle_id'=>$vehicle,'assigned_by'=>$operator,'assigned_at'=>demoTime($begin)]);
        }
        if($cancelled){$events[]=[$begin+15*60,'Annulée','Commande reportée par le client — DEMO'];}
        $completed=$terminal?$stopCount:$partial;$destinations=[];
        $anomaly=$sequence%11===0;
        for($stop=0;$stop<$stopCount;$stop++){
            $site=$client['sites'][$stop];$done=$stop<$completed;
            $isCurrent=!$done&&$stop===$completed;
            $stopStatus=$cancelled?'Annulée':($done?'Livrée':($isCurrent&&in_array($status,['Arrivée','Déchargement'],true)?$status:'À livrer'));
            $stopEnd=$done?($terminal?$finish-($stopCount-1-$stop)*25*60:$now-(60-$stop*15)*60):null;
            $arrival=$done?$stopEnd-15*60:($isCurrent&&in_array($status,['Arrivée','Déchargement'],true)?$now-20*60:null);
            $destination=demoInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>$stop+1,'label'=>$site['label'].' [DEMO]','address_line'=>$site['address'],'city'=>'Lubumbashi','contact_name'=>'Magasinier '.$client['name'],'contact_phone'=>'+24300000400','instructions'=>'Données fictives : contrôler les quantités au quai.','latitude'=>$site['lat'],'longitude'=>$site['lng'],'status'=>$stopStatus,'arrived_at'=>$arrival?demoTime($arrival):null,'delivered_at'=>$stopEnd?demoTime($stopEnd):null,'created_at'=>$createdAt,'updated_at'=>$stopEnd?demoTime($stopEnd):($arrival?demoTime($arrival):$createdAt)]);
            $destinations[]=['id'=>$destination,'site'=>$site];
            for($line=0;$line<2+$sequence%2;$line++){
                // Different catalogue items at each stop respect the existing delivery/goods unique key.
                $item=$goods[($sequence+$stop*3+$line)%count($goods)];
                $capacityKg=$resource!==null&&$resource%4===2?10000:20000;
                $quantity=min(10+($sequence*3+$line*7)%60,(int)floor($capacityKg*.75/($stopCount*3*$item['weight'])));
                $checked=$done || ($stopStatus==='Déchargement' && $sequence%2===0);
                $difference=$checked&&$anomaly&&$line===0;$condition=$checked?($difference?['Partielle','Endommagée','Manquante'][$sequence%3]:'Conforme'):null;
                $delivered=$checked?($difference?($condition==='Manquante'?0:$quantity-2):$quantity):null;
                demoInsert('delivery_goods',['delivery_id'=>$delivery,'destination_id'=>$destination,'goods_id'=>$item['id'],'quantity'=>$quantity,'unit'=>$item['unit'],'unit_weight_kg'=>$item['weight'],'description_snapshot'=>$item['label'].' [DEMO]','delivered_quantity'=>$delivered,'delivery_condition'=>$condition,'driver_note'=>$difference?'DEMO : écart constaté au contrôle contradictoire.':($checked?'DEMO : contrôle conforme.':null),'checked_at'=>$checked?demoTime(($stopEnd??$now)-5*60):null,'checked_by'=>$checked?$driver['user']:null,'created_at'=>$createdAt,'updated_at'=>$checked?demoTime(($stopEnd??$now)-5*60):$createdAt]);
            }
            if($done){
                $events[]=[$arrival,'Arrivée','Arrivée destination '.($stop+1).' — DEMO'];$events[]=[$arrival+5*60,'Déchargement','Contrôle des marchandises — DEMO'];
                $pod=demoInsert('delivery_pods',['delivery_id'=>$delivery,'destination_id'=>$destination,'recipient_name'=>'Réception '.$client['name'],'observations'=>'DOCUMENT DE DÉMONSTRATION — livraison fictive. '.($anomaly?'Réserves enregistrées sur les marchandises.':'Réception sans réserve.'),'latitude'=>$site['lat'],'longitude'=>$site['lng'],'accuracy_m'=>8,'captured_at'=>demoTime($stopEnd),'driver_id'=>$driver['id'],'vehicle_id'=>$vehicle,'created_by'=>$driver['user'],'created_at'=>demoTime($stopEnd)]);
                $pdf=PodPdf::render(DeliveryPod::findByDestination($delivery,$destination));
                demoInsert('delivery_pod_documents',['pod_id'=>$pod,'pdf_data'=>$pdf,'created_at'=>demoTime($stopEnd)]);
                $events[]=[$stopEnd,$stop===$stopCount-1?'Livrée':'En transit','Destination '.($stop+1).' livrée — DEMO'];
            }elseif($arrival){$events[]=[$arrival,'Arrivée','Arrivée destination courante — DEMO'];if($stopStatus==='Déchargement'){$events[]=[$arrival+5*60,'Déchargement','Contrôle en cours — DEMO'];}}
        }
        if($status==='Clôturée'){$events[]=[$finish+15*60,'Clôturée','Clôture administrative — DEMO'];}
        if($incident || $status==='Incident'){
            $open=$status==='Incident';$occurred=$open?$now-30*60:$begin+40*60;$resolved=$open?null:$begin+60*60;
            $type=['panne','retard','client absent','marchandise endommagée','problème documentaire'][$sequence%5];
            demoInsert('driver_incidents',['driver_id'=>$driver['id'],'delivery_id'=>$delivery,'incident_reference'=>'DEMO-INC-'.str_pad((string)$sequence,5,'0',STR_PAD_LEFT),'incident_type'=>$type,'occurred_at'=>demoTime($occurred),'severity'=>$sequence%3===0?'Majeur':'Mineur','status'=>$open?($sequence%2?'Ouvert':'En traitement'):'Résolu','description'=>'DEMO : '.$type.' pendant la tournée. Données fictives pour tester le traitement des incidents.','latitude'=>$client['sites'][0]['lat'],'longitude'=>$client['sites'][0]['lng'],'accuracy_m'=>10,'responsible_user_id'=>$operator,'corrective_action'=>$open?'DEMO : coordination avec le dispatching en cours.':'DEMO : intervention terminée, reprise du transport.','resolution'=>$resolved?'DEMO : incident résolu après intervention.':null,'resolved_at'=>$resolved?demoTime($resolved):null,'resolved_by'=>$resolved?$operator:null,'reported_by'=>$driver['user'],'created_at'=>demoTime($occurred)]);
            $events[]=[$occurred,'Incident','Signalement '.$type.' — DEMO'];if($resolved){$events[]=[$resolved,'En transit','Incident résolu, reprise de mission — DEMO'];}
        }
        usort($events,static function(array $a,array $b):int{return $a[0]<=>$b[0];});$previous=null;
        foreach($events as $event){demoInsert('delivery_status_history',['delivery_id'=>$delivery,'from_status'=>$previous,'to_status'=>$event[1],'comment'=>$event[2],'changed_by'=>$operator,'changed_at'=>demoTime($event[0])]);$previous=$event[1];}
        $onRoad=$driver && !$cancelled && ($terminal || in_array($status,['Partie','En transit','Arrivée','Déchargement','Incident'],true));
        if($onRoad){
            $distance=8+$sequence%25;
            demoInsert('driver_missions',['driver_id'=>$driver['id'],'mission_reference'=>$reference,'origin'=>'Plateforme DEMO Lubumbashi','destination'=>'Lubumbashi — '.$stopCount.' destination(s)','started_at'=>demoTime($departure),'completed_at'=>$finish?demoTime($finish):null,'distance_km'=>$finish?$distance:null,'status'=>$terminal?'Terminée':'En mission','created_at'=>demoTime($departure)]);
            demoInsert('vehicle_delivery_history',['vehicle_id'=>$vehicle,'delivery_reference'=>$reference,'client_name'=>$client['name'],'origin'=>'Plateforme DEMO Lubumbashi','destination'=>$client['sites'][$stopCount-1]['label'],'driver_name'=>$driver['name'],'started_at'=>demoTime($departure),'completed_at'=>$finish?demoTime($finish):null,'distance_km'=>$finish?$distance:null,'status'=>$terminal?'Livrée':'En transit','created_at'=>demoTime($departure)]);
            if($live || $sequence%4===0){
                $end=$finish??($now-45);$target=$client['sites'][$terminal?$stopCount-1:min($partial,$stopCount-1)];$ratio=$terminal||in_array($status,['Arrivée','Déchargement'],true)?1:.65;
                for($point=0;$point<30;$point++){
                    $fraction=$point/29*$ratio;$captured=(int)round($departure+($end-$departure)*$point/29);
                    demoInsert('delivery_gps_positions',['delivery_id'=>$delivery,'driver_id'=>$driver['id'],'device_position_id'=>'demo-2026-'.$delivery.'-'.$point,'latitude'=>-11.6647+($target['lat']+11.6647)*$fraction,'longitude'=>27.4794+($target['lng']-27.4794)*$fraction,'accuracy_m'=>6+$point%5,'altitude_m'=>1250,'speed_mps'=>$point===29?0:2.5,'heading_deg'=>45,'captured_at'=>gmdate('Y-m-d H:i:s',$captured),'received_at'=>gmdate('Y-m-d H:i:s',$captured+2),'source'=>'pwa']);
                }
            }
        }
        if($live && $driver && !$terminal && !$cancelled){
            $resourceStatus=in_array($status,['Partie','En transit','Arrivée','Déchargement'],true)?['En mission','En livraison']:($status==='Incident'?['Indisponible','Indisponible']:['Affecté','Affecté']);
            $pdo->prepare('UPDATE drivers SET status=?,updated_at=? WHERE id=?')->execute([$resourceStatus[0],demoTime($now),$driver['id']]);
            $pdo->prepare('UPDATE vehicles SET status=?,assigned_driver_id=?,updated_at=? WHERE id=?')->execute([$resourceStatus[1],$driver['id'],demoTime($now),$vehicle]);
        }
        if($terminal && $sequence%20===0){
            demoInsert('notification_outbox',['delivery_id'=>$delivery,'destination_id'=>$destinations[0]['id'],'destination_key'=>$destinations[0]['id'],'event'=>'delivery','channel'=>'email','recipient_name'=>'Direction [DEMO]','recipient_address'=>'simulation@example.test','subject'=>'[DEMO] Livraison '.$reference.' — simulation uniquement','body'=>'Enregistrement de démonstration. Aucun e-mail n’a été envoyé.','status'=>$sequence%60===0?'failed':'sent','attempts'=>$sequence%60===0?5:1,'last_error'=>$sequence%60===0?'DEMO : refus SMTP simulé.':null,'available_at'=>demoTime($finish),'sent_at'=>$sequence%60===0?null:demoTime($finish+60),'created_at'=>demoTime($finish)]);
        }
        if($live || count($examples)<3){$examples[$status]=$reference;}
        return $delivery;
    };

    for($day=$start;$day<$today;$day=$day->modify('+1 day')){
        $weekday=(int)$day->format('N');if($weekday===7){continue;}
        $volume=$weekday===6?2:5+intdiv((int)$day->format('n'),3)+(int)$day->format('z')%3;
        for($slot=0;$slot<$volume;$slot++){
            $scheduled=$day->setTime(11+$slot%5,15*($slot%3));$cancelled=($sequence+1)%23===0;
            $create($scheduled,$cancelled?'Annulée':(($sequence+1)%7===0?'Livrée':'Clôturée'),$cancelled?null:($slot+(int)$day->format('z'))%18,1+(($sequence+1)%4===0?2:(($sequence+1)%3===0?1:0)),0,($sequence+1)%5===0,!$cancelled&&($sequence+1)%13===0);
        }
        if((int)$day->format('j')===1){echo 'Préparé : '.$day->format('Y-m')."\n";}
    }
    $scenarios=['Brouillon','Affectée','À préparer','Prête','Chargement','Chargée','Partie','En transit','Arrivée','Déchargement','Incident','En transit','Déchargement','Livrée','Clôturée','Annulée'];
    $resource=0;
    foreach($scenarios as $index=>$status){
        $unassigned=in_array($status,['Brouillon','Annulée'],true);
        $scheduled=(new DateTimeImmutable())->setTimestamp($now+(in_array($status,['Livrée','Clôturée'],true)?-30*60:($index%3===0?-60*60:120*60)));
        $create($scheduled,$status,$unassigned?null:$resource++,in_array($index,[11,12],true)?3:1,in_array($index,[11,12],true)?1:0,false,$status==='Incident',true);
    }
    // A small overdue queue, on separate demo resources, makes operational alerts visible.
    $create($today->modify('-1 day')->setTime(15,0),'En transit',15,2,0,false,false,true);
    $create($today->modify('-1 day')->setTime(14,0),'Incident',16,1,0,false,true,true);
    for($offset=1;$offset<=7;$offset++){
        $day=$today->modify('+'.$offset.' days');
        for($slot=0;$slot<6;$slot++){$create($day->setTime(10+$slot%4,0),$slot%3===0?'Brouillon':'Affectée',$slot%3===0?null:$slot,1+$slot%3);}
    }

    // Validate generated relationships and chronology before the transaction can commit.
    $checks=[
        'deliveries without destinations'=>'SELECT COUNT(*) FROM deliveries d WHERE d.reference LIKE "DEMO-2026-%" AND NOT EXISTS(SELECT 1 FROM delivery_destinations x WHERE x.delivery_id=d.id)',
        'completed deliveries without delivery date'=>'SELECT COUNT(*) FROM deliveries WHERE reference LIKE "DEMO-2026-%" AND status IN ("Livrée","Clôturée") AND delivered_at IS NULL',
        'delivered stops without proof'=>'SELECT COUNT(*) FROM delivery_destinations x JOIN deliveries d ON d.id=x.delivery_id WHERE d.reference LIKE "DEMO-2026-%" AND x.status="Livrée" AND NOT EXISTS(SELECT 1 FROM delivery_pods p WHERE p.destination_id=x.id)',
        'delivered stops without goods check'=>'SELECT COUNT(*) FROM delivery_goods g JOIN delivery_destinations x ON x.id=g.destination_id JOIN deliveries d ON d.id=g.delivery_id WHERE d.reference LIKE "DEMO-2026-%" AND x.status="Livrée" AND (g.checked_at IS NULL OR g.delivered_quantity IS NULL)',
        'future completion timestamps'=>'SELECT COUNT(*) FROM deliveries WHERE reference LIKE "DEMO-2026-%" AND (delivered_at>NOW() OR closed_at>NOW())',
        'pending real notification'=>'SELECT COUNT(*) FROM notification_outbox n JOIN deliveries d ON d.id=n.delivery_id WHERE d.reference LIKE "DEMO-2026-%" AND n.status="pending"',
        'inconsistent status timeline'=>'SELECT COUNT(*) FROM deliveries d WHERE d.reference LIKE "DEMO-2026-%" AND d.status<>(SELECT h.to_status FROM delivery_status_history h WHERE h.delivery_id=d.id ORDER BY h.changed_at DESC,h.id DESC LIMIT 1)',
        'overloaded vehicles'=>'SELECT COUNT(*) FROM (SELECT d.id FROM deliveries d JOIN vehicles v ON v.id=d.vehicle_id JOIN delivery_goods g ON g.delivery_id=d.id WHERE d.reference LIKE "DEMO-2026-%" GROUP BY d.id,v.capacity_value HAVING SUM(g.quantity*g.unit_weight_kg)>v.capacity_value*1000) x',
        'history events in the future'=>'SELECT COUNT(*) FROM delivery_status_history h JOIN deliveries d ON d.id=h.delivery_id WHERE d.reference LIKE "DEMO-2026-%" AND h.changed_at>NOW()',
    ];
    foreach($checks as $label=>$sql){demoAssert((int)$pdo->query($sql)->fetchColumn()===0,'Invalid demonstration data: '.$label);}
    $summary=['dataset'=>$key,'from'=>$start->format('Y-m-d'),'history_until'=>$today->format('Y-m-d'),'planning_until'=>$today->modify('+7 days')->format('Y-m-d'),'generated_at'=>date(DATE_ATOM),'counts'=>$counts,'examples'=>$examples,'backup'=>$backupPath,'driver_login'=>'demo.chauffeur01@example.test','dispatcher_login'=>'demo.dispatcher@example.test','password'=>$password,'emails_sent'=>0];
    $pdo->prepare('UPDATE application_settings SET setting_value=? WHERE setting_key=?')->execute([json_encode(array_diff_key($summary,['password'=>true]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key]);
    if($apply){
        $manifest=BASE_PATH.'/storage/cache/demo-2026-manifest.json';
        demoAssert(file_put_contents($manifest,json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))!==false,'Impossible d’enregistrer le manifeste.');chmod($manifest,0600);
        $pdo->commit();echo "DEMO_COMMITTED\nmanifest=".$manifest."\n";
    }else{$pdo->rollBack();echo "DEMO_DRY_RUN_OK (all inserts rolled back)\n";}
    unset($summary['password']);echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
session_write_close();
