<?php use App\Core\Csrf; ?>
<main class="auth-shell">
    <section class="auth-visual">
        <div class="auth-brand"><span class="brand-mark"><i data-lucide="route"></i></span><span><strong>MSS-DMS</strong><small>Delivery Management System</small></span></div>
        <div class="auth-copy"><span class="eyebrow light">Plateforme logistique</span><h1>Le pilotage de vos livraisons, dans un espace unifié.</h1><p>Une infrastructure sécurisée pensée pour coordonner les équipes, les opérations et la performance.</p></div>
        <div class="auth-grid"></div><div class="auth-caption"><i data-lucide="shield-check"></i> Accès sécurisé et traçable</div>
    </section>
    <section class="auth-form-side">
        <form class="login-card" method="post" action="">
            <?= Csrf::field() ?>
            <div class="mobile-auth-brand"><span class="brand-mark"><i data-lucide="route"></i></span><strong>MSS-DMS</strong></div>
            <span class="eyebrow">Espace sécurisé</span><h2>Bienvenue</h2><p>Connectez-vous pour accéder à votre espace de travail.</p>
            <?php if ($error): ?><div class="alert alert-error"><i data-lucide="circle-alert"></i><span><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
            <label class="field"><span>Adresse e-mail</span><div><i data-lucide="mail"></i><input type="email" name="email" value="<?= htmlspecialchars((string) $oldEmail, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus placeholder="nom@entreprise.com"></div></label>
            <label class="field"><span>Mot de passe</span><div><i data-lucide="lock-keyhole"></i><input type="password" name="password" autocomplete="current-password" required placeholder="Votre mot de passe"><button type="button" class="password-toggle" aria-label="Afficher le mot de passe"><i data-lucide="eye"></i></button></div></label>
            <button class="button button-primary login-button" type="submit">Se connecter <i data-lucide="arrow-right"></i></button>
            <small class="security-note"><i data-lucide="lock"></i> Votre session est protégée et toutes les connexions sont journalisées.</small>
        </form>
    </section>
</main>
<script>document.addEventListener('click',function(e){var b=e.target.closest('.password-toggle');if(!b)return;var i=b.parentNode.querySelector('input');i.type=i.type==='password'?'text':'password';});</script>

