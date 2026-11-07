<?php

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * What Sentry is never sent of an outgoing request: the address beyond its
 * path. The SDK records the query string and the fragment of every request
 * the application makes, and the application fetches addresses people paste
 * — a presigned image link, a share token — whose credentials live exactly
 * there. Nor the value a unique-constraint violation names: masking the
 * query's bindings leaves the driver's own text, which repeats it.
 * NightwatchPrivacy strips the same for Nightwatch.
 *
 * Registered in config/sentry.php as callables, so `config:cache` keeps them.
 */
final class SentryPrivacy
{
    private const FILTERED = '[Filtered]';

    /** The data an outgoing request's breadcrumb and span carry beyond the path. */
    private const REQUEST_ADDRESS_DATA = ['http.query', 'http.fragment', 'url.full'];

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        foreach (self::REQUEST_ADDRESS_DATA as $key) {
            if (array_key_exists($key, $breadcrumb->getMetadata())) {
                $breadcrumb = $breadcrumb->withMetadata($key, self::FILTERED);
            }
        }

        return $breadcrumb;
    }

    public static function beforeSend(Event $event): Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(UniqueViolationValue::redact($exception->getValue(), self::FILTERED));
        }

        return $event;
    }

    public static function beforeSendTransaction(Event $event): Event
    {
        foreach ($event->getSpans() as $span) {
            $data = $span->getData();
            $filtered = array_intersect_key($data, array_flip(self::REQUEST_ADDRESS_DATA));

            if ($filtered !== []) {
                $span->setData(array_replace($data, array_fill_keys(array_keys($filtered), self::FILTERED)));
            }
        }

        return $event;
    }
}
