<?php
use App\Core\Csrf;
use App\Core\Env;
$escape=static function($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
$base=rtrim((string)Env::get('APP_URL',''),'/');
?>
<link rel="stylesheet" href="<?= $escape($base) ?>/assets/css/mail-settings.css?v=20261023-4">
<div class="page-heading"><div><span class="eyebrow">Administration · Configuration e-mail</span><h1>Tests des notifications de livraison</h1><p>Prévisualisez et testez les messages à partir d’une livraison existante.</p></div></div>
<nav class="mail-tabs" aria-label="Configuration e-mail"><a class="button button-secondary" href="<?= $escape($base) ?>/settings/mail">Configuration SMTP</a><a class="button button-primary" href="<?= $escape($base) ?>/settings/mail/notifications" aria-current="page">Tests des notifications de livraison</a><a class="button button-secondary" href="<?= $escape($base) ?>/settings/mail/history">Suivi des e-mails</a></nav>
<div class="mail-feedback <?= $driver==='log'?'mail-success':'' ?>">Configuration enregistrée : <strong><?= $driver==='log'?'Journal — simulation sans envoi':'SMTP — envoi d’un véritable e-mail' ?></strong>. Les messages portent la mention TEST. Les statuts et les notifications automatiques de la livraison restent inchangés.</div>
<form method="post" action="<?= $escape($base) ?>/settings/mail/notifications" class="notification-test-form mail-settings" data-no-confirm data-source="<?= $escape($base) ?>/settings/mail/notifications/data">
<?= Csrf::field() ?>
<article class="panel mail-panel"><div class="panel-head"><div><h2>Choisir un scénario</h2><p>Les tests utilisent la configuration SMTP enregistrée dans l’écran précédent.</p></div></div>
<div class="mail-grid">
<label class="field"><span>Rechercher une livraison</span><div><input name="search" type="search" placeholder="Référence ou nom du client" maxlength="100"></div><small>Les 100 livraisons les plus récentes correspondant à la recherche sont proposées.</small></label>
<label class="field"><span>Livraison</span><div><select name="delivery_id" required disabled><option value="">Chargement des livraisons…</option></select></div></label>
<label class="field"><span>Notification à tester</span><div><select name="event"><option value="dispatch">Départ de la livraison</option><option value="arrival">Arrivée à destination</option><option value="delivery">Livraison effectuée</option></select></div></label>
<label class="field"><span>Destination</span><div><select name="destination_id" disabled><option value="">Toutes les destinations pour le départ</option></select></div><small id="notification-pod-help">Choisissez une livraison pour voir ses destinations.</small></label>
<label class="field"><span>Adresse destinataire du test</span><div><input name="test_recipient" type="email" required maxlength="254" value="<?= $escape($recipient) ?>"></div><small>Seule cette adresse recevra le test, même si le client possède plusieurs contacts.</small></label>
</div>
<div id="notification-context" class="mail-help" role="status" aria-live="polite">Choisissez une livraison pour consulter ses contacts de notification.</div>
<div class="mail-actions"><button type="submit" name="operation" value="preview" class="button button-secondary" disabled>Aperçu du message</button> <button type="submit" name="operation" value="send" class="button button-primary" disabled>Envoyer un test</button></div>
</article>
<article class="panel mail-panel" id="notification-preview" hidden><div class="panel-head"><div><h2>Aperçu du message de test</h2><p id="notification-preview-to"></p></div></div><strong id="notification-preview-subject"></strong><p id="notification-preview-attachment" class="mail-help"></p><iframe id="notification-preview-html" class="mail-preview-frame" title="Aperçu de l’e-mail professionnel" sandbox="" referrerpolicy="no-referrer"></iframe><details class="mail-text-alternative"><summary>Consulter la version texte</summary><pre id="notification-preview-body" class="mail-preview-body"></pre></details></article>
</form>
