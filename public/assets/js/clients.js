(function ($) {
    'use strict';
    var baseUrl = (window.MSS_DMS && window.MSS_DMS.baseUrl) || '';
    var token = window.MSS_CSRF || '';
    var table = null;

    function escapeHtml(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function statusLabel(status) { return { active: 'Actif', inactive: 'Inactif', prospect: 'Prospect', archived: 'Archivé' }[status] || status; }
    function statusClass(status) { return { active: 'success', inactive: 'neutral', prospect: 'info', archived: 'danger' }[status] || 'neutral'; }

    if ($('#clientsTable').length) {
        table = $('#clientsTable').DataTable({
            processing: true, paging: true, pageLength: 10, searching: false, lengthChange: false, order: [],
            ajax: function (data, callback) {
                $.getJSON(baseUrl + '/api/clients', { search: $('#clientSearch').val(), status: $('#clientStatusFilter').val(), city: $('#clientCityFilter').val() })
                    .done(function (response) {
                        var rows = response.data || [];
                        $('#clientsTotal').text(rows.length);
                        $('#clientsActive').text(rows.filter(function (row) { return row.status === 'active'; }).length);
                        $('#clientsSites').text(rows.reduce(function (sum, row) { return sum + Number(row.sites_count || 0); }, 0));
                        callback({ data: rows });
                    }).fail(function () { callback({ data: [] }); Swal.fire({ icon: 'error', title: 'Chargement impossible', text: 'La liste des clients ne peut pas être chargée.' }); });
            },
            columns: [
                { data: null, render: function (d,t,row) { return '<a class="client-name-cell" href="' + baseUrl + '/clients/' + row.id + '"><span>' + escapeHtml(row.company_name.substring(0,2).toUpperCase()) + '</span><div><strong>' + escapeHtml(row.company_name) + '</strong><small>' + escapeHtml(row.code) + '</small></div></a>'; } },
                { data: null, render: function(d,t,row){ return '<span class="stacked-cell"><strong>' + escapeHtml(row.email || '—') + '</strong><small>' + escapeHtml(row.phone || '—') + '</small></span>'; } },
                { data: 'city', defaultContent: '—', render: function(v){ return escapeHtml(v || '—'); } },
                { data: 'sites_count', render: function(v){ return '<span class="count-pill">' + Number(v || 0) + '</span>'; } },
                { data: 'contacts_count', render: function(v){ return '<span class="count-pill">' + Number(v || 0) + '</span>'; } },
                { data: 'status', render: function(v){ return '<span class="status-badge ' + statusClass(v) + '"><i></i>' + escapeHtml(statusLabel(v)) + '</span>'; } },
                { data: 'updated_at', render: function(v){ var date=new Date(String(v).replace(' ','T')); return isNaN(date.getTime())?'—':date.toLocaleDateString('fr-FR'); } },
                { data: null, orderable: false, render: function(d,t,row){ return '<a class="icon-button small" href="' + baseUrl + '/clients/' + row.id + '" aria-label="Consulter"><i data-lucide="arrow-up-right"></i></a>'; } }
            ],
            drawCallback: function(){ if(window.lucide){ window.lucide.createIcons({attrs:{'stroke-width':1.8}}); } },
            language: { processing:'Chargement…', emptyTable:'Aucun client trouvé', info:'_START_ à _END_ sur _TOTAL_ clients', infoEmpty:'Aucun client', paginate:{previous:'Précédent',next:'Suivant'} }
        });
        var searchTimer;
        $('#clientSearch').on('input', function(){ clearTimeout(searchTimer); searchTimer=setTimeout(function(){table.ajax.reload();},300); });
        $('#clientStatusFilter,#clientCityFilter').on('change', function(){ table.ajax.reload(); });
        $('#resetClientFilters').on('click', function(){ $('#clientSearch').val(''); $('#clientStatusFilter,#clientCityFilter').val(''); table.ajax.reload(); });
    }

    $(document).on('click','[data-form-tab]',function(){ var form=$(this).closest('form'); var tab=$(this).data('form-tab'); form.find('[data-form-tab]').removeClass('active'); $(this).addClass('active'); form.find('[data-form-pane]').removeClass('active'); form.find('[data-form-pane="'+tab+'"]').addClass('active'); });
    $(document).on('click','[data-detail-tab]',function(){ var panel=$(this).closest('.client-tabs-panel'); var tab=$(this).data('detail-tab'); panel.find('[data-detail-tab]').removeClass('active'); $(this).addClass('active'); panel.find('[data-detail-pane]').removeClass('active'); panel.find('[data-detail-pane="'+tab+'"]').addClass('active'); });

    $(document).on('click','[data-add-row]',function(){
        var name=$(this).data('add-row'); var repeater=$(this).closest('.form-pane').find('[data-repeater="'+name+'"]'); var row=repeater.find('.repeater-row').eq(0).clone();
        if (!row.length) { return; }
        row.find('input').val('').prop('checked',false); row.find('select').prop('selectedIndex',0);
        if(name==='sites'){row.find('[data-field="country"]').val('RDC');row.find('[data-field="status"]').val('active');}
        if(name==='recipients'){row.find('[data-field="notify_email"]').prop('checked',true);}
        repeater.append(row);
        if(window.lucide){window.lucide.createIcons({attrs:{'stroke-width':1.8}});}
    });
    $(document).on('click','.remove-row',function(){ var repeater=$(this).closest('.repeater'); if(repeater.find('.repeater-row').length>1){$(this).closest('.repeater-row').remove();}else{$(this).closest('.repeater-row').find('input').val('').prop('checked',false);} });

    function collectForm(form) {
        var data={contacts:[],sites:[],recipients:[]};
        form.find('[name]').each(function(){data[this.name]=$(this).val();});
        form.find('[data-repeater]').each(function(){ var group=$(this).data('repeater'); $(this).find('.repeater-row').each(function(){ var row={}; $(this).find('[data-field]').each(function(){ row[$(this).data('field')]=$(this).is(':checkbox')?$(this).is(':checked'):$(this).val(); }); var ignored={country:true,status:true,is_default:true,notify_email:true,notify_sms:true,notify_on:true}; var meaningful=Object.keys(row).some(function(key){return !ignored[key]&&row[key]!==''&&row[key]!==false;}); if(meaningful){data[group].push(row);} }); });
        data._token=token; return data;
    }

    $(document).on('submit','[data-client-form]',function(event){
        event.preventDefault(); var form=$(this); var id=Number(form.data('client-id')||0); form.find('[data-error]').text('');
        var submit=form.find('[type="submit"]').prop('disabled',true); var url=baseUrl+'/api/clients'+(id?'/'+id:'');
        $.ajax({url:url,method:'POST',contentType:'application/json',dataType:'json',data:JSON.stringify(collectForm(form))})
            .done(function(response){ Swal.fire({icon:'success',title:response.message,confirmButtonColor:'#245da8'}).then(function(){ if(response.redirect){window.location.href=response.redirect;}else{window.location.reload();} }); })
            .fail(function(xhr){ var response=xhr.responseJSON||{}; $.each(response.errors||{},function(key,message){form.find('[data-error="'+key+'"]').text(message);}); if(response.errors&&response.errors.sites){form.find('[data-form-tab]').removeClass('active');form.find('[data-form-tab="sites"]').addClass('active');form.find('[data-form-pane]').removeClass('active');form.find('[data-form-pane="sites"]').addClass('active');} Swal.fire({icon:'error',title:'Enregistrement impossible',text:response.message||'Une erreur est survenue.',confirmButtonColor:'#245da8'}); })
            .always(function(){submit.prop('disabled',false);});
    });

    $(document).on('click','[data-archive-client]',function(){ var id=$(this).data('archive-client'); Swal.fire({icon:'warning',title:'Archiver ce client ?',text:'Le client restera visible dans l’historique et les filtres.',showCancelButton:true,confirmButtonText:'Archiver',cancelButtonText:'Annuler',confirmButtonColor:'#a86f12'}).then(function(result){if(!result.isConfirmed)return; $.ajax({url:baseUrl+'/api/clients/'+id+'/archive',method:'POST',contentType:'application/json',dataType:'json',data:JSON.stringify({_token:token})}).done(function(response){Swal.fire({icon:'success',title:response.message,confirmButtonColor:'#245da8'}).then(function(){window.location.reload();});}).fail(function(xhr){Swal.fire({icon:'error',title:'Action impossible',text:(xhr.responseJSON||{}).message||'Une erreur est survenue.'});});}); });
})(jQuery);
