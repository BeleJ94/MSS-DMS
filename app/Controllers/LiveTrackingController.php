<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\LiveTracking;

final class LiveTrackingController extends Controller
{
    public function index(Request $request): Response{if(!Auth::can('tracking.view')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}return $this->view('live-tracking/index',['title'=>'Suivi en direct','page'=>'live-tracking']);}
    public function data(Request $request): Response{if(!Auth::can('tracking.view')){return $this->json(['success'=>false,'message'=>'Permission insuffisante.'],403);}$rows=LiveTracking::positions();return $this->json(['success'=>true,'data'=>$rows,'server_time'=>gmdate(DATE_ATOM),'refresh_after'=>15]);}
}
