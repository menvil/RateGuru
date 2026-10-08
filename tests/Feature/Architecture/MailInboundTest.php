<?php

use Illuminate\Support\Facades\File;

/**
 * The reviewed inbound mail contract, and the CLI that validates it, renders
 * the inbound plan and the DNS a receiver would need, and judges one recipient
 * the way a receiver would at RCPT TO.
 *
 * A production target receives at three destinations, told apart by the
 * recipient's domain alone: support (exact addresses at its mail domain),
 * bounce (`<prefix>-<identifier>` at its bounce domain) and reply (the same
 * form, another prefix, at its reply domain). The domains belong to the mail
 * routing policy, and the contract never restates them. Public SMTP is
 * `disabled`, the one state there is: no receiver exists, nothing listens and
 * no MX is published.
 *
 * Every behavioural test runs the shipped infrastructure/scripts/mail-inbound
 * against fixture files. None restates a rule in PHP: a validator reimplemented
 * here would prove only that two copies agree.
 */

/** A well-formed base32-128 identifier: 26 lowercase characters, no i, l, o or u, first 0-7. */
const MAIL_INBOUND_IDENTIFIER = '01hzx3k9q2w8e7r6t5y4v3p2m1';

function mailInboundScript(): string
{
    return base_path('infrastructure/scripts/mail-inbound');
}

/** @return array<string, mixed> */
function mailInboundContract(): array
{
    return json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The committed contract — or BASE — with dot-path changes applied.
 *
 * @param  array<string, mixed>  $set
 * @param  list<string>  $forget
 * @param  array<string, mixed>|null  $base
 * @return array<string, mixed>
 */
function mailInboundContractWith(array $set = [], array $forget = [], ?array $base = null): array
{
    return withDotPaths($base ?? mailInboundContract(), $set, $forget);
}

/**
 * The synthetic demo-shop's inbound policy: its own MX host, two support
 * mailboxes and its own prefixes. None of it appears in the implementation.
 *
 * @return array<string, mixed>
 */
function mailInboundDemoShopPolicy(): array
{
    return [
        'mx_hostname' => 'in.demo-shop.example',
        'support' => ['local_parts' => ['help', 'postmaster', 'press']],
        'bounce' => ['prefix' => 'dsn', 'identifier' => 'base32-128'],
        'reply' => ['prefix' => 'ans', 'identifier' => 'base32-128'],
    ];
}

/**
 * Documents for a host with the synthetic demo-shop beside tits-guru: the
 * committed registry and routing policy plus demo-shop (held, its own domains),
 * and an inbound contract that does or does not receive for it.
 *
 * @param  array<string, mixed>  $routing  demo-shop routing overrides
 * @param  array<string, mixed>|null  $inbound  demo-shop inbound policy, null for none
 * @return array<string, array<string, mixed>>
 */
function mailInboundDemoShop(array $routing = [], ?array $inbound = null): array
{
    $policy = mailRoutingPolicy();
    $policy['targets']['demo-shop'] = [...mailRoutingDemoShopPolicy(), ...$routing];

    $contract = mailInboundContract();

    if ($inbound !== null) {
        $contract['targets']['demo-shop'] = $inbound;
    }

    return ['inbound' => $contract, 'routing' => $policy, 'registry' => mailRoutingDemoShopRegistry()];
}

/**
 * The demo-shop routing identity inside tits.guru's own zone, so one target's
 * receiver can try to claim a name of the other.
 *
 * @return array<string, string>
 */
function mailInboundNestedDemoShopRouting(): array
{
    return [
        'mail_domain' => 'shop.tits.guru',
        'default_from' => 'hello@shop.tits.guru',
        'bounce_domain' => 'bounce.shop.tits.guru',
        'reply_domain' => 'reply.shop.tits.guru',
    ];
}

/**
 * Run the shipped CLI. Each document given is written to a scratch file and
 * passed with its own flag; one left out is the committed file, reached
 * through the script's defaults. A string document is written verbatim, for
 * one that is not a valid contract to begin with.
 *
 * stdout and stderr are kept apart: a refusal must print nothing on stdout,
 * and a plan or verdict must be nothing but JSON.
 *
 * @param  list<string>  $arguments
 * @param  array<string, array<string, mixed>|string>  $documents  keyed inbound, routing, registry, outbound, identity
 * @return array{status: int, stdout: string, stderr: string}
 */
function mailInboundRun(array $arguments, array $documents = [], ?string $script = null): array
{
    $scratch = makeScratchDir('mail-inbound');

    try {
        foreach ($documents as $name => $document) {
            expect(['inbound', 'routing', 'registry', 'outbound', 'identity'])->toContain($name);

            file_put_contents("{$scratch}/{$name}.json", is_string($document) ? $document : mailRoutingJson($document));
            $arguments = [...$arguments, "--{$name}", "{$scratch}/{$name}.json"];
        }

        $process = proc_open(
            ['bash', $script ?? mailInboundScript(), ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $scratch,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $scratch],
        );

        expect($process)->not->toBeFalse('could not start mail-inbound');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    } finally {
        removeScratchDir($scratch);
    }
}

/**
 * A command's JSON, decoded — after proving it succeeded cleanly.
 *
 * @param  list<string>  $arguments
 * @param  array<string, array<string, mixed>|string>  $documents
 * @return array<string, mixed>
 */
function mailInboundJson(array $arguments, array $documents = []): array
{
    $run = mailInboundRun($arguments, $documents);

    expect($run['status'])->toBe(0, $run['stderr']);
    expect($run['stderr'])->toBe('');

    return json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The verdict for one recipient: exit 0 and accept, or exit 2 and reject —
 * always one JSON verdict on stdout, never anything on stderr.
 *
 * @param  array<string, array<string, mixed>|string>  $documents
 * @return array<string, mixed>
 */
function mailInboundRoute(string $recipient, array $documents = []): array
{
    $run = mailInboundRun(['route', '--recipient', $recipient], $documents);

    expect($run['stderr'])->toBe('', "route printed diagnostics for {$recipient}");

    $verdict = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);

    expect($run['status'])->toBe($verdict['verdict'] === 'accept' ? 0 : 2, "exit status does not match the verdict for {$recipient}");
    expect(array_keys($verdict))->toBe(['address', 'destination', 'handler', 'identifier', 'public_smtp', 'reason', 'recipient', 'rejection', 'target', 'verdict']);

    return $verdict;
}

/**
 * A refusal: exit 1, nothing on stdout, and this reason on stderr.
 *
 * @param  array{status: int, stdout: string, stderr: string}  $run
 */
function expectMailInboundRefusal(array $run, string $reason): void
{
    expect($run['status'])->toBe(1, "expected a refusal for: {$reason}\nstdout:\n{$run['stdout']}");
    expect($run['stdout'])->toBe('', 'a refused run must print nothing on stdout');
    expect(str_contains($run['stderr'], $reason))
        ->toBeTrue("refusal reason not found:\n  {$reason}\nstderr was:\n{$run['stderr']}");
}

/**
 * The target's entry in a rendered inbound plan.
 *
 * @return array<string, mixed>
 */
function mailInboundPlanTarget(array $plan, string $target): array
{
    $matches = array_values(array_filter($plan['targets'], static fn (array $entry): bool => $entry['target'] === $target));

    expect(count($matches))->toBe(1, "expected exactly one plan entry for {$target}");

    return $matches[0];
}

/** The script's executable lines, comments dropped, with the usage text removed. */
function mailInboundCode(): string
{
    $source = (string) preg_replace("/<<'USAGE'\n.*?\nUSAGE\n/s", "\n", File::get(mailInboundScript()));

    return executableSourceLines($source);
}

// =============================================================================
// THE COMMITTED CONTRACT
// =============================================================================

it('validates the committed contract against the committed routing policy, registry and outbound identity', function () {
    $run = mailInboundRun(['validate']);

    expect($run['status'])->toBe(0, $run['stderr']);
    expect($run['stderr'])->toBe('');
    expect($run['stdout'])->toContain('inbound mail contract is valid: ');
});

it('renders tits-guru receiving support, bounce and reply mail at the domains its routing policy reviews, with public SMTP disabled', function () {
    $plan = mailInboundJson(['render-plan']);

    expect(array_keys($plan))->toBe(['receiver', 'schema_version', 'targets']);
    expect($plan['schema_version'])->toBe(1);
    expect($plan['receiver']['public_smtp'])->toBe('disabled');
    expect(array_column($plan['targets'], 'target'))->toBe(['tits-guru']);

    expect(mailInboundPlanTarget($plan, 'tits-guru'))->toBe([
        'destinations' => [
            [
                'accepts' => 'exact-addresses',
                'addresses' => ['postmaster@bounce.tx.tits.guru', 'postmaster@reply.tits.guru', 'postmaster@tits.guru', 'support@tits.guru'],
                'destination' => 'support',
                'domain' => 'tits.guru',
                'handler' => ['kind' => 'support-mailbox', 'status' => 'planned'],
            ],
            [
                'accepts' => 'identifier-addresses',
                'address_space' => ['identifier' => 'base32-128', 'local_part_prefix' => 'b-'],
                'destination' => 'bounce',
                'domain' => 'bounce.tx.tits.guru',
                'handler' => ['kind' => 'bounce-correlation', 'status' => 'planned'],
                'identifies' => 'outbound-message',
            ],
            [
                'accepts' => 'identifier-addresses',
                'address_space' => ['identifier' => 'base32-128', 'local_part_prefix' => 'r-'],
                'destination' => 'reply',
                'domain' => 'reply.tits.guru',
                'handler' => ['kind' => 'reply-routing', 'status' => 'planned'],
                'identifies' => 'conversation',
            ],
        ],
        'domains' => ['bounce.tx.tits.guru', 'reply.tits.guru', 'tits.guru'],
        'environment_class' => 'production',
        'lifecycle' => 'planned',
        'mx_hostname' => 'mx1.tits.guru',
        'target' => 'tits-guru',
    ]);
});

it('reads every domain from the mail routing plan, and restates none of them', function () {
    $routing = mailRoutingListener(mailRoutingPlan(), 'tits-guru')['sender'];
    $committed = File::get(base_path('infrastructure/config/mail-inbound.json'));

    // The contract holds the receiver's own name and nothing mail-routing owns.
    foreach ([$routing['bounce_domain'], $routing['reply_domain'], $routing['default_from'], '"tits.guru"'] as $owned) {
        expect($committed)->not->toContain($owned);
    }

    expect(array_keys(mailInboundContract()['targets']['tits-guru']))->toEqualCanonicalizing(['mx_hostname', 'support', 'bounce', 'reply']);

    // A change of the routing policy is a change of the plan: the domains are
    // read, never copied.
    $policy = mailRoutingPolicy();
    $policy['targets']['tits-guru']['reply_domain'] = 'answers.tits.guru';

    $plan = mailInboundJson(['render-plan'], ['routing' => $policy]);

    expect(mailInboundPlanTarget($plan, 'tits-guru')['destinations'][2]['domain'])->toBe('answers.tits.guru');
    expect(mailInboundJson(['render-dns', '--target', 'tits-guru'], ['routing' => $policy])['records'][2]['name'])->toBe('answers.tits.guru');
});

it('refuses a contract that restates or replaces a domain mail-routing owns', function (string $property, mixed $value) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(["targets.tits-guru.{$property}" => $value])]),
        "tits-guru may not declare \"{$property}\": domains are read from mail-routing.json, their one authority",
    );
})->with([
    'the mail domain' => ['mail_domain', 'tits.guru'],
    'the bounce domain' => ['bounce_domain', 'bounce.tx.tits.guru'],
    'the reply domain' => ['reply_domain', 'reply.tits.guru'],
    'a foreign domain' => ['domain', 'gmail.com'],
    'a list of domains' => ['domains', ['tits.guru', 'evil.example']],
]);

