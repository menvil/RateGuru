{{--
    The browser's half of Translation Center: the units of the target
    language as the server drew them, the drafts, the filters and the URL.

    Filtering never asks the server: the search, the section and Missing only /
    Needs update / All run over the units already on the page and are kept in
    the URL with replaceState. A draft lives here only — typing sends nothing, and visitors
    keep the stored translation until Save sends that one unit. An AI
    suggestion is a draft too: AI translate asks the server for one missing
    unit, Suggest alternative for another version of a saved one, and what
    comes back sits in the field, marked as AI and unsaved, until it is saved,
    edited into an ordinary draft, or discarded — the saved version stays
    stored meanwhile.

    An outdated unit is a stored translation made from English the unit no
    longer has. It stays stored, and what visitors see, until the row is
    saved — changed, or as it is — which makes it current; nothing else here
    changes whether a unit is outdated.

    Generate missing is different: the server generates every missing and
    every outdated translation of the language in the background and keeps
    the suggestions, so they are persisted drafts rather than volatile ones.
    The page restores them when it opens, polls while generation runs — never
    from a hidden tab — and puts each one into its row only while the row is
    still missing or outdated, untouched and showing the English it was
    generated from. Saving or
    discarding one goes through the server, which holds the text; typing in
    one makes it an ordinary, volatile edit. Only volatile drafts are lost by
    leaving or switching language, so only they make the page ask.

    Choosing another target language is the one thing that re-renders the
    page, after asking whenever it would drop volatile drafts; leaving the
    page with them asks the browser's own question.

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
            game: '',
            mode: 'all',
            linked: null,
            context: null,
            pendingTarget: null,
            switching: false,
            announcement: '',
            // The administrator's background generation for this language, as the server last said.
            generation: null,
            generationBusy: false,
            generationError: null,
            pendingGenerate: false,
            pendingSaveAll: false,
            pollTimer: null,
            onVisibility: null,
            // The component's own $wire and $dispatch, taken where it starts. Alpine binds both to the
            // element that called the method, and a dialog's button is gone once the dialog closes:
            // through it, every later call — a poll's included — would reach no component and answer
            // nothing, and a toast would be raised where no one listens.
            server: null,
            destroyed: false,
            notify: null,

            init() {
                const wire = this.$wire
                const dispatch = this.$dispatch

                this.server = () => wire
                this.notify = (name, detail) => dispatch(name, detail)

                for (const unit of config.units) {
                    // ai and generatedAt describe the draft only while it is the suggestion as it came;
                    // attempt tells a suggestion that arrives after its row was discarded from one that is awaited.
                    // persisted marks the untouched background suggestion the server holds; bulk, the batch a
                    // row's draft came from, kept after an edit so the server's copy can be let go later.
                    // saving counts the saves of the row on their way: its own and a Save all can overlap,
                    // and the row stays read-only until the last of them answers.
                    this.units[unit.id] = {
                        ...unit, value: unit.stored, error: null, saving: 0, ai: false, generating: false, generatedAt: null, attempt: 0,
                        persisted: false, bulk: null, bulkPending: false, bulkIssue: null,
                    }
                    this.order.push(unit.id)
                }

                this.applyGeneration(config.generation)
                this.onVisibility = () => document.visibilityState === 'visible' && this.generationRunning && this.poll()
                document.addEventListener('visibilitychange', this.onVisibility)

                const params = new URLSearchParams(location.search)
                const section = params.get('section') ?? ''

                const mode = params.get('mode') ?? ''

                this.query = (params.get('q') ?? '').trim().slice(0, config.queryLimit)
                this.section = config.sections.includes(section) ? section : ''
                this.game = config.games.includes(params.get('game') ?? '') ? params.get('game') : ''
                this.mode = config.modes.includes(mode) ? mode : 'all'

                this.$watch('query', () => this.remember())
                this.$watch('section', () => this.remember())
                this.$watch('game', () => this.remember())
                this.$watch('mode', () => this.remember())
                this.$nextTick(() => {
                    this.remember()
                    this.follow(params.get('unit'))

                    // A new target language was chosen: focus goes back to where it was chosen.
                    if (window.rgAdminTranslationCenterRefocus) {
                        delete window.rgAdminTranslationCenterRefocus
                        document.getElementById('rg-admin-translation-target-trigger')?.focus()
                    }

                    this.schedulePoll()
                })
            },
            destroy() {
                // A read already on its way answers a screen that is gone: it must arm no timer.
                this.destroyed = true
                clearTimeout(this.pollTimer)
                document.removeEventListener('visibilitychange', this.onVisibility)
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
            // Stored, and made from English the unit no longer has: counted among the translated, too.
            get outdated() {
                return this.order.filter((id) => this.units[id].outdated).length
            },
            get percentage() {
                return this.total === 0 ? 100 : Math.floor(this.translated * 100 / this.total)
            },
            missingIn(section) {
                return this.order.filter((id) => this.units[id].section === section && this.units[id].stored === '').length
            },
            missingInGame(game) {
                return this.order.filter((id) => this.units[id].game === game && this.units[id].stored === '').length
            },
            figure(number) {
                return Number(number).toLocaleString('en-US')
            },

            // One unit ------------------------------------------------------------

            isDirty(id) {
                return this.units[id].value !== this.units[id].stored
            },
            // One answer per row, in this order: a draft is an AI suggestion or an edit, and
            // without one the row is what is stored — missing, outdated or saved. An AI suggestion
            // is therefore always unsaved.
            state(id) {
                if (this.isDirty(id)) {
                    return this.units[id].ai ? 'ai' : 'edited'
                }

                if (this.units[id].stored === '') {
                    return 'missing'
                }

                return this.units[id].outdated ? 'outdated' : 'saved'
            },
            note(id) {
                return {
                    saved: 'Stored translation',
                    outdated: 'Made from older English. Visitors see it until you save.',
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
                this.units[id].persisted = false
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
            // A draft, or an outdated translation as it is: saved unchanged, it is kept for the new English.
            canSave(id) {
                const unit = this.units[id]

                return (this.isDirty(id) || this.state(id) === 'outdated') && ! this.held(id) && this.check(id) === null && ! (unit.value.trim() === '' && unit.stored === '')
            },
            // AI translate fills a missing translation, Suggest alternative offers another version of a
            // saved one, Update with AI one for the new English of an outdated one, Regenerate replaces an
            // AI suggestion. None is offered for a draft someone typed, which a suggestion would overwrite.
            offersAi(id) {
                return ['missing', 'saved', 'outdated', 'ai'].includes(this.state(id))
            },
            aiLabel(id) {
                return { missing: 'AI translate', saved: 'Suggest alternative', outdated: 'Update with AI', ai: 'Regenerate' }[this.state(id)] ?? 'AI translate'
            },
            canSuggest(id) {
                return this.offersAi(id) && ! this.held(id)
            },
            // A row waiting for its background suggestion, or for its own.
            waiting(id) {
                return this.units[id].generating || this.units[id].bulkPending
            },
            // A row that takes nothing now: a suggestion or a save of it on its way, or the language
            // switching, whose answer draws the screen afresh — what was typed or discarded meanwhile
            // would be lost with it, or gone if the switch then failed.
            held(id) {
                return this.waiting(id) || this.units[id].saving > 0 || this.switching
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

                if (this.game !== '' && unit.game !== this.game) {
                    return false
                }

                // A draft stays in Missing only until it is saved.
                if (this.mode === 'missing' && unit.stored !== '' && ! this.isDirty(id)) {
                    return false
                }

                // An outdated row stays in Needs update, its draft included, until it is saved.
                if (this.mode === 'outdated' && ! unit.outdated) {
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
                this.game = ''
                this.mode = 'all'
                this.$nextTick(() => document.getElementById('rg-admin-translation-search')?.focus())
            },
            remember() {
                const url = new URL(location.href)
                const query = this.query.trim()

                query === '' ? url.searchParams.delete('q') : url.searchParams.set('q', query)
                this.section === '' ? url.searchParams.delete('section') : url.searchParams.set('section', this.section)
                this.game === '' ? url.searchParams.delete('game') : url.searchParams.set('game', this.game)
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

                    if (this.game !== this.units[id].game) {
                        this.game = ''
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
            // A draft only this page holds — typed, interactive or a background suggestion edited
            // since — and so the only kind leaving the page or switching language loses.
            isVolatile(id) {
                return this.isDirty(id) && ! this.units[id].persisted
            },
            get volatileDirtyCount() {
                return this.order.filter((id) => this.isVolatile(id)).length
            },
            get aiCount() {
                return this.order.filter((id) => this.isVolatile(id) && this.state(id) === 'ai').length
            },
            // “2 AI suggestions and 1 edit”, “1 edit”: the volatile drafts by kind, AI suggestions first.
            drafts(label = '') {
                const ai = this.aiCount
                const edits = this.volatileDirtyCount - ai
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
                unit.persisted = false
                unit.bulk = null
                unit.attempt++
            },
            // A draft that came from a background suggestion is let go on the server first, or a
            // reload would bring it back; if the server cannot be told, the draft stays.
            async discard(id) {
                const unit = this.units[id]

                if (unit.bulk !== null && ! await this.discardGenerated(unit.bulk, id)) {
                    return
                }

                this.reset(id)
                this.$nextTick(() => this.focusField(id))
            },
            // Every draft the strip counts: the ones only this page holds. A background suggestion edited
            // since is let go on the server too, or a reload would bring it back; untouched ones are
            // Discard generated's.
            async discardAll(focus = true) {
                for (const id of this.order.filter((id) => this.isVolatile(id))) {
                    const unit = this.units[id]

                    if (unit.bulk !== null && ! await this.discardGenerated(unit.bulk, id)) {
                        return
                    }

                    this.reset(id)
                }

                if (focus) {
                    this.$nextTick(() => document.getElementById('rg-admin-translation-search')?.focus())
                }
            },
            // What switching language drops: the volatile drafts only. Background suggestions stay on
            // the server and come back with the language.
            dropVolatile() {
                for (const id of this.order) {
                    if (this.isVolatile(id)) {
                        this.reset(id)
                    }
                }
            },
            warnBeforeLeaving(event) {
                if (this.volatileDirtyCount > 0 && ! this.switching) {
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

                // An untouched background suggestion is saved from the server's copy, never from the page.
                if (unit.persisted && unit.bulk !== null) {
                    return this.saveGeneratedRow(id, next, after)
                }

                unit.saving++

                try {
                    result = await this.server().save(id, this.locale, unit.value, unit.fingerprint)
                } catch (failure) {
                    result = null
                }

                unit.saving--

                if (! result?.saved) {
                    unit.error = result?.error ?? 'Not saved: the server did not answer. Try again.'

                    // A problem with the text is shown at the field; any other one is also said aloud.
                    if (! result?.field) {
                        this.toast(unit.error, 'error')
                    }

                    this.$nextTick(() => this.focusField(id))

                    return
                }

                this.stored(id, result.value)
                this.toast(`${this.label} translation ${result.value === '' ? 'removed' : 'saved'}`)
                this.moveOn(id, next, after)
            },
            // The row holds what is stored now, and nothing else: its draft and where it came from are done.
            // Whatever the server stored was recorded with the English the row shows: it is current.
            stored(id, value) {
                const unit = this.units[id]

                unit.stored = value
                unit.outdated = false
                unit.value = value
                unit.error = null
                unit.ai = false
                unit.generatedAt = null
                unit.persisted = false
                unit.bulk = null
                unit.bulkIssue = null
            },
            // Save & next goes on to the next item the filters show, and the last one
            // simply saves. A row that leaves the view hands focus on the same way.
            moveOn(id, next, after) {
                this.$nextTick(() => {
                    const target = (next && after) || ! this.shows(id) ? after : id

                    target ? this.focusField(target) : document.getElementById('rg-admin-translation-search')?.focus()
                })
            },
            toast(message, tone = 'success') {
                this.notify('rg-admin-toast', { message, tone })
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
                    result = await this.server().suggest(id, this.locale, unit.stored)
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
                // Volatile from now on, like any interactive suggestion; a background copy is let go when this is saved or discarded.
                unit.persisted = false
                this.announce(`${this.label} AI suggestion ready for ${name}. Not saved.`)
                this.$nextTick(() => this.focusField(id))
            },
            announce(message) {
                this.announcement = message
            },

            // Generate missing ----------------------------------------------------

            get generationRunning() {
                return this.generation !== null && ['queued', 'running'].includes(this.generation.status)
            },
            get readyCount() {
                return this.generation?.counts.ready ?? 0
            },
            // What Save all saves: the rows showing their untouched background suggestion. Rows edited
            // since, or whose suggestion the page did not put in — made from other English, overtaken —
            // are left out.
            get saveAllUnits() {
                return this.order.filter((id) => this.units[id].persisted && this.units[id].bulk === this.generation?.batch)
            },
            get offersGenerate() {
                return ! this.generationRunning && this.readyCount === 0
            },
            // “Generate missing (3)”, with the outdated translations counted beside them once there are any.
            get generateLabel() {
                if (this.outdated === 0) {
                    return `Generate missing (${this.figure(this.missing)})`
                }

                return this.missing === 0
                    ? `Generate outdated (${this.figure(this.outdated)})`
                    : `Generate missing (${this.figure(this.missing)}) & outdated (${this.figure(this.outdated)})`
            },
            // What Generate missing's dialog says it translates: “AI translates 3 missing items and 2 outdated ones”.
            get generateScope() {
                const parts = []

                this.missing > 0 && parts.push(`${this.figure(this.missing)} missing ${this.missing === 1 ? 'item' : 'items'}`)
                this.outdated > 0 && parts.push(`${this.figure(this.outdated)} outdated ${this.missing > 0 ? (this.outdated === 1 ? 'one' : 'ones') : (this.outdated === 1 ? 'item' : 'items')}`)

                return `AI translates ${parts.join(' and ')}`
            },
            get generationText() {
                const counts = this.generation?.counts

                if (! counts) {
                    return ''
                }

                if (this.generationRunning) {
                    return `Generating ${this.figure(counts.total - counts.pending)} of ${this.figure(counts.total)} ${counts.total === 1 ? 'translation' : 'translations'}…`
                }

                const parts = [`${this.figure(counts.ready)} ${counts.ready === 1 ? 'AI suggestion' : 'AI suggestions'} ready`]

                for (const [key, word] of [['failed', 'failed'], ['skipped', 'skipped'], ['saved', 'saved'], ['discarded', 'discarded']]) {
                    if (counts[key] > 0) {
                        parts.push(`${this.figure(counts[key])} ${word}`)
                    }
                }

                return parts.join(' · ')
            },
            get generationHint() {
                if (this.generationError) {
                    return this.generationError
                }

                return this.generationRunning
                    ? 'You can leave this page. Generation will continue in the background.'
                    : 'Generated drafts are temporary.'
            },

            // What the server says of the batch, applied to the rows. A suggestion goes into its row only
            // while the row is still missing or outdated, has no draft of the page's own and shows the
            // English the suggestion was made from: nothing typed is ever overwritten, no current
            // translation replaced, and no suggestion for other English shown.
            applyGeneration(generation) {
                if (generation?.unchanged && this.generation?.batch === generation.batch) {
                    this.generation = { ...this.generation, status: generation.status, counts: generation.counts, version: generation.version }

                    return
                }

                const batch = generation?.batch ?? null
                const items = Object.fromEntries((generation?.items ?? []).map((item) => [item.unit, item]))

                this.generation = generation ?? null

                for (const id of this.order) {
                    const unit = this.units[id]
                    const item = items[id] ?? null
                    const mine = unit.persisted && unit.bulk === batch
                    // What a background suggestion may fill: a missing translation or an outdated one.
                    const wanted = unit.stored === '' || unit.outdated

                    unit.bulkPending = false
                    unit.bulkIssue = null

                    // The batch is gone, or this row is no longer in it: its untouched suggestion goes too.
                    if (item === null || ! ['ready', 'pending'].includes(item.status)) {
                        if (unit.persisted) {
                            this.reset(id)
                        }

                        if (item !== null && ['failed', 'skipped'].includes(item.status) && wanted && ! this.isDirty(id)) {
                            unit.bulkIssue = item.message ?? null
                        }

                        continue
                    }

                    if (item.status === 'pending') {
                        unit.bulkPending = wanted && ! this.isDirty(id)

                        continue
                    }

                    if (mine || ! wanted || this.isDirty(id) || unit.generating) {
                        continue
                    }

                    if (item.fingerprint !== unit.fingerprint) {
                        unit.bulkIssue = 'English changed after this suggestion was generated. Generate a new translation.'

                        continue
                    }

                    // The AI kept an outdated translation as it was: nothing new to read, but saving confirms it for the new English.
                    if (unit.outdated && item.text === unit.stored) {
                        unit.bulkIssue = 'AI kept the saved translation for the new English. Save to mark it current.'
                    }

                    unit.value = item.text
                    unit.ai = true
                    unit.generatedAt = item.generatedAt
                    unit.error = null
                    unit.persisted = true
                    unit.bulk = batch
                }
            },
            schedulePoll(delay = 1000) {
                clearTimeout(this.pollTimer)

                if (this.generationRunning && ! this.destroyed) {
                    this.pollTimer = setTimeout(() => this.poll(), delay)
                }
            },
            // Once a second while generation runs and the tab is visible; a hidden tab waits for the
            // visibilitychange that brings it back. A failed read changes nothing and tries again.
            async poll() {
                clearTimeout(this.pollTimer)

                if (! this.generationRunning || document.visibilityState === 'hidden') {
                    return
                }

                const running = this.generationRunning
                let result = null

                try {
                    result = await this.server().generationStatus(this.locale, this.generation?.version ?? null)
                } catch (failure) {
                    result = null
                }

                if (this.destroyed) {
                    return
                }

                if (! result?.read) {
                    this.generationError = 'Progress could not be refreshed. Trying again…'
                    this.schedulePoll()

                    return
                }

                this.generationError = null
                this.applyGeneration(result.generation)

                if (running && ! this.generationRunning) {
                    this.announce(this.generation ? `${this.label} generation finished: ${this.generationText}.` : '')
                }

                this.schedulePoll()
            },
            askToGenerate() {
                if (this.missing + this.outdated > 0 && this.offersGenerate && ! this.generationBusy) {
                    this.pendingGenerate = true
                }
            },
            async startGeneration() {
                this.pendingGenerate = false
                this.generationBusy = true

                let result = null

                try {
                    result = await this.server().startGeneration(this.locale)
                } catch (failure) {
                    result = null
                }

                this.generationBusy = false

                if (! result?.started) {
                    this.toast(result?.error ?? 'Generation did not start: the server did not answer. Try again.', 'error')

                    return
                }

                this.applyGeneration(result.generation)
                this.announce(`Generating ${this.figure(result.generation.counts.total)} ${this.label} translations in the background.`)
                this.schedulePoll()
            },
            askToSaveAll() {
                if (this.saveAllUnits.length > 0 && ! this.generationBusy) {
                    this.pendingSaveAll = true
                }
            },
            async saveAll() {
                this.pendingSaveAll = false

                // Exactly the rows shown untouched: a suggestion that became ready since the last look is not saved unseen.
                const batch = this.generation?.batch
                const units = this.saveAllUnits
                let result = null

                this.generationBusy = true
                this.announce(`Saving ${this.figure(units.length)} generated ${this.label} translations…`)
                // Read-only while they are saved: a draft typed meanwhile would be written over.
                units.forEach((id) => this.units[id].saving++)

                try {
                    result = await this.server().saveAllGenerated(this.locale, batch, units)
                } catch (failure) {
                    result = null
                }

                units.forEach((id) => this.units[id].saving--)
                this.generationBusy = false

                if (! result?.saved) {
                    this.announce('')
                    this.toast(result?.error ?? 'Nothing was saved: the server did not answer. Try again.', 'error')

                    return
                }

                this.applySaved(result)

                // Made from English that has changed since: each such row says so, and Generate makes them again.
                const regenerate = result.results.filter((item) => item.outcome === 'source_changed').length
                const parts = [`${this.figure(result.count)} ${result.count === 1 ? 'translation' : 'translations'} saved`]

                regenerate > 0 && parts.push(`${this.figure(regenerate)} to generate again`)
                result.skipped - regenerate > 0 && parts.push(`${this.figure(result.skipped - regenerate)} skipped`)

                this.toast(parts.join(' · '), result.skipped > 0 ? 'info' : 'success')
                this.announce(parts.join(', '))
            },
            async saveGeneratedRow(id, next, after) {
                const unit = this.units[id]
                let result = null

                unit.saving++

                try {
                    result = await this.server().saveGenerated(this.locale, unit.bulk, id)
                } catch (failure) {
                    result = null
                }

                unit.saving--

                if (! result?.saved) {
                    this.toast(result?.error ?? 'Not saved: the server did not answer. Try again.', 'error')

                    return
                }

                this.applySaved(result)

                const outcome = result.results[0] ?? null

                if (outcome?.outcome === 'saved') {
                    this.toast(`${this.label} translation saved`)
                    this.moveOn(id, next, after)
                } else {
                    this.toast(outcome?.message ?? 'Not saved.', 'info')
                }
            },
            // What a save of background suggestions did, row by row: saved rows hold the text now;
            // skipped ones drop the suggestion and say why — and show a translation saved meanwhile.
            applySaved(result) {
                for (const item of result.results) {
                    const unit = this.units[item.unit]

                    if (! unit) {
                        continue
                    }

                    if (item.outcome === 'saved' || item.outcome === 'already_translated') {
                        this.stored(item.unit, item.value)
                    } else if (unit.persisted) {
                        this.reset(item.unit)
                    }

                    if (item.outcome !== 'saved') {
                        unit.bulkIssue = item.message ?? null
                    }
                }

                this.applyGeneration(result.generation)
            },
            // Lets one background suggestion go on the server — or, with no unit, every ready one.
            async discardGenerated(batch, unit) {
                let result = null

                try {
                    result = await this.server().discardGenerated(this.locale, batch, unit)
                } catch (failure) {
                    result = null
                }

                // One suggestion the server no longer holds as ready — saved, discarded or expired
                // meanwhile — cannot come back with a reload either: the row lets it go all the same.
                if (! result?.discarded && unit !== null && ['not_ready', 'unavailable'].includes(result?.reason)) {
                    this.units[unit] && this.reset(unit)

                    return true
                }

                if (! result?.discarded) {
                    this.toast(result?.error ?? 'Not discarded: the server did not answer. Try again.', 'error')

                    return false
                }

                if (unit === null) {
                    for (const id of this.order) {
                        this.units[id].persisted && this.reset(id)
                    }
                } else if (this.units[unit]) {
                    this.reset(unit)
                }

                this.applyGeneration(result.generation)

                return true
            },
            async discardAllGenerated() {
                if (! this.generation || this.generationBusy) {
                    return
                }

                this.generationBusy = true

                if (await this.discardGenerated(this.generation.batch, null)) {
                    this.toast('Generated suggestions discarded')
                }

                this.generationBusy = false
            },

            // Context -------------------------------------------------------------

            async openContext(id) {
                const context = await this.server().context(id, this.locale).catch(() => null)

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

                // Background suggestions are safe on the server: only volatile drafts make it ask.
                if (this.volatileDirtyCount > 0) {
                    this.pendingTarget = code

                    return
                }

                this.switchTo(code)
            },
            get switchText() {
                const count = this.volatileDirtyCount
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

                clearTimeout(this.pollTimer)
                this.switching = true
                window.rgAdminTranslationCenterRefocus = true

                // On success the server draws the new language and this component is replaced, its
                // drafts dropped with it. A request that fails changes nothing there, so the screen
                // stays usable on the language it shows — the drafts it holds included.
                Promise.resolve(this.server().$set('locale', code)).then(() => this.dropVolatile(), () => {
                    this.server().$set('locale', from, false)
                    this.switching = false
                    delete window.rgAdminTranslationCenterRefocus
                    this.schedulePoll()
                    this.toast('The language was not switched: the server did not answer. Try again.', 'error')
                })
            },
        }))

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
