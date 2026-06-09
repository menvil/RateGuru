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

<div class="flex flex-col gap-3" data-testid="rating-options">
    <h3 class="text-sm font-semibold text-rg-text">{{ $group->translatedLabel() }}</h3>

    @if(! $voted)
        {{-- Vote buttons (no stats) while the user has not voted yet --}}
        <div class="{{ $variant === 'compact' ? 'flex flex-wrap gap-1.5' : 'flex flex-wrap gap-2' }}">
            @foreach($options as $option)
                @php($sizeClass = $variant === 'compact' ? '!h-7 !min-w-9 !px-2 !text-xs' : '!min-h-[40px]')
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
        @php($cntA = (int) ($distribution[$optionA->id]['count'] ?? 0))
        @php($cntB = (int) ($distribution[$optionB->id]['count'] ?? 0))
        @php($total = $cntA + $cntB)
        {{-- Compute percentages directly from the displayed counts so they always agree --}}
        @php($pctA = $total > 0 ? (int) round($cntA / $total * 100) : 0)
        @php($pctB = $total > 0 ? 100 - $pctA : 0)

        <div data-testid="{{ $testIdPrefix }}-results">
            <div class="mb-1 flex justify-between">
                <span class="text-[11.5px] font-semibold text-rg-good">{{ $optionA->translatedLabel() }}</span>
                <span class="text-[11.5px] text-rg-text2">{{ $optionB->translatedLabel() }}</span>
            </div>
            <div class="mb-1.5 flex justify-between">
                <span class="whitespace-nowrap text-[18px] font-bold text-rg-good">{{ $pctA }}% ({{ $cntA }})</span>
                <span class="whitespace-nowrap text-[18px] font-bold text-rg-text2">{{ $pctB }}% ({{ $cntB }})</span>
            </div>
            <div class="relative h-1.5 overflow-hidden rounded-rgPill bg-rg-card2">
                <div class="absolute bottom-0 left-0 top-0 rounded-rgPill bg-rg-good" style="width: {{ $pctA }}%"></div>
            </div>
        </div>
    @else
        {{-- Multi-option group: per-option horizontal bars --}}
        @php($multiTotal = collect($options)->sum(fn ($o) => (int) ($distribution[$o->id]['count'] ?? 0)))
        <div class="flex flex-col gap-2">
            @foreach($options as $option)
                @php($active = (int) $selectedOptionId === (int) $option->id)
                @php($cnt    = (int) ($distribution[$option->id]['count'] ?? 0))
                @php($pct    = $multiTotal > 0 ? (int) round($cnt / $multiTotal * 100) : 0)
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