it('keeps public inbound SMTP disabled, the one state there is while no receiver exists', function (mixed $state) {
    expect(mailInboundContract()['receiver'])->toBe(['public_smtp' => 'disabled']);

    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['receiver.public_smtp' => $state])]),
        'receiver.public_smtp must be disabled, got '.json_encode($state).': no public receiver exists, and public SMTP is enabled only by the change that installs, verifies and activates its own isolated receiver',
    );
})->with([
    'enabled' => ['enabled'],
    'true' => [true],
    'on' => ['on'],
    'listening' => ['listening'],
]);

// =============================================================================
// GENERIC: A SECOND BRAND, NO CODE CHANGE
// =============================================================================

it('receives for a second production target with its own domains, prefixes and mailboxes, with no change to the code', function () {
    $documents = mailInboundDemoShop(inbound: mailInboundDemoShopPolicy());

    $plan = mailInboundJson(['render-plan'], $documents);

    expect(array_column($plan['targets'], 'target'))->toBe(['demo-shop', 'tits-guru']);
    expect(mailInboundPlanTarget($plan, 'demo-shop'))->toBe([
        'destinations' => [
            [
                'accepts' => 'exact-addresses',
                'addresses' => ['help@demo-shop.example', 'postmaster@bounce.demo-shop.example', 'postmaster@demo-shop.example', 'postmaster@reply.demo-shop.example', 'press@demo-shop.example'],
                'destination' => 'support',
                'domain' => 'demo-shop.example',
                'handler' => ['kind' => 'support-mailbox', 'status' => 'planned'],
            ],
            [
                'accepts' => 'identifier-addresses',
                'address_space' => ['identifier' => 'base32-128', 'local_part_prefix' => 'dsn-'],
                'destination' => 'bounce',
                'domain' => 'bounce.demo-shop.example',
                'handler' => ['kind' => 'bounce-correlation', 'status' => 'planned'],
                'identifies' => 'outbound-message',
            ],
            [
                'accepts' => 'identifier-addresses',
                'address_space' => ['identifier' => 'base32-128', 'local_part_prefix' => 'ans-'],
                'destination' => 'reply',
                'domain' => 'reply.demo-shop.example',
                'handler' => ['kind' => 'reply-routing', 'status' => 'planned'],
                'identifies' => 'conversation',
            ],
        ],
        'domains' => ['bounce.demo-shop.example', 'demo-shop.example', 'reply.demo-shop.example'],
        'environment_class' => 'production',
        'lifecycle' => 'planned',
        'mx_hostname' => 'in.demo-shop.example',
        'target' => 'demo-shop',
    ]);

    // tits-guru's entry is exactly what it is without the second brand.
    expect(mailInboundPlanTarget($plan, 'tits-guru'))->toBe(mailInboundPlanTarget(mailInboundJson(['render-plan']), 'tits-guru'));

    // Each brand's addresses reach that brand alone.
    $id = MAIL_INBOUND_IDENTIFIER;

    expect(mailInboundRoute('press@demo-shop.example', $documents))->toMatchArray(['verdict' => 'accept', 'target' => 'demo-shop', 'destination' => 'support']);
    expect(mailInboundRoute("dsn-{$id}@bounce.demo-shop.example", $documents))->toMatchArray(['verdict' => 'accept', 'target' => 'demo-shop', 'destination' => 'bounce', 'identifier' => $id]);
    expect(mailInboundRoute("ans-{$id}@reply.demo-shop.example", $documents))->toMatchArray(['verdict' => 'accept', 'target' => 'demo-shop', 'destination' => 'reply', 'identifier' => $id]);
    expect(mailInboundRoute('support@tits.guru', $documents))->toMatchArray(['verdict' => 'accept', 'target' => 'tits-guru']);

    // Neither brand's address forms are the other's.
    expect(mailInboundRoute('support@demo-shop.example', $documents))->toMatchArray(['verdict' => 'reject', 'rejection' => 'unknown-recipient', 'target' => 'demo-shop']);
    expect(mailInboundRoute("b-{$id}@bounce.demo-shop.example", $documents))->toMatchArray(['verdict' => 'reject', 'rejection' => 'unknown-recipient']);
    expect(mailInboundRoute("dsn-{$id}@bounce.tx.tits.guru", $documents))->toMatchArray(['verdict' => 'reject', 'rejection' => 'unknown-recipient', 'target' => 'tits-guru']);

    expect(mailInboundJson(['render-dns', '--target', 'demo-shop'], $documents)['records'])->toBe([
        ['name' => 'demo-shop.example', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'support', 'type' => 'MX', 'value' => 'in.demo-shop.example'],
        ['name' => 'bounce.demo-shop.example', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'bounce', 'type' => 'MX', 'value' => 'in.demo-shop.example'],
        ['name' => 'reply.demo-shop.example', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'reply', 'type' => 'MX', 'value' => 'in.demo-shop.example'],
        ['name' => 'in.demo-shop.example', 'priority' => null, 'publish' => 'before-mx', 'serves' => 'receiver', 'type' => 'A', 'value' => null],
    ]);
});

