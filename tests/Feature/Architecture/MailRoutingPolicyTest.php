<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The reviewed mail routing policy, and the CLI that validates it and renders
 * the plan a local mail gateway would follow.
 *
 * Every target submits its mail to a gateway on a loopback port of its own, and
 * the gateway routes it by that target's delivery mode: `capture` into the
 * staging capture service, `held` — no route at all — or `outbound`, out of the
 * host by the transport kind its policy names (`direct` only). Only a
 * production target may be held or outbound, and the real production target is
 * still held: the outbound mode is proved here against a synthetic demo-shop.
 *
 * Every behavioural test here runs the shipped infrastructure/scripts/
 * mail-routing against fixture files. None restates a rule in PHP: a validator
 * reimplemented here would prove only that two copies agree.
 *
 * The tests at the end of this file hold the line around it: no public SMTP
 * listener, an untouched capture slice, and environment templates that describe
 * the endpoint a host actually has.
 */
/** @return array<string, mixed> */
function mailRoutingPolicy(): array
{
    return json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The committed policy — or BASE — with dot-path changes applied: `set`
 * replaces or adds a value, `forget` removes one.
 *
 * @param  array<string, mixed>  $set
 * @param  list<string>  $forget
 * @param  array<string, mixed>|null  $base
 * @return array<string, mixed>
 */
function mailRoutingPolicyWith(array $set = [], array $forget = [], ?array $base = null): array
{
    $policy = $base ?? mailRoutingPolicy();

    foreach ($set as $path => $value) {
        data_set($policy, $path, $value);
    }

    foreach ($forget as $path) {
        Arr::forget($policy, $path);
    }

    return $policy;
}

/**
 * The pre-activation policy — tits-guru held, with no outbound route — with
 * dot-path changes applied: what a held production target is judged on.
 *
 * @param  array<string, mixed>  $set
 * @param  list<string>  $forget
 * @return array<string, mixed>
 */
function mailRoutingHeldPolicyWith(array $set = [], array $forget = []): array
{
    return mailRoutingPolicyWith($set, $forget, mailPreActivationPolicy()['routing']);
}

/** @return array<string, mixed> */
function mailRoutingListener(array $plan, string $identity): array
{
    $matches = array_values(array_filter(
        $plan['listeners'],
        static fn (array $listener): bool => $listener['identity'] === $identity,
    ));

    expect(count($matches))->toBe(1, "expected exactly one listener for {$identity}");

    return $matches[0];
}

/**
 * A refusal: non-zero, nothing on stdout, and this reason on stderr.
 *
 * @param  array{status: int, stdout: string, stderr: string}  $run
 */
function expectMailRoutingRefusal(array $run, string $reason): void
{
    expect($run['status'])->not->toBe(0, "expected a refusal for: {$reason}");
    expect($run['stdout'])->toBe('', 'a refused run must print nothing on stdout');
    expect(str_contains($run['stderr'], $reason))
        ->toBeTrue("refusal reason not found:\n  {$reason}\nstderr was:\n{$run['stderr']}");
}

/**
 * Every `listen` port an Nginx file declares.
 *
 * @return list<string>
 */
function mailRoutingNginxListenPorts(string $source): array
{
    preg_match_all('/^\s*listen\s+(?:\[::\]:)?(\d+)\b/m', executableSourceLines($source), $matches);

    return $matches[1];
}

// =============================================================================
// THE COMMITTED POLICY
// =============================================================================

it('validates the committed policy against the committed registry', function () {
    $run = mailRoutingRun(['validate']);

    expect($run['status'])->toBe(0, $run['stderr']);
    expect($run['stderr'])->toBe('');
    expect($run['stdout'])->toContain('mail routing policy is valid: ');
});

it('renders one loopback listener per registry target, in target order', function () {
    $plan = mailRoutingPlan();

    expect(array_keys($plan))->toBe(['listeners', 'schema_version']);
    expect($plan['schema_version'])->toBe(2);

    $registryTargets = array_keys(perimeterRegistry()['targets']);
    sort($registryTargets);

    expect(array_column($plan['listeners'], 'identity'))->toBe($registryTargets);
});

it('routes staging-main from its own gateway port into the existing capture', function () {
    expect(mailRoutingListener(mailRoutingPlan(), 'staging-main'))->toBe([
        'delivery_mode' => 'capture',
        'environment_class' => 'staging',
        'identity' => 'staging-main',
        'lifecycle' => 'active',
        'listen' => ['host' => '127.0.0.1', 'port' => 2525],
        'route' => ['host' => '127.0.0.1', 'kind' => 'capture', 'port' => 1025],
        'sender' => ['allowed_domain' => 'staging.invalid'],
    ]);
});

it('routes tits-guru from its own gateway port by direct outbound delivery, still planned', function () {
    $listener = mailRoutingListener(mailRoutingPlan(), 'tits-guru');

    expect($listener)->toBe([
        'delivery_mode' => 'outbound',
        'environment_class' => 'production',
        'identity' => 'tits-guru',
        'lifecycle' => 'planned',
        'listen' => ['host' => '127.0.0.1', 'port' => 2526],
        'route' => ['kind' => 'direct'],
        'sender' => [
            'allowed_domain' => 'tits.guru',
            'bounce_domain' => 'bounce.tx.tits.guru',
            'default_from' => 'noreply@tits.guru',
            'reply_domain' => 'reply.tits.guru',
        ],
    ]);

    // The listener it accepts mail on is the only endpoint anywhere in it:
    // the route names a kind, not a capture, relay or smart host to send to.
    $endpoints = array_values(array_filter(
        array_keys(Arr::dot($listener)),
        static fn (string $key): bool => str_ends_with($key, '.host') || str_ends_with($key, '.port'),
    ));

    expect($endpoints)->toBe(['listen.host', 'listen.port']);
});

it('holds the pre-activation tits-guru on the same gateway port with no delivery destination', function () {
    $listener = mailRoutingListener(mailRoutingPlan(mailPreActivationPolicy()['routing']), 'tits-guru');

    expect($listener)->toBe([
        'delivery_mode' => 'held',
        'environment_class' => 'production',
        'identity' => 'tits-guru',
        'lifecycle' => 'planned',
        'listen' => ['host' => '127.0.0.1', 'port' => 2526],
        'route' => null,
        'sender' => [
            'allowed_domain' => 'tits.guru',
            'bounce_domain' => 'bounce.tx.tits.guru',
            'default_from' => 'noreply@tits.guru',
            'reply_domain' => 'reply.tits.guru',
        ],
    ]);

    // Held: nothing to capture into, nothing to relay to, nothing to deliver.
    $endpoints = array_values(array_filter(
        array_keys(Arr::dot($listener)),
        static fn (string $key): bool => str_ends_with($key, '.host') || str_ends_with($key, '.port'),
    ));

    expect($endpoints)->toBe(['listen.host', 'listen.port']);
});

it('holds only the properties the schema declares, and no secret', function () {
    $allowed = [
        'schema_version', 'targets',
        'submission', 'host', 'port', 'delivery_mode',
        'allowed_from_domain', 'capture',
        'mail_domain', 'default_from', 'bounce_domain', 'reply_domain',
        'outbound', 'kind',
    ];

    $policy = mailRoutingPolicy();
    $targets = array_keys($policy['targets']);

    // Every key at every depth is a target ID or a schema property — read from
    // the committed file directly, independently of the validator.
    $walk = function (array $node) use (&$walk, $allowed, $targets): void {
        foreach ($node as $key => $value) {
            expect(in_array($key, [...$allowed, ...$targets], true))
                ->toBeTrue("unexpected property in the committed mail routing policy: {$key}");

            if (is_array($value)) {
                $walk($value);
            }
        }
    };

    $walk($policy);

    expect(File::get(base_path('infrastructure/config/mail-routing.json')))
        ->not->toMatch('/password|passwd|secret|token|credential|api_?key|private_?key|sasl|auth|username/i');
});

it('leaves lifecycle and environment class to the registry', function () {
    $raw = File::get(base_path('infrastructure/config/mail-routing.json'));

    expect($raw)
        ->not->toContain('lifecycle')
        ->not->toContain('environment_class')
        ->not->toContain('"production"')
        ->not->toContain('"staging"');

    // Repeating either one in the policy is refused, not tolerated as a copy —
    // in the committed outbound policy and in a held one alike.
    foreach (['lifecycle' => 'planned', 'environment_class' => 'production'] as $property => $value) {
        expectMailRoutingRefusal(
            mailRoutingRun(['validate'], mailRoutingPolicyWith(["targets.tits-guru.{$property}" => $value])),
            "tits-guru: unexpected property \"{$property}\" in an outbound policy",
        );
        expectMailRoutingRefusal(
            mailRoutingRun(['validate'], mailRoutingHeldPolicyWith(["targets.tits-guru.{$property}" => $value])),
            "tits-guru: unexpected property \"{$property}\" in a held policy",
        );
    }

    // And the plan reports the registry's value, whatever it says: moving a
    // target's class in the registry is what moves its allowed mode.
    $registry = perimeterRegistry();
    data_set($registry, 'targets.tits-guru.environment_class', 'staging');

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], null, $registry),
        'tits-guru: environment_class staging allows delivery_mode capture, not outbound',
    );
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingHeldPolicyWith(), $registry),
        'tits-guru: environment_class staging allows delivery_mode capture, not held',
    );
});

