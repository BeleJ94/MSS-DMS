<?php

declare(strict_types=1);
namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

final class MobileAuthenticate
{
    public function handle(Request $request): ?Response
    {
        if (!Auth::user()) {
            return Response::json(['success'=>false,'message'=>'Session expirée. Reconnectez-vous.'],401);
        }
        if (!Auth::can('driver_app.access') || !\App\Models\DriverMission::driver()) {
            return Response::json(['success'=>false,'message'=>'Ce compte ne possède pas de fiche chauffeur active.'],403);
        }
        return null;
    }
}