it('receives for a production target whether its outbound mail is held or delivered', function () {
    $held = mailInboundDemoShop(inbound: mailInboundDemoShopPolicy());
    $outbound = mailInboundDemoShop(['delivery_mode' => 'outbound', 'outbound' => ['kind' => 'direct']], mailInboundDemoShopPolicy());

    // An outbound target needs its signing identity; tits-guru's, renamed.
    $identity = json_decode(File::get(base_path('infrastructure/config/mail-identity.json')), true, 512, JSON_THROW_ON_ERROR);
    $identity['targets']['demo-shop'] = $identity['targets']['tits-guru'];
    $outbound['identity'] = $identity;

    expect(mailInboundPlanTarget(mailInboundJson(['render-plan'], $held), 'demo-shop'))
        ->toBe(mailInboundPlanTarget(mailInboundJson(['render-plan'], $outbound), 'demo-shop'));
});

it('names no target, domain, host name or address anywhere in its implementation', function () {
    $source = File::get(mailInboundScript());
    $code = executableSourceLines($source);

    // Everything target-specific, read from the data rather than listed here,
    // so a target added later is covered without touching this test.
    $names = ['tits', 'mx1', 'mta1', 'demo-shop', '213.199.41.241'];

    foreach (mailRoutingPlan()['listeners'] as $listener) {
        $names[] = $listener['identity'];

        foreach ($listener['sender'] as $value) {
            $names[] = $value;
        }
    }

    foreach (mailInboundContract()['targets'] as $id => $policy) {
        $names[] = $id;
        $names[] = $policy['mx_hostname'];
    }

    foreach (array_unique($names) as $name) {
        expect(str_contains($source, $name))
            ->toBeFalse("mail-inbound names {$name}: inbound mail must come from the contract and the routing plan, never from a list in the code");
    }

    // Nothing from the contract or the recipient reaches a shell: neither is
    // ever sourced or evaluated, and no jq program is assembled from a value.
    expect($code)
        ->not->toContain('eval ')
        ->not->toContain('source ')
        ->not->toContain('bash -c');

    foreach (preg_split('/\R/', $code) as $line) {
        if (preg_match('/\bjq\b/', $line)) {
            $line = (string) preg_replace('/--arg [a-z_]+ "\$\{[A-Z_]+\}"/', '', $line);
            expect(preg_match('/\$\{?(TARGET_ID|RECIPIENT|IPV4_ARG)\b/', $line))
                ->toBe(0, "a value reaches jq other than as --arg data: {$line}");
        }
    }
});

// =============================================================================
// FOREIGN NAMES AND CONFLICTING OWNERS
// =============================================================================

it('refuses an MX host outside the target own mail domain', function (string $mx) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.mx_hostname' => $mx])]),
        "tits-guru: mx_hostname \"{$mx}\" is not inside its own mail domain \"tits.guru\"",
    );
})->with([
    'another brand' => ['in.demo-shop.example'],
    'a mailbox provider' => ['mx1.gmail.com'],
    'a look-alike suffix' => ['mx1.tits.guru.evil.example'],
    'a domain merely ending the same way' => ['mx1.nottits.guru'],
    'the mail domain itself' => ['tits.guru'],
]);

it('refuses an MX host name that is not a host name', function (mixed $mx) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.mx_hostname' => $mx])]),
        'tits-guru: mx_hostname must be a lowercase fully qualified host name, got '.json_encode($mx, JSON_UNESCAPED_SLASHES),
    );
})->with([
    'uppercase' => ['MX1.tits.guru'],
    'a trailing dot' => ['mx1.tits.guru.'],
    'a wildcard' => ['*.tits.guru'],
    'an address' => ['203.0.113.25'],
    'a mail address' => ['mx1@tits.guru'],
    'empty' => [''],
    'not a string' => [['mx1.tits.guru']],
]);

it('refuses one name claimed by two owners: two targets, a mail destination, or the outbound MTA identity', function (array $routing, array $inbound, string $reason) {
    $documents = mailInboundDemoShop($routing, $inbound['demo-shop'] ?? null);

    if (isset($inbound['tits-guru'])) {
        $documents['inbound']['targets']['tits-guru']['mx_hostname'] = $inbound['tits-guru'];
    }

    expectMailInboundRefusal(mailInboundRun(['validate'], $documents), $reason);
})->with([
    'an MX host that is the other target mail domain' => [
        mailInboundNestedDemoShopRouting(),
        ['tits-guru' => 'shop.tits.guru'],
        'shop.tits.guru is claimed by demo-shop mail domain and tits-guru mx_hostname',
    ],
    'an MX host that is the other target reply domain' => [
        mailInboundNestedDemoShopRouting(),
        ['tits-guru' => 'reply.shop.tits.guru'],
        'reply.shop.tits.guru is claimed by demo-shop reply domain and tits-guru mx_hostname',
    ],
    'one MX host claimed by both targets' => [
        mailInboundNestedDemoShopRouting(),
        ['tits-guru' => 'mx1.shop.tits.guru', 'demo-shop' => [...mailInboundDemoShopPolicy(), 'mx_hostname' => 'mx1.shop.tits.guru']],
        'mx1.shop.tits.guru is claimed by demo-shop mx_hostname and tits-guru mx_hostname',
    ],
    'an MX host that is its own bounce domain' => [
        [],
        ['tits-guru' => 'bounce.tx.tits.guru'],
        'bounce.tx.tits.guru is claimed by tits-guru bounce domain and tits-guru mx_hostname',
    ],
    'an MX host that is its own reply domain' => [
        [],
        ['tits-guru' => 'reply.tits.guru'],
        'reply.tits.guru is claimed by tits-guru mx_hostname and tits-guru reply domain',
    ],
    'an MX host that is the outbound MTA identity' => [
        [],
        ['tits-guru' => 'mta1.tits.guru'],
        'mta1.tits.guru is claimed by the outbound MTA hostname in mail-outbound.json and tits-guru mx_hostname',
    ],
]);

it('refuses a mail domain two targets claim, through the routing policy that owns it', function () {
    $documents = mailInboundDemoShop(['bounce_domain' => 'bounce.tx.tits.guru'], mailInboundDemoShopPolicy());

    $run = mailInboundRun(['validate'], $documents);

    expectMailInboundRefusal($run, 'mail-routing render-plan refused the routing policy');
    expect($run['stderr'])->toContain('mail identity domain bounce.tx.tits.guru is claimed more than once');
});

// =============================================================================
// STAGING IS NEVER A PUBLIC MAIL DESTINATION
// =============================================================================

it('never lets a staging target receive public mail', function () {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.staging-main' => mailInboundDemoShopPolicy()])]),
        'staging-main: environment_class staging never receives public mail: a staging target captures its mail on the host, and staging is never a public mail destination',
    );

    // A staging brand of its own is refused the same way, by its class.
    $policy = mailRoutingPolicy();
    $policy['targets']['demo-shop'] = [
        'submission' => ['host' => '127.0.0.1', 'port' => 2599],
        'delivery_mode' => 'capture',
        'allowed_from_domain' => 'demo-shop.invalid',
        'capture' => ['host' => '127.0.0.1', 'port' => 1025],
    ];

    expectMailInboundRefusal(
        mailInboundRun(['validate'], [
            'inbound' => mailInboundContractWith(['targets.demo-shop' => mailInboundDemoShopPolicy()]),
            'routing' => $policy,
            'registry' => mailRoutingDemoShopRegistry(['environment_class' => 'staging']),
        ]),
        'demo-shop: environment_class staging never receives public mail',
    );

    // And there is no inbound DNS for one.
    expectMailInboundRefusal(
        mailInboundRun(['render-dns', '--target', 'staging-main']),
        'staging-main is a staging target: it never receives public mail, so it has no inbound DNS',
    );

    // No staging domain is anywhere in the plan.
    expect(json_encode(mailInboundJson(['render-plan'])))->not->toContain('staging');
});

