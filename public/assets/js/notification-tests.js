(function () {
    'use strict';
    var form = document.querySelector('.notification-test-form');
    if (!form) return;
    var fields = form.elements;
    var busy = false, detail = null, listVersion = 0, detailVersion = 0, timer;
    var preview = document.getElementById('notification-preview');
    var context = document.getElementById('notification-context');
    var help = document.getElementById('notification-pod-help');

    function buttons() {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = busy || !detail; });
    }

    async function request(url, options) {
        var response = await fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }, options || {}));
        if (response.status === 401 || response.redirected) throw new Error('Votre session a expiré. Reconnectez-vous avec un compte administrateur.');
        if (response.status === 419) throw new Error('La session de sécurité a expiré. Rechargez la page avant de réessayer.');
        if (response.status === 403) throw new Error('Accès refusé : les tests sont réservés aux administrateurs.');
        if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Le serveur a retourné une réponse inattendue (HTTP ' + response.status + '). Aucun envoi ne peut être confirmé.');
        var result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'L’opération a échoué. Vérifiez les données et recommencez.');
        return result;
    }

    function errorDialog(error) {
        return Swal.fire({ icon: 'error', title: 'Test non confirmé', text: error instanceof TypeError ? 'La connexion à l’application a été interrompue. Le résultat est inconnu : vérifiez la réception du test avant de le renvoyer.' : error.message, confirmButtonText: 'Fermer', confirmButtonColor: '#245da8' });
    }

    function resetDetail() {
        detail = null; detailVersion++;
        fields.destination_id.replaceChildren(new Option('Choisissez une livraison', ''));
        fields.destination_id.disabled = true;
        context.textContent = 'Choisissez une livraison pour consulter ses contacts de notification.';
        help.textContent = 'Choisissez une livraison pour voir ses destinations.';
        preview.hidden = true; buttons();
    }

    function destinationState() {
        var departure = fields.event.value === 'dispatch';
        fields.destination_id.disabled = !detail || departure;
        fields.destination_id.required = !departure;
        if (!detail) return;
        var selected = detail.destinations.find(function (stop) { return String(stop.id) === fields.destination_id.value; });
        help.textContent = departure ? 'Le message de départ reprend toutes les destinations de la livraison.' : fields.event.value === 'delivery' ? (selected && Number(selected.has_pod) ? 'Le bon de livraison PDF sera joint au test.' : 'Aucun bon de livraison pour cette destination. Le test sera envoyé sans pièce jointe.') : 'Le message indiquera l’arrivée à cette destination.';
        var subscribed = detail.recipients.filter(function (recipient) { return (recipient.notify_on || '').split(',').map(function (event) { return event.trim(); }).includes(fields.event.value); });
        if (detail.effective_recipients) {
            subscribed = fields.event.value === 'dispatch' ? detail.effective_recipients.dispatch : (detail.effective_recipients[fields.event.value][fields.destination_id.value] || []);
        }
        context.textContent = detail.delivery.reference + ' · ' + detail.delivery.company_name + ' · Statut : ' + detail.delivery.status + '. Destinataires réels (contacts généraux et destination) : ' + (subscribed.length ? subscribed.map(function (recipient) { return recipient.full_name + ' <' + recipient.email + '>'; }).join(', ') : 'aucun') + '. Le test sera envoyé uniquement à l’adresse de test saisie.';
    }

    async function loadList() {
        var version = ++listVersion;
        resetDetail();
        fields.delivery_id.disabled = true;
        fields.delivery_id.replaceChildren(new Option('Chargement des livraisons…', ''));
        try {
            var result = await request(form.getAttribute('data-source') + '?search=' + encodeURIComponent(fields.search.value));
            if (version !== listVersion) return;
            fields.delivery_id.replaceChildren(new Option(result.deliveries.length ? 'Sélectionner une livraison' : 'Aucune livraison trouvée', ''));
            result.deliveries.forEach(function (delivery) { fields.delivery_id.add(new Option(delivery.reference + ' · ' + delivery.company_name + ' · ' + delivery.status, delivery.id)); });
            fields.delivery_id.disabled = false;
        } catch (error) {
            if (version !== listVersion) return;
            fields.delivery_id.replaceChildren(new Option('Recherche indisponible', ''));
            context.textContent = error.message;
            await errorDialog(error);
        }
    }

    fields.search.addEventListener('input', function () {
        clearTimeout(timer); listVersion++; resetDetail(); fields.delivery_id.disabled = true;
        timer = setTimeout(loadList, 350);
    });
    fields.delivery_id.addEventListener('change', async function () {
        resetDetail();
        if (!fields.delivery_id.value) return;
        var version = detailVersion;
        context.textContent = 'Chargement des destinations et des contacts…';
        try {
            var result = await request(form.getAttribute('data-source') + '?delivery_id=' + encodeURIComponent(fields.delivery_id.value));
            if (version !== detailVersion) return;
            detail = result;
            fields.destination_id.replaceChildren(new Option('Sélectionner une destination', ''));
            detail.destinations.forEach(function (stop) { fields.destination_id.add(new Option(stop.stop_order + '. ' + stop.label + ' · ' + stop.status, stop.id)); });
            if (detail.destinations.length) fields.destination_id.value = String(detail.destinations[0].id);
            destinationState(); buttons();
        } catch (error) { if (version === detailVersion) { context.textContent = error.message; await errorDialog(error); } }
    });
    fields.event.addEventListener('change', function () { preview.hidden = true; destinationState(); });
    fields.destination_id.addEventListener('change', function () { preview.hidden = true; destinationState(); });
    fields.test_recipient.addEventListener('input', function () { preview.hidden = true; });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !detail || !form.reportValidity()) return;
        var sending = event.submitter && event.submitter.value === 'send';
        var payload = new FormData(form);
        payload.set('operation', 'preview');
        var locked = Array.from(form.querySelectorAll('input:not(:disabled), select:not(:disabled)'));
        busy = true; buttons(); locked.forEach(function (field) { field.disabled = true; }); form.setAttribute('aria-busy', 'true');
        try {
            Swal.fire({ title: 'Préparation de l’aperçu…', allowOutsideClick: false, allowEscapeKey: false, didOpen: function () { Swal.showLoading(); } });
            var result = await request(form.getAttribute('action'), { method: 'POST', body: payload });
            preview.hidden = false;
            document.getElementById('notification-preview-to').textContent = 'Destinataire du test : ' + result.recipient;
            document.getElementById('notification-preview-subject').textContent = result.preview.subject;
            document.getElementById('notification-preview-body').textContent = result.preview.body;
            document.getElementById('notification-preview-html').srcdoc = result.preview.html;
            document.getElementById('notification-preview-attachment').textContent = result.preview.attachment_name ? 'Pièce jointe : ' + result.preview.attachment_name : 'Aucune pièce jointe.';
            Swal.close();
            if (!sending) { preview.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
            var confirmation = await Swal.fire({ icon: 'question', title: result.simulated ? 'Simuler cette notification ?' : 'Envoyer cette notification de test ?', text: result.preview.subject + '\n\nDestinataire unique : ' + result.recipient + '\n' + (result.preview.attachment_name ? 'Pièce jointe : ' + result.preview.attachment_name : 'Aucune pièce jointe.') + '\n\n' + (result.simulated ? 'Mode Journal : aucun e-mail ne sera envoyé.' : 'Un véritable e-mail marqué TEST sera envoyé à cette adresse.'), showCancelButton: true, confirmButtonText: result.simulated ? 'Simuler' : 'Envoyer le test', cancelButtonText: 'Annuler', reverseButtons: true, focusCancel: true, confirmButtonColor: '#245da8' });
            if (!confirmation.isConfirmed) return;
            payload.set('operation', 'send');
            Swal.fire({ title: 'Test en cours…', text: 'Veuillez patienter pendant le traitement du message.', allowOutsideClick: false, allowEscapeKey: false, didOpen: function () { Swal.showLoading(); } });
            result = await request(form.getAttribute('action'), { method: 'POST', body: payload });
            await Swal.fire({ icon: result.simulated ? 'info' : 'success', title: result.simulated ? 'Simulation terminée' : 'Test accepté par le serveur', text: result.message, confirmButtonText: 'Fermer', confirmButtonColor: '#245da8' });
        } catch (error) { await errorDialog(error); }
        finally { busy = false; locked.forEach(function (field) { field.disabled = false; }); form.removeAttribute('aria-busy'); destinationState(); buttons(); }
    });
    loadList();
})();
