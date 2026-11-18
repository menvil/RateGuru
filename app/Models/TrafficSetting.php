<?php

namespace App\Models;

use App\Enums\GeoEdition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The tracker's own settings, one row: the block rule's general thresholds
 * — a network source may set its own in place of any (BlockRule) — the IP
 * database edition, and the switches that start recording visits — the
 * games' and paid clicks', and the rest of the site's — and sending
 * postbacks.
 *
 * @property int $id
 * @property string $block_min_spend
 * @property int $block_min_clicks
 * @property int $block_roi_below
 * @property int $block_open_below
 * @property int $block_open_below_average
 * @property int $block_grace_days
 * @property string $block_target_multiple
 * @property int $block_bot_clicks
 * @property int $block_start_clicks
 * @property int $block_start_percent
 * @property GeoEdition $geo_edition
 * @property CarbonImmutable|null $geo_refresh_requested_at
 * @property bool $capture_enabled
 * @property bool $site_capture_enabled
 * @property bool $postbacks_enabled
 * @property int|null $updated_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSetting extends Model
{
    private const string CAPTURE_FLAGS_KEY = 'traffic-capture-flags';

    private const string BLOCK_RULE_RAN_KEY = 'traffic-block-rule-ran-at';

    protected $table = 'traffic_settings';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'block_min_spend' => 'decimal:2',
            'block_min_clicks' => 'integer',
            'block_roi_below' => 'integer',
            'block_open_below' => 'integer',
            'block_open_below_average' => 'integer',
            'block_grace_days' => 'integer',
            'block_target_multiple' => 'decimal:1',
            'block_bot_clicks' => 'integer',
            'block_start_clicks' => 'integer',
            'block_start_percent' => 'integer',
            'geo_edition' => GeoEdition::class,
            'geo_refresh_requested_at' => 'immutable_datetime',
            'capture_enabled' => 'boolean',
            'site_capture_enabled' => 'boolean',
            'postbacks_enabled' => 'boolean',
            'updated_by_user_id' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The row, or the defaults while nobody has saved one; never written here. */
    public static function current(): self
    {
        return self::query()->find(1) ?? (new self)->forceFill(['id' => 1])->setRawAttributes([
            'id' => 1,
            'block_min_spend' => '2.00',
            'block_min_clicks' => 200,
            'block_roi_below' => -40,
            'block_open_below' => 50,
            'block_open_below_average' => 50,
            'block_grace_days' => 3,
            'block_target_multiple' => '2.0',
            'block_bot_clicks' => 30,
            'block_start_clicks' => 60,
            'block_start_percent' => 60,
            'geo_edition' => GeoEdition::GeoLite2City->value,
            'geo_refresh_requested_at' => null,
            'capture_enabled' => false,
            'site_capture_enabled' => false,
            'postbacks_enabled' => false,
            'updated_by_user_id' => null,
            'lock_version' => 1,
        ]);
    }

    /**
     * The two recording switches, as every page reads them: whether visits
     * are recorded, and whether the site's own pages record one too — only
     * ever with the first. One read of the cache, which keeps them until the
     * row is saved again; a page that asks costs no query.
     *
     * @return array{capture: bool, site: bool}
     */
    public static function captureFlags(): array
    {
        return Cache::rememberForever(self::CAPTURE_FLAGS_KEY, function (): array {
            $settings = self::current();

            return ['capture' => (bool) $settings->capture_enabled, 'site' => (bool) $settings->capture_enabled && (bool) $settings->site_capture_enabled];
        });
    }

    /** Lets the next page read the switches from the row again. */
    public static function forgetCaptureFlags(): void
    {
        Cache::forget(self::CAPTURE_FLAGS_KEY);
    }

    /**
     * When traffic:block-losers last went over the sources, as a source's
     * Blocking pane shows it. Kept in the cache, not on this row: the row is
     * saved under its lock_version, and a write every five minutes would
     * make an administrator's save of the block rule stale; a cleared cache
     * only shows no run until the next one, within five minutes.
     */
    public static function blockRuleRanAt(): ?CarbonImmutable
    {
        $at = Cache::get(self::BLOCK_RULE_RAN_KEY);

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : null;
    }

    public static function recordBlockRuleRun(CarbonInterface $at): void
    {
        Cache::forever(self::BLOCK_RULE_RAN_KEY, $at->toIso8601String());
    }

    protected static function booted(): void
    {
        // Whoever saves the row — the Tracking card, a seeder — the switches are read afresh once it is committed.
        static::saved(fn () => DB::afterCommit(fn () => self::forgetCaptureFlags()));
    }
}
