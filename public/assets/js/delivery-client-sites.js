(function ($) {
    'use strict';
    var base = (window.MSS_DMS && window.MSS_DMS.baseUrl) || '';
    function mailState(card) {
        var linked = !!card.find('[data-client-site-select]').val();
        card.find('[data-destination-field="notification_emails"]').prop('readOnly', linked);
        card.find('[data-destination-mail-help]').text(linked ? 'Destinataires du site client. Pour les modifier, ouvrez la fiche client ; les prochaines notifications utiliseront sa liste à jour.' : 'Adresses propres à cet arrêt : séparez-les par une virgule, un point-virgule ou un retour à la ligne.');
    }
    function options(form) {
        var sites = form.data('notification-sites') || [];
        form.find('[data-client-site-select]').each(function () {
            var select = $(this), selected = String(select.val() || select.attr('data-selected') || '');
            select.empty().append($('<option>').val('').text('Destination libre'));
            sites.forEach(function (site) { select.append($('<option>').val(site.id).text(site.name + ' · ' + site.city)); });
            if (selected && selected !== '0' && !sites.some(function (site) { return String(site.id) === selected; })) select.append($('<option>').val(selected).text('Site lié à cette destination (hors liste active)'));
            select.val(selected === '0' ? '' : selected).attr('data-selected', '0');
            var site = sites.find(function (row) { return String(row.id) === select.val(); });
            if (site) select.closest('[data-stop-card]').find('[data-destination-field="notification_emails"]').val(site.notification_emails || '');
            mailState(select.closest('[data-stop-card]'));
        });
    }
    function load(form, changed) {
        var client = String(form.find('[name="client_id"]').val() || '');
        var version = (form.data('sites-version') || 0) + 1; form.data('sites-version', version).data('notification-sites', []);
        if (changed) form.find('[data-stop-card]').each(function () { $(this).find('[data-client-site-select]').val('').attr('data-selected','0'); $(this).find('[data-destination-field="notification_emails"]').val(''); });
        if (changed) options(form);
        if (!client) { options(form); return; }
        form.find('[data-client-site-select]').prop('disabled',true);
        $.getJSON(base + '/api/deliveries/client/' + encodeURIComponent(client) + '/options').done(function (response) {
            if (form.data('sites-version') !== version) return;
            form.data('notification-sites', response.data.sites || []); options(form);
        }).fail(function () {
            if (form.data('sites-version') !== version) return;
            Swal.fire({ icon:'error', title:'Sites indisponibles', text:'Les sites du client n’ont pas pu être chargés. Rechargez la page avant de choisir un site enregistré.' });
        }).always(function () { if (form.data('sites-version') === version) form.find('[data-client-site-select]').prop('disabled',false); });
    }
    $('[data-delivery-form]').each(function () { load($(this), false); });
    $(document).on('change','[data-delivery-form] [name="client_id"]',function () { load($(this).closest('form'),true); });
    $(document).on('click','[data-add-stop]',function () { options($(this).closest('form')); });
    $(document).on('change','[data-client-site-select]',function () {
        var select=$(this), form=select.closest('form'), card=select.closest('[data-stop-card]');
        var site=(form.data('notification-sites') || []).find(function (row) { return String(row.id)===select.val(); });
        if (site) {
            var values={ label:site.name, address:site.address_line1, city:site.city, latitude:site.latitude, longitude:site.longitude, instructions:site.delivery_instructions, notification_emails:site.notification_emails };
            Object.keys(values).forEach(function (key) { card.find('[data-destination-field="'+key+'"]').val(values[key] || ''); });
        }
        mailState(card); card.find('[data-destination-field="label"]').trigger('change');
    });
})(jQuery);
