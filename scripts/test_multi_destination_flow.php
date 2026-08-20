<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Models\Delivery;

$pdo=Database::connection();$deliveryId=null;
function expectMulti(bool $condition,string $message): void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}

try{
    $client=$pdo->query("SELECT id FROM clients WHERE status<>'archived' ORDER BY id LIMIT 1")->fetchColumn();
    $user=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if(!$client||!$user){throw new RuntimeException('Prérequis client/utilisateur absents.');}
    Session::put('auth_user_id',(int)$user);
    $payload=['client_id'=>(int)$client,'scheduled_at'=>date('Y-m-d\TH:i',time()+3600),'priority'=>'Normale','driver_id'=>'','vehicle_id'=>'','observations'=>'Test multi-destinations','destinations'=>[
        ['label'=>'Entrepôt Nord','address'=>'12 avenue Industrielle','city'=>'Lubumbashi','contact_name'=>'Contact Nord','contact_phone'=>'+243 900 000 001','instructions'=>'Quai 2','latitude'=>'-11.6500','longitude'=>'27.4700'],
        ['label'=>'Agence Centre','address'=>'45 boulevard Central','city'=>'Lubumbashi','contact_name'=>'Contact Centre','contact_phone'=>'+243 900 000 002','instructions'=>'Réception arrière','latitude'=>'','longitude'=>''],
    ],'goods'=>[
        ['destination_index'=>0,'description'=>'Colis destination Nord','quantity'=>2,'unit'=>'pièce','unit_weight_kg'=>5],
        ['destination_index'=>1,'description'=>'Colis destination Centre','quantity'=>3,'unit'=>'carton','unit_weight_kg'=>4],
    ]];
    $invalidPayload=$payload;$invalidPayload['goods']=[$payload['goods'][0]];$rejected=false;try{Delivery::create($invalidPayload);}catch(RuntimeException $e){$rejected=strpos($e->getMessage(),'destination 2')!==false;}expectMulti($rejected,'une destination sans marchandise est refusée sans réaffectation silencieuse');
    $deliveryId=Delivery::create($payload);$delivery=Delivery::find($deliveryId);
    expectMulti($delivery!==null&&$delivery['client_site_id']===null,'la course est créée sans site client imposé');
    expectMulti(count($delivery['destinations'])===2,'deux destinations libres sont enregistrées');
    expectMulti($delivery['destinations'][0]['label']==='Entrepôt Nord'&&$delivery['destinations'][1]['label']==='Agence Centre','l’ordre des destinations est conservé');
    expectMulti(count($delivery['goods'])===2&&(int)$delivery['goods'][0]['destination_order']===1&&(int)$delivery['goods'][1]['destination_order']===2,'les marchandises sont affectées à leurs destinations');
    $listed=array_values(array_filter(Delivery::listing(['search'=>'Entrepôt Nord']),function(array $row)use($deliveryId):bool{return(int)$row['id']===$deliveryId;}));
    expectMulti(count($listed)===1&&(int)$listed[0]['destination_count']===2,'la recherche et la liste exposent la course multi-destinations');
    echo "MULTI_DESTINATION_FLOW_OK\n";
}finally{if($deliveryId){$pdo->prepare('DELETE FROM deliveries WHERE id=:id')->execute(['id'=>$deliveryId]);}}
