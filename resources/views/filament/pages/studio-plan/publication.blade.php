{{--
    Step 4 · Publication. When the post is to be published — once the plan
    is approved, or at a date it will carry as its own — what of the plan is
    already overdue, whether the plan is ready to approve, and the final
    publication card; beside them the Action plan (StudioPlanActionPlan): a
    preview of exactly what approval writes, or, once approved, the plan's
    own actions as they run. Saving the draft publishes and schedules
    nothing; Review and confirm, in the footer, approves it.

    Drawn outside the step's fieldset, so the Action plan's own controls work
    on a plan past its Draft; the parts of the draft hold their own.
--}}
@php
    $summary = $publication['summary'];
    $problems = $publication['problems'];
    $draftPlan = ! $creating && $planId !== 0 && ! $readOnly;
@endphp

<div class="rg-admin-studio__columns rg-admin-studio__columns--publication">
    <div class="rg-admin-studio__stack">
        <fieldset class="rg-admin-studio__fieldset" @disabled($readOnly)>
            <x-admin.ui.card title="Publication mode">
                <div class="rg-admin-studio__stack">
                    <x-admin.ui.radio-cards label="Publication mode" :options="$modes" :value="$publicationMode" wire:model.live="publicationMode" />
                    @error('publicationMode')
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                    @enderror

                    @if ($publicationMode === 'at_date')
                        <x-admin.ui.date-time-field
                            label="Publication date and time"
                            id="rg-admin-studio-publish-at"
                            :value="$publishAt"
                            :min="$limits['earliest']"
                            :max="$limits['latest']"
                            :timezone="$timezone"
                            :required="false"
                            :hint="'Any date from 1970 to 2038, past or future, in '.$timezone.'. Comments and votes are timed from it.'"
                            :error="$errors->first('publishAt') ?: null"
                            wire:model.live="publishAt"
                        />
                        @if (trim($publishAt) === '' && ! $errors->has('publishAt'))
                            <x-admin.ui.inline-notice tone="warning">Publication date not selected</x-admin.ui.inline-notice>
                        @endif
                    @endif
                </div>
            </x-admin.ui.card>
        </fieldset>

        @if ($summary !== null && ! $readOnly && $summary['overdue'] > 0)
            <x-admin.ui.card title="Overdue actions">
                <div class="rg-admin-studio__stack">
                    <x-admin.ui.inline-notice tone="warning" icon="clock-alert">
                        @if ($summary['past'])
                            <strong>The publication date is in the past.</strong>
                        @endif
                        {{ $summary['overdue'] === $summary['total'] ? 'All '.number_format($summary['total']) : number_format($summary['overdue']).' of '.number_format($summary['total']) }} planned {{ \Illuminate\Support\Str::plural('action', $summary['total']) }} {{ $summary['overdue'] === 1 ? 'is' : 'are' }} already overdue.
                    </x-admin.ui.inline-notice>
                    <p class="rg-admin-studio__muted">
                        They run as soon as the plan is approved — in plan order, each once what it depends on is done, within each persona’s posting, commenting and voting limits — and keep their planned dates: the post{{ $summary['editorial'] !== null ? ' is dated '.$summary['editorial'].', and' : ',' }} its comments and votes are dated as planned. When each really ran is kept in the Action queue.
                    </p>
                </div>
            </x-admin.ui.card>
        @endif

        @if ($draftPlan && $summary !== null)
            <x-admin.ui.card title="Ready to approve?" description="What the approval checks, on the plan as it is saved.">
                <div class="rg-admin-studio__stack">
                    <div x-show="$store.adminEditor?.changes > 0 || $store.rgPostVotes?.unsaved || $store.rgCommentVotes?.unsaved" x-cloak>
                        <x-admin.ui.inline-notice tone="warning">You have unsaved changes. The Action plan and the approval use the plan as it is saved: save them first.</x-admin.ui.inline-notice>
                    </div>
                    @if ($problems === [])
                        <x-admin.ui.inline-notice tone="success">Everything the approval checks is in order. Review and confirm to publish.</x-admin.ui.inline-notice>
                    @else
                        <x-admin.ui.inline-notice tone="warning">{{ count($problems) === 1 ? 'One thing stops' : count($problems).' things stop' }} this plan from being approved.</x-admin.ui.inline-notice>
                        <ul class="rg-admin-studio__problems">
                            @foreach ($problems as $problem)
                                <li class="rg-admin-studio__problem" wire:key="problem-{{ $problem['code'] }}">
                                    <span class="rg-admin-studio__problem-text">{{ $problem['message'] }}</span>
                                    <x-admin.ui.button size="sm" wire:click="editPart('{{ $problem['part'] }}')">{{ $problem['where'] }}</x-admin.ui.button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @unless ($publication['executorOn'])
                        <p class="rg-admin-studio__muted">The executor is switched off: an approved plan waits until it is switched on in the Action queue.</p>
                    @endunless
                </div>
            </x-admin.ui.card>
        @endif

        <x-admin.ui.card title="Final publication card" :description="$readOnly ? 'The post as the plan was approved.' : 'The post as this draft would make it, saved or not.'">
            <div class="rg-admin-studio__stack">
                <div class="rg-admin-studio__card">
                    <span class="rg-admin-studio__card-image" aria-hidden="true">
                        @if ($card['image'] !== null)
                            <img src="{{ $card['image'] }}" alt="" class="rg-admin-studio__preview-image" />
                        @else
                            <x-admin.ui.icon name="image" :size="24" />
                        @endif
                    </span>
                    <div class="rg-admin-studio__card-text">
                        <p @class(['rg-admin-studio__card-title', 'rg-admin-studio__empty' => $card['title'] === ''])>{{ $card['title'] !== '' ? $card['title'] : 'No title yet' }}</p>
                        <p @class(['rg-admin-studio__card-description', 'rg-admin-studio__empty' => $card['description'] === ''])>{{ $card['description'] !== '' ? $card['description'] : 'No description yet' }}</p>
                    </div>
                </div>
                <dl class="rg-admin-detail-list">
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon name="image" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">Photo</dt>
                        <dd class="rg-admin-detail-row__value">{{ $card['image'] !== null ? 'Chosen' : 'No photo yet' }}</dd>
                    </div>
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon name="user" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">Author</dt>
                        <dd class="rg-admin-detail-row__value">{{ $card['author'] !== null ? $card['author']['name'].($card['author']['username'] !== null ? ' · @'.$card['author']['username'] : '') : 'No author selected' }}</dd>
                    </div>
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon name="folder" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">Category</dt>
                        <dd class="rg-admin-detail-row__value">{{ $card['category'] ?? 'No category' }}</dd>
                    </div>
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon name="calendar" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">Publication date</dt>
                        <dd class="rg-admin-detail-row__value">{{ $card['when'] }}</dd>
                    </div>
                    @if ($summary !== null)
                        <div class="rg-admin-detail-row">
                            <x-admin.ui.icon name="list-checks" class="rg-admin-detail-row__icon" />
                            <dt class="rg-admin-detail-row__label">Actions in plan</dt>
                            <dd class="rg-admin-detail-row__value">{{ number_format($summary['total']) }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($summary !== null)
                    <div class="rg-admin-studio__final-groups">
                        <div class="rg-admin-studio__final-group">
                            <div class="rg-admin-studio__final-group-head">
                                <span class="rg-admin-studio__plan-swatch rg-admin-studio__plan-swatch--part-post" aria-hidden="true"></span>
                                <span class="rg-admin-studio__final-group-title">Post</span>
                                @unless ($readOnly)
                                    <x-admin.ui.button size="sm" icon="pencil" wire:click="editPart('votes')">Edit<span class="rg-admin-sr-only"> the post’s votes</span></x-admin.ui.button>
                                @endunless
                            </div>
                            <dl class="rg-admin-detail-list">
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="thumbs-up" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Up · down votes</dt>
                                    <dd class="rg-admin-detail-row__value">{{ $summary['postUp'] + $summary['postDown'] > 0 ? '+'.$summary['postUp'].' / −'.$summary['postDown'] : 'None' }}</dd>
                                </div>
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="star" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Answers to questions</dt>
                                    <dd class="rg-admin-detail-row__value">{{ number_format($summary['answers']) }}</dd>
                                </div>
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="users" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Post voters</dt>
                                    <dd class="rg-admin-detail-row__value">{{ number_format($summary['postVoters']) }} {{ \Illuminate\Support\Str::plural('persona', $summary['postVoters']) }}</dd>
                                </div>
                            </dl>
                        </div>
                        <div class="rg-admin-studio__final-group">
                            <div class="rg-admin-studio__final-group-head">
                                <span class="rg-admin-studio__plan-swatch rg-admin-studio__plan-swatch--part-discussion" aria-hidden="true"></span>
                                <span class="rg-admin-studio__final-group-title">Discussion</span>
                                @unless ($readOnly)
                                    <x-admin.ui.button size="sm" icon="pencil" wire:click="editPart('discussion')">Edit<span class="rg-admin-sr-only"> the discussion</span></x-admin.ui.button>
                                @endunless
                            </div>
                            <dl class="rg-admin-detail-list">
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="message-square" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Comments</dt>
                                    <dd class="rg-admin-detail-row__value">{{ number_format($summary['comments']) }} · {{ number_format($summary['replies']) }} {{ \Illuminate\Support\Str::plural('reply', $summary['replies']) }}</dd>
                                </div>
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="thumbs-up" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Comment votes</dt>
                                    <dd class="rg-admin-detail-row__value">{{ number_format($summary['commentVotes']) }}</dd>
                                </div>
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="users" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Comment voters</dt>
                                    <dd class="rg-admin-detail-row__value">{{ number_format($summary['commentVoters']) }} {{ \Illuminate\Support\Str::plural('persona', $summary['commentVoters']) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif

                @unless ($readOnly)
                    <x-admin.ui.inline-notice tone="info">Saving the draft publishes and schedules nothing. Review and confirm approves it.</x-admin.ui.inline-notice>
                @endunless
            </div>
        </x-admin.ui.card>
    </div>

    <div class="rg-admin-studio__stack">
        @if ($planId !== 0)
            <livewire:admin.studio-plan-action-plan :plan-id="$planId" :wire:key="'action-plan-'.$planId" />
        @else
            <x-admin.ui.card title="Action plan">
                <x-admin.ui.empty-state icon="list-checks" title="No plan to show yet">
                    Save the draft to see every action its approval would write, and when each would run.
                </x-admin.ui.empty-state>
            </x-admin.ui.card>
        @endif
    </div>
</div>
