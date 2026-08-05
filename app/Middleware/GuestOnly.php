<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

final class GuestOnly
{
    public function handle(Request $request): ?Response
    {
        return Auth::check() ? Response::redirect(rtrim((string) Env::get('APP_URL', ''), '/') . '/') : null;
    }
}

