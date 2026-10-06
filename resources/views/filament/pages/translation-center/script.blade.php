{{--
    The browser's half of Translation Center: the units of the target
    language as the server drew them, the drafts, the filters and the URL.

    Filtering never asks the server: the search, the section and Missing only /
    All run over the units already on the page and are kept in the URL with
    replaceState. A draft lives here only — typing sends nothing, and visitors
    keep the stored translation until Save sends that one unit. Choosing
    another target language is the one thing that re-renders the page, after
    asking whenever it would drop drafts; leaving the page with drafts asks the
    browser's own question.

    Registered once, before Alpine starts; a re-render keeps the registration.
--}}
<script>
    (() => {
        const register = () => window.Alpine.data('rgAdminTranslationCenter', (config) => ({
            locale: config.locale,
            label: config.label,
            units: {},
            order: [],
            query: '',
            section: '',
            mode: 'all',
            linked: null,
            context: null,
            pendingTarget: null,
            switching: false,

            init() {
                for (const unit of config.units) {
                    this.units[unit.id] = { ...unit, value: unit.stored, error: null, saving: false }
                    this.order.push(unit.id)
                }

                const params = new URLSearchParams(location.search)
                const section = params.get('section') ?? ''

                this.query = (params.get('q') ?? '').trim().slice(0, config.queryLimit)
                this.section = config.sections.includes(section) ? section : ''
                this.mode = params.get('mode') === 'missing' ? 'missing' : 'all'

                this.$watch('query', () => this.remember())
                this.$watch('section', () => this.remember())
                this.$watch('mode', () => this.remember())
                this.$nextTick(() => {
                    this.remember()
                    this.follow(params.get('unit'))

                    // A new target language was chosen: focus goes back to where it was chosen.
                    if (window.rgAdminTranslationCenterRefocus) {
                        delete window.rgAdminTranslationCenterRefocus
                        document.getElementById('rg-admin-translation-target-trigger')?.focus()
                    }
                })
            },

            // Figures -------------------------------------------------------------

            get total() {
                return this.order.length
            },
            get translated() {
                return this.order.filter((id) => this.units[id].stored !== '').length
            },
            get missing() {
                return this.total - this.translated
            },
            get percentage() {
                return this.total === 0 ? 100 : Math.floor(this.translated * 100 / this.total)
            },
            missingIn(section) {
                return this.order.filter((id) => this.units[id].section === section && this.units[id].stored === '').length
            },
            figure(number) {
                return Number(number).toLocaleString('en-US')
            },

            // One unit ------------------------------------------------------------

            isDirty(id) {
                return this.units[id].value !== this.units[id].stored
            },
            state(id) {
                if (this.isDirty(id)) {
                    return 'edited'
                }

                return this.units[id].stored === '' ? 'missing' : 'saved'
            },
            note(id) {
                return {
                    saved: 'Stored translation',
                    missing: 'Visitors see the English text',
                    edited: this.units[id].stored === '' ? 'Visitors see the English text until you save' : 'Saved version is kept until you save',
                }[this.state(id)]
            },
            length(id) {
                // Code points, as the server counts them.
                return Array.from(this.units[id].value).length
            },
            check(id) {
                const unit = this.units[id]
                const text = unit.value.trim()

                if (! this.isDirty(id) || text === '') {
                    return null
                }

                if (! unit.multiline && /[\r\n]/.test(text)) {
                    return 'Remove the line break: this text is a single line'
                }

                const over = Array.from(text).length - unit.max

                if (over > 0) {
                    return `${this.figure(over)} over the limit`
                }

                const lost = unit.placeholders.filter((placeholder) => ! text.includes(placeholder))

                return lost.length > 0 ? `Keep ${lost.join(', ')}` : null
            },
            error(id) {
                return this.units[id].error ?? this.check(id)
            },
            canSave(id) {
                const unit = this.units[id]

                return this.isDirty(id) && ! unit.saving && this.check(id) === null && ! (unit.value.trim() === '' && unit.stored === '')
            },

            // Filters -------------------------------------------------------------

            get needle() {
                return this.query.trim().toLowerCase()
            },
            shows(id) {
                const unit = this.units[id]

                if (this.section !== '' && unit.section !== this.section) {
                    return false
                }

                // A draft stays in Missing only until it is saved.
                if (this.mode === 'missing' && unit.stored !== '' && ! this.isDirty(id)) {
                    return false
                }

                return this.needle === '' || `${unit.search} ${unit.stored} ${unit.value}`.toLowerCase().includes(this.needle)
            },
            visible() {
                return this.order.filter((id) => this.shows(id))
            },
            get shown() {
                return this.visible().length
            },
            get resultText() {
                return `${this.figure(this.shown)} of ${this.figure(this.total)} ${this.total === 1 ? 'item' : 'items'}`
            },
            clearFilters() {
                this.query = ''
                this.section = ''
                this.mode = 'all'
                this.$nextTick(() => document.getElementById('rg-admin-translation-search')?.focus())
            },
            remember() {
                const url = new URL(location.href)
                const query = this.query.trim()

                query === '' ? url.searchParams.delete('q') : url.searchParams.set('q', query)
                this.section === '' ? url.searchParams.delete('section') : url.searchParams.set('section', this.section)
                this.mode === 'all' ? url.searchParams.delete('mode') : url.searchParams.set('mode', this.mode)
                // A link to one item is followed once, not again on every reload or language.
                url.searchParams.delete('unit')

                // Livewire keeps what it needs in history.state; it stays as it is.
                if (url.href !== location.href) {
                    history.replaceState(history.state, '', url)
                }
            },

            // A link to one item: shown, scrolled to and focused, filters loosened if they would hide it.
            follow(id) {
                if (! id || ! this.units[id]) {
                    return
                }

                if (! this.shows(id)) {
                    this.query = ''
                    this.mode = 'all'

                    if (this.section !== this.units[id].section) {
                        this.section = ''
                    }
                }

                this.linked = id
                this.focusWhenShown(id)
                setTimeout(() => this.linked === id && (this.linked = null), 4000)
            },
            // The page is still starting: the row appears once Alpine has applied the filters and lifted
            // x-cloak, which is not before this runs, so focus waits for the field to be drawn.
            focusWhenShown(id, frames = 30) {
                const field = document.getElementById(`${this.units[id].dom}-field`)

                if (field && field.getClientRects().length > 0) {
                    this.focusField(id, 'center')
                } else if (frames > 0) {
                    requestAnimationFrame(() => this.focusWhenShown(id, frames - 1))
                }
            },
            focusField(id, block = 'nearest') {
                const field = document.getElementById(`${this.units[id].dom}-field`)

                field?.scrollIntoView({ block })
                field?.focus({ preventScroll: true })
            },

            // Drafts --------------------------------------------------------------

            get dirtyCount() {
                return this.order.filter((id) => this.isDirty(id)).length
            },
            get unsavedText() {
                const count = this.dirtyCount

                return `${this.figure(count)} ${count === 1 ? 'edit' : 'edits'} not saved yet. Nothing changes for visitors until you save.`
            },
            discard(id) {
                this.units[id].value = this.units[id].stored
                this.units[id].error = null
                this.$nextTick(() => this.focusField(id))
            },
            discardAll(focus = true) {
                for (const id of this.order) {
                    this.units[id].value = this.units[id].stored
                    this.units[id].error = null
                }

                if (focus) {
                    this.$nextTick(() => document.getElementById('rg-admin-translation-search')?.focus())
                }
            },
            warnBeforeLeaving(event) {
                if (this.dirtyCount > 0 && ! this.switching) {
                    event.preventDefault()
                    event.returnValue = ''
                }
            },

            // Save ----------------------------------------------------------------

            async save(id, next = false) {
                if (! this.canSave(id)) {
                    return
                }

                const unit = this.units[id]
                const visible = this.visible()
                const after = visible[visible.indexOf(id) + 1] ?? null
                let result = null

                unit.saving = true

                try {
                    result = await this.$wire.save(id, this.locale, unit.value)
                } catch (failure) {
                    result = null
                }

                unit.saving = false

                if (! result?.saved) {
                    unit.error = result?.error ?? 'Not saved: the server did not answer. Try again.'

                    // A problem with the text is shown at the field; any other one is also said aloud.
                    if (! result?.field) {
                        this.toast(unit.error, 'error')
                    }

                    this.$nextTick(() => this.focusField(id))

                    return
                }

                unit.stored = result.value
                unit.value = result.value
                unit.error = null
                this.toast(`${this.label} translation ${result.value === '' ? 'removed' : 'saved'}`)

                this.$nextTick(() => {
                    // Save & next goes on to the next item the filters show, and the last one
                    // simply saves. A row that leaves the view hands focus on the same way.
                    const target = (next && after) || ! this.shows(id) ? after : id

                    target ? this.focusField(target) : document.getElementById('rg-admin-translation-search')?.focus()
                })
            },
            toast(message, tone = 'success') {
                this.$dispatch('rg-admin-toast', { message, tone })
            },

            // Context -------------------------------------------------------------

            async openContext(id) {
                const context = await this.$wire.context(id, this.locale).catch(() => null)

                if (! context) {
                    this.toast('This item is no longer translated here. Reload the page to see the current content.', 'error')

                    return
                }

                this.context = context
            },
            closeContext() {
                // After the drawer has let go of focus and handed it back.
                this.$nextTick(() => this.context = null)
            },

            // Target language -----------------------------------------------------

            chooseTarget(code) {
                if (code === this.locale || this.switching) {
                    return
                }

                if (this.dirtyCount > 0) {
                    this.pendingTarget = code

                    return
                }

                this.switchTo(code)
            },
            get switchText() {
                const count = this.dirtyCount
                const to = config.targets[this.pendingTarget] ?? this.pendingTarget

                return `${this.figure(count)} ${this.label} ${count === 1 ? 'edit is' : 'edits are'} not saved. Switching to ${to} drops ${count === 1 ? 'it' : 'them'}; the stored translations stay as they are.`
            },
            confirmSwitch() {
                const code = this.pendingTarget

                this.pendingTarget = null
                this.switchTo(code)
            },
            switchTo(code) {
                const from = this.locale

                this.discardAll(false)
                this.switching = true
                window.rgAdminTranslationCenterRefocus = true

                // On success the server draws the new language and this component is replaced. A request
                // that fails changes nothing there, so the screen stays usable on the language it shows.
                Promise.resolve(this.$wire.$set('locale', code)).catch(() => {
                    this.$wire.$set('locale', from, false)
                    this.switching = false
                    delete window.rgAdminTranslationCenterRefocus
                    this.toast('The language was not switched: the server did not answer. Try again.', 'error')
                })
            },
        }))

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
