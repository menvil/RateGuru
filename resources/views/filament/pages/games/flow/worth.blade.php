{{--
    What reaching the selected step is worth, mobile and desktop, every source
    and country: a row for each thing it lets the visitor do, marked where the
    value was set by hand, and the way to every price on the Value tab.
--}}
@php($money = fn (string $amount): string => \App\Support\Traffic\Money\TrafficMoney::brief($amount))
<section class="rg-admin-games__worth" data-testid="game-flow-step-worth">
    <div class="rg-admin-games__worth-head">
        <span class="rg-admin-games__worth-title"><x-admin.ui.icon name="circle-dollar-sign" :size="14" />What this step is worth</span>
        <x-admin.ui.button size="sm" :href="$valueUrl" trailing-icon="arrow-right">All prices</x-admin.ui.button>
    </div>
    <div class="rg-admin-games__worth-grid" role="table" aria-label="What this step is worth">
        <div class="rg-admin-games__worth-row rg-admin-games__worth-row--head" role="row">
            <span role="columnheader">When reached</span><span role="columnheader">Mobile</span><span role="columnheader">Desktop</span>
        </div>
        @foreach ($stepWorth as $row)
            <div class="rg-admin-games__worth-row" role="row">
                <span role="cell">{{ $row['name'] }}{{ $row['manual'] ? ' · by hand' : '' }}</span>
                <span role="cell">{{ $money($row['mobile']) }}</span>
                <span role="cell">{{ $money($row['desktop']) }}</span>
            </div>
        @endforeach
    </div>
    <p class="rg-admin-games__note">
        @unless ($worthWorkedOut)
            Not worked out yet: the chances need visits two weeks old or more.
        @endunless
        Worked out from the traffic: the chance that a visitor who gets here ends up a confirmed account, times what an account brings. Every source counted; the Value tab splits it by source and country group and lets you set a step by hand. Changing it needs no new version.
    </p>
</section>
