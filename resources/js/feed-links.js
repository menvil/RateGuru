// A tag or category link inside a post changes that one part of the feed and
// keeps the rest of the feed the visitor is on: its sort, its other filters,
// as App\Support\Feed\FeedAddress draws the sidebar's links. The page draws
// these links as a fresh feed, because Livewire also renders posts on requests
// that do not carry the feed's address; just before one is followed (pressed,
// or reached by keyboard), and only on the feed itself, it takes the other
// parts of the current address. The page number is never kept.
function rgKeepFeedParts(link) {
    var part = link.dataset.feedPart
    var target = new URL(link.href, window.location.href)
    var current = new URL(window.location.href)

    if (target.origin !== current.origin || target.pathname !== current.pathname) return

    var isPart = function (key) { return key === part || key.indexOf(part + '[') === 0 }
    var kept = new URLSearchParams()

    current.searchParams.forEach(function (value, key) {
        if (key !== 'page' && ! isPart(key)) kept.append(key, value)
    })
    target.searchParams.forEach(function (value, key) {
        if (isPart(key)) kept.append(key, value)
    })

    target.search = kept.toString()
    link.href = target.toString()
}

;['pointerdown', 'focusin'].forEach(function (type) {
    document.addEventListener(type, function (event) {
        var link = event.target instanceof Element ? event.target.closest('a[data-feed-part]') : null

        if (link) rgKeepFeedParts(link)
    }, true)
})
