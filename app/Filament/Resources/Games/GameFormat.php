<?php

namespace App\Filament\Resources\Games;

use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Models\GameVersion;

/**
 * How the library and the editor say what a game and its versions are: one
 * status-to-tone mapping (STS-01) for both, and versions named the way the
 * prototype names them (V1, V2 …).
 */
final class GameFormat
{
    /** @return array{label: string, tone: string, dot: bool} */
    public static function status(GameStatus $status): array
    {
        return match ($status) {
            GameStatus::Draft => ['label' => $status->label(), 'tone' => 'outline', 'dot' => false],
            GameStatus::Live => ['label' => $status->label(), 'tone' => 'success', 'dot' => true],
            GameStatus::Paused => ['label' => $status->label(), 'tone' => 'warning', 'dot' => false],
            GameStatus::Archived => ['label' => $status->label(), 'tone' => 'neutral', 'dot' => false],
        };
    }

    /** @return array{label: string, tone: string, dot: bool} */
    public static function versionStatus(GameVersion $version, bool $inTraffic): array
    {
        return match (true) {
            $version->status === GameVersionStatus::Retired => ['label' => 'Archived', 'tone' => 'neutral', 'dot' => false],
            $inTraffic => ['label' => 'Live', 'tone' => 'success', 'dot' => true],
            default => ['label' => 'No traffic', 'tone' => 'outline', 'dot' => false],
        };
    }

    /**
     * What a status change does, for its confirmation: title, body, the
     * button's label, the dialog's tone and its icon.
     *
     * @return array{title: string, body: string, button: string, tone: string, icon: string}
     */
    public static function statusChange(GameStatus $from, GameStatus $to): array
    {
        return match ($to) {
            GameStatus::Live => [
                'title' => $from === GameStatus::Paused ? 'Resume this game?' : 'Make this game live?',
                'body' => 'Visitors can play it at once. They get versions by the shares in Traffic.',
                'button' => $from === GameStatus::Paused ? 'Resume game' : 'Go live',
                'tone' => 'default',
                'icon' => 'play',
            ],
            GameStatus::Paused => $from === GameStatus::Archived
                ? ['title' => 'Restore this game?', 'body' => 'It comes back paused: nobody can start it until you make it live again.', 'button' => 'Restore game', 'tone' => 'default', 'icon' => 'archive-restore']
                : ['title' => 'Pause this game?', 'body' => 'Nobody can start it. Players already in a round can finish. Shares and periods are kept.', 'button' => 'Pause game', 'tone' => 'warning', 'icon' => 'pause'],
            GameStatus::Archived => [
                'title' => 'Archive this game?',
                'body' => 'It is hidden from the library and the site. Its versions, attempts and numbers are kept, and it can be restored.',
                'button' => 'Archive game',
                'tone' => 'warning',
                'icon' => 'archive',
            ],
            GameStatus::Draft => ['title' => 'Restore this game?', 'body' => 'It comes back as a draft.', 'button' => 'Restore game', 'tone' => 'default', 'icon' => 'archive-restore'],
        };
    }
}
