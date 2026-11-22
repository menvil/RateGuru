<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\AnalyticsDimension;
use App\Enums\GameStatus;
use App\Enums\TrackingLinkDestination;
use App\Enums\TrafficChannel;
use App\Models\Category;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\TrafficVisit;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\TrackingLinkUrl;
use App\Support\Analytics\UtmTags;
use App\Support\Analytics\VisitorFigures;
use App\Support\Database\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tracking links of Analytics → Tracking links and what they brought.
 * A visit is a link's when its session came by the link's five tags — the
 * tags a session's first visit carried are copied onto every visit that
 * continues it, so a game started or an account made after a link to the
 * feed is the link's too, as Campaigns counts it. A link's content or term
 * of '' is a visit's none.
 *
 *  - list()     the links the search and the page's source and campaign
 *               admit, the newest first, each with whether any visit ever
 *               came through it;
 *  - figures()  the people of the filter each link brought;
 *  - hasVisits() whether any visit ever came through a link: what locks it;
 *  - holding()  the link that already carries a set of tags;
 *  - draft()    a link as its drawer holds it, read: where it leads, its
 *               tags, and what is wrong with any of it;
 *  - pages(), games()  the destinations a link can lead to.
 *
 * Read-only.
 *
 * @phpstan-type LinkTags array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string}
 * @phpstan-type LinkDraft array{destination: TrackingLinkDestination, game_id: int|null, path: string|null, tags: LinkTags, errors: array<string, string>}
 */
final class TrackingLinksQuery implements RawSqlPersistenceBoundary
{
    /** How many links the list holds at most, the newest first: a search finds the rest. */
    public const int MOST_LISTED = 200;

    /**
     * The ways a visit's content and term can be a link's (anyVisit()), by
     * the column each is read as: whether the content is the link's '' and
     * the visit's none rather than the same text, and whether the term is.
     *
     * @var array<string, array{bool, bool}>
     */
    private const array VISIT_FORMS = [
        'visit_same' => [false, false],
        'visit_no_term' => [false, true],
        'visit_no_content' => [true, false],
        'visit_no_tags' => [true, true],
    ];

    public function __construct(private readonly AnalyticsVisitsQuery $visits) {}

