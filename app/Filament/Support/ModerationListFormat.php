<?php

namespace App\Filament\Support;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * How the moderation screens — Posts, Comments, Reports and Users — say when
 * something happened, how long it has waited, whose initials an avatar
 * shows, and where a person stands.
 */
final class ModerationListFormat
{
    /** “Today 06:29”, “Yesterday 21:40”, “03 Oct 20:15”, or with the year when it is not this one. */
    public static function when(CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'Today '.$at->format('H:i'),
            $at->isYesterday() => 'Yesterday '.$at->format('H:i'),
            $at->isCurrentYear() => $at->format('d M H:i'),
            default => $at->format('d M Y'),
        };
    }

    /** How long since then: “45 min”, “3 h 12 min”, “2 d 4 h”. */
    public static function age(CarbonInterface $since): string
    {
        $minutes = max(0, (int) $since->diffInMinutes(now(), true));

        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => intdiv($minutes, 60).' h '.($minutes % 60).' min',
            default => intdiv($minutes, 1440).' d '.intdiv($minutes % 1440, 60).' h',
        };
    }

    /**
     * A person's lifecycle status as its badge shows it (STS-01): the live
     * dot on Active only.
     *
     * @return array{label: string, tone: string, dot: bool}
     */
    public static function personStatus(User $person): array
    {
        return match ($person->status) {
            UserStatus::Limited => ['label' => 'Limited', 'tone' => 'warning', 'dot' => false],
            UserStatus::Banned => ['label' => 'Banned', 'tone' => 'danger', 'dot' => false],
            UserStatus::Shadowbanned => ['label' => 'Shadowbanned', 'tone' => 'neutral', 'dot' => false],
            UserStatus::Deleted => ['label' => 'Deleted', 'tone' => 'outline', 'dot' => false],
            default => ['label' => 'Active', 'tone' => 'success', 'dot' => true],
        };
    }

    /** Whether a person is trusted: at or above the trusted level their posts can publish at once. */
    public static function trust(User $person): string
    {
        return (int) $person->trust_level >= MarkUserTrustedAction::TRUSTED_LEVEL ? 'Trusted' : 'Not trusted';
    }

    /** The first two letters of a name, for an avatar: “luna.the.husky” is LU. */
    public static function initials(string $name): string
    {
        return Str::upper(Str::substr((string) preg_replace('/[^\pL]/u', '', $name), 0, 2));
    }
}
