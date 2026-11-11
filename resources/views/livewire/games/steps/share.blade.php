{{-- Share: the result's text as it goes out, and where to send it. --}}
<section class="rg-game__card" data-testid="game-step-share">
    <h1 class="rg-game__title rg-game__title--sm">{{ ($texts['title'] ?? '') !== '' ? $texts['title'] : __('games.play.share_title') }}</h1>
    <p class="rg-game__quote">{{ $texts['body'] }}</p>
    @if ($data['result'] !== null)
        @include('livewire.games.steps.share-buttons', ['share' => [...$data['result']['share'], 'text' => $texts['body']]])
    @endif
    @if ($data['hasNext'])
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
    @endif
</section>
