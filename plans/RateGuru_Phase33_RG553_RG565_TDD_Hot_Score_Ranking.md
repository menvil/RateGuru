# RateGuru — Phase 33 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 33 — Hot Score & Ranking**  
Диапазон задач: **RG-553 → RG-565**  
Основа нумерации: исходный atomic backlog, где Phase 33 начинается с задачи 553 и заканчивается задачей 565.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 33 соответствует исходному блоку:

```txt
Phase 33 — Hot Score & Ranking
```

Правильный диапазон Phase 33:

```txt
RG-553 — Create HotScoreCalculator
RG-554 — Test hot score increases with upvotes
RG-555 — Implement upvote contribution
RG-556 — Test hot score decreases with age
RG-557 — Implement age decay
RG-558 — Test comments affect hot score
RG-559 — Implement comments contribution
RG-560 — Create RecalculatePostScoreAction
RG-561 — Test score recalculation stores hot_score
RG-562 — Dispatch score recalculation after vote
RG-563 — Dispatch score recalculation after comment
RG-564 — Add command to recalculate all hot scores
RG-565 — Test recalculate hot scores command
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 32 закончилась на `RG-552`, а Phase 34 начинается с `RG-566`. Значит Phase 33 строго занимает `RG-553 → RG-565`.
---

# 2. Цель Phase 33

Phase 33 делает нормальный ranking backend для сортировки feed по `hot`.

До этой фазы `hot` мог быть placeholder sorting по колонке `hot_score`, но сама логика score ещё не была полноценной.

После Phase 33 должно быть готово:

```txt
- HotScoreCalculator;
- score растёт от upvotes;
- score падает с возрастом;
- comments повышают score;
- RecalculatePostScoreAction;
- сохранение hot_score в posts.hot_score;
- автоматический пересчёт после vote;
- автоматический пересчёт после comment;
- artisan command для пересчёта всех hot scores;
- тесты на calculator, action, dispatch и command.
```

Это backend/ranking-фаза. UI менять не надо, если FeedQuery уже умеет сортировать по `hot_score`.
---

# 3. Scope Phase 33

## Входит

```txt
- HotScoreCalculator;
- формула score;
- unit tests formula behavior;
- RecalculatePostScoreAction;
- хранение score в posts.hot_score;
- пересчёт score после vote;
- пересчёт score после comment;
- artisan command для full backfill;
- tests for command.
```

## Не входит

```txt
- новый SortDropdown UI;
- новый Feed UI;
- visual indicators "hot";
- trending page;
- machine learning ranking;
- personalized feed;
- Redis/cache ranking;
- scheduled job;
- queue worker;
- ranking analytics dashboard;
- admin controls for formula weights.
```

Phase 33 не должна становиться рекомендательной системой. Это простая deterministic ranking formula.
---

# 4. Critical Decisions

## 4.1. Hot score должен быть deterministic

Одинаковые inputs должны давать одинаковый score.

Плохой вариант:

```php
return rand();
```

или формула, зависящая от текущего времени без явного параметра.

Правильный вариант:

```php
$calculator->calculate(
    upvotes: 10,
    downvotes: 2,
    commentsCount: 5,
    createdAt: $post->created_at,
    now: $fixedNow,
);
```

`now` должен передаваться явно или через clock abstraction, чтобы тесты были стабильными.

## 4.2. Formula должна быть простой

MVP formula:

```txt
engagement_score = max(0, upvotes_count - downvotes_count)
comment_score = comments_count * comment_weight
age_hours = max(0, created_at.diffInHours(now))
hot_score = (engagement_score + comment_score + base) / pow(age_hours + 2, gravity)
```

Recommended constants:

```txt
base = 1.0
comment_weight = 0.5
gravity = 1.5
```

Почему так:

```txt
- upvotes дают основной сигнал качества;
- comments добавляют сигнал обсуждения, но слабее upvotes;
- age decay постепенно опускает старые посты;
- base не даёт score = 0 для нового поста без голосов;
- +2 hours сглаживает первые часы и предотвращает деление на 0.
```

Не надо делать Reddit/HackerNews-perfect formula. Для MVP нужна понятная, тестируемая, стабильная логика.

## 4.3. Downvotes

Backlog не упоминает downvotes явно, но RateGuru уже имеет up/down voting. Поэтому formula должна учитывать downvotes.

Recommended:

```txt
net_votes = upvotes_count - downvotes_count
engagement_score = max(0, net_votes)
```

Почему `max(0, net_votes)`:

```txt
- отрицательные scores могут ломать сортировку;
- сильно заминусованные посты должны падать к нулю;
- hidden/rejected posts всё равно не должны попадать в public feed.
```

Если хочется штрафовать downvotes сильнее, это отдельная tuning-задача.

## 4.4. Comments contribution

Comments должны влиять, но слабее голосов.

Recommended:

```txt
comments_count * 0.5
```

Почему не 1:1:

```txt
- комментарии легко спамить;
- один спорный пост может набрать много комментариев, но быть плохим;
- reports/moderation позже отдельно решают токсичность.
```

## 4.5. Age decay

Age decay должен гарантировать:

```txt
один и тот же post с теми же votes/comments имеет меньший score через время.
```

Нельзя делать age bonus.

Recommended:

```txt
score / pow(age_hours + 2, 1.5)
```

## 4.6. Store hot_score as denormalized column

Feed sorting должен быть дешёвым:

```sql
ORDER BY hot_score DESC
```

Поэтому score хранится в:

```txt
posts.hot_score
```

Если колонка уже есть из Phase 7 placeholder — использовать её.  
Если отсутствует — RG-560/RG-561 должны добавить migration.

Не вычислять score on-the-fly для каждой feed query.

## 4.7. When to recalculate

Минимальные triggers:

```txt
after vote
after comment
```

Backlog требует именно это.

Дополнительно score уменьшается со временем, значит нужен full recalculation command:

```txt
php artisan posts:recalculate-hot-scores
```

Почему command нужен:

```txt
- age decay меняется даже без новых votes/comments;
- backfill после formula change;
- production maintenance.
```

Scheduled job не входит в Phase 33.  
Команду можно запускать вручную.

## 4.8. Recalculation should not publish/modify status

`RecalculatePostScoreAction` должен менять только:

```txt
hot_score
```

Нельзя менять:

```txt
status
reports_count
votes_count
comments_count
published_at
```

## 4.9. Hidden/pending/rejected posts

Можно пересчитывать score для всех posts, но public feed всё равно показывает только published.

Command recommendation:

```txt
default: recalculate all posts
option --published-only: recalculate only published posts
```

Но backlog не требует option. Минимально:

```txt
recalculate all posts
```

Score itself is not a visibility rule.

## 4.10. Dispatch after vote/comment should be synchronous

Phase 33 не должна требовать queue.

After vote/comment:

```txt
call RecalculatePostScoreAction synchronously
```

Почему:

```txt
- SQLite/local MVP;
- tests simpler;
- no Redis/queue worker requirement;
- score обновляется сразу.
```

Queue можно добавить позже в performance/deployment phase.
---

# 5. Architecture Rules

## 5.1. Calculator is pure

`HotScoreCalculator` не должен знать про Eloquent.

Wrong:

```php
public function calculate(Post $post): float
```

Better:

```php
public function calculate(
    int $upvotes,
    int $downvotes,
    int $commentsCount,
    CarbonInterface $createdAt,
    CarbonInterface $now,
): float
```

This makes formula testable.

## 5.2. Action owns Eloquent integration

`RecalculatePostScoreAction` берёт `Post`, читает counters, вызывает calculator, сохраняет `hot_score`.

```php
$score = $calculator->calculate(
    upvotes: $post->upvotes_count,
    downvotes: $post->downvotes_count,
    commentsCount: $post->comments_count,
    createdAt: $post->created_at,
    now: now(),
);

