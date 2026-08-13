<div data-testid="recently-deleted-page" class="mx-auto w-full max-w-[820px] min-w-0 px-4 py-6 sm:px-6">
    <h1 class="mb-2 text-xl font-bold text-rg-text">{{ __('ui.recently_deleted.title') }}</h1>
    <p class="mb-6 text-sm text-rg-muted">{{ __('ui.recently_deleted.description', ['days' => $retentionDays]) }}</p>

    @if($statusMessage)
        <div data-testid="recently-deleted-status" class="mb-4 rounded-lg border border-rg-border bg-rg-surface px-4 py-3 text-sm text-rg-text">
            {{ $statusMessage }}
        </div>
    @endif

    @if($posts->isEmpty())
        <div data-testid="recently-deleted-empty-state" class="flex flex-col items-center justify-center py-16 text-center">
            <x-ui.icon name="trash" class="mb-4 size-10 text-rg-muted" />
            <p class="text-base font-semibold text-rg-text">{{ __('ui.recently_deleted.empty_title') }}</p>
            <p class="mt-1 text-sm text-rg-muted">{{ __('ui.recently_deleted.empty_description') }}</p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach($posts as $post)
                @php
                    $deadline = $post->authorRestoreDeadline();
                    $restorable = $post->isAuthorRestorable();
                    $daysLeft = $deadline !== null ? (int) max(0, now()->diffInDays($deadline, false)) : 0;
                @endphp
                <li
                    data-testid="recently-deleted-item"
                    wire:key="deleted-post-{{ $post->id }}"
                    class="flex flex-col gap-3 rounded-xl border border-rg-border bg-rg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        {{-- Plain text, never a public link: the post is publicly gone. --}}
                        <p class="truncate text-sm font-semibold text-rg-text">{{ $post->title }}</p>
                        <p class="mt-0.5 text-xs text-rg-muted">
                            {{ __('ui.recently_deleted.deleted_on', ['date' => $post->deleted_at?->translatedFormat('j M Y, H:i')]) }}
                        </p>
                        <p class="text-xs {{ $restorable ? 'text-rg-muted' : 'text-rg-danger' }}">
                            @if($restorable)
                                {{ trans_choice('ui.recently_deleted.days_left', $daysLeft, ['count' => $daysLeft, 'date' => $deadline?->translatedFormat('j M Y')]) }}
                            @else
                                {{ __('ui.recently_deleted.expired') }}
                            @endif
                        </p>
                    </div>
                    <div class="shrink-0">
                        @if($restorable)
                            <button
                                type="button"
                                data-testid="restore-post-{{ $post->id }}"
                                wire:click="restore({{ $post->id }})"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rg-border bg-rg-bg px-3 py-1.5 text-sm font-medium text-rg-text transition hover:border-rg-accent hover:text-rg-accent"
                            >
                                <x-ui.icon name="restore" class="size-4" />
                                {{ __('ui.recently_deleted.restore') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    @endif
</div>
