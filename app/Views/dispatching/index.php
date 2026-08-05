<script>window.MSS_DISPATCH_CAN_MANAGE=<?= $canManage?'true':'false' ?>;</script>
<div class="page-heading"><div><span class="eyebrow">Centre des opérations</span><h1>Dispatching</h1><p>Affectez rapidement les ressources disponibles aux prochaines livraisons.</p></div><div class="dispatch-live"><span></span> Disponibilité contrôlée en temps réel</div></div>

<section class="dispatch-kpis">
    <article><span><i data-lucide="inbox"></i></span><div><small>À traiter</small><strong id="dispatchTotal">—</strong></div></article>
    <article><span class="warning"><i data-lucide="user-round-x"></i></span><div><small>Non affectées</small><strong id="dispatchUnassigned">—</strong></div></article>
    <article><span class="success"><i data-lucide="circle-check"></i></span><div><small>Affectées</small><strong id="dispatchAssigned">—</strong></div></article>
    <article><span class="urgent"><i data-lucide="siren"></i></span><div><small>Urgentes</small><strong id="dispatchUrgent">—</strong></div></article>
</section>

<section class="panel dispatch-panel">
    <div class="dispatch-toolbar">
        <div class="toolbar-search"><i data-lucide="search"></i><input id="dispatchSearch" type="search" placeholder="Référence, client ou destination"></div>
        <select id="dispatchAssignmentFilter"><option value="">Toutes les affectations</option><option value="unassigned">Non affectées</option><option value="assigned">Affectées</option></select>
        <select id="dispatchPriorityFilter"><option value="">Toutes priorités</option><option>Basse</option><option>Normale</option><option>Haute</option><option>Urgente</option></select>
        <input type="date" id="dispatchDateFilter" title="Date prévue">
        <button class="button button-secondary" id="resetDispatchFilters"><i data-lucide="rotate-ccw"></i> Réinitialiser</button>
    </div>
    <div class="professional-table-wrap"><table id="dispatchTable" class="professional-table dispatch-table"><thead><tr><th>Livraison</th><th>Client / destination</th><th>Date prévue</th><th>Priorité</th><th>Chauffeur</th><th>Véhicule</th><th>Statut</th><th></th></tr></thead><tbody></tbody></table></div>
</section>

<?php if($canManage): ?><div class="modal-backdrop dispatch-modal" id="dispatchModal" hidden aria-hidden="true"><section class="modal-card dispatch-modal-card">
    <div class="modal-head"><div><span class="eyebrow">Affectation opérationnelle</span><h2 id="dispatchModalReference">Livraison</h2><p id="dispatchModalRoute"></p></div><button class="icon-button" type="button" data-modal-close><i data-lucide="x"></i></button></div>
    <form id="dispatchForm"><input type="hidden" name="delivery_id"><div class="dispatch-conflict" id="dispatchConflict" hidden><i data-lucide="triangle-alert"></i><div><strong>Conflit d’affectation</strong><p></p></div></div>
        <div class="dispatch-form-grid"><label class="dispatch-resource"><span>Chauffeur disponible</span><select name="driver_id" required><option value="">Sélectionner</option></select><small data-driver-detail>Sélectionnez un chauffeur.</small></label><span class="dispatch-link"><i data-lucide="arrow-right"></i></span><label class="dispatch-resource"><span>Véhicule disponible</span><select name="vehicle_id" required><option value="">Sélectionner</option></select><small data-vehicle-detail>Sélectionnez un véhicule.</small></label></div>
        <div class="dispatch-note"><i data-lucide="shield-check"></i><span>La disponibilité sera revérifiée au moment de l’enregistrement afin d’éviter toute double affectation.</span></div>
        <div class="modal-actions"><button type="button" class="button button-secondary" data-modal-close>Annuler</button><button type="submit" class="button button-primary"><i data-lucide="check"></i> Confirmer l’affectation</button></div>
    </form>
</section></div><?php endif; ?>
