<div data-testid="profile-page" class="mx-auto w-full max-w-4xl">
    <section
        data-testid="profile-header"
        class="rounded-rgCard border border-rg-border bg-rg-card p-5 shadow-rgPopover sm:p-6"
    >
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold text-rg-text">{{ $profileUser->name ?: $profileUser->username }}</h1>
                <p class="mt-1 text-sm text-rg-muted">{{ '@' . $profileUser->username }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                {{-- Profile actions are added in later Phase 30 tasks. --}}
            </div>
        </div>
    </section>
</div>
