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
        Session::flash('error', 'Veuillez vous connecter pour continuer.');
        return Response::redirect(rtrim((string) Env::get('APP_URL', ''), '/') . '/login');
    }
}

