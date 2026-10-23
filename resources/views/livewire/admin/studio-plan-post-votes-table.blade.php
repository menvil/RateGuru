{{--
    The votes on a post and its rating questions as saved, one a row, in the
    order they come: who, when — a moment changed here is saved at once — on
    what, which way or which answer, and what keeps one from being cast now.
    $rows are StudioPlanPostVotesEditor's, $editable whether a moment can be
    changed.
--}}
<div class="rg-admin-studio__timing-scroll rg-admin-studio__votes-list">
    <table class="rg-admin-studio__timing rg-admin-studio__votes-table">
        <thead>
            <tr><th scope="col">Persona</th><th scope="col" class="rg-admin-studio__votes-at">When</th><th scope="col">On</th><th scope="col">Vote</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr wire:key="votes-row-{{ $row['block'] }}-{{ $row['personaId'] }}-{{ $row['offset'] }}" data-vote="{{ $row['block'] }}:{{ $row['personaId'] }}">
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
                    <td class="rg-admin-studio__votes-at">
                        @include('livewire.admin.studio-plan-offset-field', [
                            'seconds' => $row['offset'],
                            'label' => 'When '.($row['persona']['name'] ?? 'a deleted user').' votes on '.$row['target'],
                            'call' => '$wire.setMoment('.\Illuminate\Support\Js::from($row['block']).', '.$row['personaId'].', seconds)',
                            'disabled' => ! $editable,
                        ])
                    </td>
                    <td>{{ $row['target'] }}</td>
                    <td>
                        @if ($row['answer'] !== null)
                            {{ $row['answer'] }}
                        @else
                            @include('livewire.admin.studio-plan-post-vote-cell', ['vote' => $row['vote']])
                        @endif
                        @if ($row['problem'] !== null)
                            <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $row['problem'] }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="rg-admin-studio__muted">No votes on the post or its questions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
