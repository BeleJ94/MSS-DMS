<?php
$maps = $mission['latitude'] && $mission['longitude']
    ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($mission['latitude'].','.$mission['longitude'])
    : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($mission['site_address'].', '.$mission['site_city']);
?>
<a class="mobile-back" href="<?= $baseUrl ?>/driver-app"><i data-lucide="arrow-left"></i> Mes missions</a>
<section class="mission-hero">
    <div><span>Mission</span><h1><?= htmlspecialchars($mission['reference'], ENT_QUOTES, 'UTF-8') ?></h1></div>
    <b><?= htmlspecialchars($mission['status'], ENT_QUOTES, 'UTF-8') ?></b>
</section>
<section class="mobile-card destination-card">
    <small>CLIENT ET DESTINATION</small>
    <h2><?= htmlspecialchars($mission['company_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p><i data-lucide="map-pin"></i><span><strong><?= htmlspecialchars($mission['site_name'], ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($mission['site_address'].', '.$mission['site_city'], ENT_QUOTES, 'UTF-8') ?></span></p>
    <a class="route-button" href="<?= htmlspecialchars($maps, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><i data-lucide="navigation"></i> Ouvrir l’itinéraire</a>
</section>
<section class="mobile-card">
    <small>CONTACT SUR PLACE</small>
    <?php if ($mission['contact_name']): ?>
        <div class="mobile-contact"><span><i data-lucide="user-round"></i></span><div><strong><?= htmlspecialchars($mission['contact_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($mission['contact_phone'] ?: $mission['contact_email'], ENT_QUOTES, 'UTF-8') ?></small></div><?php if ($mission['contact_phone']): ?><a href="tel:<?= htmlspecialchars($mission['contact_phone'], ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="phone"></i></a><?php endif; ?></div>
    <?php else: ?><p class="muted">Aucun contact renseigné.</p><?php endif; ?>
</section>
<section class="mobile-card">
    <small>MARCHANDISES</small>
    <ul class="mobile-goods"><?php foreach ($mission['goods'] as $goods): ?><li><span><?= htmlspecialchars($goods['description_snapshot'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($goods['quantity'].' '.$goods['unit'], ENT_QUOTES, 'UTF-8') ?></strong></li><?php endforeach; ?><?php if (!$mission['goods']): ?><li><span>Aucune ligne renseignée</span></li><?php endif; ?></ul>
</section>
<?php if ($mission['delivery_instructions']): ?><section class="mobile-card instruction"><i data-lucide="info"></i><div><small>INSTRUCTIONS</small><p><?= nl2br(htmlspecialchars($mission['delivery_instructions'], ENT_QUOTES, 'UTF-8')) ?></p></div></section><?php endif; ?>
<?php if ($pod): ?>
    <section class="mobile-card pod-complete-card"><span><i data-lucide="badge-check"></i></span><div><small>PREUVE DE LIVRAISON</small><strong>Signée par <?= htmlspecialchars($pod['recipient_name'], ENT_QUOTES, 'UTF-8') ?></strong><p><?= date('d/m/Y à H:i', strtotime($pod['captured_at'])) ?></p></div><a href="<?= $baseUrl ?>/deliveries/<?= (int) $mission['id'] ?>/pod.pdf" target="_blank"><i data-lucide="file-down"></i> PDF</a></section>
<?php endif; ?>
<section class="mission-actions" data-mission-id="<?= (int) $mission['id'] ?>">
    <?php if (in_array($mission['status'], ['Chargée', 'Partie'], true)): ?><button class="primary" data-mission-action="start"><i data-lucide="play"></i>Démarrer la mission</button><?php endif; ?>
    <?php if ($mission['status'] === 'En transit'): ?><button class="primary" data-mission-action="arrive"><i data-lucide="map-pin-check"></i>Je suis arrivé</button><?php endif; ?>
    <?php if ($mission['status'] === 'Arrivée'): ?><button class="primary success" type="button" data-pod-open><i data-lucide="signature"></i>Faire signer et livrer</button><?php endif; ?>
    <?php if (!in_array($mission['status'], ['Incident', 'Livrée', 'Clôturée', 'Annulée'], true)): ?><button class="danger" type="button" data-incident-open><i data-lucide="triangle-alert"></i>Signaler un incident</button><?php endif; ?>
    <?php if (in_array($mission['status'], ['Brouillon', 'À préparer', 'Prête', 'Chargement'], true)): ?><p class="waiting"><i data-lucide="clock-3"></i> Mission en préparation. Le départ sera disponible après le chargement.</p><?php endif; ?>
</section>

<?php if ($mission['status'] === 'Arrivée'): ?>
<div class="pod-modal" id="podModal" hidden aria-hidden="true">
    <section class="pod-sheet" role="dialog" aria-modal="true" aria-labelledby="podTitle">
        <header><button type="button" data-pod-close aria-label="Fermer"><i data-lucide="x"></i></button><span>PREUVE DE LIVRAISON</span><h2 id="podTitle">Faire signer la réception</h2><p><?= htmlspecialchars($mission['reference'].' · '.$mission['company_name'], ENT_QUOTES, 'UTF-8') ?></p></header>
        <form id="podForm" data-mission-id="<?= (int) $mission['id'] ?>">
            <label class="pod-field"><span>Nom du réceptionnaire *</span><input name="recipient_name" maxlength="160" autocomplete="name" placeholder="Nom et prénom" required></label>
            <div class="pod-field"><div class="pod-label-row"><span>Signature du réceptionnaire *</span><button type="button" data-signature-clear>Effacer</button></div><div class="signature-pad"><canvas id="signatureCanvas" aria-label="Zone de signature tactile"></canvas><em id="signatureHint"><i data-lucide="pen-line"></i> Signez avec le doigt</em></div></div>
            <label class="pod-photo-field"><input type="file" name="delivery_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required><span><i data-lucide="camera"></i><b>Photo de la livraison *</b><small data-file-label="delivery_photo">Prendre une photo</small></span></label>
            <label class="pod-photo-field optional"><input type="file" name="signed_note_photo" accept="image/jpeg,image/png,image/webp" capture="environment"><span><i data-lucide="file-check-2"></i><b>Bon signé</b><small data-file-label="signed_note_photo">Photo facultative</small></span></label>
            <label class="pod-field"><span>Observations</span><textarea name="observations" maxlength="2000" rows="3" placeholder="État des colis, réserves éventuelles…"></textarea></label>
            <div class="pod-auto-data"><span><i data-lucide="clock-3"></i>Date et heure automatiques</span><span><i data-lucide="map-pin"></i>GPS capturé à la validation</span><span><i data-lucide="truck"></i><?= htmlspecialchars($mission['registration_number'] ?: 'Véhicule affecté', ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="pod-mobile-actions"><button class="pod-cancel" type="button" data-pod-close><i data-lucide="x"></i>Annuler</button><button class="pod-submit" type="submit"><i data-lucide="badge-check"></i><span>Confirmer la livraison</span></button></div>
            <p class="pod-legal">En signant, le réceptionnaire confirme avoir reçu les marchandises indiquées.</p>
        </form>
    </section>
</div>
<?php endif; ?>
<?php if (!in_array($mission['status'], ['Incident', 'Livrée', 'Clôturée', 'Annulée'], true)): ?>
<div class="driver-incident-modal" id="driverIncidentModal" hidden aria-hidden="true">
    <section class="driver-incident-sheet" role="dialog" aria-modal="true" aria-labelledby="driverIncidentTitle">
        <header><button type="button" data-incident-close aria-label="Fermer"><i data-lucide="x"></i></button><span>ALERTE OPÉRATIONNELLE</span><h2 id="driverIncidentTitle">Signaler un incident</h2><p><?= htmlspecialchars($mission['reference'].' · '.$mission['company_name'],ENT_QUOTES,'UTF-8') ?></p></header>
        <form id="driverIncidentForm" data-mission-id="<?= (int)$mission['id'] ?>">
            <label class="incident-mobile-field"><span>Type d’incident *</span><select name="incident_type" required><option value="">Sélectionner</option><option value="panne">Panne</option><option value="accident">Accident</option><option value="retard">Retard</option><option value="client absent">Client absent</option><option value="marchandise refusée">Marchandise refusée</option><option value="quantité manquante">Quantité manquante</option><option value="marchandise endommagée">Marchandise endommagée</option><option value="problème documentaire">Problème documentaire</option><option value="autre">Autre</option></select></label>
            <label class="incident-mobile-field"><span>Description *</span><textarea name="description" rows="5" maxlength="3000" placeholder="Expliquez clairement ce qui s’est passé…" required></textarea><small>10 caractères minimum</small></label>
            <label class="incident-mobile-photos"><input type="file" name="incident_photos" accept="image/jpeg,image/png,image/webp" capture="environment" multiple><span><i data-lucide="camera"></i><b>Ajouter des photos</b><small id="incidentPhotoLabel">Jusqu’à 3 photos · facultatif</small></span></label>
            <div class="incident-auto-data"><span><i data-lucide="clock-3"></i>Date et heure automatiques</span><span><i data-lucide="map-pin"></i>Position GPS capturée à l’envoi</span><span><i data-lucide="package"></i><?= htmlspecialchars($mission['reference'],ENT_QUOTES,'UTF-8') ?></span></div>
            <div class="incident-mobile-actions"><button class="incident-cancel" type="button" data-incident-close><i data-lucide="x"></i>Annuler</button><button class="incident-submit" type="submit"><i data-lucide="siren"></i>Envoyer l’alerte</button></div>
            <p>L’incident suspendra la mission et alertera le dispatching.</p>
        </form>
    </section>
</div>
<?php endif; ?>
