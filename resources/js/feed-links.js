// A tag or category link inside a post changes that one part of the feed and
// keeps the rest of the feed the visitor is on: its sort, its other filters,
// as App\Support\Feed\FeedAddress draws the sidebar's links. The page draws
// these links as a fresh feed, because Livewire also renders posts on requests
// that do not carry the feed's address, and a redraw restores that. So just
// before one is followed, and only on the feed itself, it takes the other
// parts of the current address: on the press that wire:navigate acts on
// (mousedown, Enter) and on the click a new tab or a programmatic click uses.
// Only the parts the feed reads are kept, never an empty one or the page.
var RG_FEED_PARTS = ['search', 'tag', 'category', 'ratings', 'sort', 'feed']

function rgFeedPartOf(key) {
    return key.split('[')[0]
}

function rgKeepFeedParts(link) {
    var part = link.dataset.feedPart
    var target = new URL(link.href, window.location.href)
    var current = new URL(window.location.href)

    if (target.origin !== current.origin || target.pathname !== current.pathname) return

    var kept = new URLSearchParams()

    current.searchParams.forEach(function (value, key) {
        var name = rgFeedPartOf(key)

        if (value !== '' && name !== part && RG_FEED_PARTS.indexOf(name) !== -1) kept.append(key, value)
    })
    target.searchParams.forEach(function (value, key) {
        if (rgFeedPartOf(key) === part) kept.append(key, value)
    })

    target.search = kept.toString()
    link.href = target.toString()
}

;['pointerdown', 'keydown', 'click'].forEach(function (type) {
    document.addEventListener(type, function (event) {
        var link = event.target instanceof Element ? event.target.closest('a[data-feed-part]') : null

        if (link) rgKeepFeedParts(link)
    }, true)
})
