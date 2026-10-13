@php
    /*
     * Acting on what a report points to (OVL-01), while the report stays
     * open: hiding the post (a required reason) or the comment (an optional
     * one), the report's own reason filled in; or banning the target's
     * author — a danger confirmation with what a ban does, and an optional
     * reason.
     */
    use App\Filament\Resources\Reports\Pages\ListReports;

    $hiding = $detail['confirmation'] === 'hide';
    $target = $detail['target'];
    $author = $detail['author'];
    $isPost = $target['kind'] === 'Post';
    $required = $hiding && $isPost;
    $moderator = auth()->user()?->name;
    // The confirm button waits for what the server will ask for anyway, as it is typed.
    $waiting = $required ? "(\$wire.reason ?? '').trim().length < 3" : 'false';
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-reports-confirm"
    :tone="$hiding ? 'warning' : 'danger'"
    :icon="$hiding ? 'eye-off' : 'ban'"
    :title="$hiding ? 'Hide the reported '.\Illuminate\Support\Str::lower($target['kind']).'?' : 'Ban '.$author['name'].'?'"
    wire:key="confirm-{{ $detail['confirmation'] }}-{{ $detail['id'] }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    @if ($hiding)
        <p>The {{ \Illuminate\Support\Str::lower($target['kind']) }} is hidden from visitors. Report #{{ $detail['id'] }} stays open, and you still resolve or ignore it.</p>
    @else
        <p>{{ $target['kind'] === 'Person' ? 'They' : 'The author of the reported '.\Illuminate\Support\Str::lower($target['kind']) }} can no longer take part: no new posts, comments, votes, reports or follows.</p>
    @endif

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon :name="$hiding ? $target['icon'] : 'user'" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $hiding ? $target['preview'] : $author['name'] }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $hiding ? $target['id'].' · '.$author['name'] : $author['status']['label'].' · '.$author['trust'].' · '.$author['stats'] }}</span>
            </span>
        </div>

        @unless ($hiding)
            <div>
                <div class="rg-admin-section-label">
                    <h3 class="rg-admin-section-label__text">What happens</h3>
                </div>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="log-in" :size="16" />They can still sign in, browse and manage their account.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="files" :size="16" />Their posts and comments stay as they are. Moderate them separately.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="rotate-ccw" :size="16" />Reversible later with Restore access on their user page.</li>
                </ul>
            </div>
        @endunless

        @include('filament.pages.moderation.reason', [
            'label' => $hiding ? 'Moderation reason' : 'Reason',
            'required' => $required,
            'limit' => ListReports::NOTE_LIMIT,
            'presets' => $hiding ? $reasons : $banReasons,
            'hint' => 'Stored in the moderation log.',
            'acknowledge' => null,
        ])
    </x-slot:details>

    <x-slot:footnote>{{ $moderator !== null ? 'Recorded in the moderation log as '.$moderator.'.' : 'Recorded in the moderation log.' }}</x-slot:footnote>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" :autofocus="! $required">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            :variant="$hiding ? 'primary' : 'danger'"
            wire:click="confirm"
            wire:loading.attr="disabled"
            x-bind:disabled="{{ $waiting }}"
        >{{ $hiding ? 'Hide '.\Illuminate\Support\Str::lower($target['kind']) : 'Ban user' }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
