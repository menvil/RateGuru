<div>
    @if($group !== null)
        <div data-testid="rating-voting-{{ $group->key }}">
            <x-voting.rating-options
                :group="$group"
                :options="$group->options"
                :selected-option-id="$selectedOptionId"
                :guest="! auth()->check()"
                :disabled="$votingDisabled"
                :is-own-post="$isOwnPost"
                :error="$error"
                :distribution="$distribution"
            />
        </div>
    @endif
</div>
