@props([
    'selected' => false,
    'user' => 'pasta_lover',
    'time' => '2h ago',
    'score' => '128',
    'title' => 'Homemade or restaurant?',
    'dishLabel' => 'CARBONARA · 4 servings',
    'dishPalette' => 'carbonara',
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
            <x-ui.dish-placeholder :palette="$dishPalette" :label="$dishLabel" ratio="feed" />
        </div>

        <div class="mt-3 space-y-3">
            <p class="text-[13px] font-semibold text-rg-text2">What do you think?</p>
            <x-ui.binary-choice selected="homemade" />

            <div>
                <p class="mb-2 text-[13px] font-semibold text-rg-text2">Cuisine guess:</p>
                <div class="flex flex-wrap gap-2">
                    <x-ui.cuisine-chip active>IT</x-ui.cuisine-chip>
                    <x-ui.cuisine-chip>AS</x-ui.cuisine-chip>
                    <x-ui.cuisine-chip>US</x-ui.cuisine-chip>
                    <x-ui.cuisine-chip>MX</x-ui.cuisine-chip>
                    <x-ui.cuisine-chip>OT</x-ui.cuisine-chip>
                </div>
            </div>
        </div>

        <footer class="mt-3.5 flex items-center gap-4 border-t border-rg-border pt-2.5">
            <x-ui.action-button icon="comment">{{ $comments }}</x-ui.action-button>
            <x-ui.action-button icon="share">Share</x-ui.action-button>
            <x-ui.action-button icon="save">Save</x-ui.action-button>
            <x-ui.action-button icon="more" />
        </footer>
    </div>
</article>