$post->forceFill(['hot_score' => $score])->save();
```

## 5.3. Vote/comment actions call recalculation action

Wrong:

```php
$post->hot_score = ...
```

inside `VotePostAction`.

Correct:

```php
app(RecalculatePostScoreAction::class)->handle($post->fresh());
```

## 5.4. Command should chunk

Full recalculation command must not load all posts at once.

Use:

```php
Post::query()->chunkById(500, function ($posts) { ... });
```

or `lazyById()`.

Even if MVP has small data, this avoids future memory problems.

## 5.5. Tests should freeze time

Use:

```php
Carbon::setTestNow(...)
```

or Laravel travel helpers:

```php
$this->travelTo(...)
```

Do not assert formula behavior against moving `now()`.

## 5.6. FeedQuery should already sort by hot_score

Phase 7 had hot sorting placeholder. Phase 33 should not rewrite FeedQuery unless existing hot sort is broken.

Acceptance check:

```txt
SortDropdown hot → FeedQuery hot → ORDER BY hot_score DESC
```

If not already true, fix minimally.
---

# 6. Suggested Formula

Recommended initial formula:

```php
$netVotes = max(0, $upvotes - $downvotes);
$commentContribution = $commentsCount * 0.5;
$raw = 1.0 + $netVotes + $commentContribution;

