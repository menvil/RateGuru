{{--
    Review and confirm, asked first (OVL-01): what approving the plan as it is
    saved writes and how it runs — the post's date, the comments and replies,
    the votes and answers, what is overdue and what waits for its moment —
    and whether the executor runs. Confirm approves it (EditStudioPlan::approve());
    a second press finds the plan approved and writes nothing more.
--}}
@php
    $when = $publicationMode === 'at_date' ? ($summary['past'] ? 'Dated '.$summary['editorial'].', in the past' : 'Scheduled for '.$summary['editorial']) : 'Published on approval';
    $votes = $summary['postUp'] + $summary['postDown'] + $summary['commentVotes'];
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-studio-plan-approve"
    icon="clipboard-check"
    title="Confirm this publication?"
    wire:key="confirm-approve-studio-plan-{{ $plan['id'] }}"
    x-on:dismiss="$wire.closeApproval()"
>
    <p>Approving writes every action of the plan as it is saved and queues them: the post is published as its author, then its comments, replies and votes, each at its own time. The plan can’t be edited afterwards.</p>

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="newspaper" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $plan['title'] }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $card['author'] !== null ? '@'.$card['author']['username'].' · ' : '' }}{{ $when }}</span>
            </span>
        </div>
        <ul class="rg-admin-dialog-facts" aria-label="Summary">
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="calendar" :size="16" />Post date: {{ $publicationMode === 'at_date' ? $summary['editorial'] : 'the moment it is approved' }}</li>
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="message-square" :size="16" />{{ number_format($summary['comments']) }} {{ \Illuminate\Support\Str::plural('comment', $summary['comments']) }} and {{ number_format($summary['replies']) }} {{ \Illuminate\Support\Str::plural('reply', $summary['replies']) }}</li>
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="thumbs-up" :size="16" />{{ number_format($votes) }} {{ \Illuminate\Support\Str::plural('vote', $votes) }} (+{{ $summary['postUp'] }} / −{{ $summary['postDown'] }} on the post, {{ number_format($summary['commentVotes']) }} on comments) and {{ number_format($summary['answers']) }} rating {{ \Illuminate\Support\Str::plural('answer', $summary['answers']) }}</li>
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="list-checks" :size="16" />{{ number_format($summary['total']) }} {{ \Illuminate\Support\Str::plural('action', $summary['total']) }} in the plan</li>
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon :name="$summary['overdue'] > 0 ? 'clock-alert' : 'clock'" :size="16" />{{ $summary['overdue'] > 0 ? number_format($summary['overdue']).' overdue: they run right after approval, keeping their planned dates' : 'No overdue actions' }}</li>
            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="clock" :size="16" />{{ $summary['future'] > 0 ? number_format($summary['future']).' in the future: each waits for its own time' : 'Nothing waits for a later time' }}</li>
        </ul>
        @unless ($executorOn)
            <x-admin.ui.inline-notice tone="warning">The executor is switched off: nothing runs until it is switched on in the Action queue.</x-admin.ui.inline-notice>
        @endunless
    </x-slot:details>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" icon="clipboard-check" wire:click="approve" wire:loading.attr="disabled">Approve and publish</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
