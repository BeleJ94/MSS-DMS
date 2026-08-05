const fs = require('fs');
const vm = require('vm');

function expect(condition, message) {
    if (!condition) throw new Error(message);
    process.stdout.write('OK - ' + message + '\n');
}

const storage = {};
let gpsCalls = 0;
let sentPayload = null;
const listeners = {};
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
    indexedDB: { open: function () { throw new Error('IndexedDB unavailable'); } },
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
                success({ coords: { latitude: -11.6647, longitude: 27.4794, accuracy: 12, altitude: null, speed: null, heading: null }, timestamp: Date.now() });
            },
            watchPosition: function () { return 9; },
            clearWatch: function () {}
        }
    },
    fetch: function (url, options) {
        sentPayload = { url, body: JSON.parse(options.body) };
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data: { accepted: 1 } }) });
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
    expect(gpsCalls === 2 && position.coords.latitude === -11.6647, 'un GPS autorisé est relu avec le mode de secours');
    expect(await context.MSSGps.permissionState() === 'granted', 'un succès GPS corrige un état Permissions API obsolète');
    await context.MSSGps.begin(42, position);
    expect(context.MSSGps.isActive(), 'le tracking démarre pour la mission active');
    expect(await context.MSSGps.flush(), 'la synchronisation fonctionne sans IndexedDB');
    expect(sentPayload && sentPayload.url === '/mss/api/driver-app/missions/42/positions', 'la bonne API de mission est appelée');
    expect(sentPayload.body.positions.length === 1 && sentPayload.body._token === 'test-token', 'la position et le jeton CSRF sont envoyés');
    context.MSSGps.stop();
    expect(!context.MSSGps.isActive(), 'le tracking s’arrête après la mission');
    process.stdout.write('DRIVER_TRACKING_OK\n');
})().catch(function (error) {
    console.error(error);
    process.exitCode = 1;
});
