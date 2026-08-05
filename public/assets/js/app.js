(function ($) {
    'use strict';

    if (window.lucide) {
        window.lucide.createIcons({ attrs: { 'stroke-width': 1.8 } });
    }

    function confirmationCopy(form) {
        var $form=$(form), title='Confirmer l\u2019enregistrement ?', text='Vérifiez les informations avant de continuer.', button='Confirmer';
        if($form.is('[data-client-form]')){title='Enregistrer ce client ?';text='Les informations, contacts et sites de livraison seront mis à jour.';button='Enregistrer';}
        else if($form.is('[data-driver-form]')){title='Enregistrer ce chauffeur ?';button='Enregistrer';}
        else if($form.is('[data-vehicle-form]')){title='Enregistrer ce véhicule ?';button='Enregistrer';}
        else if($form.is('[data-goods-form]')){title='Enregistrer cette marchandise ?';button='Enregistrer';}
        else if($form.is('[data-delivery-form]')){title='Enregistrer cette livraison ?';text='La destination, les marchandises et les affectations seront enregistrées.';button='Enregistrer';}
        else if($form.is('[data-transition-form]')){title='Confirmer le changement de statut ?';text='Cette transition sera horodatée et ajoutée à la timeline.';button='Changer le statut';}
        else if($form.is('#incidentUpdateForm')){title='Enregistrer ce traitement ?';text='Le responsable et l’action corrective seront mis à jour.';button='Enregistrer';}
        else if($form.is('#incidentResolveForm')){title='Résoudre cet incident ?';text='La résolution sera horodatée et la livraison reprendra son workflow.';button='Marquer comme résolu';}
        else if($form.is('#dispatchForm')){title='Confirmer cette affectation ?';text='Le chauffeur et le véhicule seront réservés pour cette livraison.';button='Affecter';}
        else if($form.is('[data-document-form]')){title='Ajouter ce document ?';button='Ajouter';}
        else if($form.hasClass('logout-form')){title='Se déconnecter ?';text='Votre session MSS-DMS sera fermée.';button='Se déconnecter';}
        else if($form.hasClass('inline-form')){title='Confirmer ce changement ?';text='Le statut du compte utilisateur sera modifié.';}
        else if($form.closest('#userModal').length){title='Créer ce compte utilisateur ?';button='Créer le compte';}
        return {title:title,text:text,button:button};
    }

    document.addEventListener('submit',function(event){
        var form=event.target;if(!(form instanceof HTMLFormElement)||form.hasAttribute('data-no-confirm'))return;
        if(form.__mssSubmissionConfirmed){form.__mssSubmissionConfirmed=false;return;}
        event.preventDefault();event.stopImmediatePropagation();var copy=confirmationCopy(form),submitter=event.submitter;
        Swal.fire({icon:'question',title:copy.title,text:copy.text,showCancelButton:true,confirmButtonText:copy.button,cancelButtonText:'Annuler',confirmButtonColor:'#245da8',cancelButtonColor:'#667386',reverseButtons:true,focusCancel:true,customClass:{popup:'mss-confirm-popup'}}).then(function(result){
            if(!result.isConfirmed)return;form.__mssSubmissionConfirmed=true;if(form.requestSubmit){if(submitter&&submitter.form===form){form.requestSubmit(submitter);}else{form.requestSubmit();}}else{form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}
        });
    },true);

    $('#menuToggle').on('click', function () {
        $('#sidebar').toggleClass('open');
        $('#sidebarOverlay').prop('hidden', !$('#sidebar').hasClass('open'));
    });

    $('#sidebarOverlay').on('click', function () {
        $('#sidebar').removeClass('open');
        $(this).prop('hidden', true);
    });

    $('#notificationToggle').on('click', function (event) {
        event.stopPropagation();
        var $popover = $('#notificationPopover');
        var willOpen = $popover.prop('hidden');
        $popover.prop('hidden', !willOpen);
        $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.notification-wrap').length) {
            $('#notificationPopover').prop('hidden', true);
            $('#notificationToggle').attr('aria-expanded', 'false');
        }
    });

    $('[data-modal-open]').on('click', function () {
        var id = $(this).attr('data-modal-open');
        $('#' + id).prop('hidden', false).attr('aria-hidden', 'false');
    });

    $('[data-modal-close]').on('click', function () {
        $(this).closest('.modal-backdrop').prop('hidden', true).attr('aria-hidden', 'true');
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            $('.modal-backdrop').prop('hidden', true).attr('aria-hidden', 'true');
            $('#notificationPopover').prop('hidden', true);
        }
    });

    $('[data-demo-form]').on('submit', function (event) {
        event.preventDefault();
        $(this).closest('.modal-backdrop').prop('hidden', true).attr('aria-hidden', 'true');
        Swal.fire({ toast: true, position: 'top-end', timer: 3000, showConfirmButton: false, icon: 'success', title: 'Brouillon prêt à être complété' });
    });

    $('#systemCheck').on('click', function () {
        var baseUrl = (window.MSS_DMS && window.MSS_DMS.baseUrl) || '';
        $.ajax({ url: baseUrl + '/api/health', dataType: 'json' })
            .done(function (response) {
                Swal.fire({ icon: 'success', title: 'Système opérationnel', text: 'Application et base de données accessibles.', confirmButtonColor: '#2563eb' });
            })
            .fail(function (xhr) {
                var data = xhr.responseJSON || {};
                Swal.fire({ icon: 'warning', title: 'Configuration incomplète', text: data.database === 'unavailable' ? 'L’application fonctionne, mais la base de données doit être configurée.' : 'Le contrôle de santé a échoué.', confirmButtonColor: '#2563eb' });
            });
    });

    if (window.MSS_FLASH) {
        Swal.fire({ toast: true, position: 'top-end', timer: 3500, timerProgressBar: true, showConfirmButton: false, icon: window.MSS_FLASH.type, title: window.MSS_FLASH.message });
    }

    if ($.fn.DataTable && $('#usersTable').length) {
        $('#usersTable').DataTable({ pageLength: 10, order: [[4, 'desc']], language: { search: 'Rechercher :', lengthMenu: 'Afficher _MENU_', info: '_START_ à _END_ sur _TOTAL_', paginate: { previous: 'Précédent', next: 'Suivant' }, zeroRecords: 'Aucun utilisateur trouvé' } });
    }

    $('#openUserModal').on('click', function () {
        var form = $('#userForm'), baseUrl = (window.MSS_DMS && window.MSS_DMS.baseUrl) || '';
        if (form.length) {
            form[0].reset(); form.attr('action', baseUrl + '/users');
            form.find('[name="password"]').prop('required', true);
            $('#userModalTitle').text('Nouvel utilisateur');
            $('#userModalDescription').text('Créez un compte et attribuez son rôle initial.');
            $('#userPasswordLabel').text('Mot de passe initial'); $('#userPasswordHelp').prop('hidden', true);
            $('#userSubmitLabel').text('Créer le compte');
        }
        $('#userModal').prop('hidden', false).attr('aria-hidden', 'false');
    });
    $(document).on('click', '.edit-user-button', function () {
        var button = $(this), form = $('#userForm'), baseUrl = (window.MSS_DMS && window.MSS_DMS.baseUrl) || '';
        form[0].reset(); form.attr('action', baseUrl + '/users/' + button.data('user-id'));
        form.find('[name="name"]').val(button.attr('data-user-name'));
        form.find('[name="email"]').val(button.attr('data-user-email'));
        form.find('[name="role_id"]').val(String(button.data('user-role')));
        form.find('[name="password"]').val('').prop('required', false);
        $('#userModalTitle').text('Modifier l’utilisateur');
        $('#userModalDescription').text('Mettez à jour les informations, le rôle ou les accès du compte.');
        $('#userPasswordLabel').text('Nouveau mot de passe'); $('#userPasswordHelp').prop('hidden', false);
        $('#userSubmitLabel').text('Enregistrer les modifications');
        $('#userModal').prop('hidden', false).attr('aria-hidden', 'false');
    });
    $('.modal-close').on('click', function () { $('#userModal').prop('hidden', true).attr('aria-hidden', 'true'); });
})(jQuery);
