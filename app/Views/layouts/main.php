<?php
use App\Core\Env;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
$appName = (string) Env::get('APP_NAME', 'MSS-DMS');
$baseUrl = rtrim((string) Env::get('APP_URL', ''), '/');
$currentUser = Auth::user();
$initials = '';
foreach (explode(' ', (string) ($currentUser['name'] ?? 'Utilisateur')) as $part) { $initials .= mb_substr($part, 0, 1); }
$initials = mb_strtoupper(mb_substr($initials, 0, 2));
$successMessage = Session::pull('success');
$errorMessage = Session::pull('error');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title><?= htmlspecialchars($title ?? $appName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <?php if (($page ?? '') === 'live-tracking' || !empty($usesLeaflet)): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""><?php endif; ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/interface.css?v=20260820-3">
    <?php if (($page ?? '') === 'deliveries'): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/pod-admin.css?v=20260805-2"><?php endif; ?>
    <?php if (!empty($usesLeaflet)): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/route-history.css?v=20260805-1"><?php endif; ?>
    <?php if (($page ?? '') === 'incidents'): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/incidents.css"><?php endif; ?>
    <?php if (($page ?? '') === 'dashboard'): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/dashboard-incidents.css"><?php endif; ?>
    <?php if (($page ?? '') === 'dashboard'): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/dashboard-final.css"><?php endif; ?>
    <?php if (($page ?? '') === 'planning'): ?><link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/planning.css?v=20260805-2"><?php endif; ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/typography.css?v=20260806-1">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/modal-actions.css?v=20260806-2">
</head>
<body>
<div class="app-shell">
    <button class="sidebar-overlay" id="sidebarOverlay" aria-label="Fermer le menu" hidden></button>
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= $baseUrl ?>/">
            <span class="brand-mark"><i data-lucide="route"></i></span>
            <span><strong>MSS</strong><small>Delivery Management</small></span>
        </a>
        <nav class="nav-group" aria-label="Navigation principale">
            <span class="nav-label">Pilotage</span>
            <a class="nav-item <?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/"><i data-lucide="layout-dashboard"></i><span>Tableau de bord</span></a>
            <?php if (Auth::can('deliveries.view')): ?><a class="nav-item <?= ($page ?? '') === 'deliveries' ? 'active' : '' ?>" href="<?= $baseUrl ?>/deliveries"><i data-lucide="package-check"></i><span>Livraisons</span></a><?php endif; ?>
            <?php if (Auth::can('dispatching.view')): ?><a class="nav-item <?= ($page ?? '') === 'dispatching' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dispatching"><i data-lucide="calendar-check-2"></i><span>Dispatching</span></a><?php endif; ?>
            <?php if (Auth::can('planning.view')): ?><a class="nav-item <?= ($page ?? '') === 'planning' ? 'active' : '' ?>" href="<?= $baseUrl ?>/planning"><i data-lucide="calendar-days"></i><span>Planning</span></a><?php endif; ?>
            <?php if (Auth::can('tracking.view')): ?><a class="nav-item <?= ($page ?? '') === 'live-tracking' ? 'active' : '' ?>" href="<?= $baseUrl ?>/live-tracking"><i data-lucide="radio-tower"></i><span>Suivi en direct</span><i class="live-dot"></i></a><?php endif; ?>
            <span class="nav-label nav-label-spaced">Référentiels</span>
            <?php if (Auth::can('clients.view')): ?><a class="nav-item <?= ($page ?? '') === 'clients' ? 'active' : '' ?>" href="<?= $baseUrl ?>/clients"><i data-lucide="building-2"></i><span>Clients</span></a><?php endif; ?>
            <?php if (Auth::can('fleet.view')): ?><a class="nav-item <?= ($page ?? '') === 'fleet' ? 'active' : '' ?>" href="<?= $baseUrl ?>/fleet"><i data-lucide="truck"></i><span>Flotte</span></a><?php endif; ?>
            <?php if (Auth::can('drivers.view')): ?><a class="nav-item <?= ($page ?? '') === 'drivers' ? 'active' : '' ?>" href="<?= $baseUrl ?>/drivers"><i data-lucide="contact-round"></i><span>Chauffeurs</span></a><?php endif; ?>
            <?php if (Auth::can('goods.view')): ?><a class="nav-item <?= ($page ?? '') === 'goods' ? 'active' : '' ?>" href="<?= $baseUrl ?>/goods"><i data-lucide="boxes"></i><span>Marchandises</span></a><?php endif; ?>
            <span class="nav-label nav-label-spaced">Analyse</span>
            <?php if (Auth::can('incidents.view')): ?><a class="nav-item <?= ($page ?? '') === 'incidents' ? 'active' : '' ?>" href="<?= $baseUrl ?>/incidents"><i data-lucide="triangle-alert"></i><span>Incidents</span></a><?php endif; ?>
            <button class="nav-item nav-button"><i data-lucide="chart-no-axes-combined"></i><span>Rapports</span></button>
            <?php if (Auth::can('users.manage')): ?><a class="nav-item <?= ($page ?? '') === 'users' ? 'active' : '' ?>" href="<?= $baseUrl ?>/users"><i data-lucide="settings"></i><span>Administration</span></a><?php endif; ?>
        </nav>
        <div class="sidebar-foot"><span class="status-dot"></span><span>Système opérationnel</span><strong>v1.0</strong></div>
    </aside>
    <main class="main-area">
        <header class="topbar">
            <button class="icon-button menu-toggle" id="menuToggle" aria-label="Afficher le menu"><i data-lucide="menu"></i></button>
            <div class="mobile-brand">MSS-DMS</div>
            <div class="search"><i data-lucide="search"></i><input type="search" placeholder="Rechercher une livraison, un client…" aria-label="Rechercher"><kbd>⌘ K</kbd></div>
            <div class="topbar-actions">
                <button class="icon-button desktop-only" aria-label="Aide"><i data-lucide="circle-help"></i></button>
                <div class="notification-wrap">
                    <button class="icon-button notification-button" id="notificationToggle" aria-label="Notifications" aria-expanded="false"><i data-lucide="bell"></i><span class="notification-indicator">3</span></button>
                    <section class="notification-popover" id="notificationPopover" hidden>
                        <div class="notification-head"><div><strong>Notifications</strong><span>3 nouvelles</span></div><button>Tout marquer comme lu</button></div>
                        <div class="notification-list">
                            <article class="notification-item unread"><span class="notice-icon warning"><i data-lucide="triangle-alert"></i></span><div><strong>Retard potentiel détecté</strong><p>La livraison LIV-2026-0842 accuse 24 min de retard.</p><time>Il y a 8 min</time></div></article>
                            <article class="notification-item unread"><span class="notice-icon success"><i data-lucide="circle-check"></i></span><div><strong>Livraison confirmée</strong><p>La livraison pour Mwanza Logistics a été réceptionnée.</p><time>Il y a 32 min</time></div></article>
                            <article class="notification-item"><span class="notice-icon"><i data-lucide="wrench"></i></span><div><strong>Maintenance planifiée</strong><p>Le véhicule TRK-018 est attendu demain à 08:00.</p><time>Il y a 2 h</time></div></article>
                        </div>
                        <button class="notification-footer">Voir toutes les notifications</button>
                    </section>
                </div>
                <span class="divider"></span>
                <span class="user"><span class="avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span><strong><?= htmlspecialchars($currentUser['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($currentUser['roles'] ?? 'Sans rôle', ENT_QUOTES, 'UTF-8') ?></small></span><i data-lucide="chevron-down"></i></span>
                <form method="post" action="<?= $baseUrl ?>/logout" class="logout-form"><?= Csrf::field() ?><button class="icon-button" aria-label="Se déconnecter" title="Se déconnecter"><i data-lucide="log-out"></i></button></form>
            </div>
        </header>
        <section class="content"><?= $content ?></section>
    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>window.MSS_DMS = <?= json_encode(['baseUrl' => $baseUrl], JSON_UNESCAPED_SLASHES) ?>;</script>
<script>window.MSS_CSRF = <?= json_encode(Csrf::token()) ?>;</script>
<?php if ($successMessage || $errorMessage): ?><script>window.MSS_FLASH = <?= json_encode(['type' => $successMessage ? 'success' : 'error', 'message' => $successMessage ?: $errorMessage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script><?php endif; ?>
<script src="<?= $baseUrl ?>/assets/js/app.js?v=20260806-3"></script>
<?php if (($page ?? '') === 'dashboard'): ?><script src="<?= $baseUrl ?>/assets/js/dashboard.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'clients'): ?><script src="<?= $baseUrl ?>/assets/js/clients.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'drivers'): ?><script src="<?= $baseUrl ?>/assets/js/drivers.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'fleet'): ?><script src="<?= $baseUrl ?>/assets/js/fleet.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'goods'): ?><script src="<?= $baseUrl ?>/assets/js/goods.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'deliveries'): ?><script src="<?= $baseUrl ?>/assets/js/deliveries.js?v=20260820-3"></script><?php endif; ?>
<?php if (($page ?? '') === 'incidents'): ?><script src="<?= $baseUrl ?>/assets/js/incidents.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'dispatching'): ?><script src="<?= $baseUrl ?>/assets/js/dispatching.js"></script><?php endif; ?>
<?php if (($page ?? '') === 'planning'): ?><script src="<?= $baseUrl ?>/assets/js/planning.js?v=20260805-1"></script><?php endif; ?>
<?php if (($page ?? '') === 'live-tracking'): ?><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script><script src="<?= $baseUrl ?>/assets/js/live-tracking.js?v=20260807-4"></script><?php endif; ?>
<?php if (!empty($usesLeaflet)): ?><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script><script src="<?= $baseUrl ?>/assets/js/route-history.js?v=20260805-1"></script><?php endif; ?>
</body>
</html>