$ageHours = max(0.0, $createdAt->floatDiffInHours($now));
$decay = pow($ageHours + 2.0, 1.5);

return round($raw / $decay, 6);
```

Examples:

```txt
new post, 0 votes, 0 comments:
1 / 2^1.5 ≈ 0.353553

new post, 10 upvotes, 0 comments:
11 / 2^1.5 ≈ 3.889087

24h old post, 10 upvotes:
11 / 26^1.5 ≈ 0.08297
```

Do not overfit these constants. They are a sane starting point.
---

# 7. GitFlow для Phase 33

## Base branch

Все задачи Phase 33 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-553-create-hot-score-calculator
feature/RG-560-create-recalculate-post-score-action
feature/RG-565-test-recalculate-hot-scores-command
```

## Commit format

```txt
RG-553: Create HotScoreCalculator
RG-560: Create RecalculatePostScoreAction
RG-565: Test recalculate hot scores command
```

## Release branch

После выполнения `RG-553`–`RG-565`:

```txt
release/v0.2.14-phase33-hot-score-ranking
```

## Tag

После merge release branch в `main`:

```txt
v0.2.14-phase33-hot-score-ranking
```

Почему `v0.2.14`: Phase 32 использует `v0.2.13`, Phase 34 — `v0.2.15`, значит Phase 33 логично получает `v0.2.14`.
---

# 8. TDD Rules for Phase 33

## Для calculator

Тестировать свойства, а не только конкретное число:

```txt
- score increases with upvotes;
- score decreases with age;
- score increases with comments;
- downvotes reduce net contribution;
- score is never negative;
- same inputs produce same score.
```

Можно также проверять точные значения при frozen time, но property tests важнее.

## Для RecalculatePostScoreAction

Тестировать:

```txt
- action stores hot_score;
- action uses post counters;
- action only mutates hot_score;
- action works for posts with zero counters.
```

## Для dispatch after vote/comment

Тестировать:

```txt
- vote changes hot_score;
- comment changes hot_score;
- failed vote/comment does not recalculate score unnecessarily.
```

## Для command

Тестировать:

```txt
- command recalculates all posts;
- command outputs useful summary;
- command chunks or can handle multiple posts;
- command returns success exit code.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Ranking / Hot Score / Tests
Type: Test / Feature / Calculator / Action / Command
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым. Если тест напрямую невозможен:
No direct test — причина.

Implementation:
Что именно меняем.

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Тест написан первым, если задача тестируемая
- Тест падает до реализации, если применимо
- Реализация минимальная
- Тест проходит
- Calculator remains pure
- No queue/Redis dependency introduced
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 33 Atomic Tasks
---

## RG-553 — Create HotScoreCalculator

**Area:** Ranking / Calculator  
**Type:** Calculator  
**Priority:** P0  
**Branch:** `feature/RG-553-create-hot-score-calculator`  
**Base branch:** develop
**Depends on:** RG-552

### Goal

Создать pure calculator для hot score.

### TDD step

Unit/skeleton test:

```php
it('has hot score calculator with calculate method', function () {
    $calculator = app(HotScoreCalculator::class);

    expect(method_exists($calculator, 'calculate'))->toBeTrue();
});
```

Тест должен упасть до создания класса.

### Implementation

Создать:

```txt
app/Support/Ranking/HotScoreCalculator.php
```

Skeleton:

```php
namespace App\Support\Ranking;

use Carbon\CarbonInterface;

final class HotScoreCalculator
{
    public function calculate(
        int $upvotes,
        int $downvotes,
        int $commentsCount,
        CarbonInterface $createdAt,
        CarbonInterface $now,
    ): float {
        return 0.0;
    }
}
```

Не добавлять Eloquent dependency.

### Acceptance criteria

- `HotScoreCalculator` exists.
- `calculate(...)` method exists.
- Method accepts counters + timestamps.
- Class resolves from container.
- No Eloquent/Post dependency.
- Skeleton test passes.

### Definition of Done

- Test written first.
- Calculator created.
- Test passes.
- Коммит: `RG-553: Create HotScoreCalculator`

### Files likely touched

```txt
app/Support/Ranking/HotScoreCalculator.php
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-554 — Test Hot Score Increases With Upvotes

