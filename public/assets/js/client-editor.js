(function ($) {
    'use strict';
    var opener = null;
    var labels = { contacts:'Contact', sites:'Destination', recipients:'Destinataire' };
    function activate(form, tab) {
        form.find('[data-form-tab]').each(function () { var active=$(this).attr('data-form-tab')===tab; $(this).toggleClass('active',active).attr('aria-selected',String(active)).attr('tabindex',active?'0':'-1'); });
        form.find('[data-form-pane]').each(function () { $(this).toggleClass('active',$(this).attr('data-form-pane')===tab); });
    }
    function refresh(form) {
        form.find('[data-repeater]').each(function () {
            var repeater=$(this), group=repeater.attr('data-repeater'), count=0;
            repeater.find('.repeater-row').each(function (index) {
                var row=$(this), title=$.trim(row.find('[data-field="'+(group==='sites'?'name':'full_name')+'"]').val()||'');
                if(title) count++;
                if(!row.children('.client-row-head').length){
                    if(group==='sites'){
                        var grid=row.children('.repeater-grid');
                        ['name','site_code','address_line1','city','province','country','status','latitude','longitude','delivery_instructions','notification_emails'].forEach(function(key){grid.append(grid.find('[data-field="'+key+'"]').closest('.field'));});
                    }
                    var head=$('<header class="client-row-head">'),toggle=$('<button type="button" class="client-row-toggle" aria-expanded="true">');
                    toggle.append('<span class="client-row-number"></span><span class="client-row-label"><strong></strong><small></small></span><span class="client-row-chevron" aria-hidden="true">⌄</span>');
                    head.append(toggle).append(row.children('.remove-row')); row.prepend(head);
                    if(index>0) row.addClass('is-collapsed');
                }
                var meta=group==='sites'?[$.trim(row.find('[data-field="address_line1"]').val()||''),$.trim(row.find('[data-field="city"]').val()||'')].filter(Boolean).join(' · '):$.trim(row.find('[data-field="email"]').val()||'');
                row.find('.client-row-number').text(String(index+1).padStart(2,'0'));
                row.find('.client-row-label strong').text(title || (group==='sites'?'Nouvelle destination':'Nouveau '+labels[group].toLowerCase()));
                row.find('.client-row-label small').text(meta || (group==='sites'?'Adresse et destinataires de notification':'Coordonnées à compléter'));
                row.find('.client-row-toggle').attr('aria-expanded',String(!row.hasClass('is-collapsed')));
                row.find('.remove-row').attr('aria-label','Retirer '+labels[group].toLowerCase()+' '+(index+1));
            });
            form.find('[data-group-count="'+group+'"]').text(count);
        });
    }
    $('.client-modal [data-client-form]').each(function () {
        var form=$(this), id=form.attr('id');
        form.find('.form-tabs').attr('role','tablist');
        form.find('[data-form-tab]').each(function () { var key=$(this).attr('data-form-tab'); $(this).attr({role:'tab',id:id+'-tab-'+key,'aria-controls':id+'-pane-'+key}); });
        form.find('[data-form-pane]').each(function () { var key=$(this).attr('data-form-pane'); $(this).attr({role:'tabpanel',id:id+'-pane-'+key,'aria-labelledby':id+'-tab-'+key}); });
        form.find('[data-form-pane="sites"]>.section-title').after('<p class="client-editor-note">Chaque destination possède sa propre adresse et sa liste d’e-mails. Les notifications d’arrivée et de livraison seront adressées au site concerné, en plus des contacts généraux.</p>');
        form.find('[data-form-pane="notifications"]>.section-title').after('<p class="client-editor-note">Ces destinataires suivent les livraisons de tout le client. Les adresses propres à un site se renseignent dans l’onglet Destinations.</p>');
        form.closest('[role="dialog"]').attr('aria-labelledby',id+'-title').find('.modal-head h2').attr('id',id+'-title');
        activate(form,'general'); refresh(form);
        this.addEventListener('invalid',function(event){var field=$(event.target),row=field.closest('.repeater-row');row.removeClass('is-collapsed');activate(form,field.closest('[data-form-pane]').attr('data-form-pane'));refresh(form);},true);
    });
    $(document).on('click','.client-modal [data-form-tab]',function(){activate($(this).closest('form'),$(this).attr('data-form-tab'));});
    $(document).on('keydown','.client-modal [data-form-tab]',function(event){
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();var form=$(this).closest('form'),tabs=form.find('[data-form-tab]'),index=tabs.index(this);index=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;tabs.eq(index).trigger('click').trigger('focus');
    });
    $(document).on('click','.client-row-toggle',function(){var row=$(this).closest('.repeater-row');row.toggleClass('is-collapsed');$(this).attr('aria-expanded',String(!row.hasClass('is-collapsed')));});
    $(document).on('input change','.client-modal [data-client-form] input,.client-modal [data-client-form] textarea,.client-modal [data-client-form] select',function(){var form=$(this).closest('form');form.find('[data-client-edit-state]').text('Modifications non enregistrées');$(this).removeAttr('aria-invalid');refresh(form);});
    $(document).on('click','.client-modal [data-add-row],.client-modal .remove-row',function(){var form=$(this).closest('form'),adding=$(this).is('[data-add-row]'),group=$(this).attr('data-add-row');setTimeout(function(){refresh(form);form.find('[data-client-edit-state]').text('Modifications non enregistrées');if(adding){var row=form.find('[data-repeater="'+group+'"]>.repeater-row').last();row.removeClass('is-collapsed');row.find('.client-row-toggle').attr('aria-expanded','true');row.find('input:not([type="hidden"])').first().trigger('focus');}},0);});
    $(document).on('click','[data-modal-open]',function(){var modal=$('#'+$(this).attr('data-modal-open'));if(!modal.hasClass('client-modal'))return;opener=this;setTimeout(function(){modal.find('.form-pane.active input:not([type="hidden"])').first().trigger('focus');},0);});
    $(document).on('click','.client-modal [data-modal-close]',function(){if(opener)opener.focus();});
    $(document).on('keydown',function(event){var modal=$('.client-modal:not([hidden])');if(!modal.length || $('.swal2-container').length)return;if(event.key==='Escape'&&opener){opener.focus();return;}if(event.key!=='Tab')return;var items=modal.find('button,input,select,textarea,a[href]').filter(':visible:not(:disabled)').filter(function(){return $(this).attr('tabindex')!=='-1';}),first=items[0],last=items[items.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}});
    window.MSS_CLIENT_ERRORS=function(form,errors){
        form.find('.client-inline-error').remove();form.find('[aria-invalid]').removeAttr('aria-invalid');var target=null;
        Object.keys(errors||{}).forEach(function(key){var parts=key.split('.'),field=parts.length===3?form.find('[data-repeater="'+parts[0]+'"]>.repeater-row').eq(Number(parts[1])).find('[data-field="'+parts[2]+'"]'):form.find('[name="'+key+'"]');if(field.length){field.attr('aria-invalid','true');$('<small class="client-inline-error">').text(errors[key]).appendTo(field.closest('.field'));field.closest('.repeater-row').removeClass('is-collapsed');if(!target)target=field;}else if(key==='sites'){activate(form,'sites');form.find('[data-repeater="sites"]>.repeater-row').removeClass('is-collapsed');}});
        if(target){activate(form,target.closest('[data-form-pane]').attr('data-form-pane'));}refresh(form);
    };
})(jQuery);