    /**
     * The links whose name, source or tags hold the search — any case — and
     * that carry the source and campaign the filter narrows to, the newest
     * first, at most MOST_LISTED of them; with how many there are, and for
     * each link listed the id of a visit that came through it (`visit_id`),
     * none while none came.
     *
     * @return array{links: Collection<int, TrackingLink>, total: int}
     */
    public function list(string $search, AnalyticsFilter $filter): array
    {
        $search = trim($search);
        $campaign = $filter->campaignKey === null ? null : CampaignKey::label($filter->campaignKey);

        // A paid campaign is no link's.
        if ($filter->campaignKey !== null && CampaignKey::paidCampaignId($filter->campaignKey) !== null) {
            return ['links' => new Collection, 'total' => 0];
        }

        $links = TrackingLink::query()
            ->when($campaign !== null, fn (Builder $query) => $query->where('utm_campaign', $campaign))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = LikePattern::containing($search);

                $query->where(fn (Builder $matching) => $matching
                    ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(utm_source) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(utm_campaign) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(utm_content) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(utm_term) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // A link's source is the key its tags give a session, as a visit of it is keyed.
        if ($filter->sourceKey !== null) {
            $links = $links->filter(fn (TrackingLink $link): bool => self::sourceKey($link) === $filter->sourceKey)->values();
        }

        $total = $links->count();
        $links = $links->take(self::MOST_LISTED)->values();

        // Only the links listed are completed: their game, their maker, and whether a visit came.
        if ($links->isNotEmpty()) {
            $links->load(['game:id,title,slug,status', 'createdBy:id,name,username,status']);
            $visited = [];

            foreach (TrackingLink::query()->whereKey($links->modelKeys())->select('tracking_links.id')->addSelect(self::anyVisit())->toBase()->get() as $row) {
                $row = (array) $row;
                $id = (int) $row['id'];
                $visited[$id] = null;

                foreach (array_keys(self::VISIT_FORMS) as $form) {
                    $visited[$id] ??= $row[$form];
                }
            }

            $links->each(fn (TrackingLink $link) => $link->setAttribute('visit_id', $visited[$link->id] ?? null));
        }

        return ['links' => $links, 'total' => $total];
    }

    /**
     * The people of the filter each link given brought, by the link's id: a
     * link nobody came through in it is left out.
     *
     * @param  iterable<TrackingLink>  $links
     * @return array<int, VisitorFigures>
     */
    public function figures(iterable $links, AnalyticsFilter $filter): array
    {
        $links = is_array($links) ? $links : iterator_to_array($links, false);
        $byTags = [];

        foreach ($links as $link) {
            $byTags[self::tuple($link->utm_campaign, $link->utm_source, $link->utm_medium, $link->utm_content, $link->utm_term)] = (int) $link->id;
        }

        if ($byTags === []) {
            return [];
        }

        $campaigns = [];
        $own = [];

        // Only the links' own sets of tags are grouped: a link's '' is a visit's none.
        foreach ($links as $link) {
            $campaigns[$link->utm_campaign] = true;
            $own[] = [
                'utm_campaign' => $link->utm_campaign,
                'utm_source' => $link->utm_source,
                'utm_medium' => $link->utm_medium,
                'utm_content' => $link->utm_content === '' ? null : $link->utm_content,
                'utm_term' => $link->utm_term === '' ? null : $link->utm_term,
            ];
        }

        $figures = [];

        foreach ($this->visits->among($filter, AnalyticsDimension::Tags, array_keys($campaigns), $own) as $group) {
            $keys = $group['keys'];
            $id = $byTags[self::tuple(...array_map(fn (int|string|null $value): string => (string) $value, array_values($keys)))] ?? null;

            if ($id !== null) {
                $figures[$id] = $group['figures'];
            }
        }

        return $figures;
    }

    /** Whether any visit ever came through the link, its page run or not, whatever the period. */
    public function hasVisits(TrackingLink $link): bool
    {
        return self::carrying(TrafficVisit::query(), $link->utm_source, $link->utm_medium, $link->utm_campaign, $link->utm_content, $link->utm_term)->exists();
    }

    /**
     * The link that carries the tags given already — besides the one named —
     * if any: no two links may.
     *
     * @param  LinkTags  $tags
     */
    public function holding(array $tags, ?int $except = null): ?TrackingLink
    {
        return TrackingLink::query()
            ->where('utm_source', $tags['utm_source'])
            ->where('utm_medium', $tags['utm_medium'])
            ->where('utm_campaign', $tags['utm_campaign'])
            ->where('utm_content', $tags['utm_content'])
            ->where('utm_term', $tags['utm_term'])
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
            ->first();
    }

    /**
     * A link as its drawer holds it — `destination`, `game`, `page`, `custom`,
     * `source` (one of TrackingLinkUrl::SOURCES, or Other with
     * `source_custom`), `medium`, `campaign`, `content`, `term` — read as
     * the reference reads it: where it leads (a game not archived, a page
     * offered, a path or address of the site's own), its five tags (the
     * source and campaign lower-case letters, digits, - and _, the content
     * and term kept in lower case, none of them a network's placeholder a
     * visit wouldn't keep) and what is wrong with any of it, by the field it
     * is about. What can be read is read even where another field is wrong,
     * so the address can be drawn as soon as its destination is known.
     *
     * @param  array<string, mixed>  $input
     * @param  string  $host  the site's host, which an address typed in full must be on
     * @return LinkDraft
     */
    public function draft(array $input, string $host): array
    {
        $text = fn (string $key, bool $lower = false): string => self::text($input[$key] ?? null, $lower);
        $destination = TrackingLinkDestination::tryFrom($text('destination')) ?? TrackingLinkDestination::Game;
        $errors = [];
        $gameId = null;
        $path = null;

        if ($destination === TrackingLinkDestination::Game) {
            $id = filter_var($input['game'] ?? null, FILTER_VALIDATE_INT);
            $game = is_int($id) ? $this->games()->firstWhere('id', $id) : null;

            if ($game instanceof Game) {
                [$gameId, $path] = [(int) $game->id, TrackingLinkUrl::gamePath($game->slug)];
            } else {
                $errors['destination'] = 'Choose a game.';
            }
        } elseif ($destination === TrackingLinkDestination::Page) {
            $page = $text('page');

            if (array_key_exists($page, $this->pages())) {
                $path = $page;
            } else {
                $errors['destination'] = 'Choose a page.';
            }
        } else {
            ['path' => $path, 'error' => $error] = TrackingLinkUrl::customPath($text('custom'), $host);

            if ($error !== null) {
                $errors['destination'] = $error;
            }
        }

        $picked = $text('source');
        $source = $picked === TrackingLinkUrl::OTHER_SOURCE ? $text('source_custom', lower: true) : (array_key_exists($picked, TrackingLinkUrl::SOURCES) ? $picked : '');
        $medium = in_array($text('medium'), TrackingLinkUrl::MEDIUMS, true) ? $text('medium') : '';
        $tags = [
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $text('campaign'),
            'utm_content' => $text('content', lower: true),
            'utm_term' => $text('term', lower: true),
        ];

        $errors['source'] = match (true) {
            $picked === '' || ($picked !== TrackingLinkUrl::OTHER_SOURCE && $source === '') => 'Choose a source.',
            $source === '' => 'Enter a source name.',
            ! TrackingLinkUrl::isToken($source) => 'Use lowercase letters, numbers, - or _ for the source.',
            mb_strlen($source) > TrackingLinkUrl::MAX_SOURCE => 'Up to '.TrackingLinkUrl::MAX_SOURCE.' characters.',
            default => null,
        };
        $errors['medium'] = $medium === '' ? 'Choose a medium.' : null;
        $errors['campaign'] = match (true) {
            $tags['utm_campaign'] === '' => 'Enter a campaign identifier.',
            ! TrackingLinkUrl::isToken($tags['utm_campaign']) => 'Use lowercase letters, numbers, - or _ with no spaces.',
            mb_strlen($tags['utm_campaign']) > TrackingLinkUrl::MAX_TAG => 'Up to '.TrackingLinkUrl::MAX_TAG.' characters.',
            default => null,
        };

        foreach (['content' => 'utm_content', 'term' => 'utm_term'] as $field => $tag) {
            $errors[$field] = match (true) {
                $tags[$tag] === '' => null,
                mb_strlen($tags[$tag]) > TrackingLinkUrl::MAX_TAG => 'Up to '.TrackingLinkUrl::MAX_TAG.' characters.',
                UtmTags::clean($tags[$tag]) !== $tags[$tag] => 'This reads as an ad network’s placeholder, which a visit doesn’t keep. Use plain text.',
                default => null,
            };
        }

        return ['destination' => $destination, 'game_id' => $gameId, 'path' => $path, 'tags' => $tags, 'errors' => array_filter($errors, fn (?string $error): bool => $error !== null)];
    }

    /**
     * The site's pages a link can lead to, by path: the home feed, its hot
     * and top posts, and each active category's posts.
     *
     * @return array<string, string>
     */
    public function pages(): array
    {
        $pages = ['/' => 'Home feed', '/?sort=hot' => 'Hot posts', '/?sort=top' => 'Top posts'];

        foreach (Category::query()->active()->ordered()->get(['id', 'slug', 'name']) as $category) {
            $pages['/?'.http_build_query(['category' => [$category->slug]])] = 'Category · '.$category->name;
        }

        return $pages;
    }

    /**
     * The games a link can lead to: every one not archived, by title.
     *
     * @return Collection<int, Game>
     */
    public function games(): Collection
    {
        return Game::query()->where('status', '!=', GameStatus::Archived->value)->orderBy('title')->orderBy('id')->get(['id', 'title', 'slug', 'status']);
    }

    /** The key a link's tags give the source of a session that came by them (AnalyticsSource). */
    public static function sourceKey(TrackingLink $link): string
    {
        return AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, $link->utm_source, $link->utm_medium);
    }

