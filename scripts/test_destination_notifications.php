<?php

declare(strict_types=1);

// Real database integration in a rolled-back transaction. No Mailer or queue worker is invoked.
ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\EmailRecipients;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\Delivery;

$pdo=Database::connection();$pdo->beginTransaction();$checks=0;$suffix=bin2hex(random_bytes(5));
function destinationCheck(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException($label);}$checks++;echo 'OK - '.$label.PHP_EOL;}
function destinationInsert(string $table,array $data):int{global $pdo;$s=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$pdo->lastInsertId();}
function destinationEmails(array $recipients):array{$emails=array_column($recipients,'email');sort($emails);return $emails;}
try{
    destinationCheck(EmailRecipients::parse(" A@example.test; b@example.test\nA@EXAMPLE.TEST, ")===['a@example.test','b@example.test'],'les séparateurs, espaces, doublons et majuscules sont normalisés');
    foreach(['invalid',implode(',',array_map(static function(int $i):string{return 'recipient'.$i.'@example.test';},range(1,21)))] as $input){try{EmailRecipients::parse($input);throw new LogicException('Une liste invalide doit être refusée.');}catch(RuntimeException $e){destinationCheck(true,'une liste invalide ou trop longue est refusée');}}
    $client=destinationInsert('clients',['code'=>'TEST-MAIL-A-'.$suffix,'company_name'=>'Client A']);
    $other=destinationInsert('clients',['code'=>'TEST-MAIL-B-'.$suffix,'company_name'=>'Client B']);
    $contact=destinationInsert('client_contacts',['client_id'=>$client,'full_name'=>'Réception A','email'=>'contact@example.test']);
    $site1=destinationInsert('client_sites',['client_id'=>$client,'name'=>'Dépôt A','address_line1'=>'Rue A','city'=>'Lubumbashi','notification_emails'=>"site-a@example.test\nshared@example.test"]);
    $site2=destinationInsert('client_sites',['client_id'=>$client,'name'=>'Dépôt B','address_line1'=>'Rue B','city'=>'Lubumbashi','notification_emails'=>'site-b@example.test']);
    $siteOther=destinationInsert('client_sites',['client_id'=>$other,'name'=>'Site étranger','address_line1'=>'Rue C','city'=>'Lubumbashi','notification_emails'=>'confidential@example.test']);
    destinationInsert('client_notification_recipients',['client_id'=>$client,'full_name'=>'Direction','email'=>'SHARED@example.test','notify_email'=>1,'notify_on'=>'dispatch,arrival,delivery']);
    destinationInsert('client_notification_recipients',['client_id'=>$client,'full_name'=>'Livraison uniquement','email'=>'delivery-only@example.test','notify_email'=>1,'notify_on'=>'delivery']);
    $delivery=destinationInsert('deliveries',['reference'=>'TEST-SITE-'.$suffix,'client_id'=>$client,'client_site_id'=>$site1,'client_contact_id'=>$contact,'scheduled_at'=>date('Y-m-d H:i:s')]);
    $a=destinationInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>1,'label'=>'Dépôt A','address_line'=>'Rue A','city'=>'Lubumbashi','client_site_id'=>$site1,'notification_emails'=>'obsolete@example.test']);
    $b=destinationInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>2,'label'=>'Dépôt B','address_line'=>'Rue B','city'=>'Lubumbashi','client_site_id'=>$site2]);
    $free=destinationInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>3,'label'=>'Libre','address_line'=>'Rue libre','notification_emails'=>'free@example.test']);
    $foreign=destinationInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>4,'label'=>'Incorrect','address_line'=>'Rue C','client_site_id'=>$siteOther]);
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'arrival',$a))===['shared@example.test','site-a@example.test'],'l’arrivée ne cible que le site concerné et les contacts généraux, sans doublon');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'delivery',$b))===['delivery-only@example.test','shared@example.test','site-b@example.test'],'la livraison respecte les abonnements généraux et les destinataires du site B');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'dispatch'))===['free@example.test','shared@example.test','site-a@example.test','site-b@example.test'],'le départ regroupe uniquement les sites de la tournée et les contacts abonnés');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'arrival',$free))===['free@example.test','shared@example.test'],'une destination libre peut posséder ses propres destinataires');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'arrival',$foreign))===['shared@example.test'],'aucune adresse d’un autre client ne peut être utilisée');
    destinationCheck(ClientNotification::queue($pdo,$delivery,'arrival',$a)===2 && ClientNotification::queue($pdo,$delivery,'arrival',$a)===0,'la file enregistre chaque destinataire une seule fois');
    $snapshot=Client::find($client);
    $snapshot['sites'][0]['notification_emails']="new-site-a@example.test; SHARED@example.test";
    $sync=new ReflectionMethod(Client::class,'replaceChildren');$sync->setAccessible(true);$sync->invoke(null,$pdo,$client,$snapshot);
    $after=Client::find($client);
    destinationCheck(array_column($after['sites'],'id')===array_column($snapshot['sites'],'id'),'modifier un site conserve ses identifiants');
    destinationCheck($pdo->query('SELECT client_site_id FROM deliveries WHERE id='.$delivery)->fetchColumn()==$site1 && $pdo->query('SELECT client_contact_id FROM deliveries WHERE id='.$delivery)->fetchColumn()==$contact,'les liens existants de la livraison vers le site et le contact sont conservés');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'arrival',$a))===['new-site-a@example.test','shared@example.test'],'une modification de la fiche site est utilisée pour les prochaines notifications');
    $s=$pdo->prepare('SELECT recipient_address FROM notification_outbox WHERE delivery_id=? ORDER BY recipient_address');$s->execute([$delivery]);
    destinationCheck($s->fetchAll(PDO::FETCH_COLUMN)===['shared@example.test','site-a@example.test'],'les messages déjà préparés conservent leurs destinataires initiaux');
    $siteRows=$after['sites'];$sync->invoke(null,$pdo,$client,['sites'=>[$siteRows[1]]]);
    destinationCheck($pdo->query('SELECT status FROM client_sites WHERE id='.$site1)->fetchColumn()==='inactive','supprimer un site utilisé le désactive sans casser l’historique');
    $replacement=new ReflectionMethod(Delivery::class,'replaceDestinations');$replacement->setAccessible(true);
    $pdo->exec('SAVEPOINT before_invalid_site');
    try{$replacement->invoke(null,$pdo,$delivery,[['label'=>'Test interdit','address'=>'Adresse interdite','client_site_id'=>$siteOther]]);throw new LogicException('Le site d’un autre client doit être refusé.');}catch(RuntimeException $e){destinationCheck(true,'une livraison ne peut pas être liée au site d’un autre client');}finally{$pdo->exec('ROLLBACK TO SAVEPOINT before_invalid_site');}
    $ids=$replacement->invoke(null,$pdo,$delivery,[['label'=>'Dépôt B','address'=>'Rue B','city'=>'Lubumbashi','client_site_id'=>$site2],['label'=>'Libre','address'=>'Rue libre','notification_emails'=>'direct@example.test']]);
    $s=$pdo->prepare('SELECT client_site_id,notification_emails FROM delivery_destinations WHERE id=?');$s->execute([$ids[0]]);$row=$s->fetch();
    destinationCheck((int)$row['client_site_id']===$site2 && $row['notification_emails']==='site-b@example.test','une nouvelle livraison conserve le lien et reprend les adresses du site');
    destinationCheck(destinationEmails(ClientNotification::recipients($pdo,$delivery,'arrival',$ids[1]))===['direct@example.test','shared@example.test'],'une adresse de destination libre est enregistrée et routée');
    $validation=new ReflectionMethod(\App\Controllers\ClientController::class,'validate');$validation->setAccessible(true);
    $errors=$validation->invoke(new \App\Controllers\ClientController(),['company_name'=>'Test','sites'=>[['name'=>'Site','address_line1'=>'Rue test','city'=>'Lubumbashi','notification_emails'=>'adresse-invalide']]]);
    destinationCheck(isset($errors['sites']) && strpos($errors['sites'],'adresse-invalide')!==false,'le formulaire client reçoit une erreur explicite sur l’adresse à corriger');
    $html=\App\Core\View::partial('clients/_form',['client'=>Client::find($client),'formId'=>'test-client-site-mails']);
    destinationCheck(strpos($html,'data-field="notification_emails"')!==false && strpos($html,'data-field="id"')!==false,'le formulaire affiche les adresses du site et conserve ses identifiants');
    destinationCheck(strpos($html,'value="delivery" selected')!==false,'les préférences des contacts généraux sont conservées lors de l’édition');
    echo 'DESTINATION_NOTIFICATIONS_OK: '.$checks.' checks; no mail sent; transaction rolled back'.PHP_EOL;
}finally{if($pdo->inTransaction()){$pdo->rollBack();}session_write_close();}
