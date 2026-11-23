@php
    /*
     * Full Result: the profile's description large above the card that goes
     * out when the result is shared — the profile's own picture, or the
     * game's cover, or its sign in the accent's light, though a profile the
     * step hides shows neither its picture nor its sign; the game, the profile,
     * the score and the player's @name on it, or the site's address while they
     * have no account. Beside it, for an anonymous visitor, sign-up first when
     * the step asks for it here — the quickest way to an account is the point
     * of the screen; once they signed up or in, word that their name is on the
     * card; then the way on, with Play again. Sharing sits right under the
     * card, on a phone too.
     */
    $result = $data['result'];
    $settings = $data['settings'];
    $profile = $result['profile'] ?? null;
    $showProfile = $profile !== null && (bool) ($settings['show_profile'] ?? true);
    $picture = ($showProfile ? ($profile['image'] ?? null) : null) ?: $game['cover'];
    $score = $result['byCrowd']
        ? __('games.play.matched_crowd', ['matches' => $result['matches'], 'total' => $result['scored']])
        : __('games.play.matched_right', ['matches' => $result['matches'], 'total' => $result['scored']]);
    $score .= $result['points'] > 1 ? ' · '.__('games.play.score', ['score' => $result['score'], 'max' => $result['max']]) : '';
    $description = $showProfile ? $profile['description'] : '';
@endphp
<section class="rg-game__result" data-testid="game-step-full_result">
    <div class="rg-game__result-card">
        @if ($description !== '')
            <x-games.headline :text="$description" as="p" size="result" data-testid="game-profile-description" />
        @endif
        <div class="rg-game__share-card">
            @if ($picture)
                <img class="rg-game__share-card-image" src="{{ $picture->src }}" @if ($picture->srcset) srcset="{{ $picture->srcset }}" sizes="{{ $picture->sizes }}" @endif alt="">
            @else
                <span class="rg-game__share-card-glow" aria-hidden="true"><x-ui.icon :name="$showProfile ? ($profile['icon'] ?? 'award') : 'award'" class="size-16" /></span>
            @endif
            <span class="rg-game__share-card-shade" aria-hidden="true"></span>
            <div class="rg-game__share-card-text">
                <span class="rg-game__share-card-game" {{ \App\Support\Locale\WrittenText::dir($game['title']) }}>{{ $game['title'] }}</span>
                @if ($showProfile)
                    <h1 class="rg-game__share-card-profile" {{ \App\Support\Locale\WrittenText::dir($profile['name']) }} data-testid="game-profile">{{ $profile['name'] }}</h1>
                @else
                    <h1 class="sr-only">{{ __('games.play.your_result') }}</h1>
                @endif
                <span class="rg-game__share-card-line" @if ((bool) ($settings['show_score'] ?? true)) data-testid="game-score" @endif>{{ (bool) ($settings['show_score'] ?? true) ? $score.' · ' : '' }}<span class="rg-game__share-card-host" dir="auto" data-testid="game-byline">{{ $result['share']['byline'] }}</span></span>
            </div>
        </div>

        @if ((bool) ($settings['share_enabled'] ?? true))
            @include('livewire.games.steps.share-buttons', ['share' => $result['share']])
        @endif
    </div>

    <div class="rg-game__result-side">
        @if ($data['onward'] !== null)
            {{-- Sent on into the site: the way in; sign-up waits for the site. --}}
            @include('livewire.games.steps.onward')
            @if ($data['canReplay'])
                <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
            @endif
        @else
        @if ($data['signup'] !== null)
            @include('livewire.games.steps.signup', ['signup' => $data['signup'], 'preview' => $data['preview'], 'stepKey' => $data['stepKey']])
        @endif

        @if ($result['named'] !== null)
            {{-- The sentence is one item of the row beside the tick, so the space before the @name stays a space. --}}
            <p class="rg-game__named" role="status" data-testid="game-named"><x-ui.icon name="check" class="size-4" /><span>{{ \App\Support\Locale\IsolatedTranslation::line('games.play.name_on_card', ['name' => $result['named']]) }}</span></p>
        @endif

        @if ($data['hasNext'])
            {{-- The words and the arrow are one item of the button's flex row, so the space
                 between them is a space, not the row's gap; the arrow turns round on a page
                 read right to left (rg-mirror-rtl). --}}
            <button type="button" class="rg-game__button rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue"><span>{{ __('games.play.continue') }} <span class="rg-mirror-rtl" aria-hidden="true">→</span></span></button>
        @else
            <button type="button" class="rg-game__button rg-game__button--wide" wire:click="leave('feed')" data-testid="game-feed"><span>{{ __('games.play.go_to_feed') }} <span class="rg-mirror-rtl" aria-hidden="true">→</span></span></button>
        @endif
        @if ($data['canReplay'])
            <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
        @endif
        @endif
    </div>
</section>
