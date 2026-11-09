<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ApplyGameTrafficAction;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Traffic\TrafficShareValidator;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;

/**
 * A game's Traffic tab: how each audience's visitors are shared out between
 * its active versions — anonymous visitors and logged-in players apart — each
 * applied on its own (ApplyGameTrafficAction), and every period an allocation
 * was in effect, with how many visitors it assigned and how many attempts
 * they started and completed.
 */
final class GameTraffic extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-traffic';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** @var array<string, array<int|string, mixed>> the shares on screen, by audience and version id */
    public array $shares = [];

    /** @var array<string, array<int|string, mixed>> the shares in effect */
    public array $applied = [];

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
        $this->draw();
    }

    public function addVersion(mixed $audience, mixed $versionId): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;

        if ($audience === null || ! is_numeric($versionId) || ! in_array((int) $versionId, $this->activeVersionIds(), true)) {
            return;
        }

        $this->shares[$audience->value][(int) $versionId] ??= 0;
    }

    public function removeVersion(mixed $audience, mixed $versionId): void
    {
        if (is_string($audience) && is_numeric($versionId)) {
            unset($this->shares[$audience][(int) $versionId]);
        }
    }

    /** Whole shares as even as they can be, the remainder to the first versions. */
    public function splitEvenly(mixed $audience): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;

        if ($audience === null || ($this->shares[$audience->value] ?? []) === []) {
            return;
        }

        $ids = array_keys($this->shares[$audience->value]);
        $each = intdiv(100, count($ids));
        $left = 100 - $each * count($ids);

        foreach ($ids as $index => $id) {
            $this->shares[$audience->value][$id] = $each + ($index < $left ? 1 : 0);
        }
    }

    public function resetShares(mixed $audience = null): void
    {
        if (is_string($audience) && isset($this->applied[$audience])) {
            $this->shares[$audience] = $this->applied[$audience];
        }
    }

    public function apply(mixed $audience): void
    {
        $audience = is_string($audience) ? GameAudience::tryFrom($audience) : null;
        $game = $this->game();

        if ($audience === null || $game === null) {
            return;
        }

        $shares = [];

        foreach ($this->shares[$audience->value] ?? [] as $versionId => $share) {
            $shares[(int) $versionId] = is_numeric($share) && (string) (int) $share === (string) $share ? (int) $share : -1;
        }

        try {
            $started = app(ApplyGameTrafficAction::class)->handle($this->admin(), $game, $audience, $shares);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->draw();
        $this->toast($started ? 'Shares applied. A new period started.' : 'Shares applied. The current period had no visitors yet, so it was updated.');
    }

    private function draw(): void
    {
        $traffic = app(GameTrafficQuery::class);

        foreach (GameAudience::cases() as $audience) {
            $shares = $traffic->currentPeriod($this->gameId, $audience)?->shares() ?? [];
            $this->shares[$audience->value] = $shares;
            $this->applied[$audience->value] = $shares;
        }
    }

    /** @return list<int> */
    private function activeVersionIds(): array
    {
        return GameVersion::query()->where('game_id', $this->gameId)->where('status', GameVersionStatus::Active)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow'])->orderBy('number')->get()->keyBy('id');
        $active = $versions->filter(fn (GameVersion $version): bool => $version->isActive());
        $unplayable = app(GameTrafficQuery::class)->unplayableVersionIds($versions->keys()->map(fn (mixed $id): int => (int) $id)->all());
        $groups = [];

        foreach (GameAudience::cases() as $audience) {
            $draft = $this->shares[$audience->value] ?? [];
            $clean = [];

            foreach ($draft as $versionId => $share) {
                $clean[(int) $versionId] = is_numeric($share) ? (int) $share : -1;
            }

            $groups[] = [
                'audience' => $audience->value,
                'label' => $audience->label(),
                'note' => $audience === GameAudience::Anonymous
                    ? 'Each new visitor gets one version and keeps it when they come back.'
                    : 'Logged-in players skip the steps shown only to anonymous visitors, such as sign-up.',
                'rows' => array_map(fn (int $versionId): array => [
                    'id' => $versionId,
                    'label' => $versions->get($versionId)?->label() ?? 'V?',
                    'made' => $versions->has($versionId) ? $versions[$versionId]->contentSet->name.' · '.$versions[$versionId]->flow->name : '',
                    'published' => $versions->get($versionId)?->published_at->format('d M Y'),
                    'unplayable' => in_array($versionId, $unplayable, true),
                ], array_keys($clean)),
                'addable' => $active->reject(fn (GameVersion $version): bool => array_key_exists((int) $version->id, $clean))->map(fn (GameVersion $version): array => ['id' => (int) $version->id, 'label' => $version->label()])->values()->all(),
                'total' => array_sum(array_map(fn (int $share): int => max(0, $share), $clean)),
                'issues' => TrafficShareValidator::issues($clean, $active->keys()->map(fn (mixed $id): int => (int) $id)->all()),
                'changed' => $clean != array_map('intval', $this->applied[$audience->value] ?? []),
            ];
        }

        $periods = GameTrafficPeriod::query()->where('game_id', $game->id)->with('versions')->withCount('assignments')->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get();
        $attempts = app(GameMetricsQuery::class)->periods((int) $game->id);

        return [
            'header' => $this->editorHeader($game, 'traffic'),
            'paused' => $game->status === GameStatus::Paused,
            'archived' => $game->status === GameStatus::Archived,
            'noVersions' => $versions->isEmpty(),
            'groups' => $groups,
            'periods' => $periods->map(fn (GameTrafficPeriod $period): array => [
                'audience' => $period->audience->label(),
                'from' => $period->started_at->format('d M Y, H:i'),
                'to' => $period->ended_at?->format('d M Y, H:i'),
                'shares' => $period->versions->map(fn (GameVersion $version): string => $version->label().' '.((int) $version->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ') ?: 'No traffic',
                'visitors' => (int) $period->assignments_count,
                'started' => $attempts[(int) $period->id]['started'] ?? 0,
                'completed' => $attempts[(int) $period->id]['completed'] ?? 0,
            ])->all(),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
        ];
    }
}
