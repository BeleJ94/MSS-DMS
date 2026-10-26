<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class Authenticate
{
    public function handle(Request $request): ?Response
    {
        if (Auth::check() && Auth::user() !== null) {
            return null;
        }
        if (strpos($request->path(),'/api/')===0 || (($request->path() === '/settings/mail' || strpos($request->path(),'/settings/mail/')===0) && stripos((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')!==false)) {
            return Response::json(['success'=>false,'message'=>'Votre session a expiré. Reconnectez-vous avec un compte administrateur avant de recommencer.'],401);
        }
        Session::flash('error', 'Veuillez vous connecter pour continuer.');
        return Response::redirect(rtrim((string) Env::get('APP_URL', ''), '/') . '/login');
    }
}

