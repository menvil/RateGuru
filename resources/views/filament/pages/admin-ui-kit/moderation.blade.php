@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['DOM-02']])
    <div class="rg-admin-kit__frame rg-admin-kit__chain">
        <x-admin.ui.timeline-item icon="flag" title="Reported as spam" time="08:31">
            <span>Reported by <strong class="rg-admin-reports__strong">biscuit_mum</strong> · 2 open reports on this post</span>
        </x-admin.ui.timeline-item>
        <x-admin.ui.timeline-item icon="image" title="Post #48227">
            <div class="rg-admin-reports__actions">
                <x-admin.ui.badge tone="warning">Pending</x-admin.ui.badge>
                <span class="rg-admin-reports__spacer"></span>
                <x-admin.ui.button size="sm" icon="eye-off">Hide target</x-admin.ui.button>
            </div>
        </x-admin.ui.timeline-item>
        <x-admin.ui.timeline-item initials="KO" title="Author · kotofey">
            <div class="rg-admin-reports__actions">
                <x-admin.ui.badge tone="warning">Limited</x-admin.ui.badge>
                <x-admin.ui.badge tone="outline">Not trusted</x-admin.ui.badge>
            </div>
        </x-admin.ui.timeline-item>
        <x-admin.ui.timeline-item icon="ban" title="Banned 09:41" tone="danger" last>
            <span>The danger tone: a link that records a sanction.</span>
        </x-admin.ui.timeline-item>
    </div>
@endcomponent
