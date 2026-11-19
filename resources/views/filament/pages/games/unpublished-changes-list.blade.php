{{--
    Every change a new version would put live, by content set and flow, in a
    disclosure that stays open while the page redraws around it. Shown where
    more changes are counted than are named: the editors' notice and Versions.

    @param int $count  every change
    @param list<array{title: string, changes: list<string>}> $groups
    @param string $testid
--}}
<details class="rg-admin-games__unpublished-all" wire:ignore.self data-testid="{{ $testid }}">
    <summary class="rg-admin-games__unpublished-toggle">
        <x-admin.ui.icon name="chevron-right" :size="14" class="rg-admin-games__unpublished-chevron" />
        All {{ $count }} changes
    </summary>
    @foreach ($groups as $group)
        <p class="rg-admin-games__unpublished-group">{{ $group['title'] }}</p>
        <ul class="rg-admin-games__unpublished-list" aria-label="{{ $group['title'] }}">
            @foreach ($group['changes'] as $change)
                <li class="rg-admin-games__unpublished-change">{{ $change }}</li>
            @endforeach
        </ul>
    @endforeach
</details>
