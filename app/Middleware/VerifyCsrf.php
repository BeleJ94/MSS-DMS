<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class VerifyCsrf
{
    public function handle(Request $request): ?Response
    {
        $token = $request->input('_token');
        if (Csrf::validate(is_string($token) ? $token : null)) {
            return null;
        }
        if (strpos($request->path(), '/api/') === 0) {
            return Response::json(['success' => false, 'message' => 'Jeton CSRF absent ou invalide. Rechargez la page.'], 419);
        }
        return new Response(View::render('errors/419', ['title' => 'Session expirée']), 419);
    }
}
