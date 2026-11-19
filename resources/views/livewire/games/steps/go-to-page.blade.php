{{-- Go to Page: a category, a tag, a page of the site or another site. --}}
<section class="rg-game__card" data-testid="game-step-go_to_page">
    @include('livewire.games.steps.onward')
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
