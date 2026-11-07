{{-- A post vote as the Post votes tab shows it: up, down, or none. $vote is `up`, `down` or null. --}}
@if ($vote === 'up')
    <span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--up">▲ Up</span>
@elseif ($vote === 'down')
    <span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--down">▼ Down</span>
@else
    <span class="rg-admin-studio__muted">—</span>
@endif
