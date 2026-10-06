<?php

namespace App\Filament\Pages;

use App\Http\Middleware\EnsureDevEnvironment;
use Filament\Pages\Page;

/**
 * The Admin v2 developer UI kit at /admin/dev/ui-kit.
 *
 * Every reusable admin primitive, rendered live from the x-admin.ui components
 * and the .rg-admin-* classes, under the stable reference IDs of the Dev UI kit
 * reference (docs/design/admin/reference/original/RateGuru-Dev-UI-Kit.html).
 * Developers build screens from what is shown here and quote the IDs in
 * tickets and reviews.
 *
 * A developer tool rather than part of the admin: it exists only in the local
 * and testing environments (404 anywhere else), is never offered in the
 * navigation, and stays behind the panel's own authentication, so it opens to
 * exactly the people who may open the panel. Its specimens make no queries,
 * no writes and no external calls; the overlays and toasts among them are
 * live in the browser, driven by Alpine alone.
 */
final class AdminUiKit extends Page
{
    protected static ?string $slug = 'dev/ui-kit';

    protected static ?string $title = 'Dev UI kit';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|array $routeMiddleware = EnsureDevEnvironment::class;

    // A standalone reference page: the kit has its own index, so it skips the
    // panel's sidebar and top bar.
    protected static string $layout = 'filament-panels::components.layout.base';

    protected string $view = 'filament.pages.admin-ui-kit';

    /**
     * Livewire round-trips do not pass through the route middleware, so the
     * environment is checked here too.
     */
    public static function canAccess(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $specs = [];

        foreach (self::specs() as $id => $spec) {
            $specs[$id] = [
                'id' => $id,
                'search' => "{$id} {$spec['name']} {$spec['purpose']}",
                'specs' => [],
                'rules' => [],
                ...$spec,
            ];
        }

        $groups = array_map(fn (array $group): array => [
            ...$group,
            'search' => array_map(fn (string $id): string => $specs[$id]['search'], $group['ids']),
        ], self::groups());

        return [
            'groups' => $groups,
            'specs' => $specs,
            'total' => count($specs),
        ];
    }

