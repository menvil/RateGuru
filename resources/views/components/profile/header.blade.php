@props(['user'])

<div data-testid="profile-header" class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div class="flex min-w-0 items-center gap-4">
        <div data-testid="profile-avatar">
            <x-ui.avatar
                :src="$user->resolved_avatar_url"
                :name="$user->resolved_display_name"
                size="xl"
            />
        </div>

        <div data-testid="profile-identity" class="min-w-0">
            <h1 class="truncate text-2xl font-semibold text-rg-text">{{ $user->resolved_display_name }}</h1>
            <p class="mt-0.5 text-sm text-rg-muted">{{ '@' . $user->username }}</p>

            @if($user->bio)
                <p class="mt-2 max-w-md text-sm leading-relaxed text-rg-text2" data-testid="profile-bio">{{ $user->bio }}</p>
            @endif

            @if($user->profile_website_url)
                <a
                    href="{{ $user->profile_website_url }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-1 inline-flex items-center gap-1 text-xs text-rg-accent hover:underline"
                    data-testid="profile-website"
                >
                    {{ $user->profile_website_url }}
                </a>
            @endif
        </div>
    </div>

    @if(isset($actions))
        <div class="flex shrink-0 items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
