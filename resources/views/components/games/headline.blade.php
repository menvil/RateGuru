{{--
    A step's headline, the same way on every screen of a game: up to 80
    characters on one line, more on two, as large as fills the column's width
    up to its size's ceiling and down to 18px (games.js, rgGameLine; its
    length gives a first guess until then, and wire:ignore.self keeps the size
    it sets when the page draws again). The size is the screen's: a title, a
    smaller title, a round's question or the result's headline. The text is
    the game's own, written in the admin, so it reads its own way: an English
    headline on a page read right to left keeps its "?" at its end. A step's
    text comes as a StepText, which knows its direction and what was put into
    it; any other text people wrote, as a string.
--}}
@props(['text', 'as' => 'h1', 'size' => 'title'])
@php
    $classes = match ($size) {
        'sm' => 'rg-game__title rg-game__title--sm',
        'question' => 'rg-game__question',
        'result' => 'rg-game__result-headline',
        default => 'rg-game__title',
    };
    $stepText = $text instanceof \App\Support\Games\Play\StepText;
    $length = mb_strlen($stepText ? $text->text : (string) $text);
    $dir = $stepText ? $text->dir() : \App\Support\Locale\WrittenText::dir((string) $text);
@endphp
<{{ $as }} {{ $attributes->class([$classes, 'rg-game__fit', 'rg-game__fit--two' => $length > 80]) }} {{ $dir }} style="--rg-game-line-chars: {{ max(1, $length) }}" data-lines="{{ $length > 80 ? 2 : 1 }}" x-data="rgGameLine" x-on:resize.window="fit()" wire:ignore.self>{{ $text }}</{{ $as }}>
