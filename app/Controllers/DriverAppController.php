<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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
        if(!Auth::can('driver_app.access')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}$mission=DriverMission::findOwned((int)$request->param('id'));if(!$mission){return new Response(View::render('driver-app/404',['title'=>'Mission introuvable','baseUrl'=>$this->baseUrl(),'driver'=>DriverMission::driver(),'activeMissionId'=>GpsTracking::activeMissionId()],'layouts/driver-app'),404);}return $this->view('driver-app/show',['title'=>$mission['reference'],'mission'=>$mission,'pod'=>DeliveryPod::summary((int)$mission['id']),'driver'=>DriverMission::driver(),'activeMissionId'=>in_array($mission['status'],GpsTracking::ACTIVE_STATUSES,true)?(int)$mission['id']:GpsTracking::activeMissionId(),'baseUrl'=>$this->baseUrl()],'layouts/driver-app');
    }
    public function action(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return $this->json(['success'=>false,'message'=>'Accès refusé.'],403);}$action=trim((string)$request->input('action',''));try{$message=DriverMission::perform((int)$request->param('id'),$action,(string)$request->input('description',''));return $this->json(['success'=>true,'message'=>$message]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Action impossible.'],422);}
    }
    public function positions(Request $request): Response
    {
        if(!Auth::can('driver_app.access')){return $this->json(['success'=>false,'message'=>'Accès refusé.'],403);}try{$positions=$request->input('positions',[]);if(!is_array($positions)){throw new RuntimeException('Format de positions invalide.');}$result=GpsTracking::recordBatch((int)$request->param('id'),$positions,(string)$request->input('source','pwa'));return $this->json(['success'=>true,'data'=>$result]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Enregistrement GPS impossible.'],422);}
    }
    private function baseUrl(): string{return rtrim((string)Env::get('APP_URL',''),'/');}
}
