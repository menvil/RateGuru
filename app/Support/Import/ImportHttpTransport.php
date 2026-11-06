<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;

/**
 * The one place an actual outbound TCP connection is opened for an import
 * fetch. Given a ResolvedImportTarget, an implementation must connect to
 * exactly $target->ip — never re-resolve $target->host itself — while still
 * sending $target->host as the Host header and TLS SNI/certificate name, and
 * must never disable TLS verification.
 *
 * Redirects are not this interface's concern: it fetches exactly one hop and
 * returns whatever status/headers/body came back (including a 3xx with a
 * Location header) for the caller to act on.
 */
interface ImportHttpTransport
{
    /**
     * @throws ImportFetchException
     */
    public function get(ResolvedImportTarget $target, ImportFetchPolicy $policy): ImportTransportResponse;
}
