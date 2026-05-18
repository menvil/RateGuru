# RateGuru — Phase 34 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 34 — Rate Limiting & Abuse Guards**  
Диапазон задач: **RG-566 → RG-574**  
Основа нумерации: исходный atomic backlog: Phase 34 начинается с `PLR-566` и заканчивается `PLR-574`.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 34 соответствует блоку:

```txt
Phase 34 — Rate Limiting & Abuse Guards
```

Правильный диапазон:

```txt
RG-566 — Add upload rate limit middleware
RG-567 — Test upload rate limit blocks excessive posts
RG-568 — Add comment rate limit
RG-569 — Test comment rate limit blocks spam
RG-570 — Add report rate limit
RG-571 — Test report rate limit blocks spam
RG-572 — Add vote rate limit
RG-573 — Test vote rate limit blocks abuse
RG-574 — Add suspicious activity log placeholder
```

Phase 35 начинается с `RG-575`, поэтому Phase 34 не должна залезать в Authorization Policies.
---

# 2. Цель Phase 34

Добавить базовые abuse guards для действий, которые проще всего спамить:

```txt
- upload/create post;
- comments;
- reports;
- votes.
```

После Phase 34 система должна:

```txt
- блокировать чрезмерные uploads;
- блокировать comment spam;
- блокировать report spam;
- блокировать vote abuse;
- иметь placeholder для suspicious activity log;
- работать без Redis;
- работать в SQLite/local/test окружении;
- не заменять authorization/business guards.
```

Это не полноценная anti-fraud система. Это минимальные rate limits для MVP.
---

# 3. Scope Phase 34

## Входит

```txt
- reusable ActionRateLimiter;
- reusable RateLimitKey builder;
- RateLimitExceededException или action-specific wrappers;
- upload rate limit;
- comment rate limit;
- report rate limit;
- vote rate limit;
- tests for blocked attempts and no side effects;
- suspicious activity log placeholder documentation / no-op service.
```

## Не входит

```txt
- Redis requirement;
- queues;
- captcha;
- device fingerprinting;
- IP reputation;
- ML/AI abuse scoring;
- automatic bans/shadowbans;
- moderation dashboard changes;
- suspicious activity admin UI;
- new authorization policies;
- API endpoints.
```
---

# 4. Ключевые решения

## 4.1. Используем Laravel RateLimiter

Нельзя строить собственную таблицу попыток в Phase 34.  
Используем стандартный Laravel `RateLimiter` facade / named limiters / throttle middleware.

Причина простая:

```txt
- встроено в Laravel;
- работает через cache;
- не требует Redis;
- легко тестируется;
- позже можно переключить limiter cache на Redis.
```

## 4.2. Не делаем Redis обязательным

Rate limits должны работать на текущем cache driver.

Для MVP допустимо:

```txt
array / file / database cache
```

Для production позже можно настроить:

```txt
cache.limiter = redis
```

Но Phase 34 не должна зависеть от Redis.

## 4.3. Основная защита — action-level, не только route middleware

RateGuru активно использует Livewire и backend actions. Если поставить `throttle` только на route, можно получить слишком грубый лимит на все Livewire requests.

Правило:

```txt
- upload может иметь route middleware, если есть отдельный endpoint;
- comments/reports/votes должны иметь action-level limiter;
- state-changing backend actions остаются source of truth.
```

## 4.4. Rate limit keys должны быть scoped

Плохой ключ:

```txt
comment
vote
report
upload
```

Он заблокирует всех пользователей глобально.

Хорошие ключи:

```txt
upload:user:{user_id}
comment:user:{user_id}
comment:post:{post_id}:user:{user_id}
report:user:{user_id}
report:target:{type}:{id}:user:{user_id}
vote:user:{user_id}
vote:post:{post_id}:user:{user_id}
```

## 4.5. Начальные лимиты

Это стартовые значения, не “истина”.

```txt
upload:
- 5 posts / 10 minutes per user

comments:
- 10 comments / minute per user
- optional: 3 comments / minute per same post/user

reports:
- 10 reports / 10 minutes per user

votes:
- 60 votes / minute per user
```

Future tuning должен идти по production telemetry, а не по догадкам.

## 4.6. Что делать при превышении лимита

