{{-- Share: the result's text as it goes out, where to send it, and the way on — or out, when the flow ends here. --}}
<section class="rg-game__card" data-testid="game-step-share">
    <x-games.headline :text="($texts['title'] ?? '') !== '' ? $texts['title'] : __('games.play.share_title')" size="sm" />
    <p class="rg-game__quote">{{ $texts['body'] }}</p>
    @if ($data['result'] !== null)
        @include('livewire.games.steps.share-buttons', ['share' => [...$data['result']['share'], 'text' => $texts['body']]])
    @endif
    @if ($data['hasNext'])
        {{-- The words and the arrow are one item of the button's flex row, so the space
             between them is a space, not the row's gap; the arrow turns round on a page
             read right to left (rg-mirror-rtl). --}}
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue"><span>{{ __('games.play.continue') }} <span class="rg-mirror-rtl" aria-hidden="true">→</span></span></button>
    @else
        <button type="button" class="rg-game__button rg-game__button--wide" wire:click="leave('feed')" data-testid="game-feed"><span>{{ __('games.play.go_to_feed') }} <span class="rg-mirror-rtl" aria-hidden="true">→</span></span></button>
    @endif
</section>
