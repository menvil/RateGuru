{{--
    Choose a post (OVL-02): published posts with a ready picture, the most
    voted first, searched on the server, each with its share of upvotes and
    its votes — and whether that is enough for this set's crowd rounds.
--}}
<x-admin.ui.drawer id="rg-admin-game-picker" :title="'Choose a post for '.strtolower($picker['option'])" subtitle="Published posts with their picture, the most voted first." wide x-on:dismiss="$wire.closePicker()">
    <div class="rg-admin-games__form">
        <x-admin.ui.search-field placeholder="Search title, ID or author" name="pickerSearch" id="rg-admin-game-picker-search" :value="$pickerSearch" wire:model.live.debounce.300ms="pickerSearch" clearable />

        @if ($picker['posts'] === [])
            <x-admin.ui.empty-state icon="search-x" title="No posts match">Only published posts with a ready picture can be shown in a game.</x-admin.ui.empty-state>
        @else
            <ul class="rg-admin-games__picker">
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
        <p class="rg-admin-games__note">A crowd pick needs both posts to have at least {{ number_format($picker['minVotes']) }} site votes.</p>
    </div>
</x-admin.ui.drawer>
