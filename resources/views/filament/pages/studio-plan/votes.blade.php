{{--
    Step 2 · Post, its Votes tab (StudioPlanPostVotesEditor): the votes on the
    post and the answers to its rating questions, the Personas who cast them,
    and when. Drawn once for the plan and kept for as long as its editor is
    open — hidden on the other steps and tab, never drawn again — so its
    settings and its preview survive moving around the editor. A new
    publication has no plan to keep votes on until its Draft is made; the tab
    says so, and the editor appears by itself once there is one.
--}}
@if ($planId === 0)
    <x-admin.ui.card title="Post votes">
        <x-admin.ui.empty-state icon="message-square" title="No draft yet" :heading-level="3">
            Save the draft first to prepare its votes.
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <livewire:admin.studio-plan-post-votes-editor :plan-id="$planId" :wire:key="'studio-plan-post-votes-'.$planId" />
@endif