    /**
     * The kit's sections, in the order of the reference.
     *
     * @return list<array{key: string, label: string, description: string, ids: list<string>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'foundations', 'label' => 'Foundations', 'description' => 'Tokens everything else is built from.', 'ids' => ['FND-01', 'FND-02', 'FND-03', 'FND-04']],
            ['key' => 'actions', 'label' => 'Actions', 'description' => 'Buttons, icon buttons and text actions.', 'ids' => ['ACT-01', 'ACT-02', 'ACT-03']],
            ['key' => 'status', 'label' => 'Status', 'description' => 'Badges, counters and indicators that report state.', 'ids' => ['STS-01', 'STS-02', 'STS-03', 'STS-05']],
            ['key' => 'forms', 'label' => 'Forms', 'description' => 'The fields every editor is built from.', 'ids' => ['FRM-01', 'FRM-02', 'FRM-03', 'FRM-08', 'FRM-10', 'FRM-11']],
            ['key' => 'navigation', 'label' => 'Navigation', 'description' => 'Where the admin is and how to move through lists.', 'ids' => ['NAV-01', 'NAV-02', 'NAV-03', 'NAV-04']],
            ['key' => 'layout', 'label' => 'Layout', 'description' => 'Page structure, containers and read-only details.', 'ids' => ['LAY-01', 'LAY-02', 'LAY-03']],
            ['key' => 'tables', 'label' => 'Tables', 'description' => 'The moderation workhorse: rows, toolbar, actions and states.', 'ids' => ['TBL-01', 'TBL-02', 'TBL-04', 'TBL-05', 'TBL-06']],
            ['key' => 'feedback', 'label' => 'Overlays and feedback', 'description' => 'Confirmations, drawers, toasts and notices.', 'ids' => ['OVL-01', 'OVL-02', 'FBK-01', 'FBK-02']],
            ['key' => 'localization', 'label' => 'Localization domain', 'description' => 'Patterns that exist only in RateGuru Admin: the translation states Translation Center is built on.', 'ids' => ['DOM-01']],
        ];
    }

    /**
     * What each element is, its measurements, how to use it and its rules.
     * A rule starting with "!" is a prohibition.
     *
     * @return array<string, array{name: string, source: string, kind: string, purpose: string, specs?: list<array{0: string, 1: string}>, code: string, rules?: list<string>}>
     */
    public static function specs(): array
    {
        return [
            'FND-01' => [
                'name' => 'Colour tokens',
                'source' => 'Tokens · tokens.css',
                'kind' => 'tokens',
                'purpose' => 'Every colour in the admin. Ink and greys carry the interface; colour appears only where it means a status.',
                'specs' => [
                    ['Ink', '--rg-admin-gray-950 · #0E121B'],
                    ['App ground', '--rg-admin-surface-app · #F6F7FB'],
                    ['Card', '--rg-admin-surface-card · #FFFFFF'],
                    ['Hairline', '--rg-admin-border-default · #E1E4EB'],
                    ['Strong border', '--rg-admin-border-strong · #CACFD8'],
                    ['Text', 'strong #0E121B · secondary #525866 · tertiary #68707D'],
                    ['Tertiary text', '#68707D, 5.0:1 on white · reference gray-400 #99A0AE is 2.63:1, below AA'],
                    ['Success', '--rg-admin-status-success-bg / -fg · dot green-500'],
                    ['Warning', '--rg-admin-status-warning-bg / -fg · dot orange-500'],
                    ['Danger', '--rg-admin-status-danger-bg / -fg · dot red-500'],
                    ['Info · admin', '--rg-admin-status-info-bg / -fg · border #C9D5EE'],
                    ['Due time', '--rg-admin-status-due-fg · #7A4520'],
                ],
                'code' => <<<'CSS'
                    .rg-admin-example {
                        background: var(--rg-admin-status-warning-bg);
                        color: var(--rg-admin-status-warning-fg);
                    }

                    /* Info tint: system activity and unsaved AI output only. */
                    --rg-admin-status-info-bg: #EEF2FB;
                    --rg-admin-status-info-fg: #2D4373;
                    --rg-admin-status-info-border: #C9D5EE;
                    --rg-admin-status-info-field: #F4F7FD;
                    CSS,
                'rules' => [
                    'Text on a tint always uses the dark shade of the same hue.',
                    '!No gradients, no brand hue besides ink, no colour on large surfaces.',
                    '!Never the public --rg-* tokens: the admin is its own design system.',
                ],
            ],
            'FND-02' => [
                'name' => 'Typography',
                'source' => 'Tokens · tokens.css',
                'kind' => 'tokens',
                'purpose' => 'One family, eight sizes. Weight 500 for emphasis and figures, 600 only for dialog titles and the workspace name.',
                'specs' => [
                    ['Family', 'Inter · ui-sans-serif fallback'],
                    ['Sizes', '11 · 12 · 13 · 14 · 15 · 16 · 20 · 24'],
                    ['Weights', '400 text · 500 emphasis · 600 rare'],
                    ['Tracking', '−0.02em ≥ 20px · −0.01em 15–16px'],
                    ['Overline', '11px uppercase · .04em content · .12em sidebar'],
                    ['Numbers', 'font-variant-numeric: tabular-nums'],
                    ['Mono', 'IDs, keys, slugs, paths, exceptions'],
                ],
                'code' => <<<'CSS'
                    font-size: var(--rg-admin-text-title);      /* 24/32 · 500 */
                    font-size: var(--rg-admin-text-stat);       /* 20/28 · 500 */
                    font-size: var(--rg-admin-text-ui);         /* 15/20 */
                    font-size: var(--rg-admin-text-body);       /* 14/22 */
                    font-size: var(--rg-admin-text-caption);    /* 13/18 */
                    font-size: var(--rg-admin-text-micro);      /* 12/16 */
                    font-size: var(--rg-admin-text-overline);   /* 11/16 uppercase */
                    font-family: var(--rg-admin-font-mono);
                    CSS,
                'rules' => [
                    'Sentence case everywhere. Uppercase only in 11px overlines.',
                    '!No sizes outside the scale; no bold 700.',
                ],
            ],
            'FND-03' => [
                'name' => 'Spacing, radii, elevation',
                'source' => 'Tokens · tokens.css',
                'kind' => 'tokens',
                'purpose' => 'A 4px grid with fixed layout dimensions. Almost nothing is elevated.',
                'specs' => [
                    ['Grid', '4 · 8 · 12 · 16 · 20 · 24 · 28'],
                    ['Sidebar', '300 · icon rail 68 below 1280 px'],
                    ['Top bar', '62'],
                    ['Page gutter', '28'],
                    ['Card gap', '24'],
                    ['Detail panel', '448 (drawer, docked panel) · 480 long lists'],
                    ['Controls', 'sm 32 · md 36 · input 40'],
                    ['Rows', 'table 56–64 · menu 36 · detail 36'],
                    ['Radii', '6 badge · 8 item · 10 control · 12 popover · 16 card · full pill'],
                    ['Scrim', 'drawer rgba(14,18,27,.2) · dialog .28'],
                ],
                'code' => <<<'CSS'
                    --rg-admin-sidebar-width: 300px;   --rg-admin-rail-width: 68px;
                    --rg-admin-topbar-height: 62px;    --rg-admin-page-gutter: 28px;
                    --rg-admin-card-gap: 24px;         --rg-admin-detail-panel-width: 448px;
                    --rg-admin-radius-badge · -item · -control · -popover · -card · -full
                    --rg-admin-shadow-xs · -button-dark · -popover
                    CSS,
                'rules' => [
                    'Cards have no shadow. Only popovers, menus, drawers and toasts are elevated.',
                ],
            ],
            'FND-04' => [
                'name' => 'Icons',
                'source' => 'Blade component · x-admin.ui.icon',
                'kind' => 'component',
                'purpose' => 'Outline icons from one set, Lucide 0.460.0, drawn inline. The names below are every icon the admin has so far.',
                'specs' => [
                    ['Library', 'lucide@0.460.0 geometry, inlined · no package'],
                    ['Stroke', '1.75 (3 inside checkboxes)'],
                    ['Sizes', 'nav 18 · button 14–16 · table 15–18 · inline 12'],
                    ['Colour', 'inherits currentColor · nav secondary · rows tertiary'],
                    ['Unknown name', 'throws: a typo fails loudly, never a blank'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.icon name="flag" :size="12" />

                    {{-- Decorative by default (aria-hidden). Give a label
                         only when the icon carries meaning on its own. --}}
                    <x-admin.ui.icon name="hard-drive" :size="18" label="Media diagnostics" />
                    BLADE,
                'rules' => [
                    'Every icon-only control needs a text label (aria-label and tooltip).',
                    '!No emoji in the interface. Flags appear only next to language names.',
                ],
            ],
            'ACT-01' => [
                'name' => 'Button',
                'source' => 'Blade component · x-admin.ui.button',
                'kind' => 'component',
                'purpose' => 'Primary ink and secondary white buttons in two sizes, plus a ghost text action. Danger is the primary button on a red fill, used only to confirm irreversible actions.',
                'specs' => [
                    ['Height', 'md 36 · sm 32'],
                    ['Padding', 'md 0 14 · sm 0 12 · ghost 0 10'],
                    ['Radius', '10 · ghost 8'],
                    ['Label', 'md 14/500 · sm 13/500 · −0.01em'],
                    ['Icon', 'md 16 · sm 14 · gap 8 / 6'],
                    ['Primary', 'ink fill · shadow-button-dark · hover 88% opacity'],
                    ['Secondary', 'white · hairline · shadow-xs · hover gray-50'],
                    ['Ghost', 'transparent · gray-600 · hover gray-200'],
                    ['Danger', 'primary + background --rg-admin-red-950'],
                    ['Disabled', 'real disabled · 50% opacity · not-allowed'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.button variant="primary">Save changes</x-admin.ui.button>
                    <x-admin.ui.button>Discard</x-admin.ui.button>
                    <x-admin.ui.button size="sm" variant="primary" icon="check">Approve selected (2)</x-admin.ui.button>
                    <x-admin.ui.button trailing-icon="chevron-down">Category: All</x-admin.ui.button>
                    <x-admin.ui.button variant="ghost" size="sm">Clear selection</x-admin.ui.button>

                    {{-- Danger: confirmation dialogs only --}}
                    <x-admin.ui.button variant="danger">Finalize removal</x-admin.ui.button>
                    BLADE,
                'rules' => [
                    'One primary button per region: top bar, dialog footer, drawer footer, bulk bar.',
                    'Labels are verb + object in sentence case: Approve selected, Run full audit.',
                    '!Never use the danger fill for an action that can be undone.',
                ],
            ],
            'ACT-02' => [
                'name' => 'Icon button',
                'source' => 'Blade component · x-admin.ui.icon-button',
                'kind' => 'component',
                'purpose' => 'Square buttons for row menus, closing panels, pagination and stepping through records.',
                'specs' => [
                    ['Size', 'md 36 · sm 32 square'],
                    ['Ghost', 'transparent · gray-500 · hover gray-50'],
                    ['Outline', 'white · hairline · shadow-xs'],
                    ['Icon', 'md 18 · sm 16'],
                    ['Label', 'required · aria-label + title'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />
                    <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" />
                    <x-admin.ui.icon-button icon="refresh-cw" label="Refresh" />
                    <x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" disabled />
                    BLADE,
                'rules' => [
                    'Row menus always use ellipsis, ghost, sm.',
                ],
            ],
            'ACT-03' => [
                'name' => 'Links and text actions',
                'source' => 'CSS primitive · .rg-admin-link',
                'kind' => 'primitive',
                'purpose' => 'Navigation inside text, opening public content, and low-emphasis actions such as Clear selection or Discard.',
                'specs' => [
                    ['Inline link', 'ink 500 · underline offset 3 · decoration gray-300'],
                    ['Public content', 'title link + arrow-up-right 14 tertiary'],
                    ['Quiet link', '12/500 gray-600 + arrow-up-right 12'],
                    ['Arrow link', '13/500 ink + arrow-right 14'],
                    ['Text action', 'x-admin.ui.button variant ghost · 13/500 · 32 h · hover gray-200'],
                ],
                'code' => <<<'BLADE'
                    <a class="rg-admin-link" href="…">Open Translation Center</a>

                    <a class="rg-admin-link rg-admin-link--external" href="…">
                        Rex at the vet <x-admin.ui.icon name="arrow-up-right" :size="14" />
                    </a>

                    <x-admin.ui.button variant="ghost" size="sm">Clear selection</x-admin.ui.button>
                    BLADE,
                'rules' => [
                    'Links that leave the admin show arrow-up-right.',
                    'Links are links and actions are buttons; never one dressed as the other.',
                ],
            ],
            'STS-01' => [
                'name' => 'Status badge',
                'source' => 'Blade component · x-admin.ui.badge',
                'kind' => 'component',
                'purpose' => 'One badge per record state. Each screen maps its backend statuses to tones in a single place, so a status looks the same everywhere.',
                'specs' => [
                    ['Height', '24 · padding 0 8 · radius 6'],
                    ['Label', '12/500 · nowrap'],
                    ['Dot', '6 px · live healthy states, running and unsaved AI'],
                    ['success', 'Published Visible Active Enabled Completed Saved Resolved'],
                    ['warning', 'Pending Open Limited Missing Warning Degraded'],
                    ['danger', 'Rejected Banned Failed Critical Not configured'],
                    ['neutral', 'Hidden Ignored Disabled Inactive Shadowbanned Removal finalized'],
                    ['outline', 'Draft Deleted by author Archived Default'],
                    ['info', 'AI suggestion · not saved, Running, Info'],
                ],
                'code' => <<<'BLADE'
                    {{-- The screen maps status to tone, once: --}}
                    @php
                        [$tone, $dot] = match ($post->status) {
                            PostStatus::Published => ['success', true],
                            PostStatus::Pending => ['warning', false],
                            PostStatus::Hidden => ['neutral', false],
                        };
                    @endphp

                    <x-admin.ui.badge :tone="$tone" :dot="$dot">{{ $label }}</x-admin.ui.badge>
                    BLADE,
                'rules' => [
                    'Map statuses in one place per screen. The badge knows tones, never statuses.',
                    'Put a short note under the badge when time matters: Restorable until 16 Oct.',
                ],
            ],
            'STS-02' => [
                'name' => 'Counters',
                'source' => 'Blade component · x-admin.ui.badge pill',
                'kind' => 'component',
                'purpose' => 'Navigation counts and the report count shown in table rows.',
                'specs' => [
                    ['Nav pill', '22 h · radius full · 12/500'],
                    ['Pill tones', 'warning awaiting action · danger critical · neutral backlog'],
                    ['Report chip', '24 h · radius 6 · flag 12 · 12/500'],
                    ['Chip tone', '1–2 warning · 3+ danger'],
                    ['Zero', '— in gray-300'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.badge tone="warning" pill>8</x-admin.ui.badge>

                    <x-admin.ui.badge :tone="$reports >= 3 ? 'danger' : 'warning'" icon="flag">
                        {{ $reports }}
                    </x-admin.ui.badge>
                    BLADE,
                'rules' => [
                    'Only operational counts belong in navigation. No totals for their own sake.',
                ],
            ],
            'STS-03' => [
                'name' => 'Progress bar',
                'source' => 'CSS primitive · .rg-admin-progress',
                'kind' => 'primitive',
                'purpose' => 'Completeness, health and running progress. One bar, no charts.',
                'specs' => [
                    ['Height', '6 in rows · 8 in summaries'],
                    ['Track', 'gray-200 · radius full'],
                    ['Progress', 'ink · green-500 only at 100%'],
                    ['Invalid', 'red-500 #E5484D'],
                    ['Running', 'info #2D4373'],
                    ['Legend', '13px with 6 px dots'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-progress" role="img" aria-label="63% translated">
                        <span class="rg-admin-progress__bar" style="width: 63%"></span>
                    </div>
                    <span class="rg-admin-table__meta--small">63% · 66 of 104</span>
                    BLADE,
                'rules' => [
                    'The figure is always printed next to the bar; the bar never stands alone.',
                ],
            ],
            'STS-05' => [
                'name' => 'Locale chips',
                'source' => 'CSS primitive · .rg-admin-locale-chip',
                'kind' => 'primitive',
                'purpose' => 'Translation coverage of one record at a glance, next to its English name.',
                'specs' => [
                    ['Size', '18 h · padding 0 5 · radius 5'],
                    ['Label', 'mono 10/500 uppercase'],
                    ['Translated', 'white · hairline · gray-600'],
                    ['Missing', 'amber-50 · amber-900 · “missing” for screen readers'],
                    ['Tooltip', 'language name and value'],
                    ['Summary', '“1 missing” · 12px orange-900'],
                ],
                'code' => <<<'BLADE'
                    <span class="rg-admin-locale-chip" title="Русский: Кролики и грызуны">ru</span>
                    <span class="rg-admin-locale-chip rg-admin-locale-chip--missing" title="Български: missing">
                        bg<span class="rg-admin-sr-only">, missing</span>
                    </span>
                    BLADE,
                'rules' => [
                    '!Never a column per language. The chips summarise; Translation Center edits.',
                ],
            ],
            'FRM-01' => [
                'name' => 'Search field',
                'source' => 'Blade component · x-admin.ui.search-field',
                'kind' => 'component',
                'purpose' => 'Filters the current table as you type. The placeholder names what can be searched.',
                'specs' => [
                    ['Height', '40 · radius 10 · 15px text'],
                    ['Icon', 'search 18 tertiary'],
                    ['Width', 'toolbar 320 · sidebar full'],
                    ['Shortcut', 'Kbd ⌘K on global search only'],
                    ['Focus', 'border gray-500 + 3 px halo'],
                    ['Behaviour', 'filters on input, no submit button'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.search-field
                        placeholder="Search title, author or post ID"
                        wire:model.live.debounce.300ms="search"
                    />

                    <x-admin.ui.search-field placeholder="Search posts, users" shortcut="⌘K" />
                    BLADE,
                'rules' => [
                    'Placeholder says what is searched: “Search username, name or email”.',
                ],
            ],
            'FRM-02' => [
                'name' => 'Text field',
                'source' => 'Blade component · x-admin.ui.text-field',
                'kind' => 'component',
                'purpose' => 'Single-line input with a label above and a hint or error below.',
                'specs' => [
                    ['Field', '40 h · radius 10 · 14px text'],
                    ['Label', '13/500 · 6 above · for= the input'],
                    ['Tag', 'Required (orange-900) / Optional (tertiary) · 12'],
                    ['Hint', '12 tertiary · 6 below · aria-describedby'],
                    ['Error', 'replaces the hint · red-950 + icon · aria-invalid'],
                    ['Focus', 'border gray-500 + 3 px halo'],
                    ['Disabled', 'real disabled · sunken · not-allowed'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.text-field
                        label="Username"
                        name="username"
                        :value="$username"
                        :error="$errors->first('username')"
                        hint="Public as @biscuit_mum."
                    />
                    BLADE,
                'rules' => [
                    'Errors say what to do, not just what is wrong.',
                ],
            ],
            'FRM-03' => [
                'name' => 'Textarea with counter',
                'source' => 'Blade component · x-admin.ui.textarea',
                'kind' => 'component',
                'purpose' => 'Moderation reasons, descriptions, resolution notes and translations. Shows Required or Optional and a character count.',
                'specs' => [
                    ['Min height', '84 (dialogs) · 42 compact (translations)'],
                    ['Padding', '10 12 · 14/22'],
                    ['Tag', 'Required / Optional 12 · orange-900 until valid'],
                    ['Counter', '12 tabular · right · red past the limit'],
                    ['Limit', 'soft: typing past it is allowed, the error says by how much'],
                    ['Tones', 'default · info (generated draft) · changed (unsaved edit)'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.textarea
                        label="Moderation reason"
                        name="reason"
                        :required="true"
                        :limit="500"
                        placeholder="Describe why this action is necessary…"
                        hint="Shared with the author and stored in the moderation log."
                    />
                    BLADE,
            ],
            'FRM-08' => [
                'name' => 'Segmented control',
                'source' => 'Blade component · x-admin.ui.segmented',
                'kind' => 'component',
                'purpose' => 'Two to four mutually exclusive options on one track: Missing only / All, a role, a default sort.',
                'specs' => [
                    ['Track', 'gray-100 · hairline · padding 3 · radius 10'],
                    ['Segment', '32 h (28 compact) · radius 8 · 14px (13 compact)'],
                    ['Checked', 'white · shadow-xs · 500 ink · aria-checked'],
                    ['Keyboard', 'Tab reaches the checked option · arrows, Home and End choose'],
                    ['Binding', 'x-model on the component (x-modelable)'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.segmented
                        label="Show"
                        :options="['missing' => 'Missing only', 'all' => 'All']"
                        x-model="mode"
                    />
                    BLADE,
                'rules' => [
                    'Two to four options; a longer list belongs in a filter dropdown.',
                    '!Never tell the checked option by colour alone.',
                ],
            ],
            'FRM-10' => [
                'name' => 'Filter dropdown and chips',
                'source' => 'Blade component · x-admin.ui.filter-dropdown',
                'kind' => 'component',
                'purpose' => 'A secondary filter next to the search: “Field: value”, and a menu of the values with their counts.',
                'specs' => [
                    ['Trigger', 'secondary button md · “Field: value” · chevron-down'],
                    ['Menu', '240 w · radius 12 · padding 6 · shadow-popover'],
                    ['Item', '36 h · radius 8 · check on the chosen one · optional count'],
                    ['Keyboard', 'Enter, Space or Down opens · Up, Down, Home, End · Escape back to the button'],
                    ['Chip', '28 h · radius 8 · sunken · remove 20 (.rg-admin-filter-chip)'],
                    ['Binding', 'x-model on the component (x-modelable); live counts as Alpine expressions'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.filter-dropdown
                        label="Section"
                        :options="[
                            ['value' => '', 'label' => 'All sections', 'trigger' => 'All'],
                            ['value' => 'categories', 'label' => 'Categories', 'count' => '3 missing'],
                        ]"
                        x-model="section"
                    />
                    BLADE,
                'rules' => [
                    'Close on a choice, Escape or a click outside.',
                ],
            ],
            'FRM-11' => [
                'name' => 'Searchable combobox',
                'source' => 'Blade component · x-admin.ui.combobox',
                'kind' => 'component',
                'purpose' => 'Choosing one value from a long list: the target language among thirty or more.',
                'specs' => [
                    ['Trigger', '52 h · radius 12 · border gray-300 · overline + value · badge · chevrons-up-down'],
                    ['List', '420 w (max 100vw − 32) · search first · max 340 h, scrolls'],
                    ['Option', '44 h · flag · name — native · code, state, missing · %'],
                    ['Search', 'filters in the browser · “No installed language matches.”'],
                    ['Keyboard', 'Enter, Space or arrows open · focus in the search · Up and Down move · Enter chooses · Escape back to the trigger'],
                    ['A11y', 'combobox over a listbox · aria-activedescendant · aria-selected'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.combobox
                        label="Target language"
                        :options="$languages"
                        :value="$locale"
                        search-placeholder="Search 32 target languages"
                        empty="No installed language matches."
                        x-on:choose="$event.preventDefault(); switchTo($event.detail.value)"
                    />
                    BLADE,
                'rules' => [
                    '!Never a column per language. One target language at a time.',
                    'A screen that has to ask first cancels the choose event, then decides.',
                ],
            ],
            'NAV-01' => [
                'name' => 'Sidebar',
                'source' => 'CSS primitive · .rg-admin-nav-item',
                'kind' => 'primitive',
                'purpose' => 'Fixed navigation for every area. Counts show what is waiting. Below 1280 px it collapses to an icon rail with dots. In production App\\Livewire\\Admin\\Sidebar draws it around every admin page from Filament’s own navigation.',
                'specs' => [
                    ['Width', '300 · rail 68 below 1280 px'],
                    ['Item', '40 h · radius 10 · icon 18 · 15px label'],
                    ['Active', 'gray-50 fill · ink 500 label and icon · aria-current'],
                    ['Section', 'overline .12em · gap 10 between sections'],
                    ['Sections', 'Overview · Moderation · Content · Localization · Configuration · System'],
                    ['Counts', 'Posts pending · Comments reported · Reports open · Translation missing · Media critical'],
                    ['Rail', '44×40 icon links · 8 px status dot · tooltip on hover and focus, label kept for screen readers'],
                    ['Below 1024 px', 'no rail · the top bar’s menu button opens the same sidebar as a drawer'],
                    ['Header', '62 · as tall as the top bar, one hairline across'],
                    ['Search', 'FRM-01 under the workspace · ⌘K / Ctrl+K · Filament global search in Admin v2 markup'],
                    ['Source', 'filament()->getNavigation() via AdminShellNavigation · icons in AdminShellNavigation::ICONS'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-nav-section">
                        <p class="rg-admin-nav-section__label" id="nav-moderation">Moderation</p>
                        <ul class="rg-admin-nav-section__items" aria-labelledby="nav-moderation">
                            <li>
                                <a class="rg-admin-nav-item" href="…" aria-current="page">
                                    <x-admin.ui.icon name="image" :size="18" />
                                    <span class="rg-admin-nav-item__label">Posts</span>
                                    <x-admin.ui.badge tone="warning" pill>8</x-admin.ui.badge>
                                </a>
                            </li>
                        </ul>
                    </div>
                    BLADE,
                'rules' => [
                    'Only operational counts belong in navigation. No totals for their own sake.',
                ],
            ],
            'NAV-02' => [
                'name' => 'Top bar and breadcrumb',
                'source' => 'CSS primitive · .rg-admin-topbar',
                'kind' => 'primitive',
                'purpose' => 'Location on the left, page actions on the right. Edit pages show unsaved state next to Save. In production App\\Livewire\\Admin\\Topbar draws it, with the breadcrumb taken from the navigation.',
                'specs' => [
                    ['Height', '62 · padding 0 24 0 28 · white · bottom hairline'],
                    ['Breadcrumb', 'section › page; last item current · on create/edit the resource links back to its list'],
                    ['Page actions', 'TOPBAR_END render hook, scoped to the page · legacy pages keep theirs in the content'],
                    ['Actions', 'gap 10 · primary last'],
                    ['Unsaved', '13px orange-900 “2 unsaved changes”'],
                    ['Meta', '13px tertiary “Updated 09:41”'],
                ],
                'code' => <<<'BLADE'
                    <header class="rg-admin-topbar">
                        <nav class="rg-admin-breadcrumb" aria-label="Breadcrumb">…</nav>
                        <div class="rg-admin-topbar__actions">
                            <span class="rg-admin-topbar__meta">Updated 09:41</span>
                            <x-admin.ui.button icon="refresh-cw">Refresh</x-admin.ui.button>
                        </div>
                    </header>
                    BLADE,
            ],
            'NAV-03' => [
                'name' => 'Status tabs',
                'source' => 'Blade component · x-admin.ui.tabs',
                'kind' => 'component',
                'purpose' => 'The main filter of every list. One tab per status with its count; the default tab is the work queue.',
                'specs' => [
                    ['Height', '40 above the table card'],
                    ['Label', '15px · count 12/500 gray-600'],
                    ['Active', '2 px ink underline · 500'],
                    ['Semantics', 'links with aria-current, or toggles with aria-pressed'],
                    ['Defaults', 'Posts → Pending · Reports → Open · others → All'],
                    ['Counts', 'totals across all pages, not the current page'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.tabs label="Post status" active="pending" :items="[
                        ['id' => 'all', 'label' => 'All', 'count' => '1,262', 'href' => '?status=all'],
                        ['id' => 'pending', 'label' => 'Pending', 'count' => 8, 'href' => '?status=pending'],
                    ]" />
                    BLADE,
            ],
            'NAV-04' => [
                'name' => 'Pagination',
                'source' => 'CSS primitive · .rg-admin-pagination',
                'kind' => 'primitive',
                'purpose' => 'Table footer with range and pages.',
                'specs' => [
                    ['Footer', 'padding 12 16 · 13px gray-600'],
                    ['Range', '“1–25 of 1,231” tabular'],
                    ['Page', '32 min · current sunken + hairline + 500 · aria-current'],
                    ['Arrows', 'x-admin.ui.icon-button ghost sm · disabled at ends'],
                ],
                'code' => <<<'BLADE'
                    <nav class="rg-admin-pagination" aria-label="Pagination">
                        <span class="rg-admin-pagination__range">1–25 of 1,231</span>
                        <ul class="rg-admin-pagination__pages">
                            <li><x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" disabled /></li>
                            <li><a class="rg-admin-pagination__page" aria-current="page" href="?page=1">1</a></li>
                            …
                        </ul>
                    </nav>
                    BLADE,
            ],
            'LAY-01' => [
                'name' => 'Page header',
                'source' => 'CSS primitive · .rg-admin-page-header',
                'kind' => 'primitive',
                'purpose' => 'Title, one sentence on how the screen works, and two to four operational figures.',
                'specs' => [
                    ['Band', 'padding 22 28 20 · white · bottom hairline'],
                    ['Title', '24/32 500 −0.02em'],
                    ['Description', '14/20 gray-600 · max 640'],
                    ['Stats', '24 px padding · vertical hairlines · overline label + 20/28 value'],
                    ['Page layout', 'top bar 62 → header band → scrolling content on gray-50 · gutter 28'],
                ],
                'code' => <<<'BLADE'
                    <section class="rg-admin-page-header">
                        <div>
                            <h1 class="rg-admin-page-header__title">Languages</h1>
                            <p class="rg-admin-page-header__description">…</p>
                        </div>
                        <dl class="rg-admin-stats">
                            <div class="rg-admin-stat">
                                <dt class="rg-admin-stat__label">Installed</dt>
                                <dd class="rg-admin-stat__value">4</dd>
                            </div>
                        </dl>
                    </section>
                    BLADE,
                'rules' => [
                    '!No decorative charts or vanity totals in the header.',
                ],
            ],
            'LAY-02' => [
                'name' => 'Card and sections',
                'source' => 'Blade component · x-admin.ui.card',
                'kind' => 'component',
                'purpose' => 'The container for tables, forms and summaries. Sections inside are split by full-width hairlines.',
                'specs' => [
                    ['Card', 'white · hairline · radius 16 · no shadow'],
                    ['Header', 'padding 16 20 · title 15/500 · sub 13 tertiary'],
                    ['Section', 'padding 16 20 · hairline between'],
                    ['Summary footer', 'sunken gray-50 for read-only notes'],
                    ['Grid', 'two columns: fluid + 448 panel · gap 24'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.card title="Profile" description="Public identity and contact address.">
                        …fields…
                        <x-slot:footer>Translations are edited in Translation Center.</x-slot:footer>
                    </x-admin.ui.card>

                    {{-- flush: the slot is drawn edge to edge, e.g. a table --}}
                    <x-admin.ui.card flush clip>…</x-admin.ui.card>
                    BLADE,
            ],
            'LAY-03' => [
                'name' => 'Detail rows and section labels',
                'source' => 'CSS primitive · .rg-admin-detail-row',
                'kind' => 'primitive',
                'purpose' => 'Read-only facts in panels and drawers: account details, asset properties, a language’s catalog.',
                'specs' => [
                    ['Row', 'min 36 · icon 16 tertiary · label 14 gray-600 · value 14/500 right'],
                    ['Label', '11px overline · rule optional · trailing 13px value'],
                    ['Mono values', 'IDs and paths 12px mono, ellipsis + title'],
                    ['Semantics', 'dl / dt / dd'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-section-label">
                        <span class="rg-admin-section-label__text">Bulgarian</span>
                        <span class="rg-admin-section-label__trailing">Never enabled</span>
                    </div>
                    <dl class="rg-admin-detail-list">
                        <div class="rg-admin-detail-row">
                            <x-admin.ui.icon name="globe" class="rg-admin-detail-row__icon" />
                            <dt class="rg-admin-detail-row__label">Locale</dt>
                            <dd class="rg-admin-detail-row__value rg-admin-detail-row__value--mono">bg</dd>
                        </div>
                    </dl>
                    BLADE,
            ],
            'TBL-01' => [
                'name' => 'Table row',
                'source' => 'CSS primitive · .rg-admin-table',
                'kind' => 'primitive',
                'purpose' => 'All list screens share one table built from CSS grid rows. Header and rows use the same column template, which each screen sets for itself.',
                'specs' => [
                    ['Structure', 'div rows · role table/row/columnheader/cell · one grid template'],
                    ['Header', '40 h · overline 11px tertiary · bottom hairline'],
                    ['Row', 'min 56–64 · bottom hairline'],
                    ['States', 'hover gray-50 · selected sunken + checked box'],
                    ['Cells', 'padding 0 12 · first 16 · two lines: 14 primary + 12–13 meta'],
                    ['Overflow', 'min-width on the grid · card scrolls horizontally'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-table">
                        <div class="rg-admin-table__scroll">
                            <div class="rg-admin-table__grid" role="table" style="
                                --rg-admin-table-columns: 44px 56px minmax(200px, 1fr) 160px 140px 80px 120px 192px;
                                --rg-admin-table-min-width: 992px;">
                                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">…</div>
                                <div class="rg-admin-table__row" role="row">…</div>
                            </div>
                        </div>
                    </div>
                    BLADE,
                'rules' => [
                    'Never hide a column the moderator needs; scroll horizontally instead.',
                    'Time-sensitive rows show age (“Waiting 3 h 12 min”) in orange-900 after 2 h.',
                    '!No generic table framework: each screen writes its own grid template.',
                ],
            ],
            'TBL-02' => [
                'name' => 'Table toolbar',
                'source' => 'CSS primitive · .rg-admin-toolbar',
                'kind' => 'primitive',
                'purpose' => 'Search, secondary filters, active filter chips and the result count at the top of the table card.',
                'specs' => [
                    ['Padding', '14 16 · bottom hairline · gap 10 · wraps'],
                    ['Order', 'search · filters · segmented · chips · count right'],
                    ['Filter', 'Button md + chevron-down · “Field: value”'],
                    ['Chip', '28 h · radius 8 · sunken · remove 20 with label'],
                    ['Count', '13px tertiary · “3 posts match”'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-toolbar">
                        <x-admin.ui.search-field class="rg-admin-toolbar__search" placeholder="Search title, author or post ID" />
                        <x-admin.ui.button trailing-icon="chevron-down">Category: All</x-admin.ui.button>
                        <span class="rg-admin-toolbar__count">8 posts</span>
                    </div>
                    BLADE,
            ],
            'TBL-04' => [
                'name' => 'Row actions and menu',
                'source' => 'CSS primitive · .rg-admin-menu',
                'kind' => 'primitive',
                'purpose' => 'The one or two actions used most for a status are inline. Everything else is in the row menu, including unavailable actions with the reason. The menu is shown as a static surface; its popover behaviour arrives with the first migrated table.',
                'specs' => [
                    ['Inline', 'secondary sm buttons · by status'],
                    ['Trigger', 'icon button ellipsis ghost sm'],
                    ['Menu', '248–272 w · radius 12 · padding 6 · shadow-popover'],
                    ['Item', 'icon 16 + 14px label + optional 12px hint'],
                    ['Disabled', 'tertiary · kept visible · hint gives the reason'],
                    ['Destructive', 'red-950 · after a separator'],
                ],
                'code' => <<<'BLADE'
                    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                        <x-admin.ui.button size="sm">Approve</x-admin.ui.button>
                        <x-admin.ui.button size="sm">Reject</x-admin.ui.button>
                        <x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />
                    </div>
                    BLADE,
                'rules' => [
                    '!Never hide an unavailable action; disable it and say why.',
                ],
            ],
            'TBL-05' => [
                'name' => 'Empty states',
                'source' => 'Blade component · x-admin.ui.empty-state',
                'kind' => 'component',
                'purpose' => 'Distinguishes a finished queue from a search with no results.',
                'specs' => [
                    ['Icon', '40 circle · sunken (success tint for done) · 18 icon'],
                    ['Title', '15/500'],
                    ['Body', '13/18 tertiary · max 380'],
                    ['Action', 'only when the user can change the outcome (Clear filters)'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.empty-state icon="circle-check" title="The queue is clear">
                        Every submitted post has been reviewed.
                    </x-admin.ui.empty-state>

                    <x-admin.ui.empty-state title="No posts match these filters">
                        Try a different search or remove the category filter.
                        <x-slot:action><x-admin.ui.button size="sm">Clear filters</x-admin.ui.button></x-slot:action>
                    </x-admin.ui.empty-state>
                    BLADE,
            ],
            'TBL-06' => [
                'name' => 'Loading skeleton',
                'source' => 'Blade component · x-admin.ui.skeleton',
                'kind' => 'component',
                'purpose' => 'Placeholder rows while a table reloads. Toolbar and tabs stay usable.',
                'specs' => [
                    ['Shape', 'same grid as the real row'],
                    ['Bars', 'gray-200 / gray-100 · radius full · 8–10 h'],
                    ['Motion', 'opacity pulse 1.2 s · no shimmer · off for reduced motion'],
                    ['A11y', 'bars aria-hidden · the region says what is loading'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.skeleton width="62%" height="10px" />
                    <x-admin.ui.skeleton width="40px" height="40px" shape="box" tone="soft" />
                    BLADE,
            ],
            'OVL-01' => [
                'name' => 'Confirmation dialog',
                'source' => 'Blade component · x-admin.ui.confirm-dialog',
                'kind' => 'component',
                'purpose' => 'Confirms an action with consequences before it runs, or explains why it cannot run. Open the live examples below; they change nothing.',
                'specs' => [
                    ['Panel', '520 w · radius 16 · shadow-popover · 11vh from the top'],
                    ['Scrim', 'rgba(14,18,27,.28) · Escape, the scrim, × and Cancel dismiss'],
                    ['Header', '36 tone circle · title 16/24 600 · body 14/22 secondary'],
                    ['Light', 'tone default · Cancel + the action, primary'],
                    ['Warning', 'tone warning · amber circle · a secondary way out, e.g. Review missing'],
                    ['Blocked', 'explains why and offers the alternative · no confirm'],
                    ['Focus', 'moves inside · cannot leave · returns to the trigger, or to the page heading once the trigger is gone'],
                    ['Page behind', 'hidden from assistive technology · does not scroll'],
                ],
                'code' => <<<'BLADE'
                    @if ($confirming)
                        <x-admin.ui.confirm-dialog
                            tone="warning"
                            icon="globe"
                            title="Disable German?"
                            x-on:dismiss="$wire.closeConfirmation()"
                        >
                            <p>Visitors currently using German will get their browser's language…</p>

                            <x-slot:actions>
                                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                                <x-admin.ui.button variant="primary" wire:click="disableLanguage('de')">Disable German</x-admin.ui.button>
                            </x-slot:actions>
                        </x-admin.ui.confirm-dialog>
                    @endif
                    BLADE,
                'rules' => [
                    'The confirm label repeats the action: “Disable German”, never “OK”.',
                    '!Never confirm reversible, low-risk actions; use a toast instead.',
                    '!Never ask for a reason the product does not store.',
                ],
            ],
            'OVL-02' => [
                'name' => 'Drawer',
                'source' => 'Blade component · x-admin.ui.drawer',
                'kind' => 'component',
                'purpose' => 'Inspects or edits one record without leaving the list: the missing translations of a language, later a category or a media asset.',
                'specs' => [
                    ['Size', '448 w · wide 480 for long lists · full height · right · max 100vw'],
                    ['Header', '62 h · leading · title 15/500 + 12px subtitle · close'],
                    ['Body', 'scrolls on its own · sections split by hairlines'],
                    ['Footer', 'note or actions · destructive on the left · hairline above'],
                    ['Scrim', 'rgba(14,18,27,.2) · Escape, the scrim and × close'],
                    ['Focus', 'moves inside · cannot leave · returns to the trigger, or to the page heading once the trigger is gone'],
                ],
                'code' => <<<'BLADE'
                    @if ($missingLocale)
                        <x-admin.ui.drawer
                            wide
                            title="Missing in German"
                            subtitle="de · 82% project content"
                            x-on:dismiss="$wire.closeMissing()"
                        >
                            <x-slot:leading>🇩🇪</x-slot:leading>
                            …sections…
                            <x-slot:footer>Visitors see the English text wherever a translation is missing.</x-slot:footer>
                        </x-admin.ui.drawer>
                    @endif
                    BLADE,
                'rules' => [
                    'One drawer at a time; a dialog opened from it replaces it.',
                ],
            ],
            'FBK-01' => [
                'name' => 'Toast',
                'source' => 'Blade component · x-admin.ui.toast-stack',
                'kind' => 'component',
                'purpose' => 'Confirms what just happened. The shell draws one stack on every admin page; a screen raises a toast with a browser event.',
                'specs' => [
                    ['Surface', 'ink · white 14px · radius 12 · shadow-popover'],
                    ['Position', 'bottom centre of the main column · 24 from bottom'],
                    ['Duration', '5.2 s · max 3 stacked · waits while hovered or focused'],
                    ['Icon', 'success green-500 · error #FF9AA2 · info gray-400'],
                    ['Screen readers', 'status region for success and info · alert region for errors · each heard once'],
                    ['Undo', 'only for reversible actions · not wired into the stack until one needs it'],
                ],
                'code' => <<<'BLADE'
                    {{-- Livewire --}}
                    $this->dispatch('rg-admin-toast', message: 'German enabled', tone: 'success');

                    {{-- Alpine --}}
                    <button x-on:click="$dispatch('rg-admin-toast', { message: 'Copied', tone: 'info' })">…</button>
                    BLADE,
                'rules' => [
                    '!Never confirm reversible, low-risk actions such as Approve or Restore; use a toast with Undo.',
                    '!A toast is never the only record of a lasting error; the screen shows it too.',
                ],
            ],
            'FBK-02' => [
                'name' => 'Inline notice',
                'source' => 'Blade component · x-admin.ui.inline-notice',
                'kind' => 'component',
                'purpose' => 'Explains a rule or a consequence in place: unsaved AI output, blocked transitions, a hidden target with an open report.',
                'specs' => [
                    ['Box', 'radius 10 · padding 10 12 · 13/18 · icon 16'],
                    ['Info', 'info tint · info'],
                    ['Warning', 'amber tint · circle-alert'],
                    ['Danger', 'red tint · triangle-alert · 500 · irreversible dialogs only'],
                    ['Success', 'green tint · circle-check'],
                    ['Strip', 'full-width variant under a card toolbar · actions right'],
                ],
                'code' => <<<'BLADE'
                    <x-admin.ui.inline-notice tone="warning">
                        Links to /c/small-pets will stop working after you save.
                    </x-admin.ui.inline-notice>

                    <x-admin.ui.inline-notice tone="info" icon="sparkles" strip>
                        2 AI suggestions not saved yet.
                        <x-slot:actions><x-admin.ui.button variant="ghost" size="sm">Discard all</x-admin.ui.button></x-slot:actions>
                    </x-admin.ui.inline-notice>
                    BLADE,
            ],
            'DOM-01' => [
                'name' => 'Translation field states',
                'source' => 'Composition · badge + textarea + notice',
                'kind' => 'component',
                'purpose' => 'Stored, missing, AI suggestion not saved and edited not saved must never look alike. Only Save turns a suggestion into project data.',
                'specs' => [
                    ['Saved', 'success badge + dot · white field'],
                    ['Missing', 'warning badge · placeholder · English fallback note'],
                    ['AI not saved', 'info badge + dot · field #F4F7FD, border #C9D5EE · Regenerate / Discard'],
                    ['Edited', 'outline badge · border gray-400 · Discard'],
                    ['Errors', 'border red-950 · “Keep {contact_email}” · “4 over the limit” · Save disabled'],
                    ['Bulk', 'strip counts unsaved items · Save all generated in top bar'],
                ],
                'code' => <<<'BLADE'
                    @php
                        $state = $draft ? ($draft->generated ? 'ai' : 'edited') : ($stored ? 'saved' : 'missing');
                    @endphp

                    {{-- saved   → badge success + dot · textarea tone default
                         missing → badge warning · placeholder · “Visitors see the English text”
                         ai      → badge info + dot · textarea tone info · Regenerate / Discard
                         edited  → badge outline · textarea tone changed · Discard --}}
                    <x-admin.ui.badge tone="info" dot>AI suggestion · not saved</x-admin.ui.badge>
                    <x-admin.ui.textarea label="Category name" tone="info" :limit="32" :value="$draft->text" compact />
                    BLADE,
                'rules' => [
                    '!Never write AI output to the database before the administrator saves it.',
                    'Switching the target language asks before dropping unsaved drafts.',
                ],
            ],
        ];
    }
}