**Area:** Ranking / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-554-test-hot-score-increases-with-upvotes`  
**Base branch:** develop
**Depends on:** RG-553

### Goal

Написать падающий тест: при прочих равных score растёт с upvotes.

### TDD step

Unit test:

```php
it('increases hot score with upvotes', function () {
    $calculator = app(HotScoreCalculator::class);

    $createdAt = CarbonImmutable::parse('2026-05-14 10:00:00');
    $now = CarbonImmutable::parse('2026-05-14 12:00:00');

    $lowScore = $calculator->calculate(
        upvotes: 1,
        downvotes: 0,
        commentsCount: 0,
        createdAt: $createdAt,
        now: $now,
    );

    $highScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: $createdAt,
        now: $now,
    );

    expect($highScore)->toBeGreaterThan($lowScore);
});
```

Downvote sanity test optional:

```php
it('does not allow downvotes to make hot score negative', ...)
```

### Implementation

Только добавить тест. Он должен упасть, если calculator возвращает 0.0.

### Acceptance criteria

- Test proves monotonic upvote contribution.
- Same age/comments/downvotes.
- Test fails before implementation.
- No formula implementation in this task.

### Definition of Done

- Test written.
- Test expected to fail before RG-555.
- Коммит: `RG-554: Test hot score increases with upvotes`

### Files likely touched

```txt
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-555 — Implement Upvote Contribution

**Area:** Ranking / Calculator  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-555-implement-upvote-contribution`  
**Base branch:** develop
**Depends on:** RG-554

### Goal

Реализовать contribution от upvotes/downvotes.

### TDD step

Использовать падающий тест из RG-554.

### Implementation

В `HotScoreCalculator`:

```php
public function calculate(...): float
{
    $netVotes = max(0, $upvotes - $downvotes);

    $raw = 1.0 + $netVotes;

    return round($raw, 6);
}
```

На этой задаче ещё не добавляем age decay/comments contribution.  
Да, это временно неполная формула — дальше её расширят RG-557/RG-559.

Add guards:

```php
$upvotes = max(0, $upvotes);
$downvotes = max(0, $downvotes);
$commentsCount = max(0, $commentsCount);
```

### Acceptance criteria

- Score increases with upvotes.
- Downvotes reduce net score.
- Score never negative.
- Calculator remains pure.
- Tests pass.

### Definition of Done

- Upvote/net vote logic implemented.
- Tests pass.
- Коммит: `RG-555: Implement upvote contribution`

### Files likely touched

```txt
app/Support/Ranking/HotScoreCalculator.php
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-556 — Test Hot Score Decreases With Age

**Area:** Ranking / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-556-test-hot-score-decreases-with-age`  
**Base branch:** develop
**Depends on:** RG-555

### Goal

Написать падающий тест: score падает с возрастом поста при одинаковых votes/comments.

### TDD step

Unit test:

```php
it('decreases hot score with age', function () {
    $calculator = app(HotScoreCalculator::class);

    $now = CarbonImmutable::parse('2026-05-14 12:00:00');

    $newScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: CarbonImmutable::parse('2026-05-14 11:00:00'),
        now: $now,
    );

    $oldScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: CarbonImmutable::parse('2026-05-13 12:00:00'),
        now: $now,
    );

    expect($oldScore)->toBeLessThan($newScore);
});
```

Zero age safety test:

```php
it('handles newly created posts without division by zero', ...)
```

### Implementation

Только добавить тест. Он должен упасть до age decay implementation.

### Acceptance criteria

- Test proves older post scores lower.
- Same votes/comments.
- Frozen now.
- Test fails before RG-557.

### Definition of Done

- Test written.
- Test expected to fail before implementation.
- Коммит: `RG-556: Test hot score decreases with age`

### Files likely touched

```txt
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-557 — Implement Age Decay

**Area:** Ranking / Calculator  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-557-implement-age-decay`  
**Base branch:** develop
**Depends on:** RG-556

### Goal

Реализовать age decay в формуле hot score.

### TDD step

Использовать падающий тест из RG-556.

### Implementation

Update formula:

```php
$netVotes = max(0, max(0, $upvotes) - max(0, $downvotes));
$raw = 1.0 + $netVotes;

