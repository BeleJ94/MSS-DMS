const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/notification-tests.js'), 'utf8');

async function scenario(options = {}) {
    const calls = [], dialogs = [], fields = {}, nodes = {};
    for (const name of ['search', 'delivery_id', 'event', 'destination_id', 'test_recipient', '_token']) {
        fields[name] = { value: name === 'event' ? 'dispatch' : name === 'test_recipient' ? 'only-test@example.com' : name === '_token' ? 'csrf-test' : '', disabled: false, handlers: {},
            addEventListener(event, handler) { this.handlers[event] = handler; }, replaceChildren() {}, add() {} };
    }
    const buttons = [{ disabled: true }, { disabled: true }];
    let submit;
    const form = { elements: fields, getAttribute(name) { return name === 'action' ? '/MSS-DMS/public/settings/mail/notifications' : '/MSS-DMS/public/settings/mail/notifications/data'; },
        querySelectorAll(selector) { return selector.startsWith('button') ? buttons : Object.values(fields).filter(field => !field.disabled); },
        reportValidity() { return true; }, setAttribute() {}, removeAttribute() {}, addEventListener(name, handler) { if (name === 'submit') submit = handler; } };
    const context = { document: { querySelector() { return form; }, getElementById(id) { return nodes[id] || (nodes[id] = { hidden: true, textContent: '', scrollIntoView() {} }); } },
        Option: function (label, value) { this.label = label; this.value = value; },
        FormData: class { constructor() { this.values = {}; Object.keys(fields).forEach(key => { if (!fields[key].disabled) this.values[key] = fields[key].value; }); } set(key, value) { this.values[key] = value; } },
        Swal: { close() {}, showLoading() {}, async fire(dialog) { dialogs.push(dialog); return { isConfirmed: options.confirm !== false }; } },
        setTimeout, clearTimeout, Error, TypeError,
        fetch: async (url, request) => {
            const operation = request.method === 'POST' ? request.body.values.operation : null;
            calls.push({ url, operation, values: operation ? { ...request.body.values } : null });
            let result;
            if (url.includes('?search=')) result = { success: true, deliveries: [{ id: 10, reference: 'LIV-10', company_name: 'Client test', status: 'En transit' }] };
            else if (url.includes('?delivery_id=')) result = { success: true, delivery: { reference: 'LIV-10', company_name: 'Client test', status: 'En transit' }, destinations: [{ id: 20, stop_order: 1, label: 'Dépôt', status: 'Arrivée', has_pod: 1 }], recipients: [{ full_name: 'Contact réel', email: 'client@example.com', notify_on: 'delivery' }], effective_recipients: { dispatch: [], arrival: { 20: [] }, delivery: { 20: [{ full_name:'Contact réel',email:'client@example.com' },{ full_name:'Dépôt',email:'site@example.com' }] } } };
            else {
                assert(buttons.every(button => button.disabled), 'les doubles soumissions sont bloquées');
                result = options.failPreview && operation === 'preview' ? { success: false, message: 'Destination introuvable' } : { success: true, simulated: !!options.simulated, recipient: 'only-test@example.com', preview: { subject: '[TEST] LIV-10', body: 'Message de test', html: '<html><body>Message professionnel</body></html>', attachment_name: 'Bon-LIV-10.pdf' }, message: 'Test accepté' };
            }
            return { status: result.success ? 200 : 422, ok: result.success, redirected: false, headers: { get() { return 'application/json'; } }, async json() { return result; } };
        }
    };
    vm.runInNewContext(source, context);
    await new Promise(resolve => setImmediate(resolve));
    fields.delivery_id.value = '10';
    await fields.delivery_id.handlers.change();
    fields.event.value = 'delivery';
    fields.event.handlers.change();
    assert(nodes['notification-context'].textContent.includes('client@example.com'), 'les abonnements client sont visibles');
    assert(nodes['notification-context'].textContent.includes('site@example.com'), 'les destinataires propres à la destination sont visibles');
    assert(nodes['notification-pod-help'].textContent.includes('PDF'), 'la présence du PDF est annoncée');
    const event = { preventDefault() {}, submitter: { value: options.sending ? 'send' : 'preview' } };
    const pending = submit(event); await submit(event); await pending;
    assert(buttons.every(button => !button.disabled), 'les actions sont réactivées');
    return { calls, dialogs, nodes, fields };
}

(async () => {
    let result = await scenario();
    assert.equal(result.calls.filter(call => call.operation === 'preview').length, 1);
    assert.equal(result.calls.filter(call => call.operation === 'send').length, 0, 'un aperçu n’envoie rien');
    assert.equal(result.nodes['notification-preview-subject'].textContent, '[TEST] LIV-10');
    assert(result.nodes['notification-preview-html'].srcdoc.includes('Message professionnel'), 'l’aperçu affiche la version HTML de l’e-mail');
    result = await scenario({ sending: true, confirm: false });
    assert.equal(result.calls.filter(call => call.operation === 'send').length, 0, 'une annulation n’envoie rien');
    result = await scenario({ sending: true });
    const sends = result.calls.filter(call => call.operation === 'send');
    assert.equal(sends.length, 1);
    assert.equal(sends[0].url, '/MSS-DMS/public/settings/mail/notifications');
    assert.equal(sends[0].values.test_recipient, 'only-test@example.com', 'seule l’adresse de test est soumise');
    assert.equal(sends[0].values._token, 'csrf-test');
    assert.equal(sends[0].values.destination_id, '20');
    result = await scenario({ sending: true, simulated: true });
    assert(result.dialogs.some(dialog => dialog.title === 'Simuler cette notification ?'));
    result = await scenario({ sending: true, failPreview: true });
    assert.equal(result.calls.filter(call => call.operation === 'send').length, 0, 'un aperçu invalide bloque l’envoi');
    assert.equal(result.dialogs.at(-1).text, 'Destination introuvable');
    assert.equal(result.fields.test_recipient.value, 'only-test@example.com');
    console.log('NOTIFICATION_TESTS_AJAX_OK: aperçu, confirmation, annulation, destinataire unique, PDF, CSRF, simulation et erreurs');
})().catch(error => { console.error(error); process.exitCode = 1; });
