// Tells the traffic tracker that a page ran in a real browser: the arrival
// token the server printed goes back once, after a prerendered page is
// actually shown — and again for each page a wire:navigate move brings in
// (a profile, a category, a tag), whose head carries its own token. Each
// token goes back once, however often its page is shown. A game page sends
// it through its own first call instead. A sign-up dialog calls
// rgTrafficSignal('signup_form') when it opens — with { post: id } on a
// post's page (resources/js/auth-modal.js) — and a page that shows a
// sign-in or sign-up form names that signal on an element of its own
// (data-rg-traffic-signal), sent once as the page is in; a post's share buttons call
// rgTrafficSignal('post_shared', { post: id }) for a share that never passes
// the server — by email, a copied link or the system's share sheet. Those
// wait until the page's own token is back, since the visit they go on may
// be the one it makes, and until a prerendered page is shown.
var rgTrafficOpening = Promise.resolve();

function rgTrafficSend(signal, arrival, fields) {
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var body = new URLSearchParams({ signal: signal });

    if (arrival) {
        body.set('arrival', arrival);
    }

    Object.keys(fields || {}).forEach(function (name) {
        body.set(name, String(fields[name]));
    });

    try {
        return fetch('/visit/signal', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'X-CSRF-TOKEN': csrf ? csrf.content : '', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
        }).catch(function () {});
    } catch (e) {
        return Promise.resolve();
    }
}

// The signals of a page go one after another, each once the one before has its answer, so the tracker reads them in
// the order they happened: the first thing that opened the sign-in dialog stays the first, whatever the network does.
var rgTrafficSignals = Promise.resolve();

window.rgTrafficSignal = function (signal, fields) {
    var send = function () {
        rgTrafficSignals = rgTrafficSignals
            .then(function () {
                return rgTrafficOpening;
            })
            .then(function () {
                return rgTrafficSend(signal, null, fields);
            })
            .catch(function () {});
    };

    if (document.prerendering) {
        document.addEventListener('prerenderingchange', send, { once: true });
    } else {
        send();
    }
};

var rgTrafficSentArrivals = {};

function rgTrafficOpened() {
    var meta = document.querySelector('meta[name="rg-arrival"]');

    if (document.prerendering || !meta || !meta.content || meta.dataset.sent || rgTrafficSentArrivals[meta.content]) {
        return;
    }

    meta.dataset.sent = '1';
    rgTrafficSentArrivals[meta.content] = true;
    rgTrafficOpening = rgTrafficSend('opened', meta.content);
}

function rgTrafficPageSignals() {
    document.querySelectorAll('[data-rg-traffic-signal]:not([data-sent])').forEach(function (element) {
        element.dataset.sent = '1';

        // A standalone sign-in or sign-up page names the state it shows and itself as what showed it.
        const fields = element.dataset.rgTrafficTrigger
            ? { mode: element.dataset.rgTrafficMode, trigger: element.dataset.rgTrafficTrigger }
            : undefined;

        window.rgTrafficSignal(element.dataset.rgTrafficSignal, fields);
    });
}

function rgTrafficPageIn() {
    rgTrafficOpened();
    rgTrafficPageSignals();
}

if (document.prerendering) {
    document.addEventListener('prerenderingchange', rgTrafficPageIn, { once: true });
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rgTrafficPageIn, { once: true });
} else {
    rgTrafficPageIn();
}

// Livewire fires it once a page is in, the first one included: a page a
// move brought in has replaced the head, and with it the token.
document.addEventListener('livewire:navigated', rgTrafficPageIn);

// What an arriving page's visitor sees and does, while Traffic sources records
// it (the server prints <meta name="rg-presence"> on such a page): whether the
// page was on screen when it began and how long until it was — a popunder
// loads behind the window — how long it stayed on screen, the first touch,
// click, key or wheel the browser itself made (isTrusted) and how long after
// the page was first on screen, inputs a script made instead, and input while
// hidden; and, once, a few facts of the browser a server compares with what it
// says it is: its time zone, languages and system, its screen and window, its
// touch points, cores and memory, whether WebDriver or another automation
// tool drives it (BotD, loaded only here and told not to call home), whether
// the page is inside another site's frame, and whether its graphics are drawn
// by software — a class, never the renderer's name. No address, no canvas or
// audio print, nothing stored in the browser. It reports 3 seconds after the
// page is first on screen, half a second after the first input, after 15
// seconds whatever happened, and when the page is hidden or left — at most
// four times, and only when something changed.
var rgPresence = null;

function rgPresenceGraphics() {
    try {
        var canvas = document.createElement('canvas');
        var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

        if (!gl) {
            return 'none';
        }

        var info = gl.getExtension('WEBGL_debug_renderer_info');
        var renderer = String(info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER));
        var lose = gl.getExtension('WEBGL_lose_context');

        if (lose) {
            lose.loseContext();
        }

        return /swiftshader|llvmpipe|softpipe|software|basic render/i.test(renderer) ? 'software' : 'hw';
    } catch (e) {
        return 'none';
    }
}

function rgPresenceAutomation() {
    var marks = 0;

    try {
        marks |= window._phantom || window.callPhantom ? 1 : 0;
        marks |= window.__nightmare ? 2 : 0;
        marks |= window.domAutomation || window.domAutomationController ? 4 : 0;
        marks |= window.Cypress ? 8 : 0;
        marks |= Object.keys(window).some(function (key) { return /^(__playwright|__pw|\$?cdc_)/.test(key); }) ? 16 : 0;
        marks |= document.documentElement.getAttribute('webdriver') !== null ? 32 : 0;
    } catch (e) {}

    return marks;
}

