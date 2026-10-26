# MSS-DMS

Socle du **Delivery Management System** en PHP MVC sans framework, conçu pour PHP 7.4 et MariaDB 10.4.

## Installation

1. Copier `.env.example` vers `.env` et adapter les accès MariaDB.
2. Créer la base : `CREATE DATABASE mss_dms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Exécuter `php scripts/migrate.php`.
4. Créer le premier administrateur : `php scripts/create_admin.php "Administrateur" admin@example.com "mot-de-passe-fort"`.
5. Pointer Apache vers `public/`, ou lancer `php -S 127.0.0.1:8000 -t public`.

## Jeu de démonstration local 2026

`php scripts/seed_demo_2026.php` prépare un jeu fictif depuis le 01/01/2026 jusqu’au jour courant, avec sept jours de planning à venir, puis annule les insertions pour vérifier leur cohérence. `php scripts/seed_demo_2026.php --apply` sauvegarde les données locales existantes et ajoute le jeu dans une transaction. Le script refuse une base distante et une seconde génération du même jeu. Les données portent le préfixe `DEMO` ou la mention `[DEMO]` ; aucune donnée préexistante n’est remplacée. Les e-mails des contacts fictifs sont désactivés et aucune notification en attente d’envoi n’est créée. Le manifeste et les identifiants des comptes fictifs sont enregistrés dans `storage/cache/demo-2026-manifest.json` (non versionné). La sauvegarde JSON compressée contient le schéma et les valeurs encodées en base64. `php scripts/verify_demo_2026.php` compare les lignes préexistantes à cette sauvegarde et contrôle les modèles opérationnels, les preuves PDF et les données de test des notifications.

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

## Notifications client

Chaque site de la fiche client dispose d’un champ **E-mails de notification de cette destination**, dans l’onglet **Sites de livraison**. Il accepte jusqu’à 20 adresses, séparées par virgules, points-virgules ou retours à la ligne ; les doublons sont éliminés. Dans une livraison, sélectionner le **Site enregistré du client** reprend ses coordonnées et le relie à sa liste de notification. Les prochaines notifications utilisent la liste à jour du site. Les destinations libres peuvent également recevoir une liste propre. Au départ, les adresses des sites de la tournée sont regroupées ; à l’arrivée et à la livraison, seules celles de la destination concernée sont ajoutées aux contacts généraux abonnés. Les messages déjà mis en file conservent leurs destinataires. Les sites et contacts conservent leurs identifiants lors des modifications ; retirer un site utilisé le rend inactif pour préserver les historiques. La migration `2026_10_23_000029_destination_notification_emails.sql` récupère les liens existants uniquement lorsque l’adresse et la ville identifient un site unique du même client. Test transactionnel sans envoi : `php scripts/test_destination_notifications.php`.

Les administrateurs disposent de l’écran **Configuration e-mail** (`/settings/mail`) dans le menu. Il permet d’enregistrer le serveur SMTP, le chiffrement, les identifiants et l’expéditeur dans `.env`, ou de simuler les envois en mode Journal. Le bouton **Envoyer un test** utilise les paramètres saisis sans les enregistrer ; en mode SMTP, un véritable e-mail est envoyé à l’adresse indiquée. Le mot de passe n’est jamais réaffiché : un champ vide conserve le secret enregistré, une case permet de le supprimer. Le fichier `.env` et son dossier doivent être accessibles en écriture par PHP. Les variables `MAIL_*` imposées par l’hébergement restent prioritaires et bloquent l’enregistrement depuis cet écran. La tâche planifiée d’envoi des notifications reste nécessaire. Tests isolés : `php scripts/test_mail_settings.php` et `php scripts/test_mail_settings_access.php`.

Le test `node scripts/test_mail_smtp.js` vérifie l’acceptation et le refus d’un message via un simulateur SMTP local, sans envoyer d’e-mail réel.

Les soumissions de cet écran utilisent AJAX avec confirmation, attente et résultat via SweetAlert. Une erreur conserve les valeurs saisies et indique le champ à corriger ou l’étape SMTP en échec (authentification, expéditeur, destinataire, TLS). Le test conserve le mot de passe saisi pour permettre l’enregistrement ultérieur ; une sauvegarde réussie efface le secret du formulaire. Vérification des interactions : `node scripts/test_mail_settings_ajax.js`.

Le sous-écran **Tests des notifications de livraison** (`/settings/mail/notifications`), réservé aux administrateurs, propose une recherche par référence/client et un aperçu des notifications de départ, d’arrivée et de livraison. Les modèles et données de la livraison sont utilisés, avec un objet préfixé `[TEST]`. L’envoi vise uniquement l’adresse de test saisie et utilise les paramètres mail enregistrés ; les contacts abonnés sont affichés pour information. Les statuts et la file des notifications automatiques ne sont pas modifiés. Le test de livraison joint le bon PDF existant de la destination ; en son absence, l’aperçu et le message signalent qu’aucune pièce jointe n’est disponible. Tests isolés : `php scripts/test_notification_preview.php` et `node scripts/test_notification_tests_ajax.js`.

Les notifications sont envoyées en HTML avec une version texte alternative : en-tête institutionnel, statut, référence, informations opérationnelles, réserves et document de livraison. Le modèle utilise des tableaux et des styles intégrés, sans images ni polices externes. Il s’applique aux notifications automatiques, y compris celles déjà en attente, et aux tests. L’aperçu HTML est affiché dans un cadre isolé. Aucune migration supplémentaire n’est nécessaire. Vérification du rendu, de l’échappement et du format MIME avec PDF : `php scripts/test_notification_email.php`.

Les destinataires sont saisis dans la fiche client, onglet « Notifications » (e-mail coché, événements choisis). Un e-mail est préparé :

- au **départ** de la livraison (statut « Partie », ou passage direct depuis la préparation à « En transit ») ;
- à l’**arrivée** du chauffeur à chaque destination ;
- à la **livraison** de chaque destination, avec le PDF uniquement s’il est déjà stocké.

La migration `2026_10_26_000030_notification_event_triggers.sql` installe les triggers `notification_delivery_updated` et `notification_destination_updated`. Ils enregistrent les changements dans `notification_events`, dans la transaction métier, quelle que soit l’origine de l’UPDATE (chauffeur, bureau, API ou SQL). Un rollback annule aussi l’événement. Aucun événement historique n’est recréé lors de la migration. Le passage global à « Livrée » couvre les destinations non annulées ; la clôture et la reprise après incident ne renvoient pas les notifications déjà créées.

Le fichier utilise des triggers à instruction unique, sans bloc `BEGIN…END` ni changement de délimiteur : il est exécutable par `php scripts/migrate.php` et importable directement dans un client SQL avec le délimiteur standard `;`. En cas d’import partiel sur le VPS, réimporter le fichier corrigé en entier : la table existante est conservée et les deux triggers sont remplacés, sans effacement des événements ni renvoi historique. Vérification locale des deux modes et de la réimportation : `php scripts/test_notification_trigger_import.php` (réinstalle les triggers, sans modifier les données métier).

Chaque minute, le worker transforme les événements en messages `notification_outbox` puis les envoie. Les contraintes uniques des deux files empêchent les doublons par événement/destination et par destinataire. La préparation et l’envoi possèdent chacun leurs erreurs et tentatives (maximum 5) ; une panne SMTP ne bloque pas la livraison. Les destinataires sont résolus lors de la préparation ; un événement sans abonné est marqué traité sans message. Les confirmations sont indépendantes du PDF : aucun rendu de PDF dans le worker, et aucun second mail à sa création. Sans document disponible, le texte annonce une confirmation sans pièce jointe. La preuve est enregistrée lors de la confirmation, et le PDF est généré et conservé au premier téléchargement. Les PDF déjà existants restent disponibles.

Tests transactionnels sans envoi réel : `php scripts/test_notification_triggers.php` (SQL direct, rollback, plusieurs destinations, doublons, PDF facultatif, reprise après erreur) et `php scripts/test_driver_app_audit.php` (parcours chauffeur et téléchargements). La tâche d’envoi reste nécessaire :

```
* * * * * php /chemin/vers/MSS-DMS/scripts/send_notifications.php >> /chemin/vers/MSS-DMS/storage/logs/notifications.log 2>&1
```

Configuration dans `.env` : `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` (`tls`, `ssl` ou `none`), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Avec `MAIL_DRIVER=log`, les e-mails sont seulement écrits dans `storage/logs/mail.log`. L’historique des envois est visible sur la fiche de chaque livraison. Test : `php scripts/test_client_notifications.php`.

Sur le poste Windows local, la tâche **MSS-DMS - Notifications e-mail** traite la file chaque minute et à l’ouverture de session. Elle utilise `scripts/run_notifications.ps1`, PHP de XAMPP, le verrou du worker et le journal `storage/logs/notifications.log`. Elle fonctionne lorsque la session Windows de son propriétaire est ouverte (y compris verrouillée), avec XAMPP et la connexion réseau disponibles. Le lanceur configure un dossier de session PHP accessible et renvoie le code de sortie du worker au Planificateur. Une installation sur un autre serveur nécessite sa propre tâche planifiée.

Les bibliothèques frontend sont chargées dans des versions figées depuis CDN. Aucun module métier n’est encore implémenté.
