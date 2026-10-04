@props([
    'selected' => false,
    'user' => 'sample_author',
    'time' => '2h ago',
    'score' => '128',
    'title' => 'Which option fits best?',
    'imageLabel' => 'SAMPLE POST · IMAGE 01',
    'imagePalette' => 'warm',
    'comments' => '34',
    'avatarColor' => 'purple',
])

<article data-ui="post-card" class="{{ $selected ? 'border-rg-accent shadow-rgSelected' : 'border-rg-border' }} grid grid-cols-[32px_1fr] gap-2 rounded-rgCard border bg-rg-card p-[14px]">
    <x-ui.vote-rail :score="$score" :active="$selected ? 'up' : 'none'" />

    <div class="min-w-0">
        <div class="flex items-center gap-2">
            <x-ui.avatar :name="$user" :color="$avatarColor" size="md" />
            <span class="text-[13px] font-semibold text-rg-text">{{ $user }}</span>
            <span class="text-xs text-rg-muted">{{ $time }}</span>
        </div>

        <h3 class="mt-3 text-base font-bold text-rg-text">{{ $title }}</h3>

        <div class="mt-3">
            <x-ui.image-placeholder :palette="$imagePalette" :label="$imageLabel" ratio="feed" />
        </div>

        <div class="mt-3 space-y-3">
            <p class="text-[13px] font-semibold text-rg-text2">What do you think?</p>
            <x-ui.binary-choice selected="option_a" />

            <div>
                <p class="mb-2 text-[13px] font-semibold text-rg-text2">Choose an attribute:</p>
                <div class="flex flex-wrap gap-2">
                    <x-ui.rating-option-chip active>A</x-ui.rating-option-chip>
                    <x-ui.rating-option-chip>B</x-ui.rating-option-chip>
                    <x-ui.rating-option-chip>C</x-ui.rating-option-chip>
                    <x-ui.rating-option-chip>D</x-ui.rating-option-chip>
                    <x-ui.rating-option-chip>OT</x-ui.rating-option-chip>
                </div>
            </div>
        </div>

        <footer class="mt-3.5 flex items-center gap-4 border-t border-rg-border pt-2.5">
            <x-ui.action-button icon="comment">{{ $comments }}</x-ui.action-button>
            <x-ui.action-button icon="share">Share</x-ui.action-button>
            <x-ui.action-button icon="bookmark">Save</x-ui.action-button>
            <x-ui.action-button icon="more" />
        </footer>
    </div>
</article>
