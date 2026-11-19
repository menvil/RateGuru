<?php

namespace App\Support\Traffic\Links;

/**
 * A link read the way the server reads a click (LinkInspector): whether it
 * is a link to paste (every parameter a token), a real click (every one
 * filled in), a mix, or not paid at all; the source, game and campaign it
 * names; each parameter's state; and what to fix.
 *
 * @phpstan-type Field array{label: string, param: string, value: string, state: string}
 * @phpstan-type Warning array{tone: string, text: string}
 */
final readonly class LinkInspection
{
    /**
     * @param  list<Field>  $fields
     * @param  list<array{param: string, value: string}>  $extra
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public string $mode,
        public ?int $sourceId,
        public ?string $sourceName,
        public ?string $gameTitle,
        public ?string $campaignId,
        public ?string $campaignName,
        public array $fields,
        public array $extra,
        public array $warnings,
    ) {}
}
