<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use Throwable;
use App\Models\Dashboard;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        if(Auth::hasRole('chauffeur')){return Response::redirect(rtrim((string)Env::get('APP_URL',''),'/').'/driver-app');}
        if(!Auth::can('dashboard.view')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403);}
        return $this->view('home/index', ['title'=>'Tableau de bord','page'=>'dashboard']+Dashboard::data());
    }

    public function health(Request $request): Response
    {
        if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}
        $database = 'unavailable';
        try {
            Database::connection()->query('SELECT 1');
            $database = 'ok';
        } catch (Throwable $exception) {
            // L'application reste observable même avant la configuration de la base.
        }

        return $this->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'application' => 'MSS-DMS',
            'database' => $database,
            'timestamp' => date(DATE_ATOM),
        ], $database === 'ok' ? 200 : 503);
    }
}
