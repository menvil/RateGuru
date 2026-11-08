{{-- The games library's primary action, in the top bar: a new game opens on its own page. --}}
<x-admin.ui.button variant="primary" icon="plus" :href="\App\Filament\Resources\Games\GameResource::getUrl('create')">New game</x-admin.ui.button>
