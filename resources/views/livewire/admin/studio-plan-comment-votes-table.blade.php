{{--
    Prepared votes on comments, one a row, in the order they come: when —
    after the post, and after the comment — who, on which comment, which way,
    and, for one saved, what keeps it from being cast now. $rows are
    StudioPlanDiscussionVotesEditor's, $empty what is said when there are none.
--}}
<div class="rg-admin-studio__timing-scroll rg-admin-studio__votes-list">
    <table class="rg-admin-studio__timing rg-admin-studio__votes-table">
        <thead>
            <tr><th scope="col">When</th><th scope="col">Persona</th><th scope="col">Comment</th><th scope="col">Vote</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr wire:key="comment-votes-list-{{ md5(json_encode($row)) }}-{{ $loop->index }}">
                    <td class="rg-admin-studio__votes-when">
                        {{ $row['when'] }}
                        <span class="rg-admin-studio__persona-short">{{ $row['after'] }}</span>
                    </td>
                    <td>
                        <span class="rg-admin-studio__votes-who">
                            @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $row['persona']])
                            <span class="rg-admin-studio__persona-name">{{ $row['persona']['name'] ?? 'Deleted user' }}</span>
                            @if (($row['persona']['problem'] ?? null) === 'deleted')
                                <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
                            @elseif (($row['persona']['problem'] ?? null) === 'not_persona')
                                <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
                            @elseif (($row['persona']['problem'] ?? null) === 'restricted' && ($row['persona']['status'] ?? null) !== null)
                                <x-admin.ui.badge :tone="$row['persona']['status']['tone']">{{ $row['persona']['status']['label'] }}</x-admin.ui.badge>
                            @endif
                        </span>
                    </td>
                    <td>
                        <span class="rg-admin-studio__mono">{{ $row['label'] }}</span>
                        <span class="rg-admin-studio__persona-short">{{ $row['body'] }}</span>
                    </td>
                    <td>
                        @include('livewire.admin.studio-plan-post-vote-cell', ['vote' => $row['vote']])
                        @if ($row['problem'] !== null)
                            <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $row['problem'] }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="rg-admin-studio__muted">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