it('refuses an inbound policy for a target the registry does not have', function () {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-gurus' => mailInboundDemoShopPolicy()])]),
        'tits-gurus: not a target in the deployment registry',
    );

    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.Tits_Guru' => mailInboundDemoShopPolicy()])]),
        'invalid target ID "Tits_Guru"',
    );
});

// =============================================================================
// SUPPORT: EXACT ADDRESSES ONLY
// =============================================================================

it('accepts support mail at exactly the addresses the contract lists, compared as a receiver compares them', function (string $recipient, string $address) {
    expect(mailInboundRoute($recipient))->toBe([
        'address' => $address,
        'destination' => 'support',
        'handler' => ['kind' => 'support-mailbox', 'status' => 'planned'],
        'identifier' => null,
        'public_smtp' => 'disabled',
        'reason' => null,
        'recipient' => $recipient,
        'rejection' => null,
        'target' => 'tits-guru',
        'verdict' => 'accept',
    ]);
})->with([
    'as listed' => ['support@tits.guru', 'support@tits.guru'],
    'in capitals' => ['SUPPORT@TITS.GURU', 'support@tits.guru'],
    'with a capitalized domain' => ['support@Tits.Guru', 'support@tits.guru'],
    'the postmaster' => ['postmaster@tits.guru', 'postmaster@tits.guru'],
    'the postmaster in capitals' => ['POSTMASTER@TITS.GURU', 'postmaster@tits.guru'],
    'the postmaster in mixed case' => ['PostMaster@tits.guru', 'postmaster@tits.guru'],
    'the postmaster of the bounce domain' => ['postmaster@bounce.tx.tits.guru', 'postmaster@bounce.tx.tits.guru'],
    'the postmaster of the reply domain' => ['Postmaster@Reply.Tits.Guru', 'postmaster@reply.tits.guru'],
]);

it('requires postmaster in every support list, as RFC 5321 requires it at every domain a server receives for', function () {
    expect(mailInboundContract()['targets']['tits-guru']['support']['local_parts'])->toContain('postmaster');

    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.support.local_parts' => ['support']])]),
        'tits-guru: support.local_parts must include "postmaster": RFC 5321 section 4.5.1 requires every domain a server receives mail for to accept postmaster@tits.guru',
    );

    // A second brand is held to the same rule.
    expectMailInboundRefusal(
        mailInboundRun(['validate'], mailInboundDemoShop(inbound: [...mailInboundDemoShopPolicy(), 'support' => ['local_parts' => ['help']]])),
        'demo-shop: support.local_parts must include "postmaster"',
    );
});

it('never turns an unknown address at the mail domain into support', function (string $recipient) {
    expect(mailInboundRoute($recipient))->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'unknown-recipient',
        'target' => 'tits-guru',
        'destination' => 'support',
        'handler' => null,
        'reason' => mb_strtolower($recipient).' is not a support address of tits-guru, which receives at tits.guru only postmaster@tits.guru, support@tits.guru',
    ]);
})->with([
    'the no-reply sender' => ['noreply@tits.guru'],
    'any other mailbox' => ['anything@tits.guru'],
    'a subaddress of support' => ['support+urgent@tits.guru'],
    'a dotted variant' => ['support.team@tits.guru'],
    'a look-alike' => ['supp0rt@tits.guru'],
    'a quoted support' => ['"support"@tits.guru'],
    'a subaddress of the postmaster' => ['postmaster+abuse@tits.guru'],
    'a look-alike of the postmaster' => ['post.master@tits.guru'],
    'the hostmaster, not listed' => ['hostmaster@tits.guru'],
    'abuse, not listed' => ['abuse@tits.guru'],
    'a bounce address at the mail domain' => ['b-'.MAIL_INBOUND_IDENTIFIER.'@tits.guru'],
]);

it('never lets support be reached at a bounce or reply domain', function (string $recipient, string $destination) {
    expect(mailInboundRoute($recipient))->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'unknown-recipient',
        'destination' => $destination,
    ]);
})->with([
    'the bounce domain' => ['support@bounce.tx.tits.guru', 'bounce'],
    'the reply domain' => ['support@reply.tits.guru', 'reply'],
]);

it('refuses a wildcard, an address or anything but an exact lowercase local part as a support mailbox', function (mixed $localPart, string $reason) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.support.local_parts' => ['postmaster', 'support', $localPart]])]),
        $reason,
    );
})->with([
    'a star' => ['*', 'tits-guru: support local part "*" is a wildcard: support is exact addresses only, never a catch-all'],
    'a trailing star' => ['support*', 'tits-guru: support local part "support*" is a wildcard'],
    'a percent' => ['%', 'tits-guru: support local part "%" is a wildcard'],
    'empty' => ['', 'tits-guru: support local part "" is a wildcard'],
    'an external address' => ['ops@gmail.com', 'tits-guru: support local part "ops@gmail.com" is an address: support lists local parts at tits.guru and never names a mailbox anywhere else'],
    'a domain part alone' => ['@tits.guru', 'tits-guru: support local part "@tits.guru" is an address'],
    'capitals' => ['Help', 'tits-guru: support local part "Help" is not a lowercase local part'],
    'a subaddress' => ['help+x', 'tits-guru: support local part "help+x" is not a lowercase local part'],
    'a double dot' => ['a..b', 'tits-guru: support local part "a..b" is not a lowercase local part'],
    'a space' => ['help desk', 'tits-guru: support local part "help desk" is not a lowercase local part'],
    'too long' => [str_repeat('a', 65), 'is not a lowercase local part'],
    'not a string' => [7, 'tits-guru: a support local part must be a string, got 7'],
    'a duplicate' => ['support', 'tits-guru: support local part "support" is listed more than once'],
]);

it('never makes the reviewed no-reply sender a mailbox', function () {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.support.local_parts' => ['postmaster', 'support', 'noreply']])]),
        'tits-guru: noreply@tits.guru is the reviewed sender (default_from), a no-reply identity that is never a mailbox; answers reach the reply domain',
    );
});

it('refuses support with no mailbox, or support as anything but a list of local parts', function (mixed $support, string $reason) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.support' => $support])]),
        $reason,
    );
})->with([
    'an empty list' => [['local_parts' => []], 'tits-guru: support.local_parts must be a non-empty list of local parts, got []'],
    'a string' => [['local_parts' => 'support'], 'tits-guru: support.local_parts must be a non-empty list of local parts, got "support"'],
    'a bare list' => [['support'], 'tits-guru: support must be an object {local_parts}, got ["support"]'],
    'no local parts' => [['addresses' => ['support']], 'tits-guru support must declare local_parts'],
]);

it('has no catch-all to configure, at any level', function (string $path) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith([$path => true])]),
        'there is no catch-all; an address this contract does not name is refused',
    );
})->with([
    'a target catch-all' => ['targets.tits-guru.catch_all'],
    'a support wildcard' => ['targets.tits-guru.support.wildcard'],
    'a support default' => ['targets.tits-guru.support.default'],
    'a receiver luser relay' => ['receiver.luser_relay'],
]);

// =============================================================================
// BOUNCE AND REPLY: SEPARATE ADDRESS SPACES, WELL-FORMED IDENTIFIERS
// =============================================================================

