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
     * card; then sharing and the way on, with Play again.
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
            <p @class(['rg-game__result-headline', 'rg-game__result-headline--long' => mb_strlen($description) > 80]) data-testid="game-profile-description">{{ $description }}</p>
        @endif
        <div class="rg-game__share-card">
            @if ($picture)
                <img class="rg-game__share-card-image" src="{{ $picture->src }}" @if ($picture->srcset) srcset="{{ $picture->srcset }}" sizes="{{ $picture->sizes }}" @endif alt="">
            @else
                <span class="rg-game__share-card-glow" aria-hidden="true"><x-ui.icon :name="$showProfile ? ($profile['icon'] ?? 'award') : 'award'" class="size-16" /></span>
            @endif
            <span class="rg-game__share-card-shade" aria-hidden="true"></span>
            <div class="rg-game__share-card-text">
                <span class="rg-game__share-card-game">{{ $game['title'] }}</span>
                @if ($showProfile)
                    <h1 class="rg-game__share-card-profile" data-testid="game-profile">{{ $profile['name'] }}</h1>
                @else
                    <h1 class="sr-only">{{ __('games.play.your_result') }}</h1>
                @endif
                <span class="rg-game__share-card-line" @if ((bool) ($settings['show_score'] ?? true)) data-testid="game-score" @endif>{{ (bool) ($settings['show_score'] ?? true) ? $score.' · ' : '' }}<span class="rg-game__share-card-host" data-testid="game-byline">{{ $result['share']['byline'] }}</span></span>
            </div>
        </div>
    </div>

    <div class="rg-game__result-side">
        @if ($data['signup'] !== null)
            @include('livewire.games.steps.signup', ['signup' => $data['signup'], 'preview' => $data['preview'], 'stepKey' => $data['stepKey']])
        @endif

        @if ($result['named'] !== null)
            <p class="rg-game__named" role="status" data-testid="game-named"><x-ui.icon name="check" class="size-4" />{{ __('games.play.name_on_card', ['name' => $result['named']]) }}</p>
        @endif

        @if ((bool) ($settings['share_enabled'] ?? true))
            @include('livewire.games.steps.share-buttons', ['share' => $result['share']])
        @endif

        @if ($data['hasNext'])
            <button type="button" class="rg-game__button rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
        @else
            <button type="button" class="rg-game__button rg-game__button--wide" wire:click="leave('feed')" data-testid="game-feed">{{ __('games.play.go_to_feed') }} →</button>
        @endif
        @if ($data['canReplay'])
            <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
        @endif
    </div>
</section>
