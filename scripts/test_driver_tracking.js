const fs = require('fs');
const vm = require('vm');

function expect(condition, message) {
    if (!condition) throw new Error(message);
    process.stdout.write('OK - ' + message + '\n');
}

const storage = {};
const indexedPositions = new Map();
let gpsCalls = 0;
let sentPayload = null;
const sentPayloads = [];
let watchSuccess = null;
const listeners = {};
const rejectedPositionIds = new Set();
const context = {
    console,
    Promise,
    Error,
    Date,
    Math,
    Object,
    Number,
    JSON,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    CustomEvent: function (name, options) { this.type = name; this.detail = options.detail; },
    MSS_DRIVER_APP: { baseUrl: '/mss', csrf: 'test-token', activeMissionId: 0 },
    isSecureContext: true,
    location: { hostname: 'delivery.example.test' },
    document: {
        head: { appendChild: function () {} },
        createElement: function () { return {}; },
        getElementById: function () { return null; }
    },
    localStorage: {
        getItem: key => storage[key] || null,
        setItem: (key, value) => { storage[key] = String(value); },
        removeItem: key => { delete storage[key]; }
    },
    indexedDB: { open: function () {
        const request = {};
        setTimeout(function () {
            request.result = {
                objectStoreNames: { contains: () => true },
                transaction: function () {
                    const tx = { error: null, objectStore: function () { return {
                        put: row => indexedPositions.set(row.position_id, Object.assign({}, row)),
                        delete: key => indexedPositions.delete(key),
                        getAll: function () { const read = {}; setTimeout(function () { read.result = Array.from(indexedPositions.values()); if (read.onsuccess) read.onsuccess(); }, 0); return read; }
                    }; } };
                    setTimeout(function () { if (tx.oncomplete) tx.oncomplete(); }, 0);
                    return tx;
                },
                close: function () {}
            };
            if (request.onsuccess) request.onsuccess();
        }, 0);
        return request;
    } },
    navigator: {
        onLine: true,
        permissions: { query: () => Promise.resolve({ state: 'denied', onchange: null }) },
        geolocation: {
            getCurrentPosition: function (success, failure, options) {
                gpsCalls++;
                if (options.enableHighAccuracy) {
                    failure({ code: 2 });
                    return;
                }
                success({ coords: { latitude: -11.6647 + (gpsCalls * 0.0001), longitude: 27.4794 + (gpsCalls * 0.0001), accuracy: 12, altitude: null, speed: null, heading: null }, timestamp: Date.now() });
            },
            watchPosition: function (success) { watchSuccess = success; return 9; },
            clearWatch: function () {}
        }
    },
    fetch: function (url, options) {
        sentPayload = { url, body: JSON.parse(options.body) };
        sentPayloads.push(sentPayload);
        const ids = sentPayload.body.positions.map(row => row.position_id);
        if (ids.some(id => rejectedPositionIds.has(id))) {
            return Promise.resolve({ ok: false, status: 422, json: () => Promise.resolve({ success: false, message: 'Ancienne position refusée.' }) });
        }
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data: { accepted: ids.length, duplicates: 0, total_positions: ids.length, recorded_ids: ids, duplicate_ids: [], persisted_ids: ids } }) });
    },
    addEventListener: function (name, callback) { listeners[name] = callback; },
    dispatchEvent: function () {}
};
context.window = context;
context.global = context;

vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/js/driver-tracking.js', 'utf8'), context);

(async function () {
    expect(await context.MSSGps.permissionState() === 'denied', 'l’état navigateur bloqué est détecté');
    const position = await context.MSSGps.prepare();
    expect(gpsCalls === 2 && Number.isFinite(position.coords.latitude), 'un GPS autorisé est relu avec le mode de secours');
    expect(await context.MSSGps.permissionState() === 'granted', 'un succès GPS corrige un état Permissions API obsolète');
    await context.MSSGps.begin(42, position);
    expect(context.MSSGps.isActive(), 'le tracking démarre pour la mission active');
    context.navigator.onLine = false;
    expect(await context.MSSGps.flush(), 'la file IndexedDB est relue et synchronisée');
    expect(indexedPositions.size === 0, 'la position confirmée est retirée d’IndexedDB');
    expect(sentPayload !== null, 'un faux état navigateur hors ligne ne bloque pas l’envoi');
    expect(sentPayload && sentPayload.url === '/mss/api/driver-app/missions/42/positions', 'la bonne API de mission est appelée');
    expect(sentPayload.body.positions.length === 1 && sentPayload.body._token === 'test-token', 'la position et le jeton CSRF sont envoyés');
    await watchSuccess({ coords: { latitude: -11.6631, longitude: 27.4812, accuracy: 9, altitude: 1249, speed: 4.2, heading: 40 }, timestamp: Date.now() + 1000 });
    await watchSuccess({ coords: { latitude: -11.6614, longitude: 27.4835, accuracy: 8, altitude: 1250, speed: 4.6, heading: 42 }, timestamp: Date.now() + 2000 });
    expect(await context.MSSGps.flush(), 'les notifications GPS rapprochées ne créent pas des rafales en base');
    let allSentPositions = sentPayloads.reduce((rows, payload) => rows.concat(payload.body.positions || []), []);
    expect(allSentPositions.length === 1, 'la cadence limite les captures à une position par période');
    const callsBeforePeriodicCapture = gpsCalls;
    expect(await context.MSSGps.captureNow(), 'une capture périodique est déclenchée même sans mouvement');
    expect(gpsCalls > callsBeforePeriodicCapture, 'la capture périodique interroge réellement la géolocalisation');
    expect(await context.MSSGps.flush(), 'la nouvelle position périodique est synchronisée');
    allSentPositions = sentPayloads.reduce((rows, payload) => rows.concat(payload.body.positions || []), []);
    expect(allSentPositions.length >= 2, 'la capture périodique ajoute un nouveau point');
    expect(new Set(allSentPositions.map(row => row.position_id)).size === allSentPositions.length, 'chaque position PWA possède un identifiant unique');
    rejectedPositionIds.add('old-rejected');
    indexedPositions.set('old-rejected', { mission_id: 1, position_id: 'old-rejected', latitude: -11.7, longitude: 27.4, accuracy: 8, captured_at: new Date(Date.now() - 60000).toISOString() });
    indexedPositions.set('current-valid', { mission_id: 42, position_id: 'current-valid', latitude: -11.6, longitude: 27.5, accuracy: 7, captured_at: new Date().toISOString() });
    expect(!(await context.MSSGps.flush()), 'la file signale encore la position réellement refusée');
    expect(indexedPositions.has('old-rejected'), 'la position refusée reste récupérable sur le téléphone');
    expect(!indexedPositions.has('current-valid'), 'une ancienne position refusée ne bloque plus les positions valides');
    context.MSSGps.stop();
    expect(!context.MSSGps.isActive(), 'le tracking s’arrête après la mission');
    process.stdout.write('DRIVER_TRACKING_OK\n');
})().catch(function (error) {
    console.error(error);
    process.exitCode = 1;
});
