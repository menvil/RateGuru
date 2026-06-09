@props([
    'group',
    'options',
    'selectedOptionId' => null,
    'guest' => false,
    'disabled' => true,
    'isOwnPost' => false,
    'error' => '',
    'distribution' => [],
    'variant' => 'default',
    'testIdPrefix' => 'rating-option',
])

@php($voted = $selectedOptionId !== null)
@php($isBinary = count($options) === 2)

<div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold text-rg-text">{{ $group->translatedLabel() }}</h3>

    @if(! $voted)
        {{-- Vote buttons (no stats) while the user has not voted yet --}}
        <div class="{{ $variant === 'compact' ? 'flex flex-wrap gap-1.5' : 'flex flex-wrap gap-2' }}">
            @foreach($options as $option)
                @php($sizeClass = $variant === 'compact' ? '!h-7 !min-w-9 !px-2 !text-xs' : '')
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    :class="$sizeClass"
                    :disabled="$disabled"
                    aria-pressed="false"
                    data-state="idle"
                    data-testid="{{ $testIdPrefix }}-{{ $option->id }}"
                    :wire:click="$disabled ? false : 'vote('.$option->id.')'"
                    :wire:target="$disabled ? false : 'vote'"
                    :wire:loading.attr="$disabled ? false : 'disabled'"
                    :wire:loading.class="$disabled ? false : 'opacity-60 cursor-not-allowed'"
                >
                    {{ $option->translatedLabel() }}
                </x-ui.button>
            @endforeach
        </div>
    @elseif($isBinary)
        {{-- Binary group: side-by-side percentages with a single split bar --}}
        @php($optionsList = collect($options)->values())
        @php($optionA = $optionsList[0])
        @php($optionB = $optionsList[1])
        @php($resultA = $distribution[$optionA->id] ?? ['percent' => 0, 'count' => 0])
        @php($resultB = $distribution[$optionB->id] ?? ['percent' => 0, 'count' => 0])
        @php($pctA = (int) round($resultA['percent'] ?? 0))
        @php($pctB = (int) round($resultB['percent'] ?? 0))
        @php($total = ($resultA['count'] ?? 0) + ($resultB['count'] ?? 0))

        <div data-testid="{{ $testIdPrefix }}-results">
            <div class="mb-1 flex justify-between">
                <span class="text-[11.5px] font-semibold text-rg-good">{{ $optionA->translatedLabel() }}</span>
                <span class="text-[11.5px] text-rg-text2">{{ $optionB->translatedLabel() }}</span>
            </div>
            <div class="mb-1.5 flex justify-between">
                <span class="whitespace-nowrap text-[18px] font-bold text-rg-good">{{ $pctA }}% ({{ $resultA['count'] ?? 0 }})</span>
                <span class="whitespace-nowrap text-[18px] font-bold text-rg-text2">{{ $pctB }}% ({{ $resultB['count'] ?? 0 }})</span>
            </div>
            <div class="relative h-1.5 overflow-hidden rounded-rgPill bg-rg-card2">
                <div class="absolute bottom-0 left-0 top-0 rounded-rgPill bg-rg-good" style="width: {{ $pctA }}%"></div>
            </div>
            <div class="mt-1.5 text-[11px] text-rg-muted">{{ $total }} {{ $total === 1 ? 'vote' : 'votes' }}</div>
        </div>
    @else
        {{-- Multi-option group: per-option horizontal bars --}}
        <div class="flex flex-col gap-2">
            @foreach($options as $option)
                @php($active = (int) $selectedOptionId === (int) $option->id)
                @php($result = $distribution[$option->id] ?? null)
                @php($pct    = (int) round($result['percent'] ?? 0))
                @php($cnt    = $result['count'] ?? 0)
                <div
                    data-testid="{{ $testIdPrefix }}-{{ $option->id }}"
                    class="flex flex-col gap-1"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span @class([
                            'truncate text-xs font-medium',
                            'text-rg-accent' => $active,
                            'text-rg-text2'  => ! $active,
                        ])>{{ $option->translatedLabel() }}</span>
                        <span class="shrink-0 text-[11px] text-rg-muted">{{ $pct }}% &middot; {{ $cnt }} {{ $cnt === 1 ? 'vote' : 'votes' }}</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-rgPill bg-rg-card2">
                        <div
                            class="h-full rounded-rgPill transition-all duration-300 {{ $active ? 'bg-rg-accent' : 'bg-rg-border2' }}"
                            style="width: {{ $pct }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($guest && ! $voted)
        <p class="text-xs text-rg-muted">{{ __('ui.voting.sign_in_to_vote') }}</p>
    @elseif($error !== '')
        <p class="text-xs text-rg-danger">{{ $error }}</p>
    @endif
</div>
