<?php

namespace Database\Seeders;

use App\Actions\Comments\AddCommentAction;
use App\Actions\Languages\WorkOutPostLanguageAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoLanguagePost;
use Database\Seeders\Support\DemoPlaythrough;
use Database\Seeders\Support\DemoPostMediaGenerator;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;

/**
 * A month of a multilingual site, to look at the feed with the viewer's language first
 * (docs/architecture/language-ranking.md): members who speak a dozen languages — English the most, Russian next, a
 * long tail after, some with no language on their account — posting in their own language, commenting and voting
 * mostly among their own. Every post, comment and vote goes through the action the site uses, the clock set back to
 * its moment, so each text gets its language as it would on the site. The texts come from
 * database/seeders/data/language-demo-texts.json; a share of them tell no language ("wow 😍", names).
 *
 *     php artisan db:seed --class=LanguageDemoSeeder
 *
 * About ten posts a day, as the site has now; BusyLanguageDemoSeeder writes a hundred. Local and testing
 * environments only, into a database of its own; run once — a second run adds nothing.
 */
class LanguageDemoSeeder extends Seeder
{
    private const SEED = 20261125;

    private const EMAIL_PREFIX = 'langdemo';

    /** @var list<array{id: int, language: string}> */
    private array $members = [];

    /** @var array<string, list<int>> member indexes by the language they write in */
    private array $community = [];

    /** @var array{languages: array<string, array{title: list<string>, description: list<string>, comment: list<string>}>, neutral: list<string>} */
    private array $texts;

    private int $pictures = 0;

    /** @var array<string, int> */
    private array $counts = ['members' => 0, 'posts' => 0, 'comments' => 0, 'votes' => 0];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (User::query()->where('email', 'like', self::EMAIL_PREFIX.'%@demo.test')->exists()) {
            $this->command?->info('The multilingual members are already here; nothing was added.');

            return;
        }

