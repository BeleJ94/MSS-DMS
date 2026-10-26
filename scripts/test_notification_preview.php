<?php

declare(strict_types=1);

// Test data is supplied by an in-memory PDO double. No database or real e-mail.
final class PreviewStatement extends PDOStatement
{
    private $provider;
    private $rows=[];
    public function __construct(callable $provider){$this->provider=$provider;}
    #[\ReturnTypeWillChange]
    public function execute($params=null){$this->rows=($this->provider)($params??[]);return true;}
    #[\ReturnTypeWillChange]
    public function fetch($mode=PDO::FETCH_DEFAULT,$orientation=PDO::FETCH_ORI_NEXT,$offset=0){return array_shift($this->rows)??false;}
    #[\ReturnTypeWillChange]
    public function fetchAll($mode=PDO::FETCH_DEFAULT,...$args){return $this->rows;}
    #[\ReturnTypeWillChange]
    public function fetchColumn($column=0){$row=$this->fetch();return $row===false?false:array_values($row)[$column];}
}

final class PreviewDatabase extends PDO
{
    public $pod=false;
    public $queries=[];
    public function __construct(){}
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]){
        if(strpos($query,'SELECT ')!==0){throw new RuntimeException('A test must never modify a delivery or its notification queue.');}
        $this->queries[]=$query;
        return new PreviewStatement(function(array $params) use($query):array{
            if(strpos($query,'FROM deliveries d')!==false){return ($params['id']??0)===10?[['id'=>10,'reference'=>'LIV-TEST-10','client_id'=>2,'company_name'=>'Client Test','first_name'=>'Test','last_name'=>'Chauffeur','driver_phone'=>'123','registration_number'=>'VEH-TEST']]:[];}
            if(strpos($query,'SELECT * FROM delivery_destinations')===0){return ($params['id']??0)===20 && ($params['delivery']??0)===10?[['id'=>20,'stop_order'=>1,'label'=>'Entrepôt test','address_line'=>'Rue du Test','city'=>'Lubumbashi']]:[];}
            if(strpos($query,'SELECT stop_order')===0){return [['stop_order'=>1,'label'=>'Entrepôt test','address_line'=>'Rue du Test','city'=>'Lubumbashi']];}
            if(strpos($query,'SELECT id FROM delivery_pods')===0){return $this->pod?[['id'=>30]]:[];}
            if(strpos($query,'SELECT recipient_name')===0){return [['recipient_name'=>'Réception Test','captured_at'=>'2026-10-23 12:00:00']];}
            if(strpos($query,'FROM delivery_goods')!==false){return [['description_snapshot'=>'Colis de test','quantity'=>10,'delivered_quantity'=>8,'unit'=>'colis','delivery_condition'=>'Partielle']];}
            if(strpos($query,'SELECT pdf_data')===0){return [['pdf_data'=>"%PDF-1.4\nTest stored document"]];}
            throw new RuntimeException('Unexpected test query: '.$query);
        });
    }
}

ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';
$pdo=new PreviewDatabase();
$property=(new ReflectionClass(App\Core\Database::class))->getProperty('connection');$property->setAccessible(true);$property->setValue(null,$pdo);
$checks=0;
function checkPreview(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);}$checks++;echo 'OK - '.$label.PHP_EOL;}
function rejectedPreview(callable $call,string $label):void{try{$call();}catch(RuntimeException $e){checkPreview(true,$label);return;}throw new RuntimeException($label);}
try{
    $message=App\Models\ClientNotification::prepareTest($pdo,10,'dispatch',null);
    checkPreview(strpos($message['subject'],'[TEST] Livraison LIV-TEST-10 en route')===0,'le départ utilise le modèle réel et un objet marqué TEST');
    checkPreview(strpos($message['body'],'MESSAGE DE TEST')===0 && strpos($message['body'],'Entrepôt test')!==false,'le message identifie le test et les destinations');
    checkPreview(strpos($message['html'],'EN ACHEMINEMENT')!==false && strpos($message['html'],'MESSAGE DE TEST')!==false,'l’aperçu du départ possède le rendu HTML et le bandeau de test');
    $message=App\Models\ClientNotification::prepareTest($pdo,10,'arrival',20);
    checkPreview(strpos($message['body'],'Rue du Test')!==false && $message['attachments']===[],'l’arrivée reprend la destination choisie sans pièce jointe');
    $message=App\Models\ClientNotification::prepareTest($pdo,10,'delivery',20,true);
    checkPreview($message['attachment_name']===null && strpos($message['body'],'Aucun bon de livraison')!==false,'un test sans preuve annonce clairement l’absence de PDF');
    checkPreview(strpos($message['body'],'Partielle')!==false,'les réserves réelles apparaissent dans le test');
    checkPreview(strpos($message['html'],'Aucun bon de livraison')!==false && strpos($message['html'],'Partielle')!==false,'le rendu HTML respecte les réserves et l’absence de pièce jointe');
    $pdo->pod=true;
    $message=App\Models\ClientNotification::prepareTest($pdo,10,'delivery',20,false);
    checkPreview($message['attachment_name']==='Bon-de-livraison-LIV-TEST-10.pdf' && $message['attachments']===[],'l’aperçu annonce le PDF sans exposer ses données');
    $message=App\Models\ClientNotification::prepareTest($pdo,10,'delivery',20,true);
    checkPreview(count($message['attachments'])===1 && strpos($message['attachments'][0]['data'],'%PDF-')===0,'le test de livraison joint le bon PDF existant');
    checkPreview(strpos($message['body'],'Réception Test')!==false,'le réceptionnaire réel apparaît dans le test');
    rejectedPreview(function()use($pdo){App\Models\ClientNotification::prepareTest($pdo,10,'arrival',999);},'une destination étrangère à la livraison est refusée');
    rejectedPreview(function()use($pdo){App\Models\ClientNotification::prepareTest($pdo,10,'arrival',null);},'une destination est obligatoire pour une arrivée');
    rejectedPreview(function()use($pdo){App\Models\ClientNotification::prepareTest($pdo,999,'dispatch',null);},'une livraison inexistante est refusée');
    rejectedPreview(function()use($pdo){App\Models\ClientNotification::prepareTest($pdo,10,'unknown',null);},'un événement inconnu est refusé');
    checkPreview(count(array_filter($pdo->queries,function(string $query):bool{return stripos($query,'notification_outbox')!==false;}))===0,'aucune notification automatique n’est consommée ni créée');
    echo 'NOTIFICATION_PREVIEW_OK: '.$checks.' checks'.PHP_EOL;
}finally{$property->setValue(null,null);session_write_close();}