// =============================================================================
// HELD: NO ROUTE, AND NEVER ACTIVE
// =============================================================================

it('never lets a production target render a capture or an Internet destination', function (array $set, string $reason) {
    $policy = mailRoutingHeldPolicyWith($set);

    expectMailRoutingRefusal(mailRoutingRun(['validate'], $policy), $reason);
    expectMailRoutingRefusal(mailRoutingRun(['render-plan'], $policy), $reason);
})->with([
    'a capture destination beside held' => [
        ['targets.tits-guru.capture' => ['host' => '127.0.0.1', 'port' => 1025]],
        'tits-guru: held mail has no delivery destination, so it may not declare "capture"',
    ],
    'an Internet relay beside held' => [
        ['targets.tits-guru.relay' => ['host' => 'smtp.example.com', 'port' => 587]],
        'tits-guru: held mail has no delivery destination, so it may not declare "relay"',
    ],
    'a relayhost beside held' => [
        ['targets.tits-guru.relayhost' => '[smtp.example.com]:587'],
        'tits-guru: held mail has no delivery destination, so it may not declare "relayhost"',
    ],
    'an outbound transport beside held' => [
        ['targets.tits-guru.outbound' => ['host' => 'smtp.example.com', 'port' => 465]],
        'tits-guru: held mail has no delivery destination, so it may not declare "outbound"',
    ],
    'a generic destination beside held' => [
        ['targets.tits-guru.destination' => ['host' => '203.0.113.25', 'port' => 25]],
        'tits-guru: held mail has no delivery destination, so it may not declare "destination"',
    ],
    'outbound with no transport named' => [
        ['targets.tits-guru.delivery_mode' => 'outbound'],
        'tits-guru: an outbound policy must declare outbound',
    ],
    'a relay delivery mode' => [
        ['targets.tits-guru.delivery_mode' => 'relay'],
        'tits-guru: delivery_mode must be one of capture, held, outbound, got "relay"',
    ],
    'a production target rewritten as capture into staging Mailpit' => [
        ['targets.tits-guru' => [
            'submission' => ['host' => '127.0.0.1', 'port' => 2526],
            'delivery_mode' => 'capture',
            'allowed_from_domain' => 'staging.invalid',
            'capture' => ['host' => '127.0.0.1', 'port' => 1025],
        ]],
        'tits-guru: environment_class production allows delivery_mode held or outbound, not capture',
    ],
]);

it('never lets a staging target declare production delivery', function (array $set, string $reason) {
    expectMailRoutingRefusal(mailRoutingRun(['validate'], mailRoutingPolicyWith($set)), $reason);
})->with([
    'held with a production identity' => [
        ['targets.staging-main' => [
            'submission' => ['host' => '127.0.0.1', 'port' => 2525],
            'delivery_mode' => 'held',
            'mail_domain' => 'staging.example.com',
            'default_from' => 'noreply@staging.example.com',
            'bounce_domain' => 'bounce.staging.example.com',
            'reply_domain' => 'reply.staging.example.com',
        ]],
        'staging-main: environment_class staging allows delivery_mode capture, not held',
    ],
    'a production mail domain beside capture' => [
        ['targets.staging-main.mail_domain' => 'staging.example.com'],
        'staging-main: unexpected property "mail_domain" in a capture policy',
    ],
    'a deliverable sender domain' => [
        ['targets.staging-main.allowed_from_domain' => 'tits.guru'],
        'staging-main: allowed_from_domain must be under the reserved .invalid TLD',
    ],
]);

it('refuses staging.invalid as a production identity', function (array $set, string $reason) {
    expectMailRoutingRefusal(mailRoutingRun(['validate'], mailRoutingPolicyWith($set)), $reason);
})->with([
    'as the mail domain' => [
        [
            'targets.tits-guru.mail_domain' => 'staging.invalid',
            'targets.tits-guru.default_from' => 'noreply@staging.invalid',
        ],
        'tits-guru: mail_domain "staging.invalid" is under the reserved .invalid TLD',
    ],
    'as the bounce domain' => [
        ['targets.tits-guru.bounce_domain' => 'bounce.staging.invalid'],
        'tits-guru: bounce_domain "bounce.staging.invalid" is under the reserved .invalid TLD',
    ],
    'as the reply domain' => [
        ['targets.tits-guru.reply_domain' => 'staging.invalid'],
        'tits-guru: reply_domain "staging.invalid" is under the reserved .invalid TLD',
    ],
]);

it('keeps tits-guru planned, and refuses any held target that is active', function () {
    $registry = perimeterRegistry();

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
    expect(mailRoutingPolicy()['targets']['tits-guru']['delivery_mode'])->toBe('outbound');

    // A held tits-guru made active is refused by mail routing itself — not
    // only by the registry's own active allowlist, which this scratch copy
    // widens so the mail rule is the one left to decide.
    data_set($registry, 'targets.tits-guru.lifecycle', 'active');

    $scratch = sys_get_temp_dir().'/mail-routing-active-'.bin2hex(random_bytes(6));
    @mkdir($scratch, 0o755, true);

    try {
        $repo = provisionRepo($scratch, mailRoutingJson($registry), widenActiveAllowlist: true);
        file_put_contents($repo.'/infrastructure/config/mail-routing.json', mailRoutingJson(mailPreActivationPolicy()['routing']));
        $script = $repo.'/infrastructure/scripts/mail-routing';

        expectMailRoutingRefusal(
            mailRoutingRun(['validate'], null, null, $script),
            'tits-guru: lifecycle=active with held mail — an active target must have a delivery route and held has none',
        );

        // The same scratch copy with tits-guru planned is valid, so the
        // lifecycle alone is what was refused.
        provisionRepo($scratch, mailRoutingJson(perimeterRegistry()), widenActiveAllowlist: true);

        expect(mailRoutingRun(['validate'], null, null, $script)['status'])->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

// =============================================================================
// OUTBOUND: DIRECT ONLY, PRODUCTION ONLY
// =============================================================================

it('refuses the committed policy written as the previous schema, rather than reinterpreting it', function () {
    expect(mailRoutingPolicy()['schema_version'])->toBe(2);

    // Exactly the policy that validates today, under the schema that knew only
    // capture and held: refused whole, by both commands.
    $previous = mailRoutingPolicyWith(['schema_version' => 1]);

    expectMailRoutingRefusal(mailRoutingRun(['validate'], $previous), 'unsupported mail routing schema_version: 1 (expected 2)');
    expectMailRoutingRefusal(mailRoutingRun(['render-plan'], $previous), 'unsupported mail routing schema_version: 1 (expected 2)');
});

it('validates and renders a production target delivered outbound by direct SMTP', function () {
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopOutboundPolicy()]);
    $registry = mailRoutingDemoShopRegistry();

    $run = mailRoutingRun(['validate'], $policy, $registry);
    expect($run['status'])->toBe(0, $run['stderr']);

    $plan = mailRoutingPlan($policy, $registry);

    // The same identity a held policy renders, and a route naming only its
    // kind: no host, port, relay, credential or MTA hostname — the physical
    // identity it delivers under is the host's, never the policy's.
    expect(mailRoutingListener($plan, 'demo-shop'))->toBe([
        'delivery_mode' => 'outbound',
        'environment_class' => 'production',
        'identity' => 'demo-shop',
        'lifecycle' => 'planned',
        'listen' => ['host' => '127.0.0.1', 'port' => 2599],
        'route' => ['kind' => 'direct'],
        'sender' => [
            'allowed_domain' => 'demo-shop.example',
            'bounce_domain' => 'bounce.demo-shop.example',
            'default_from' => 'hello@demo-shop.example',
            'reply_domain' => 'reply.demo-shop.example',
        ],
    ]);

    // The real targets render exactly as they do without it.
    $others = array_values(array_filter(
        $plan['listeners'],
        static fn (array $listener): bool => $listener['identity'] !== 'demo-shop',
    ));

    expect($others)->toBe(mailRoutingPlan()['listeners']);
});

it('routes only the real production target outbound in the real plan, by direct delivery and still planned', function () {
    expect(mailRoutingPolicy()['targets']['tits-guru']['outbound'])->toBe(['kind' => 'direct']);
    expect(mailRoutingListener(mailRoutingPlan(), 'tits-guru')['route'])->toBe(['kind' => 'direct']);
    expect(perimeterRegistry()['targets']['tits-guru']['lifecycle'])->toBe('planned');

    $outbound = array_values(array_filter(mailRoutingPlan()['listeners'], static fn (array $listener): bool => $listener['delivery_mode'] === 'outbound'));
    expect(array_column($outbound, 'identity'))->toBe(['tits-guru']);
    expect(array_column($outbound, 'environment_class'))->toBe(['production']);
});

it('never gives staging the production direct route', function () {
    $staging = mailRoutingListener(mailRoutingPlan(), 'staging-main');

    // Staging still captures into its own Mailpit, and nothing else.
    expect($staging['delivery_mode'])->toBe('capture');
    expect($staging['route'])->toBe(['host' => '127.0.0.1', 'kind' => 'capture', 'port' => 1025]);
    expect($staging['listen'])->toBe(['host' => '127.0.0.1', 'port' => 2525]);

    // Nor can it be given one: outbound is a production target's mode alone.
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main' => [
            'submission' => ['host' => '127.0.0.1', 'port' => 2525],
            'delivery_mode' => 'outbound',
            'outbound' => ['kind' => 'direct'],
            'mail_domain' => 'staging.example.com',
            'default_from' => 'noreply@staging.example.com',
            'bounce_domain' => 'bounce.staging.example.com',
            'reply_domain' => 'reply.staging.example.com',
        ]])),
        'staging-main: environment_class staging allows delivery_mode capture, not outbound',
    );
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.outbound' => ['kind' => 'direct']])),
        'staging-main: unexpected property "outbound" in a capture policy',
    );
});

