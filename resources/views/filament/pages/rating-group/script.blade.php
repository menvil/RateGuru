{{--
    The browser's half of the group editor: whether the settings have
    unsaved changes, and whether Save may run, for the top bar — a component
    of its own, which reads them from one Alpine store. The draft is the
    page's $wire properties as typed; `saved` is what the server last saved,
    so a save, a discard or a raised maximum puts it back in step.

    Registered once, before Alpine starts; a re-render keeps the registration.
--}}
<script>
    (() => {
        const register = () => {
            window.Alpine.store('ratingGroupEditor', { dirty: false, blocked: false })
        }

        window.rgAdminRatingGroupDirty = ($wire) => {
            const draft = {
                key: $wire.draftKey,
                label: $wire.draftLabel,
                description: $wire.draftDescription,
                min: $wire.draftMin,
                max: $wire.draftMax,
                sort: $wire.draftSort,
                active: $wire.draftActive,
            }

            return Object.keys($wire.saved ?? {}).some((field) => String(draft[field] ?? '') !== String($wire.saved[field] ?? ''))
        }

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