На action-level лучше бросать typed exception:

```txt
RateLimitExceededException
```

Или оборачивать в существующие domain exceptions:

```txt
CannotCommentException::becauseRateLimited()
CannotReportContentException::becauseRateLimited()
CannotVoteException::becauseRateLimited()
```

UI должен показывать user-safe error:

```txt
You are doing this too quickly. Please try again later.
```

Нельзя показывать внутренние ключи:

```txt
comment:user:123:post:456
```

## 4.7. Suspicious activity log — только placeholder

`RG-574` не означает полноценную таблицу и dashboard.

Правильно:

```txt
- docs/security/suspicious-activity-log.md;
- optional no-op SuspiciousActivityLogger;
- список будущих event names.
```

Неправильно:

```txt
- suspicious_activity_logs table;
- admin UI;
- automatic scoring;
- automatic shadowban/ban;
- moderator notifications.
```
---

# 5. Архитектура

## 5.1. Reusable ActionRateLimiter

Создать:

```txt
app/Support/AbuseGuards/ActionRateLimiter.php
```

Пример API:

```php
public function hitOrFail(
    string $key,
    int $maxAttempts,
    int $decaySeconds,
    string $message = 'Too many attempts. Please try again later.',
): void
```

Пример поведения:

```php
if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
    throw RateLimitExceededException::make(
        message: $message,
        retryAfterSeconds: RateLimiter::availableIn($key),
    );
}

RateLimiter::hit($key, $decaySeconds);
```

## 5.2. Reusable RateLimitKey

Создать:

```txt
app/Support/AbuseGuards/RateLimitKey.php
```

Пример:

```php
public static function userAction(string $action, User $user): string
{
    return "rate-limit:{$action}:user:{$user->id}";
}

public static function userTarget(string $action, User $user, string $targetType, int|string $targetId): string
{
    return "rate-limit:{$action}:user:{$user->id}:target:{$targetType}:{$targetId}";
}
```

## 5.3. Нельзя заменять authorization

Rate limiting не заменяет:

```txt
guest guard;
banned guard;
post status guard;
ownership guard;
moderator/admin guard;
duplicate report guard.
```

Rate limit — только дополнительная защита.

## 5.4. Тесты должны изолировать RateLimiter state

В тестах:

```txt
- использовать уникальных users/keys;
- либо чистить ключи через RateLimiter::clear($key);
- не допускать leakage между tests.
```
---

# 6. GitFlow

## Base branch

```txt
develop
```

## Branch format

```txt
feature/RG-566-add-upload-rate-limit-middleware
feature/RG-568-add-comment-rate-limit
feature/RG-574-add-suspicious-activity-log-placeholder
```

## Commit format

```txt
RG-566: Add upload rate limit middleware
RG-568: Add comment rate limit
RG-574: Add suspicious activity log placeholder
```

## Release branch

```txt
release/v0.2.15-phase34-rate-limiting-abuse-guards
```

## Tag

```txt
v0.2.15-phase34-rate-limiting-abuse-guards
```
---

# 7. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Abuse Guards / Rate Limiting / Tests
Type: Test / Feature / Middleware / Action / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым.

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
- Rate limit scoped correctly
- No Redis/queue dependency introduced
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 8. Phase 34 Atomic Tasks
---

## RG-566 — Add Upload Rate Limit Middleware

**Area:** Abuse Guards / Upload  
**Type:** Middleware / Action Guard  
**Priority:** P0  
**Branch:** `feature/RG-566-add-upload-rate-limit-middleware`  
**Base branch:** develop
**Depends on:** RG-565

### Goal

Добавить rate limit для upload/create post flow.

### TDD step

Smoke tests:

```php
it('has upload rate limit config', function () {
    expect(config('rate_limits.upload.max_attempts'))->toBeInt();
    expect(config('rate_limits.upload.decay_seconds'))->toBeInt();
});

it('resolves action rate limiter', function () {
    expect(app(ActionRateLimiter::class))->toBeInstanceOf(ActionRateLimiter::class);
});
```

### Implementation

Создать config:

```txt
config/rate_limits.php
```

Пример:

