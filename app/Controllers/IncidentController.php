<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\IncidentPhotoUpload;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Incident;
use RuntimeException;
use Throwable;

final class IncidentController extends Controller
{
    public function index(Request $request): Response{if(!Auth::can('incidents.view')){return $this->forbidden(false);}return $this->view('incidents/index',['title'=>'Incidents','page'=>'incidents','types'=>Incident::TYPES]);}
    public function data(Request $request): Response{if(!Auth::can('incidents.view')){return $this->forbidden(true);}return $this->json(['data'=>Incident::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'type'=>(string)$request->query('type','')])]);}
    public function show(Request $request): Response{if(!Auth::can('incidents.view')){return $this->forbidden(false);}$incident=Incident::find((int)$request->param('id'));if(!$incident){return new Response(View::render('errors/404',['title'=>'Incident introuvable']),404);}return $this->view('incidents/show',['title'=>$incident['incident_reference'],'page'=>'incidents','incident'=>$incident,'users'=>Incident::users(),'canManage'=>Auth::can('incidents.manage'),'canResolve'=>Auth::can('incidents.resolve')]);}
    public function report(Request $request): Response{if(!Auth::can('driver_app.access')){return $this->forbidden(true);}try{$photos=IncidentPhotoUpload::many($request->file('incident_photos'));$id=Incident::reportOwned((int)$request->param('id'),$request->all(),$photos);return $this->json(['success'=>true,'message'=>'Incident signalé au dispatching.','id'=>$id],201);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Impossible de signaler l’incident.'],422);}}
    public function update(Request $request): Response{if(!Auth::can('incidents.manage')){return $this->forbidden(true);}try{Incident::update((int)$request->param('id'),$request->all());return $this->json(['success'=>true,'message'=>'Traitement de l’incident mis à jour.']);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Mise à jour impossible.'],422);}}
    public function resolve(Request $request): Response{if(!Auth::can('incidents.resolve')){return $this->forbidden(true);}try{Incident::resolve((int)$request->param('id'),$request->all());return $this->json(['success'=>true,'message'=>'Incident résolu et workflow de livraison repris.']);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Résolution impossible.'],422);}}
    public function photo(Request $request): Response{if(!Auth::can('incidents.view')){return $this->forbidden(false);}$photo=Incident::photo((int)$request->param('id'),(int)$request->param('photoId'));if(!$photo){return new Response('',404);}return new Response((string)$photo['photo_data'],200,['Content-Type'=>$photo['photo_mime'],'Cache-Control'=>'private, max-age=600','X-Content-Type-Options'=>'nosniff']);}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
}
