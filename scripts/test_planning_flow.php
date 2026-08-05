<?php
declare(strict_types=1);
ini_set('session.save_path', sys_get_temp_dir());
$app=require dirname(__DIR__).'/bootstrap/app.php';
use App\Core\Database;use App\Models\Planning;
$pdo=Database::connection();$ids=[];$driver=null;$vehicle=null;$testDriverId=0;$testVehicleId=0;
function check(bool $ok,string $label):void{echo ($ok?'[OK] ':'[ECHEC] ').$label.PHP_EOL;if(!$ok)throw new RuntimeException($label);}
try{
 $client=$pdo->query("SELECT c.id client_id,s.id site_id FROM clients c JOIN client_sites s ON s.client_id=c.id LIMIT 1")->fetch();
 $key=date('His');$pdo->prepare("INSERT INTO drivers(code,first_name,last_name,phone,license_number,license_category,license_expires_at,status) VALUES(:code,'Test','Planning','000',:license,'C','2035-12-31','Disponible')")->execute(['code'=>'T-PLAN-'.$key,'license'=>'T-PLAN-'.$key]);$testDriverId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO vehicles(code,registration_number,brand,model,vehicle_type,capacity_value,status) VALUES(:code,:registration,'Test','Planning','Camion',10,'Disponible')")->execute(['code'=>'TV-PLAN-'.$key,'registration'=>'TV-PLAN-'.$key]);$testVehicleId=(int)$pdo->lastInsertId();
 $driver=['id'=>$testDriverId,'status'=>'Disponible'];$vehicle=['id'=>$testVehicleId,'status'=>'Disponible','assigned_driver_id'=>null];
 check((bool)$client&&(bool)$driver&&(bool)$vehicle,'Prérequis client, chauffeur et véhicule');
 $insert=$pdo->prepare("INSERT INTO deliveries(reference,client_id,client_site_id,scheduled_at,priority,status) VALUES(:reference,:client,:site,:scheduled,'Normale','Brouillon')");
 foreach([1,2] as $n){$ref='TEST-PLAN-'.date('His').'-'.$n;$insert->execute(['reference'=>$ref,'client'=>$client['client_id'],'site'=>$client['site_id'],'scheduled'=>'2031-06-10 08:00:00']);$ids[]=(int)$pdo->lastInsertId();}
 Planning::update($ids[0],'2031-06-10 08:00:00',120,(int)$driver['id'],(int)$vehicle['id'],'Test créneau initial');
 $blocked=false;try{Planning::update($ids[1],'2031-06-10 09:00:00',120,(int)$driver['id'],(int)$vehicle['id'],'Doit échouer');}catch(RuntimeException $e){$blocked=strpos($e->getMessage(),'Conflit')!==false;}
 check($blocked,'Chevauchement chauffeur/véhicule bloqué');
 Planning::update($ids[1],'2031-06-10 10:00:00',120,(int)$driver['id'],(int)$vehicle['id'],'Créneau successif');
 check((int)$pdo->query('SELECT COUNT(*) FROM delivery_planning_history WHERE delivery_id IN ('.implode(',',$ids).')')->fetchColumn()===2,'Changements journalisés');
 $rows=Planning::entries(['start'=>'2031-06-10 00:00:00','end'=>'2031-06-11 00:00:00']);
 check(count(array_filter($rows,function($r)use($ids){return in_array((int)$r['id'],$ids,true);}))===2,'Deux créneaux successifs visibles dans le planning');
}finally{
 if($ids){$pdo->exec('DELETE FROM deliveries WHERE id IN ('.implode(',',$ids).')');}
 if($testVehicleId){$pdo->prepare('DELETE FROM vehicles WHERE id=:id')->execute(['id'=>$testVehicleId]);}
 if($testDriverId){$pdo->prepare('DELETE FROM drivers WHERE id=:id')->execute(['id'=>$testDriverId]);}
}
echo "Tests Planning terminés.\n";
