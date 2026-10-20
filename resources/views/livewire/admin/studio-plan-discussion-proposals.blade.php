{{--
    A plan's discussion generations (StudioPlanDiscussionProposals): the
    newest one's state, the earlier ones ten at a time, and a proposal
    previewed apart from the discussion it would change — a whole discussion
    as the tree it would become, with the warning that it replaces the whole
    prepared discussion; a part as each text before and after. Apply proposal
    and Retry go to the discussion's editor; Ignore proposal closes the
    preview and stores nothing. Read again every few seconds only while a
    generation is on its way. Every text is drawn escaped.
--}}
@php($poll = \App\Livewire\Admin\StudioPlanDiscussionProposals::POLL_SECONDS)

<div class="rg-admin-studio__proposals" data-generation="{{ $latest['phase'] ?? 'none' }}" @if ($polling) wire:poll.{{ $poll }}s.visible @endif>
    <x-admin.ui.card title="Generations" description="Each request is kept here. A proposal changes nothing until you apply it.">
        @if ($latest === null)
            <p class="rg-admin-studio__suggestion-empty">No discussion has been generated for this draft yet.</p>
        @else
            @foreach ([$latest, ...$history] as $index => $item)
                <div @class(['rg-admin-studio__generation', 'rg-admin-studio__generation--latest' => $index === 0]) wire:key="discussion-generation-{{ $item['id'] }}">
                    <div class="rg-admin-studio__generation-head">
                        <x-admin.ui.badge :tone="$item['state']['tone']" :dot="in_array($item['phase'], ['queued', 'running'], true)">{{ $item['state']['label'] }}</x-admin.ui.badge>
                        <span class="rg-admin-table__name">{{ $item['what'] }}</span>
                        <span class="rg-admin-table__meta rg-admin-table__meta--small">
                            {{ $item['when'] }}{{ $item['by'] !== null ? ' · '.$item['by'] : '' }}{{ $item['comments'] !== null ? ' · '.$item['comments'].' '.($item['comments'] === 1 ? 'comment' : 'comments') : '' }}{{ $item['tone'] !== null ? ' · '.$item['tone'] : '' }}
                        </span>
                    </div>
                    @if ($item['error'] !== null)
                        <p class="rg-admin-hint">{{ $item['error'] }}</p>
                    @endif
                    <div class="rg-admin-studio__comment-actions">
                        @if ($item['previewable'])
                            <x-admin.ui.button size="sm" icon="eye" wire:click="preview({{ $item['id'] }})">{{ $item['applied'] ? 'View' : 'Preview proposal' }}</x-admin.ui.button>
                        @endif
                        @if ($index === 0 && in_array($item['phase'], ['failed', 'stale'], true) && $draft)
                            <x-admin.ui.button size="sm" icon="refresh-cw" x-on:click="$dispatch('retry-studio-discussion-generation', { generationId: {{ $item['id'] }}, postChanges: $store.adminEditor?.changes ?? 0 })">Retry</x-admin.ui.button>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($more)
                <x-admin.ui.button size="sm" variant="ghost" wire:click="showMore">Show earlier generations</x-admin.ui.button>
            @endif
        @endif
    </x-admin.ui.card>

    @if ($preview !== null)
        <x-admin.ui.card :title="$preview['full'] ? 'Proposed discussion' : 'Proposed texts'" class="rg-admin-studio__proposal">
            @if ($preview['applied'])
                <x-admin.ui.inline-notice tone="success">This proposal was applied to the discussion.</x-admin.ui.inline-notice>
            @elseif (! $preview['fits'])
                <x-admin.ui.inline-notice tone="warning">The post, its photo, its discussion or a persona changed since this was written, so it can no longer be applied. Generate again.</x-admin.ui.inline-notice>
            @elseif ($preview['full'])
                <x-admin.ui.inline-notice tone="warning">Applying this proposal replaces the entire prepared discussion.</x-admin.ui.inline-notice>
            @else
                <x-admin.ui.inline-notice tone="info">Only these texts change. Every comment keeps its persona, place and time, and the rest of the discussion stays as it is.</x-admin.ui.inline-notice>
            @endif

            @if ($preview['full'])
                <ol class="rg-admin-studio__thread" aria-label="Proposed discussion">
                    @foreach ($preview['tree'] as $row)
                        <li @class(['rg-admin-studio__comment', 'rg-admin-studio__comment--reply' => $row['reply']]) wire:key="proposal-{{ $preview['id'] }}-{{ $row['label'] }}">
                            <div class="rg-admin-studio__comment-head">
                                <span class="rg-admin-table__name">{{ $row['author'] }}</span>
                                <span class="rg-admin-studio__comment-label rg-admin-mono">{{ $row['label'] }}</span>
                                <span class="rg-admin-studio__comment-time">{{ $row['relative'] }}</span>
                            </div>
                            <p class="rg-admin-studio__comment-body">{{ $row['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            @else
                <ul class="rg-admin-studio__changes" aria-label="Texts it changes">
                    @foreach ($preview['changes'] as $index => $change)
                        <li class="rg-admin-studio__change" wire:key="proposal-{{ $preview['id'] }}-change-{{ $index }}">
                            <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $change['reply'] ? 'Reply' : 'Comment' }}{{ $change['author'] !== null ? ' by '.$change['author'] : '' }}</span>
                            @if ($change['gone'])
                                <p class="rg-admin-hint">This comment is no longer in the discussion.</p>
                            @else
                                <p class="rg-admin-studio__change-before"><span class="rg-admin-sr-only">Now: </span>{{ $change['before'] }}</p>
                            @endif
                            <p class="rg-admin-studio__change-after"><span class="rg-admin-sr-only">Proposed: </span>{{ $change['after'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="rg-admin-studio__discussion-footer">
                <x-admin.ui.button wire:click="closePreview">{{ $preview['applied'] || ! $preview['fits'] ? 'Close preview' : 'Ignore proposal' }}</x-admin.ui.button>
                @if (! $preview['applied'] && $preview['fits'] && $draft)
                    <span class="rg-admin-studio__discussion-footer-end">
                        <x-admin.ui.button variant="primary" icon="check" wire:click="apply({{ $preview['id'] }})" wire:loading.attr="disabled" wire:target="apply">Apply proposal</x-admin.ui.button>
                    </span>
                @endif
            </div>
        </x-admin.ui.card>
    @endif

    @if ($confirming !== null && $preview !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-discussion-replace"
            tone="warning"
            icon="refresh-cw"
            title="Replace the prepared discussion?"
            wire:key="confirm-replace-discussion-{{ $preview['id'] }}"
            x-on:dismiss="$wire.closeConfirmation()"
        >
            <p>Applying this proposal replaces the entire prepared discussion: its {{ $confirming }} saved {{ $confirming === 1 ? 'comment is' : 'comments are' }} removed from the draft, edits by hand included.</p>

            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="confirmApply">Replace discussion</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
