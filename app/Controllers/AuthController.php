<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\LoginLogger;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', [
            'title' => 'Connexion',
            'error' => Session::pull('error'),
            'oldEmail' => Session::pull('old_email', ''),
        ], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return $this->failedLogin($email, 'Renseignez une adresse e-mail et un mot de passe valides.');
        }

        if (LoginThrottle::isBlocked($email)) {
            LoginLogger::write(null, $email, false, 'blocked');
            return $this->failedLogin($email, 'Trop de tentatives. Patientez 15 minutes avant de réessayer.');
        }

        if (!Auth::attempt($email, $password)) {
            LoginLogger::write(null, $email, false);
            return $this->failedLogin($email, 'Identifiants incorrects ou compte désactivé.');
        }

        $user = Auth::user();
        LoginLogger::write(Auth::id(), $email, true);
        $statement = Database::connection()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => Auth::id()]);
        Session::flash('success', 'Bienvenue, ' . ($user['name'] ?? '') . '.');

        return Response::redirect($this->url(Auth::hasRole('chauffeur')?'/driver-app':'/'));
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        if ($user !== null) {
            LoginLogger::write(Auth::id(), (string) $user['email'], true, 'logout');
        }
        Auth::logout();
        return Response::redirect($this->url('/login'));
    }

    private function failedLogin(string $email, string $message): Response
    {
        Session::flash('error', $message);
        Session::flash('old_email', $email);
        return Response::redirect($this->url('/login'));
    }

    private function url(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . $path;
    }
}
