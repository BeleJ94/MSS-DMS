<?php
declare(strict_types=1);
ini_set('session.save_path',sys_get_temp_dir());
$app=require dirname(__DIR__).'/bootstrap/app.php';require BASE_PATH.'/routes/web.php';
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Models\MailHistory;
$pdo=Database::connection();$checks=0;$suffix=bin2hex(random_bytes(5));
function historyCheck(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException($label);}echo 'OK - '.$label.PHP_EOL;$checks++;}
function historyInsert(string $table,array $data):int{global $pdo;$s=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$pdo->lastInsertId();}
function historyIdentity(?int $id):void{if($id){Session::put('auth_user_id',$id);}else{Session::forget('auth_user_id');}$p=new ReflectionProperty(Auth::class,'user');$p->setAccessible(true);$p->setValue(null,null);}
function historyResponse(string $path,array $query=[]):array{global $app;$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/MSS-DMS/public'.$path;$_SERVER['SCRIPT_NAME']='/MSS-DMS/public/index.php';$_GET=$query;$_POST=[];$r=$app->router()->dispatch(Request::capture());$result=[];foreach(['status','content','headers'] as $k){$p=new ReflectionProperty($r,$k);$p->setAccessible(true);$result[$k]=$p->getValue($r);}return $result;}
$pdo->beginTransaction();
try{
 $admin=(int)$pdo->query('SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug="administrateur" AND u.is_active=1 LIMIT 1')->fetchColumn();
 $other=(int)$pdo->query('SELECT u.id FROM users u WHERE u.is_active=1 AND NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug="administrateur") LIMIT 1')->fetchColumn();
 historyCheck($admin>0&&$other>0,'comptes administrateur et utilisateur disponibles');
 $client=historyInsert('clients',['code'=>'HISTORY-'.$suffix,'company_name'=>'Client suivi '.$suffix]);
 $delivery=historyInsert('deliveries',['reference'=>'HISTORY-'.$suffix,'client_id'=>$client,'status'=>'En transit','scheduled_at'=>'2026-10-28 10:00:00']);
 $destination=historyInsert('delivery_destinations',['delivery_id'=>$delivery,'stop_order'=>1,'label'=>'Site suivi','address_line'=>'Adresse audit']);
 $id=0;
 for($i=0;$i<30;$i++){$id=historyInsert('notification_outbox',['delivery_id'=>$delivery,'destination_id'=>$destination,'destination_key'=>$destination,'event'=>'delivery','recipient_name'=>'Audit','recipient_address'=>'audit'.$i.'@example.test','subject'=>'Objet <script>alert(1)</script>','body'=>'Message <script>alert(2)</script>','status'=>$i===0?'failed':($i===1?'pending':'sent'),'attempts'=>$i===0?5:1,'last_error'=>$i===0?'<img src=x onerror=alert(3)>':null,'created_at'=>$i===29?'2026-10-29 00:00:00':'2026-10-28 23:59:59','sent_at'=>$i>1?'2026-10-29 00:01:00':null]);}
 $f=MailHistory::filters(['q'=>'HISTORY-'.$suffix]);$data=MailHistory::search($f);
 historyCheck($data['total']===30&&count($data['rows'])===25&&$data['pages']===2,'pagination de 25 mails et compteurs exacts');
 $data=MailHistory::search(MailHistory::filters(['q'=>'HISTORY-'.$suffix,'p'=>999]));historyCheck(count($data['rows'])===5&&$data['currentPage']===2,'une page hors limite revient à la dernière page');
 $data=MailHistory::search(MailHistory::filters(['q'=>'HISTORY-'.$suffix,'status'=>'failed']));historyCheck($data['total']===1&&$data['rows'][0]['attempts']===5,'filtre sur les échecs et affichage des tentatives');
 $data=MailHistory::search(MailHistory::filters(['q'=>'HISTORY-'.$suffix,'from'=>'2026-10-28','to'=>'2026-10-28']));historyCheck($data['total']===29,'la date de fin est inclusive et exclut le lendemain');
 historyCheck(MailHistory::search(MailHistory::filters(['q'=>'audit12@example.test']))['total']===1,'recherche par destinataire');
 historyCheck(MailHistory::search(MailHistory::filters(['q'=>'Client suivi '.$suffix]))['total']===30,'recherche par client');
 historyCheck(MailHistory::search(MailHistory::filters(['q'=>"%' OR 1=1 --"]))['total']===0,'recherche paramétrée et caractères joker traités littéralement');
 foreach([['status'=>'invalid'],['event'=>'invalid'],['from'=>'2026-02-30'],['from'=>'2026-10-29','to'=>'2026-10-28']] as $invalid){try{MailHistory::filters($invalid);throw new LogicException('Validation absente');}catch(RuntimeException $e){historyCheck(true,'filtre invalide refusé explicitement');}}
 $pdo->exec('UPDATE delivery_destinations SET status="Livrée" WHERE id='.$destination);
 $event=(int)$pdo->query('SELECT id FROM notification_events WHERE delivery_id='.$delivery)->fetchColumn();
 $pdo->exec('UPDATE notification_events SET status="failed",attempts=5,last_error="Préparation impossible" WHERE id='.$event);
 historyCheck(MailHistory::search(MailHistory::filters(['kind'=>'events','q'=>'HISTORY-'.$suffix]))['total']===1,'les échecs de préparation des triggers sont visibles');
 historyIdentity(null);historyCheck(historyResponse('/settings/mail/history')['status']===302,'une session est obligatoire');
 historyIdentity($other);historyCheck(historyResponse('/settings/mail/history')['status']===403&&historyResponse('/settings/mail/history/'.$id)['status']===403,'liste et détails réservés aux administrateurs');
 historyIdentity($admin);$r=historyResponse('/settings/mail/history',['q'=>'HISTORY-'.$suffix]);
 historyCheck($r['status']===200&&strpos($r['content'],'Suivi des e-mails')!==false,'la page administrateur et la navigation sont rendues');
 file_put_contents(BASE_PATH.'/storage/cache/mail-history-preview.html',$r['content']);
 historyCheck(($r['headers']['Cache-Control']??'')==='no-store','les destinataires ne sont pas mis en cache');
 $r=historyResponse('/settings/mail/history/'.$id);historyCheck($r['status']===200&&strpos($r['content'],'&lt;script&gt;')!==false&&strpos($r['content'],'<script>alert')===false,'objet et contenu échappés contre les injections HTML');
 $r=historyResponse('/settings/mail/history/'.$event,['kind'=>'events']);historyCheck($r['status']===200&&strpos($r['content'],'Préparation impossible')!==false,'le détail de l’événement affiche son erreur');
 historyCheck(historyResponse('/settings/mail/history/999999999')['status']===404,'une notification absente affiche une erreur 404');
 historyCheck(historyResponse('/settings/mail/history',['from'=>'bad'])['status']===422,'une date invalide affiche une erreur explicite');
 $r=historyResponse('/settings/mail/history',['q'=>'missing-'.$suffix]);historyCheck($r['status']===200&&strpos($r['content'],'Aucun résultat')!==false,'l’état sans résultat est lisible');
 echo 'MAIL_HISTORY_OK: '.$checks.' checks; rolled back; no email sent'.PHP_EOL;
}finally{if($pdo->inTransaction()){$pdo->rollBack();}historyIdentity(null);}
