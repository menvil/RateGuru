@php
    /*
     * Analytics → Journeys (JourneysTab), as the reference draws it: the
     * search for a visitor_… or user_… ID, the examples to pick — the one
     * shown pressed — and the word that only administrators look and every
     * lookup is written down; then the journey: its name and what became of
     * it over the steps in order, each with its moment, what it was and its
     * session, page, device and place in small mono type, beside the touches
     * of Attribution and the rows of Identity, which go under it where the
     * two don't fit side by side. An ID several browsers share lists them,
     * each by more of its digits, to pick one; an ID nobody has, or anything
     * that is no ID, is said in one card; with no journey recorded at all,
     * the tab says so.
     */
    $journey = $data['journey'] ?? null;
@endphp

<div class="rg-admin-analytics__journey-bar" data-testid="analytics-journey-bar">
    <x-admin.ui.search-field
        placeholder="Look up a visitor_… or user_… ID"
        id="rg-admin-analytics-journey-search"
        class="rg-admin-analytics__journey-search"
        :value="$data['who']"
        clearable
        wire:model.live.debounce.500ms="who"
    />
    @if ($data['examples'] !== [])
        <div class="rg-admin-analytics__journey-examples" role="group" aria-label="Examples">
            <span class="rg-admin-analytics__journey-examples-label" aria-hidden="true">Examples</span>
            @foreach ($data['examples'] as $example)
                <button
                    type="button"
                    @class(['rg-admin-analytics__journey-example', 'rg-admin-analytics__journey-example--on' => $example['on']])
                    aria-pressed="{{ $example['on'] ? 'true' : 'false' }}"
                    wire:click="pickJourney(@js($example['id']))"
                    wire:key="analytics-journey-example-{{ $example['id'] }}"
                    data-testid="analytics-journey-example"
                >{{ $example['id'] }}</button>
            @endforeach
        </div>
    @endif
    <span class="rg-admin-analytics__journey-logged"><x-admin.ui.icon name="lock" :size="13" />Admins only · lookups are logged</span>
</div>

@if ($data['state'] === 'none')
    <x-admin.ui.card>
        <x-admin.ui.empty-state icon="route" title="No journeys yet" data-testid="analytics-journey-none">
            A journey appears once someone plays a game or a visit is recorded.
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@elseif ($data['state'] === 'missing')
    <x-admin.ui.card>
        <p class="rg-admin-analytics__journey-missing" data-testid="analytics-journey-missing">{{ $data['missing'] }}</p>
    </x-admin.ui.card>
@elseif ($data['state'] === 'matches')
    <x-admin.ui.card :title="$data['matches']['title']" :description="$data['matches']['note']" data-testid="analytics-journey-matches">
        <div class="rg-admin-analytics__journey-matches">
            @foreach ($data['matches']['rows'] as $row)
                <button type="button" class="rg-admin-analytics__journey-example" wire:click="pickJourney(@js($row))" wire:key="analytics-journey-match-{{ $row }}" data-testid="analytics-journey-match">{{ $row }}</button>
            @endforeach
        </div>
    </x-admin.ui.card>
@else
    <div class="rg-admin-analytics__journey" data-testid="analytics-journey">
        <x-admin.ui.card flush class="rg-admin-analytics__journey-path" data-testid="analytics-journey-path">
            <div class="rg-admin-analytics__journey-head">
                <h2 class="rg-admin-analytics__journey-label" data-testid="analytics-journey-label">{{ $journey['label'] }}</h2>
                <p class="rg-admin-analytics__journey-status">{{ $journey['status'] }}</p>
            </div>
            <div class="rg-admin-analytics__journey-steps">
                @if ($journey['cut'])
                    <p class="rg-admin-analytics__journey-cut" data-testid="analytics-journey-cut">Earlier steps aren’t shown: a journey shows its latest {{ \App\Support\Analytics\JourneyTimeline::MOST_STEPS }} steps, and the posts its latest sessions opened.</p>
                @endif
                @forelse ($journey['entries'] as $entry)
                    <x-admin.ui.timeline-item :icon="$entry['icon']" :title="$entry['title']" :time="$entry['time']" :last="$loop->last" data-testid="analytics-journey-step">
                        <span class="rg-admin-analytics__journey-body">{{ $entry['body'] }}</span>
                        @if ($entry['meta'] !== null && $entry['meta'] !== '')
                            <span class="rg-admin-analytics__journey-meta">{{ $entry['meta'] }}</span>
                        @endif
                    </x-admin.ui.timeline-item>
                @empty
                    <p class="rg-admin-analytics__journey-missing">Nothing was recorded of this account yet.</p>
                @endforelse
            </div>
        </x-admin.ui.card>

        <div class="rg-admin-analytics__journey-side">
            <x-admin.ui.card title="Attribution" flush data-testid="analytics-journey-attribution">
                <dl class="rg-admin-analytics__journey-touches">
                    @foreach ($journey['touches'] as $touch)
                        <div class="rg-admin-analytics__journey-touch" data-testid="analytics-journey-touch">
                            <dt class="rg-admin-analytics__journey-touch-label">{{ $touch['label'] }}</dt>
                            <dd class="rg-admin-analytics__journey-touch-value">{{ $touch['value'] }}</dd>
                            <dd class="rg-admin-analytics__journey-touch-note">{{ $touch['note'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-admin.ui.card>

            <x-admin.ui.card title="Identity" flush data-testid="analytics-journey-identity">
                <dl class="rg-admin-detail-list rg-admin-analytics__journey-identity">
                    @foreach ($journey['identity'] as $row)
                        <div class="rg-admin-detail-row">
                            <x-admin.ui.icon :name="$row['icon']" class="rg-admin-detail-row__icon" />
                            <dt class="rg-admin-detail-row__label">{{ $row['label'] }}</dt>
                            <dd class="rg-admin-detail-row__value rg-admin-analytics__journey-identity-value" title="{{ $row['value'] }}">{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="rg-admin-analytics__journey-note">{{ $journey['note'] }}</p>
            </x-admin.ui.card>
        </div>
    </div>
@endif
