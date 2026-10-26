(function () {
    'use strict';
    var form = document.querySelector('.mail-settings');
    if (!form) return;
    var busy = false;
    var lockedFields = [];
    var recipient = form.elements.test_recipient;
    // Only the test action requires a recipient; saving must not depend on that field.
    form.noValidate = true;

    function clearErrors() {
        form.querySelectorAll('[aria-invalid]').forEach(function (field) { field.removeAttribute('aria-invalid'); field.removeAttribute('aria-describedby'); });
        form.querySelectorAll('.mail-field-error').forEach(function (error) { error.remove(); });
    }

    function showErrors(errors) {
        Object.keys(errors || {}).forEach(function (name) {
            var field = form.elements.namedItem(name);
            if (!field || !field.closest) return;
            var error = document.createElement('small');
            error.className = 'mail-field-error';
            error.id = 'mail-error-' + name;
            error.textContent = errors[name];
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', error.id);
            field.closest('.field').appendChild(error);
        });
    }

    form.addEventListener('input', clearErrors);
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) return;
        var action = event.submitter ? event.submitter.value : 'save';
        var test = action === 'test';
        var simulation = form.elements.MAIL_DRIVER.value === 'log';
        clearErrors();
        // Ignore the optional test address when saving the configuration.
        recipient.disabled = !test;
        recipient.required = test;
        var valid = form.reportValidity();
        recipient.disabled = false;
        if (!valid) return;
        busy = true;
        try {
            var confirmation = await Swal.fire({
                icon: 'question', title: test ? (simulation ? 'Simuler l’envoi ?' : 'Envoyer l’e-mail de test ?') : 'Enregistrer la configuration e-mail ?',
                text: test ? (simulation ? 'Le message sera écrit dans le journal. Aucun e-mail ne sera envoyé.' : 'Un e-mail de test sera envoyé à ' + recipient.value + ' avec les paramètres saisis. Ils ne seront pas enregistrés.') : 'Ces paramètres seront utilisés pour les prochains envois de notifications client.',
                showCancelButton: true, confirmButtonText: test ? (simulation ? 'Simuler le test' : 'Envoyer le test') : 'Enregistrer',
                cancelButtonText: 'Annuler', reverseButtons: true, focusCancel: true, confirmButtonColor: '#245da8', customClass: { popup: 'mss-confirm-popup' }
            });
            if (!confirmation.isConfirmed) return;
            var payload = new FormData(form);
            payload.set('action', action);
            lockedFields = Array.from(form.querySelectorAll('input:not(:disabled), select:not(:disabled), button[type="submit"]:not(:disabled)'));
            lockedFields.forEach(function (field) { field.disabled = true; });
            form.setAttribute('aria-busy', 'true');
            Swal.fire({ title: test ? 'Test en cours…' : 'Enregistrement en cours…', text: test ? 'Connexion au serveur et vérification de l’envoi. Veuillez patienter.' : 'Sauvegarde de la configuration.', allowOutsideClick: false, allowEscapeKey: false, didOpen: function () { Swal.showLoading(); } });
            // Buttons named "action" mask HTMLFormElement.action in the browser.
            var endpoint = form.getAttribute('action');
            var response = await fetch(endpoint, { method: 'POST', body: payload, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.status === 419) throw new Error('Votre session de sécurité a expiré. Rechargez cette page avant de recommencer. Les paramètres saisis n’ont pas été enregistrés par cette requête.');
            if (response.status === 401 || response.redirected) throw new Error('Votre session a expiré. Reconnectez-vous avec un compte administrateur, puis recommencez.');
            if (response.status === 403) throw new Error('Accès refusé. Seul un administrateur peut modifier ces paramètres ou envoyer un test.');
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Le serveur a retourné une réponse inattendue (HTTP ' + response.status + '). Vérifiez les journaux PHP. Aucun succès ne peut être confirmé.');
            var result = await response.json();
            if (!response.ok || !result.success) {
                showErrors(result.errors);
                throw new Error(result.message || 'L’opération a échoué (HTTP ' + response.status + '). Vérifiez les paramètres et réessayez.');
            }
            if (!test) {
                form.elements.MAIL_PASSWORD.value = '';
                form.elements.MAIL_PASSWORD.placeholder = result.has_password ? 'Mot de passe enregistré' : 'Aucun mot de passe enregistré';
                form.elements.clear_password.checked = false;
            }
            await Swal.fire({ icon: result.simulated ? 'info' : 'success', title: test ? (result.simulated ? 'Simulation terminée' : 'E-mail accepté par le serveur') : 'Configuration enregistrée', text: result.message, confirmButtonText: 'Fermer', confirmButtonColor: '#245da8' });
        } catch (error) {
            var message = error instanceof TypeError ? 'Impossible de joindre l’application. Vérifiez votre connexion et la disponibilité du serveur. Le résultat de l’opération est inconnu ; vérifiez avant de relancer un test.' : error.message;
            await Swal.fire({ icon: 'error', title: test ? 'Le test n’a pas pu être confirmé' : 'Enregistrement non confirmé', text: message, confirmButtonText: 'Corriger les paramètres', confirmButtonColor: '#245da8' });
            var invalid = form.querySelector('[aria-invalid="true"]');
            if (invalid) invalid.focus();
        } finally {
            busy = false;
            form.removeAttribute('aria-busy');
            lockedFields.forEach(function (field) { field.disabled = false; });
            lockedFields = [];
            var firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
        }
    });
})();
