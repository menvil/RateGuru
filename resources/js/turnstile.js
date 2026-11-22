// Cloudflare Turnstile on the sign-up forms. The script is loaded, and a
// widget drawn, only once a sign-up form is actually on screen: the modal is
// on every guest page, and most visitors never open it.
const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

const widgets = new Map();
let loading = null;

function loadTurnstile() {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');

        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = () => {
            // Asked again the next time a form opens.
            loading = null;
            script.remove();
            reject(new Error('Turnstile did not load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

function showFailure(element, failed) {
    const message = element.parentElement?.querySelector('[data-rg-turnstile-error]');

    if (message) {
        message.hidden = ! failed;
    }
}

function theme() {
    const current = document.documentElement.dataset.theme;

    return current === 'light' || current === 'dark' ? current : 'auto';
}

async function mount(element) {
    if (element.dataset.rgTurnstileMounted) {
        return;
    }

    element.dataset.rgTurnstileMounted = 'true';

    try {
        const turnstile = await loadTurnstile();

        // The person may have left the page while the script loaded.
        if (! element.isConnected) {
            return;
        }

        widgets.set(element, turnstile.render(element, {
            sitekey: element.dataset.sitekey,
            action: element.dataset.action,
            'response-field-name': element.dataset.field,
            size: 'flexible',
            theme: theme(),
            'refresh-expired': 'auto',
            callback: () => showFailure(element, false),
            'error-callback': () => {
                showFailure(element, true);

                // Handled here: the widget retries by itself.
                return true;
            },
        }));
    } catch {
        delete element.dataset.rgTurnstileMounted;
        showFailure(element, true);
    }
}

// Draws the widgets under an element that are visible now. A form in a closed
// dialog or a step not reached yet is drawn when it is shown.
window.rgMountTurnstile = function (root = document) {
    root?.querySelectorAll('[data-rg-turnstile]').forEach((element) => {
        if (element.getClientRects().length > 0) {
            mount(element);
        }
    });
};

function mountOnPage() {
    window.rgMountTurnstile();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountOnPage);
} else {
    mountOnPage();
}

document.addEventListener('livewire:navigated', mountOnPage);

// Before Livewire keeps this page for the Back button: a widget restored from
// that copy would be a dead frame, so it is drawn anew instead.
document.addEventListener('livewire:navigating', () => {
    widgets.forEach((id, element) => {
        window.turnstile?.remove(id);
        delete element.dataset.rgTurnstileMounted;
    });
    widgets.clear();
});
