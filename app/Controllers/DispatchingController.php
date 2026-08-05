<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Dispatching;
use RuntimeException;
use Throwable;

final class DispatchingController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Auth::can('dispatching.view')) {return $this->forbidden(false);}
        return $this->view('dispatching/index', ['title'=>'Dispatching','page'=>'dispatching','canManage'=>Auth::can('dispatching.manage')]);
    }

    public function data(Request $request): Response
    {
        if (!Auth::can('dispatching.view')) {return $this->forbidden(true);}
        return $this->json(['data'=>Dispatching::board(['search'=>(string)$request->query('search',''),'priority'=>(string)$request->query('priority',''),'assignment'=>(string)$request->query('assignment',''),'date'=>(string)$request->query('date','')])]);
    }

    public function resources(Request $request): Response
    {
        if (!Auth::can('dispatching.view')) {return $this->forbidden(true);}
        try {return $this->json(['success'=>true,'data'=>Dispatching::resources((int)$request->param('id'))]);}
        catch (Throwable $e) {return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Chargement impossible.'],422);}
    }

    public function assign(Request $request): Response
    {
        if (!Auth::can('dispatching.manage')) {return $this->forbidden(true);}
        $driverId=(int)$request->input('driver_id',0);$vehicleId=(int)$request->input('vehicle_id',0);
        if ($driverId<1||$vehicleId<1) {return $this->json(['success'=>false,'message'=>'Sélectionnez un chauffeur et un véhicule.'],422);}
        try {$assignment=Dispatching::assign((int)$request->param('id'),$driverId,$vehicleId);return $this->json(['success'=>true,'message'=>'Affectation enregistrée.','assignment'=>$assignment]);}
        catch (Throwable $e) {return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Affectation impossible.','conflict'=>true],422);}
    }

    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
}
