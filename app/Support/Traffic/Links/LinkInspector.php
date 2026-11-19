<?php

namespace App\Support\Traffic\Links;

use App\Enums\TrafficLinkParam;
use Closure;

/**
 * Reads a pasted link the way the server reads a click: its source by rg_src,
 * the game it opens, each of the source's parameters — missing, a token
 * still waiting to be filled in, or a value — and the campaign it would
 * count for. The source, game and campaign lookups are handed in, so it
 * reads nothing itself.
 *
 * @phpstan-type Source array{id: int, name: string, link_params: list<array{kind: string, label: string, param: string, token: string, note: string}>, api_spend: bool}
 */
final class LinkInspector
{
    /**
     * @param  Closure(string): (Source|null)  $source  by link key
     * @param  Closure(string): (string|null)  $game  a live game's title by its address
     * @param  Closure(int, string): (array{name: string|null, archived: bool}|null)  $campaign  by source and the network's campaign ID
     */
    public static function inspect(string $text, string $ownHost, Closure $source, Closure $game, Closure $campaign): LinkInspection
    {
        $text = trim($text);
        $url = preg_match('#^https?://#i', $text) === 1 ? $text : (str_starts_with($text, '/') ? 'https://'.$ownHost.$text : 'https://'.$text);
        // A value's ##NAME## token is no fragment: encoded, it stays in the query, read back as it was.
        $url = preg_replace('/(?<==)##([^#&=]+)##/', '%23%23$1%23%23', $url) ?? $url;
        $parts = parse_url($url);

        if ($text === '' || $parts === false || ! isset($parts['host'])) {
            return new LinkInspection('bad', null, null, null, null, null, [], [], [['tone' => 'danger', 'text' => 'This isn’t a link. Paste the whole address, starting with https://']]);
        }

        $query = self::query((string) ($parts['query'] ?? ''));
        $warnings = [];

        if (strcasecmp((string) $parts['host'], $ownHost) !== 0) {
            $warnings[] = ['tone' => 'danger', 'text' => 'The link opens '.$parts['host'].', not this site: no click on it reaches RateGuru.'];
        }

        $slug = preg_match('#^/games/([^/?\#]+)#', (string) ($parts['path'] ?? ''), $match) === 1 ? rawurldecode($match[1]) : null;
        $gameTitle = $slug === null ? null : $game($slug);

        if ($slug === null) {
            $warnings[] = ['tone' => 'warning', 'text' => 'The link doesn’t open a game. Visits still count for the source, but no game gets them.'];
        } elseif ($gameTitle === null) {
            $warnings[] = ['tone' => 'warning', 'text' => "No game has the address “{$slug}”."];
        }

        $key = $query['rg_src'] ?? null;
        $found = $key === null || $key === '' ? null : $source($key);

        if ($key === null || $key === '') {
            $warnings[] = ['tone' => 'danger', 'text' => 'No rg_src in the link. RateGuru can’t tell it’s paid: visits will count as unpaid (another site or direct).'];
        } elseif ($found === null) {
            $warnings[] = ['tone' => 'danger', 'text' => "No source has the key “{$key}”. Check the key under Sources, or add the source first."];
        }

        $fields = [];
        $used = ['rg_src' => true];
        $tokens = 0;
        $filled = 0;
        $campaignId = null;

        foreach ($found['link_params'] ?? [] as $param) {
            if ($param['param'] === '') {
                continue;
            }

            $used[$param['param']] = true;
            $value = $query[$param['param']] ?? null;
            // An empty value is none: the capture drops it, as it drops a token left unfilled.
            $value = $value !== null && trim($value) === '' ? null : $value;
            $isToken = $value !== null && LinkTokenDetector::isToken($value);
            $kind = TrafficLinkParam::tryFrom($param['kind']);
            $shown = $param['param'].'='.($param['token'] !== '' ? $param['token'] : '…');

            if ($value === null) {
                $fields[] = ['label' => $param['label'], 'param' => $param['param'], 'value' => 'missing', 'state' => 'missing'];
            } else {
                $fields[] = ['label' => $param['label'], 'param' => $param['param'], 'value' => $value, 'state' => $isToken ? 'token' : 'ok'];
                $isToken ? $tokens++ : $filled++;
            }

            if ($value === null && $kind === TrafficLinkParam::Click) {
                $warnings[] = ['tone' => 'danger', 'text' => 'No '.mb_strtolower($param['label'])." ({$shown}): postbacks can’t tell {$found['name']} which click it was."];
            }

            if ($value === null && $kind === TrafficLinkParam::Campaign) {
                $warnings[] = ['tone' => 'warning', 'text' => "No campaign ({$shown}): every campaign’s clicks will count as one."];
            }

            if ($value === null && $kind === TrafficLinkParam::Cost) {
                $warnings[] = ['tone' => 'neutral', 'text' => 'No click cost: spend comes from the '.($found['api_spend'] ? 'API' : 'price you set').' instead.'];
            }

            if ($kind === TrafficLinkParam::Campaign && $value !== null && ! $isToken) {
                $campaignId = $value;
            }
        }

        $extra = [];

        foreach ($query as $param => $value) {
            if (! isset($used[$param])) {
                $extra[] = ['param' => (string) $param, 'value' => $value];
            }
        }

        $mode = match (true) {
            $found === null => 'none',
            $tokens > 0 && $filled === 0 => 'template',
            $tokens > 0 => 'mixed',
            default => 'click',
        };
        $known = $found !== null && $campaignId !== null ? $campaign($found['id'], $campaignId) : null;

        if ($mode === 'mixed') {
            $warnings[] = ['tone' => 'warning', 'text' => "Some tokens weren’t filled in. In a real click {$found['name']} replaces every one; if they arrive as they are, the token is wrong for this network."];
        }

        if ($mode === 'click') {
            $leads = $gameTitle !== null ? " Leads to {$gameTitle}." : '';
            $warnings[] = match (true) {
                $known !== null => ['tone' => 'success', 'text' => 'Counts for '.($known['name'] ?? 'campaign '.$campaignId).($known['archived'] ? ', which is archived: this click brings it back.' : '.').$leads],
                $campaignId !== null => ['tone' => 'neutral', 'text' => "Campaign {$campaignId} isn’t known yet: this click adds it to the list as new, and it works at once."],
                default => ['tone' => 'warning', 'text' => 'No campaign in the click.'],
            };
        }

        if ($mode === 'template') {
            $warnings[] = ['tone' => 'success', 'text' => "Paste it as the target URL of every {$found['name']} campaign".($gameTitle !== null ? " of {$gameTitle}" : '').". {$found['name']} fills in each token on every click."];
        }

        return new LinkInspection($mode, $found['id'] ?? null, $found['name'] ?? null, $gameTitle, $campaignId, $known['name'] ?? null, $fields, $extra, $warnings);
    }

    /**
     * The query as the server reads it: a token's braces and brackets kept
     * as they are, every name once, the last value winning.
     *
     * @return array<string, string>
     */
    private static function query(string $query): array
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[rawurldecode(str_replace('+', ' ', $name))] = rawurldecode(str_replace('+', ' ', $value));
        }

        return $pairs;
    }
}