it('lets an outbound target be active, and still refuses an active held one', function () {
    // The registry's own allowlist names one active target; this scratch copy
    // widens it, so the mail rule is the one left to decide.
    $scratch = sys_get_temp_dir().'/mail-routing-outbound-active-'.bin2hex(random_bytes(6));
    @mkdir($scratch, 0o755, true);

    try {
        $repo = provisionRepo($scratch, mailRoutingJson(mailRoutingDemoShopRegistry(['lifecycle' => 'active'])), widenActiveAllowlist: true);
        $script = $repo.'/infrastructure/scripts/mail-routing';

        $outbound = mailRoutingRun(['render-plan'], mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopOutboundPolicy()]), null, $script);
        expect($outbound['status'])->toBe(0, $outbound['stderr']);
        expect(mailRoutingListener(json_decode($outbound['stdout'], true), 'demo-shop'))
            ->toMatchArray(['lifecycle' => 'active', 'delivery_mode' => 'outbound', 'route' => ['kind' => 'direct']]);

        expectMailRoutingRefusal(
            mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]), null, $script),
            'demo-shop: lifecycle=active with held mail — an active target must have a delivery route and held has none; it stays inactive until its policy is outbound',
        );
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('never lets a staging target deliver outbound', function () {
    $outbound = mailRoutingDemoShopOutboundPolicy();
    $outbound['submission']['port'] = 2525;

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main' => $outbound])),
        'staging-main: environment_class staging allows delivery_mode capture, not outbound — a staging target never delivers',
    );

    // A staging target the code has never heard of, the same way.
    expectMailRoutingRefusal(
        mailRoutingRun(
            ['render-plan'],
            mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopOutboundPolicy()]),
            mailRoutingDemoShopRegistry(['environment_class' => 'staging']),
        ),
        'demo-shop: environment_class staging allows delivery_mode capture, not outbound',
    );
});

it('accepts direct as the one outbound kind, and nothing beside it', function (mixed $outbound, string $reason) {
    $policy = mailRoutingDemoShopOutboundPolicy();
    $policy['outbound'] = $outbound;

    $run = mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.demo-shop' => $policy]), mailRoutingDemoShopRegistry());

    expectMailRoutingRefusal($run, $reason);
})->with([
    'a relay kind, which is not implemented' => [['kind' => 'relay'], 'demo-shop: outbound.kind must be one of direct, got "relay"'],
    'a provider kind' => [['kind' => 'ses'], 'demo-shop: outbound.kind must be one of direct, got "ses"'],
    'a capitalised kind' => [['kind' => 'Direct'], 'demo-shop: outbound.kind must be one of direct, got "Direct"'],
    'an empty kind' => [['kind' => ''], 'demo-shop: outbound.kind must be one of direct, got ""'],
    'a null kind' => [['kind' => null], 'demo-shop: outbound.kind must be one of direct, got null'],
    'no kind at all' => [new stdClass, 'demo-shop: outbound must be exactly {kind}, found []'],
    'the kind as a bare string' => ['direct', 'demo-shop: outbound must be an object {kind}, got "direct"'],
    'a relay host beside the kind' => [['kind' => 'direct', 'relayhost' => '[smtp.example.com]:587'], 'demo-shop: outbound must be exactly {kind}, found ["kind","relayhost"]'],
    'an MTA hostname beside the kind' => [['kind' => 'direct', 'mta_hostname' => 'mta1.example.net'], 'demo-shop: outbound must be exactly {kind}, found ["kind","mta_hostname"]'],
    'a credential beside the kind' => [['kind' => 'direct', 'password' => 'hunter2'], 'the policy holds a secret-like property name "password"'],
]);

it('refuses any destination beside the outbound transport', function (string $property) {
    $policy = mailRoutingDemoShopOutboundPolicy();
    $policy[$property] = ['host' => 'smtp.example.com', 'port' => 587];

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.demo-shop' => $policy]), mailRoutingDemoShopRegistry()),
        "demo-shop: outbound mail leaves only through its own outbound transport, so it may not declare \"{$property}\" — the route is outbound.kind and nothing else",
    );
})->with(['relay', 'relayhost', 'smarthost', 'capture', 'transport', 'nexthop', 'destination', 'route']);

it('judges an outbound identity by exactly the rules a held one is judged by', function (array $identity) {
    $problems = [];

    foreach (['held' => mailRoutingDemoShopPolicy(), 'outbound' => mailRoutingDemoShopOutboundPolicy()] as $mode => $policy) {
        $run = mailRoutingRun(
            ['validate'],
            mailRoutingPolicyWith(['targets.demo-shop' => [...$policy, ...$identity]]),
            mailRoutingDemoShopRegistry(),
        );

        expect($run['status'])->not->toBe(0, "a {$mode} policy accepted the identity");
        $problems[$mode] = array_values(preg_grep('/^INVALID: /', preg_split('/\R/', $run['stderr'])));
    }

    expect($problems['held'])->not->toBe([]);
    expect($problems['outbound'])->toBe($problems['held']);
})->with([
    'a mail domain that is not a lowercase domain' => [['mail_domain' => 'Demo-Shop.example']],
    'a staging identity' => [[
        'mail_domain' => 'demo-shop.invalid',
        'default_from' => 'hello@demo-shop.invalid',
        'bounce_domain' => 'bounce.demo-shop.invalid',
        'reply_domain' => 'reply.demo-shop.invalid',
    ]],
    'a sender at another domain' => [['default_from' => 'hello@other.example']],
    'a sender that is not an address' => [['default_from' => 'hello demo-shop.example']],
    'a bounce domain outside the identity' => [['bounce_domain' => 'bounce.other.example']],
    'a reply domain that merely ends the same way' => [['reply_domain' => 'replydemo-shop.example']],
    'a bounce domain that is the mail domain' => [['bounce_domain' => 'demo-shop.example']],
]);

