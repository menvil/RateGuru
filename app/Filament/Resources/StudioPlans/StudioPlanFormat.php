<?php

namespace App\Filament\Resources\StudioPlans;

use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Support\ModerationListFormat;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Media\AvatarUrlResolver;
use Carbon\CarbonInterface;

/**
 * How Publications & plans and the publication editor say what a plan is:
 * its name, its state (STS-01, one mapping for both), its author and when it
 * is to be published. Nothing here claims more than the plan holds — a Draft
 * is never called scheduled, and a deleted author is never named.
 */
final class StudioPlanFormat
{
    /** The statuses that keep a Persona from publishing until they are lifted. */
    private const RESTRICTED = [UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned];

    /** The plan's title, or what an untitled one is called. */
    public static function title(StudioPlan $plan): string
    {
        $title = trim((string) $plan->title);

        return $title !== '' ? $title : 'Untitled draft #'.$plan->id;
    }

    /**
     * A plan's state as its badge shows it.
     *
     * @return array{label: string, tone: string, dot: bool}
     */
    public static function state(StudioPlanStatus $status): array
    {
        return match ($status) {
            StudioPlanStatus::Draft => ['label' => $status->label(), 'tone' => 'outline', 'dot' => false],
            StudioPlanStatus::Approved => ['label' => $status->label(), 'tone' => 'info', 'dot' => false],
            StudioPlanStatus::Running => ['label' => $status->label(), 'tone' => 'warning', 'dot' => true],
            StudioPlanStatus::Completed => ['label' => $status->label(), 'tone' => 'success', 'dot' => false],
            StudioPlanStatus::Cancelled => ['label' => $status->label(), 'tone' => 'neutral', 'dot' => false],
        };
    }

    /**
     * When the plan is to be published, in words that promise nothing a
     * Draft does not do: a plan published at once is published when it is
     * approved, and a date is only the date it will carry.
     */
    public static function publication(StudioPlanPublicationMode $mode, ?CarbonInterface $at): string
    {
        return match (true) {
            $mode === StudioPlanPublicationMode::Now => 'When approved',
            $at === null => 'Date not selected',
            default => $at->format('d M Y, H:i'),
        };
    }

    /**
     * The author as a row or the editor shows it: who it is, and what keeps it
     * from publishing, if anything. A deleted account is “Deleted user” and
     * nothing else — no username, no name, no picture.
     *
     * @return array{id: int, name: string, username: ?string, avatar: ?string, initials: string, status: ?array{label: string, tone: string, dot: bool}, problem: ?string}|null
     */
    public static function author(?User $author): ?array
    {
        if ($author === null) {
            return null;
        }

        if ($author->isTombstoned()) {
            return [
                'id' => (int) $author->id,
                'name' => User::TOMBSTONE_DISPLAY_NAME,
                'username' => null,
                'avatar' => null,
                'initials' => '',
                'status' => null,
                'problem' => 'deleted',
            ];
        }

        $name = (string) ($author->display_name ?: ($author->name ?: $author->username));

        return [
            'id' => (int) $author->id,
            'name' => $name,
            'username' => (string) $author->username,
            'avatar' => app(AvatarUrlResolver::class)->url($author),
            'initials' => ModerationListFormat::initials($name !== '' ? $name : (string) $author->username),
            'status' => ModerationListFormat::personStatus($author),
            'problem' => match (true) {
                $author->role !== UserRole::Persona => 'not_persona',
                in_array($author->status, self::RESTRICTED, true) => 'restricted',
                default => null,
            },
        ];
    }
}
