<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficEvent;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\User;
use App\Services\Traffic\Postbacks\PostbackAttempt;
use App\Services\Traffic\Postbacks\PostbackDeliverer;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\Traffic\Postbacks\PostbackTemplate;
use App\Support\Traffic\Postbacks\PostbackUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a rule as the editor holds it — saved or not — once, with a made-up
 * click ID (TEST-…) and a value of 0.01, and says what the network
 * answered. Nothing is written: the network shows it as a test, or refuses
 * the unknown click, which proves the address reaches it.
 */
final class SendTestPostbackAction
{
    use LocksTrafficRecords;

    public function __construct(
        private readonly PostbackDeliverer $deliverer,
        private readonly ActionRateLimiter $limiter,
    ) {}

    /**
     * @param  array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null  $responseRules
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, string $template, TrafficEvent $event, ?string $goalCode, ?array $responseRules): PostbackAttempt
    {
        // Checked and sent as one: an address pasted with a newline after it is checked trimmed, so it is sent trimmed.
        $template = trim($template);
        $problems = PostbackTemplate::violations($template);

        if ($problems !== []) {
            throw ValidationException::withMessages(['url_template' => $problems]);
        }

        DB::transaction(fn () => $this->lockAdministrator($admin));
        $this->limiter->hitOrFail('traffic-test-postback:'.$admin->id, 10, 60);

        $url = PostbackUrl::withClickId(PostbackUrl::render($template, [
            'event' => $event->value,
            'goal_code' => (string) $goalCode,
            'value' => '0.01',
            'value_total' => '0.01',
            'currency' => 'USD',
            'conversion_id' => '0',
            'device' => 'mobile',
            'game' => 'test',
            'version' => 'V1',
        ]), 'TEST-'.bin2hex(random_bytes(4)));

        return $this->deliverer->send([0 => ['url' => $url, 'rules' => $responseRules]])[0];
    }
}
