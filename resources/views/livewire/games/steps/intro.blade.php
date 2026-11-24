{{-- Intro and Instructions: a title, a few lines, and the way on. --}}
<section class="rg-game__card" data-testid="game-step-{{ $data['type'] }}">
    <x-games.headline :text="$shown['title']" />
    @if (($texts['body'] ?? '') !== '')
        <div class="rg-game__lead rg-game__lines" {{ $shown['body']->dir() }}>{{ $shown['body']->lines() }}</div>
    @endif
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ $shown['button_label']->inline() }}</button>
</section>
