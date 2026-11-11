{{-- Share: the result's text as it goes out, where to send it, and the way on — or out, when the flow ends here. --}}
<section class="rg-game__card" data-testid="game-step-share">
    <h1 class="rg-game__title rg-game__title--sm">{{ ($texts['title'] ?? '') !== '' ? $texts['title'] : __('games.play.share_title') }}</h1>
    <p class="rg-game__quote">{{ $texts['body'] }}</p>
    @if ($data['result'] !== null)
        @include('livewire.games.steps.share-buttons', ['share' => [...$data['result']['share'], 'text' => $texts['body']]])
    @endif
    @if ($data['hasNext'])
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
    @else
        <button type="button" class="rg-game__button rg-game__button--wide" wire:click="leave('feed')" data-testid="game-feed">{{ __('games.play.go_to_feed') }} →</button>
    @endif
</section>
