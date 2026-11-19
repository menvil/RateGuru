{{--
    The post picker of a game editor (PicksPosts): published posts with
    their picture, the most voted first, searched on the server, each with
    its author, its share of upvotes and its votes — marked when they fall
    short of a crowd pick's bar, where there is one. Picking calls
    pickPost(id); closing, closePicker().
--}}
<x-admin.ui.drawer id="rg-admin-game-picker" :title="$picker['title']" subtitle="Published posts with their picture, the most voted first." wide x-on:dismiss="$wire.closePicker()">
    <div class="rg-admin-games__form">
        <x-admin.ui.search-field placeholder="Search title, ID or author" name="pickerSearch" id="rg-admin-game-picker-search" :value="$pickerSearch" wire:model.live.debounce.300ms="pickerSearch" clearable />

        @if ($picker['posts'] === [])
            <x-admin.ui.empty-state icon="search-x" title="No posts match">Only published posts with a ready picture can be chosen.</x-admin.ui.empty-state>
        @else
            <ul class="rg-admin-games__picker" data-testid="game-post-picker">
                @foreach ($picker['posts'] as $post)
                    <li wire:key="picker-post-{{ $post['id'] }}">
                        <button type="button" class="rg-admin-games__picker-item" wire:click="pickPost({{ $post['id'] }})">
                            <span class="rg-admin-table__thumb">
                                @if ($post['thumbnail'] !== null)
                                    <img src="{{ $post['thumbnail'] }}" alt="" loading="lazy" class="rg-admin-games__picker-thumb" />
                                @else
                                    <x-admin.ui.icon name="image" :size="16" />
                                @endif
                            </span>
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $post['title'] !== '' ? $post['title'] : 'Post #'.$post['id'] }}</span>
                                <span class="rg-admin-games__note"><span class="rg-admin-mono">#{{ $post['id'] }}</span>@if ($post['author']) · {{ '@'.$post['author'] }}@endif · {{ $post['share'] !== null ? $post['share'].'% upvotes' : 'no votes yet' }}</span>
                            </span>
                            <x-admin.ui.badge :tone="$post['enough'] ? 'neutral' : 'warning'">{{ number_format($post['votes']) }} votes</x-admin.ui.badge>
                        </button>
                    </li>
                @endforeach
            </ul>
            <x-admin.ui.pagination :paginator="$picker['paginator']" noun="posts" note="most voted first" action="pickerGoToPage" label="Post pages" />
        @endif
        @if (($picker['note'] ?? null) !== null)
            <p class="rg-admin-games__note">{{ $picker['note'] }}</p>
        @endif
    </div>
</x-admin.ui.drawer>
