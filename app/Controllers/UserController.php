<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDOException;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Auth::can('users.manage')) {
            return new Response(View::render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        $users = Database::connection()->query(
            'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.created_at, MIN(r.id) AS role_id, GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ", ") AS roles
             FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id ORDER BY u.created_at DESC'
        )->fetchAll();
        $roles = Database::connection()->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
        return $this->view('users/index', ['title' => 'Utilisateurs', 'page' => 'users', 'users' => $users, 'roles' => $roles]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::can('users.manage')) {
            return new Response(View::render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $roleId = (int) $request->input('role_id', 0);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || $roleId < 1) {
            Session::flash('error', 'Vérifiez les champs. Le mot de passe doit contenir au moins 10 caractères.');
            return Response::redirect($this->url('/users'));
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password)');
            $statement->execute(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $role = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE id = :role_id');
            $role->execute(['user_id' => $userId, 'role_id' => $roleId]);
            if ($role->rowCount() !== 1) {
                throw new PDOException('Rôle invalide.');
            }
            $pdo->commit();
            Session::flash('success', 'Utilisateur créé avec succès.');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            Session::flash('error', strpos($exception->getMessage(), 'Duplicate') !== false ? 'Cette adresse e-mail est déjà utilisée.' : 'Impossible de créer cet utilisateur.');
        }
        return Response::redirect($this->url('/users'));
    }

    public function toggle(Request $request): Response
    {
        if (!Auth::can('users.manage')) {
            return new Response(View::render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        $id = (int) $request->input('user_id', 0);
        if ($id === Auth::id()) {
            Session::flash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        } else {
            $statement = Database::connection()->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
            $statement->execute(['id' => $id]);
            Session::flash('success', 'Statut du compte mis à jour.');
        }
        return Response::redirect($this->url('/users'));
    }

    public function update(Request $request): Response
    {
        if (!Auth::can('users.manage')) {
            return new Response(View::render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        $id = (int) $request->param('id', 0);
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $roleId = (int) $request->input('role_id', 0);
        if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleId < 1 || ($password !== '' && strlen($password) < 10)) {
            Session::flash('error', 'Vérifiez les champs. Le nouveau mot de passe doit contenir au moins 10 caractères.');
            return Response::redirect($this->url('/users'));
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) { throw new PDOException('Utilisateur invalide.'); }
            $role = $pdo->prepare('SELECT id FROM roles WHERE id = :id');
            $role->execute(['id' => $roleId]);
            if (!$role->fetchColumn()) { throw new PDOException('Rôle invalide.'); }
            $currentAdmin = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:user AND r.slug='administrateur'");
            $currentAdmin->execute(['user' => $id]);
            $targetAdmin = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id=:role AND slug='administrateur'");
            $targetAdmin->execute(['role' => $roleId]);
            if ((int)$currentAdmin->fetchColumn() > 0 && (int)$targetAdmin->fetchColumn() === 0) {
                $otherAdmins = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='administrateur' AND u.is_active=1 AND u.id<>:user");
                $otherAdmins->execute(['user' => $id]);
                if ((int)$otherAdmins->fetchColumn() === 0) { throw new PDOException('Dernier administrateur.'); }
            }
            $sql = 'UPDATE users SET name = :name, email = :email';
            $params = ['name' => $name, 'email' => $email, 'id' => $id];
            if ($password !== '') {
                $sql .= ', password_hash = :password';
                $params['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $pdo->prepare($sql . ' WHERE id = :id')->execute($params);
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $id]);
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')->execute(['user_id' => $id, 'role_id' => $roleId]);
            $pdo->commit();
            Session::flash('success', 'Utilisateur modifié avec succès.');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (strpos($exception->getMessage(), 'Duplicate') !== false) {
                Session::flash('error', 'Cette adresse e-mail est déjà utilisée.');
            } elseif (strpos($exception->getMessage(), 'Dernier administrateur') !== false) {
                Session::flash('error', 'Le rôle du dernier administrateur actif ne peut pas être retiré.');
            } else {
                Session::flash('error', 'Impossible de modifier cet utilisateur.');
            }
        }
        return Response::redirect($this->url('/users'));
    }

    private function url(string $path): string { return rtrim((string) Env::get('APP_URL', ''), '/') . $path; }
}