it('accepts bounce and reply mail only in their own address spaces, each naming its identifier', function (string $recipient, string $destination, string $handler) {
    expect(mailInboundRoute($recipient))->toMatchArray([
        'verdict' => 'accept',
        'target' => 'tits-guru',
        'destination' => $destination,
        'identifier' => MAIL_INBOUND_IDENTIFIER,
        'handler' => ['kind' => $handler, 'status' => 'planned'],
        'address' => mb_strtolower($recipient),
    ]);
})->with([
    'a bounce' => ['b-'.MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru', 'bounce', 'bounce-correlation'],
    'a reply' => ['r-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru', 'reply', 'reply-routing'],
    'a bounce in capitals' => [mb_strtoupper('b-'.MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru'), 'bounce', 'bounce-correlation'],
]);

it('never mixes bounce and reply addresses, in either direction', function (string $recipient, string $reason) {
    expect(mailInboundRoute($recipient))->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'unknown-recipient',
        'identifier' => null,
        'reason' => $reason,
    ]);
})->with([
    'a bounce address at the reply domain' => [
        'b-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru',
        'b-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru is a bounce address at the reply domain of tits-guru: reply and bounce never accept each other addresses',
    ],
    'a reply address at the bounce domain' => [
        'r-'.MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru',
        'r-'.MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru is a reply address at the bounce domain of tits-guru: bounce and reply never accept each other addresses',
    ],
    'a bare identifier at the bounce domain' => [
        MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru',
        MAIL_INBOUND_IDENTIFIER.'@bounce.tx.tits.guru is not a bounce address of tits-guru: bounce.tx.tits.guru receives only b-<identifier>',
    ],
    'another prefix at the reply domain' => [
        'rx-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru',
        'rx-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru is not a reply address of tits-guru: reply.tits.guru receives only r-<identifier>',
    ],
]);

it('refuses bounce and reply sharing one prefix', function () {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['targets.tits-guru.reply.prefix' => 'b'])]),
        'tits-guru: bounce and reply share the prefix "b": a bounce address must never also read as a reply address, whichever domain it arrives at',
    );
});

it('refuses an invalid or ambiguous identifier', function (string $identifier, string $problem) {
    $recipient = "b-{$identifier}@bounce.tx.tits.guru";

    $verdict = mailInboundRoute($recipient);

    expect($verdict)->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'invalid-identifier',
        'destination' => 'bounce',
        'identifier' => null,
    ]);
    expect($verdict['reason'])->toStartWith('the bounce identifier of '.mb_strtolower($recipient).' '.$problem);
})->with([
    'an i, read as 1' => ['01hzx3k9q2w8e7r6t5y4v3p2mi', 'holds i, l, o or u, which base32-128 refuses because each reads as another character: one address must never name two identifiers'],
    'an l, read as 1' => ['01hzx3k9q2w8e7r6t5y4v3p2ml', 'holds i, l, o or u'],
    'an o, read as 0' => ['o1hzx3k9q2w8e7r6t5y4v3p2m1', 'holds i, l, o or u'],
    'a u' => ['01hzx3k9q2w8e7r6t5y4u3p2m1', 'holds i, l, o or u'],
    'a capital I' => ['01HZX3K9Q2W8E7R6T5Y4V3P2MI', 'holds i, l, o or u'],
    'too short' => ['01hzx3k9q2w8e7r6t5y4v3p2m', 'is 25 characters long, not exactly 26'],
    'too long' => ['01hzx3k9q2w8e7r6t5y4v3p2m12', 'is 27 characters long, not exactly 26'],
    'empty' => ['', 'is 0 characters long, not exactly 26'],
    'beyond 128 bits' => ['81hzx3k9q2w8e7r6t5y4v3p2m1', 'starts above 7, beyond 128 bits'],
    'a subaddress' => [MAIL_INBOUND_IDENTIFIER.'+x', 'holds characters outside the lowercase base32 alphabet'],
    'a second separator' => ['01hzx3k9q2w8e7r6t5y4v-p2m1', 'holds characters outside the lowercase base32 alphabet'],
    'a dot' => ['01hzx3k9q2w8e7r6t5y4v.p2m1', 'holds characters outside the lowercase base32 alphabet'],
]);

it('refuses an address space with a malformed prefix or an identifier format it does not know', function (string $path, mixed $value, string $reason) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith([$path => $value])]),
        $reason,
    );
})->with([
    'a capital prefix' => ['targets.tits-guru.bounce.prefix', 'B', 'tits-guru: bounce.prefix must be a lowercase letter followed by at most seven lowercase letters or digits, got "B"'],
    'a prefix holding the separator' => ['targets.tits-guru.bounce.prefix', 'b-', 'tits-guru: bounce.prefix must be a lowercase letter'],
    'an empty prefix' => ['targets.tits-guru.reply.prefix', '', 'tits-guru: reply.prefix must be a lowercase letter'],
    'a prefix starting with a digit' => ['targets.tits-guru.reply.prefix', '1r', 'tits-guru: reply.prefix must be a lowercase letter'],
    'a long prefix' => ['targets.tits-guru.reply.prefix', 'replyaddr', 'tits-guru: reply.prefix must be a lowercase letter'],
    'a ULID' => ['targets.tits-guru.bounce.identifier', 'ulid', 'tits-guru: bounce.identifier must be one of base32-128, got "ulid"'],
    'any local part' => ['targets.tits-guru.reply.identifier', 'any', 'tits-guru: reply.identifier must be one of base32-128, got "any"'],
    'not an object' => ['targets.tits-guru.bounce', 'b-<identifier>', 'tits-guru: bounce must be an object {prefix, identifier}, got "b-<identifier>"'],
]);

it('refuses a malformed recipient before it asks which destination it is for', function (string $recipient, string $reason) {
    $verdict = mailInboundRoute($recipient);

    expect($verdict)->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'malformed-address',
        'target' => null,
        'destination' => null,
        'address' => null,
    ]);
    expect($verdict['reason'])->toStartWith($reason);
})->with([
    'no @' => ['support', 'an address holds exactly one @'],
    'two @' => ['support@tits.guru@gmail.com', 'an address holds exactly one @'],
    'an empty local part' => ['@tits.guru', 'a local part is 1 to 64 characters'],
    'a long local part' => [str_repeat('a', 65).'@tits.guru', 'a local part is 1 to 64 characters'],
    'a trailing dot' => ['support@tits.guru.', '"tits.guru." is not a domain name: address literals and trailing dots are not accepted'],
    'an address literal' => ['support@[203.0.113.25]', '"[203.0.113.25]" is not a domain name'],
    'a space' => ['support @tits.guru', 'an address is printable ASCII with no space or control character'],
    'a newline' => ["support@tits.guru\n", 'an address is printable ASCII with no space or control character'],
    'a non-ASCII letter' => ['suppоrt@tits.guru', 'an address is printable ASCII with no space or control character'],
    'too long' => ['a@'.str_repeat('a', 250).'.guru', 'an address is at most 254 characters'],
]);

// =============================================================================
// NO FORWARDING, NO RELAY
// =============================================================================

it('refuses every recipient at a domain no target receives at, as relaying', function (string $recipient) {
    expect(mailInboundRoute($recipient))->toMatchArray([
        'verdict' => 'reject',
        'rejection' => 'relay-denied',
        'target' => null,
        'reason' => 'no target receives mail at '.explode('@', mb_strtolower($recipient))[1].': the receiver never relays',
    ]);
})->with([
    'a mailbox provider' => ['someone@gmail.com'],
    'the staging identity' => ['noreply@staging.invalid'],
    'the MX host itself' => ['postmaster@mx1.tits.guru'],
    'the outbound MTA' => ['root@mta1.tits.guru'],
    'a subdomain of the mail domain' => ['support@help.tits.guru'],
    'a parent of the bounce domain' => ['b-'.MAIL_INBOUND_IDENTIFIER.'@tx.tits.guru'],
]);

it('has no forwarding, relay or authentication setting to configure, at any level', function (string $path, string $reason) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith([$path => 'ops@gmail.com'])]),
        $reason,
    );
})->with([
    'forwarding support' => ['targets.tits-guru.support.forward_to', 'tits-guru support may not declare "forward_to": received mail is never forwarded or relayed off the host'],
    'aliases' => ['targets.tits-guru.aliases', 'tits-guru may not declare "aliases": received mail is never forwarded'],
    'a reply redirect' => ['targets.tits-guru.reply.redirect', 'tits-guru reply may not declare "redirect": received mail is never forwarded'],
    'a bounce transport' => ['targets.tits-guru.bounce.transport', 'tits-guru bounce may not declare "transport": received mail is never forwarded'],
    'a relay host' => ['receiver.relayhost', 'receiver may not declare "relayhost": received mail is never forwarded'],
    'trusted networks' => ['receiver.mynetworks', 'receiver may not declare "mynetworks": the public receiver never relays and never authenticates a client, so nothing can make it an open relay'],
    'relay domains' => ['receiver.relay_domains', 'receiver may not declare "relay_domains": the public receiver never relays'],
    'an open relay switch' => ['targets.tits-guru.open_relay', 'tits-guru may not declare "open_relay": the public receiver never relays'],
    'SMTP AUTH' => ['receiver.smtp_auth', 'the inbound contract holds a secret-like property name "smtp_auth"'],
    'a top-level relay' => ['relay', 'the inbound contract may not declare "relay": the public receiver never relays'],
]);

