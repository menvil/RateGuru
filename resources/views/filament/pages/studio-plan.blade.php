@php
    /*
     * One publication's editor (CreateStudioPlan, EditStudioPlan). The header
     * band with what the plan is, and its four steps — Image, Post,
     * Discussion, Publication — which only change what is on screen. Under
     * it the step on screen, then Back, where the editor is, and Continue.
     *
     * The browser counts what is unsaved from the draft against `saved`
     * (filament.pages.editor.script) and asks before a page with unsaved
     * changes is left; moving between steps is never a change, while a
     * chosen photo, a link to import one from and taking the saved one off
     * each count as one. An editor drawn before somebody else's save, of a
     * plan deleted meanwhile or of one past its Draft holds Save back and
     * says so.
     */
    $draft = '{
        title: $wire.postTitle,
        description: $wire.postDescription,
        sourceUrl: $wire.sourceUrl,
        authorUserId: $wire.authorUserId,
        categoryId: $wire.categoryId,
        tagIds: Object.values($wire.tagIds ?? {}).map(Number).sort((a, b) => a - b).join(\',\'),
        authorAnswers: Object.values($wire.authorAnswers ?? {}).filter((option) => option !== \'\' && option !== null).map(Number).sort((a, b) => a - b).join(\',\'),
        publicationMode: $wire.publicationMode,
        publishAt: $wire.publicationMode === \'at_date\' ? $wire.publishAt : \'\',
        photo: $wire.photo ? \'new\' : \'\',
        imageUrl: ($wire.imageUrl ?? \'\').trim(),
        removeImage: $wire.removeImage ? \'yes\' : \'\',
    }';
    // One line: Alpine reads a handler that starts with `if` as a statement
    // only when its condition closes on the first line.
    $draft = (string) preg_replace('/\s+/', ' ', $draft);
    $blocked = $creating ? 'false' : '$wire.stale || $wire.gone || $wire.closed';
    $title = $creating ? 'New publication' : ($plan['title'] ?? 'Publication');
    $stepKeys = array_column($steps, 'key');
    $first = $step === $stepKeys[0];
    $last = $step === end($stepKeys);
@endphp

<div
    class="rg-admin rg-admin-screen"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorChanges({{ $draft }}, $wire.saved); $store.adminEditor.blocked = {{ $blocked }}"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')

    <section class="rg-admin-page-header rg-admin-studio__header" aria-labelledby="rg-admin-studio-plan-title">
        <div class="rg-admin-page-header__text">
            <div class="rg-admin-studio__title-row">
                <h1 id="rg-admin-studio-plan-title" class="rg-admin-page-header__title">{{ $title }}</h1>
                @if (! $creating && $plan !== null)
                    <x-admin.ui.badge :tone="$plan['state']['tone']" :dot="$plan['state']['dot']">{{ $plan['state']['label'] }}</x-admin.ui.badge>
                @endif
            </div>
            <p class="rg-admin-page-header__description rg-admin-studio__meta">
                @if ($creating || $plan === null)
                    <span>Choose a photo, write the post and choose when it is to be published. Saving the draft publishes nothing.</span>
                @else
                    <span class="rg-admin-mono">#{{ $plan['id'] }}</span>
                    @if ($plan['created'] !== null)
                        <span>· Created {{ $plan['created'] }}{{ $plan['createdBy'] !== null ? ' by '.$plan['createdBy'] : '' }}</span>
                    @endif
                    @if ($plan['updated'] !== null)
                        <span>· Saved {{ $plan['updated'] }}</span>
                    @endif
                @endif
            </p>
        </div>
        @if (! $creating && $plan !== null && ! $readOnly)
            <x-admin.ui.button icon="trash-2" wire:click="askToDelete">Delete draft</x-admin.ui.button>
        @endif

        <nav class="rg-admin-studio__steps" aria-label="Editor steps">
            @foreach ($steps as $index => $item)
                <button
                    type="button"
                    @class(['rg-admin-studio__step', 'rg-admin-studio__step--current' => $item['key'] === $step])
                    @if ($item['key'] === $step) aria-current="step" @endif
                    wire:click="goToStep('{{ $item['key'] }}')"
                >
                    <span class="rg-admin-studio__step-number" aria-hidden="true">{{ $index + 1 }}</span>
                    <span class="rg-admin-studio__step-text">
                        <span class="rg-admin-studio__step-label">{{ $item['label'] }}</span>
                        <span class="rg-admin-studio__step-caption">{{ $item['caption'] }}</span>
                    </span>
                </button>
            @endforeach
        </nav>
    </section>

    <div class="rg-admin-screen__body rg-admin-studio__body">
        @if (! $creating && $gone)
            <x-admin.ui.inline-notice tone="warning">
                This draft was deleted, so nothing here can be saved any more.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="arrow-left" :href="\App\Filament\Resources\StudioPlans\Pages\ListStudioPlans::getUrl()">Back to publications</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @elseif (! $creating && $closed)
            <x-admin.ui.inline-notice tone="info">
                This plan is no longer a draft, so it can’t be edited here.
            </x-admin.ui.inline-notice>
        @elseif (! $creating && $stale)
            <x-admin.ui.inline-notice tone="warning">
                Someone saved this draft after you opened it. Reload to see their changes; what you typed here is replaced.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        <fieldset class="rg-admin-studio__step-body" @disabled($readOnly) aria-labelledby="rg-admin-studio-step-title">
            <h2 id="rg-admin-studio-step-title" class="rg-admin-sr-only">Step {{ $stepNumber }} of 4: {{ $stepLabel }}</h2>
            @include('filament.pages.studio-plan.'.$step)
        </fieldset>

        <div class="rg-admin-studio__footer">
            @if ($first)
                <x-admin.ui.button icon="arrow-left" :href="\App\Filament\Resources\StudioPlans\Pages\ListStudioPlans::getUrl()">Back</x-admin.ui.button>
            @else
                <x-admin.ui.button icon="arrow-left" wire:click="previousStep">Back</x-admin.ui.button>
            @endif
            <span class="rg-admin-studio__position">Step {{ $stepNumber }} of 4 · {{ $stepLabel }}</span>
            @unless ($last)
                <x-admin.ui.button variant="primary" trailing-icon="arrow-right" wire:click="nextStep">Continue</x-admin.ui.button>
            @endunless
        </div>
    </div>

    @if (! $creating && $confirmingDelete && $plan !== null)
        @include('filament.pages.studio-plans.confirmation', ['plan' => ['id' => $plan['id'], 'title' => $plan['title']]])
    @endif
</div>
