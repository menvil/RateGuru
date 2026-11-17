<?php

namespace App\Support\Traffic\Links;

/**
 * The link to paste into a network's campaigns, or onto a banner: the page it
 * opens, the source's key in rg_src, and for a network each parameter with
 * the network's token, raw — the network fills it in on every click, and an
 * encoded token is one it no longer recognises.
 */
final class TrackingLinkBuilder
{
    /** @param  list<array{kind: string, label: string, param: string, token: string, note: string}>  $linkParams */
    public static function forNetwork(string $address, string $linkKey, array $linkParams): string
    {
        $pairs = ['rg_src='.rawurlencode($linkKey)];

        foreach ($linkParams as $param) {
            if ($param['param'] !== '' && $param['token'] !== '') {
                $pairs[] = rawurlencode($param['param']).'='.$param['token'];
            }
        }

        return self::join($address, $pairs);
    }

    public static function forPlacement(string $address, string $linkKey, ?string $creative): string
    {
        $pairs = ['rg_src='.rawurlencode($linkKey)];

        if ($creative !== null && $creative !== '') {
            $pairs[] = 'rg_cr='.rawurlencode($creative);
        }

        return self::join($address, $pairs);
    }

    /** @param  list<string>  $pairs */
    private static function join(string $address, array $pairs): string
    {
        return $address.(str_contains($address, '?') ? '&' : '?').implode('&', $pairs);
    }
}
