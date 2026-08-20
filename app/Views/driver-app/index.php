<?php $stageClasses=['Brouillon'=>'assigned','Affectée'=>'assigned','À préparer'=>'preparation','Prête'=>'ready','Chargement'=>'loading','Chargée'=>'loaded','Partie'=>'transit','En transit'=>'transit','Arrivée'=>'arrived','Déchargement'=>'unloading','Incident'=>'incident','Livrée'=>'completed','Clôturée'=>'completed']; ?>
<section class="mobile-intro"><span><?= date('d/m/Y') ?></span><h1>Mes missions</h1><p>Gérez séparément les étapes de chaque livraison qui vous est affectée.</p></section>
<?php if(!$driver): ?><section class="mobile-empty warning"><i data-lucide="user-round-x"></i><h2>Compte non associé</h2><p>Demandez à l’administrateur de relier votre compte à votre fiche chauffeur.</p></section><?php elseif(!$missions): ?><section class="mobile-empty"><i data-lucide="check-circle-2"></i><h2>Aucune mission</h2><p>Vos prochaines affectations apparaîtront ici.</p></section><?php else: ?>
<div class="mission-list"><?php foreach($missions as $mission): ?>
<?php $nextAction=App\Models\DriverMission::nextAction($mission['status']);if($nextAction&&$nextAction['action']==='arrive'){$nextAction['label']='Arrivée destination '.((int)($mission['delivered_destination_count']??0)+1).'/'.(int)($mission['destination_count']??1).' · '.($mission['site_name']?:'Prochain arrêt');} ?>
<article class="mission-card stage-<?= $stageClasses[$mission['status']]??'assigned' ?> <?= in_array($mission['status'],['Livrée','Clôturée'],true)?'completed':'' ?>" data-mission-id="<?= (int)$mission['id'] ?>">
    <a class="mission-card-link" href="<?= $baseUrl ?>/driver-app/missions/<?= (int)$mission['id'] ?>">
        <div class="mission-card-top"><strong><?= htmlspecialchars($mission['reference'],ENT_QUOTES,'UTF-8') ?></strong><span class="mobile-status"><?= htmlspecialchars($mission['status'],ENT_QUOTES,'UTF-8') ?></span></div>
        <h2><?= htmlspecialchars($mission['company_name'],ENT_QUOTES,'UTF-8') ?></h2>
        <p><i data-lucide="map-pin"></i><?= htmlspecialchars(($mission['site_name']?:'Itinéraire terminé').($mission['city']?', '.$mission['city']:''),ENT_QUOTES,'UTF-8') ?></p>
        <div class="mission-progress"><span><b><?= (int)($mission['delivered_destination_count']??0) ?></b>/<?= (int)($mission['destination_count']??0) ?> destination(s) livrée(s)</span></div>
        <div class="mission-card-foot"><span><i data-lucide="calendar-clock"></i><?= date('d/m à H:i',strtotime($mission['scheduled_at'])) ?></span><b class="priority-<?= mb_strtolower($mission['priority']) ?>"><?= htmlspecialchars($mission['priority'],ENT_QUOTES,'UTF-8') ?></b><i data-lucide="chevron-right"></i></div>
    </a>
    <div class="mission-card-actions">
        <?php if($nextAction): ?><button type="button" class="mission-quick-action primary" data-mission-action="<?= htmlspecialchars($nextAction['action'],ENT_QUOTES,'UTF-8') ?>"><i data-lucide="<?= htmlspecialchars($nextAction['icon'],ENT_QUOTES,'UTF-8') ?>"></i> <?= htmlspecialchars($nextAction['label'],ENT_QUOTES,'UTF-8') ?></button>
        <?php elseif($mission['status']==='Arrivée'): ?><a class="mission-quick-action primary" href="<?= $baseUrl ?>/driver-app/missions/<?= (int)$mission['id'] ?>"><i data-lucide="package-open"></i> Décharger</a>
        <?php elseif($mission['status']==='Déchargement'): ?><a class="mission-quick-action success" href="<?= $baseUrl ?>/driver-app/missions/<?= (int)$mission['id'] ?>"><i data-lucide="list-checks"></i> Contrôler</a>
        <?php elseif($mission['status']==='Incident'): ?><a class="mission-quick-action danger" href="<?= $baseUrl ?>/driver-app/missions/<?= (int)$mission['id'] ?>"><i data-lucide="triangle-alert"></i> Voir l’incident</a>
        <?php else: ?><a class="mission-quick-action neutral" href="<?= $baseUrl ?>/driver-app/missions/<?= (int)$mission['id'] ?>"><i data-lucide="eye"></i> Consulter</a><?php endif; ?>
    </div>
</article>
<?php endforeach; ?></div><?php endif; ?>
