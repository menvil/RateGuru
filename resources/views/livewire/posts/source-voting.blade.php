<div data-testid="source-voting">
    @if($post === null || $group === null)
        <span data-testid="source-voting-unavailable" class="text-xs text-rg-muted">Source voting unavailable</span>
    @else
            <x-voting.rating-options
                :group="$group"
                :options="$group->options"
                :selected-option-id="$selectedOptionId"
                :guest="! auth()->check()"
                :disabled="$votingDisabled"
                :is-own-post="$isOwnPost"
                :error="$error"
                :distribution="$distribution"
                test-id-prefix="rating-option-{{ $post->id }}"
            />
    @endif
</div>
