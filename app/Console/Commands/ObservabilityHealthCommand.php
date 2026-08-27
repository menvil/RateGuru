<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Laravel\Nightwatch\Facades\Nightwatch as NightwatchFacade;
use Sentry\Laravel\Http\FlushEventsMiddleware;
use Sentry\Laravel\Http\SetRequestMiddleware;
use Sentry\Laravel\Tracing\Middleware as TracingMiddleware;

final class ObservabilityHealthCommand extends Command
{
    protected $signature = 'rateguru:observability:health';

    protected $description = 'Check observability configuration health';

    public function handle(): int
    {
        $this->info('RateGuru Observability Health Check');
        $this->line('');

        $this->checkRequestId();
        $this->checkRedaction();
        $this->checkSlowActions();
        $this->checkLogChannel();
        $this->checkDeploymentIdentity();
        $this->checkSentry();
        $this->checkNightwatch();
        $this->checkExternalVendors();

        $this->line('');
        $this->info('Health check complete.');

        return self::SUCCESS;
    }

    private function checkRequestId(): void
    {
        $header = config('observability.request_id.header');
        $this->line("[OK] Request ID header: {$header}");
    }

    private function checkRedaction(): void
    {
        $enabled = config('observability.redaction.enabled', false);
        $keys = config('observability.redaction.keys', []);
        $status = $enabled ? 'enabled' : 'disabled';

        $this->line("[OK] Sensitive data redaction: {$status} (".count($keys).' keys)');
    }

    private function checkSlowActions(): void
    {
        $enabled = config('observability.slow_actions.enabled', false);
        $threshold = config('observability.slow_actions.default_threshold_ms');
        $status = $enabled ? 'enabled' : 'disabled';

        $this->line("[OK] Slow action logging: {$status} (threshold: {$threshold}ms)");
    }

    private function checkLogChannel(): void
    {
        $channel = config('logging.default');
        $this->line("[OK] Default log channel: {$channel}");
    }

    /**
     * The operator-facing answer to "what exactly is this server running?".
     *
     * Every value comes from the same canonical source Sentry uses, so what is
     * printed here and what a Sentry event carries can never disagree.
     */
    private function checkDeploymentIdentity(): void
    {
        $this->line('');
        $this->line('Deployment identity:');

        $this->line('  Target: '.$this->orUnknown(config('deployment.target')));
        $this->line('  Release: '.$this->orUnknown(config('deployment.release')));
        $this->line('  Commit: '.$this->orUnknown(config('deployment.commit')));

        $state = (string) config('deployment.metadata_state');
        $file = (string) config('deployment.metadata_file');

        $this->line("  {$file}: {$state}");

        if ($state !== 'present') {
            $this->warn("  Release metadata is {$state} — Sentry events will carry no release.");
        }
    }

    /**
     * Reports whether Sentry is wired up and how it is configured. The DSN is a
     * credential and is deliberately never printed, in any form — only whether
     * one is present at all.
     */
    private function checkSentry(): void
    {
        $this->line('');
        $this->line('Sentry:');

        $enabled = filled(config('sentry.dsn'));

        $this->line('  Enabled: '.($enabled ? 'yes' : 'no (no DSN configured)'));
        $this->line('  Environment: '.$this->orUnknown(config('sentry.environment') ?? config('app.env')));
        $this->line('  Error sample rate: '.$this->rate(config('sentry.sample_rate')));
        $this->line('  Traces sample rate: '.$this->rate(config('sentry.traces_sample_rate')));
        $this->line('  Profiles sample rate: '.$this->rate(config('sentry.profiles_sample_rate')));
        $this->line('  Send default PII: '.$this->onOff(config('sentry.send_default_pii')));
        $this->line('  Structured logs: '.$this->onOff(config('sentry.enable_logs')));
        $this->line('  Metrics: '.$this->onOff(config('sentry.enable_metrics')));
        $this->line('  SQL bindings (breadcrumbs): '.$this->onOff(config('sentry.breadcrumbs.sql_bindings')));
        $this->line('  SQL bindings (tracing): '.$this->onOff(config('sentry.tracing.sql_bindings')));
        $this->line('  Ignored transactions: '.implode(', ', (array) config('sentry.ignore_transactions', [])));

        $this->checkTracingMiddleware();
    }

    /**
     * Whether the SDK actually attached its HTTP instrumentation.
     *
     * Sample rates say what a target *intends*; this says what the running
     * application will actually do. Both official Sentry providers register
     * their middleware in `boot()`, and only when a DSN is already configured
     * — so a target can report a trace sample rate of 1 and still produce no
     * transactions at all. That gap is invisible from configuration alone, and
     * is exactly the question to answer first when traces are missing.
     */
    private function checkTracingMiddleware(): void
    {
        $kernel = $this->laravel->bound(HttpKernelContract::class)
            ? $this->laravel->make(HttpKernelContract::class)
            : null;

        if (! $kernel instanceof HttpKernel) {
            $this->line('  HTTP tracing middleware: (unavailable — no HTTP kernel)');

            return;
        }

        foreach ([
            'HTTP tracing middleware' => TracingMiddleware::class,
            'Request context middleware' => SetRequestMiddleware::class,
            'Event flush middleware' => FlushEventsMiddleware::class,
        ] as $label => $middleware) {
            $registered = $kernel->hasMiddleware($middleware);

            $this->line("  {$label}: ".($registered ? 'registered' : 'NOT REGISTERED'));
        }

        if (filled(config('sentry.traces_sample_rate')) && ! $kernel->hasMiddleware(TracingMiddleware::class)) {
            $this->warn('  A trace sample rate is configured but the tracing middleware is not registered — no HTTP transactions will be produced.');
        }
    }