it('refuses a production identity claimed twice, across held and outbound targets alike', function () {
    $claim = [
        'mail_domain' => 'tits.guru',
        'default_from' => 'shop@tits.guru',
        'bounce_domain' => 'bounce.shop.tits.guru',
        'reply_domain' => 'reply.shop.tits.guru',
    ];

    // An outbound target taking the held target's domain.
    expectMailRoutingRefusal(
        mailRoutingRun(
            ['validate'],
            mailRoutingPolicyWith(['targets.demo-shop' => [...mailRoutingDemoShopOutboundPolicy(), ...$claim]]),
            mailRoutingDemoShopRegistry(),
        ),
        'mail identity domain tits.guru is claimed more than once: demo-shop mail_domain, tits-guru mail_domain',
    );

    // Two outbound targets sharing a reply domain.
    ['policy' => $policy, 'registry' => $registry] = mailRoutingTwoOutboundTargets();
    expect(mailRoutingRun(['validate'], $policy, $registry)['status'])->toBe(0);

    $policy['targets']['demo-books']['mail_domain'] = 'demo-shop.example';
    $policy['targets']['demo-books']['default_from'] = 'books@demo-shop.example';
    $policy['targets']['demo-books']['bounce_domain'] = 'bounce.books.demo-shop.example';

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], $policy, $registry),
        'mail identity domain demo-shop.example is claimed more than once: demo-books mail_domain, demo-shop mail_domain',
    );

    $policy['targets']['demo-books']['reply_domain'] = 'reply.demo-shop.example';

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], $policy, $registry),
        'mail identity domain reply.demo-shop.example is claimed more than once: demo-books reply_domain, demo-shop reply_domain',
    );
});

// =============================================================================
// ENDPOINTS
// =============================================================================

it('refuses two targets on one submission port', function () {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.tits-guru.submission.port' => 2525])),
        'submission port 2525 is claimed by more than one target: staging-main, tits-guru',
    );

    // A third target colliding with an existing one is caught the same way.
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]);
    data_set($policy, 'targets.demo-shop.submission.port', 2526);

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], $policy, mailRoutingDemoShopRegistry()),
        'submission port 2526 is claimed by more than one target: demo-shop, tits-guru',
    );
});

it('refuses a submission endpoint that is not exactly 127.0.0.1', function (mixed $host) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.submission.host' => $host])),
        'staging-main: submission.host must be exactly 127.0.0.1',
    );
})->with([
    'every IPv4 interface' => '0.0.0.0',
    'every IPv6 interface' => '::',
    'IPv6 loopback' => '::1',
    'a name' => 'localhost',
    'a routable address' => '192.0.2.10',
    'a public hostname' => 'mail.tits.guru',
    'another loopback address' => '127.0.0.2',
    'an address with its port' => '127.0.0.1:2525',
    'not a string' => 2130706433,
]);

it('refuses a submission port that is privileged, out of range or not an integer', function (mixed $port) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.submission.port' => $port])),
        'staging-main: submission.port must be an integer from 1024 to 65535',
    );
})->with([
    'the SMTP port' => 25,
    'the submission port' => 587,
    'the SMTPS port' => 465,
    'zero' => 0,
    'past the range' => 65536,
    'a string' => '2525',
    'a fraction' => 2525.5,
    'null' => null,
]);

it('refuses a capture destination that is not loopback', function (mixed $host) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.capture.host' => $host])),
        'staging-main: capture.host must be an IPv4 loopback address',
    );
})->with([
    'every IPv4 interface' => '0.0.0.0',
    'a private address' => '10.0.0.5',
    'a routable address' => '192.0.2.25',
    'IPv6 loopback' => '::1',
    'a name' => 'localhost',
    'the capture UI hostname' => 'mailpit.staging.myprojects.pp.ua',
    'a zero-padded loopback' => '127.000.0.1',
    'an out-of-range octet' => '127.0.0.256',
    'a short form' => '127.1',
]);

it('accepts a capture destination anywhere in the IPv4 loopback range', function () {
    expect(mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.capture.host' => '127.0.0.2']))['status'])
        ->toBe(0);
});

it('refuses a capture destination that is a gateway endpoint', function () {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.capture.port' => 2525])),
        'staging-main: capture destination 127.0.0.1:2525 is its own submission endpoint — mail would loop back into the gateway',
    );

    // Into another target's gateway, staging mail would arrive as that target.
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.staging-main.capture.port' => 2526])),
        'staging-main: capture destination 127.0.0.1:2526 is the submission endpoint of tits-guru — its mail would be resubmitted as tits-guru',
    );
});

// =============================================================================
// TARGETS: EXACTLY ONE POLICY EACH
// =============================================================================

it('refuses a policy for a target the registry does not have', function () {
    $food = mailRoutingDemoShopPolicy();
    $food['submission']['port'] = 2598;

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.food-guru' => $food])),
        'food-guru: not a target in the deployment registry',
    );

    // A misspelt key is both: a policy for nobody, and a real target without one.
    $run = mailRoutingRun(['validate'], mailRoutingPolicyWith(
        ['targets.titsguru' => mailRoutingPolicy()['targets']['tits-guru']],
        ['targets.tits-guru'],
    ));

    expectMailRoutingRefusal($run, 'titsguru: not a target in the deployment registry');
    expectMailRoutingRefusal($run, 'tits-guru: is in the deployment registry but has no mail routing policy');
});

it('refuses a registry target with no policy', function () {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith([], ['targets.tits-guru'])),
        'tits-guru: is in the deployment registry but has no mail routing policy',
    );

    // A target added to the registry needs its own reviewed policy too.
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicy(), mailRoutingDemoShopRegistry()),
        'demo-shop: is in the deployment registry but has no mail routing policy',
    );
});

it('judges the policy only against a registry the registry validator accepts', function () {
    $registry = perimeterRegistry();
    data_set($registry, 'targets.tits-guru.lifecycle', 'live');

    $run = mailRoutingRun(['validate'], null, $registry);

    expectMailRoutingRefusal($run, 'the deployment target registry is invalid, so no mail routing can be judged against it');
    // The registry's own diagnostics come through, from its own validator.
    expect($run['stderr'])->toContain("tits-guru: lifecycle must be active, planned or disabled, got 'live'");
});

// =============================================================================
// SHAPE
// =============================================================================

it('refuses a document that is not one well-formed policy object', function (string $document, string $reason) {
    expectMailRoutingRefusal(mailRoutingRun(['validate'], $document), $reason);
})->with([
    'not JSON' => ['{"schema_version": 1,', 'mail routing policy is not valid JSON'],
    'an empty file' => ['', 'mail routing policy must hold exactly one JSON document, found 0'],
    'two documents' => ['{"schema_version": 1, "targets": {}} {"schema_version": 1, "targets": {}}', 'must hold exactly one JSON document, found 2'],
    'an array' => ['[]', 'mail routing policy must be a JSON object'],
    'a target declared twice' => [
        '{"schema_version": 1, "targets": {"staging-main": {}, "staging-main": {}}}',
        'mail routing policy declares the same key twice in one object',
    ],
    'a property declared twice' => [
        '{"schema_version": 1, "schema_version": 1, "targets": {}}',
        'mail routing policy declares the same key twice in one object',
    ],
    'the previous schema version' => ['{"schema_version": 1, "targets": {}}', 'unsupported mail routing schema_version: 1 (expected 2)'],
    'a later schema version' => ['{"schema_version": 3, "targets": {}}', 'unsupported mail routing schema_version: 3 (expected 2)'],
    'schema version as a string' => ['{"schema_version": "2", "targets": {}}', 'unsupported mail routing schema_version: "2" (expected 2)'],
    'no schema version' => ['{"targets": {}}', 'unsupported mail routing schema_version: null (expected 2)'],
    'an extra top-level property' => [
        '{"schema_version": 2, "comment": "x", "targets": {"staging-main": {}}}',
        'mail routing policy must be exactly {schema_version, targets}, found ["comment","schema_version","targets"]',
    ],
    'targets as an array' => ['{"schema_version": 2, "targets": []}', 'mail routing policy targets must be a non-empty object'],
    'no targets at all' => ['{"schema_version": 2, "targets": {}}', 'mail routing policy targets must be a non-empty object'],
]);

