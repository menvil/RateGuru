<?php

namespace App\Support\Import;

/**
 * Which schemes and ports an outbound request to an address someone typed may
 * use. The import of a post reads its own from config/import.php; a postback
 * to an ad network allows http and https on 80 and 443. Everything else
 * UrlImportValidator checks — the host, its DNS answers all public, the IP
 * the connection is pinned to — is the same for both.
 */
final readonly class OutboundUrlPolicy
{
    /**
     * @param  list<string>  $schemes
     * @param  list<int>  $ports
     */
    public function __construct(
        public array $schemes,
        public array $ports,
    ) {}

    public static function import(): self
    {
        return new self(
            array_values(array_map('strval', (array) config('import.allowed_schemes', ['https']))),
            array_values(array_map('intval', (array) config('import.allowed_ports', [80, 443]))),
        );
    }

    public static function postback(): self
    {
        return new self(['http', 'https'], [80, 443]);
    }
}
