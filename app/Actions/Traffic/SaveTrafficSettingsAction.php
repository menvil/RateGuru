<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\GeoEdition;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The tracker's switches and site-wide rules: whether visits are recorded,
 * whether the site's own pages record them too, and whether postbacks are
 * sent (all off until the administrator turns them on), the IP database
 * edition and a refresh asked for — which only records the request, the
 * download runs in geo:update — and the block rule's limits. One row, saved
 * at the version the screen was drawn from. Site visits are recorded only
 * with every other visit, so switching Record visits off switches them off
 * too. Once it is committed, the switches every page reads from the cache
 * are read afresh (TrafficSetting::captureFlags()).
 */
final class SaveTrafficSettingsAction
{
    use LocksTrafficRecords;

    /**
     * @param  array<string, mixed>  $changes  any of the settings, by column
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, array $changes, int $lockVersion): TrafficSetting
    {
        Validator::make($changes, [
            'capture_enabled' => ['sometimes', 'boolean'],
            'site_capture_enabled' => ['sometimes', 'boolean'],
            'postbacks_enabled' => ['sometimes', 'boolean'],
            'geo_edition' => ['sometimes', 'in:'.implode(',', array_map(fn (GeoEdition $edition): string => $edition->value, GeoEdition::cases()))],
            'block_min_spend' => ['sometimes', 'numeric', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'min:0', 'max:10000'],
            'block_min_clicks' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'block_roi_below' => ['sometimes', 'integer', 'min:-100', 'max:0'],
            'block_open_below' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'block_bot_clicks' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'block_start_clicks' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'block_start_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $saved = DB::transaction(function () use ($admin, $changes, $lockVersion): TrafficSetting {
            $locked = $this->lockAdministrator($admin);
            $settings = TrafficSetting::query()->lockForUpdate()->find(1) ?? TrafficSetting::current();

            if ($settings->exists && $settings->lock_version !== $lockVersion) {
                throw CannotManageTrafficException::becauseItChanged('the tracking settings');
            }

            $settings->forceFill([
                ...array_intersect_key($changes, array_flip(['capture_enabled', 'site_capture_enabled', 'postbacks_enabled', 'geo_edition', 'block_min_spend', 'block_min_clicks', 'block_roi_below', 'block_open_below', 'block_bot_clicks', 'block_start_clicks', 'block_start_percent'])),
                'id' => 1,
                'updated_by_user_id' => $locked->id,
                'lock_version' => $settings->exists ? $settings->lock_version + 1 : 2,
            ]);

            if (! $settings->capture_enabled) {
                $settings->site_capture_enabled = false;
            }

            $settings->save();

            return $settings;
        });

        TrafficSetting::forgetCaptureFlags();

        return $saved;
    }

    /** "Update now": the next geo:update checks for a newer database at once. */
    public function refreshGeo(User $admin): void
    {
        DB::transaction(function () use ($admin): void {
            $this->lockAdministrator($admin);
            $settings = TrafficSetting::query()->lockForUpdate()->find(1) ?? TrafficSetting::current();
            $settings->forceFill(['id' => 1, 'geo_refresh_requested_at' => now()])->save();
        });
    }
}
