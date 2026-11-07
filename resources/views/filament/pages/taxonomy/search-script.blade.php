{{--
    The browser's search over the rows of Categories and Tags: both lists are
    bounded and already on the page, so typing filters them here, without a
    Livewire request per keystroke, and keeps the query in the address (?q=)
    with replaceState, so a link or a reload opens the same view. While the
    field holds text, its clear button empties it in one click.

    Each row carries what the search looks in as data-search; the card reads
    them when it is drawn, so the count follows a save, an activation or a
    deletion.

    Registered once, before Alpine starts; a re-render keeps the registration.
--}}
<script>
    (() => {
        const register = () => window.Alpine.data('rgAdminTaxonomySearch', (field) => ({
            query: new URLSearchParams(location.search).get('q') ?? '',
            rows: [],
            init() {
                this.$watch('query', () => this.remember())
            },
            get needle() {
                return this.query.trim().toLowerCase()
            },
            matches(search) {
                return this.needle === '' || search.includes(this.needle)
            },
            get shown() {
                return this.rows.filter((search) => this.matches(search)).length
            },
            remember() {
                const url = new URL(location.href)
                const query = this.query.trim()

                query === '' ? url.searchParams.delete('q') : url.searchParams.set('q', query)

                // Livewire keeps the state Back needs in history.state; it stays as it is.
                if (url.href !== location.href) {
                    history.replaceState(history.state, '', url)
                }
            },
            clear() {
                this.query = ''
                this.$nextTick(() => document.getElementById(field)?.focus())
            },
        }))

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