    /**
     * The id of a visit — any one — that came through the tracking link the
     * query reads, as the four columns VISIT_FORMS names: each holds a visit
     * that came by its way, and all four are none while no visit came.
     *
     * A visit's content is the link's when it is the same text, or when the
     * link's is '' and the visit's none, and so is its term. Asked as one
     * condition, the two ORs keep the five tags from being looked up in
     * their index, and every visit of the campaign is read instead. Taken
     * apart into the four ways they combine, each way names all five tags
     * of the visit — the link's, or none — so it is one look at the index.
     * Together the four hold exactly the visits the one condition holds.
     *
     * @return array<string, Builder<TrafficVisit>>
     */
    private static function anyVisit(): array
    {
        $forms = [];

        foreach (self::VISIT_FORMS as $form => [$noContent, $noTerm]) {
            $visits = TrafficVisit::query()
                ->select('traffic_visits.id')
                ->whereColumn('traffic_visits.utm_campaign', 'tracking_links.utm_campaign')
                ->whereColumn('traffic_visits.utm_source', 'tracking_links.utm_source')
                ->whereColumn('traffic_visits.utm_medium', 'tracking_links.utm_medium');

            foreach (['utm_content' => $noContent, 'utm_term' => $noTerm] as $column => $none) {
                $none
                    ? $visits->where('tracking_links.'.$column, '')->whereNull('traffic_visits.'.$column)
                    : $visits->whereColumn('traffic_visits.'.$column, 'tracking_links.'.$column);
            }

            $forms[$form] = $visits->limit(1);
        }

        return $forms;
    }

    /**
     * The visits of a session that came by the tags given.
     *
     * @param  Builder<TrafficVisit>  $visits
     * @return Builder<TrafficVisit>
     */
    private static function carrying(Builder $visits, string $source, string $medium, string $campaign, string $content, string $term): Builder
    {
        $visits->where('utm_campaign', $campaign)->where('utm_source', $source)->where('utm_medium', $medium);

        foreach (['utm_content' => $content, 'utm_term' => $term] as $column => $value) {
            $value === '' ? $visits->whereNull($column) : $visits->where($column, $value);
        }

        return $visits;
    }

    /** A value of the drawer as text: trimmed, without control characters, and — asked — in lower case. */
    private static function text(mixed $value, bool $lower = false): string
    {
        $value = is_string($value) || is_int($value) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value)) : '';

        return $lower ? mb_strtolower($value) : $value;
    }

    /** One set of tags as a key, in the order AnalyticsDimension::Tags names them; none is ''. */
    private static function tuple(string $campaign, string $source, string $medium, string $content, string $term): string
    {
        return (string) json_encode([$campaign, $source, $medium, $content, $term], JSON_UNESCAPED_UNICODE);
    }
}
