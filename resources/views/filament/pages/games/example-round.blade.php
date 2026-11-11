@php
    /*
     * Beside a game's General form: what a round of its mechanic looks like to
     * a player, and the mechanic in a sentence. The round is an example — the
     * card says so — drawn from placeholders, not from the game's content;
     * Preview plays the game itself. Only the title is the game's own.
     */
    $example = match ($mechanic) {
        \App\Enums\GameMechanic::Versus => [
            'question' => 'Which one would you rather see in your feed?',
            'options' => [
                ['caption' => 'Beach at sunset', 'label' => 'Beach'],
                ['caption' => 'Mountain lake', 'label' => 'Mountain'],
            ],
            'note' => 'Players tap one photo, then see how the crowd voted on the site.',
        ],
    };
@endphp

<x-admin.ui.card class="rg-admin-games__example" title="Example round" :flush="true" :clip="true">
    <x-slot:aside>
        <x-admin.ui.badge tone="outline">{{ $mechanic->label() }}</x-admin.ui.badge>
    </x-slot:aside>

    <div class="rg-admin-games__example-body">
        <div class="rg-admin-games__example-screen" aria-hidden="true">
            <div class="rg-admin-games__example-progress">
                <span>Round 1</span>
                <span class="rg-admin-games__example-title">{{ $title }}</span>
            </div>
            <p class="rg-admin-games__example-question">{{ $example['question'] }}</p>
            <div class="rg-admin-games__example-options" style="--rg-admin-games-example-columns: {{ $mechanic->optionCount() }}">
                @foreach ($example['options'] as $option)
                    <div class="rg-admin-games__example-option">
                        <span class="rg-admin-games__example-tile">
                            <x-admin.ui.icon name="image" :size="22" />
                            <span>{{ $option['caption'] }}</span>
                        </span>
                        <span class="rg-admin-games__example-label">{{ $option['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="rg-admin-games__note"><strong class="rg-admin-games__example-mechanic">{{ $mechanic->label() }}.</strong> {{ $mechanic->description() }} {{ $example['note'] }}</p>
    </div>
</x-admin.ui.card>