it('refuses a policy whose shape is not exactly its mode\'s', function (array $set, array $forget, string $reason) {
    expectMailRoutingRefusal(mailRoutingRun(['validate'], mailRoutingHeldPolicyWith($set, $forget)), $reason);
})->with([
    'an extra property' => [['targets.staging-main.notes' => 'x'], [], 'staging-main: unexpected property "notes" in a capture policy'],
    'no capture destination' => [[], ['targets.staging-main.capture'], 'staging-main: a capture policy must declare capture'],
    'no sender domain' => [[], ['targets.staging-main.allowed_from_domain'], 'staging-main: a capture policy must declare allowed_from_domain'],
    'no submission endpoint' => [[], ['targets.tits-guru.submission'], 'tits-guru: a held policy must declare submission'],
    'no bounce domain' => [[], ['targets.tits-guru.bounce_domain'], 'tits-guru: a held policy must declare bounce_domain'],
    'no delivery mode' => [[], ['targets.tits-guru.delivery_mode'], 'tits-guru: delivery_mode must be one of capture, held, outbound, got null'],
    'an extra endpoint property' => [
        ['targets.staging-main.submission.tls' => true],
        [],
        'staging-main: submission must be exactly {host, port}, found ["host","port","tls"]',
    ],
    'an endpoint without its port' => [[], ['targets.staging-main.capture.port'], 'staging-main: capture must be exactly {host, port}, found ["host"]'],
    'an endpoint as a string' => [
        ['targets.staging-main.submission' => '127.0.0.1:2525'],
        [],
        'staging-main: submission must be an object {host, port}, got "127.0.0.1:2525"',
    ],
    'a policy that is not an object' => [['targets.tits-guru' => 'held'], [], 'tits-guru: policy must be an object, got "held"'],
]);

it('refuses a committed outbound policy whose shape is not exactly an outbound one', function (array $set, array $forget, string $reason) {
    expectMailRoutingRefusal(mailRoutingRun(['validate'], mailRoutingPolicyWith($set, $forget)), $reason);
})->with([
    'no submission endpoint' => [[], ['targets.tits-guru.submission'], 'tits-guru: an outbound policy must declare submission'],
    'no bounce domain' => [[], ['targets.tits-guru.bounce_domain'], 'tits-guru: an outbound policy must declare bounce_domain'],
    'no transport named' => [[], ['targets.tits-guru.outbound'], 'tits-guru: an outbound policy must declare outbound'],
    'a relay transport' => [['targets.tits-guru.outbound' => ['kind' => 'relay']], [], 'tits-guru: outbound.kind must be one of direct, got "relay"'],
    'a smart host beside the kind' => [['targets.tits-guru.outbound.host' => 'smtp.example.com'], [], 'tits-guru: outbound must be exactly {kind}, found ["host","kind"]'],
    'a capture destination beside outbound' => [['targets.tits-guru.capture' => ['host' => '127.0.0.1', 'port' => 1025]], [], 'tits-guru: outbound mail leaves only through its own outbound transport, so it may not declare "capture"'],
]);

it('refuses a control character anywhere, including the newline a shell would strip', function (string $path, string $value) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith([$path => $value])),
        'mail routing policy must not contain control characters in any key or value',
    );
})->with([
    'a trailing newline on a loopback address' => ['targets.staging-main.submission.host', "127.0.0.1\n"],
    'a NUL inside a domain' => ['targets.tits-guru.mail_domain', "tits.guru\u{0000}.evil.example"],
    'a header injected into an address' => ['targets.tits-guru.default_from', "noreply@tits.guru\r\nBcc: someone@example.com"],
]);

it('refuses a policy holding a secret-like property, however deep', function (string $path, string $name) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith([$path => 'hunter2'])),
        "the policy holds a secret-like property name \"{$name}\"",
    );
})->with([
    'a password beside a policy' => ['targets.tits-guru.smtp_password', 'smtp_password'],
    'an auth block inside an endpoint' => ['targets.staging-main.capture.auth', 'auth'],
    'a DKIM private key' => ['targets.tits-guru.dkim_private_key', 'dkim_private_key'],
    'an API token' => ['targets.tits-guru.api_token', 'api_token'],
    'a relay username' => ['targets.tits-guru.username', 'username'],
]);

// =============================================================================
// SAFE VALUES
// =============================================================================

it('refuses a mail domain that is not a safe lowercase domain', function (string $domain) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.tits-guru.mail_domain' => $domain])),
        'tits-guru: mail_domain is not a lowercase domain name: ',
    );
})->with([
    'uppercase' => 'Tits.Guru',
    'an empty label' => 'tits..guru',
    'a leading hyphen' => '-tits.guru',
    'a trailing dot' => 'tits.guru.',
    'a wildcard' => '*.tits.guru',
    'whitespace' => 'tits guru',
    'a shell separator' => 'tits.guru;reboot',
    'an underscore' => 'tits_guru.com',
    'a single label' => 'guru',
    'an IP address' => '127.0.0.1',
    'a path' => 'tits.guru/inbox',
    'a label past 63 characters' => str_repeat('a', 64).'.guru',
    'empty' => '',
]);

it('refuses a sender address that is not safe, or not at the mail domain', function (string $address, string $reason) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(['targets.tits-guru.default_from' => $address])),
        $reason,
    );
})->with([
    'two at-signs' => ['noreply@@tits.guru', 'tits-guru: default_from is not a lowercase mail address'],
    'whitespace' => ['no reply@tits.guru', 'tits-guru: default_from is not a lowercase mail address'],
    'no domain' => ['noreply', 'tits-guru: default_from is not a lowercase mail address'],
    'a display form' => ['<noreply@tits.guru>', 'tits-guru: default_from is not a lowercase mail address'],
    'uppercase' => ['NoReply@tits.guru', 'tits-guru: default_from is not a lowercase mail address'],
    'a quoted local part' => ['"noreply"@tits.guru', 'tits-guru: default_from is not a lowercase mail address'],
    'two addresses' => ['noreply@tits.guru,ops@example.com', 'tits-guru: default_from is not a lowercase mail address'],
    'a leading dot' => ['.noreply@tits.guru', 'tits-guru: default_from is not a lowercase mail address'],
    'another domain' => ['noreply@other.guru', 'tits-guru: default_from "noreply@other.guru" must be an address at mail_domain "tits.guru"'],
    'its own bounce domain' => [
        'noreply@bounce.tx.tits.guru',
        'tits-guru: default_from "noreply@bounce.tx.tits.guru" must be an address at mail_domain "tits.guru"',
    ],
]);

it('keeps bounce and reply domains inside the identity they belong to', function (string $property, string $domain) {
    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], mailRoutingPolicyWith(["targets.tits-guru.{$property}" => $domain])),
        "tits-guru: {$property} \"{$domain}\" must be a subdomain of mail_domain \"tits.guru\"",
    );
})->with([
    'a bounce domain elsewhere' => ['bounce_domain', 'bounce.other.guru'],
    'a bounce domain that is the mail domain' => ['bounce_domain', 'tits.guru'],
    'a reply domain elsewhere' => ['reply_domain', 'reply.example.com'],
    'a reply domain that merely ends the same way' => ['reply_domain', 'replytits.guru'],
]);

it('refuses a production identity domain claimed by two targets', function () {
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]);
    data_set($policy, 'targets.demo-shop.mail_domain', 'tits.guru');
    data_set($policy, 'targets.demo-shop.default_from', 'shop@tits.guru');
    data_set($policy, 'targets.demo-shop.bounce_domain', 'bounce.shop.tits.guru');
    data_set($policy, 'targets.demo-shop.reply_domain', 'reply.shop.tits.guru');

    expectMailRoutingRefusal(
        mailRoutingRun(['validate'], $policy, mailRoutingDemoShopRegistry()),
        'mail identity domain tits.guru is claimed more than once: demo-shop mail_domain, tits-guru mail_domain',
    );
});

