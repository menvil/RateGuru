<?php

namespace App\Support\Traffic\Postbacks;

/**
 * A postback address as the administrator types it: what may be in it, and
 * what each placeholder becomes. An http or https address on a host name
 * (never an IP) at port 80 or 443, with no user name, password or fragment;
 * placeholders only in its path and query, only these, and {click_id}
 * always — without it the network cannot tell which click converted.
 */
final class PostbackTemplate
{
    public const int MAX_LENGTH = 2000;

    /** @var list<string> */
    public const array PLACEHOLDERS = ['click_id', 'event', 'goal_code', 'value', 'value_total', 'currency', 'conversion_id', 'device', 'game', 'version'];

    /**
     * What is wrong with the address, in words for the administrator; none
     * when it can be sent. A preset's address keeps its gaps (…) for the
     * administrator to fill in, so a preset is checked with $allowGaps.
     *
     * @return list<string>
     */
    public static function violations(string $template, bool $allowGaps = false): array
    {
        $template = trim($template);

        if ($template === '') {
            return ['Enter the postback address.'];
        }

        $problems = [];

        if (mb_strlen($template) > self::MAX_LENGTH) {
            $problems[] = 'The address is longer than '.self::MAX_LENGTH.' characters.';
        }

        if (! $allowGaps && str_contains($template, '…')) {
            $problems[] = 'Fill in the parts marked … with your account’s values.';
        }

        $parts = parse_url(str_replace('…', 'x', $template));

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return [...$problems, 'The address must start with https:// or http://.'];
        }

        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || str_contains($host, '{') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            $problems[] = 'The address must name a host, such as postback.example.com, not an IP address.';
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            $problems[] = 'Only ports 80 and 443 can be used.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $problems[] = 'The address can’t carry a user name or password.';
        }

        if (isset($parts['fragment'])) {
            $problems[] = 'Remove the part after #: it never reaches the network.';
        }

        foreach (self::placeholders($template) as $placeholder) {
            if (! self::isKnown($placeholder)) {
                $problems[] = "{{$placeholder}} isn’t a placeholder the site fills in.";
            }
        }

        if (! str_contains($template, '{click_id}')) {
            $problems[] = 'Put {click_id} in the address: without it the network can’t tell which click converted.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * The switched-on rules that report to the same goal of the network: the
     * same address once each rule's own {goal_code} and {event} are in it,
     * the parameters that only carry another placeholder set aside, and its
     * query read in any order. The
     * network counts each of them as another conversion of the same click and
     * adds up their values, so one estimate of a visitor arrives as several.
     *
     * @param  array<int|string, array<string, mixed>>  $rules
     * @return list<list<int|string>> the keys of the rules in each such group, in their order
     */
    public static function sharedGoals(array $rules): array
    {
        $groups = [];

        foreach ($rules as $key => $rule) {
            $template = trim((string) ($rule['url_template'] ?? ''));

            if (! (bool) ($rule['enabled'] ?? false) || $template === '') {
                continue;
            }

            $groups[self::destination($template, (string) ($rule['goal_code'] ?? ''), (string) ($rule['event'] ?? ''))][] = $key;
        }

        return array_values(array_filter($groups, fn (array $keys): bool => count($keys) > 1));
    }

    /** Where an address reports to, as the network tells one goal from another. */
    private static function destination(string $template, string $goalCode, string $event): string
    {
        $address = preg_replace_callback('/\{([^{}]*)\}/', fn (array $match): string => match ($match[1]) {
            // Encoded as PostbackUrl::render() sends them, so a "+" and a space stay two goals.
            'goal_code' => rawurlencode($goalCode),
            'event' => rawurlencode($event),
            default => '{}',
        }, $template) ?? $template;
        $parts = parse_url($address);

        if ($parts === false) {
            return $address;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        // A parameter that only carries a placeholder — the click, the worth — says nothing of which goal it is.
        $query = array_filter($query, fn (mixed $value): bool => $value !== '{}');
        ksort($query);

        return strtolower((string) ($parts['host'] ?? '')).($parts['path'] ?? '').'?'.http_build_query($query);
    }

    /** @return list<string> the names in braces, in order, each once */
    public static function placeholders(string $template): array
    {
        preg_match_all('/\{([^{}]*)\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function isKnown(string $placeholder): bool
    {
        return in_array($placeholder, self::PLACEHOLDERS, true)
            || preg_match('/^param:[a-z][a-z0-9_]{0,23}$/', $placeholder) === 1;
    }
}
