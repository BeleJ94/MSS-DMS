<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\LoginLogger;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Models\DriverMission;
use App\Models\DeliveryPod;
use App\Models\Incident;

/** JSON adapter over the existing driver domain. Cookies + CSRF remain mandatory. */
final class MobileDriverController extends Controller
{
    public function bootstrap(Request $request): Response
    {
        return $this->json(['csrf_token'=>Csrf::token()]);
    }

    public function login(Request $request): Response
    {
        $email=trim((string)$request->input('email',''));
        $password=(string)$request->input('password','');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $password==='') {
            return $this->json(['message'=>'Renseignez votre e-mail et votre mot de passe.'],422);
        }
        if (LoginThrottle::isBlocked($email)) {
            LoginLogger::write(null,$email,false,'blocked');
            return $this->json(['message'=>'Trop de tentatives. Réessayez dans 15 minutes.'],429);
        }
        if (!Auth::attempt($email,$password)) {
            LoginLogger::write(null,$email,false);
            return $this->json(['message'=>'Identifiants incorrects ou compte désactivé.'],401);
        }
        LoginLogger::write(Auth::id(),$email,true);
        if (!Auth::can('driver_app.access') || !DriverMission::driver()) {
            Auth::logout();
            return $this->json(['message'=>'Demandez au dispatching de relier votre compte à une fiche chauffeur active.'],403);
        }
        Database::connection()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute(['id'=>Auth::id()]);
        $result=['user'=>Auth::user(),'driver'=>DriverMission::driver(),'csrf_token'=>Csrf::token()];
        if($request->input('remember_device')===true){
            \App\Models\MobileSession::revokeCurrent();
            $result['mobile_token']=\App\Models\MobileSession::issue((int)Auth::id());
        }
        return $this->json($result);
    }

    public function resume(Request $request): Response
    {
        $token=$request->input('mobile_token','');
        if(!is_string($token) || !\App\Models\MobileSession::resume($token)){
            return $this->json(['message'=>'Votre connexion a expiré. Reconnectez-vous.'],401);
        }
        return $this->me($request);
    }

    public function me(Request $request): Response
    {
        return $this->json(['user'=>Auth::user(),'driver'=>DriverMission::driver(),'csrf_token'=>Csrf::token()]);
    }

    public function logout(Request $request): Response
    {
        $user=Auth::user();
        LoginLogger::write(Auth::id(),(string)$user['email'],true,'logout');
        \App\Models\MobileSession::revokeCurrent();
        Auth::logout();
        return $this->json(['success'=>true]);
    }

    public function missions(Request $request): Response
    {
        return $this->json(['data'=>DriverMission::listing()]);
    }

    public function show(Request $request): Response
    {
        $mission=DriverMission::findOwned((int)$request->param('id'));
        if (!$mission) { return $this->json(['message'=>'Mission introuvable.'],404); }
        $mission['next_action']=DriverMission::nextAction($mission['status']);
        $mission['pods']=DeliveryPod::summaries((int)$mission['id']);
        $mission['incident_types']=Incident::TYPES;
        return $this->json(['data'=>$mission]);
    }

    public function start(Request $request): Response
    {
        try {
            $position=$request->input('initial_position');
            if(!is_array($position)){throw new \RuntimeException('Une position GPS est nécessaire pour commencer la mission.');}
            \App\Models\MobileMission::start((int)$request->param('id'),$position);
            return $this->json(['success'=>true,'message'=>'Mission commencée.']);
        } catch(\Throwable $e) {
            return $this->json(['success'=>false,'message'=>$e instanceof \RuntimeException && !($e instanceof \PDOException)?$e->getMessage():'Impossible de commencer la mission. Réessayez.'],422);
        }
    }

    public function deliver(Request $request): Response
    {
        try {
            $declaration = $request->input('confirmed') === true;
            $photo = $declaration ? [] : \App\Core\PodUpload::photo($request->file('delivery_photo'),true,'La photo de livraison');
            $id=(int)$request->param('id');$destination=(int)$request->input('destination_id',0);
            $result=\App\Models\MobileMission::deliver($id,$destination,$request->all(),$photo,$declaration);
            return $this->json(['success'=>true,'message'=>'Livraison confirmée. Bon de livraison créé.','already_delivered'=>$result['already_delivered'],'pdf_url'=>rtrim((string)\App\Core\Env::get('APP_URL',''),'/').'/deliveries/'.$id.'/destinations/'.$destination.'/pod.pdf']);
        } catch(\Throwable $e) {
            return $this->json(['success'=>false,'message'=>$e instanceof \RuntimeException && !($e instanceof \PDOException)?$e->getMessage():'La livraison et son bon n’ont pas pu être enregistrés. Réessayez.'],422);
        }
    }

    public function action(Request $request): Response
    {
        // Validate GPS before the existing controller can change the mission status.
        if ($request->input('action')==='start') {
            $p=$request->input('initial_position');
            if (!is_array($p) || empty($p['position_id']) || strlen((string)$p['position_id'])>80 ||
                !is_numeric($p['latitude']??null) || abs((float)$p['latitude'])>90 ||
                !is_numeric($p['longitude']??null) || abs((float)$p['longitude'])>180 ||
                !is_numeric($p['accuracy']??null) || (float)$p['accuracy']<0 || (float)$p['accuracy']>10000 ||
                !is_string($p['captured_at']??null) || !($timestamp=strtotime($p['captured_at'])) ||
                $timestamp>time()+300 || $timestamp<time()-300) {
                return $this->json(['message'=>'Une position GPS récente et valide est requise pour partir.'],422);
            }
        }
        return (new DriverAppController())->action($request);
    }
}
