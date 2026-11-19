// Tells the traffic tracker that a page ran in a real browser: the arrival
// token the server printed goes back once, after a prerendered page is
// actually shown — and again for each page a wire:navigate move brings in
// (a profile, a category, a tag), whose head carries its own token. Each
// token goes back once, however often its page is shown. A game page sends
// it through its own first call instead. A sign-up dialog calls
// rgTrafficSignal('signup_form') when it opens, and a page that shows a
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

window.rgTrafficSignal = function (signal, fields) {
    var send = function () {
        rgTrafficOpening.then(function () {
            rgTrafficSend(signal, null, fields);
        });
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
        window.rgTrafficSignal(element.dataset.rgTrafficSignal);
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