function rgPresenceBrowser() {
    var framed = false;

    try {
        framed = window.top !== window.self;
    } catch (e) {
        framed = true;
    }

    var zone = '';

    try {
        zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {}

    return {
        wd: navigator.webdriver ? 1 : 0,
        auto: rgPresenceAutomation(),
        frm: framed ? 1 : 0,
        gl: rgPresenceGraphics(),
        tz: zone,
        tzo: new Date().getTimezoneOffset(),
        langs: (navigator.languages || [navigator.language || '']).slice(0, 2).join(','),
        plat: navigator.platform || '',
        sw: screen.width,
        sh: screen.height,
        ww: window.innerWidth,
        wh: window.innerHeight,
        dpr: window.devicePixelRatio || 1,
        mtp: navigator.maxTouchPoints || 0,
        hc: navigator.hardwareConcurrency || '',
        dm: navigator.deviceMemory || '',
        plg: navigator.plugins ? navigator.plugins.length : '',
    };
}

function rgPresenceFields(state) {
    var now = performance.now();
    var visible = state.visibleMs + (state.visibleSince === null ? 0 : now - state.visibleSince);
    var fields = Object.assign({}, state.browser, {
        vis0: state.vis0 ? 1 : 0,
        tvis: state.firstVisible === null ? '' : Math.round(state.firstVisible),
        visms: Math.round(visible),
        tin: state.firstInput === null || state.firstVisible === null ? '' : Math.max(0, Math.round(state.firstInput - state.firstVisible)),
        inkind: state.inputKind || '',
        ins: state.inputs,
        unt: state.untrusted,
        hin: state.hiddenInput ? 1 : 0,
        botd: state.botd || '',
    });

    Object.keys(fields).forEach(function (name) {
        if (fields[name] === '' || fields[name] === null || fields[name] === undefined) {
            delete fields[name];
        }
    });

    return fields;
}

function rgPresenceReport(state, leaving) {
    if (state.reports >= 4) {
        return;
    }

    state.browser = state.browser || rgPresenceBrowser();
    var fields = rgPresenceFields(state);
    // Visible time alone moves on by itself: a report only for something new, rounded so a page left on screen isn't one.
    var key = JSON.stringify(Object.assign({}, fields, { visms: Math.floor((fields.visms || 0) / 10000) }));

    if (key === state.lastKey) {
        return;
    }

    state.lastKey = key;
    state.reports++;

    if (leaving && navigator.sendBeacon) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var body = new URLSearchParams(Object.assign({ signal: 'presence', _token: csrf ? csrf.content : '' }, state.arrival ? { arrival: state.arrival } : {}, fields));

        try {
            navigator.sendBeacon('/visit/signal', body);

            return;
        } catch (e) {}
    }

    rgTrafficSignals = rgTrafficSignals
        .then(function () {
            return rgTrafficOpening;
        })
        .then(function () {
            return rgTrafficSend('presence', state.arrival, fields);
        })
        .catch(function () {});
}

function rgPresenceLater(state, delay) {
    window.setTimeout(function () {
        rgPresenceReport(state, false);
    }, delay);
}

function rgPresenceStart() {
    if (rgPresence || !document.querySelector('meta[name="rg-presence"]')) {
        return;
    }

    var arrival = document.querySelector('meta[name="rg-arrival"]');
    var visible = document.visibilityState === 'visible';
    var state = rgPresence = {
        arrival: arrival ? arrival.content : null,
        vis0: visible,
        firstVisible: visible ? performance.now() : null,
        visibleSince: visible ? performance.now() : null,
        visibleMs: 0,
        firstInput: null,
        inputKind: null,
        inputs: 0,
        untrusted: 0,
        hiddenInput: false,
        botd: null,
        browser: null,
        reports: 0,
        lastKey: '',
    };

    document.addEventListener('visibilitychange', function () {
        var now = performance.now();

        if (document.visibilityState === 'visible') {
            if (state.firstVisible === null) {
                state.firstVisible = now;
                rgPresenceLater(state, 3000);
            }

            state.visibleSince = now;
        } else {
            if (state.visibleSince !== null) {
                state.visibleMs += now - state.visibleSince;
                state.visibleSince = null;
            }

            rgPresenceReport(state, true);
        }
    });

    var kinds = { pointerdown: 'pointer', touchstart: 'touch', keydown: 'key', wheel: 'wheel' };

    Object.keys(kinds).forEach(function (type) {
        window.addEventListener(type, function (event) {
            if (!event.isTrusted) {
                state.untrusted = Math.min(state.untrusted + 1, 1000);

                return;
            }

            state.inputs = Math.min(state.inputs + 1, 1000);
            state.hiddenInput = state.hiddenInput || document.visibilityState !== 'visible';

            if (state.firstInput === null) {
                state.firstInput = performance.now();
                state.inputKind = kinds[type];
                rgPresenceLater(state, 500);
            }
        }, { capture: true, passive: true });
    });

    window.addEventListener('pagehide', function () {
        rgPresenceReport(state, true);
    });

    if (visible) {
        rgPresenceLater(state, 3000);
    }

    rgPresenceLater(state, 15000);

    // BotD in a chunk of its own, after the page is in; its verdict rides along with the next report.
    window.setTimeout(function () {
        import('@fingerprintjs/botd')
            .then(function (botd) {
                return botd.load({ monitoring: false });
            })
            .then(function (detector) {
                var result = detector.detect();
                state.botd = result.bot ? String(result.botKind || 'unknown') : 'none';
            })
            .catch(function () {});
    }, 0);
}

if (document.prerendering) {
    document.addEventListener('prerenderingchange', rgPresenceStart, { once: true });
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rgPresenceStart, { once: true });
} else {
    rgPresenceStart();
}
