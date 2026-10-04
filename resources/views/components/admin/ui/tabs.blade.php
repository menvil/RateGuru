@props([
    'items' => [],
    'active' => null,
    'label' => 'Filter',
])

{{--
    Status tabs: the main filter of a list, one tab per status with its count.
    Each item is ['id' => …, 'label' => …, 'count' => …?, 'href' => …?]. An item
    with an href is a link (aria-current on the active one); without one it is a
    toggle button (aria-pressed) for a screen that filters in place.
--}}
<nav aria-label="{{ $label }}" {{ $attributes }}>
    <ul class="rg-admin-tabs">
        @foreach ($items as $item)
            @php
                $isActive = $item['id'] === $active;
            @endphp
            <li>
                @isset($item['href'])
                    <a href="{{ $item['href'] }}" class="rg-admin-tab" @if ($isActive) aria-current="page" @endif>
                        {{ $item['label'] }}
                        @isset($item['count'])
                            <span class="rg-admin-tab__count">{{ $item['count'] }}</span>
                        @endisset
                    </a>
                @else
                    <button type="button" class="rg-admin-tab" aria-pressed="{{ $isActive ? 'true' : 'false' }}">
                        {{ $item['label'] }}
                        @isset($item['count'])
                            <span class="rg-admin-tab__count">{{ $item['count'] }}</span>
                        @endisset
                    </button>
                @endisset
            </li>
        @endforeach
    </ul>
</nav>
