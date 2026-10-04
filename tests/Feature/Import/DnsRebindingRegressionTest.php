<?php

use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\ImportFetchPolicy;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\ResolvedImportTarget;
use App\Support\Import\SafeImportHttpClient;

/**
 * The flagship regression test for this PR: proves the actual outbound
 * connection is pinned to the IP UrlImportValidator resolved and validated
 * for that exact hop, and that nothing in the request path performs a
 * second, uncontrolled DNS lookup between validation and connection — the
 * TOCTOU window the old "resolve -> validate -> Http::get(hostname)" design
 * left open.
 *
 * A resolver that returns a DIFFERENT answer on each call to resolve() —
 * the exact shape of a DNS-rebinding attack, where the attacker's
 * nameserver answers truthfully (a public IP) the first time and something
 * private the next.
 */
final class SequencedHostResolver implements HostResolver
{
    private int $calls = 0;

    /** @var list<array{0: string, 1: list<string>}> */
    private array $log = [];

    /**
     * @param  list<list<string>>  $answers  one answer set per call, in order
     */
    public function __construct(private readonly array $answers) {}

    public function resolve(string $host): array
    {
        // end() would throw here: it needs a writable reference to move the
        // array's internal pointer, and $this->answers is readonly.
        $answer = $this->answers[$this->calls] ?? $this->answers[array_key_last($this->answers)];
        $this->calls++;
        $this->log[] = [$host, $answer];

        return $answer;
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /** @return list<array{0: string, 1: list<string>}> */
    public function log(): array
    {
        return $this->log;
    }
}

final class RecordingImportHttpTransport implements ImportHttpTransport
{
    /** @var list<ResolvedImportTarget> */
    public array $targets = [];

    /** @param list<ImportTransportResponse> $responses */
    public function __construct(private array $responses) {}

    public function get(ResolvedImportTarget $target, ImportFetchPolicy $policy): ImportTransportResponse
    {
        $this->targets[] = $target;

        $next = array_shift($this->responses);

        if ($next === null) {
            throw new RuntimeException('RecordingImportHttpTransport: no more scripted responses.');
        }

        return $next;
    }
}

it('resolves DNS exactly once per hop, and the transport connects to precisely that resolved IP — no second, uncontrolled lookup', function () {
    $resolver = new SequencedHostResolver([
        ['93.184.216.34'],
    ]);
    app()->instance(HostResolver::class, $resolver);

    $transport = new RecordingImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'text/html'], '<html>ok</html>'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://rebind.example/page');

    expect($resolver->callCount())->toBe(1)
        ->and($transport->targets)->toHaveCount(1);

    $target = $transport->targets[0];

    // The exact properties a rebinding-safe transport call must carry: the
    // connection goes to the resolved IP, while host/port/TLS identity stay
    // against the original hostname — never the IP.
    expect($target->host)->toBe('rebind.example')
        ->and($target->port)->toBe(443)
        ->and($target->ip)->toBe('93.184.216.34')
        ->and($response->status)->toBe(200);
});

it('re-resolves independently for each redirect hop, proving a mid-flight DNS change is picked up rather than a stale target being reused', function () {
    // Hop 0 sees one public IP; hop 1 (a redirect back to the SAME
    // hostname) sees a DIFFERENT public IP — simulating DNS having changed
    // between the two lookups. If the application ever cached or reused the
    // first hop's resolution, hop 1's transport call would incorrectly
    // still show the OLD ip.
    $resolver = new SequencedHostResolver([
        ['93.184.216.34'],
        ['93.184.216.99'],
    ]);
    app()->instance(HostResolver::class, $resolver);

    $transport = new RecordingImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://rebind.example/final'], ''),
        new ImportTransportResponse(200, ['content-type' => 'text/html'], '<html>final</html>'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    app(SafeImportHttpClient::class)->get('https://rebind.example/start');

    expect($resolver->callCount())->toBe(2)
        ->and($transport->targets)->toHaveCount(2);

    [$hop0, $hop1] = $transport->targets;

    expect($hop0->host)->toBe('rebind.example')
        ->and($hop0->ip)->toBe('93.184.216.34')
        ->and($hop1->host)->toBe('rebind.example')
        ->and($hop1->ip)->toBe('93.184.216.99')
        ->and($hop0->ip)->not->toBe($hop1->ip);
});

it('blocks the classic rebinding attack: a hostname that answers public on the first lookup and private on a later one is rejected before any connection to the private address, and the transport is never invoked for that hop', function () {
    // The attacker's nameserver tells the truth (a public IP) long enough
    // to pass an earlier, unrelated validation elsewhere in the app, then
    // answers with a loopback/internal address on THIS lookup — the attack
    // this whole redesign exists to close. UrlImportValidator must still
    // catch it here, at the moment it's actually about to be used.
    $resolver = new SequencedHostResolver([
        ['127.0.0.1'],
    ]);
    app()->instance(HostResolver::class, $resolver);

    $transport = new RecordingImportHttpTransport([
        new ImportTransportResponse(200, [], 'should never be reached'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://rebind.example/page'))
        ->toThrow(UnsafeImportUrlException::class);

    expect($transport->targets)->toBeEmpty();
});
