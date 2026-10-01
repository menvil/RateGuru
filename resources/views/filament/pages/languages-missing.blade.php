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
                    <li class="flex items-center justify-between gap-4">
                        <span>✗ {{ $item['label'] }} → {{ $item['field'] }}</span>
                        <x-filament::link :href="$item['url']" size="sm">Edit</x-filament::link>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @empty
        <p class="text-sm">Every piece of project content has a translation.</p>
    @endforelse
</div>