    /**
     * Static RateGuru-side Nightwatch configuration, and nothing else.
     *
     * Deliberately not a second `nightwatch:status`: that command is the
     * authoritative answer to "can this application reach its local agent",
     * it already exists, and duplicating it here would create two answers to
     * one question. This answers the different question — what this release
     * would send, and to where, if the agent is up.
     *
     * The environment token is a credential and is never printed, in any form
     * or any prefix — only whether one is present at all.
     */
    private function checkNightwatch(): void
    {
        $this->line('');
        $this->line('Nightwatch:');

        $installed = class_exists(NightwatchFacade::class);

        $this->line('  Installed: '.($installed ? 'yes' : 'no'));

        if (! $installed) {
            return;
        }

        $this->line('  Enabled: '.(config('nightwatch.enabled') ? 'yes' : 'no'));
        $this->line('  Token configured: '.(filled(config('nightwatch.token')) ? 'yes' : 'no'));
        $this->line('  Deploy: '.$this->orUnknown(config('nightwatch.deployment')));
        $this->line('  Server: '.$this->orUnknown(config('nightwatch.server')));
        $this->line('  Request sample rate: '.$this->rate(config('nightwatch.sampling.requests')));
        $this->line('  Command sample rate: '.$this->rate(config('nightwatch.sampling.commands')));
        $this->line('  Exception sample rate: '.$this->rate(config('nightwatch.sampling.exceptions')));
        $this->line('  Scheduled task sample rate: '.$this->rate(config('nightwatch.sampling.scheduled_tasks')));
        $this->line('  Request payload capture: '.$this->onOff(config('nightwatch.capture_request_payload')));
        $this->line('  Exception source code capture: '.$this->onOff(config('nightwatch.capture_exception_source_code')));
        $this->line('  Redacted headers: '.implode(', ', (array) config('nightwatch.redact_headers', [])));

        foreach ([
            'Queries' => 'ignore_queries',
            'Cache events' => 'ignore_cache_events',
            'Outgoing HTTP' => 'ignore_outgoing_requests',
            'Mail' => 'ignore_mail',
            'Notifications' => 'ignore_notifications',
        ] as $label => $key) {
            $this->line("  {$label}: ".(config("nightwatch.filtering.{$key}") ? 'ignored' : 'captured'));
        }

        $this->checkNightwatchLogChannel();
        $this->checkNightwatchIngest();
    }

    /**
     * Whether Laravel log records are being shipped to Nightwatch.
     *
     * The package registers a `nightwatch` log channel whether or not anyone
     * uses it, so the only thing that decides this is the log stack — which is
     * per-target `.env`, not code. Phase 6B leaves it out deliberately, so a
     * target that has added it is a finding, not a detail.
     */
    private function checkNightwatchLogChannel(): void
    {
        $stack = (array) config('logging.channels.stack.channels', []);
        $inStack = config('logging.default') === 'nightwatch' || in_array('nightwatch', $stack, true);

        $this->line('  Log ingestion: '.($inStack ? 'ENABLED' : 'disabled (channel not in the log stack)'));
        $this->line('  Log level (if enabled): '.$this->orUnknown(config('nightwatch.filtering.log_level')));

        if ($inStack) {
            $this->warn('  Laravel log records are being shipped to Nightwatch — Phase 6B deliberately leaves this off; see infrastructure/runbooks/nightwatch-evaluation.md.');
        }
    }

    /**
     * Where the agent is expected to listen, and whether that address is
     * routable.
     *
     * One value serves both directions: the application sends events to it and
     * `artisan nightwatch:agent` binds to it. A non-loopback value therefore
     * means the ingest listener is reachable from off-box, which is never
     * correct for RateGuru — the agent is a local sidecar, not a service.
     */
    private function checkNightwatchIngest(): void
    {
        $uri = (string) config('nightwatch.ingest.uri');
        $loopback = $this->isLoopbackIngestUri($uri);

        $this->line("  Ingest URI: {$uri} (".($loopback ? 'loopback' : 'NOT LOOPBACK').')');

        if (! $loopback) {
            $this->warn('  The Nightwatch ingest address is not a loopback address — the agent would accept connections from off-box. Fix NIGHTWATCH_INGEST_URI before enabling the agent.');
        }
    }

    private function isLoopbackIngestUri(string $uri): bool
    {
        $host = $uri;

        if (preg_match('/^\[(?<host>[^\]]+)\](?::\d+)?$/', $uri, $matches) === 1) {
            $host = $matches['host'];
        } elseif (str_contains($uri, ':')) {
            $host = (string) strstr($uri, ':', true);
        }

        return $host === 'localhost'
            || $host === '::1'
            || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
    }

    private function checkExternalVendors(): void
    {
        $this->line('');
        $this->line('External vendors (not installed):');

        $datadog = config('observability.external_vendors.datadog_agent_host') ? 'configured' : 'not configured (optional)';
        $this->line("  Datadog: {$datadog}");
    }

    private function orUnknown(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '(unknown)';
    }

    private function rate(mixed $value): string
    {
        return $value === null ? 'disabled' : (string) (float) $value;
    }

    private function onOff(mixed $value): string
    {
        return $value ? 'enabled' : 'disabled';
    }
}
