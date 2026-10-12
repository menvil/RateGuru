// Alpine.js component data for native Web Share API support. Given the
// post's picture, it shares the picture itself, with the text and link as
// its caption, wherever the system share sheet takes files.
window.rgNativeShare = function ({ title, text, url }) {
    return {
        supported: typeof navigator.share === 'function',
        async share(picture = null) {
            if (!this.supported) return;
            const data = picture && canShareFiles(picture)
                ? { files: [picture], title, text: `${text} ${url}` }
                : { title, text, url };
            try {
                await navigator.share(data);
            } catch (_) {
                // User cancelled or share failed — silently ignore.
            }
        },
    };
};

// Alpine.js component data for the share buttons whose apps take a picture
// (X, Telegram, WhatsApp, email). Their share links carry text alone, so the
// app would only see the picture as its own preview of our page, if at all.
// Instead, a button:
//
// - where the system share sheet takes files and is where the apps are
//   (phones), opens the sheet with the picture, captioned with the text and
//   the link;
// - elsewhere, where a page may put a picture on the clipboard, copies the
//   picture and then offers to open the app, to paste it into the post;
// - and wherever neither works, or the picture is not ready yet, stays the
//   plain link it always was.
//
// The picture is fetched once the buttons come into view, not on page load:
// a feed renders a share panel for every post, and most are never opened.
window.rgSharePicture = function ({ pictureUrl, fileName, title, text }) {
    return {
        picture: null,
        clipboardPicture: null,
        offer: null,

        async prepare() {
            if (!pictureUrl) return;

            try {
                const response = await fetch(pictureUrl, { credentials: 'same-origin' });
                if (!response.ok) return;

                const blob = await response.blob();
                if (!blob.type.startsWith('image/')) return;

                const picture = new File([blob], `${fileName}.${extensionOf(blob.type)}`, { type: blob.type });
                this.picture = picture;

                if (!(prefersShareSheet() && canShareFiles(picture)) && canCopyPicture()) {
                    this.clipboardPicture = blob.type === 'image/png' ? blob : await toPng(blob);
                }
            } catch (_) {
                // No picture to hand over: the buttons stay plain links.
            }
        },

        sharePicture(event) {
            const link = event.currentTarget;
            this.offer = null;

            if (this.picture && prefersShareSheet() && canShareFiles(this.picture)) {
                event.preventDefault();
                navigator.share({ files: [this.picture], title, text }).catch((error) => {
                    if (error.name !== 'AbortError') this.openOrOffer(link);
                });

                return;
            }

            if (this.clipboardPicture) {
                event.preventDefault();
                // Written from the click itself: a clipboard write needs the
                // user's gesture, and the picture is already a PNG by now.
                navigator.clipboard
                    .write([new ClipboardItem({ [this.clipboardPicture.type]: this.clipboardPicture })])
                    .then(
                        () => { this.offer = offerFor(link, link.dataset.pictureCopied); },
                        () => this.openOrOffer(link),
                    );
            }
        },

        // What the link would have done, when handing over the picture
        // failed; a browser that no longer counts this as the user's click
        // blocks the new tab, so the link is offered to click instead.
        openOrOffer(link) {
            const opened = window.open(link.href, '_blank');

            if (opened) {
                opened.opener = null;
            } else {
                this.offer = offerFor(link, null);
            }
        },
    };
};

function offerFor(link, message) {
    return { href: link.href, open: link.dataset.pictureOpen, message };
}

// A phone's share sheet lists the apps a picture can go to. A desktop's
// rarely lists X or WhatsApp, so a mouse-driven browser copies instead.
function prefersShareSheet() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
}

function canShareFiles(file) {
    return typeof navigator.canShare === 'function' && navigator.canShare({ files: [file] });
}

function canCopyPicture() {
    return window.isSecureContext
        && typeof window.ClipboardItem === 'function'
        && typeof navigator.clipboard?.write === 'function';
}

function extensionOf(type) {
    return { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif' }[type] ?? 'jpg';
}

// Browsers put a picture on the clipboard as PNG only.
async function toPng(blob) {
    const bitmap = await createImageBitmap(blob);
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    canvas.getContext('2d').drawImage(bitmap, 0, 0);
    bitmap.close();

    return new Promise((resolve, reject) => {
        canvas.toBlob((png) => (png ? resolve(png) : reject(new Error('The picture could not be converted to PNG.'))), 'image/png');
    });
}