```php
return [
    'upload' => [
        'max_attempts' => env('RATE_LIMIT_UPLOAD_ATTEMPTS', 5),
        'decay_seconds' => env('RATE_LIMIT_UPLOAD_DECAY_SECONDS', 600),
    ],
    'comment' => [
        'max_attempts' => env('RATE_LIMIT_COMMENT_ATTEMPTS', 10),
        'decay_seconds' => env('RATE_LIMIT_COMMENT_DECAY_SECONDS', 60),
    ],
    'report' => [
        'max_attempts' => env('RATE_LIMIT_REPORT_ATTEMPTS', 10),
        'decay_seconds' => env('RATE_LIMIT_REPORT_DECAY_SECONDS', 600),
    ],
    'vote' => [
        'max_attempts' => env('RATE_LIMIT_VOTE_ATTEMPTS', 60),
        'decay_seconds' => env('RATE_LIMIT_VOTE_DECAY_SECONDS', 60),
    ],
];
```

Создать:

```txt
app/Support/AbuseGuards/ActionRateLimiter.php
app/Support/AbuseGuards/RateLimitKey.php
app/Exceptions/Abuse/RateLimitExceededException.php
```

Подключить upload limit в post creation action:

```php
$this->rateLimiter->hitOrFail(
    key: RateLimitKey::userAction('upload', $user),
    maxAttempts: config('rate_limits.upload.max_attempts'),
    decaySeconds: config('rate_limits.upload.decay_seconds'),
    message: 'You are uploading too quickly. Please try again later.',
);
```

Если в проекте upload идёт через отдельный route, можно дополнительно добавить named middleware. Но action-level guard обязателен, чтобы Livewire/future API не обходили лимит.

### Acceptance criteria

- `config/rate_limits.php` exists.
- `ActionRateLimiter` exists.
- `RateLimitKey` exists.
- `RateLimitExceededException` exists.
- Upload/create post path invokes limiter.
- Key is scoped by user id.
- No Redis dependency.
- Tests pass.

### Definition of Done

- Tests written.
- Helper/service created.
- Upload guard wired.
- Tests pass.
- Коммит: `RG-566: Add upload rate limit middleware`

### Files likely touched

```txt
config/rate_limits.php
app/Support/AbuseGuards/ActionRateLimiter.php
app/Support/AbuseGuards/RateLimitKey.php
app/Exceptions/Abuse/RateLimitExceededException.php
app/Actions/Posts/CreatePostAction.php
tests/Unit/Support/ActionRateLimiterTest.php
tests/Feature/Actions/CreatePostRateLimitTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-567 — Test Upload Rate Limit Blocks Excessive Posts

**Area:** Abuse Guards / Upload / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-567-test-upload-rate-limit-blocks-excessive-posts`  
**Base branch:** develop
**Depends on:** RG-566

### Goal

Проверить, что excessive uploads блокируются и не создают posts.

### TDD step

```php
it('blocks excessive post uploads from same user', function () {
    config()->set('rate_limits.upload.max_attempts', 2);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, PostDataFactory::valid());
    app(CreatePostAction::class)->handle($user, PostDataFactory::valid());

    app(CreatePostAction::class)->handle($user, PostDataFactory::valid());
})->throws(RateLimitExceededException::class);
```

No side effects:

```php
it('does not create post when upload rate limit is exceeded', function () {
    config()->set('rate_limits.upload.max_attempts', 1);

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, PostDataFactory::valid());

    try {
        app(CreatePostAction::class)->handle($user, PostDataFactory::valid());
    } catch (RateLimitExceededException) {
        // expected
    }

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1);
});
```

Scoping test:

```php
it('does not block another user when one user hits upload limit', ...)
```

### Implementation

Если тесты падают:

```txt
- убедиться, что limiter вызывается до создания post;
- убедиться, что key содержит user id;
- убедиться, что UI/Livewire не глотает exception без проверки;
- очистить rate limiter state between tests if needed.
```

### Acceptance criteria

- Same user blocked after configured attempts.
- Blocked attempt creates no post.
- Another user is not blocked.
- Error message is user-safe.
- Tests pass.

### Definition of Done

