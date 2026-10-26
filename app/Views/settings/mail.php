<?php
use App\Core\Csrf;
use App\Core\Env;
$escape=static function($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
$base=rtrim((string)Env::get('APP_URL',''),'/');
?>
<link rel="stylesheet" href="<?= $escape($base) ?>/assets/css/mail-settings.css?v=20261023-4">
<div class="page-heading"><div><span class="eyebrow">Administration</span><h1>Configuration e-mail</h1><p>Configurez l’expéditeur des notifications client et vérifiez l’envoi.</p></div></div>
<nav class="mail-tabs" aria-label="Configuration e-mail"><a class="button button-primary" href="<?= $escape($base) ?>/settings/mail" aria-current="page">Configuration SMTP</a><a class="button button-secondary" href="<?= $escape($base) ?>/settings/mail/notifications">Tests des notifications de livraison</a></nav>
<?php if($success): ?><div class="mail-feedback mail-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="mail-feedback mail-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<form method="post" action="<?= $escape($base) ?>/settings/mail" class="mail-settings" data-no-confirm>
<?= Csrf::field() ?>
<article class="panel mail-panel">
<div class="panel-head"><div><h2>Serveur d’envoi</h2><p>Ces paramètres s’appliquent à toutes les notifications e-mail.</p></div></div>
<div class="mail-grid">
<label class="field"><span>Mode d’envoi</span><div><select name="MAIL_DRIVER"><option value="smtp" <?= $settings['MAIL_DRIVER']==='smtp'?'selected':'' ?>>SMTP — envoyer des e-mails</option><option value="log" <?= $settings['MAIL_DRIVER']==='log'?'selected':'' ?>>Journal — simuler sans envoyer</option></select></div></label>
<label class="field"><span>Chiffrement</span><div><select name="MAIL_ENCRYPTION"><?php foreach(['tls'=>'STARTTLS (généralement port 587)','ssl'=>'SSL/TLS (généralement port 465)','none'=>'Sans chiffrement'] as $value=>$label): ?><option value="<?= $value ?>" <?= $settings['MAIL_ENCRYPTION']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div></label>
<label class="field"><span>Serveur SMTP</span><div><input name="MAIL_HOST" value="<?= $escape($settings['MAIL_HOST']) ?>" placeholder="smtp.exemple.com" maxlength="253" autocomplete="off"></div></label>
<label class="field"><span>Port</span><div><input name="MAIL_PORT" type="number" min="1" max="65535" required value="<?= $escape($settings['MAIL_PORT']) ?>"></div></label>
<label class="field"><span>Identifiant SMTP</span><div><input name="MAIL_USERNAME" value="<?= $escape($settings['MAIL_USERNAME']) ?>" autocomplete="off" maxlength="1000"></div><small>Laissez vide si le serveur ne demande pas d’authentification.</small></label>
<label class="field"><span>Mot de passe SMTP</span><div><input name="MAIL_PASSWORD" type="password" autocomplete="new-password" maxlength="1000" placeholder="<?= $hasPassword?'Mot de passe enregistré':'Aucun mot de passe enregistré' ?>"></div><small>Laissez vide pour conserver le mot de passe enregistré.</small></label>
<label class="field"><span>Adresse de l’expéditeur</span><div><input name="MAIL_FROM_ADDRESS" type="email" required value="<?= $escape($settings['MAIL_FROM_ADDRESS']) ?>" placeholder="notifications@exemple.com" maxlength="254"></div></label>
<label class="field"><span>Nom de l’expéditeur</span><div><input name="MAIL_FROM_NAME" value="<?= $escape($settings['MAIL_FROM_NAME']) ?>" maxlength="160"></div></label>
</div>
<label class="mail-checkbox"><input type="checkbox" name="clear_password" value="1"> Supprimer le mot de passe enregistré</label>
<div class="mail-actions"><button type="submit" name="action" value="save" class="button button-primary"><i data-lucide="save"></i> Enregistrer la configuration</button></div>
</article>
<article class="panel mail-panel"><div class="panel-head"><div><h2>Tester l’envoi</h2><p>Le test utilise les paramètres saisis ci-dessus, sans les enregistrer.</p></div></div>
<div class="mail-test"><label class="field"><span>Adresse du destinataire de test</span><div><input name="test_recipient" type="email" value="<?= $escape($recipient) ?>" placeholder="votre.adresse@exemple.com" maxlength="254"></div></label><button type="submit" name="action" value="test" class="button button-secondary"><i data-lucide="send"></i> Envoyer un test</button></div>
<p class="mail-help">En mode Journal, le test est simulé et aucun message ne quitte le serveur. En mode SMTP, l’acceptation du message par le serveur ne garantit pas son arrivée dans la boîte de réception.</p>
</article>
</form>
