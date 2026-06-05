<div>
    @if($group !== null)
        <div data-testid="rating-voting-{{ $group->key }}">
            <x-voting.rating-options
                :group="$group"
                :options="$group->options"
                :selected-option-id="$selectedOptionId"
            />
        </div>
    @endif
</div>