- Tests written.
- Upload limiter verified.
- Tests pass.
- Коммит: `RG-567: Test upload rate limit blocks excessive posts`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostRateLimitTest.php
app/Actions/Posts/CreatePostAction.php
app/Livewire/Upload/UploadPostForm.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-568 — Add Comment Rate Limit

**Area:** Abuse Guards / Comments  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-568-add-comment-rate-limit`  
**Base branch:** develop
**Depends on:** RG-567

### Goal

Добавить rate limit для comment creation.

### TDD step

```php
it('has comment rate limit config', function () {
    expect(config('rate_limits.comment.max_attempts'))->toBeInt();
    expect(config('rate_limits.comment.decay_seconds'))->toBeInt();
});
```

### Implementation

В `AddCommentAction` перед созданием comment:

```php
$this->rateLimiter->hitOrFail(
    key: RateLimitKey::userAction('comment', $user),
    maxAttempts: config('rate_limits.comment.max_attempts'),
    decaySeconds: config('rate_limits.comment.decay_seconds'),
    message: 'You are commenting too quickly. Please try again later.',
);
```

Опционально добавить same-post limiter:

```php
$this->rateLimiter->hitOrFail(
    key: RateLimitKey::userTarget('comment-post', $user, 'post', $post->id),
    maxAttempts: 3,
    decaySeconds: 60,
    message: 'You are commenting on this post too quickly.',
);
```

`CommentForm` должен поймать exception:

```php
catch (RateLimitExceededException $e) {
    $this->addError('body', $e->getMessage());
}
```

Если в проекте принято всё оборачивать в `CannotCommentException`, добавить:

```php
CannotCommentException::becauseRateLimited()
```

### Acceptance criteria

- AddCommentAction invokes limiter.
- Limit is per user.
- Blocked attempt creates no comment.
- CommentForm shows user-safe error.
- No Redis dependency.
- Tests pass.

### Definition of Done

- Comment limiter wired.
- UI exception mapping added if needed.
- Tests pass.
- Коммит: `RG-568: Add comment rate limit`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
app/Livewire/Comments/CommentForm.php
app/Exceptions/Comments/CannotCommentException.php
config/rate_limits.php
tests/Feature/Actions/AddCommentRateLimitTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-569 — Test Comment Rate Limit Blocks Spam

**Area:** Abuse Guards / Comments / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-569-test-comment-rate-limit-blocks-spam`  
**Base branch:** develop
**Depends on:** RG-568

### Goal

Проверить, что excessive comments блокируются и не меняют counters.

### TDD step

```php
it('blocks excessive comments from same user', function () {
    config()->set('rate_limits.comment.max_attempts', 2);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle($user, $post, 'First');
    app(AddCommentAction::class)->handle($user, $post, 'Second');

    app(AddCommentAction::class)->handle($user, $post, 'Third');
})->throws(RateLimitExceededException::class);
```

No side effects:

```php
it('does not create comment or increment count when comment rate limit is exceeded', function () {
    config()->set('rate_limits.comment.max_attempts', 1);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['comments_count' => 0]);

    app(AddCommentAction::class)->handle($user, $post, 'First');

    try {
        app(AddCommentAction::class)->handle($user, $post->fresh(), 'Second');
    } catch (RateLimitExceededException) {
        // expected
    }

    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(1);
    expect($post->fresh()->comments_count)->toBe(1);
});
```

Scoping test:

```php
it('does not block another user when first user hits comment limit', ...)
```

Livewire test:

```php
it('shows comment rate limit error in comment form', ...)
```

### Acceptance criteria

- Same user blocked after configured attempts.
- Blocked attempt creates no comment.
- Blocked attempt does not increment comments_count.
- Another user is not blocked.
- Livewire form shows safe error.
- Tests pass.

### Definition of Done

- Tests written.
- Comment limiter verified.
- Tests pass.
- Коммит: `RG-569: Test comment rate limit blocks spam`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentRateLimitTest.php
tests/Feature/Livewire/CommentFormTest.php
app/Actions/Comments/AddCommentAction.php
app/Livewire/Comments/CommentForm.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-570 — Add Report Rate Limit

