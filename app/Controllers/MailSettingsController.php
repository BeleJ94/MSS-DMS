<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Core\MailSettingsValidationException;
use App\Core\NotificationEmail;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use Throwable;

final class MailSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return $this->forbidden();}
        return $this->screen(MailSettings::current());
    }

    public function update(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return $this->isAjax()?$this->result(['success'=>false,'message'=>'Accès refusé : seul un administrateur peut configurer les e-mails ou effectuer un test.'],403):$this->forbidden();}
        $current=MailSettings::current();$values=$current;
        $recipient=$request->input('test_recipient','');
        $recipient=is_string($recipient)?trim($recipient):'';
        try{
            $values=MailSettings::validate($request->all(),$current);
            $action=$request->input('action');
            if($action==='test'){
                if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)){throw new MailSettingsValidationException('test_recipient','L’adresse du destinataire de test est absente ou invalide. Saisissez une adresse complète à laquelle vous avez accès.');}
                $subject='[TEST] Configuration e-mail MSS-DMS';
                $body="Bonjour,\n\nCe message vérifie le fonctionnement de la configuration e-mail de MSS-DMS. Il ne concerne aucune livraison réelle.\n\nDate : ".date('d/m/Y H:i:s');
                Mailer::send($recipient,'',$subject,$body,[],$values,NotificationEmail::render($subject,$body,'configuration','Vérification de la messagerie'));
                $message=$values['MAIL_DRIVER']==='log'?'Test enregistré dans le journal. Aucun e-mail n’a été envoyé.':'Le serveur SMTP a accepté l’e-mail de test pour '.$recipient.'. Vérifiez sa réception et les courriers indésirables.';
                $message.=$this->isAjax()?' Les paramètres testés n’ont pas été enregistrés.':' Les paramètres testés n’ont pas été enregistrés. Si vous avez saisi un nouveau mot de passe, ressaisissez-le avant l’enregistrement.';
            }elseif($action==='save'){
                MailSettings::save($values);
                $message='Configuration mail enregistrée. Elle sera utilisée pour les prochains envois.';
            }else{throw new \RuntimeException('Action inconnue.');}
            if($this->isAjax()){return $this->result(['success'=>true,'message'=>$message,'action'=>$action,'has_password'=>MailSettings::current()['MAIL_PASSWORD']!=='','simulated'=>$action==='test' && $values['MAIL_DRIVER']==='log']);}
            return $this->screen($values,$message,null,$recipient);
        }catch(Throwable $e){
            $message=$e instanceof \RuntimeException && !($e instanceof \PDOException)?$e->getMessage():'Le serveur n’a pas pu terminer l’opération. Aucun succès n’a été confirmé. Vérifiez les journaux PHP et réessayez après correction.';
            foreach([$current['MAIL_PASSWORD'],$values['MAIL_PASSWORD'],base64_encode($values['MAIL_PASSWORD'])] as $secret){if($secret!==''){$message=str_replace($secret,'[masqué]',$message);}}
            if($this->isAjax()){return $this->result(['success'=>false,'message'=>$message,'errors'=>$e instanceof MailSettingsValidationException?[$e->field=>$message]:[]],422);}
            return $this->screen($values,null,$message,$recipient,422);
        }
    }

    private function screen(array $values,?string $success=null,?string $error=null,string $recipient='',int $status=200): Response
    {
        // Never send the SMTP password back to the browser.
        $hasPassword=MailSettings::current()['MAIL_PASSWORD']!=='';
        unset($values['MAIL_PASSWORD']);
        return new Response(View::render('settings/mail',['title'=>'Configuration e-mail','page'=>'mail-settings','settings'=>$values,'hasPassword'=>$hasPassword,'success'=>$success,'error'=>$error,'recipient'=>$recipient?:Auth::user()['email']]),$status,['Cache-Control'=>'no-store']);
    }

    private function forbidden(): Response
    {
        return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);
    }

    private function isAjax(): bool
    {
        return stripos((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')!==false;
    }

    private function result(array $data,int $status=200): Response
    {
        return new Response((string)json_encode($data,JSON_UNESCAPED_UNICODE),$status,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);
    }
}
