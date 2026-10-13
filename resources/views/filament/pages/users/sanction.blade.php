@php
    /*
     * The moderation confirmations on a person (OVL-01), shared by the Users
     * list and the person's page: Limit (warning), Shadowban and Ban (danger),
     * each with what it does — as the lifecycle has it: a sanction stops
     * taking part and never locks anyone out of their account — and an
     * optional reason; Mark trusted (light), with no reason to give.
     */
    use App\Filament\Resources\Users\Pages\ListUsers;

    $kind = $dialog['kind'];
    $name = $dialog['username'];

    [$tone, $icon, $title, $body, $confirm, $presets] = match ($kind) {
        'ban' => ['danger', 'ban', 'Ban '.$name.'?', 'Use for accounts that should no longer take part.', 'Ban user', ['Repeated spam', 'Harassment', 'Scam', 'Ban evasion']],
        'limit' => ['warning', 'shield-alert', 'Limit '.$name.'?', 'A restriction for someone whose activity needs closer review.', 'Limit user', ['Low-quality posts', 'Borderline spam', 'Rule reminders ignored']],
        'shadowban' => ['danger', 'eye-off', 'Shadowban '.$name.'?', 'The same restrictions as a ban, kept apart for accounts that look suspicious.', 'Shadowban user', ['Spam network', 'Ban evasion', 'Coordinated voting']],
        default => ['default', 'shield-check', 'Mark '.$name.' as trusted?', 'Trusted people publish without the moderation queue, once their email is confirmed.', 'Mark trusted', []],
    };

    $facts = match ($kind) {
        'trust' => [
            ['upload', 'New posts publish at once. Reports still reach the Reports queue.'],
            ['clock', number_format($dialog['posts']).' '.\Illuminate\Support\Str::plural('post', $dialog['posts']).' so far, '.number_format($dialog['reports']).' '.\Illuminate\Support\Str::plural('report', $dialog['reports']).' on their content.'],
        ],
        'shadowban' => [
            ['ban', 'No new posts, comments, votes, reports or follows.'],
            ['eye', 'Their existing content stays visible to everyone.'],
            ['rotate-ccw', 'Reversible with Restore access, and they can still be banned.'],
        ],
        default => [
            ['log-in', 'They can still sign in, browse and manage their account.'],
            ['ban', 'No new posts, comments, votes, reports or follows.'],
            ['files', 'Their posts and comments stay as they are. Moderate them separately.'],
            ['rotate-ccw', 'Reversible with Restore access.'],
        ],
    };
    $moderator = auth()->user()?->name;
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-users-confirm"
    :tone="$tone"
    :icon="$icon"
    :title="$title"
    wire:key="sanction-{{ $kind }}-{{ $name }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    <p>{{ $body }}</p>

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="user" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $name }}{{ str_contains($dialog['name'], ' ') ? ' · '.$dialog['name'] : '' }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $dialog['meta'] }}</span>
            </span>
        </div>

        <div>
            <div class="rg-admin-section-label">
                <h3 class="rg-admin-section-label__text">What happens</h3>
            </div>
            <ul class="rg-admin-dialog-facts">
                @foreach ($facts as [$factIcon, $fact])
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon :name="$factIcon" :size="16" />{{ $fact }}</li>
                @endforeach
            </ul>
        </div>

        @unless ($kind === 'trust')
            @include('filament.pages.moderation.reason', [
                'label' => 'Reason',
                'required' => false,
                'limit' => ListUsers::REASON_LIMIT,
                'presets' => $presets,
                'hint' => 'Stored in the moderation log.',
                'acknowledge' => null,
            ])
        @endunless
    </x-slot:details>

    <x-slot:footnote>{{ $moderator !== null ? 'Recorded in the moderation log as '.$moderator.'.' : 'Recorded in the moderation log.' }}</x-slot:footnote>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        <x-admin.ui.button :variant="in_array($kind, ['ban', 'shadowban'], true) ? 'danger' : 'primary'" wire:click="confirmSanction" wire:loading.attr="disabled">{{ $confirm }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