**Area:** Abuse Guards / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-570-add-report-rate-limit`  
**Base branch:** develop
**Depends on:** RG-569

### Goal

Добавить rate limit для report content action.

### TDD step

```php
it('has report rate limit config', function () {
    expect(config('rate_limits.report.max_attempts'))->toBeInt();
    expect(config('rate_limits.report.decay_seconds'))->toBeInt();
});
```

### Implementation

В `ReportContentAction` после guest/banned guards, но до создания report:

```php
$this->rateLimiter->hitOrFail(
    key: RateLimitKey::userAction('report', $user),
    maxAttempts: config('rate_limits.report.max_attempts'),
    decaySeconds: config('rate_limits.report.decay_seconds'),
    message: 'You are reporting too quickly. Please try again later.',
);
```

Не путать с duplicate report guard:

```txt
duplicate report = тот же user уже пожаловался на тот же target;
rate limit = user слишком часто отправляет reports.
```

`ReportModal` должен показать ошибку через existing `report` error slot.

Если используем report-specific exception:

```php
CannotReportContentException::becauseRateLimited()
```

### Acceptance criteria

- ReportContentAction invokes limiter.
- Limit is per user.
- Duplicate guard remains separate.
- Blocked report creates no report row.
- ReportModal handles error safely.
- Tests pass.

### Definition of Done

- Report limiter wired.
- Exception mapping added if needed.
- Tests pass.
- Коммит: `RG-570: Add report rate limit`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Exceptions/Reports/CannotReportContentException.php
app/Livewire/Reports/ReportModal.php
config/rate_limits.php
tests/Feature/Actions/ReportContentRateLimitTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-571 — Test Report Rate Limit Blocks Spam

**Area:** Abuse Guards / Reports / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-571-test-report-rate-limit-blocks-spam`  
**Base branch:** develop
**Depends on:** RG-570

### Goal

Проверить, что excessive reports блокируются и не создают reports.

### TDD step

```php
it('blocks excessive reports from same user', function () {
    config()->set('rate_limits.report.max_attempts', 2);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();
    $third = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $first, ReportReason::Spam);
    app(ReportContentAction::class)->handle($user, $second, ReportReason::Spam);

    app(ReportContentAction::class)->handle($user, $third, ReportReason::Spam);
})->throws(CannotReportContentException::class);
```

No side effects:

```php
it('does not create report when report rate limit is exceeded', function () {
    config()->set('rate_limits.report.max_attempts', 1);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $first, ReportReason::Spam);

    try {
        app(ReportContentAction::class)->handle($user, $second, ReportReason::Spam);
    } catch (CannotReportContentException) {
        // expected
    }

    expect(Report::query()->where('user_id', $user->id)->count())->toBe(1);
});
```

Scoping test:

```php
it('does not block another user when first user hits report limit', ...)
```

Livewire test:

```php
it('shows report rate limit error in report modal', ...)
```

### Acceptance criteria

- Same user blocked after configured attempts.
- Blocked attempt creates no report.
- Blocked attempt does not increment reports_count.
- Another user is not blocked.
- ReportModal shows safe error.
- Tests pass.

### Definition of Done

- Tests written.
- Report limiter verified.
- Tests pass.
- Коммит: `RG-571: Test report rate limit blocks spam`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentRateLimitTest.php
tests/Feature/Livewire/ReportModalTest.php
app/Actions/Reports/ReportContentAction.php
app/Livewire/Reports/ReportModal.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-572 — Add Vote Rate Limit

