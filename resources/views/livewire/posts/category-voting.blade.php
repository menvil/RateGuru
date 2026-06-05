<div>
    @if($group !== null)
        <div data-testid="category-voting">
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
            />
        </div>
    @endif
</div>
