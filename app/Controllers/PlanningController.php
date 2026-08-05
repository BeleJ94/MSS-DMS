<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Core\Request;use App\Core\Response;use App\Core\View;use App\Models\Planning;use RuntimeException;use Throwable;
final class PlanningController extends Controller
{
 public function index(Request $r):Response{if(!Auth::can('planning.view'))return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);return $this->view('planning/index',['title'=>'Planning','page'=>'planning','canManage'=>Auth::can('planning.manage'),'options'=>Planning::filterOptions()]);}
 public function data(Request $r):Response{if(!Auth::can('planning.view'))return $this->json(['success'=>false,'message'=>'Permission insuffisante.'],403);try{return $this->json(['success'=>true,'data'=>Planning::entries($r->all())]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Chargement impossible.'],422);}}
 public function resources(Request $r):Response{if(!Auth::can('planning.view'))return $this->json(['success'=>false,'message'=>'Permission insuffisante.'],403);try{return $this->json(['success'=>true,'data'=>Planning::resources((int)$r->param('id'),(string)$r->query('scheduled_at',''),(int)$r->query('duration',120))]);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Ressources indisponibles.'],422);}}
 public function update(Request $r):Response{if(!Auth::can('planning.manage'))return $this->json(['success'=>false,'message'=>'Permission insuffisante.'],403);$d=(int)$r->input('driver_id',0);$v=(int)$r->input('vehicle_id',0);try{Planning::update((int)$r->param('id'),(string)$r->input('scheduled_at',''),(int)$r->input('duration',120),$d>0?$d:null,$v>0?$v:null,(string)$r->input('comment',''));return $this->json(['success'=>true,'message'=>'Planification enregistrée et journalisée.']);}catch(Throwable $e){return $this->json(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Planification impossible.','conflict'=>true],422);}}
}
