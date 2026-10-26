<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\DriverMission;
use App\Models\GpsTracking;
use App\Models\DeliveryPod;
use RuntimeException;
use Throwable;

final class DriverAppController extends Controller
{
    public function index(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
        $driver=DriverMission::driver();return $this->view('driver-app/index',['title'=>'Mes missions','driver'=>$driver,'missions'=>$driver?DriverMission::listing():[],'activeMissionId'=>$driver?GpsTracking::activeMissionId():null,'baseUrl'=>$this->baseUrl()],'layouts/driver-app');
    }
    public function show(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}$mission=DriverMission::findOwned((int)$request->param('id'));if(!$mission){return new Response(View::render('driver-app/404',['title'=>'Mission introuvable','baseUrl'=>$this->baseUrl(),'driver'=>DriverMission::driver(),'activeMissionId'=>GpsTracking::activeMissionId()],'layouts/driver-app'),404);}return $this->view('driver-app/show',['title'=>$mission['reference'],'mission'=>$mission,'pod'=>DeliveryPod::summary((int)$mission['id']),'pods'=>DeliveryPod::summaries((int)$mission['id']),'driver'=>DriverMission::driver(),'activeMissionId'=>in_array($mission['status'],GpsTracking::ACTIVE_STATUSES,true)?(int)$mission['id']:GpsTracking::activeMissionId(),'baseUrl'=>$this->baseUrl()],'layouts/driver-app');
    }
    public function action(Request $request): Response
    {
        if(!Auth::can('driver_app.access') || !DriverMission::driver()){return $this->json(['success'=>false,'message'=>'Accès refusé : aucune fiche chauffeur active.'],403);}
        $pdo=Database::connection();$ownsTransaction=!$pdo->inTransaction();
        // A savepoint keeps failed requests atomic even inside an integration-test transaction.
        if($ownsTransaction){$pdo->beginTransaction();}else{$pdo->exec('SAVEPOINT driver_app_action');}
        try{
            $action=$request->input('action','');if(!is_string($action)){throw new RuntimeException('Action invalide.');}$action=trim($action);
            $missionId=(int)$request->param('id');$gps=null;
            if($action==='start' && !is_array($request->input('initial_position'))){throw new RuntimeException('La première position GPS est obligatoire pour démarrer la mission.');}
            if($action==='confirm_unloading'){
                $goods=$request->input('goods',[]);if(!is_array($goods)){throw new RuntimeException('Format de contrôle invalide.');}
                $message=DriverMission::confirmUnloading($missionId,$goods);
            }else{
                $message=DriverMission::perform($missionId,$action,(string)$request->input('description',''));
                if($action==='start'){$gps=GpsTracking::recordBatch($missionId,[$request->input('initial_position')],'pwa');$message='Mission démarrée · position GPS enregistrée sur le serveur.';}
            }
            if($ownsTransaction){$pdo->commit();}else{$pdo->exec('RELEASE SAVEPOINT driver_app_action');}
            return $this->json(['success'=>true,'message'=>$message,'gps'=>$gps]);
        }catch(Throwable $e){
            if($pdo->inTransaction()){if($ownsTransaction){$pdo->rollBack();}else{$pdo->exec('ROLLBACK TO SAVEPOINT driver_app_action');$pdo->exec('RELEASE SAVEPOINT driver_app_action');}}
            @error_log(sprintf("[%s] Driver action/GPS failed for mission %d: %s\n",date('c'),(int)$request->param('id'),$e->getMessage()),3,BASE_PATH.'/storage/logs/app.log');
            return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException && !($e instanceof \PDOException)?$e->getMessage():'Action ou enregistrement GPS impossible.'],422);
        }
    }
    public function positions(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return $this->json(['success'=>false,'message'=>'Accès refusé.'],403);}$userId=(int)Auth::id();if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}try{$positions=$request->input('positions',[]);if(!is_array($positions)){throw new RuntimeException('Format de positions invalide.');}$result=GpsTracking::recordBatch((int)$request->param('id'),$positions,(string)$request->input('source','pwa'));return $this->json(['success'=>true,'data'=>$result]);}catch(Throwable $e){$errorId='GPS-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));@error_log(sprintf("[%s] %s sync failed user=%d mission=%d positions=%d: %s\n",date('c'),$errorId,$userId,(int)$request->param('id'),is_array($request->input('positions',[]))?count($request->input('positions',[])):0,$e->getMessage()),3,BASE_PATH.'/storage/logs/app.log');if($e instanceof RuntimeException){return $this->json(['success'=>false,'message'=>$e->getMessage()],422);}return $this->json(['success'=>false,'message'=>'Erreur serveur pendant l’enregistrement GPS. Référence '.$errorId.'.'],500);}
    }
    public function positionHistory(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return $this->json(['success'=>false,'message'=>'Accès refusé.'],403);}if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}try{return $this->json(['success'=>true,'data'=>GpsTracking::recentOwned((int)$request->param('id'))]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Historique GPS indisponible.'],422);}
    }
    private function baseUrl(): string{return rtrim((string)Env::get('APP_URL',''),'/');}
}
