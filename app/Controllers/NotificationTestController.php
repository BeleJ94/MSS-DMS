<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ClientNotification;
use PDOException;
use RuntimeException;
use Throwable;

final class NotificationTestController extends Controller
{
    public function index(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
        return new Response(View::render('settings/notification-tests',['title'=>'Tests des notifications de livraison','page'=>'notification-tests','recipient'=>Auth::user()['email'],'driver'=>MailSettings::current()['MAIL_DRIVER']]),200,['Cache-Control'=>'no-store']);
    }

    public function data(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return $this->denied();}
        try{
            $pdo=Database::connection();
            $id=filter_var($request->query('delivery_id',0),FILTER_VALIDATE_INT);
            if($id){
                $s=$pdo->prepare('SELECT d.id,d.reference,d.status,c.company_name,d.client_id FROM deliveries d JOIN clients c ON c.id=d.client_id WHERE d.id=:id');$s->execute(['id'=>$id]);$delivery=$s->fetch();
                if(!$delivery){throw new RuntimeException('Livraison introuvable. Relancez la recherche.');}
                $s=$pdo->prepare('SELECT dd.id,dd.stop_order,dd.label,dd.status,EXISTS(SELECT 1 FROM delivery_pods p WHERE p.destination_id=dd.id) has_pod FROM delivery_destinations dd WHERE dd.delivery_id=:id ORDER BY dd.stop_order,dd.id');$s->execute(['id'=>$id]);$destinations=$s->fetchAll();
                $s=$pdo->prepare('SELECT full_name,email,notify_on FROM client_notification_recipients WHERE client_id=:id AND notify_email=1 AND email IS NOT NULL AND email<>"" ORDER BY id');$s->execute(['id'=>$delivery['client_id']]);
                $recipients=$s->fetchAll();
                $effective=['dispatch'=>ClientNotification::recipients($pdo,(int)$id,'dispatch'),'arrival'=>[],'delivery'=>[]];
                foreach($destinations as $destination){foreach(['arrival','delivery'] as $event){$effective[$event][(int)$destination['id']]=ClientNotification::recipients($pdo,(int)$id,$event,(int)$destination['id']);}}
                return $this->result(['success'=>true,'delivery'=>$delivery,'destinations'=>$destinations,'recipients'=>$recipients,'effective_recipients'=>$effective]);
            }
            $search=$request->query('search','');$search=is_string($search)?mb_substr(trim($search),0,100):'';
            $s=$pdo->prepare('SELECT d.id,d.reference,d.status,c.company_name FROM deliveries d JOIN clients c ON c.id=d.client_id WHERE d.reference LIKE :reference OR c.company_name LIKE :client ORDER BY d.scheduled_at DESC,d.id DESC LIMIT 100');
            $s->execute(['reference'=>'%'.$search.'%','client'=>'%'.$search.'%']);
            return $this->result(['success'=>true,'deliveries'=>$s->fetchAll()]);
        }catch(Throwable $e){return $this->failure($e);}
    }

    public function run(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return $this->denied();}
        try{
            $action=$request->input('operation');
            if(!in_array($action,['preview','send'],true)){throw new RuntimeException('Choisissez Aperçu ou Envoyer le test.');}
            $id=filter_var($request->input('delivery_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $event=$request->input('event');
            $destination=filter_var($request->input('destination_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null;
            $recipient=$request->input('test_recipient');
            if(!$id){throw new RuntimeException('Sélectionnez une livraison dans la liste avant de préparer le test.');}
            if(!is_string($event) || !isset(ClientNotification::EVENTS[$event])){throw new RuntimeException('Choisissez le type de notification à tester.');}
            if(!is_string($recipient) || !filter_var(trim($recipient),FILTER_VALIDATE_EMAIL)){throw new RuntimeException('Saisissez une adresse e-mail de test valide. Aucun contact client ne sera ajouté automatiquement.');}
            $recipient=trim($recipient);
            $message=ClientNotification::prepareTest(Database::connection(),$id,$event,$destination,$action==='send');
            $settings=MailSettings::current();
            if($action==='send'){
                $settings=MailSettings::validate($settings,$settings);
                Mailer::send($recipient,'',$message['subject'],$message['body'],$message['attachments'],$settings,$message['html']);
            }
            unset($message['attachments']);
            return $this->result(['success'=>true,'preview'=>$message,'recipient'=>$recipient,'simulated'=>$settings['MAIL_DRIVER']==='log','message'=>$action==='preview'?'Aperçu préparé.':($settings['MAIL_DRIVER']==='log'?'Test simulé dans le journal : aucun e-mail n’a été envoyé.':'Le serveur SMTP a accepté le test pour '.$recipient.'. Vérifiez sa réception et les courriers indésirables.')]);
        }catch(Throwable $e){return $this->failure($e);}
    }

    private function failure(Throwable $exception): Response
    {
        $message=$exception instanceof RuntimeException && !($exception instanceof PDOException)?$exception->getMessage():'Les données du test ne sont pas accessibles. Vérifiez la connexion à la base et les migrations des livraisons, preuves de livraison et contacts de notification.';
        $password=MailSettings::current()['MAIL_PASSWORD'];
        foreach([$password,base64_encode($password)] as $secret){if($secret!==''){$message=str_replace($secret,'[masqué]',$message);}}
        return $this->result(['success'=>false,'message'=>$message],422);
    }

    private function denied(): Response{return $this->result(['success'=>false,'message'=>'Seul un administrateur peut tester les notifications de livraison.'],403);}
    private function result(array $data,int $status=200): Response{return new Response((string)json_encode($data,JSON_UNESCAPED_UNICODE),$status,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);}
}
