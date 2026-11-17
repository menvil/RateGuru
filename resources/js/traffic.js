// Tells the traffic tracker that a page ran in a real browser: the arrival
// token the server printed goes back once, after a prerendered page is
// actually shown. A game page sends it through its own first call instead.
// A sign-up dialog calls rgTrafficSignal('signup_form') when it opens.
function rgTrafficSend(signal, arrival) {
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var body = new URLSearchParams({ signal: signal });

    if (arrival) {
        body.set('arrival', arrival);
    }

    try {
        fetch('/visit/signal', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'X-CSRF-TOKEN': csrf ? csrf.content : '', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
        }).catch(function () {});
    } catch (e) {}
}

window.rgTrafficSignal = function (signal) {
    rgTrafficSend(signal, null);
};

function rgTrafficOpened() {
    var meta = document.querySelector('meta[name="rg-arrival"]');

    if (!meta || !meta.content || meta.dataset.sent) {
        return;
    }

    meta.dataset.sent = '1';
    rgTrafficSend('opened', meta.content);
}

if (document.prerendering) {
    document.addEventListener('prerenderingchange', rgTrafficOpened, { once: true });
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rgTrafficOpened, { once: true });
} else {
    rgTrafficOpened();
}