// =============================================================================
// DNS
// =============================================================================

it('plans an MX for the mail domain and both subdomains, and the A record of the MX host', function () {
    $dns = mailInboundJson(['render-dns', '--target', 'tits-guru']);

    expect($dns)->toBe([
        'mx_hostname' => 'mx1.tits.guru',
        'outbound_identity' => ['mta_hostname' => 'mta1.tits.guru', 'records' => 'unchanged'],
        'public_smtp' => 'disabled',
        'receiver_ipv4' => ['address' => null, 'status' => 'not-provided'],
        'records' => [
            ['name' => 'tits.guru', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'support', 'type' => 'MX', 'value' => 'mx1.tits.guru'],
            ['name' => 'bounce.tx.tits.guru', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'bounce', 'type' => 'MX', 'value' => 'mx1.tits.guru'],
            ['name' => 'reply.tits.guru', 'priority' => 10, 'publish' => 'after-receiver-activation', 'serves' => 'reply', 'type' => 'MX', 'value' => 'mx1.tits.guru'],
            ['name' => 'mx1.tits.guru', 'priority' => null, 'publish' => 'before-mx', 'serves' => 'receiver', 'type' => 'A', 'value' => null],
        ],
        'schema_version' => 1,
        'target' => 'tits-guru',
    ]);

    // The address is the one given, judged public — never one committed.
    $given = mailInboundJson(['render-dns', '--target', 'tits-guru', '--ipv4', '203.0.113.25']);

    expect($given['receiver_ipv4'])->toBe(['address' => '203.0.113.25', 'status' => 'provided']);
    expect($given['records'][3])->toMatchArray(['name' => 'mx1.tits.guru', 'type' => 'A', 'value' => '203.0.113.25']);
    expect(array_slice($given['records'], 0, 3))->toBe(array_slice($dns['records'], 0, 3));
});

it('never touches the outbound identity: no record for the MTA hostname, and no PTR, SPF, DKIM or DMARC', function () {
    $mta = json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true, 512, JSON_THROW_ON_ERROR)['direct']['mta_hostname'];
    $dns = mailInboundJson(['render-dns', '--target', 'tits-guru', '--ipv4', '203.0.113.25']);

    expect($mta)->toBe('mta1.tits.guru');
    expect(array_values(array_unique(array_column($dns['records'], 'type'))))->toBe(['MX', 'A']);

    foreach ($dns['records'] as $record) {
        expect($record['name'])->not->toBe($mta);
        expect($record['value'])->not->toBe($mta);
        expect($record['name'])->not->toStartWith('_dmarc.')->not->toContain('_domainkey');
    }

    expect($dns['outbound_identity'])->toBe(['mta_hostname' => $mta, 'records' => 'unchanged']);

    // The MX host can never be that name, so no inbound A record can rewrite it.
    expectMailInboundRefusal(
        mailInboundRun(['render-dns', '--target', 'tits-guru'], ['inbound' => mailInboundContractWith(['targets.tits-guru.mx_hostname' => $mta])]),
        "{$mta} is claimed by the outbound MTA hostname in mail-outbound.json and tits-guru mx_hostname",
    );
});

it('takes the receiver address only as one public IPv4 address', function (string $address) {
    expectMailInboundRefusal(
        mailInboundRun(['render-dns', '--target', 'tits-guru', '--ipv4', $address]),
        "--ipv4 must be a public IPv4 address, got: {$address}",
    );
})->with([
    'private' => ['10.0.0.7'],
    'private 172.16/12' => ['172.20.0.1'],
    'private 192.168/16' => ['192.168.1.10'],
    'loopback' => ['127.0.0.1'],
    'shared address space' => ['100.64.0.1'],
    'link-local' => ['169.254.10.10'],
    'unspecified' => ['0.0.0.0'],
    'multicast' => ['224.0.0.1'],
    'an octet out of range' => ['256.1.1.1'],
    'a leading zero' => ['203.0.113.025'],
    'three octets' => ['203.0.113'],
    'a host name' => ['mx1.tits.guru'],
    'IPv6' => ['2001:db8::25'],
]);

it('judges a public IPv4 address exactly as mail-identity judges the address a host sends from', function () {
    $inbound = File::get(mailInboundScript());
    $identity = File::get(mailIdentityScript());

    foreach (['is_ipv4', 'is_public_ipv4'] as $function) {
        expect(shellFunctionBody($inbound, $function))
            ->not->toBe('')
            ->toBe(shellFunctionBody($identity, $function), "{$function} differs between mail-inbound and mail-identity");
    }
});

it('renders inbound DNS only for a production target with an inbound policy', function () {
    expectMailInboundRefusal(mailInboundRun(['render-dns', '--target', 'demo-shop']), 'unknown target: demo-shop — not in the deployment registry');

    expectMailInboundRefusal(
        mailInboundRun(['render-dns', '--target', 'demo-shop'], mailInboundDemoShop()),
        'demo-shop receives no inbound mail: it has no policy in ',
    );
});

// =============================================================================
// DETERMINISTIC
// =============================================================================

it('renders the same plan, DNS and verdicts byte for byte, however the documents are ordered', function () {
    $commands = [
        ['render-plan'],
        ['render-dns', '--target', 'tits-guru', '--ipv4', '203.0.113.25'],
        ['route', '--recipient', 'r-'.MAIL_INBOUND_IDENTIFIER.'@reply.tits.guru'],
        ['route', '--recipient', 'someone@gmail.com'],
    ];

    // Reverse the order of every object's keys, and drop all whitespace.
    $reverse = function (mixed $node) use (&$reverse): mixed {
        if (! is_array($node) || array_is_list($node)) {
            return $node;
        }

        return array_map($reverse, array_reverse($node, true));
    };

    $reordered = mailInboundDemoShop(inbound: mailInboundDemoShopPolicy());
    $documents = $reordered;

    foreach ($reordered as $name => $document) {
        $reordered[$name] = json_encode($reverse($document), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    foreach ($commands as $arguments) {
        $first = mailInboundRun($arguments, $documents);
        $second = mailInboundRun($arguments, $documents);

        expect($first['stdout'])->not->toBe('');
        expect($second['stdout'])->toBe($first['stdout']);
        expect(mailInboundRun($arguments, $reordered)['stdout'])->toBe($first['stdout']);
    }
});

it('renders no command, no path and no secret: only names, addresses and closed vocabulary', function () {
    $vocabulary = [
        'schema_version', 'receiver', 'public_smtp', 'requirements', 'targets', 'target', 'environment_class', 'lifecycle',
        'mx_hostname', 'domains', 'destinations', 'destination', 'domain', 'handler', 'kind', 'status', 'accepts',
        'addresses', 'address_space', 'local_part_prefix', 'identifier', 'identifies',
    ];

    $plans = [
        mailInboundJson(['render-plan']),
        mailInboundJson(['render-plan'], mailInboundDemoShop(inbound: mailInboundDemoShopPolicy())),
    ];

    foreach ($plans as $plan) {
        $walk = function (array $node) use (&$walk, $vocabulary): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    expect(in_array($key, $vocabulary, true))->toBeTrue("the plan has an unexpected property: {$key}");
                }

                if (is_array($value)) {
                    $walk($value);
                } else {
                    expect(is_string($value) || is_int($value))->toBeTrue('the plan holds an unexpected value type');
                    // No whitespace, no slash, no quote, no shell metacharacter.
                    expect(preg_match('/\A[a-z0-9][a-z0-9@._-]*\z/', (string) $value))
                        ->toBe(1, "the plan holds a value that is not plain data: {$value}");
                }
            }
        };

        $walk($plan);
    }
});

// =============================================================================
// SHAPE
// =============================================================================

