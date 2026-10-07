{{--
    The browser's half of Translation Center: the units of the target
    language as the server drew them, the drafts, the filters and the URL.

    Filtering never asks the server: the search, the section and Missing only /
    All run over the units already on the page and are kept in the URL with
    replaceState. A draft lives here only — typing sends nothing, and visitors
    keep the stored translation until Save sends that one unit. An AI
    suggestion is a draft too: AI translate asks the server for one missing
    unit, Suggest alternative for another version of a saved one, and what
    comes back sits in the field, marked as AI and unsaved, until it is saved,
    edited into an ordinary draft, or discarded — the saved version stays
    stored meanwhile. Choosing
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
            announcement: '',

            init() {
                for (const unit of config.units) {
                    // ai and generatedAt describe the draft only while it is the suggestion as it came;
                    // attempt tells a suggestion that arrives after its row was discarded from one that is awaited.
                    this.units[unit.id] = { ...unit, value: unit.stored, error: null, saving: false, ai: false, generating: false, generatedAt: null, attempt: 0 }
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
            // One answer per row, in this order: a draft is an AI suggestion or an edit, and
            // without one the row is what is stored. An AI suggestion is therefore always unsaved.
            state(id) {
                if (this.isDirty(id)) {
                    return this.units[id].ai ? 'ai' : 'edited'
                }

                return this.units[id].stored === '' ? 'missing' : 'saved'
            },
            note(id) {
                return {
                    saved: 'Stored translation',
                    missing: 'Visitors see the English text',
                    ai: this.units[id].stored === ''
                        ? `Generated ${this.generatedTime(id)} · AI suggestions are drafts until saved.`
                        : `Generated ${this.generatedTime(id)} · Saved version is kept until you save.`,
                    edited: this.units[id].stored === '' ? 'Visitors see the English text until you save' : 'Saved version is kept until you save',
                }[this.state(id)]
            },
            generatedTime(id) {
                const at = new Date(this.units[id].generatedAt)

                return Number.isNaN(at.getTime()) ? 'just now' : at.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
            },
            // Typing turns an AI suggestion into an ordinary edit: the text is the administrator's now,
            // and Regenerate, which would overwrite it, goes away with the AI mark.
            edited(id) {
                this.units[id].error = null
                this.units[id].ai = false
                this.units[id].generatedAt = null
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

                return this.isDirty(id) && ! unit.saving && ! unit.generating && this.check(id) === null && ! (unit.value.trim() === '' && unit.stored === '')
            },
            // AI translate fills a missing translation, Suggest alternative offers another version of a
            // saved one, Regenerate replaces an AI suggestion. None is offered for a draft someone typed,
            // which a suggestion would overwrite.
            offersAi(id) {
                return ['missing', 'saved', 'ai'].includes(this.state(id))
            },
            aiLabel(id) {
                return { missing: 'AI translate', saved: 'Suggest alternative', ai: 'Regenerate' }[this.state(id)] ?? 'AI translate'
            },
            canSuggest(id) {
                const unit = this.units[id]

                return this.offersAi(id) && ! unit.generating && ! unit.saving
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
            get aiCount() {
                return this.order.filter((id) => this.state(id) === 'ai').length
            },
            // “2 AI suggestions and 1 edit”, “1 edit”: the drafts by kind, AI suggestions first.
            drafts(label = '') {
                const ai = this.aiCount
                const edits = this.dirtyCount - ai
                const kind = label === '' ? '' : `${label} `
                const parts = []

                if (ai > 0) {
                    parts.push(`${this.figure(ai)} ${kind}${ai === 1 ? 'AI suggestion' : 'AI suggestions'}`)
                }

                if (edits > 0) {
                    parts.push(`${this.figure(edits)} ${ai > 0 ? '' : kind}${edits === 1 ? 'edit' : 'edits'}`)
                }

                return parts.join(' and ')
            },
            get unsavedText() {
                return `${this.drafts()} not saved yet. Nothing changes for visitors until you save.`
            },
            // Back to what is stored. A suggestion still on its way for this row is no longer wanted.
            reset(id) {
                const unit = this.units[id]

                unit.value = unit.stored
                unit.error = null
                unit.ai = false
                unit.generatedAt = null
                unit.generating = false
                unit.attempt++
            },
            discard(id) {
                this.reset(id)
                this.$nextTick(() => this.focusField(id))
            },
            discardAll(focus = true) {
                for (const id of this.order) {
                    this.reset(id)
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
                unit.ai = false
                unit.generatedAt = null
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

            // AI suggestion -------------------------------------------------------

            // One row at a time, and only that row waits: its field is read-only and its actions
            // paused until the answer, so nothing typed meanwhile could be overwritten by it. The
            // stored text the row shows goes along to be compared, so a suggestion is never made
            // against a translation someone else has saved or changed since the page was drawn.
            // A suggestion replaces the field only once it has arrived — a failed Regenerate
            // leaves the suggestion before it in place — and is stored nowhere: the figures,
            // which count stored translations, stay as they are until it is saved.
            async suggest(id) {
                if (! this.canSuggest(id)) {
                    return
                }

                const unit = this.units[id]
                const attempt = ++unit.attempt
                const name = unit.name
                let result = null

                unit.generating = true
                this.announce(`Generating a ${this.label} suggestion for ${name}…`)

                try {
                    result = await this.$wire.suggest(id, this.locale, unit.stored)
                } catch (failure) {
                    result = null
                }

                // Discarded, or the language switched, while it was on its way.
                if (attempt !== unit.attempt || ! unit.generating) {
                    return
                }

                unit.generating = false

                if (! result?.generated || result.unit !== id || result.locale !== this.locale) {
                    this.announce('')
                    this.toast(result?.error ?? 'No suggestion: the server did not answer. Try again.', 'error')

                    return
                }

                // Word for word what is saved already: nothing to review, so the row stays as it is.
                if (result.text === unit.stored) {
                    this.announce('')
                    this.toast('AI suggested the same text as the saved translation.', 'info')

                    return
                }

                unit.value = result.text
                unit.ai = true
                unit.generatedAt = result.generatedAt
                unit.error = null
                this.announce(`${this.label} AI suggestion ready for ${name}. Not saved.`)
                this.$nextTick(() => this.focusField(id))
            },
            announce(message) {
                this.announcement = message
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

                return `${this.drafts(this.label)} ${count === 1 ? 'is' : 'are'} not saved. Switching to ${to} drops ${count === 1 ? 'it' : 'them'}; the stored translations stay as they are.`
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
