<?php
$maps = $mission['latitude'] && $mission['longitude']
    ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($mission['latitude'].','.$mission['longitude'])
    : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($mission['site_address'].', '.$mission['site_city']);
$nextAction=App\Models\DriverMission::nextAction($mission['status']);
if($nextAction&&$nextAction['action']==='arrive'){$nextAction['label']='Confirmer l’arrivée · Destination '.(int)$mission['current_stop_order'].'/'.count($mission['destinations']);}
if($nextAction&&$nextAction['action']==='unload'){$nextAction['label']='Décharger · '.($mission['site_name']?:'Destination '.(int)$mission['current_stop_order']);}
$stageClasses=['Brouillon'=>'assigned','Affectée'=>'assigned','À préparer'=>'preparation','Prête'=>'ready','Chargement'=>'loading','Chargée'=>'loaded','Partie'=>'transit','En transit'=>'transit','Arrivée'=>'arrived','Déchargement'=>'unloading','Incident'=>'incident','Livrée'=>'completed','Clôturée'=>'completed'];$stageClass=$stageClasses[$mission['status']]??'assigned';
$driverStages=['Affectée','À préparer','Prête','Chargement','Chargée','En transit','Arrivée','Déchargement','Livrée'];
$driverStageStatus=$mission['status']==='Brouillon'?'Affectée':($mission['status']==='Partie'?'En transit':$mission['status']);
$driverStageIndex=array_search($driverStageStatus,$driverStages,true);
?>
<a class="mobile-back" href="<?= $baseUrl ?>/driver-app"><i data-lucide="arrow-left"></i> Mes missions</a>
<section class="mission-hero stage-<?= $stageClass ?>">
    <div><span>Mission</span><h1><?= htmlspecialchars($mission['reference'], ENT_QUOTES, 'UTF-8') ?></h1></div>
    <b><?= htmlspecialchars($mission['status'], ENT_QUOTES, 'UTF-8') ?></b>