$ageHours = max(0.0, $createdAt->floatDiffInHours($now, false));

if ($createdAt->greaterThan($now)) {
    $ageHours = 0.0;
}

$decay = pow($ageHours + 2.0, 1.5);

return round($raw / $decay, 6);
```

If Carbon version lacks `floatDiffInHours`, use:

```php
$ageHours = max(0, $createdAt->diffInSeconds($now, false)) / 3600;
```

Important:

```txt
future created_at should not create negative decay.
```

### Acceptance criteria

- Older posts score lower with same counters.
- New posts do not divide by zero.
- Future timestamps are clamped safely.
- Upvote contribution still works.
- Score never negative.
- Tests pass.

### Definition of Done

- Age decay implemented.
- Tests pass.
- Коммит: `RG-557: Implement age decay`

### Files likely touched

```txt
app/Support/Ranking/HotScoreCalculator.php
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-558 — Test Comments Affect Hot Score

**Area:** Ranking / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-558-test-comments-affect-hot-score`  
**Base branch:** develop
**Depends on:** RG-557

### Goal

Написать падающий тест: comments_count повышает hot score.

### TDD step

Unit test:

```php
it('increases hot score with comments', function () {
    $calculator = app(HotScoreCalculator::class);

    $createdAt = CarbonImmutable::parse('2026-05-14 10:00:00');
    $now = CarbonImmutable::parse('2026-05-14 12:00:00');

    $withoutComments = $calculator->calculate(
        upvotes: 5,
        downvotes: 0,
        commentsCount: 0,
        createdAt: $createdAt,
        now: $now,
    );

    $withComments = $calculator->calculate(
        upvotes: 5,
        downvotes: 0,
        commentsCount: 10,
        createdAt: $createdAt,
        now: $now,
    );

    expect($withComments)->toBeGreaterThan($withoutComments);
});
```

Optional weight sanity:

```php
it('weights comments less than upvotes', ...)
```

### Implementation

Только добавить тест. Он должен упасть до RG-559, если comments are ignored.

### Acceptance criteria

- Test proves comments increase score.
- Same votes/age.
- Test fails before implementation.
- No implementation in this task.

### Definition of Done

- Test written.
- Test expected to fail before implementation.
- Коммит: `RG-558: Test comments affect hot score`

### Files likely touched

```txt
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-559 — Implement Comments Contribution

**Area:** Ranking / Calculator  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-559-implement-comments-contribution`  
**Base branch:** develop
**Depends on:** RG-558

### Goal

Добавить comments contribution в hot score formula.

### TDD step

Использовать падающий тест из RG-558.

### Implementation

Update formula:

```php
$netVotes = max(0, max(0, $upvotes) - max(0, $downvotes));
$comments = max(0, $commentsCount);

$commentWeight = 0.5;

$raw = 1.0 + $netVotes + ($comments * $commentWeight);

$ageHours = ...;
$decay = pow($ageHours + 2.0, 1.5);

return round($raw / $decay, 6);
```

Optionally constants:

```php
private const BASE_SCORE = 1.0;
private const COMMENT_WEIGHT = 0.5;
private const AGE_OFFSET_HOURS = 2.0;
private const GRAVITY = 1.5;
```

### Acceptance criteria

- Comments increase score.
- Comments weighted lower than upvotes if tested.
- Age decay still works.
- Upvote contribution still works.
- Score never negative.
- Constants are readable.
- Tests pass.

### Definition of Done

- Comment contribution implemented.
- Formula constants named.
- Tests pass.
- Коммит: `RG-559: Implement comments contribution`

### Files likely touched

```txt
app/Support/Ranking/HotScoreCalculator.php
tests/Unit/Support/Ranking/HotScoreCalculatorTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-560 — Create RecalculatePostScoreAction

