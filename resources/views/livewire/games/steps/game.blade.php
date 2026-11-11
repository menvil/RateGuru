@php
    /*
     * The game: the pair on screen with its question, the picks made so far,
     * and — once picked — the reveal: each picture's share of upvotes over it,
     * the crowd's pick or the correct answer, the player's own pick, what it
     * earned, and the way on, by itself after the delay or by button. The
     * pair's frames follow its pictures — wide, upright or square — and a wide
     * pair stacks on a phone. The pick is marked the moment it is made; the
     * server judges it.
     */
    $round = $data['round'];
    $reveal = $round['reveal'];
    $auto = ($data['settings']['advance'] ?? 'auto') === 'auto';
    $seconds = (float) ($data['settings']['advance_seconds'] ?? 2.5);
@endphp
<section
    class="rg-game__round"
    wire:key="round-{{ $round['index'] }}-{{ $reveal === null ? 'question' : 'reveal' }}"
    x-data="rgGameRound({ round: @js($round['index']), revealed: @js($reveal !== null), auto: @js($auto), seconds: @js($seconds) })"
    data-testid="game-step-game"
    data-round="{{ $round['index'] }}"
>
    <div class="rg-game__progress">
        <span class="rg-game__eyebrow">{{ __('games.play.pair_of', ['current' => $round['index'] + 1, 'total' => $round['total']]) }}</span>
        {{-- The picks so far in colour, for the eye: the eyebrow says where the player is in words. --}}
        <ol class="rg-game__dots" aria-hidden="true">
            @foreach ($round['progress'] as $state)
                <li class="rg-game__dot rg-game__dot--{{ $state }}"></li>
            @endforeach
        </ol>
    </div>

    <h1 class="rg-game__question">{{ $round['question'] }}</h1>
    @if ($round['description'] !== '')
        <p class="rg-game__lead">{{ $round['description'] }}</p>
    @endif

    <div class="rg-game__pair rg-game__pair--{{ $round['shape'] }}">
        @foreach ($round['options'] as $option)
            @php
                $position = $option['position'];
                $picked = $reveal !== null && $reveal['position'] === $position;
                $isAnswer = $reveal !== null && $reveal['answer'] === $position;
                $share = $reveal['shares'][$position] ?? null;
                // What the reveal says of the picture, read after its name: a
                // button's label stands in for everything inside it.
                $described = array_filter([
                    $share !== null || $isAnswer ? 'game-option-'.$position.'-overlay' : null,
                    $picked ? 'game-option-'.$position.'-badge' : null,
                ]);
            @endphp
            <button
                type="button"
                @class(['rg-game__option', 'rg-game__option--picked' => $picked, 'rg-game__option--answer' => $isAnswer, 'rg-game__option--miss' => $picked && ! $isAnswer && $reveal['outcome'] === 'incorrect', 'rg-game__option--dim' => $reveal !== null && ! $picked && ! $isAnswer])
                x-bind:class="{ 'rg-game__option--picked': picked === {{ $position }} }"
                x-on:click="choose({{ $position }})"
                @disabled($reveal !== null)
                aria-label="{{ $option['label'] !== '' ? $option['label'] : __('games.play.pick_option', ['letter' => chr(65 + $position)]) }}"
                @if ($described !== []) aria-describedby="{{ implode(' ', $described) }}" @endif
                data-testid="game-option-{{ $position }}"
            >
                @if ($option['image'])
                    <img class="rg-game__image" src="{{ $option['image']->src }}" @if ($option['image']->srcset) srcset="{{ $option['image']->srcset }}" sizes="{{ $option['image']->sizes }}" @endif alt="{{ $option['label'] }}" @if ($option['image']->width) width="{{ $option['image']->width }}" height="{{ $option['image']->height }}" @endif decoding="async">
                @else
                    <span class="rg-game__image rg-game__image--missing">{{ __('games.play.image_missing') }}</span>
                @endif
                @if ($option['label'] !== '')
                    <span class="rg-game__label">{{ $option['label'] }}</span>
                @endif
                @if ($share !== null || $isAnswer)
                    <span class="rg-game__overlay" id="game-option-{{ $position }}-overlay">
                        @if ($share !== null)
                            <span class="rg-game__overlay-share" aria-hidden="true">{{ rtrim(rtrim(number_format($share, 1), '0'), '.') }}%</span>
                            <span class="sr-only">{{ __('games.play.upvoted', ['share' => rtrim(rtrim(number_format($share, 1), '0'), '.')]) }}</span>
                        @endif
                        @if ($isAnswer)
                            <span class="rg-game__overlay-label">{{ $reveal['crowd'] ? __('games.play.crowd_pick') : __('games.play.correct_answer') }}</span>
                        @endif
                    </span>
                @endif
                @if ($picked)
                    <span class="rg-game__badge" id="game-option-{{ $position }}-badge">{{ __('games.play.you_picked') }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Kept from the start, so the pictures already leave room for the verdict and the way on. --}}
    <div class="rg-game__after">
        @if ($reveal !== null)
            <div class="rg-game__verdict rg-game__verdict--{{ $reveal['outcome'] }}" role="status">
                <p class="rg-game__verdict-title">{{ $reveal['verdict'] }}</p>
                @if ($reveal['detail'] !== '')
                    <p class="rg-game__note">{{ $reveal['detail'] }}</p>
                @endif
                @if ($reveal['message'] !== '')
                    <p class="rg-game__note rg-game__note--message" title="{{ $reveal['message'] }}">{{ $reveal['message'] }}</p>
                @endif
            </div>
            <button type="button" class="rg-game__next" x-on:click="next()" data-testid="game-next">
                <span class="rg-game__timer" x-bind:style="`width: ${progress}%`" aria-hidden="true"></span>
                <span class="rg-game__button-text">{{ $reveal['last'] ? __('games.play.see_result') : __('games.play.next_pair') }} →</span>
            </button>
        @endif
    </div>

    @foreach ($round['preload'] as $src)
        <link rel="prefetch" as="image" href="{{ $src }}">
    @endforeach
</section>
