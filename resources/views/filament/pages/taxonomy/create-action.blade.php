{{-- The top bar's primary action for Categories, Tags and Personas: the page opens its drawer on the event. --}}
<x-admin.ui.button variant="primary" icon="plus" x-data x-on:click="$dispatch('{{ $event }}')">{{ $label }}</x-admin.ui.button>
