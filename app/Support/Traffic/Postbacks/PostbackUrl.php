<?php

namespace App\Support\Traffic\Postbacks;

/**
 * A postback address filled in: every placeholder replaced by its value,
 * each encoded as a value — except {click_id}, filled in only as the
 * postback is sent, from the click's own record, so the address kept in the
 * log never carries it. A placeholder with no value becomes nothing.
 */
final class PostbackUrl
{
    public const string CLICK_ID = '{click_id}';

    /** @param  array<string, string>  $values  by placeholder name, `param:zone` for a parameter of the click */
    public static function render(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{([^{}]*)\}/', function (array $match) use ($values): string {
            if ($match[1] === 'click_id') {
                return self::CLICK_ID;
            }

            return rawurlencode($values[$match[1]] ?? '');
        }, $template);
    }

    public static function withClickId(string $url, string $clickId): string
    {
        return str_replace(self::CLICK_ID, rawurlencode($clickId), $url);
    }
}
