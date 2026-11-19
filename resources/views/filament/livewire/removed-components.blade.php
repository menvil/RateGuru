{{--
    Every admin page: a Livewire component that has left the page is never
    drawn again.

    Livewire morphs a component with each answer the server sends it, even
    one that arrives after the component was removed. That happens whenever
    a nested editor closes with a field still pending: wire:model.live.debounce
    keeps its timer when the field's component is removed, so a drawer saved
    or cancelled within the debounce of the last keystroke sends that field
    afterwards, and a request sent just before the parent removed the
    component is answered after it too.

    Drawing it morphs the component's detached elements, and Alpine evaluates
    the new markup against the scopes it took down when the component was
    removed: `closing` is not defined on the drawer, `selected` on a
    segmented control. So the drawing is all that is skipped. The request
    still counts — a change an editor saves as it is made is kept — and what
    the answer dispatches still reaches the page.
--}}
<script>
    (() => {
        const register = () => window.Livewire.interceptMessage(({ message, onSuccess }) => {
            onSuccess(({ payload }) => {
                if (! message.component.el.isConnected) {
                    delete payload.effects.html
                }
            })
        })

        window.Livewire ? register() : document.addEventListener('livewire:init', register, { once: true })
    })()
</script>
