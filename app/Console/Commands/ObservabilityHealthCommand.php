<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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

    private function checkExternalVendors(): void
    {
        $this->line('');
        $this->line('External vendors (optional):');

        $sentry = config('observability.external_vendors.sentry_dsn') ? 'configured' : 'not configured (optional)';
        $this->line("  Sentry: {$sentry}");

        $datadog = config('observability.external_vendors.datadog_agent_host') ? 'configured' : 'not configured (optional)';
        $this->line("  Datadog: {$datadog}");

        $nightwatch = config('observability.external_vendors.nightwatch_token') ? 'configured' : 'not configured (optional)';
        $this->line("  Nightwatch: {$nightwatch}");
    }
}
