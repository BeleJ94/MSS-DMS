<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Goods;
use Throwable;

final class GoodsController extends Controller
{
    public function index(Request $request): Response{if(!Auth::can('goods.view')){return $this->forbidden(false);}return $this->view('goods/index',['title'=>'Marchandises','page'=>'goods','canManage'=>Auth::can('goods.manage'),'units'=>Goods::units()]);}
    public function data(Request $request): Response{if(!Auth::can('goods.view')){return $this->forbidden(true);}return $this->json(['data'=>Goods::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'unit'=>(string)$request->query('unit','')])]);}
    public function detail(Request $request): Response{if(!Auth::can('goods.view')){return $this->forbidden(true);}$goods=Goods::find((int)$request->param('id'));return $goods?$this->json(['success'=>true,'data'=>$goods]):$this->json(['success'=>false,'message'=>'Marchandise introuvable.'],404);}
    public function store(Request $request): Response{return $this->persist($request,null);}
    public function update(Request $request): Response{return $this->persist($request,(int)$request->param('id'));}
    public function archive(Request $request): Response{if(!Auth::can('goods.manage')){return $this->forbidden(true);}$done=Goods::archive((int)$request->param('id'));return $this->json(['success'=>$done,'message'=>$done?'Marchandise archivée.':'Marchandise déjà archivée ou introuvable.'],$done?200:422);}
    private function persist(Request $request,?int $id): Response{if(!Auth::can('goods.manage')){return $this->forbidden(true);}$data=$request->all();$errors=$this->validate($data);if($errors!==[]){return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422);}try{if($id===null){$id=Goods::create($data);return $this->json(['success'=>true,'message'=>'Marchandise créée.','id'=>$id],201);}if(!Goods::update($id,$data)){return $this->json(['success'=>false,'message'=>'Marchandise introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Marchandise mise à jour.']);}catch(Throwable $e){$message='Enregistrement impossible. La référence doit être unique.';if(filter_var(Env::get('APP_DEBUG',false),FILTER_VALIDATE_BOOLEAN)){$message.=' '.$e->getMessage();}return $this->json(['success'=>false,'message'=>$message],422);}}
    private function validate(array $d): array{$e=[];if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{1,59}$/',trim((string)($d['reference']??'')))){$e['reference']='Référence invalide (2 à 60 caractères).';}if(mb_strlen(trim((string)($d['designation']??'')))<2){$e['designation']='La désignation est obligatoire.';}if(trim((string)($d['unit']??''))===''){$e['unit']='L’unité est obligatoire.';}if(($d['unit_weight_kg']??'')!==''&&(!is_numeric($d['unit_weight_kg'])||(float)$d['unit_weight_kg']<=0)){$e['unit_weight_kg']='Le poids doit être supérieur à zéro.';}if(!in_array($d['status']??'Actif',['Actif','Inactif','Archivé'],true)){$e['status']='Statut invalide.';}return $e;}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
}
