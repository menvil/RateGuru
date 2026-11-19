{{--
    A step's headline, the same way on every screen of a game: up to 80
    characters on one line, more on two, as large as fills the column's width
    up to its size's ceiling and down to 18px (games.js, rgGameLine; its
    length gives a first guess until then, and wire:ignore.self keeps the size
    it sets when the page draws again). The size is the screen's: a title, a
    smaller title, a round's question or the result's headline.
--}}
@props(['text', 'as' => 'h1', 'size' => 'title'])
@php
    $classes = match ($size) {
        'sm' => 'rg-game__title rg-game__title--sm',
        'question' => 'rg-game__question',
        'result' => 'rg-game__result-headline',
        default => 'rg-game__title',
    };
    $length = mb_strlen((string) $text);
@endphp
<{{ $as }} {{ $attributes->class([$classes, 'rg-game__fit', 'rg-game__fit--two' => $length > 80]) }} style="--rg-game-line-chars: {{ max(1, $length) }}" data-lines="{{ $length > 80 ? 2 : 1 }}" x-data="rgGameLine" x-on:resize.window="fit()" wire:ignore.self>{{ $text }}</{{ $as }}>
