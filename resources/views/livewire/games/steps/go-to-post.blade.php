{{-- Go to Post: a post to vote on and rate, on its own page. --}}
<section class="rg-game__card" data-testid="game-step-go_to_post">
    @include('livewire.games.steps.onward')
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
