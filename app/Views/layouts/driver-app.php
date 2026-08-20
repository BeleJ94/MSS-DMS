<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;

$baseUrl = rtrim((string) Env::get('APP_URL', ''), '/');
$user = Auth::user();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#163a67">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= htmlspecialchars($title ?? 'MSS Chauffeur', ENT_QUOTES, 'UTF-8') ?> · MSS</title>
    <link rel="manifest" href="<?= $baseUrl ?>/manifest.json">
    <link rel="icon" href="<?= $baseUrl ?>/assets/icons/driver-app.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= $baseUrl ?>/assets/icons/driver-app.svg">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/driver-app.css?v=20260811-2">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/driver-missions.css?v=20260820-3">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/driver-location.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/driver-pod.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/driver-incident.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/typography.css?v=20260806-1">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/modal-actions.css?v=20260806-2">
</head>
<body>
<div class="mobile-app">
    <header class="mobile-header">
        <a href="<?= $baseUrl ?>/driver-app" class="mobile-brand">
            <span><i data-lucide="route"></i></span>
            <div>
                <strong>MSS Chauffeur</strong>
                <small><?= htmlspecialchars($driver ? ($driver['first_name'].' '.$driver['last_name']) : ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </a>
        <div class="mobile-head-actions">
            <span class="gps-pill" id="gpsStatus" hidden><i data-lucide="locate-fixed"></i><b>GPS</b></span>
            <form method="post" action="<?= $baseUrl ?>/logout" data-mobile-confirm="Déconnexion">
                <input type="hidden" name="_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <button aria-label="Se déconnecter"><i data-lucide="log-out"></i></button>
            </form>
        </div>
    </header>
    <?php if (!empty($driver)): ?>
        <section class="location-setup" id="locationSetup" data-state="checking">
            <span class="location-setup-icon"><i data-lucide="map-pin"></i></span>
            <div>
                <strong id="locationSetupTitle">Vérification de la localisation…</strong>
                <small id="locationSetupText">Quelques secondes suffisent pour préparer le GPS.</small>
            </div>
            <button type="button" id="locationSetupButton"><i data-lucide="navigation"></i><span>Autoriser</span></button>
        </section>
    <?php endif; ?>
    <main><?= $content ?></main>
    <div class="offline-bar" id="offlineBar" hidden><i data-lucide="wifi-off"></i> Mode hors ligne</div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script>window.MSS_DRIVER_APP=<?= json_encode(['baseUrl' => $baseUrl, 'csrf' => Csrf::token(), 'activeMissionId' => (int) ($activeMissionId ?? 0), 'build' => '20260807-5'], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= $baseUrl ?>/assets/js/driver-tracking.js?v=20260807-5"></script>
<script src="<?= $baseUrl ?>/assets/js/driver-app.js?v=20260820-3"></script>
<script src="<?= $baseUrl ?>/assets/js/driver-gps-diagnostics.js?v=20260807-5"></script>
<script src="<?= $baseUrl ?>/assets/js/driver-pod.js"></script>
<script src="<?= $baseUrl ?>/assets/js/driver-incident.js"></script>
</body>
</html>
