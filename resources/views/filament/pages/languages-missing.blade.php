@php
    /** @var \App\Support\Translations\TranslationCatalogReport $catalog */
@endphp

<div class="space-y-6">
    @unless ($catalog->isComplete())
        <x-filament::section>
            <x-slot name="heading">Application translations</x-slot>
            <x-slot name="description">The release itself breaks the catalog contract for this language, so it cannot be enabled.</x-slot>

            <ul class="space-y-1 text-sm">
                @foreach (array_slice($catalog->issues, 0, 50) as $issue)
                    <li>✗ {{ $issue->message }}</li>
                @endforeach

                @if (count($catalog->issues) > 50)
                    <li>… and {{ count($catalog->issues) - 50 }} more</li>
                @endif
            </ul>
        </x-filament::section>
    @endunless

    @forelse ($sections as $section)
        <x-filament::section>
            <x-slot name="heading">{{ $section['label'] }}</x-slot>

            <ul class="space-y-2 text-sm">
                @foreach ($section['items'] as $item)
                    <li class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span>✗ {{ $item['label'] }} → {{ $item['field'] }}</span>
                                <x-filament::badge size="sm" :color="$item['reason_color']">{{ $item['reason'] }}</x-filament::badge>
                            </div>

                            @if ($item['explanation'] !== null)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['explanation'] }}</p>
                            @endif
                        </div>

                        <x-filament::link :href="$item['url']" size="sm">Edit</x-filament::link>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @empty
        <p class="text-sm">Every piece of project content has a translation.</p>
    @endforelse
</div>
