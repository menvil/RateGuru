@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['ACT-01']])
    <div class="rg-admin-kit__row">
        <div class="rg-admin-kit__item">
            <x-admin.ui.button variant="primary">Save changes</x-admin.ui.button>
            <span class="rg-admin-kit__caption">primary · md</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button>Discard</x-admin.ui.button>
            <span class="rg-admin-kit__caption">secondary · md</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button variant="primary" size="sm" icon="check">Approve selected (2)</x-admin.ui.button>
            <span class="rg-admin-kit__caption">primary · sm · icon</span>
        </div>
        <div class="rg-admin-kit__item">
            <div class="rg-admin-kit__wrap">
                <x-admin.ui.button size="sm">Approve</x-admin.ui.button>
                <x-admin.ui.button size="sm">Reject</x-admin.ui.button>
            </div>
            <span class="rg-admin-kit__caption">row actions · secondary sm</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button trailing-icon="chevron-down">Category: All</x-admin.ui.button>
            <span class="rg-admin-kit__caption">filter trigger</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button variant="ghost" size="sm">Clear selection</x-admin.ui.button>
            <span class="rg-admin-kit__caption">ghost · text action</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button variant="danger">Finalize removal</x-admin.ui.button>
            <span class="rg-admin-kit__caption">danger · dialogs only</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button variant="primary" disabled>Save changes</x-admin.ui.button>
            <span class="rg-admin-kit__caption">primary · disabled</span>
        </div>
        <div class="rg-admin-kit__item">
            <x-admin.ui.button disabled>Discard</x-admin.ui.button>
            <span class="rg-admin-kit__caption">secondary · disabled</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['ACT-02']])
    <div class="rg-admin-kit__row">
        <div class="rg-admin-kit__item rg-admin-kit__item--center">
            <x-admin.ui.icon-button icon="ellipsis" label="More actions" variant="ghost" size="sm" />
            <span class="rg-admin-kit__caption">ghost sm</span>
        </div>
        <div class="rg-admin-kit__item rg-admin-kit__item--center">
            <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" />
            <span class="rg-admin-kit__caption">close</span>
        </div>
        <div class="rg-admin-kit__item rg-admin-kit__item--center">
            <x-admin.ui.icon-button icon="refresh-cw" label="Refresh" />
            <span class="rg-admin-kit__caption">outline md</span>
        </div>
        <div class="rg-admin-kit__item rg-admin-kit__item--center">
            <div class="rg-admin-kit__wrap">
                <x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" />
                <x-admin.ui.icon-button icon="chevron-right" label="Next page" variant="ghost" size="sm" />
            </div>
            <span class="rg-admin-kit__caption">pager</span>
        </div>
        <div class="rg-admin-kit__item rg-admin-kit__item--center">
            <x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" disabled />
            <span class="rg-admin-kit__caption">disabled</span>
        </div>
    </div>
@endcomponent

@component('filament.pages.admin-ui-kit.spec', ['spec' => $specs['ACT-03']])
    <div class="rg-admin-kit__row rg-admin-kit__row--center">
        <a class="rg-admin-link" href="#ACT-03">Open Translation Center</a>
        <a class="rg-admin-link rg-admin-link--external" href="#ACT-03">
            Rex at the vet — please be kind
            <x-admin.ui.icon name="arrow-up-right" :size="14" />
        </a>
        <a class="rg-admin-link rg-admin-link--quiet" href="#ACT-03">
            Edit source
            <x-admin.ui.icon name="arrow-up-right" :size="12" />
        </a>
        <a class="rg-admin-link rg-admin-link--arrow" href="#ACT-03">
            Review pending posts
            <x-admin.ui.icon name="arrow-right" :size="14" />
        </a>
        <x-admin.ui.button variant="ghost" size="sm">Clear selection</x-admin.ui.button>
        <x-admin.ui.button variant="ghost" size="sm">Discard</x-admin.ui.button>
    </div>
@endcomponent