it('refuses a document that is not one well-formed contract', function (string $document, string $reason) {
    expectMailInboundRefusal(mailInboundRun(['validate'], ['inbound' => $document]), $reason);
})->with([
    'not JSON' => ['{"schema_version": 1,', 'the inbound mail contract is not valid JSON'],
    'two documents' => ['{"schema_version": 1} {"schema_version": 1}', 'the inbound mail contract must hold exactly one JSON document, found 2'],
    'an array' => ['[]', 'the inbound mail contract must be a JSON object'],
    'a duplicated key' => [
        '{"schema_version": 1, "receiver": {"public_smtp": "disabled"}, "targets": {}, "targets": {}}',
        'the inbound mail contract declares the same key twice in one object',
    ],
    'a control character' => [
        '{"schema_version": 1, "receiver": {"public_smtp": "disabled\n"}, "targets": {}}',
        'the inbound mail contract must not contain control characters in any key or value',
    ],
    'the next schema' => ['{"schema_version": 2, "receiver": {"public_smtp": "disabled"}, "targets": {}}', 'unsupported inbound mail schema_version: 2 (expected 1)'],
    'a schema written as a string' => ['{"schema_version": "1", "receiver": {"public_smtp": "disabled"}, "targets": {}}', 'unsupported inbound mail schema_version: "1" (expected 1)'],
    'no schema' => ['{"receiver": {"public_smtp": "disabled"}, "targets": {}}', 'unsupported inbound mail schema_version: null (expected 1)'],
]);

it('refuses an unknown or missing property at every level', function (array $set, array $forget, string $reason) {
    expectMailInboundRefusal(mailInboundRun(['validate'], ['inbound' => mailInboundContractWith($set, $forget)]), $reason);
})->with([
    'top level' => [['comment' => 'x'], [], 'the inbound contract has an unexpected property "comment"'],
    'the receiver' => [['receiver.listen' => '0.0.0.0:25'], [], 'receiver has an unexpected property "listen"'],
    'a target' => [['targets.tits-guru.priority' => 10], [], 'tits-guru has an unexpected property "priority"'],
    'support' => [['targets.tits-guru.support.mailbox' => 'support'], [], 'tits-guru support has an unexpected property "mailbox"'],
    'bounce' => [['targets.tits-guru.bounce.length' => 26], [], 'tits-guru bounce has an unexpected property "length"'],
    'no receiver' => [[], ['receiver'], 'the inbound contract must declare receiver'],
    'no public SMTP state' => [['receiver' => new stdClass], [], 'receiver must declare public_smtp'],
    'no targets' => [[], ['targets'], 'the inbound contract must declare targets'],
    'no MX host' => [[], ['targets.tits-guru.mx_hostname'], 'tits-guru must declare mx_hostname'],
    'no reply' => [[], ['targets.tits-guru.reply'], 'tits-guru must declare reply'],
    'no identifier' => [[], ['targets.tits-guru.bounce.identifier'], 'tits-guru bounce must declare identifier'],
    'targets as a list' => [['targets' => ['tits-guru']], [], 'targets must be an object of inbound policies by target ID, got ["tits-guru"]'],
    'a policy as a string' => [['targets.tits-guru' => 'mx1.tits.guru'], [], 'tits-guru: an inbound policy must be an object {mx_hostname, support, bounce, reply}, got "mx1.tits.guru"'],
]);

it('refuses a secret-like property name, however deep', function (string $path, string $name) {
    expectMailInboundRefusal(
        mailInboundRun(['validate'], ['inbound' => mailInboundContractWith([$path => 'x'])]),
        "the inbound contract holds a secret-like property name \"{$name}\": a credential never belongs in committed mail policy",
    );
})->with([
    'a receiver password' => ['receiver.password', 'password'],
    'a support API token' => ['targets.tits-guru.support.api_token', 'api_token'],
    'a bounce signing secret' => ['targets.tits-guru.bounce.signing_secret', 'signing_secret'],
    'a SASL username' => ['receiver.sasl_username', 'sasl_username'],
]);

it('lets a host receive for no target at all, and then renders no target', function () {
    $plan = mailInboundJson(['render-plan'], ['inbound' => mailInboundContractWith(['targets' => new stdClass])]);

    expect($plan['targets'])->toBe([]);
    expect($plan['receiver']['public_smtp'])->toBe('disabled');
});

