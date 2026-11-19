<?php

namespace App\Support\Traffic\Capture;

use App\Support\Traffic\Links\LinkTokenDetector;

/**
 * What a paid click's address says, read by the source's own link
 * parameters: the network's click id, campaign, site, zone, creative and the
 * click's price, and up to twenty other parameters kept for the postback's
 * {param:name}. A value that is a network's unfilled placeholder, not a
 * string, or longer than its limit counts as none; a price must be a plain
 * decimal number.
 */
final readonly class ClickParameters
{
    public const int MAX_CLICK_ID = 1000;

    public const int MAX_REF = 120;

    private const int MAX_EXTRA = 20;

    private const int MAX_EXTRA_LENGTH = 200;

    /** @param  array<string, string>  $extra */
    public function __construct(
        public ?string $clickId,
        public ?string $campaign,
        public ?string $site,
        public ?string $zone,
        public ?string $creative,
        public ?string $cost,
        public array $extra,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query
     * @param  list<array{kind: string, param: string}>  $linkParams
     */
    public static function parse(array $query, array $linkParams): self
    {
        $values = ['click' => null, 'campaign' => null, 'site' => null, 'zone' => null, 'creative' => null, 'cost' => null];
        $named = ['rg_src' => true];

        foreach ($linkParams as $param) {
            $name = $param['param'];
            $kind = $param['kind'];

            if ($name === '' || ! array_key_exists($kind, $values)) {
                continue;
            }

            $named[$name] = true;
            $values[$kind] ??= self::value($query[$name] ?? null, $kind === 'click' ? self::MAX_CLICK_ID : self::MAX_REF);
        }

        $creative = $values['creative'] ?? self::value($query['rg_cr'] ?? null, self::MAX_REF);
        $named['rg_cr'] = true;
        $cost = $values['cost'] !== null && preg_match('/^\d{1,6}(\.\d{1,8})?$/', $values['cost']) === 1 ? $values['cost'] : null;

        $extra = [];

        foreach ($query as $name => $value) {
            if (count($extra) >= self::MAX_EXTRA) {
                break;
            }

            $name = (string) $name;

            if (isset($named[$name]) || preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $name) !== 1) {
                continue;
            }

            $value = self::value($value, self::MAX_EXTRA_LENGTH);

            if ($value !== null) {
                $extra[$name] = $value;
            }
        }

        return new self($values['click'], $values['campaign'], $values['site'], $values['zone'], $creative, $cost, $extra);
    }

    private static function value(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max || LinkTokenDetector::isToken($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }
}
