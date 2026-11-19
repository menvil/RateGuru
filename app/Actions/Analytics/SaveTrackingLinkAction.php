<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrackingLinkDestination;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrackingLink;
use App\Models\User;
use App\Queries\Analytics\TrackingLinksQuery;
use App\Support\Analytics\TrackingLinkUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a tracking link as its drawer holds it, read by
 * TrackingLinksQuery::draft(): where it leads and its five tags, and its
 * name — the source, campaign and content it carries while none is typed.
 * What is wrong is said by the field it is about, and nothing is written; so
 * is a set of tags another link carries already ("Another link already uses
 * these tags: TL-12"), since the visits of one set are one link's.
 *
 * A link is edited only at the version its drawer was drawn from
 * (lock_version), and only its name once any visit came through it: its
 * address is locked so what it counted stays its own — a drawer drawn
 * before that first visit, changing more than the name, is refused and says
 * why. A duplicate is a new link made from another's fields.
 *
 * @phpstan-import-type LinkDraft from TrackingLinksQuery
 */
final class SaveTrackingLinkAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly TrackingLinksQuery $links) {}

    /**
     * @param  array<string, mixed>  $input  `name` and the fields TrackingLinksQuery::draft() reads
     * @param  int|null  $linkId  the link edited; none for a new one
     * @param  int|null  $lockVersion  the edited link's version as its drawer was drawn from
     *
     * @throws CannotManageTrafficException|ValidationException
     */
    public function handle(User $admin, ?int $linkId, array $input, ?int $lockVersion = null): TrackingLink
    {
        $draft = $this->links->draft($input, self::host());
        $name = is_string($input['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $input['name'])) : '';

        return DB::transaction(function () use ($admin, $linkId, $input, $draft, $name, $lockVersion): TrackingLink {
            $locked = $this->lockAdministrator($admin);

            if ($linkId === null) {
                return $this->create($locked, $draft, $name);
            }

            $link = TrackingLink::query()->whereKey($linkId)->lockForUpdate()->first();

            if ($link === null) {
                throw CannotManageTrafficException::becauseItIsGone('This link');
            }

            if ($lockVersion !== null && $link->lock_version !== $lockVersion) {
                throw CannotManageTrafficException::becauseItChanged('this link');
            }

            if ($this->links->hasVisits($link)) {
                return $this->rename($locked, $link, $input, $draft, $name);
            }

            $this->refuseWrong($draft, $name, $link->id);

            $link->forceFill([
                ...$this->columns($draft, $name),
                'updated_by_user_id' => $locked->id,
                'lock_version' => $link->lock_version + 1,
            ]);

            $this->write($link, $draft);

            return $link;
        });
    }

    /**
     * @param  LinkDraft  $draft
     */
    private function create(User $admin, array $draft, string $name): TrackingLink
    {
        $this->refuseWrong($draft, $name, null);
        $link = (new TrackingLink)->forceFill([
            ...$this->columns($draft, $name),
            'created_by_user_id' => $admin->id,
            'lock_version' => 1,
        ]);

        $this->write($link, $draft);

        return $link;
    }

    /**
     * Only the name of a link visits came through: anything else the drawer
     * changed is refused. Its game or page is compared as the drawer holds
     * it, since a game archived or a category put away since leaves the
     * link as it is.
     *
     * @param  array<string, mixed>  $input
     * @param  LinkDraft  $draft
     */
    private function rename(User $admin, TrackingLink $link, array $input, array $draft, string $name): TrackingLink
    {
        $leadsTheSame = match ($link->destination) {
            TrackingLinkDestination::Game => filter_var($input['game'] ?? null, FILTER_VALIDATE_INT) === $link->game_id,
            TrackingLinkDestination::Page => is_string($input['page'] ?? null) && trim($input['page']) === $link->path,
            TrackingLinkDestination::Custom => $draft['path'] === $link->path,
        };
        $same = $draft['destination'] === $link->destination && $leadsTheSame
            && $draft['tags'] === ['utm_source' => $link->utm_source, 'utm_medium' => $link->utm_medium, 'utm_campaign' => $link->utm_campaign, 'utm_content' => $link->utm_content, 'utm_term' => $link->utm_term];

        if (! $same) {
            throw CannotManageTrafficException::because($link->reference().' got its first visit after you opened it, so its address is locked and only its name can change. Nothing was saved.');
        }

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a link name.']);
        }

        if (mb_strlen($name) > TrackingLinkUrl::MAX_NAME) {
            throw ValidationException::withMessages(['name' => 'Up to '.TrackingLinkUrl::MAX_NAME.' characters.']);
        }

        $link->forceFill(['name' => $name, 'updated_by_user_id' => $admin->id, 'lock_version' => $link->lock_version + 1])->save();

        return $link;
    }

    /**
     * What is wrong with the draft, said by its fields; and a set of tags
     * another link carries, said by the content, which tells links apart.
     *
     * @param  LinkDraft  $draft
     *
     * @throws ValidationException
     */
    private function refuseWrong(array $draft, string $name, ?int $except): void
    {
        $errors = $draft['errors'];

        if (mb_strlen($name) > TrackingLinkUrl::MAX_NAME) {
            $errors['name'] = 'Up to '.TrackingLinkUrl::MAX_NAME.' characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $holding = $this->links->holding($draft['tags'], $except);

        if ($holding !== null) {
            throw self::duplicate($holding->reference());
        }
    }

    /**
     * The columns a draft without fault writes.
     *
     * @param  LinkDraft  $draft
     * @return array<string, mixed>
     */
    private function columns(array $draft, string $name): array
    {
        $tags = $draft['tags'];

        return [
            'name' => $name !== '' ? $name : TrackingLinkUrl::autoName($tags['utm_source'], $tags['utm_campaign'], $tags['utm_content']),
            'destination' => $draft['destination'],
            'game_id' => $draft['game_id'],
            'path' => (string) $draft['path'],
            ...$tags,
        ];
    }

    /**
     * Writes the row — in a savepoint of its own, so the transaction lives on
     * — a set of tags another link took meanwhile said as if it had been
     * there when asked.
     *
     * @param  LinkDraft  $draft
     *
     * @throws ValidationException
     */
    private function write(TrackingLink $link, array $draft): void
    {
        try {
            DB::transaction(fn () => $link->save());
        } catch (UniqueConstraintViolationException) {
            throw self::duplicate($this->links->holding($draft['tags'])?->reference() ?? 'another link');
        }
    }

    private static function duplicate(string $reference): ValidationException
    {
        return ValidationException::withMessages(['content' => 'Another link already uses these tags: '.$reference.'. Change the content or term to tell them apart.']);
    }

    /** The site's host, which an address typed in full must be on. */
    public static function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
