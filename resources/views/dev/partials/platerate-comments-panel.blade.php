<section data-ui="comments-panel" class="rounded-rgCard border border-rg-border bg-rg-card p-5">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-bold text-rg-text">Comments (34)</h3>
        <button type="button" class="text-xs font-semibold text-rg-text2">Top</button>
    </div>

    <div class="mt-4 flex h-[42px] items-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card2 px-3">
        <span class="flex-1 text-[13px] text-rg-muted">Add a comment...</span>
        <x-ui.button size="sm">Post</x-ui.button>
    </div>

    <div class="mt-5 space-y-4">
        @foreach ([
            ['reviewer_one', '2h ago', 'Option A fits the description and presentation best.', 'blue', false],
            ['reviewer_two', '2h ago', 'I picked Option B because of the details near the edge.', 'yellow', false],
            ['reviewer_one', '1h ago', 'Fair point — the lighting changes how it reads.', 'blue', true],
            ['attribute_fan', '3h ago', 'Attribute A looks like the closest match.', 'green', false],
        ] as [$user, $time, $body, $color, $reply])
            <div class="{{ $reply ? 'ml-4 border-l border-rg-border pl-4' : '' }} grid grid-cols-[32px_1fr] gap-2.5 text-[13px]">
                <x-ui.avatar :name="$user" :color="$color" size="md" />
                <div>
                    <p><span class="font-semibold text-rg-text">{{ $user }}</span> <span class="text-xs text-rg-muted">{{ $time }}</span></p>
                    <p class="mt-1 leading-5 text-rg-text2">{{ $body }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