**Area:** Abuse Guards / Voting  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-572-add-vote-rate-limit`  
**Base branch:** develop
**Depends on:** RG-571

### Goal

Добавить rate limit для voting actions.

### TDD step

```php
it('has vote rate limit config', function () {
    expect(config('rate_limits.vote.max_attempts'))->toBeInt();
    expect(config('rate_limits.vote.decay_seconds'))->toBeInt();
});
```

### Implementation

Защитить как минимум:

```txt
VotePostAction
```

Если существуют:

```txt
VoteOriginAction
VoteCuisineAction
```

их тоже нужно защитить, иначе abuse можно будет перенести туда.

В each vote action before mutation:

```php
$this->rateLimiter->hitOrFail(
    key: RateLimitKey::userAction('vote', $user),
    maxAttempts: config('rate_limits.vote.max_attempts'),
    decaySeconds: config('rate_limits.vote.decay_seconds'),
    message: 'You are voting too quickly. Please try again later.',
);
```

Если есть `CannotVoteException`, добавить:

```php
CannotVoteException::becauseRateLimited()
```

Или оборачивать generic `RateLimitExceededException`.

Livewire voting components should not crash:

```txt
- PostVoting
- OriginVoting
- CuisineVoting
```

Они должны показать safe error или graceful no-op.

### Acceptance criteria

- VotePostAction invokes limiter.
- Origin/Cuisine vote actions invoke limiter if they exist.
- Limit is per user.
- Blocked vote does not mutate vote rows/counters.
- UI handles blocked vote safely.
- Tests pass.

### Definition of Done

- Vote limiter wired.
- UI exception mapping added if needed.
- Tests pass.
- Коммит: `RG-572: Add vote rate limit`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
app/Actions/Votes/VoteOriginAction.php
app/Actions/Votes/VoteCuisineAction.php
app/Exceptions/Votes/CannotVoteException.php
app/Livewire/Voting/PostVoting.php
app/Livewire/Voting/OriginVoting.php
app/Livewire/Voting/CuisineVoting.php
config/rate_limits.php
tests/Feature/Actions/VoteRateLimitTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-573 — Test Vote Rate Limit Blocks Abuse

**Area:** Abuse Guards / Voting / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-573-test-vote-rate-limit-blocks-abuse`  
**Base branch:** develop
**Depends on:** RG-572

### Goal

Проверить, что excessive voting блокируется и не мутирует vote/counters.

### TDD step

```php
it('blocks excessive post votes from same user', function () {
    config()->set('rate_limits.vote.max_attempts', 2);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();
    $third = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $first, VoteValue::Up);
    app(VotePostAction::class)->handle($user, $second, VoteValue::Up);

    app(VotePostAction::class)->handle($user, $third, VoteValue::Up);
})->throws(CannotVoteException::class);
```

No side effects:

```php
it('does not mutate vote counters when vote rate limit is exceeded', function () {
    config()->set('rate_limits.vote.max_attempts', 1);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create(['upvotes_count' => 0]);
    $second = Post::factory()->published()->create(['upvotes_count' => 0]);

    app(VotePostAction::class)->handle($user, $first, VoteValue::Up);

    try {
        app(VotePostAction::class)->handle($user, $second, VoteValue::Up);
    } catch (CannotVoteException) {
        // expected
    }

    expect($first->fresh()->upvotes_count)->toBe(1);
    expect($second->fresh()->upvotes_count)->toBe(0);
});
```

Scoping test:

```php
it('does not block another user when first user hits vote limit', ...)
```

If origin/cuisine actions exist:

```php
it('applies shared vote rate limit to origin voting', ...)
it('applies shared vote rate limit to cuisine voting', ...)
```

### Acceptance criteria

- Same user blocked after configured vote attempts.
- Blocked attempt does not create/update vote.
- Blocked attempt does not update counters.
- Another user is not blocked.
- Origin/cuisine voting protected if actions exist.
- Tests pass.

### Definition of Done

- Tests written.
- Vote limiter verified.
- Tests pass.
- Коммит: `RG-573: Test vote rate limit blocks abuse`

### Files likely touched

```txt
tests/Feature/Actions/VoteRateLimitTest.php
app/Actions/Votes/VotePostAction.php
app/Actions/Votes/VoteOriginAction.php
app/Actions/Votes/VoteCuisineAction.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-574 — Add Suspicious Activity Log Placeholder

**Area:** Abuse Guards / Docs  
**Type:** Docs / Placeholder  
**Priority:** P1  
**Branch:** `feature/RG-574-add-suspicious-activity-log-placeholder`  
**Base branch:** develop
**Depends on:** RG-573

### Goal

Добавить placeholder для будущего suspicious activity logging.

### TDD step

```php
it('has suspicious activity log placeholder documentation', function () {
    expect(file_exists(base_path('docs/security/suspicious-activity-log.md')))->toBeTrue();
});
```

Optional no-op service test:

```php
it('resolves suspicious activity logger as no-op service', function () {
    expect(app(SuspiciousActivityLogger::class))
        ->toBeInstanceOf(SuspiciousActivityLogger::class);
});
```

### Implementation

Создать:

```txt
docs/security/suspicious-activity-log.md
```

Содержимое:

```md
# Suspicious Activity Log Placeholder

