<?php

namespace App\Support\Traffic\Postbacks;

use App\Enums\TrafficPostbackOutcome;

/**
 * What a network's answer to a postback means. A 2xx is taken as received
 * unless the source's rules say otherwise — ExoClick answers 200 to
 * everything and says in words whether it took it, had it already, or
 * refused it; PopAds answers 200 with an empty body and names an error only
 * in a header (a rule matching `header` looks for the header named in its
 * text); with no rule matching, a source can ask to try again. A 4xx
 * refuses it for good, but a timeout (408) or too many requests (429) is
 * tried again, as is a redirect (never followed) and a 5xx.
 */
final class PostbackResponse
{
    /**
     * @param  array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null  $rules
     * @param  array<string, string>  $headers  by lower-case name
     */
    public static function outcome(int $status, string $body, ?array $rules, array $headers = []): TrafficPostbackOutcome
    {
        if ($status >= 200 && $status < 300) {
            if ($rules === null || $rules['rules'] === []) {
                return ($rules['otherwise'] ?? 'ok') === 'retry' ? TrafficPostbackOutcome::HttpError : TrafficPostbackOutcome::Ok;
            }

            $text = mb_strtolower(trim($body));

            foreach ($rules['rules'] as $rule) {
                $wanted = mb_strtolower(trim($rule['text']));
                $matches = match ($rule['match']) {
                    'equals' => $text === $wanted,
                    'header' => trim($headers[$wanted] ?? '') !== '',
                    default => str_contains($text, $wanted),
                };

                if ($matches) {
                    return TrafficPostbackOutcome::tryFrom($rule['outcome']) ?? TrafficPostbackOutcome::Ok;
                }
            }

            return $rules['otherwise'] === 'retry' ? TrafficPostbackOutcome::HttpError : TrafficPostbackOutcome::Ok;
        }

        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)) {
            return TrafficPostbackOutcome::Rejected;
        }

        return TrafficPostbackOutcome::HttpError;
    }

    /**
     * The start of an answer, to keep in the log: control characters as
     * spaces, and a body that is not text — a GIF pixel, a page in another
     * encoding — told as bytes, since a column of text can't hold them;
     * after what the network said in a header a rule reads, when it did, a
     * byte of it that is no text kept as "?".
     */
    public static function excerpt(string $body, string $said = ''): string
    {
        $text = mb_check_encoding($body, 'UTF-8') ? $body : '[not text: '.strlen($body).' bytes]';
        $said = mb_check_encoding($said, 'UTF-8') ? $said : mb_scrub($said, 'UTF-8');

        return mb_strcut((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($said.$text)), 0, 255);
    }
}
