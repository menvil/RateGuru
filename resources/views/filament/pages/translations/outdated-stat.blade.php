{{--
    How many translations of a content screen's texts need an update — made from English since changed — beside its
    other figures, as a link to them in Translation Center's Needs update; nothing while none does.
--}}
@if ($outdated['count'] > 0)
    <div class="rg-admin-stat" data-testid="translations-outdated">
        <dt class="rg-admin-stat__label">Translations to update</dt>
        <dd class="rg-admin-stat__value">
            @if ($outdated['url'] !== null)
                <a href="{{ $outdated['url'] }}" class="rg-admin-link">{{ number_format($outdated['count']) }}</a>
            @else
                {{ number_format($outdated['count']) }}
            @endif
        </dd>
    </div>
@endif
