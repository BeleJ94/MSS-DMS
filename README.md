# MSS-DMS

Socle du **Delivery Management System** en PHP MVC sans framework, conçu pour PHP 7.4 et MariaDB 10.4.

## Installation

1. Copier `.env.example` vers `.env` et adapter les accès MariaDB.
2. Créer la base : `CREATE DATABASE mss_dms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Exécuter `php scripts/migrate.php`.
4. Créer le premier administrateur : `php scripts/create_admin.php "Administrateur" admin@example.com "mot-de-passe-fort"`.
5. Pointer Apache vers `public/`, ou lancer `php -S 127.0.0.1:8000 -t public`.

## Structure

- `app/Core` : routeur, requête/réponse, PDO, vues et erreurs
- `app/Controllers` : contrôleurs HTTP
- `app/Views` : vues et layout
- `config` : configuration applicative
- `database/migrations` : migrations SQL versionnées
- `public` : contrôleur frontal et ressources publiques
- `routes` : déclaration des routes
- `storage` : journaux et cache

## Routes initiales

- `GET /` : écran d’accueil technique
- `GET /api/health` : santé de l’application et de MariaDB au format JSON
- `GET|POST /login` : connexion sécurisée
- `POST /logout` : déconnexion protégée par CSRF
- `GET|POST /users` : gestion des utilisateurs (permission `users.manage`)

Les sessions utilisent exclusivement un cookie HTTPOnly, SameSite=Lax et Secure sous HTTPS. Les mots de passe sont traités avec `password_hash()` / `password_verify()`. Les tentatives et déconnexions sont consignées dans `login_logs`.

Les bibliothèques frontend sont chargées dans des versions figées depuis CDN. Aucun module métier n’est encore implémenté.
