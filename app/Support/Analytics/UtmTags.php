<?php

namespace App\Support\Analytics;

use App\Support\Traffic\Links\LinkTokenDetector;

/**
 * The campaign tags an address carries — utm_source, utm_medium,
 * utm_campaign, utm_content and utm_term — as a visit keeps them for its
 * session (`traffic_visits.utm_*`). Each is trimmed, rid of control
 * characters and cut to 120 characters; one that is no text, a network's
 * unfilled placeholder ({source}, [CAMPAIGN], ##ZONE## or ${TERM}) or empty
 * is none. The same keys name the tags of an address, of an arrival token
 * and the columns of a visit, so any of them is read the same way. Pure.
 */
final readonly class UtmTags
{
    public const int MAX_LENGTH = 120;

    /** @var list<string> */
    public const array KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    private function __construct(
        public ?string $source,
        public ?string $medium,
        public ?string $campaign,
        public ?string $content,
        public ?string $term,
    ) {}

    /**
     * The tags of an address's query, an arrival token's tags or a visit's columns.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function fromQuery(array $values): self
    {
        return new self(
            self::clean($values['utm_source'] ?? null),
            self::clean($values['utm_medium'] ?? null),
            self::clean($values['utm_campaign'] ?? null),
            self::clean($values['utm_content'] ?? null),
            self::clean($values['utm_term'] ?? null),
        );
    }

    /** A tag as a visit keeps it; null when nothing is left of it. */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = self::trim((string) preg_replace('/\p{Cc}/u', '', $value));

        if ($value === '' || LinkTokenDetector::isToken($value)) {
            return null;
        }

        $value = self::trim(mb_substr($value, 0, self::MAX_LENGTH, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * Whether an arrival with these tags came by other tags than the session
     * carrying the given ones. An arrival without any — a link inside the
     * site, a page opened again — comes by the session's own.
     */
    public function differFrom(self $session): bool
    {
        return ! $this->isEmpty() && ! $this->equals($session);
    }

    /** The creative the tags name: utm_content, else utm_term. */
    public function creative(): ?string
    {
        return $this->content ?? $this->term;
    }

    /**
     * The tags there are, by their parameter names, as an arrival token carries them.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter($this->columns(), fn (?string $value): bool => $value !== null);
    }

    /**
     * All five, as a visit's columns.
     *
     * @return array{utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, utm_content: string|null, utm_term: string|null}
     */
    public function columns(): array
    {
        return [
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
            'utm_content' => $this->content,
            'utm_term' => $this->term,
        ];
    }

    /** Trimmed of white space, the kinds outside ASCII included. */
    private static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);
    }
}
