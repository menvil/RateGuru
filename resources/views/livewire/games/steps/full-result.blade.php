@php
    /*
     * Full Result: the profile, the score and how often the player matched,
     * as the step shows them; sharing; for an anonymous visitor, sign-up on
     * the result when the step invites it there; then on, and Play again.
     */
    $result = $data['result'];
    $settings = $data['settings'];
    $profile = $result['profile'] ?? null;
@endphp
<section class="rg-game__card rg-game__card--result" data-testid="game-step-full_result">
    <p class="rg-game__eyebrow">{{ __('games.play.your_result') }}</p>
    @if ($profile !== null && (bool) ($settings['show_profile'] ?? true))
        @if ($profile['image'])
            <img class="rg-game__result-image" src="{{ $profile['image']->src }}" @if ($profile['image']->srcset) srcset="{{ $profile['image']->srcset }}" sizes="{{ $profile['image']->sizes }}" @endif alt="">
        @else
            <span class="rg-game__badge-icon" aria-hidden="true"><x-ui.icon :name="$profile['icon']" class="size-8" /></span>
        @endif
        <h1 class="rg-game__title" data-testid="game-profile">{{ $profile['name'] }}</h1>
        @if ($profile['description'] !== '')
            <p class="rg-game__lead">{{ $profile['description'] }}</p>
        @endif
    @endif
    @if ((bool) ($settings['show_score'] ?? true))
        <p class="rg-game__score" data-testid="game-score">{{ $result['byCrowd'] ? __('games.play.matched_crowd', ['matches' => $result['matches'], 'total' => $result['scored']]) : __('games.play.matched_right', ['matches' => $result['matches'], 'total' => $result['scored']]) }}@if ($result['points'] > 1) · {{ __('games.play.score', ['score' => $result['score'], 'max' => $result['max']]) }}@endif</p>
    @endif
    @if ((bool) ($settings['show_agreement'] ?? true) && $result['showAgreement'])
        <p class="rg-game__note">{{ __('games.play.agreement', ['percent' => $result['agreement']]) }}</p>
    @endif

    @if ((bool) ($settings['share_enabled'] ?? true))
        @include('livewire.games.steps.share-buttons', ['share' => $result['share']])
    @endif

    @if ($data['signup'])
        <div class="rg-game__signup" data-testid="game-signup">
            <p class="rg-game__subtitle">{{ $texts['title'] }}</p>
            @if (($texts['body'] ?? '') !== '')
                <p class="rg-game__note">{{ $texts['body'] }}</p>
            @endif
            <div class="rg-game__actions">
                <button type="button" class="rg-game__button rg-game__button--primary" wire:click="signUp('register')" data-testid="game-signup-register">{{ __('games.play.sign_up') }}</button>
                <button type="button" class="rg-game__link" wire:click="signUp('login')">{{ __('games.play.log_in') }}</button>
            </div>
        </div>
    @endif

    <div class="rg-game__actions">
        @if ($data['hasNext'])
            <button type="button" class="rg-game__button rg-game__button--primary" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
        @else
            <button type="button" class="rg-game__button rg-game__button--primary" wire:click="leave('feed')" data-testid="game-feed">{{ __('games.play.go_to_feed') }}</button>
        @endif
        @if ($data['canReplay'])
            <button type="button" class="rg-game__button" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
        @endif
    </div>
</section>
