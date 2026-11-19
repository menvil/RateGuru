{{-- Go to Feed: the way out to the site's feed. --}}
<section class="rg-game__card" data-testid="game-step-feed">
    @include('livewire.games.steps.onward')
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