it('judges the contract only against a routing policy, a registry and an outbound identity their own judges accept', function (string $document, mixed $value, string $reason) {
    $documents = match ($document) {
        'routing' => ['routing' => mailRoutingPolicyWith(['targets.tits-guru.default_from' => $value])],
        'registry' => ['registry' => mailRoutingDemoShopRegistry(['environment_class' => $value])],
        'outbound' => ['outbound' => ['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => $value]]],
    };

    $run = mailInboundRun(['render-plan'], $documents);

    expectMailInboundRefusal($run, $reason);
})->with([
    'an invalid routing policy' => ['routing', 'noreply@gmail.com', 'mail-routing render-plan refused the routing policy'],
    'an invalid registry' => ['registry', 'qa', 'mail-routing render-plan refused the routing policy'],
    'an invalid outbound identity' => ['outbound', 'mta1.tits.invalid', 'mail-identity validate refused the host mail identity'],
]);

it('refuses a contract reached through a symlink', function () {
    $scratch = makeScratchDir('mail-inbound-symlink');

    try {
        file_put_contents($scratch.'/real.json', mailRoutingJson(mailInboundContract()));
        symlink($scratch.'/real.json', $scratch.'/mail-inbound.json');

        expectMailInboundRefusal(
            mailInboundRun(['validate', '--inbound', $scratch.'/mail-inbound.json']),
            'the inbound mail contract must not be a symlink',
        );
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// THE CLI ITSELF
// =============================================================================

it('handles its arguments strictly', function (array $arguments, int $status, string $expected) {
    $run = mailInboundRun($arguments);

    expect($run['status'])->toBe($status);
    expect(str_contains($run['stdout'].$run['stderr'], $expected))
        ->toBeTrue("expected '{$expected}' in:\n{$run['stdout']}{$run['stderr']}");
})->with([
    'no command' => [[], 1, 'Usage:'],
    'an unknown command' => [['activate'], 1, 'ERROR: unknown command: activate'],
    'an unknown argument' => [['validate', '--listen', '0.0.0.0:25'], 1, 'ERROR: unknown argument: --listen'],
    'a target where none is read' => [['validate', '--target', 'tits-guru'], 1, 'ERROR: --target is not an option of validate'],
    'an address where none is read' => [['render-plan', '--ipv4', '203.0.113.25'], 1, 'ERROR: --ipv4 is not an option of render-plan'],
    'a recipient where none is read' => [['render-dns', '--target', 'tits-guru', '--recipient', 'support@tits.guru'], 1, 'ERROR: --recipient is not an option of render-dns'],
    'render-dns without a target' => [['render-dns'], 1, 'ERROR: render-dns requires --target'],
    'an invalid target ID' => [['render-dns', '--target', '../tits-guru'], 1, 'ERROR: invalid target ID: ../tits-guru'],
    'route without a recipient' => [['route'], 1, 'ERROR: route requires --recipient'],
    'an empty recipient' => [['route', '--recipient', ''], 1, 'ERROR: --recipient requires a non-empty value'],
    'a repeated target' => [['render-dns', '--target', 'tits-guru', '--target', 'tits-guru'], 1, 'ERROR: --target given more than once'],
    'a file flag with no value' => [['validate', '--inbound'], 1, 'ERROR: --inbound requires a value'],
    'a missing contract' => [['validate', '--inbound', '/nonexistent/mail-inbound.json'], 1, 'the inbound mail contract is unavailable'],
    'help' => [['--help'], 0, 'mail-inbound route       --recipient ADDRESS [FILES]'],
]);

// =============================================================================
// NOTHING IS INSTALLED, OPENED OR SENT
// =============================================================================

it('changes nothing on disk when it validates, renders or routes', function () {
    $scratch = makeScratchDir('mail-inbound-readonly');

    try {
        $repo = provisionRepo($scratch, mailRoutingJson(perimeterRegistry()));
        $script = $repo.'/infrastructure/scripts/mail-inbound';

        $snapshot = function () use ($scratch): array {
            $files = [];

            foreach (File::allFiles($scratch, true) as $file) {
                $files[$file->getPathname()] = [hash_file('sha256', $file->getPathname()), $file->getMTime(), $file->getPerms()];
            }

            ksort($files);

            return $files;
        };

        $before = $snapshot();

        expect(mailInboundRun(['validate'], [], $script)['status'])->toBe(0);
        expect(mailInboundRun(['render-plan'], [], $script)['status'])->toBe(0);
        expect(mailInboundRun(['render-dns', '--target', 'tits-guru', '--ipv4', '203.0.113.25'], [], $script)['status'])->toBe(0);
        expect(mailInboundRun(['route', '--recipient', 'support@tits.guru'], [], $script)['status'])->toBe(0);
        expect(mailInboundRun(['route', '--recipient', 'someone@gmail.com'], [], $script)['status'])->toBe(2);
        expect(mailInboundRun(['validate'], ['inbound' => mailInboundContractWith(['receiver.public_smtp' => 'enabled'])], $script)['status'])->toBe(1);

        expect($snapshot())->toBe($before);
    } finally {
        removeScratchDir($scratch);
    }
});

it('installs nothing, starts nothing, opens no port and asks no DNS', function () {
    $code = mailInboundCode();

    foreach ([
        'systemctl', 'service', 'postfix', 'postconf', 'postmap', 'postsuper', 'postmulti', 'sendmail', 'swaks',
        'curl', 'wget', 'ssh', 'scp', 'rsync', 'install', 'mkdir', 'mktemp', 'cp', 'mv', 'rm', 'tee', 'touch',
        'chmod', 'chown', 'sudo', 'nc', 'ncat', 'socat', 'ss', 'ip', 'ufw', 'iptables', 'nft', 'dig', 'nslookup',
        'openssl', 'gh',
    ] as $command) {
        expect(preg_match('/(?<![\w.-])'.preg_quote($command, '/').'(?![\w.-])/', $code))
            ->toBe(0, "mail-inbound runs {$command}");
    }

    // Its only programs are jq and the two judges it composes, from beside itself.
    expect($code)
        ->toContain('"${MAIL_ROUTING_CLI}" render-plan')
        ->toContain('"${MAIL_IDENTITY_CLI}" validate')
        ->toContain('MAIL_ROUTING_CLI="${SCRIPT_DIR}/mail-routing"')
        ->toContain('MAIL_IDENTITY_CLI="${SCRIPT_DIR}/mail-identity"');

    // Output is only ever redirected away: no file is written.
    preg_match_all('/(?:^|[\s\d])>{1,2}\s*(["\/$][^\s;|&)]*)/m', $code, $matches);

    expect(array_values(array_unique($matches[1])))->toBe(['/dev/null']);

    // The committed contract receives nothing: public SMTP is disabled.
    expect(mailInboundContract()['receiver']['public_smtp'])->toBe('disabled');
});

it('carries every requirement the future receiver is held to, and the runbook states each one', function () {
    $requirements = mailInboundJson(['render-plan'])['receiver']['requirements'];

    expect($requirements)->toBe([
        'recipient-allowlist',
        'postmaster-accepted',
        'relay-refused-at-rcpt',
        'no-smtp-auth',
        'no-relay-no-forwarding',
        'message-size-limit',
        'recipient-limit',
        'connection-limits',
        'queue-limits',
        'isolated-queue',
        'no-outbound-submission',
        'no-message-content-in-logs',
        'content-never-executed',
        'untrusted-sender-fields',
        'no-automatic-replies',
        'null-sender-accepted',
        'malformed-and-duplicate-safe',
        'disk-exhaustion-guard',
        'single-public-port-owner',
        'no-shared-mail-state',
    ]);

    $runbook = File::get(base_path('infrastructure/runbooks/mail-inbound.md'));
    $start = strpos($runbook, '## Receiver requirements');
    expect($start)->not->toBeFalse('the runbook lost its receiver requirements');

    $end = strpos($runbook, "\n## ", $start + 1);
    $section = substr($runbook, $start, $end === false ? null : $end - $start);

    preg_match_all('/^\| `([a-z0-9-]+)` \|/m', $section, $documented);

    expect($documented[1])->toBe($requirements);
});

it('is repository tooling that no host, workflow, action or installer reaches', function () {
    expect(repositoryOnlyScriptNames())->toContain('mail-inbound');
    expect(requiredCliManifestNames())->not->toContain('mail-inbound');

    foreach (operationalFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if ($relative === 'infrastructure/scripts/mail-inbound') {
            continue;
        }

        expect(executableSourceLines(File::get($path)))
            ->not->toContain('mail-inbound', "{$relative} reaches the inbound mail contract — nothing installs or verifies an inbound receiver yet");
    }
});

it('leaves the public SMTP port checks of the gateway and the outbound activation exactly as strict', function () {
    // Every public SMTP port stays forbidden to everything on the host: the
    // receiver's own port gets a named owner when the receiver exists, never a
    // general exception.
    foreach (['install-mail-gateway', 'activate-mail-outbound'] as $script) {
        expect(executableSourceLines(File::get(base_path("infrastructure/scripts/{$script}"))))
            ->toContain('PUBLIC_SMTP_PORTS=(25 465 587)');
    }

    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/install-mail-gateway'))))
        ->toContain("printf 'something listens on %s — no SMTP service may listen on port %s\\n'");
});

// =============================================================================
// THE RECORD
// =============================================================================

it('documents the order: the receiver first, MX records only once it is active', function () {
    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-inbound.md')));

    expect($runbook)
        ->toContain('**No MX record is published before the receiver is installed, verified and activated.**')
        ->toContain('| Public inbound SMTP | **Disabled**')
        ->toContain('| MX records for `tits.guru`, `bounce.tx.tits.guru`, `reply.tits.guru` | **Not published**')
        ->toContain('The envelope sender of every message sent today is `noreply@tits.guru`')
        ->toContain('`verify-mail-gateway` is never weakened to allow port 25 in general');

    $steps = [
        '1. **The inbound contract**',
        '2. **An isolated receiver, proved locally**',
        '3. **Guarded activation of public SMTP**',
        '4. **MX records**',
        '5. **An external test message to `support@tits.guru`**',
        '6. **Bounce reception and correlation**',
        '7. **Replies**',
        '8. **Storage and processing**',
        '9. **Security and recovery**',
    ];
    $position = -1;

    foreach ($steps as $step) {
        $next = strpos($runbook, $step, $position + 1);
        expect($next)->not->toBeFalse("the sequence does not say: {$step}");
        expect($next)->toBeGreaterThan($position, "the sequence says \"{$step}\" out of order");
        $position = $next;
    }
});

it('records the outbound activation as accepted in the roadmap, and inbound mail as started, not working', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4B.4.2 Production outbound activation — PRODUCTION-ACCEPTED 2026-10-08.**')
        ->toContain('Activate tits.guru outbound mail run `37813433328` SUCCESS')
        ->toContain('Verify production infrastructure run `37814215899` SUCCESS, `OUTBOUND READY: YES`')
        ->toContain('run `37814715904`')
        ->toContain('run `37821815403` SUCCESS')
        ->toContain('run `37822226157` SUCCESS')
        ->toContain('Verify staging infrastructure run `37823079457` SUCCESS: 6 PASS, 0 FAIL, 0 DEFERRED, 1 N/A')
        ->toContain('**8.4B.5 Bounce reception, reply routing and the support mailbox — current.**')
        ->toContain('**8.4B.5.1 Inbound contract and DNS plan — IMPLEMENTED, nothing installed.**')
        ->toContain('**8.4B.5.2 Isolated inbound SMTP receiver and guarded activation — planned.**')
        ->toContain('**8.4B.5.3 Bounce reception and correlation — planned.**')
        ->toContain('**8.4B.5.4 Reply routing and the support mailbox — planned.**')
        ->toContain('**8.4B.5.5 Real-host acceptance and recovery proof — planned.**')
        ->toContain('Inbound mail is not working until a message from outside has actually been received.')
        ->toContain('[`runbooks/mail-inbound.md`](runbooks/mail-inbound.md)')
        ->not->toContain('production activation pending')
        ->not->toContain('host activation pending')
        ->not->toContain('Inbound mail — ACCEPTED');
});
