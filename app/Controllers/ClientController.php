<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Client;
use Throwable;

final class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Auth::can('clients.view')) { return $this->forbidden(false); }
        return $this->view('clients/index', ['title'=>'Clients','page'=>'clients','canManage'=>Auth::can('clients.manage'),'cities'=>Client::cities()]);
    }

    public function show(Request $request): Response
    {
        if (!Auth::can('clients.view')) { return $this->forbidden(false); }
        $client = Client::find((int)$request->param('id'));
        if ($client === null) { return new Response(View::render('errors/404',['title'=>'Client introuvable']),404); }
        return $this->view('clients/show',['title'=>$client['company_name'],'page'=>'clients','client'=>$client,'canManage'=>Auth::can('clients.manage')]);
    }

    public function data(Request $request): Response
    {
        if (!Auth::can('clients.view')) { return $this->forbidden(true); }
        $rows = Client::dataTable(['status'=>(string)$request->query('status',''),'city'=>(string)$request->query('city',''),'search'=>(string)$request->query('search','')]);
        return $this->json(['data'=>$rows]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::can('clients.manage')) { return $this->forbidden(true); }
        $data = $request->all();
        $errors = $this->validate($data);
        if ($errors !== []) { return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422); }
        try {
            $id = Client::create($data);
            return $this->json(['success'=>true,'message'=>'Client créé avec succès.','id'=>$id,'redirect'=>$this->url('/clients/'.$id)],201);
        } catch (Throwable $exception) {
            return $this->json(['success'=>false,'message'=>'Impossible de créer le client. Vérifiez que ses informations sont uniques.'],422);
        }
    }

    public function update(Request $request): Response
    {
        if (!Auth::can('clients.manage')) { return $this->forbidden(true); }
        $data = $request->all();
        $errors = $this->validate($data);
        if ($errors !== []) { return $this->json(['success'=>false,'message'=>'Veuillez corriger les champs signalés.','errors'=>$errors],422); }
        try {
            if (!Client::update((int)$request->param('id'),$data)) { return $this->json(['success'=>false,'message'=>'Client introuvable.'],404); }
            return $this->json(['success'=>true,'message'=>'Client mis à jour.']);
        } catch (Throwable $exception) {
            return $this->json(['success'=>false,'message'=>'La mise à jour a échoué.'],422);
        }
    }

    public function archive(Request $request): Response
    {
        if (!Auth::can('clients.manage')) { return $this->forbidden(true); }
        $changed = Client::archive((int)$request->param('id'));
        return $this->json(['success'=>$changed,'message'=>$changed?'Client archivé.':'Ce client est déjà archivé ou introuvable.'],$changed?200:422);
    }

    private function validate(array $data): array
    {
        $errors=[];
        if (mb_strlen(trim((string)($data['company_name']??'')))<2) { $errors['company_name']='La raison sociale est obligatoire.'; }
        if (($data['email']??'')!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) { $errors['email']='Adresse e-mail invalide.'; }
        if (!in_array($data['status']??'active',['active','inactive','prospect','archived'],true)) { $errors['status']='Statut invalide.'; }
        foreach (['latitude'=>[-90,90],'longitude'=>[-180,180]] as $field=>$range) { if (($data[$field]??'')!=='' && (!is_numeric($data[$field]) || (float)$data[$field]<$range[0] || (float)$data[$field]>$range[1])) { $errors[$field]='Coordonnée GPS invalide.'; } }
        foreach ($data['contacts']??[] as $index=>$contact) { if (($contact['email']??'')!==''&&!filter_var($contact['email'],FILTER_VALIDATE_EMAIL)) { $errors['contacts.'.$index.'.email']='E-mail du contact invalide.'; } }
        foreach ($data['sites']??[] as $index=>$site) {
            if (trim((string)($site['name']??''))==='' || trim((string)($site['address_line1']??''))==='' || trim((string)($site['city']??''))==='') {$errors['sites']='Chaque site doit contenir un nom, une adresse et une ville.';break;}
            foreach (['latitude'=>[-90,90],'longitude'=>[-180,180]] as $field=>$range) {if (($site[$field]??'')!==''&&(!is_numeric($site[$field])||(float)$site[$field]<$range[0]||(float)$site[$field]>$range[1])) {$errors['sites']='Les coordonnées GPS d’un site sont invalides.';break 2;}}
        }
        foreach ($data['recipients']??[] as $index=>$recipient) { if (($recipient['email']??'')!==''&&!filter_var($recipient['email'],FILTER_VALIDATE_EMAIL)) { $errors['recipients.'.$index.'.email']='E-mail du destinataire invalide.'; } }
        return $errors;
    }

    private function forbidden(bool $json): Response { return $json?$this->json(['success'=>false,'message'=>'Permission insuffisante.'],403):new Response(View::render('errors/403',['title'=>'Accès refusé']),403); }
    private function url(string $path): string { return rtrim((string)\App\Core\Env::get('APP_URL',''),'/').$path; }
}
