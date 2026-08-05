<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DocumentUpload;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Vehicle;
use RuntimeException;
use Throwable;

final class FleetController extends Controller
{
    public function index(Request $request): Response{if(!Auth::can('fleet.view')){return $this->forbidden(false);}return $this->view('fleet/index',['title'=>'Flotte','page'=>'fleet','canManage'=>Auth::can('fleet.manage'),'types'=>Vehicle::types(),'drivers'=>Vehicle::availableDrivers()]);}
    public function show(Request $request): Response{if(!Auth::can('fleet.view')){return $this->forbidden(false);}$vehicle=Vehicle::find((int)$request->param('id'));if(!$vehicle){return new Response(View::render('errors/404',['title'=>'Véhicule introuvable']),404);}return $this->view('fleet/show',['title'=>$vehicle['registration_number'],'page'=>'fleet','vehicle'=>$vehicle,'canManage'=>Auth::can('fleet.manage'),'drivers'=>Vehicle::availableDrivers()]);}
    public function data(Request $request): Response{if(!Auth::can('fleet.view')){return $this->forbidden(true);}return $this->json(['data'=>Vehicle::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'type'=>(string)$request->query('type',''),'expiry'=>(string)$request->query('expiry',''),'active'=>(string)$request->query('active','1')])]);}
    public function store(Request $request): Response{return $this->persist($request,null);}
    public function update(Request $request): Response{return $this->persist($request,(int)$request->param('id'));}
    public function deactivate(Request $request): Response{if(!Auth::can('fleet.manage')){return $this->forbidden(true);}$done=Vehicle::deactivate((int)$request->param('id'));return $this->json(['success'=>$done,'message'=>$done?'Véhicule désactivé.':'Véhicule déjà inactif ou introuvable.'],$done?200:422);}
    public function addDocument(Request $request): Response
    {
        if(!Auth::can('fleet.manage')){return $this->forbidden(true);}$data=$request->all();$errors=$this->validateDocument($data);if($errors!==[]){return $this->json(['success'=>false,'message'=>'Vérifiez les informations du document.','errors'=>$errors],422);}try{$file=DocumentUpload::read($request->file('document'));$id=Vehicle::addDocument((int)$request->param('id'),$data,$file);return $this->json(['success'=>true,'message'=>'Document ajouté.','id'=>$id],201);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Impossible d’ajouter le document.'],422);}
    }
    public function downloadDocument(Request $request): Response
    {
        if(!Auth::can('fleet.view')){return $this->forbidden(true);}$document=Vehicle::document((int)$request->param('id'),(int)$request->param('documentId'));if(!$document){return new Response('',404);}$filename=str_replace('"','',(string)$document['file_name']);return new Response($document['file_data'],200,['Content-Type'=>$document['file_mime'],'Content-Disposition'=>'attachment; filename="'.$filename.'"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
    public function deleteDocument(Request $request): Response{if(!Auth::can('fleet.manage')){return $this->forbidden(true);}$done=Vehicle::deleteDocument((int)$request->param('id'),(int)$request->param('documentId'));return $this->json(['success'=>$done,'message'=>$done?'Document supprimé.':'Document introuvable.'],$done?200:404);}
    private function persist(Request $request,?int $id): Response
    {
        if(!Auth::can('fleet.manage')){return $this->forbidden(true);}$data=$request->all();$errors=$this->validateVehicle($data);if($errors!==[]){return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422);}try{if($id===null){$id=Vehicle::create($data);return $this->json(['success'=>true,'message'=>'Véhicule créé avec succès.','id'=>$id,'redirect'=>$this->url('/fleet/'.$id)],201);}if(!Vehicle::update($id,$data)){return $this->json(['success'=>false,'message'=>'Véhicule introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Fiche véhicule mise à jour.']);}catch(Throwable $e){$message='Enregistrement impossible. Vérifiez notamment l’immatriculation et le chauffeur affecté.';if(filter_var(Env::get('APP_DEBUG',false),FILTER_VALIDATE_BOOLEAN)){$message.=' '.$e->getMessage();}return $this->json(['success'=>false,'message'=>$message],422);}
    }
    private function validateVehicle(array $d): array
    {
        $e=[];foreach(['registration_number'=>'L’immatriculation est obligatoire.','brand'=>'La marque est obligatoire.','model'=>'Le modèle est obligatoire.','vehicle_type'=>'Le type est obligatoire.'] as $field=>$message){if(trim((string)($d[$field]??''))===''){$e[$field]=$message;}}if(!is_numeric($d['capacity_value']??null)||(float)$d['capacity_value']<=0){$e['capacity_value']='La capacité doit être supérieure à zéro.';}if(trim((string)($d['capacity_unit']??''))===''){$e['capacity_unit']='Précisez l’unité.';}$year=(int)($d['manufacture_year']??0);if($year!==0&&($year<1950||$year>(int)date('Y')+1)){$e['manufacture_year']='Année de fabrication invalide.';}if(($d['mileage_km']??'')!==''&&(!is_numeric($d['mileage_km'])||(float)$d['mileage_km']<0)){$e['mileage_km']='Kilométrage invalide.';}if(!in_array($d['status']??'Disponible',['Disponible','Affecté','En livraison','Maintenance','Indisponible'],true)){$e['status']='Statut invalide.';}if(in_array($d['status']??'', ['Affecté','En livraison'],true)&&(int)($d['assigned_driver_id']??0)<1){$e['assigned_driver_id']='Un chauffeur est requis pour ce statut.';}return $e;
    }
    private function validateDocument(array $d): array{$e=[];if(trim((string)($d['document_type']??''))===''){$e['document_type']='Le type est obligatoire.';}$issued=(string)($d['issued_at']??'');$expiry=(string)($d['expires_at']??'');if($issued!==''&&!$this->validDate($issued)){$e['issued_at']='Date invalide.';}if($expiry!==''&&!$this->validDate($expiry)){$e['expires_at']='Date invalide.';}if($issued!==''&&$expiry!==''&&$issued>$expiry){$e['expires_at']='L’expiration doit suivre la délivrance.';}return $e;}
    private function validDate(string $date): bool{$d=\DateTime::createFromFormat('Y-m-d',$date);return $d&&$d->format('Y-m-d')===$date;}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
    private function url(string $path): string{return rtrim((string)Env::get('APP_URL',''),'/').$path;}
}

