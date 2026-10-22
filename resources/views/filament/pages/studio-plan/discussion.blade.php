{{--
    Step 3 · Discussion (StudioPlanDiscussionEditor): the comments and replies
    the post is to get, written by hand or by AI, each change written at once —
    and the votes on them (StudioPlanDiscussionVotesEditor): its Comment voters
    and Comment votes beside the Discussion settings, each comment's breakdown
    beside the tree with Save votes, its voters on the tree's Voters tab and
    its votes in time on the Timing tab. Drawn once for the plan and kept for as long as its editor is
    open — hidden on the other steps, never drawn again — so its settings, and
    a generation it waits for, survive moving between steps. A new publication
    has no plan to keep a discussion on until its Draft is made; the step says
    so, and the editor appears by itself once there is one.

    The `rgCommentVotes` store carries the comment votes as set on the step to
    the parts of the page drawn by others: each comment's counts on the tree,
    the comment whose breakdown is open, all of them in a few words for the
    Generate strip, and whether anything is unsaved, which the step's Back,
    Continue and steps ask about before leaving — and, from the discussion
    editor, whether a generation is on its way, while which the Timing tab
    moves no comment. Registered
    once, before Alpine starts; a re-render keeps the registration.
--}}
<script>
    (() => {
        const register = () => {
            if (! window.Alpine.store('rgCommentVotes')) {
                window.Alpine.store('rgCommentVotes', { nodes: {}, selected: null, unsaved: false, status: null, generating: false })
            }
        }

        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>
@if ($planId === 0)
    <x-admin.ui.card title="Discussion">
        <x-admin.ui.empty-state icon="message-square" title="No draft yet" :heading-level="3">
            Save the draft first to prepare its discussion.
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <livewire:admin.studio-plan-discussion-editor :plan-id="$planId" :wire:key="'studio-plan-discussion-'.$planId" />
    {{-- The votes on the comments above (StudioPlanDiscussionVotesEditor), kept with them for as long as the page is open; it draws its cards and the breakdown in the places the discussion keeps for them. --}}
    <livewire:admin.studio-plan-discussion-votes-editor :plan-id="$planId" :wire:key="'studio-plan-discussion-votes-'.$planId" />
@endif
