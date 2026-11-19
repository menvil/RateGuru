<?php

namespace App\Support\Traffic\Links;

/**
 * Tells a network's placeholder from a real value. Networks write their
 * tokens as {click_id}, [IMPRESSIONID], ##SUB_ID## or ${SUBID}; a link pasted
 * from a campaign's settings still holds them, and a click whose network
 * failed to fill one in arrives with it as it is — which must count as no
 * value, or every such click would share one id.
 */
final class LinkTokenDetector
{
    private const string PATTERN = '/^(\{[^{}]*\}|\[[^\[\]]*\]|##[^#]+##|\$\{[^{}]*\})$/';

    public static function isToken(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && (preg_match(self::PATTERN, $value) === 1 || preg_match(self::PATTERN, rawurldecode($value)) === 1);
    }
}
