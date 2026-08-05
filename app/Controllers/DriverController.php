<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Env;
use App\Core\PhotoUpload;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Driver;
use Throwable;
use RuntimeException;

final class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        if(!Auth::can('drivers.view')){return $this->forbidden(false);}
        return $this->view('drivers/index',['title'=>'Chauffeurs','page'=>'drivers','canManage'=>Auth::can('drivers.manage'),'categories'=>Driver::categories(),'mobileUsers'=>Driver::mobileUsers()]);
    }
    public function show(Request $request): Response
    {
        if(!Auth::can('drivers.view')){return $this->forbidden(false);}$driver=Driver::find((int)$request->param('id'));if(!$driver){return new Response(View::render('errors/404',['title'=>'Chauffeur introuvable']),404);}return $this->view('drivers/show',['title'=>$driver['first_name'].' '.$driver['last_name'],'page'=>'drivers','driver'=>$driver,'canManage'=>Auth::can('drivers.manage'),'mobileUsers'=>Driver::mobileUsers()]);
    }
    public function data(Request $request): Response
    {
        if(!Auth::can('drivers.view')){return $this->forbidden(true);}return $this->json(['data'=>Driver::listing(['search'=>(string)$request->query('search',''),'status'=>(string)$request->query('status',''),'license_category'=>(string)$request->query('category',''),'active'=>(string)$request->query('active','1')])]);
    }
    public function photo(Request $request): Response
    {
        if(!Auth::can('drivers.view')){return $this->forbidden(true);}$photo=Driver::photo((int)$request->param('id'));if(!$photo){return new Response('',404);}return new Response($photo['photo_data'],200,['Content-Type'=>$photo['photo_mime'],'Cache-Control'=>'private, max-age=3600','X-Content-Type-Options'=>'nosniff']);
    }
    public function store(Request $request): Response {return $this->persist($request,null);}
    public function update(Request $request): Response {return $this->persist($request,(int)$request->param('id'));}
    public function deactivate(Request $request): Response
    {
        if(!Auth::can('drivers.manage')){return $this->forbidden(true);}$done=Driver::deactivate((int)$request->param('id'));return $this->json(['success'=>$done,'message'=>$done?'Chauffeur désactivé.':'Chauffeur déjà inactif ou introuvable.'],$done?200:422);
    }
    private function persist(Request $request,?int $id): Response
    {
        if(!Auth::can('drivers.manage')){return $this->forbidden(true);}$data=$this->payload($request);$errors=$this->validate($data,$request->file('photo'));if($errors!==[]){return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422);}$photo=null;
        try{$photo=PhotoUpload::store($request->file('photo'));if($id===null){$id=Driver::create($data,$photo);return $this->json(['success'=>true,'message'=>'Chauffeur créé avec succès.','id'=>$id,'redirect'=>$this->url('/drivers/'.$id)],201);}if(!Driver::update($id,$data,$photo)){return $this->json(['success'=>false,'message'=>'Chauffeur introuvable.'],404);}return $this->json(['success'=>true,'message'=>'Dossier chauffeur mis à jour.']);}catch(Throwable $e){$message=$e instanceof RuntimeException?$e->getMessage():'Enregistrement impossible. Vérifiez notamment l’unicité du permis.';if(!($e instanceof RuntimeException)&&filter_var(Env::get('APP_DEBUG',false),FILTER_VALIDATE_BOOLEAN)){$message.=' '.$e->getMessage();}return $this->json(['success'=>false,'message'=>$message],422);}
    }
    private function payload(Request $request): array {$raw=$request->input('payload');if(is_string($raw)){ $decoded=json_decode($raw,true);return is_array($decoded)?$decoded:[];}return $request->all();}
    private function validate(array $d,?array $photo): array
    {
        $e=[];if(mb_strlen(trim((string)($d['first_name']??'')))<2){$e['first_name']='Le prénom est obligatoire.';}if(mb_strlen(trim((string)($d['last_name']??'')))<2){$e['last_name']='Le nom est obligatoire.';}if(mb_strlen(trim((string)($d['phone']??'')))<7){$e['phone']='Numéro de téléphone invalide.';}if(trim((string)($d['license_number']??''))===''){$e['license_number']='Le numéro de permis est obligatoire.';}if(trim((string)($d['license_category']??''))===''){$e['license_category']='La catégorie est obligatoire.';}
        $expiry=(string)($d['license_expires_at']??'');$issued=(string)($d['license_issued_at']??'');$birth=(string)($d['date_of_birth']??'');if(!$this->validDate($expiry)){$e['license_expires_at']='Date d’expiration invalide.';}elseif($expiry<date('Y-m-d')){$e['license_expires_at']='Le permis est déjà expiré.';}if($issued!==''&&(!$this->validDate($issued)||$issued>$expiry)){$e['license_issued_at']='La date de délivrance doit précéder l’expiration.';}if($birth!==''&&(!$this->validDate($birth)||$birth>date('Y-m-d',strtotime('-18 years')))){$e['date_of_birth']='Le chauffeur doit avoir au moins 18 ans.';}if(($d['email']??'')!==''&&!filter_var($d['email'],FILTER_VALIDATE_EMAIL)){$e['email']='Adresse e-mail invalide.';}if(!in_array($d['status']??'Disponible',['Disponible','Affecté','En mission','Indisponible'],true)){$e['status']='Statut invalide.';}if($photo&&($photo['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK&&(int)($photo['size']??0)>3*1024*1024){$e['photo']='La photo ne doit pas dépasser 3 Mo.';}return $e;
    }
    private function validDate(string $date): bool {$parsed=\DateTime::createFromFormat('Y-m-d',$date);return $parsed&&$parsed->format('Y-m-d')===$date;}
    private function forbidden(bool $json): Response{return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
    private function url(string $path): string{return rtrim((string)Env::get('APP_URL',''),'/').$path;}
}
