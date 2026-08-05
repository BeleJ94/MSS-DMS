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
    public function show(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(false);}$delivery=Delivery::find((int)$request->param('id'));if(!$delivery){return new Response(View::render('errors/404',['title'=>'Livraison introuvable']),404);}$canViewRoute=Auth::can('tracking.history.view');return $this->view('deliveries/show',$this->viewData(['title'=>$delivery['reference'],'page'=>'deliveries','delivery'=>$delivery,'allowedNext'=>Delivery::allowedNext($delivery),'canManage'=>Auth::can('deliveries.manage'),'canTransition'=>Auth::can('deliveries.status'),'canViewRoute'=>$canViewRoute,'usesLeaflet'=>$canViewRoute]));}
    public function routeHistory(Request $request): Response{if(!Auth::can('tracking.history.view')){return $this->forbidden(true);}$history=DeliveryRouteHistory::forDelivery((int)$request->param('id'));if($history===null){return $this->json(['success'=>false,'message'=>'Livraison introuvable.'],404);}return $this->json(['success'=>true,'data'=>$history]);}
    public function data(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(true);}return $this->json(['data'=>Delivery::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'priority'=>(string)$request->query('priority',''),'client_id'=>(string)$request->query('client_id',''),'date_from'=>(string)$request->query('date_from',''),'date_to'=>(string)$request->query('date_to','')])]);}
    public function clientOptions(Request $request): Response{if(!Auth::can('deliveries.view')){return $this->forbidden(true);}return $this->json(['success'=>true,'data'=>Delivery::clientOptions((int)$request->param('id'))]);}
    public function store(Request $request): Response{return $this->persist($request,null);}
    public function update(Request $request): Response{return $this->persist($request,(int)$request->param('id'));}
    public function transition(Request $request): Response
    {
        if(!Auth::can('deliveries.status')){return $this->forbidden(true);}$target=trim((string)$request->input('status',''));$comment=trim((string)$request->input('comment',''));if(!in_array($target,array_merge(Delivery::FLOW,Delivery::EXCEPTIONS),true)){return $this->json(['success'=>false,'message'=>'Statut invalide.'],422);}try{$change=Delivery::transition((int)$request->param('id'),$target,$comment?:null);return $this->json(['success'=>true,'message'=>'Statut mis à jour : '.$target.'.','change'=>$change]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Transition impossible.'],422);}
    }
    private function persist(Request $request,?int $id): Response
    {
        if(!Auth::can('deliveries.manage')){return $this->forbidden(true);}$data=$request->all();$errors=$this->validate($data);if($errors!==[]){return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422);}try{if($id===null){$id=Delivery::create($data);return $this->json(['success'=>true,'message'=>'Livraison créée en brouillon.','id'=>$id,'redirect'=>$this->url('/deliveries/'.$id)],201);}if(!Delivery::update($id,$data)){return $this->json(['success'=>false,'message'=>'Livraison introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Livraison mise à jour.']);}catch(Throwable $e){$message=$e instanceof RuntimeException?$e->getMessage():'Enregistrement impossible.';if(!($e instanceof RuntimeException)&&filter_var(Env::get('APP_DEBUG',false),FILTER_VALIDATE_BOOLEAN)){$message.=' '.$e->getMessage();}return $this->json(['success'=>false,'message'=>$message],422);}
    }
    private function validate(array $d): array
    {
        $e=[];foreach(['client_id'=>'Sélectionnez un client.','client_site_id'=>'Sélectionnez un site de livraison.','scheduled_at'=>'La date prévue est obligatoire.'] as $field=>$message){if(trim((string)($d[$field]??''))===''){$e[$field]=$message;}}$scheduled=str_replace('T',' ',(string)($d['scheduled_at']??''));$date=\DateTime::createFromFormat('Y-m-d H:i',$scheduled);if(($d['scheduled_at']??'')!==''&&(!$date||$date->format('Y-m-d H:i')!==$scheduled)){$e['scheduled_at']='Date prévue invalide.';}if(!in_array($d['priority']??'Normale',['Basse','Normale','Haute','Urgente'],true)){$e['priority']='Priorité invalide.';}$goods=$d['goods']??[];if(!is_array($goods)||$goods===[]){$e['goods']='Ajoutez au moins une marchandise.';}else{$seen=[];foreach($goods as $index=>$row){$goodsId=(int)($row['goods_id']??0);if($goodsId<1||!is_numeric($row['quantity']??null)||(float)$row['quantity']<=0||isset($seen[$goodsId])){$e['goods']='Chaque marchandise doit être unique avec une quantité positive.';break;}$seen[$goodsId]=true;}}return $e;
    }
    private function viewData(array $data): array{return $data+['clients'=>Delivery::clients(),'goodsCatalog'=>Delivery::goodsCatalog(),'drivers'=>Delivery::drivers(),'vehicles'=>Delivery::vehicles(),'statuses'=>array_merge(Delivery::FLOW,Delivery::EXCEPTIONS)];}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
    private function url(string $path): string{return rtrim((string)Env::get('APP_URL',''),'/').$path;}
}
