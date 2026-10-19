{{--
    The browser's half of an editor page that saves from the top bar: how
    many of the draft's values differ from what is saved, and whether Save
    may run, for the top bar — a component of its own, which reads them from
    the `adminEditor` store. The page's root sets them with x-effect from its
    $wire draft and the server's `saved` copy, so a save, a discard or a value
    the server moved puts them back in step.

    Values compare as text, so 4 typed into a field and 4 saved are the same;
    nested values (a page's title, a feature flag) count one by one.

    Registered once, before Alpine starts; a re-render keeps the registration.
--}}
<script>
    (() => {
        const register = () => {
            if (! window.Alpine.store('adminEditor')) {
                window.Alpine.store('adminEditor', { changes: 0, blocked: false, saveLabel: null })
            }
        }

        const leaves = (value, path = '', into = {}) => {
            if (value !== null && typeof value === 'object') {
                Object.keys(value).forEach((key) => leaves(value[key], path === '' ? key : `${path}.${key}`, into))
            } else {
                into[path] = value === null || value === undefined ? '' : String(value)
            }

            return into
        }

        window.rgAdminEditorChanges = (draft, saved) => {
            const now = leaves(draft ?? {})
            const before = leaves(saved ?? {})

            return Object.keys(before).filter((path) => (now[path] ?? '') !== before[path]).length
        }

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