it('treats every target ID as data, never as a command or a program', function () {
    $canaries = sys_get_temp_dir().'/mail-routing-canary-'.bin2hex(random_bytes(6));
    @mkdir($canaries, 0o755, true);

    try {
        $hostile = [
            '$(touch '.$canaries.'/substitution)',
            '`touch '.$canaries.'/backtick`',
            'x"; touch '.$canaries.'/quote; echo "',
            'staging-main") | halt_error("injected',
            'Staging-Main',
            '../etc',
            'a',
            str_repeat('a', 33),
            'tits-guru ',
        ];

        foreach ($hostile as $index => $id) {
            $policy = mailRoutingPolicy();
            $hostilePolicy = mailRoutingDemoShopPolicy();
            $hostilePolicy['submission']['port'] = 3000 + $index;
            $hostilePolicy['mail_domain'] = "hostile{$index}.example";
            $hostilePolicy['default_from'] = "x@hostile{$index}.example";
            $hostilePolicy['bounce_domain'] = "bounce.hostile{$index}.example";
            $hostilePolicy['reply_domain'] = "reply.hostile{$index}.example";
            $policy['targets'][$id] = $hostilePolicy;

            expectMailRoutingRefusal(
                mailRoutingRun(['validate'], $policy),
                'invalid target ID '.json_encode($id, JSON_UNESCAPED_SLASHES).': it must match [a-z0-9][a-z0-9-]{1,31}',
            );
        }

        expect(glob($canaries.'/*') ?: [])->toBe([], 'a target ID reached a shell');
    } finally {
        exec('rm -rf '.escapeshellarg($canaries));
    }
});

// =============================================================================
// GENERIC, NOT HARD-CODED
// =============================================================================

it('routes a production target it has never heard of, with no change to the code', function () {
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]);
    $registry = mailRoutingDemoShopRegistry();

    $run = mailRoutingRun(['validate'], $policy, $registry);
    expect($run['status'])->toBe(0, $run['stderr']);

    $plan = mailRoutingPlan($policy, $registry);

    expect(mailRoutingListener($plan, 'demo-shop'))->toBe([
        'delivery_mode' => 'held',
        'environment_class' => 'production',
        'identity' => 'demo-shop',
        'lifecycle' => 'planned',
        'listen' => ['host' => '127.0.0.1', 'port' => 2599],
        'route' => null,
        'sender' => [
            'allowed_domain' => 'demo-shop.example',
            'bounce_domain' => 'bounce.demo-shop.example',
            'default_from' => 'hello@demo-shop.example',
            'reply_domain' => 'reply.demo-shop.example',
        ],
    ]);

    // The real targets render exactly as they do without it.
    $others = array_values(array_filter(
        $plan['listeners'],
        static fn (array $listener): bool => $listener['identity'] !== 'demo-shop',
    ));

    expect($others)->toBe(mailRoutingPlan()['listeners']);
});

it('routes a staging target it has never heard of into capture the same way', function () {
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => [
        'submission' => ['host' => '127.0.0.1', 'port' => 2599],
        'delivery_mode' => 'capture',
        'allowed_from_domain' => 'demo-shop.invalid',
        'capture' => ['host' => '127.0.0.1', 'port' => 1025],
    ]]);
    $registry = mailRoutingDemoShopRegistry(['environment_class' => 'staging']);

    expect(mailRoutingListener(mailRoutingPlan($policy, $registry), 'demo-shop'))->toBe([
        'delivery_mode' => 'capture',
        'environment_class' => 'staging',
        'identity' => 'demo-shop',
        'lifecycle' => 'planned',
        'listen' => ['host' => '127.0.0.1', 'port' => 2599],
        'route' => ['host' => '127.0.0.1', 'kind' => 'capture', 'port' => 1025],
        'sender' => ['allowed_domain' => 'demo-shop.invalid'],
    ]);
});

it('names no target, domain or port anywhere in its implementation', function () {
    $source = File::get(mailRoutingScript());
    $code = executableSourceLines($source);

    // Everything target-specific, read from the data rather than listed here,
    // so a target added later is covered without touching this test.
    $policy = mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]);
    $names = ['food-guru', 'animals-guru'];
    $ports = [];

    foreach ($policy['targets'] as $id => $target) {
        $names[] = $id;

        // A delivery mode and a route kind are the schema's own vocabulary,
        // which the validator must name; every other value is the target's.
        foreach (Arr::dot($target) as $key => $value) {
            if (str_ends_with($key, 'port')) {
                $ports[] = (string) $value;
            } elseif (is_string($value) && ! str_ends_with($key, 'host') && ! in_array($key, ['delivery_mode', 'outbound.kind'], true)) {
                $names[] = $value;
            }
        }
    }

    foreach (array_unique($names) as $name) {
        expect(str_contains($source, $name))
            ->toBeFalse("mail-routing names {$name} — routing must come from the policy, never from a list in the code");
    }

    foreach (array_unique($ports) as $port) {
        expect(preg_match('/\b'.preg_quote($port, '/').'\b/', $code))
            ->toBe(0, "mail-routing hard-codes port {$port}");
    }

    // Nothing from the policy reaches a shell: it is never sourced or
    // evaluated, and no jq program is assembled from a variable holding one
    // of its values.
    expect($code)
        ->not->toContain('eval ')
        ->not->toContain('source ')
        ->not->toContain('bash -c');
    expect(preg_match('/jq[^\n]*\$\{?(id|target)/i', $code))->toBe(0);
});

it('renders the same plan byte for byte, however the policy and registry are ordered', function () {
    $first = mailRoutingRun(['render-plan']);
    $second = mailRoutingRun(['render-plan']);

    expect($first['status'])->toBe(0, $first['stderr']);
    expect($second['stdout'])->toBe($first['stdout']);

    // Reverse the order of every object's keys — targets and properties alike
    // — and drop all whitespace.
    $reverse = function (mixed $node) use (&$reverse): mixed {
        if (! is_array($node) || array_is_list($node)) {
            return $node;
        }

        return array_map($reverse, array_reverse($node, true));
    };

    $reordered = mailRoutingRun(
        ['render-plan'],
        json_encode($reverse(mailRoutingPolicy()), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $reverse(perimeterRegistry()),
    );

    expect($reordered['stdout'])->toBe($first['stdout']);
});

it('renders no command, no path and no secret — only closed vocabulary', function () {
    $vocabulary = [
        'schema_version', 'listeners', 'identity', 'environment_class', 'lifecycle',
        'listen', 'host', 'port', 'delivery_mode', 'route', 'kind', 'sender',
        'allowed_domain', 'default_from', 'bounce_domain', 'reply_domain',
    ];

    $plans = [
        mailRoutingPlan(),
        mailRoutingPlan(
            mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopPolicy()]),
            mailRoutingDemoShopRegistry(),
        ),
        mailRoutingPlan(
            mailRoutingPolicyWith(['targets.demo-shop' => mailRoutingDemoShopOutboundPolicy()]),
            mailRoutingDemoShopRegistry(),
        ),
    ];

    foreach ($plans as $plan) {
        $walk = function (array $node) use (&$walk, $vocabulary): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    expect(in_array($key, $vocabulary, true))->toBeTrue("the plan has an unexpected property: {$key}");
                }

                if (is_array($value)) {
                    $walk($value);
                } elseif (is_string($value)) {
                    // No whitespace, no slash, no quote, no shell metacharacter.
                    expect(preg_match('/\A[a-z0-9][a-z0-9@._-]*\z/', $value))
                        ->toBe(1, "the plan holds a value that is not plain data: {$value}");
                } else {
                    expect(is_int($value) || $value === null)->toBeTrue('the plan holds an unexpected value type');
                }
            }
        };

        $walk($plan);
    }
});

// =============================================================================
// THE CLI ITSELF
// =============================================================================

it('changes nothing on disk when it validates or renders', function () {
    $scratch = sys_get_temp_dir().'/mail-routing-readonly-'.bin2hex(random_bytes(6));
    @mkdir($scratch, 0o755, true);

    try {
        $repo = provisionRepo($scratch, mailRoutingJson(perimeterRegistry()));
        $script = $repo.'/infrastructure/scripts/mail-routing';

        $snapshot = function () use ($scratch): array {
            $files = [];

            foreach (File::allFiles($scratch, true) as $file) {
                $files[$file->getPathname()] = [hash_file('sha256', $file->getPathname()), $file->getMTime(), $file->getPerms()];
            }

            ksort($files);

            return $files;
        };

        $before = $snapshot();

        expect(mailRoutingRun(['validate'], null, null, $script)['status'])->toBe(0);
        expect(mailRoutingRun(['render-plan'], null, null, $script)['status'])->toBe(0);
        expect(mailRoutingRun(['validate'], mailRoutingPolicyWith([], ['targets.tits-guru']), null, $script)['status'])->toBe(1);

        expect($snapshot())->toBe($before);
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }

    // And nothing in it could: no service, mail, network or file-writing command.
    $code = executableSourceLines(File::get(mailRoutingScript()));

    foreach (['systemctl', 'service ', 'postfix', 'postconf', 'sendmail', 'swaks', 'curl', 'wget', 'ssh', 'scp', 'rsync', 'install ', 'mkdir', 'cp ', 'mv ', 'rm ', 'tee', 'chmod', 'chown', 'sudo', 'mktemp'] as $command) {
        expect(str_contains($code, $command))->toBeFalse("mail-routing runs {$command}");
    }
});