**Area:** Ranking / Action  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-560-create-recalculate-post-score-action`  
**Base branch:** develop
**Depends on:** RG-559

### Goal

Создать action для пересчёта и сохранения `posts.hot_score`.

### TDD step

Skeleton/action test:

```php
it('has recalculate post score action with handle method', function () {
    $action = app(RecalculatePostScoreAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

If `posts.hot_score` may be missing, add schema test:

```php
it('posts table has hot_score column', function () {
    expect(Schema::hasColumn('posts', 'hot_score'))->toBeTrue();
});
```

### Implementation

Create:

```txt
app/Actions/Ranking/RecalculatePostScoreAction.php
```

Action skeleton:

```php
namespace App\Actions\Ranking;

use App\Models\Post;
use App\Support\Ranking\HotScoreCalculator;

final class RecalculatePostScoreAction
{
    public function __construct(
        private readonly HotScoreCalculator $calculator,
    ) {}

    public function handle(Post $post): float
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

If `hot_score` column does not exist, add migration:

```php
$table->decimal('hot_score', 12, 6)->default(0)->index();
```

If already exists, do not duplicate.

### Acceptance criteria

- `RecalculatePostScoreAction` exists.
- `handle(Post $post): float` exists.
- Action resolves from container.
- `posts.hot_score` exists.
- No recalculation logic yet unless needed for test.
- Tests pass.

### Definition of Done

- Tests written.
- Action skeleton created.
- hot_score column confirmed/added.
- Tests pass.
- Коммит: `RG-560: Create RecalculatePostScoreAction`

### Files likely touched

```txt
app/Actions/Ranking/RecalculatePostScoreAction.php
database/migrations/*add_hot_score_to_posts_table.php
tests/Unit/Actions/RecalculatePostScoreActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-561 — Test Score Recalculation Stores Hot_Score

**Area:** Ranking / Tests + Action  
**Type:** Test / Feature  
**Priority:** P0  
**Branch:** `feature/RG-561-test-score-recalculation-stores-hot-score`  
**Base branch:** develop
**Depends on:** RG-560

### Goal

Проверить и реализовать, что action сохраняет рассчитанный score в `posts.hot_score`.

### TDD step

Feature/action test:

```php
it('recalculates and stores post hot score', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $post = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'comments_count' => 4,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
        'hot_score' => 0,
    ]);

    $score = app(RecalculatePostScoreAction::class)->handle($post);

    $post->refresh();

    expect($score)->toBeFloat();
    expect((float) $post->hot_score)->toBe($score);
    expect((float) $post->hot_score)->toBeGreaterThan(0);
});
```

Only hot_score mutation test:

```php
it('only updates hot score during recalculation', function () {
    ...
});
```

### Implementation

Implement action:

```php
public function handle(Post $post): float
{
    $post->refresh();

    $score = $this->calculator->calculate(
        upvotes: (int) $post->upvotes_count,
        downvotes: (int) $post->downvotes_count,
        commentsCount: (int) $post->comments_count,
        createdAt: $post->created_at,
        now: now(),
    );

    $post->forceFill([
        'hot_score' => $score,
    ])->save();

    return $score;
}
```

If `downvotes_count` column is named differently, adapt to actual aggregate field from Phase 16.

### Acceptance criteria

- Action calculates score using HotScoreCalculator.
- Action stores score in `hot_score`.
- Action returns stored score.
- Score > 0 for engaged post.
- Action does not change status/counters.
- Tests pass.

### Definition of Done

- Tests written.
- Action implemented.
- Tests pass.
- Коммит: `RG-561: Test score recalculation stores hot_score`

### Files likely touched

```txt
app/Actions/Ranking/RecalculatePostScoreAction.php
tests/Feature/Actions/RecalculatePostScoreActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-562 — Dispatch Score Recalculation After Vote

**Area:** Ranking / Voting Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-562-dispatch-score-recalculation-after-vote`  
**Base branch:** develop
**Depends on:** RG-561

### Goal

Пересчитывать `hot_score` после successful vote.

### TDD step

Feature/action test:

```php
it('recalculates hot score after post vote', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteValue::Up);

    expect((float) $post->fresh()->hot_score)->toBeGreaterThan(0);
});
```

If action starts with default hot_score > 0, use comparison:

```php
$before = (float) $post->hot_score;
...
expect((float) $post->fresh()->hot_score)->not->toBe($before);
```

Failed vote no recalc test optional:

```php
it('does not recalculate score when vote fails', ...)
```

### Implementation

In `VotePostAction`, after successful vote/counter update:

```php
$this->recalculatePostScore->handle($post->fresh());
```

Constructor injection:

```php
public function __construct(
    private readonly RecalculatePostScoreAction $recalculatePostScore,
) {}
```

If there are multiple voting actions:

```txt
VotePostAction is required by backlog.
Origin/Cuisine votes probably should not affect hot_score unless they change engagement counters.
```

Do not recalculate after origin/cuisine votes unless product wants them to influence hotness. MVP: only up/down post votes affect hot_score.

### Acceptance criteria

- Successful post vote recalculates hot_score.
- hot_score stored after vote.
- Failed vote does not create inconsistent score.
- Vote action does not implement formula directly.
- Tests pass.

### Definition of Done

- Tests written.
- VotePostAction calls RecalculatePostScoreAction.
- Tests pass.
- Коммит: `RG-562: Dispatch score recalculation after vote`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionHotScoreTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-563 — Dispatch Score Recalculation After Comment

**Area:** Ranking / Comments Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-563-dispatch-score-recalculation-after-comment`  
**Base branch:** develop
**Depends on:** RG-562

### Goal

Пересчитывать `hot_score` после successful comment creation.

### TDD step

Feature/action test:

```php
it('recalculates hot score after comment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
        'comments_count' => 0,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    app(AddCommentAction::class)->handle($user, $post, 'Nice dish.');

    expect((float) $post->fresh()->hot_score)->toBeGreaterThan(0);
    expect($post->fresh()->comments_count)->toBe(1);
});
```

Failed comment no recalc/no counter test:

```php
it('does not recalculate score when comment creation fails', ...)
```

### Implementation

In `AddCommentAction`, after successful comment creation and comments_count update:

```php
$this->recalculatePostScore->handle($post->fresh());
```

Constructor injection:

```php
public function __construct(
    private readonly RecalculatePostScoreAction $recalculatePostScore,
) {}
```

If `AddCommentAction` already has constructor dependencies, extend carefully.

Important ordering:

```txt
1. create comment
2. update comments_count
3. recalculate hot_score using fresh comments_count
4. dispatch notification
```

Notification can happen before or after score recalc; score recalc should use correct counters.

### Acceptance criteria

- Successful comment recalculates hot_score.
- Score uses updated comments_count.
- Failed comment does not change score/counters.
- Comment action does not implement formula directly.
- Tests pass.

### Definition of Done

- Tests written.
- AddCommentAction calls RecalculatePostScoreAction.
- Tests pass.
- Коммит: `RG-563: Dispatch score recalculation after comment`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionHotScoreTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-564 — Add Command To Recalculate All Hot Scores

**Area:** Ranking / Console Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-564-add-command-to-recalculate-all-hot-scores`  
**Base branch:** develop
**Depends on:** RG-563

### Goal

Добавить artisan command для пересчёта `hot_score` у всех posts.

### TDD step

Command existence/smoke test:

```php
it('has recalculate hot scores command', function () {
    $this->artisan('posts:recalculate-hot-scores')
        ->assertExitCode(0);
});
```

This may fail until command exists.

### Implementation

Create command:

```bash
php artisan make:command RecalculateHotScoresCommand
```

Command:

```txt
php artisan posts:recalculate-hot-scores
```

Class:

```php
final class RecalculateHotScoresCommand extends Command
{
    protected $signature = 'posts:recalculate-hot-scores {--chunk=500}';
    protected $description = 'Recalculate hot_score for all posts.';

    public function handle(RecalculatePostScoreAction $recalculatePostScore): int
    {
        $count = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        Post::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($posts) use (&$count, $recalculatePostScore): void {
                foreach ($posts as $post) {
                    $recalculatePostScore->handle($post);
                    $count++;
                }
            });

        $this->info("Recalculated hot scores for {$count} posts.");

        return self::SUCCESS;
    }
}
```

Register command if Laravel version requires manual registration. In modern Laravel, auto-discovery may work depending app structure.

Do not add scheduler.

### Acceptance criteria

- Artisan command exists.
- Command name = `posts:recalculate-hot-scores`.
- Command uses RecalculatePostScoreAction.
- Command chunks posts.
- Command outputs count.
- Command returns success exit code.
- No scheduler/queue added.
- Smoke test passes.

### Definition of Done

- Command created.
- Smoke test passes.
- Коммит: `RG-564: Add command to recalculate all hot scores`

### Files likely touched

```txt
app/Console/Commands/RecalculateHotScoresCommand.php
tests/Feature/Console/RecalculateHotScoresCommandTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-565 — Test Recalculate Hot Scores Command

**Area:** Ranking / Console Command / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-565-test-recalculate-hot-scores-command`  
**Base branch:** develop
**Depends on:** RG-564

### Goal

Полноценно проверить, что command пересчитывает scores для всех posts.

### TDD step

Feature/command test:

```php
it('recalculates hot scores for all posts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-14 12:00:00'));

    $first = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'comments_count' => 1,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-14 10:00:00'),
    ]);

    $second = Post::factory()->published()->create([
        'upvotes_count' => 2,
        'downvotes_count' => 0,
        'comments_count' => 5,
        'hot_score' => 0,
        'created_at' => CarbonImmutable::parse('2026-05-13 10:00:00'),
    ]);

    $this->artisan('posts:recalculate-hot-scores')
        ->expectsOutput('Recalculated hot scores for 2 posts.')
        ->assertExitCode(0);

    expect((float) $first->fresh()->hot_score)->toBeGreaterThan(0);
    expect((float) $second->fresh()->hot_score)->toBeGreaterThan(0);
});
```

Chunk option test:

```php
it('recalculates hot scores using chunk option', function () {
    Post::factory()->count(3)->published()->create(['hot_score' => 0]);

    $this->artisan('posts:recalculate-hot-scores --chunk=1')
        ->assertExitCode(0);

    expect(Post::query()->where('hot_score', '>', 0)->count())->toBe(3);
});
```

### Implementation

If tests fail, fix command:

```txt
- ensure command registered;
- ensure chunk option works;
- ensure all posts are processed;
- ensure output uses exact count;
- ensure action returns/stores score.
```

Add final review doc:

```txt
docs/ranking/phase-33-hot-score-ranking-review.md
```

Checklist:

```txt
- upvotes increase score;
- age decreases score;
- comments increase score;
- score stored in hot_score;
- vote recalculates score;
- comment recalculates score;
- command recalculates all scores;
- no queue/Redis introduced;
- formula constants documented.
```

### Acceptance criteria

- Command recalculates all posts.
- Posts with zero old hot_score get positive scores when inputs justify it.
- Command output includes processed count.
- Chunk option works.
- Command returns success.
- Ranking review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- Command behavior verified.
- Review note added.
- Tests/build pass.
- Коммит: `RG-565: Test recalculate hot scores command`

### Files likely touched

```txt
app/Console/Commands/RecalculateHotScoresCommand.php
docs/ranking/phase-33-hot-score-ranking-review.md
tests/Feature/Console/RecalculateHotScoresCommandTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 33 Completion Criteria

Phase 33 завершена, когда:

```txt
- RG-553–RG-565 выполнены;
- HotScoreCalculator exists;
- calculator is pure and deterministic;
- score increases with upvotes;
- score decreases with age;
- comments increase score;
- downvotes do not create negative score;
- RecalculatePostScoreAction exists;
- posts.hot_score exists;
- action stores calculated hot_score;
- vote action recalculates hot_score after successful vote;
- comment action recalculates hot_score after successful comment;
- artisan command posts:recalculate-hot-scores exists;
- command processes posts in chunks;
- command recalculates all posts;
- FeedQuery hot sort still uses hot_score DESC;
- no queue/Redis dependency added;
- no UI changes added unless required by broken tests;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 33

Без отдельной задачи нельзя:

```txt
- менять SortDropdown UI;
- добавлять trending page;
- добавлять personalized feed;
- добавлять ML ranking;
- добавлять Redis sorted sets;
- добавлять queue worker;
- добавлять scheduled job;
- добавлять admin controls for formula weights;
- добавлять ranking analytics dashboard;
- добавлять social share features из Phase 32;
- добавлять rate limiting из Phase 34;
- добавлять API endpoints;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-553 Create HotScoreCalculator
RG-554 Test hot score increases with upvotes
RG-555 Implement upvote contribution
RG-556 Test hot score decreases with age
RG-557 Implement age decay
RG-558 Test comments affect hot score
RG-559 Implement comments contribution
RG-560 Create RecalculatePostScoreAction
RG-561 Test score recalculation stores hot_score
RG-562 Dispatch score recalculation after vote
RG-563 Dispatch score recalculation after comment
RG-564 Add command to recalculate all hot scores
RG-565 Test recalculate hot scores command
```
---

# 14. Release

После завершения Phase 33:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.14-phase33-hot-score-ranking
git push -u origin release/v0.2.14-phase33-hot-score-ranking
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.14-phase33-hot-score-ranking -m "RateGuru Phase 33 Hot Score & Ranking"
git push origin v0.2.14-phase33-hot-score-ranking
```
