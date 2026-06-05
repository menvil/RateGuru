<div>
    @if($group !== null && $post !== null)
        <div data-testid="rating-voting-{{ $group->key }}-{{ $post->id }}">
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
        </div>
    @endif
</div>
