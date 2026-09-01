<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Delivery;
use App\Models\DeliveryRouteHistory;
use RuntimeException;
use Throwable;

final class DeliveryController extends Controller
{
    public function index(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(false);}return $this->view('deliveries/index',$this->viewData(['title'=>'Livraisons','page'=>'deliveries','canManage'=>Auth::can('deliveries.manage')]));}
    public function create(Request $request): Response{if(!Auth::can('deliveries.manage')){return $this->forbidden(false);}return $this->view('deliveries/create',$this->viewData(['title'=>'Nouvelle livraison','page'=>'deliveries']));}
    public function show(Request $request): Response
    {
        if(!Auth::can('deliveries.view')){return $this->forbidden(false);}$delivery=Delivery::find((int)$request->param('id'));if(!$delivery){return new Response(View::render('errors/404',['title'=>'Livraison introuvable']),404);}$canViewRoute=Auth::can('tracking.history.view');$isAdministrator=Auth::hasRole('administrateur');$officeActions=array_values(array_filter(Delivery::allowedNext($delivery),function(string $status):bool{return in_array($status,['Annulée','Clôturée'],true);}));return $this->view('deliveries/show',$this->viewData(['title'=>$delivery['reference'],'page'=>'deliveries','delivery'=>$delivery,'allowedNext'=>$officeActions,'rollbackTargets'=>$isAdministrator?Delivery::rollbackTargets($delivery):[],'canManage'=>Auth::can('deliveries.manage'),'canDelete'=>$isAdministrator,'canTransition'=>Auth::can('deliveries.status'),'canRollback'=>$isAdministrator,'canViewRoute'=>$canViewRoute,'usesLeaflet'=>$canViewRoute]));
    }
    public function routeHistory(Request $request): Response{if(!Auth::can('tracking.history.view')){return $this->forbidden(true);}$history=DeliveryRouteHistory::forDelivery((int)$request->param('id'));if($history===null){return $this->json(['success'=>false,'message'=>'Livraison introuvable.'],404);}return $this->json(['success'=>true,'data'=>$history]);}
    public function data(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(true);}return $this->json(['data'=>Delivery::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'priority'=>(string)$request->query('priority',''),'client_id'=>(string)$request->query('client_id',''),'date_from'=>(string)$request->query('date_from',''),'date_to'=>(string)$request->query('date_to','')])]);}
    public function clientOptions(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(true);}return $this->json(['success'=>true,'data'=>Delivery::clientOptions((int)$request->param('id'))]);}
    public function store(Request $request): Response{return $this->persist($request,null);}
    public function update(Request $request): Response{return $this->persist($request,(int)$request->param('id'));}
    public function delete(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return $this->forbidden(true);}try{if(!Delivery::delete((int)$request->param('id'))){return $this->json(['success'=>false,'message'=>'Livraison introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Livraison supprimée définitivement.','redirect'=>$this->url('/deliveries')]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Suppression impossible.'],422);}
    }
    public function transition(Request $request): Response
    {
        $target=trim((string)$request->input('status',''));$comment=trim((string)$request->input('comment',''));$correction=(string)$request->input('mode','')==='rollback';if($correction){if(!Auth::hasRole('administrateur')){return $this->forbidden(true);}try{$change=Delivery::rollbackStatus((int)$request->param('id'),$target,$comment);return $this->json(['success'=>true,'message'=>'Livraison ramenée au statut '.$target.'.','change'=>$change]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Correction impossible.'],422);}}if(!Auth::can('deliveries.status')){return $this->forbidden(true);}if(!in_array($target,['Annulée','Clôturée'],true)){return $this->json(['success'=>false,'message'=>'La progression opérationnelle est réservée au chauffeur affecté.'],403);}try{$change=Delivery::transition((int)$request->param('id'),$target,$comment?:null);return $this->json(['success'=>true,'message'=>'Statut mis à jour : '.$target.'.','change'=>$change]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Transition impossible.'],422);}
    }
    private function persist(Request $request,?int $id): Response
    {
        if(!Auth::can('deliveries.manage')){return $this->forbidden(true);}$data=$request->all();$errors=$this->validate($data);if($errors!==[]){return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422);}try{if($id===null){$id=Delivery::create($data);$assigned=!empty($data['driver_id'])&&!empty($data['vehicle_id']);return $this->json(['success'=>true,'message'=>$assigned?'Livraison créée et transmise au chauffeur.':'Livraison créée en brouillon.','id'=>$id,'redirect'=>$this->url('/deliveries/'.$id)],201);}if(!Delivery::update($id,$data)){return $this->json(['success'=>false,'message'=>'Livraison introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Livraison mise à jour.']);}catch(Throwable $e){$message=$e instanceof RuntimeException?$e->getMessage():'Enregistrement impossible.';if(!($e instanceof RuntimeException)&&filter_var(Env::get('APP_DEBUG',false),FILTER_VALIDATE_BOOLEAN)){$message.=' '.$e->getMessage();}return $this->json(['success'=>false,'message'=>$message],422);}
    }
    private function validate(array $d): array
    {
        $e=[];foreach(['client_id'=>'Sélectionnez un client.','scheduled_at'=>'La date prévue est obligatoire.'] as $field=>$message){if(trim((string)($d[$field]??''))===''){$e[$field]=$message;}}$scheduled=str_replace('T',' ',(string)($d['scheduled_at']??''));$date=\DateTime::createFromFormat('Y-m-d H:i',$scheduled);if(($d['scheduled_at']??'')!==''&&(!$date||$date->format('Y-m-d H:i')!==$scheduled)){$e['scheduled_at']='Date prévue invalide.';}if(!in_array($d['priority']??'Normale',['Basse','Normale','Haute','Urgente'],true)){$e['priority']='Priorité invalide.';}$destinations=$d['destinations']??[];if(!is_array($destinations)||$destinations===[]){$e['destinations']='Ajoutez au moins une destination.';}else{foreach($destinations as $row){$label=trim((string)($row['label']??''));$address=trim((string)($row['address']??''));$lat=trim((string)($row['latitude']??''));$lng=trim((string)($row['longitude']??''));if(mb_strlen($label)<2||mb_strlen($label)>160||mb_strlen($address)<3||mb_strlen($address)>255||($lat!==''&&(!is_numeric($lat)||(float)$lat < -90||(float)$lat > 90))||($lng!==''&&(!is_numeric($lng)||(float)$lng < -180||(float)$lng > 180))){$e['destinations']='Chaque destination doit avoir un nom et une adresse valides.';break;}}}$goods=$d['goods']??[];$goodsPerDestination=is_array($destinations)?array_fill(0,count($destinations),0):[];if(!is_array($goods)||$goods===[]){$e['goods']='Ajoutez au moins une marchandise à chaque destination.';}else{foreach($goods as $row){$description=trim((string)($row['description']??''));$unit=trim((string)($row['unit']??''));$weight=$row['unit_weight_kg']??'';$destinationIndex=$row['destination_index']??null;if(!is_numeric($destinationIndex)||!isset($destinations[(int)$destinationIndex])||mb_strlen($description)<2||mb_strlen($description)>255||!is_numeric($row['quantity']??null)||(float)$row['quantity']<=0||$unit===''||mb_strlen($unit)>30||($weight!==''&&(!is_numeric($weight)||(float)$weight<=0))){$e['goods']='Chaque ligne doit contenir une désignation, une quantité et une unité valides.';break;}$goodsPerDestination[(int)$destinationIndex]++;}if(!isset($e['goods'])){foreach($goodsPerDestination as $index=>$count){if($count===0){$e['goods']='La destination '.($index+1).' doit contenir au moins une marchandise.';break;}}}}return $e;
    }
    private function viewData(array $data): array{return $data+['clients'=>Delivery::clients(),'goodsCatalog'=>Delivery::goodsCatalog(),'drivers'=>Delivery::drivers(),'vehicles'=>Delivery::vehicles(),'statuses'=>array_merge(Delivery::FLOW,Delivery::EXCEPTIONS)];}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
    private function url(string $path): string{return rtrim((string)Env::get('APP_URL',''),'/').$path;}
}
