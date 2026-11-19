<?php

namespace App\Filament\Resources\Games\Analytics;

use App\Enums\GameAudience;
use App\Models\GameVersion;
use App\Models\TrafficPriceSet;
use Livewire\Attributes\Url;

/**
 * The filters of a game's own that its analytics reads (GameAnalyticsView),
 * as the address keeps them, wherever it is drawn — the game's Analytics tab
 * and Growth → Analytics → Games alike: the group (anonymous visitors or
 * logged-in players), a version of the game or every one, a country or a
 * group of countries or every one, what the figures are broken down by, and
 * the two cards' own choices — the paid source the versions are compared on,
 * and the device Traffic sources is of while the page is of every device.
 * The period and the device are each page's own. Whatever the address or an
 * update sends is read back as the figures read it.
 */
trait ReadsGameAnalytics
{
    #[Url(as: 'group', except: 'anonymous')]
    public string $audience = 'anonymous';

    /** Every version ('0'), or one by id: text, as the Versions menu's values are, so the one chosen is checked there. */
    #[Url(except: '0')]
    public mixed $version = '0';

    /** Every country, a country's code, or a country group of the price set as `group:` and its key. */
    #[Url(except: '')]
    public mixed $country = '';

    #[Url(as: 'by', except: 'version')]
    public string $breakdown = 'version';

    /** Versions on paid traffic: every paid source ('0'), or one by id, as text like the version. */
    #[Url(as: 'paid', except: '0')]
    public mixed $paidSource = '0';

    /** Traffic sources: every device, or one, while the page itself is of every device. */
    #[Url(as: 'sources_device', except: 'all')]
    public string $sourcesDevice = 'all';

    /**
     * Puts the game's filters back as they are read — the audience, a version
     * of this game, the country, the breakdown and the cards' own choices —
     * so the address, the screen and the figures agree.
     */
    protected function settleGameAnalytics(int $gameId): void
    {
        $this->audience = (GameAudience::tryFrom($this->audience) ?? GameAudience::Anonymous)->value;
        $version = is_numeric($this->version) ? (int) $this->version : 0;
        $this->version = (string) ($version > 0 && GameVersion::query()->where('game_id', $gameId)->whereKey($version)->exists() ? $version : 0);
        $this->paidSource = (string) (is_numeric($this->paidSource) ? max(0, (int) $this->paidSource) : 0);
        $this->country = GameAnalyticsView::country($this->country, TrafficPriceSet::current()->country_groups);
        $this->breakdown = array_key_exists($this->breakdown, GameAnalyticsView::BREAKDOWNS) ? $this->breakdown : 'version';
        $this->sourcesDevice = in_array($this->sourcesDevice, ['mobile', 'desktop'], true) ? $this->sourcesDevice : 'all';
    }

    /** Every filter of the game's back to none chosen, as where no game's figures are shown. */
    protected function clearGameAnalytics(): void
    {
        $this->audience = GameAudience::Anonymous->value;
        $this->version = '0';
        $this->country = '';
        $this->breakdown = 'version';
        $this->paidSource = '0';
        $this->sourcesDevice = 'all';
    }
}
