const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/mail-settings.js'), 'utf8');

async function scenario(options = {}) {
    const dialogs = [], requests = [], errors = [];
    const fields = {};
    ['test_recipient', 'MAIL_DRIVER', 'MAIL_PASSWORD', 'clear_password', '_token'].forEach(name => {
        fields[name] = { value: name === 'MAIL_DRIVER' ? 'smtp' : name === 'MAIL_PASSWORD' ? 'new-secret' : name === '_token' ? 'csrf-test' : 'recipient@example.com', disabled: false, checked: false,
            setAttribute() {}, removeAttribute() {}, focus() {}, closest() { return { appendChild(error) { errors.push(error.textContent); } }; } };
    });
    fields.namedItem = name => fields[name];
    const buttons = [{ disabled: false }, { disabled: false }];
    let submit;
    // The real form has two buttons named action: browser named access masks form.action.
    const form = { elements: fields, action: { toString() { return '[object RadioNodeList]'; } }, noValidate: false,
        getAttribute(name) { return name === 'action' ? '/MSS-DMS/public/settings/mail' : null; },
        querySelectorAll(selector) { return selector.includes('input:not') ? Object.values(fields).filter(field => typeof field === 'object').concat(buttons) : []; },
        querySelector() { return null; }, setAttribute() {}, removeAttribute() {}, reportValidity() { return true; },
        addEventListener(name, handler) { if (name === 'submit') submit = handler; }
    };
    const context = { document: { querySelector() { return form; }, createElement() { return {}; } },
        FormData: class { constructor() { this.values = { _token: fields._token.value, MAIL_PASSWORD: fields.MAIL_PASSWORD.value }; } set(key, value) { this.values[key] = value; } },
        Swal: { showLoading() {}, fire(dialog) { dialogs.push(dialog); return Promise.resolve({ isConfirmed: options.confirm !== false }); } },
        fetch: async (url, request) => {
            requests.push({ url, request });
            assert(buttons.every(button => button.disabled), 'les boutons sont bloqués pendant la requête');
            if (options.networkError) throw new TypeError('Failed to fetch');
            return { status: options.status || 200, ok: !options.status || options.status === 200, redirected: false,
                headers: { get() { return 'application/json'; } }, json: async () => options.result || { success: true, message: 'OK', has_password: true } };
        }, TypeError, Error, FormDataEvent: undefined
    };
    vm.runInNewContext(source, context);
    const event = { preventDefault() {}, submitter: { value: options.action || 'save' } };
    const first = submit(event);
    await submit(event); // A second click while the confirmation is open must be ignored.
    await first;
    assert(buttons.every(button => !button.disabled), 'les boutons sont réactivés après la requête');
    return { dialogs, requests, fields, errors };
}

(async () => {
    let result = await scenario({ confirm: false });
    assert.equal(result.requests.length, 0, 'annuler ne soumet rien');
    result = await scenario();
    assert.equal(result.requests.length, 1, 'une seule requête est envoyée');
    assert.equal(result.requests[0].url, '/MSS-DMS/public/settings/mail', 'l’URL du formulaire est utilisée même avec des boutons nommés action');
    assert.equal(result.requests[0].request.headers.Accept, 'application/json');
    assert.equal(result.requests[0].request.body.values.action, 'save');
    assert.equal(result.requests[0].request.body.values._token, 'csrf-test');
    assert.equal(result.fields.MAIL_PASSWORD.value, '', 'le secret est effacé du formulaire après sauvegarde');
    result = await scenario({ action: 'test' });
    assert.equal(result.fields.MAIL_PASSWORD.value, 'new-secret', 'un test conserve le secret saisi pour pouvoir enregistrer ensuite');
    result = await scenario({ status: 422, result: { success: false, message: 'Expéditeur invalide', errors: { test_recipient: 'Adresse invalide' } } });
    assert.equal(result.errors[0], 'Adresse invalide');
    assert.equal(result.dialogs.at(-1).text, 'Expéditeur invalide');
    assert.equal(result.fields.MAIL_PASSWORD.value, 'new-secret', 'un échec conserve la saisie');
    result = await scenario({ status: 419 });
    assert(result.dialogs.at(-1).text.includes('Rechargez cette page'));
    result = await scenario({ networkError: true });
    assert(result.dialogs.at(-1).text.includes('résultat de l’opération est inconnu'));
    console.log('MAIL_SETTINGS_AJAX_OK: annulation, confirmation, AJAX, doubles clics, secrets, erreurs de champ, session et réseau');
})().catch(error => { console.error(error); process.exitCode = 1; });
