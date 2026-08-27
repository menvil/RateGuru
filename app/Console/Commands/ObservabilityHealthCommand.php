<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
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

    private function checkExternalVendors(): void
    {
        $this->line('');
        $this->line('External vendors (not installed):');

        $datadog = config('observability.external_vendors.datadog_agent_host') ? 'configured' : 'not configured (optional)';
        $this->line("  Datadog: {$datadog}");

        $nightwatch = config('observability.external_vendors.nightwatch_token') ? 'configured' : 'not configured (optional)';
        $this->line("  Nightwatch: {$nightwatch}");
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