</section>
<section class="mobile-card driver-workflow-card"><div class="driver-workflow-head"><div><small>PROGRESSION DE LA MISSION</small><h2>Étape sous votre responsabilité</h2></div><b><?= $driverStageIndex===false?'—':($driverStageIndex+1).' / '.count($driverStages) ?></b></div><div class="driver-workflow-track"><?php foreach($driverStages as $index=>$stage): $done=$driverStageIndex!==false&&$index<$driverStageIndex;$active=$driverStageIndex===$index; ?><div class="<?= $done?'done':'' ?> <?= $active?'active':'' ?>"><span><?= $done?'<i data-lucide="check"></i>':($index+1) ?></span><small><?= htmlspecialchars($stage,ENT_QUOTES,'UTF-8') ?></small></div><?php endforeach; ?></div><p><i data-lucide="shield-check"></i> Chaque action est horodatée et enregistrée à votre nom.</p></section>
<section class="mobile-card destination-card">
    <small>CLIENT ET PROCHAINE DESTINATION</small>
    <h2><?= htmlspecialchars($mission['company_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p><i data-lucide="map-pin"></i><span><strong><?= htmlspecialchars($mission['site_name'], ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($mission['site_address'].', '.$mission['site_city'], ENT_QUOTES, 'UTF-8') ?></span></p>
    <a class="route-button" href="<?= htmlspecialchars($maps, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><i data-lucide="navigation"></i> Ouvrir l’itinéraire</a>
</section>
<section class="mobile-card"><small>ITINÉRAIRE · <?= count($mission['destinations']) ?> DESTINATION(S)</small><ol class="delivery-timeline"><?php foreach($mission['destinations'] as $destination): ?><li><span><?= (int)$destination['stop_order'] ?></span><div><strong><?= htmlspecialchars($destination['label'],ENT_QUOTES,'UTF-8') ?></strong><p><?= htmlspecialchars($destination['address_line'].($destination['city']?', '.$destination['city']:''),ENT_QUOTES,'UTF-8') ?></p><small><?= htmlspecialchars($destination['status'],ENT_QUOTES,'UTF-8') ?></small></div></li><?php endforeach; ?></ol></section>
<section class="mobile-card">
    <small>CONTACT SUR PLACE</small>
    <?php if ($mission['contact_name']): ?>
        <div class="mobile-contact"><span><i data-lucide="user-round"></i></span><div><strong><?= htmlspecialchars($mission['contact_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($mission['contact_phone'] ?: $mission['contact_email'], ENT_QUOTES, 'UTF-8') ?></small></div><?php if ($mission['contact_phone']): ?><a href="tel:<?= htmlspecialchars($mission['contact_phone'], ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="phone"></i></a><?php endif; ?></div>
    <?php else: ?><p class="muted">Aucun contact renseigné.</p><?php endif; ?>
</section>
<section class="mobile-card <?= $mission['status']==='Déchargement'?'unloading-card':'' ?>">
    <small>MARCHANDISES</small>
    <?php if($mission['status']==='Déchargement'): ?>
    <form class="unloading-form" data-unloading-form data-mission-id="<?= (int)$mission['id'] ?>">
        <div class="unloading-head"><div><h2>Contrôlez ce qui est remis</h2><p>Une touche par marchandise. Signalez uniquement les écarts.</p></div><button type="button" data-all-conform><i data-lucide="check-check"></i> Tout est conforme</button></div>
        <div class="unloading-list"><?php foreach($mission['goods'] as $goods): $planned=(float)$goods['quantity']; ?><article class="unloading-item <?= $goods['checked_at']?'is-checked':'' ?>" data-goods-id="<?= (int)$goods['id'] ?>" data-planned="<?= htmlspecialchars((string)$planned,ENT_QUOTES,'UTF-8') ?>"><header><span><i data-lucide="package"></i></span><div><strong><?= htmlspecialchars($goods['description_snapshot'],ENT_QUOTES,'UTF-8') ?></strong><small>Prévu : <?= htmlspecialchars($goods['quantity'].' '.$goods['unit'],ENT_QUOTES,'UTF-8') ?></small></div><b data-line-state><?= $goods['checked_at']?'<i data-lucide="check"></i>':'' ?></b></header><div class="unloading-quantity"><span>Quantité remise</span><div><button type="button" data-qty-minus aria-label="Diminuer">−</button><input inputmode="decimal" data-delivered-quantity value="<?= htmlspecialchars((string)($goods['delivered_quantity']??$goods['quantity']),ENT_QUOTES,'UTF-8') ?>" aria-label="Quantité remise"><em><?= htmlspecialchars($goods['unit'],ENT_QUOTES,'UTF-8') ?></em><button type="button" data-qty-plus aria-label="Augmenter">+</button></div></div><div class="unloading-conditions" role="group" aria-label="État de la marchandise"><?php foreach(['Conforme','Partielle','Endommagée','Refusée','Manquante'] as $condition): ?><button type="button" data-condition="<?= $condition ?>" class="<?= $goods['delivery_condition']===$condition?'active':'' ?>"><?= $condition ?></button><?php endforeach; ?></div><label class="unloading-note" <?= (!$goods['delivery_condition']||$goods['delivery_condition']==='Conforme')?'hidden':'' ?>><span>Expliquez l’écart *</span><textarea maxlength="500" rows="2" placeholder="Ex. 1 colis endommagé…" data-driver-note><?= htmlspecialchars((string)$goods['driver_note'],ENT_QUOTES,'UTF-8') ?></textarea></label></article><?php endforeach; ?></div>
        <button class="unloading-submit" type="submit"><i data-lucide="clipboard-check"></i><span>Valider le déchargement</span></button>
    </form>
    <?php else: ?><ul class="mobile-goods"><?php foreach ($mission['goods'] as $goods): ?><li><span><?= htmlspecialchars($goods['description_snapshot'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($goods['quantity'].' '.$goods['unit'], ENT_QUOTES, 'UTF-8') ?></strong></li><?php endforeach; ?><?php if (!$mission['goods']): ?><li><span>Aucune ligne renseignée</span></li><?php endif; ?></ul><?php endif; ?>
</section>
<?php if ($mission['delivery_instructions']): ?><section class="mobile-card instruction"><i data-lucide="info"></i><div><small>INSTRUCTIONS</small><p><?= nl2br(htmlspecialchars($mission['delivery_instructions'], ENT_QUOTES, 'UTF-8')) ?></p></div></section><?php endif; ?>
<section class="mobile-card gps-audit" id="gpsAudit" data-mission-id="<?= (int)$mission['id'] ?>">
    <div class="gps-audit-head"><div><small>VÉRIFICATION GPS EN TEMPS RÉEL</small><h2>Historique des transmissions</h2></div><button type="button" id="gpsAuditRefresh" aria-label="Actualiser"><i data-lucide="refresh-cw"></i></button></div>
    <div class="gps-audit-kpis"><span><b id="gpsCapturedCount">0</b><small>capturées</small></span><span><b id="gpsPendingCount">0</b><small>en attente</small></span><span><b id="gpsServerCount">0</b><small>sur serveur</small></span><span><b id="gpsRejectedCount">0</b><small>rejetées</small></span></div>
    <p class="gps-audit-state" id="gpsAuditState"><i data-lucide="satellite"></i><span>Chargement de l’historique du serveur…</span></p>
    <div class="gps-audit-list" id="gpsAuditList"><p>Aucune position capturée pendant cette session.</p></div>
    <small class="gps-audit-help">Ce journal compare la capture du téléphone, la file locale et la confirmation de la base de données. <b id="gpsBuildVersion"></b></small>
</section>
<?php foreach($pods as $completedPod): ?><section class="mobile-card pod-complete-card"><span><i data-lucide="badge-check"></i></span><div><small>DESTINATION <?= (int)$completedPod['stop_order'] ?> · BON DE LIVRAISON</small><strong><?= htmlspecialchars($completedPod['label'].' · '.$completedPod['recipient_name'],ENT_QUOTES,'UTF-8') ?></strong><p><?= date('d/m/Y à H:i',strtotime($completedPod['captured_at'])) ?></p></div><a href="<?= $baseUrl ?>/deliveries/<?= (int)$mission['id'] ?>/destinations/<?= (int)$completedPod['destination_id'] ?>/pod.pdf" target="_blank"><i data-lucide="file-down"></i> PDF</a></section><?php endforeach; ?>
<section class="mission-actions" data-mission-id="<?= (int) $mission['id'] ?>">
    <?php if($nextAction): ?><div class="mission-next-action"><small>PROCHAINE ACTION</small><strong><?= htmlspecialchars($nextAction['label'],ENT_QUOTES,'UTF-8') ?></strong><p><?= $nextAction['action']==='start'?'Le suivi GPS sera activé au départ.':'Confirmez uniquement lorsque cette étape est réellement terminée.' ?></p></div><button class="primary" data-mission-action="<?= htmlspecialchars($nextAction['action'],ENT_QUOTES,'UTF-8') ?>"><i data-lucide="<?= htmlspecialchars($nextAction['icon'],ENT_QUOTES,'UTF-8') ?>"></i><?= htmlspecialchars($nextAction['label'],ENT_QUOTES,'UTF-8') ?></button><?php endif; ?>
    <?php if ($mission['status'] === 'Déchargement' && $mission['unloading_complete']): ?><button class="primary success" type="button" data-pod-open><i data-lucide="signature"></i>Faire signer et livrer</button><?php endif; ?>
    <?php if (!in_array($mission['status'], ['Incident', 'Livrée', 'Clôturée', 'Annulée'], true)): ?><button class="danger" type="button" data-incident-open><i data-lucide="triangle-alert"></i>Signaler un incident</button><?php endif; ?>
    <?php if ($mission['status']==='Incident'): ?><p class="waiting"><i data-lucide="clock-3"></i> La progression est suspendue pendant le traitement de l’incident par le bureau.</p><?php endif; ?>
</section>

<?php if ($mission['status'] === 'Déchargement' && $mission['unloading_complete']): ?>
<div class="pod-modal" id="podModal" hidden aria-hidden="true">
    <section class="pod-sheet" role="dialog" aria-modal="true" aria-labelledby="podTitle">
        <header><button type="button" data-pod-close aria-label="Fermer"><i data-lucide="x"></i></button><span>PREUVE DE LIVRAISON</span><h2 id="podTitle">Faire signer la réception</h2><p><?= htmlspecialchars($mission['reference'].' · '.$mission['company_name'], ENT_QUOTES, 'UTF-8') ?></p></header>
        <form id="podForm" data-mission-id="<?= (int) $mission['id'] ?>" data-destination-id="<?= (int)$mission['current_destination_id'] ?>">
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