it('handles its arguments strictly', function (array $arguments, int $status, string $expected) {
    $run = mailRoutingRun($arguments);

    expect($run['status'])->toBe($status);
    expect(str_contains($run['stdout'].$run['stderr'], $expected))
        ->toBeTrue("expected '{$expected}' in:\n{$run['stdout']}{$run['stderr']}");
})->with([
    'no command' => [[], 1, 'Usage:'],
    'an unknown command' => [['install'], 1, 'ERROR: unknown command: install'],
    'an unknown argument' => [['validate', '--target', 'tits-guru'], 1, 'ERROR: unknown argument: --target'],
    'a repeated --file' => [['validate', '--file', 'a.json', '--file', 'b.json'], 1, 'ERROR: --file given more than once'],
    'an empty --file' => [['validate', '--file', ''], 1, 'ERROR: --file requires a non-empty value'],
    'a --registry with no value' => [['validate', '--registry'], 1, 'ERROR: --registry requires a value'],
    'a missing policy file' => [['validate', '--file', '/nonexistent/mail-routing.json'], 1, 'mail routing policy is unavailable'],
    'help' => [['--help'], 0, 'mail-routing render-plan [--file PATH] [--registry PATH]'],
]);

it('refuses a policy reached through a symlink', function () {
    $scratch = sys_get_temp_dir().'/mail-routing-symlink-'.bin2hex(random_bytes(6));
    @mkdir($scratch, 0o755, true);

    try {
        file_put_contents($scratch.'/real.json', mailRoutingJson(mailRoutingPolicy()));
        symlink($scratch.'/real.json', $scratch.'/mail-routing.json');

        expectMailRoutingRefusal(
            mailRoutingRun(['validate', '--file', $scratch.'/mail-routing.json']),
            'mail routing policy must not be a symlink',
        );
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('is repository tooling that only the mail gateway, the mail identity judge and the infrastructure verifier consume, from the bundle they run in', function () {
    expect(repositoryOnlyScriptNames())->toContain('mail-routing');
    expect(requiredCliManifestNames())->not->toContain('mail-routing');

    // Never part of the operational bundle installed on a host.
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/install-target-operations'))))
        ->not->toContain('mail-routing');

    // Its only consumers are the gateway's installer and its verifier, the
    // mail identity judge, the read-only infrastructure verifier, which reads
    // a target's delivery mode from the plan, and the signing acceptance,
    // which reads the one held listener it may submit to, the outbound
    // activation, which reads the plan of the bundle it was asked to activate
    // and of the pre-activation copy it derives, and the canary, which reads
    // the reviewed sender and endpoint — each running the copy next to itself.
    // No workflow, action, orchestrator or other installer invokes it. The
    // one other file
    // that may name the POLICY is the prerequisite installer, which hands its
    // configuration directory's copy to the identity judge — never to this CLI.
    $consumers = [
        'infrastructure/scripts/install-mail-gateway',
        'infrastructure/scripts/verify-mail-gateway',
        'infrastructure/scripts/mail-identity',
        'infrastructure/scripts/verify-infrastructure',
        'infrastructure/scripts/verify-mail-signing',
        'infrastructure/scripts/activate-mail-outbound',
        'infrastructure/scripts/send-mail-canary',
    ];

    foreach (operationalFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (in_array($relative, ['infrastructure/scripts/mail-routing', 'infrastructure/config/mail-routing.json', ...$consumers], true)) {
            continue;
        }

        expect(preg_match('/mail-routing(?!\.json)/', executableSourceLines(File::get($path))))
            ->toBe(0, "{$relative} reaches mail-routing — only the mail gateway, the identity judge and the infrastructure verifier read the plan");
    }

    foreach ($consumers as $consumer) {
        expect(executableSourceLines(File::get(base_path($consumer))))
            ->toContain('${SCRIPT_DIR}/mail-routing')
            ->toContain('render-plan');
    }
});

// =============================================================================
// NOTHING IS LISTENING YET, AND NOTHING ELSE MOVED
// =============================================================================

it('configures no public SMTP listener anywhere in the repository', function () {
    // Every gateway endpoint in the plan is loopback.
    foreach (mailRoutingPlan()['listeners'] as $listener) {
        expect($listener['listen']['host'])->toBe('127.0.0.1');
    }

    // No mail transfer agent is committed, installed, configured or started.
    foreach (File::allFiles(base_path('infrastructure')) as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        expect(preg_match('/postfix|exim|(^|\/)(main|master)\.cf$/i', $relative))
            ->toBe(0, "mail transfer agent configuration committed: {$relative}");
    }

    // Only the gateway's own three scripts speak a mail transfer agent's
    // language; its configuration is rendered on the host, never committed.
    // What it renders is proved loopback-only in MailGatewayTest. The signing
    // acceptance reads and deletes its own one queue entry, by its exact ID,
    // and configures nothing; MailSigningTest proves it. The outbound
    // activation reads the live routes back with postconf and configures
    // nothing itself — the gateway's installer does — and the canary deletes
    // only its own queue entry; the MailOutboundActivation*Test and
    // MailCanary*Test files prove both.
    $gateway = [
        'infrastructure/scripts/install-mail-gateway',
        'infrastructure/scripts/verify-mail-gateway',
        'infrastructure/scripts/status-mail-gateway',
        'infrastructure/scripts/verify-mail-signing',
        'infrastructure/scripts/activate-mail-outbound',
        'infrastructure/scripts/send-mail-canary',
    ];

    foreach (operationalFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (in_array($relative, $gateway, true)) {
            continue;
        }

        $code = mb_strtolower(executableSourceLines(File::get($path)));

        foreach (['postfix', 'postconf', 'postmap', 'postsuper', 'inet_interfaces', 'smtpd_', 'exim4'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse("{$relative} configures a mail transfer agent ({$needle})");
        }
    }

    // Nginx publishes HTTP and HTTPS only, and proxies no raw TCP stream.
    foreach (glob(base_path('infrastructure/config/nginx/*')) ?: [] as $path) {
        $source = File::get($path);

        foreach (mailRoutingNginxListenPorts($source) as $port) {
            expect(in_array($port, ['80', '443'], true))->toBeTrue(basename($path)." listens on {$port}");
        }

        expect(preg_match('/^\s*stream\s*\{/m', executableSourceLines($source)))->toBe(0);
    }

    // No socket-activated listener in any committed unit.
    foreach (glob(base_path('infrastructure/config/systemd/*')) ?: [] as $path) {
        expect(executableSourceLines(File::get($path)))->not->toContain('ListenStream');
    }

    // The capture slice's own SMTP listeners stay on loopback.
    expect(envFileValues('infrastructure/config/mail-capture/mailpit.env')['MP_SMTP_BIND_ADDR'])->toStartWith('127.');
    expect(File::get(base_path('infrastructure/config/systemd/staging-mailtrap-local.service')))
        ->toContain('--smtp-listen 127.0.0.2:3535')
        ->not->toContain('0.0.0.0');
});

it('routes staging capture into the Mailpit listener that is actually configured, and leaves the capture slice as it is', function () {
    $mailpit = envFileValues('infrastructure/config/mail-capture/mailpit.env');
    $relay = Yaml::parse(File::get(base_path('infrastructure/config/mail-capture/mailpit-relay.yml')));
    $mailtrapUnit = File::get(base_path('infrastructure/config/systemd/staging-mailtrap-local.service'));

    // The existing capture listeners, exactly as accepted on the host.
    expect($mailpit['MP_SMTP_BIND_ADDR'])->toBe('127.0.0.1:1025');
    expect($mailpit['MP_UI_BIND_ADDR'])->toBe('127.0.0.1:8025');
    expect($mailpit['MP_SMTP_RELAY_ALL'])->toBe('true');
    expect([$relay['host'], $relay['port']])->toBe(['127.0.0.2', 3535]);
    expect($mailtrapUnit)
        ->toContain('--smtp-listen 127.0.0.2:3535')
        ->toContain('--http-listen 127.0.0.1:3550');

    $captureListeners = [
        $mailpit['MP_SMTP_BIND_ADDR'],
        $mailpit['MP_UI_BIND_ADDR'],
        '127.0.0.2:3535',
        '127.0.0.1:3550',
    ];
    $capturePorts = array_map(static fn (string $endpoint): int => (int) substr($endpoint, strrpos($endpoint, ':') + 1), $captureListeners);

    foreach (mailRoutingPlan()['listeners'] as $listener) {
        // Every capture route is Mailpit's own SMTP listener — the canonical
        // capture, which mirrors on to Mailtrap Local by itself.
        if ($listener['delivery_mode'] === 'capture') {
            expect($listener['route']['host'].':'.$listener['route']['port'])->toBe($mailpit['MP_SMTP_BIND_ADDR']);
        }

        // And no gateway endpoint lands on a port the capture services hold.
        expect(in_array($listener['listen']['port'], $capturePorts, true))
            ->toBeFalse("{$listener['identity']}'s gateway port {$listener['listen']['port']} collides with a mail-capture listener");
    }

    // The capture slice knows nothing about the gateway.
    $gatewayPorts = array_map(static fn (array $listener): string => (string) $listener['listen']['port'], mailRoutingPlan()['listeners']);
    $captureFiles = [
        'infrastructure/scripts/install-mail-capture',
        'infrastructure/scripts/verify-mail-capture',
        'infrastructure/scripts/status-mail-capture',
        'infrastructure/config/systemd/staging-mailpit.service',
        'infrastructure/config/systemd/staging-mailtrap-local.service',
        'infrastructure/config/nginx/mailpit-staging',
        'infrastructure/config/nginx/mailtrap-local-staging',
        ...array_map(
            static fn (string $path): string => str_replace(base_path().'/', '', $path),
            glob(base_path('infrastructure/config/mail-capture/*')) ?: [],
        ),
    ];

    foreach ($captureFiles as $path) {
        $source = File::get(base_path($path));

        expect(str_contains($source, 'mail-routing'))->toBeFalse("{$path} refers to mail routing");

        foreach ($gatewayPorts as $port) {
            expect(preg_match('/\b'.$port.'\b/', $source))->toBe(0, "{$path} refers to gateway port {$port}");
        }
    }
});

it('names the gateway in the staging template, and no endpoint for a target with no route', function () {
    $plan = mailRoutingPlan();
    $staging = mailRoutingListener($plan, 'staging-main');

    // The gateway is committed host infrastructure, so the reviewed staging
    // template names its listener — exactly the one the plan gives staging.
    $template = envFileValues('infrastructure/templates/environment/staging.env.example');

    expect($template['MAIL_MAILER'])->toBe('smtp');
    expect($template['MAIL_HOST'])->toBe($staging['listen']['host']);
    expect($template['MAIL_PORT'])->toBe((string) $staging['listen']['port']);
    expect($template['MAIL_FROM_ADDRESS'])->toBe('noreply@'.$staging['sender']['allowed_domain']);

    // tits-guru is planned and held: no route, so its template names no
    // endpoint, and no template anywhere names a held listener.
    $titsGuru = envFileValues('infrastructure/templates/environment/tits-guru.env.example');

    foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS'] as $key) {
        expect($titsGuru[$key])->toBe('', "tits-guru.env.example sets {$key} while its mail has no route");
    }

    $heldPorts = collect($plan['listeners'])
        ->where('delivery_mode', 'held')
        ->map(static fn (array $listener): string => (string) $listener['listen']['port'])
        ->all();

    foreach (glob(base_path('infrastructure/templates/environment/*.env.example')) ?: [] as $path) {
        $values = envFileValues(str_replace(base_path().'/', '', $path));

        expect(in_array($values['MAIL_PORT'] ?? '', $heldPorts, true))
            ->toBeFalse(basename($path).' names a held listener as its mail endpoint');
    }

    // The contract the templates are generated from: staging moved, and
    // production did not.
    $contract = collect(json_decode(File::get(base_path('infrastructure/config/environment-contract.json')), true)['keys'])
        ->keyBy('key');

    expect($contract['MAIL_HOST']['value'])->toBe(['by_environment_class' => ['staging' => '127.0.0.1', 'production' => '']]);
    expect($contract['MAIL_PORT']['value'])->toBe(['by_environment_class' => ['staging' => '2525', 'production' => '']]);
    expect($contract['MAIL_MAILER']['value'])->toBe(['by_environment_class' => ['staging' => 'smtp', 'production' => '']]);
    expect($contract['MAIL_FROM_ADDRESS']['value'])->toBe(['by_environment_class' => ['staging' => 'noreply@staging.invalid', 'production' => '']]);
});

it('needs no application change to move a target onto its gateway', function () {
    // The smtp mailer reads every endpoint setting from the environment, so
    // the gateway contract is an environment change and nothing else.
    expect(File::get(base_path('config/mail.php')))
        ->toContain("'default' => env('MAIL_MAILER', 'log')")
        ->toContain("'transport' => 'smtp'")
        ->toContain("'scheme' => env('MAIL_SCHEME')")
        ->toContain("'host' => env('MAIL_HOST', '127.0.0.1')")
        ->toContain("'port' => env('MAIL_PORT', 2525)")
        ->toContain("'username' => env('MAIL_USERNAME')")
        ->toContain("'password' => env('MAIL_PASSWORD')")
        ->toContain("'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com')");
});

it('documents the template, the host and the future apart, and the template and the future match the plan', function () {
    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-routing.md')));

    expect($runbook)
        ->toContain('Laravel staging → gateway 127.0.0.1:2525 → Mailpit 127.0.0.1:1025 → Mailtrap Local')
        ->toContain('There is **no public SMTP listener**')
        ->toContain('**No secret in the routing policy.**')
        ->toContain('never written by code');

    $plan = mailRoutingPlan();
    $staging = mailRoutingListener($plan, 'staging-main');
    $titsGuru = mailRoutingListener($plan, 'tits-guru');

    $section = static function (string $heading, ?string $next) use ($runbook): string {
        $start = mb_strpos($runbook, $heading);
        expect($start)->not->toBeFalse("the runbook lost its section: {$heading}");

        $end = $next === null ? false : mb_strpos($runbook, $next, (int) $start);

        return $end === false ? mb_substr($runbook, (int) $start) : mb_substr($runbook, (int) $start, $end - $start);
    };

    $template = $section('### The committed staging template names the gateway', '### CURRENT runtime values');
    $current = $section('### CURRENT runtime values (what the staging host runs today)', '### FUTURE application values');
    $future = $section('### FUTURE application values (not set — tits-guru is not deployed)', '## Adding a target');

    // The template is the plan's staging listener, and so is the host since its
    // operator cut over to the gateway; the future is tits-guru's.
    expect($template)->toContain("MAIL_MAILER=smtp MAIL_HOST={$staging['listen']['host']} MAIL_PORT={$staging['listen']['port']} MAIL_FROM_ADDRESS=noreply@staging.invalid");
    expect($current)->toContain("MAIL_MAILER=smtp MAIL_HOST={$staging['listen']['host']} MAIL_PORT={$staging['listen']['port']} MAIL_FROM_ADDRESS=noreply@staging.invalid");
    expect($future)->toContain("MAIL_MAILER=smtp MAIL_HOST={$titsGuru['listen']['host']} MAIL_PORT={$titsGuru['listen']['port']} MAIL_FROM_ADDRESS={$titsGuru['sender']['default_from']}");
});

it('records the mail-routing foundation in the roadmap as implemented, not installed and not accepted', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4A Production backup perimeter readiness — IMPLEMENTED, not yet active.**')
        ->toContain('**8.4B.1 Generic mail-routing foundation — IMPLEMENTED, not installed and not accepted on a real host.**')
        ->toContain('Production mail is not accepted.')
        ->toContain('[`runbooks/mail-routing.md`](runbooks/mail-routing.md)')
        // The roadmap's acceptance for production mail is still the open one it was.
        ->toContain('*Acceptance:* production mail is delivered and its failure paths are handled')
        ->not->toContain('mail-routing foundation — ACCEPTED');
});
