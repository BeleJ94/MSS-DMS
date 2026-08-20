<?php use App\Core\Env;use App\Core\View;$baseUrl=rtrim((string)Env::get('APP_URL',''),'/'); ?>
<div class="delivery-create-page">
    <header class="create-page-head">
        <div><a class="back-link" href="<?= $baseUrl ?>/deliveries"><i data-lucide="arrow-left"></i> Retour aux livraisons</a><span class="eyebrow">Nouvelle opération</span><h1>Créer une livraison</h1><p>Préparez la course, ordonnez les destinations et répartissez les marchandises.</p></div>
        <div class="draft-reference"><small>Référence</small><strong>Générée automatiquement</strong></div>
    </header>
    <div class="create-layout">
        <aside class="create-steps" aria-label="Structure du formulaire">
            <div><span>1</span><section><strong>Course</strong><small>Client, date et priorité</small></section></div>
            <div><span>2</span><section><strong>Itinéraire</strong><small>Destinations ordonnées</small></section></div>
            <div><span>3</span><section><strong>Chargement</strong><small>Marchandises par arrêt</small></section></div>
            <div><span>4</span><section><strong>Ressources</strong><small>Chauffeur et véhicule</small></section></div>
            <p><i data-lucide="info"></i> La livraison sera créée en brouillon. Elle restera modifiable avant le départ.</p>
        </aside>
        <main class="create-form-panel panel"><?= View::partial('deliveries/_form',['formId'=>'createDeliveryForm','clients'=>$clients,'goodsCatalog'=>$goodsCatalog,'drivers'=>$drivers,'vehicles'=>$vehicles,'pageMode'=>true,'cancelUrl'=>$baseUrl.'/deliveries']) ?></main>
    </div>
</div>