        $this->texts = json_decode((string) file_get_contents(database_path('seeders/data/language-demo-texts.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->limitInMemory();
        mt_srand(self::SEED);
        $until = CarbonImmutable::now('UTC')->startOfSecond();

        try {
            $this->members($until->subDays($this->days() + 30));
            $this->history($until);
        } finally {
            DemoPlaythrough::clock(null);
        }

        foreach (Post::query()->whereNotNull('language_stale_at')->pluck('id') as $id) {
            app(WorkOutPostLanguageAction::class)->handle((int) $id);
        }

        Artisan::call('posts:recalculate-hot-scores');

        $this->command?->info(collect($this->counts)->map(fn (int $count, string $what): string => number_format($count).' '.$what)->implode(', ').' added.');
    }

    protected function memberCount(): int
    {
        return 300;
    }

    protected function days(): int
    {
        return 30;
    }

    protected function postsPerDay(): int
    {
        return 10;
    }

    /**
     * The members: each with the language they write in, chosen by how many speak it (English the most), and the
     * language their account holds — chosen by themselves for some, a browser's guess for the rest, now and then
     * English instead; one in twelve holds none, and writes English.
     */
    private function members(CarbonImmutable $joined): void
    {
        $languages = array_keys($this->texts['languages']);
        $weights = array_map(fn (int $rank): float => 1 / ($rank + 1) ** 0.9, array_keys($languages));
        $password = Hash::make('password');

        for ($i = 1; $i <= $this->memberCount(); $i++) {
            $none = mt_rand(1, 12) === 1;
            $language = $none ? $languages[0] : $languages[self::weighted($weights)];
            $locale = $none ? null : (mt_rand(1, 100) <= 45 || mt_rand(1, 100) <= 80 ? $language : $languages[0]);

            $user = new User;
            $user->forceFill([
                'name' => 'Speaker '.$i,
                'username' => 'lang_demo_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'email' => self::EMAIL_PREFIX.$i.'@demo.test',
                'password' => $password,
                'role' => UserRole::User,
                'status' => UserStatus::Active,
                'locale' => $locale,
                'email_verified_at' => $joined,
                'created_at' => $joined,
                'updated_at' => $joined,
            ])->save();

            $this->members[] = ['id' => (int) $user->id, 'language' => $language];
            $this->community[$language][] = count($this->members) - 1;
            $this->counts['members']++;
        }
    }

    /** Every post of the month with its comments and votes, each at its moment, in the order they happened. */
    private function history(CarbonImmutable $until): void
    {
        $events = [];

        for ($day = $this->days(); $day >= 0; $day--) {
            for ($n = 0; $n < $this->postsPerDay(); $n++) {
                $at = $until->subDays($day)->startOfDay()->addSeconds(mt_rand(0, 86399));

                if ($at->greaterThan($until)) {
                    continue;
                }

                $quality = exp(self::normal() * 0.8) / 1.377;
                $post = new DemoLanguagePost(mt_rand(0, count($this->members) - 1));
                $events[] = [$at, 'post', $post];

                for ($c = min(30, (int) (self::exponential(4) * $quality)); $c > 0; $c--) {
                    $events[] = [$this->later($at, 6, $until), 'comment', $post];
                }

                for ($v = min(120, (int) (self::exponential(12) * $quality)); $v > 0; $v--) {
                    $events[] = [$this->later($at, 4, $until), 'vote', $post];
                }
            }
        }

        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($events as [$at, $kind, $post]) {
            DemoPlaythrough::clock($at);

            try {
                match ($kind) {
                    'post' => $this->post($post, $at),
                    'comment' => $post->id === null ? null : $this->comment($post, $at),
                    'vote' => $post->id === null ? null : $this->vote($post, $at),
                };
            } catch (CannotCreatePostException|CannotCommentException|CannotVoteException) {
                // Refused as the site refuses it — a rate limit, say: left out. Anything else stops the run: start
                // again from a fresh database (migrate:fresh), as a run stopped half way leaves half a month.
            }
        }
    }

    private function post(DemoLanguagePost $post, CarbonImmutable $at): void
    {
        $author = $this->members[$post->author];
        $texts = $this->texts['languages'][$author['language']];
        $this->pictures++;
        $picture = app(DemoPostMediaGenerator::class)->create('demo/languages/post-'.$this->pictures.'.svg', $this->pictures);

        $created = app(CreatePostAction::class)->handle(User::query()->findOrFail($author['id']), new CreatePostData(
            title: mt_rand(1, 100) <= 70 ? self::pick($texts['title']) : self::pick($this->texts['neutral']),
            description: mt_rand(1, 100) <= 35 ? self::pick($texts['description']) : null,
            imageAssetId: (int) $picture->id,
            createdAt: $at,
            updatedAt: $at,
        ));

        $post->id = (int) $created->id;
        $this->counts['posts']++;
    }

    private function comment(DemoLanguagePost $post, CarbonImmutable $at): void
    {
        $writer = $this->members[$this->someone($post)];
        $body = mt_rand(1, 100) <= 80 ? self::pick($this->texts['languages'][$writer['language']]['comment']) : self::pick($this->texts['neutral']);

        app(AddCommentAction::class)->handle(User::query()->findOrFail($writer['id']), Post::query()->findOrFail($post->id), $body, null, $at);
        $this->counts['comments']++;
    }

    private function vote(DemoLanguagePost $post, CarbonImmutable $at): void
    {
        $voter = $this->members[$this->someone($post)]['id'];

        // A second vote by the same member would take the first back.
        if (in_array($voter, $post->voters, true)) {
            return;
        }

        app(VotePostAction::class)->handle(User::query()->findOrFail($voter), Post::query()->findOrFail($post->id), mt_rand(1, 100) <= 80 ? VoteType::Up : VoteType::Down, $at);
        $post->voters[] = $voter;
        $this->counts['votes']++;
    }

    /** Someone reading a post: mostly one of the author's own language. */
    private function someone(DemoLanguagePost $post): int
    {
        $own = $this->community[$this->members[$post->author]['language']] ?? [];

        return $own !== [] && mt_rand(1, 100) <= 70 ? $own[mt_rand(0, count($own) - 1)] : mt_rand(0, count($this->members) - 1);
    }

    private function later(CarbonImmutable $at, float $meanHours, CarbonImmutable $until): CarbonImmutable
    {
        $later = $at->addSeconds((int) (self::exponential($meanHours) * 3600) + 60);

        return $later->greaterThan($until) ? $until : $later;
    }

    /** Rate limits kept in memory: a history played in a few minutes would otherwise fill the cache's. */
    private function limitInMemory(): void
    {
        config(['cache.limiter' => 'array']);
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
    }

    /** @param list<string> $items */
    private static function pick(array $items): string
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @param list<float> $weights */
    private static function weighted(array $weights): int
    {
        $roll = mt_rand() / mt_getrandmax() * array_sum($weights);

        foreach ($weights as $index => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $index;
            }
        }

        return array_key_last($weights);
    }

    private static function exponential(float $mean): float
    {
        return -$mean * log(1 - mt_rand() / (mt_getrandmax() + 1));
    }

    private static function normal(): float
    {
        return sqrt(-2 * log(1 - mt_rand() / (mt_getrandmax() + 1))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }
}