## Purpose
Future structured log for abuse-related signals.

## Candidate events
- upload_rate_limit_exceeded
- comment_rate_limit_exceeded
- report_rate_limit_exceeded
- vote_rate_limit_exceeded
- duplicate_report_attempt
- repeated_hidden_content
- repeated_rejected_posts
- suspicious_user_marked
- moderator_manual_note

## Not implemented in Phase 34
- database table
- dashboard UI
- automatic scoring
- automatic bans/shadowbans
- notifications to moderators
```

Optional no-op service:

```txt
app/Support/AbuseGuards/SuspiciousActivityLogger.php
```

```php
final class SuspiciousActivityLogger
{
    public function record(
        string $event,
        ?User $user = null,
        array $context = [],
    ): void {
        // No-op placeholder.
    }
}
```

Создать review doc:

```txt
docs/security/phase-34-rate-limiting-review.md
```

Checklist:

```txt
- upload limit works;
- comment limit works;
- report limit works;
- vote limit works;
- keys are scoped;
- no Redis dependency;
- no auto-ban/shadowban;
- no suspicious_activity_logs table.
```

### Acceptance criteria

- Suspicious activity placeholder doc exists.
- Future event names documented.
- Optional no-op logger resolves if created.
- No DB table added.
- No automatic ban/shadowban added.
- Security review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Placeholder documentation created.
- Optional no-op logger created.
- Review note added.
- Tests/build pass.
- Коммит: `RG-574: Add suspicious activity log placeholder`

### Files likely touched

```txt
docs/security/suspicious-activity-log.md
docs/security/phase-34-rate-limiting-review.md
app/Support/AbuseGuards/SuspiciousActivityLogger.php
tests/Feature/Security/SuspiciousActivityLogPlaceholderTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 9. Phase 34 Completion Criteria

Phase 34 завершена, когда:

```txt
- RG-566–RG-574 выполнены;
- upload/create post rate limit exists;
- excessive uploads are blocked;
- blocked upload does not create post;
- comment rate limit exists;
- excessive comments are blocked;
- blocked comment does not create comment or increment comments_count;
- report rate limit exists;
- excessive reports are blocked;
- blocked report does not create report or increment reports_count;
- vote rate limit exists;
- excessive votes are blocked;
- blocked vote does not mutate vote rows/counters;
- rate limit keys are scoped per user and not global;
- another user is not blocked by first user's abuse;
- Livewire components show user-safe errors where applicable;
- suspicious activity log placeholder exists;
- no Redis/queue requirement added;
- no AI/scoring/auto-ban added;
- composer test passes;
- npm run build passes.
```
---

# 10. Что нельзя делать в Phase 34

Без отдельной задачи нельзя:

```txt
- добавлять Redis как обязательную зависимость;
- добавлять queue worker setup;
- делать ML/AI abuse scoring;
- добавлять captcha;
- добавлять device fingerprinting;
- добавлять IP reputation integration;
- добавлять automatic ban/shadowban;
- менять ModerationDashboard;
- создавать suspicious activity admin UI;
- добавлять notifications to moderators;
- создавать PostPolicy/CommentPolicy/ModerationPolicy;
- добавлять API endpoints;
- добавлять Vue/React/Inertia.
```
---

# 11. Recommended Execution Order

```txt
RG-566 Add upload rate limit middleware
RG-567 Test upload rate limit blocks excessive posts
RG-568 Add comment rate limit
RG-569 Test comment rate limit blocks spam
RG-570 Add report rate limit
RG-571 Test report rate limit blocks spam
RG-572 Add vote rate limit
RG-573 Test vote rate limit blocks abuse
RG-574 Add suspicious activity log placeholder
```
---

# 12. Release

После завершения Phase 34:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.15-phase34-rate-limiting-abuse-guards
git push -u origin release/v0.2.15-phase34-rate-limiting-abuse-guards
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.15-phase34-rate-limiting-abuse-guards -m "RateGuru Phase 34 Rate Limiting & Abuse Guards"
git push origin v0.2.15-phase34-rate-limiting-abuse-guards
```
