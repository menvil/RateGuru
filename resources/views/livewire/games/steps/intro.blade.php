{{-- Intro and Instructions: a title, a few lines, and the way on. --}}
<section class="rg-game__card" data-testid="game-step-{{ $data['type'] }}">
    <h1 class="rg-game__title">{{ $texts['title'] }}</h1>
    @if (($texts['body'] ?? '') !== '')
        <div class="rg-game__lead rg-game__lines">{!! nl2br(e($texts['body'])) !!}</div>
    @endif
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ $texts['button_label'] }}</button>
</section>
