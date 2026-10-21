{{--
    Step 3 · Discussion (StudioPlanDiscussionEditor): the comments and replies
    the post is to get, written by hand or by AI, each change written at once.
    Drawn once for the plan and kept for as long as its editor is open —
    hidden on the other steps, never drawn again — so its settings, and a
    generation it waits for, survive moving between steps. A new publication has no plan to keep a
    discussion on until its Draft is made; the step says so, and the editor
    appears by itself once there is one.
--}}
@if ($planId === 0)
    <x-admin.ui.card title="Discussion">
        <x-admin.ui.empty-state icon="message-square" title="No draft yet" :heading-level="3">
            Save the draft first to prepare its discussion.
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <livewire:admin.studio-plan-discussion-editor :plan-id="$planId" :wire:key="'studio-plan-discussion-'.$planId" />
@endif
