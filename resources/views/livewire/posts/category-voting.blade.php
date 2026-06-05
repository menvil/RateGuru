<div data-testid="category-voting">
    @if($post === null || $group === null)
        <span data-testid="category-voting-unavailable" class="text-xs text-rg-muted">Category voting unavailable</span>
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
                :variant="$variant"
                test-id-prefix="rating-option-{{ $post->id }}"
            />
    @endif
</div>
