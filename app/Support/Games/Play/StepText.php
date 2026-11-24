<?php

namespace App\Support\Games\Play;

use App\Support\Locale\WrittenText;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * One of a step's texts as the game page draws it.
 *
 * What an administrator wrote reads its own way: the element that holds it
 * alone takes dir() — dir="auto", or nothing for a text with no letter — and
 * among the page's own words, on a button beside its arrow, it sits in a <bdi>
 * (inline()). A value put into one of its {placeholders} that people wrote too
 * — the game's title, the result's name — sits in a <bdi> of its own, which
 * dir="auto" passes over: the administrator's own words decide the direction,
 * never a title put in front of them. "{game} میں میرا نتیجہ: {profile}۔" stays
 * an Urdu sentence with an English title in it, and the title keeps its "!" at
 * its end.
 *
 * The site's own words, shown where the administrator left a text empty, keep
 * the page's direction.
 */
final readonly class StepText implements Htmlable
{
    /**
     * @param  string  $text  the text as it is shared and measured: plain, every value put in
     */
    private function __construct(
        public string $text,
        private string $html,
        private bool $written,
        private bool $hasLetter,
    ) {}

    /**
     * @param  array<string, string>  $isolated  placeholders filled with text people wrote, each read its own way
     * @param  array<string, string>  $replace  the other placeholders, filled as they are
     */
    public static function written(string $template, array $isolated = [], array $replace = []): self
    {
        $pieces = array_map(fn (string $value): string => '<bdi>'.e($value).'</bdi>', $isolated);

        return new self(
            text: strtr($template, [...$replace, ...$isolated]),
            html: strtr(e($template), [...array_map(fn (string $value): string => e($value), $replace), ...$pieces]),
            written: true,
            hasLetter: WrittenText::hasLetter(strtr($template, [...$replace, ...array_fill_keys(array_keys($isolated), '')])),
        );
    }

    /** The site's own words: a line of its catalogs, or one IsolatedTranslation drew. */
    public static function own(string|Htmlable $line): self
    {
        $html = $line instanceof Htmlable ? $line->toHtml() : e($line);

        return new self(
            text: $line instanceof Htmlable ? html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5) : $line,
            html: $html,
            written: false,
            hasLetter: false,
        );
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    /** The text with the line breaks it was written with. */
    public function lines(): HtmlString
    {
        return new HtmlString(nl2br($this->html));
    }

    /** The direction of the element that holds this text alone. */
    public function dir(): HtmlString
    {
        return new HtmlString($this->written && $this->hasLetter ? 'dir="auto"' : '');
    }

    /**
     * The text among the page's own words, as one element — one item of a
     * button's flex row, so a space inside it stays a space: in a <bdi> when
     * an administrator wrote it, in the page's direction otherwise.
     */
    public function inline(): HtmlString
    {
        return new HtmlString(($this->written ? '<bdi>' : '<span>').$this->html.($this->written ? '</bdi>' : '</span>'));
    }
}
