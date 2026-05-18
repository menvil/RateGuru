# RateGuru — Phase 5 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 5 — Post Creation Backend**  
Диапазон задач: **RG-113 → RG-134**  
Основа нумерации: исходный atomic backlog, где Phase 5 начинается с задачи 113 и заканчивается задачей 134.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 5 соответствует исходному блоку:

```txt
Phase 5 — Post Creation Backend
```

Правильный диапазон Phase 5:

```txt
RG-113 — Create CreatePostData object
RG-114 — Add CreatePostAction skeleton
RG-115 — Test CreatePostAction creates pending post for normal user
RG-116 — Implement pending post creation
RG-117 — Test CreatePostAction creates published post for trusted user
RG-118 — Implement trusted user auto-publish rule
RG-119 — Test banned user cannot create post
RG-120 — Implement banned user guard in CreatePostAction
RG-121 — Test post title is required
RG-122 — Add title validation request rules
RG-123 — Test post image is required
RG-124 — Add image validation request rules
RG-125 — Test post description is optional
RG-126 — Add description persistence
RG-127 — Test source_url is optional
RG-128 — Add source_url persistence
RG-129 — Test origin_truth can be stored
RG-130 — Add origin_truth persistence
RG-131 — Test cuisine_truth can be stored
RG-132 — Add cuisine_truth persistence
RG-133 — Test tags can be attached to post
RG-134 — Implement tag attach in CreatePostAction
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 5

Phase 5 создаёт backend-логику создания поста без полноценной image storage infrastructure.

После Phase 5 должно быть готово:

```txt
- CreatePostData DTO;
- CreatePostAction;
- pending post creation для обычного пользователя;
- auto-publish rule для trusted user;
- banned user guard;
- StorePostRequest validation для title/image/description/source_url/origin_truth/cuisine_truth/tags;
- persistence для description/source_url/origin_truth/cuisine_truth;
- tag attach в CreatePostAction;
- tests для ключевых правил.
```

---

# 3. Важный нюанс про изображение

В исходном backlog Phase 5 содержит задачи:

```txt
RG-123 — Test post image is required
RG-124 — Add image validation request rules
```

Но полноценная инфраструктура хранения изображения начинается в Phase 6:

```txt
RG-135 — Create ImageStorage interface
RG-136 — Create LocalImageStorage implementation
RG-140 — Test CreatePostAction calls ImageStorage
RG-141 — Store image_path on post
```

Поэтому в Phase 5:

```txt
- image required validation добавляется в StorePostRequest;
- CreatePostData может принимать UploadedFile или image placeholder field;
- CreatePostAction не обязан сохранять файл;
- image_path/image_url/thumbnail_url могут оставаться null;
- реальное сохранение файла запрещено до Phase 6.
```

Иначе Phase 5 начнёт незаметно тащить Phase 6 внутрь себя.

---

# 4. Scope Phase 5

## Входит

```txt
- app/Data/Posts/CreatePostData.php;
- app/Actions/Posts/CreatePostAction.php;
- app/Http/Requests/StorePostRequest.php;
- basic action tests;
- validation tests;
- post creation rules;
- trusted auto-publish rule;
- banned guard;
- tag attach.
```

## Не входит

```txt
- ImageStorage interface;
- LocalImageStorage;
- Cloudinary;
- ProcessUploadedImageJob;
- FeedQuery;
- Livewire UploadPostForm;
- UI upload modal logic;
- notifications;
- moderation logs;
- Filament resources;
- API controller;
- Redis;
- PostgreSQL.
```

---

# 5. Design Decisions

## 5.1. Action is the source of business logic

Контроллеры, Livewire и будущий API должны вызывать один и тот же action:

```txt
StorePostRequest
  ↓
CreatePostData
  ↓
CreatePostAction
  ↓
Post model / tags attach
```

Нельзя размазывать creation rules по request/controller/Livewire.

## 5.2. Validation belongs to FormRequest

Правила HTTP-входа должны жить в:

```txt
app/Http/Requests/StorePostRequest.php
```

Action не должен проверять “title required” как HTTP validation.  
Action может иметь domain guard:

```txt
- user cannot create content;
- invalid enum safety if DTO bypasses request;
- tags normalization safety.
```

## 5.3. CreatePostData is transport-independent

DTO должен быть пригоден для:

```txt
- web controller;
- Livewire;
- future API controller;
- tests.
```

Он не должен зависеть от конкретного controller.

## 5.4. Tags can be attached, but not auto-created unless explicitly allowed

В Phase 5 задача говорит “tags can be attached”.  
Лучшее поведение для MVP:

```txt
- CreatePostData receives tag IDs;
- CreatePostAction attaches existing tags by id;
- CreatePostAction does not create new tags from arbitrary strings.
```

Создание/merge тегов — отдельная будущая задача.  
Так мы избегаем мусора в tags table.

---

# 6. GitFlow для Phase 5

## Base branch

Все задачи Phase 5 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-113-create-create-post-data-object
feature/RG-114-add-create-post-action-skeleton
feature/RG-122-add-title-validation-request-rules
```

## Commit format

```txt
RG-113: Create CreatePostData object
RG-114: Add CreatePostAction skeleton
RG-122: Add title validation request rules
```

## Release branch

После выполнения `RG-113`–`RG-134`:

```txt
release/v0.0.6-phase5-post-creation-backend
```

## Tag

После merge release branch в `main`:

```txt
v0.0.6-phase5-post-creation-backend
```

---

# 7. TDD Rules for Phase 5

## Для DTO

Сначала unit test:

```txt
- DTO can be constructed;
- DTO stores title/description/source_url/origin_truth/cuisine_truth/tag_ids;
- DTO accepts image as nullable/UploadedFile depending on implementation.
```

## Для Action

Сначала feature/action test:

```txt
- normal user creates pending post;
- trusted user creates published post;
- banned user cannot create post;
- description/source_url/origin_truth/cuisine_truth persist;
- tags attach.
```

## Для FormRequest

Сначала HTTP/request validation tests:

```txt
- title required;
- image required;
- image must be valid image;
- description optional;
- source_url optional and url;
- origin_truth valid enum;
- cuisine_truth valid enum;
- tags array exists/valid ids.
```

Если в Phase 5 нет настоящего route/controller, можно тестировать FormRequest rules через Validator directly.  
Не надо создавать UI или полноценный upload route только ради validation tests.

---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Tests / Validation
Type: Test / Feature / Action / DTO / Request
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
- Тест написан первым
- Тест падает до реализации, если применимо
- Реализация минимальная
- Тест проходит
- Все связанные тесты проходят
- Нет логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 9. Phase 5 Atomic Tasks

---

## RG-113 — Create CreatePostData Object

**Area:** Backend / Tests  
**Type:** DTO  
**Priority:** P0  
**Branch:** `feature/RG-113-create-create-post-data-object`  
**Base branch:** develop 
**Depends on:** RG-112

### Goal

Создать DTO `CreatePostData`, который будет переносить данные создания поста из request/Livewire/API в `CreatePostAction`.

### TDD step

Сначала unit test:

```php
it('can create create post data object', function () {
    $data = new CreatePostData(
        title: 'Homemade pasta',
        description: 'Simple dinner',
        sourceUrl: 'https://example.com/source',
        originTruth: OriginType::Homemade,
        cuisineTruth: CuisineType::Italian,
        tagIds: [1, 2],
        image: null,
    );

    expect($data->title)->toBe('Homemade pasta');
    expect($data->description)->toBe('Simple dinner');
    expect($data->sourceUrl)->toBe('https://example.com/source');
    expect($data->originTruth)->toBe(OriginType::Homemade);
    expect($data->cuisineTruth)->toBe(CuisineType::Italian);
    expect($data->tagIds)->toBe([1, 2]);
});
```

Тест должен упасть до создания класса.

### Implementation

Создать:

```txt
app/Data/Posts/CreatePostData.php
```

Пример структуры:

```php
namespace App\Data\Posts;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use Illuminate\Http\UploadedFile;

final readonly class CreatePostData
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $sourceUrl = null,
        public OriginType $originTruth = OriginType::Unknown,
        public CuisineType $cuisineTruth = CuisineType::Unknown,
        public array $tagIds = [],
        public ?UploadedFile $image = null,
    ) {}
}
```

Можно добавить named constructor `fromArray()` позже, но не обязательно в этой задаче.

### Acceptance criteria

- `CreatePostData` существует.
- DTO immutable/readonly.
- DTO хранит title.
- DTO хранит optional description/sourceUrl.
- DTO хранит originTruth/cuisineTruth enum values.
- DTO хранит tagIds.
- DTO может принять nullable image.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- DTO создан.
- Тест проходит.
- Коммит: `RG-113: Create CreatePostData object`

### Files likely touched

```txt
app/Data/Posts/CreatePostData.php
tests/Unit/Data/CreatePostDataTest.php
```

---

## RG-114 — Add CreatePostAction Skeleton

**Area:** Backend / Tests  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-114-add-create-post-action-skeleton`  
**Base branch:** develop 
**Depends on:** RG-113

### Goal

Создать skeleton `CreatePostAction`, который принимает `User` и `CreatePostData`.

### TDD step

Unit/feature test:

```php
it('has create post action class with handle method', function () {
    $action = app(CreatePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания класса.

### Implementation

Создать:

```txt
app/Actions/Posts/CreatePostAction.php
```

Skeleton:

```php
namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Models\Post;
use App\Models\User;

final class CreatePostAction
{
    public function handle(User $user, CreatePostData $data): Post
    {
        // implementation in following tasks
    }
}
```

На этой задаче допустимо временно бросить `LogicException`, если следующие задачи сразу реализуют поведение.  
Лучше вернуть минимальный Post только в RG-116.

### Acceptance criteria

- `CreatePostAction` существует.
- Есть метод `handle(User $user, CreatePostData $data): Post`.
- Класс резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-114: Add CreatePostAction skeleton`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
tests/Unit/Actions/CreatePostActionSkeletonTest.php
```

---

## RG-115 — Test CreatePostAction Creates Pending Post For Normal User

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-115-test-create-post-action-creates-pending-post-for-normal-user`  
**Base branch:** develop 
**Depends on:** RG-114

### Goal

Написать падающий тест: обычный пользователь создаёт post со статусом `pending`.

### TDD step

Feature test:

```php
it('creates a pending post for normal user', function () {
    $user = User::factory()->create();

    $data = new CreatePostData(
        title: 'Homemade pasta',
        description: null,
        sourceUrl: null,
        originTruth: OriginType::Unknown,
        cuisineTruth: CuisineType::Unknown,
        tagIds: [],
        image: null,
    );

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post)->toBeInstanceOf(Post::class);
    expect($post->exists)->toBeTrue();
    expect($post->user_id)->toBe($user->id);
    expect($post->title)->toBe('Homemade pasta');
    expect($post->status)->toBe(PostStatus::Pending);
    expect($post->published_at)->toBeNull();
});
```

Этот тест должен упасть до RG-116.

### Implementation

Только добавить тест.  
Не реализовывать action в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест проверяет pending status для normal user.
- Тест проверяет user_id/title.
- Тест падает до реализации RG-116.

### Definition of Done

- Тест добавлен.
- Тест запускается отдельно.
- Ожидаемо падает до реализации.
- Коммит: `RG-115: Test CreatePostAction creates pending post for normal user`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-116 — Implement Pending Post Creation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-116-implement-pending-post-creation`  
**Base branch:** develop 
**Depends on:** RG-115

### Goal

Реализовать создание pending post для обычного пользователя.

### TDD step

Использовать падающий тест из RG-115.

### Implementation

В `CreatePostAction::handle()` создать post:

```php
return Post::create([
    'user_id' => $user->id,
    'title' => $data->title,
    'description' => $data->description,
    'source_url' => $data->sourceUrl,
    'origin_truth' => $data->originTruth,
    'cuisine_truth' => $data->cuisineTruth,
    'status' => PostStatus::Pending,
    'published_at' => null,
]);
```

Пока не сохранять image.  
Пока не attach tags — это RG-134.  
Пока не trusted rule — это RG-118.  
Пока не banned guard — это RG-120.

### Acceptance criteria

- Тест RG-115 проходит.
- Post создаётся в database.
- Status = pending.
- published_at = null.
- Нет image storage logic.
- Нет tag attach logic.

### Definition of Done

- Реализация минимальная.
- Тест RG-115 проходит.
- Все action tests проходят.
- Коммит: `RG-116: Implement pending post creation`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
```

---

## RG-117 — Test CreatePostAction Creates Published Post For Trusted User

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-117-test-create-post-action-creates-published-post-for-trusted-user`  
**Base branch:** develop 
**Depends on:** RG-116

### Goal

Написать падающий тест: trusted user создаёт post со статусом `published`.

### TDD step

Feature test:

```php
it('creates a published post for trusted user', function () {
    $user = User::factory()->trusted()->create();

    $data = new CreatePostData(title: 'Trusted dish');

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});
```

Тест должен упасть до RG-118.

### Implementation

Только добавить тест.  
Не реализовывать trusted rule в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест использует trusted user factory state.
- Тест проверяет published status.
- Тест проверяет published_at.
- Тест падает до RG-118.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-117: Test CreatePostAction creates published post for trusted user`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-118 — Implement Trusted User Auto-Publish Rule

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-118-implement-trusted-user-auto-publish-rule`  
**Base branch:** develop 
**Depends on:** RG-117

### Goal

Реализовать правило: trusted user создаёт post сразу published.

### TDD step

Использовать падающий тест из RG-117.

### Implementation

В `CreatePostAction` добавить правило:

```php
$isTrusted = $user->trust_level >= 10 && $user->status === UserStatus::Active;
```

Если trusted:

```php
$status = PostStatus::Published;
$publishedAt = now();
```

Иначе:

```php
$status = PostStatus::Pending;
$publishedAt = null;
```

Не добавлять notifications/moderation logs.

### Acceptance criteria

- Trusted user создаёт published post.
- published_at заполнен.
- Normal user всё ещё создаёт pending post.
- Banned guard ещё не реализован в этой задаче.
- Тесты RG-115/RG-117 проходят.

### Definition of Done

- Реализация минимальная.
- Тесты проходят.
- Коммит: `RG-118: Implement trusted user auto-publish rule`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-119 — Test Banned User Cannot Create Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-119-test-banned-user-cannot-create-post`  
**Base branch:** develop 
**Depends on:** RG-118

### Goal

Написать падающий тест: banned user не может создать post.

### TDD step

Feature test:

```php
it('does not allow banned user to create post', function () {
    $user = User::factory()->banned()->create();

    $data = new CreatePostData(title: 'Blocked dish');

    app(CreatePostAction::class)->handle($user, $data);
})->throws(CannotCreatePostException::class);
```

Также проверить, что post не создан:

```php
expect(Post::query()->count())->toBe(0);
```

Если кастомный exception не готов, тест должен фиксировать его создание в RG-120.

### Implementation

Только добавить тест.  
Не реализовывать guard в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест использует banned user factory state.
- Тест ожидает explicit exception.
- Тест проверяет, что post не создан.
- Тест падает до RG-120.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-119: Test banned user cannot create post`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-120 — Implement Banned User Guard In CreatePostAction

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-120-implement-banned-user-guard-in-create-post-action`  
**Base branch:** develop 
**Depends on:** RG-119

### Goal

Реализовать guard, запрещающий banned user создавать посты.

### TDD step

Использовать падающий тест из RG-119.

### Implementation

Создать exception:

```txt
app/Exceptions/Posts/CannotCreatePostException.php
```

Пример:

```php
final class CannotCreatePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to create posts.');
    }
}
```

В `CreatePostAction` перед созданием post:

```php
if (! $user->canCreateContent()) {
    throw CannotCreatePostException::becauseUserIsNotAllowed();
}
```

Метод `canCreateContent()` уже должен существовать с Phase 2.

### Acceptance criteria

- Banned user получает `CannotCreatePostException`.
- Post не создаётся.
- Active normal user всё ещё может создать pending post.
- Trusted active user всё ещё может создать published post.
- Тесты проходят.

### Definition of Done

- Exception добавлен.
- Guard добавлен.
- Тесты проходят.
- Коммит: `RG-120: Implement banned user guard in CreatePostAction`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
app/Exceptions/Posts/CannotCreatePostException.php
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-121 — Test Post Title Is Required

**Area:** Validation / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-121-test-post-title-is-required`  
**Base branch:** develop 
**Depends on:** RG-120

### Goal

Написать тест validation rule: title обязателен.

### TDD step

Validation test через `Validator` и request rules:

```php
it('requires post title', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('title'))->toBeTrue();
});
```

Тест должен упасть до RG-122, если request/rule ещё нет.

### Implementation

Только добавить тест.  
Если `StorePostRequest` ещё не существует, тест будет красным.

### Acceptance criteria

- Тест существует.
- Тест проверяет отсутствие title.
- Тест ожидает validation error по title.
- Тест падает до RG-122.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-121: Test post title is required`

### Files likely touched

```txt
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-122 — Add Title Validation Request Rules

**Area:** Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-122-add-title-validation-request-rules`  
**Base branch:** develop 
**Depends on:** RG-121

### Goal

Создать `StorePostRequest` и добавить validation rules для title.

### TDD step

Использовать падающий тест из RG-121.

### Implementation

Создать:

```bash
php artisan make:request StorePostRequest
```

Файл:

```txt
app/Http/Requests/StorePostRequest.php
```

Rules:

```php
'title' => ['required', 'string', 'min:3', 'max:120'],
```

Authorize:

```php
public function authorize(): bool
{
    return $this->user()?->canCreateContent() ?? false;
}
```

Важно: если request tests запускаются без authenticated user, можно либо:
- тестировать rules отдельно, не authorize;
- либо сделать отдельные authorize tests позже.

В этой задаче главное — title rules.

### Acceptance criteria

- `StorePostRequest` существует.
- `title` имеет required/string/min/max.
- Тест RG-121 проходит.
- Нет controller/UI logic.

### Definition of Done

- Request создан.
- Title rules добавлены.
- Тест проходит.
- Коммит: `RG-122: Add title validation request rules`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-123 — Test Post Image Is Required

**Area:** Validation / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-123-test-post-image-is-required`  
**Base branch:** develop 
**Depends on:** RG-122

### Goal

Написать тест validation rule: image обязателен.

### TDD step

Validation test:

```php
it('requires post image', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('image'))->toBeTrue();
});
```

Тест должен упасть до RG-124.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет отсутствие image.
- Тест ожидает validation error по image.
- Тест падает до RG-124.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-123: Test post image is required`

### Files likely touched

```txt
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-124 — Add Image Validation Request Rules

**Area:** Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-124-add-image-validation-request-rules`  
**Base branch:** develop 
**Depends on:** RG-123

### Goal

Добавить validation rules для image.

### TDD step

Использовать падающий тест из RG-123.

Дополнительно добавить тест:

```php
it('requires image to be a valid image file', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->create('not-image.txt', 10, 'text/plain'),
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('image'))->toBeTrue();
});
```

### Implementation

В `StorePostRequest` добавить:

```php
'image' => ['required', 'image', 'max:5120'],
```

5MB достаточно для MVP.  
Не сохранять изображение в этой фазе.

### Acceptance criteria

- `image` required.
- `image` должен быть изображением.
- Max size ограничен.
- Тесты проходят.
- Нет ImageStorage logic.

### Definition of Done

- Image rules добавлены.
- Тесты проходят.
- Коммит: `RG-124: Add image validation request rules`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-125 — Test Post Description Is Optional

**Area:** Validation / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-125-test-post-description-is-optional`  
**Base branch:** develop 
**Depends on:** RG-124

### Goal

Написать тест: description необязателен.

### TDD step

Validation test:

```php
it('allows description to be omitted', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->errors()->has('description'))->toBeFalse();
});
```

### Implementation

Только добавить тест.  
Если description rules ещё нет, тест может уже проходить. Это допустимо как regression test.

### Acceptance criteria

- Тест существует.
- Тест проверяет, что description можно не передавать.
- Тест проходит или падает до RG-126, если текущие rules неправильные.

### Definition of Done

- Тест добавлен.
- Тест зафиксирован.
- Коммит: `RG-125: Test post description is optional`

### Files likely touched

```txt
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-126 — Add Description Persistence

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-126-add-description-persistence`  
**Base branch:** develop 
**Depends on:** RG-125

### Goal

Добавить validation rule и persistence для description.

### TDD step

Action test:

```php
it('persists post description', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        description: 'Fresh pasta with tomato sauce',
    ));

    expect($post->fresh()->description)->toBe('Fresh pasta with tomato sauce');
});
```

Если persistence уже есть из RG-116, этот тест станет regression test.

### Implementation

В `StorePostRequest` добавить:

```php
'description' => ['nullable', 'string', 'max:2000'],
```

В `CreatePostAction` убедиться, что:

```php
'description' => $data->description,
```

уже сохраняется.

### Acceptance criteria

- Description optional.
- Description max length ограничен.
- CreatePostAction сохраняет description.
- Тесты проходят.

### Definition of Done

- Тест добавлен/проходит.
- Request rules обновлены.
- Persistence подтверждён.
- Коммит: `RG-126: Add description persistence`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-127 — Test Source Url Is Optional

**Area:** Validation / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-127-test-source-url-is-optional`  
**Base branch:** develop 
**Depends on:** RG-126

### Goal

Написать тест: source_url необязателен.

### TDD step

Validation test:

```php
it('allows source url to be omitted', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->errors()->has('source_url'))->toBeFalse();
});
```

Также можно добавить test invalid URL в RG-128.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Отсутствие source_url не даёт validation error.
- Тест проходит или ожидаемо падает до RG-128.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-127: Test source_url is optional`

### Files likely touched

```txt
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-128 — Add Source Url Persistence

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-128-add-source-url-persistence`  
**Base branch:** develop 
**Depends on:** RG-127

### Goal

Добавить validation и persistence для `source_url`.

### TDD step

Action test:

```php
it('persists source url', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        sourceUrl: 'https://example.com/original',
    ));

    expect($post->fresh()->source_url)->toBe('https://example.com/original');
});
```

Validation test:

```php
it('requires source url to be valid url when provided', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
        'source_url' => 'not-a-url',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('source_url'))->toBeTrue();
});
```

### Implementation

В `StorePostRequest` добавить:

```php
'source_url' => ['nullable', 'url', 'max:2048'],
```

В `CreatePostAction` убедиться:

```php
'source_url' => $data->sourceUrl,
```

### Acceptance criteria

- source_url optional.
- source_url валидируется как URL, если передан.
- source_url сохраняется в post.
- Тесты проходят.

### Definition of Done

- Validation и action tests проходят.
- Persistence реализован/подтверждён.
- Коммит: `RG-128: Add source_url persistence`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-129 — Test Origin Truth Can Be Stored

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-129-test-origin-truth-can-be-stored`  
**Base branch:** develop 
**Depends on:** RG-128

### Goal

Написать тест: `origin_truth` можно сохранить.

### TDD step

Action test:

```php
it('stores origin truth', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        originTruth: OriginType::Homemade,
    ));

    expect($post->fresh()->origin_truth)->toBe(OriginType::Homemade);
});
```

Тест должен упасть, если action не сохраняет origin_truth.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет OriginType enum persistence.
- Тест падает до RG-130, если persistence отсутствует.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-129: Test origin_truth can be stored`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-130 — Add Origin Truth Persistence

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-130-add-origin-truth-persistence`  
**Base branch:** develop 
**Depends on:** RG-129

### Goal

Добавить validation и persistence для `origin_truth`.

### TDD step

Использовать тест из RG-129.

Дополнительно validation test:

```php
it('validates origin truth enum value', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
        'origin_truth' => 'invalid',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('origin_truth'))->toBeTrue();
});
```

### Implementation

В `StorePostRequest` добавить:

```php
'origin_truth' => ['nullable', Rule::enum(OriginType::class)],
```

В `CreatePostAction`:

```php
'origin_truth' => $data->originTruth,
```

DTO default уже должен быть `OriginType::Unknown`.

### Acceptance criteria

- origin_truth optional.
- origin_truth валидируется через enum.
- origin_truth сохраняется.
- Default unknown сохраняется, если не передано.
- Тесты проходят.

### Definition of Done

- Persistence реализован.
- Validation добавлена.
- Тесты проходят.
- Коммит: `RG-130: Add origin_truth persistence`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-131 — Test Cuisine Truth Can Be Stored

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-131-test-cuisine-truth-can-be-stored`  
**Base branch:** develop 
**Depends on:** RG-130

### Goal

Написать тест: `cuisine_truth` можно сохранить.

### TDD step

Action test:

```php
it('stores cuisine truth', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Homemade pasta',
        cuisineTruth: CuisineType::Italian,
    ));

    expect($post->fresh()->cuisine_truth)->toBe(CuisineType::Italian);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет CuisineType enum persistence.
- Тест падает до RG-132, если persistence отсутствует.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-131: Test cuisine_truth can be stored`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-132 — Add Cuisine Truth Persistence

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-132-add-cuisine-truth-persistence`  
**Base branch:** develop 
**Depends on:** RG-131

### Goal

Добавить validation и persistence для `cuisine_truth`.

### TDD step

Использовать тест из RG-131.

Дополнительно validation test:

```php
it('validates cuisine truth enum value', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
        'cuisine_truth' => 'invalid',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('cuisine_truth'))->toBeTrue();
});
```

### Implementation

В `StorePostRequest` добавить:

```php
'cuisine_truth' => ['nullable', Rule::enum(CuisineType::class)],
```

В `CreatePostAction`:

```php
'cuisine_truth' => $data->cuisineTruth,
```

DTO default уже должен быть `CuisineType::Unknown`.

### Acceptance criteria

- cuisine_truth optional.
- cuisine_truth валидируется через enum.
- cuisine_truth сохраняется.
- Default unknown сохраняется, если не передано.
- Тесты проходят.

### Definition of Done

- Persistence реализован.
- Validation добавлена.
- Тесты проходят.
- Коммит: `RG-132: Add cuisine_truth persistence`

### Files likely touched

```txt
app/Http/Requests/StorePostRequest.php
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

## RG-133 — Test Tags Can Be Attached To Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-133-test-tags-can-be-attached-to-post`  
**Base branch:** develop 
**Depends on:** RG-132, RG-097

### Goal

Написать тест: CreatePostAction может прикрепить существующие tags к post.

### TDD step

Action test:

```php
it('attaches tags to created post', function () {
    $user = User::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Tagged dish',
        tagIds: $tags->pluck('id')->all(),
    ));

    expect($post->tags()->count())->toBe(2);
    expect($post->tags()->pluck('id')->all())
        ->toEqualCanonicalizing($tags->pluck('id')->all());
});
```

Тест должен упасть до RG-134.

### Implementation

Только добавить тест.  
Не реализовывать attach logic в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест использует существующие tags.
- Тест проверяет attach через relation.
- Тест падает до RG-134.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-133: Test tags can be attached to post`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-134 — Implement Tag Attach In CreatePostAction

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-134-implement-tag-attach-in-create-post-action`  
**Base branch:** develop 
**Depends on:** RG-133

### Goal

Реализовать attach существующих tags при создании post.

### TDD step

Использовать падающий тест из RG-133.

Дополнительно validation test для request:

```php
it('validates tag ids exist', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
        'tag_ids' => [999999],
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('tag_ids.0'))->toBeTrue();
});
```

### Implementation

В `StorePostRequest` добавить:

```php
'tag_ids' => ['nullable', 'array', 'max:10'],
'tag_ids.*' => ['integer', 'exists:tags,id'],
```

В `CreatePostAction` после создания post:

```php
if ($data->tagIds !== []) {
    $post->tags()->sync($data->tagIds);
}
```

Лучше использовать `sync()` на новом post — результат тот же, но не будет дубликатов.

Важно:

```txt
- не создавать новые tags из строк;
- не принимать tag names;
- не делать slug generation;
- не делать tag normalization;
- не делать moderation/tag merge.
```

### Acceptance criteria

- CreatePostAction attach existing tags.
- Invalid tag IDs не проходят request validation.
- tag_ids optional.
- Ограничение max tags есть.
- Тест RG-133 проходит.
- Все CreatePostAction tests проходят.

### Definition of Done

- Attach logic реализована.
- Request validation добавлена.
- Тесты проходят.
- Коммит: `RG-134: Implement tag attach in CreatePostAction`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
app/Http/Requests/StorePostRequest.php
tests/Feature/Actions/CreatePostActionTest.php
tests/Feature/Requests/StorePostRequestTest.php
```

---

# 10. Phase 5 Completion Criteria

Phase 5 завершена, когда:

```txt
- RG-113–RG-134 выполнены;
- CreatePostData существует;
- CreatePostAction существует;
- normal user создаёт pending post;
- trusted user создаёт published post;
- banned user не может создать post;
- title validation работает;
- image required validation работает;
- description optional и сохраняется;
- source_url optional, валидируется и сохраняется;
- origin_truth optional, валидируется и сохраняется;
- cuisine_truth optional, валидируется и сохраняется;
- existing tags can be attached;
- request validation tests проходят;
- action tests проходят;
- composer test проходит;
- нет image storage implementation вне Phase 6.
```

---

# 11. Что нельзя делать в Phase 5

Без отдельной задачи нельзя:

```txt
- создавать ImageStorage interface;
- сохранять файл изображения;
- писать LocalImageStorage;
- подключать Cloudinary;
- писать ProcessUploadedImageJob;
- делать FeedQuery;
- делать UploadPostForm Livewire;
- делать UI upload modal behavior;
- делать notifications;
- делать moderation logs;
- делать Filament resources;
- делать API controller;
- добавлять Redis;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia.
```

---

# 12. Recommended Execution Order

```txt
RG-113 Create CreatePostData object
RG-114 Add CreatePostAction skeleton
RG-115 Test CreatePostAction creates pending post for normal user
RG-116 Implement pending post creation
RG-117 Test CreatePostAction creates published post for trusted user
RG-118 Implement trusted user auto-publish rule
RG-119 Test banned user cannot create post
RG-120 Implement banned user guard in CreatePostAction
RG-121 Test post title is required
RG-122 Add title validation request rules
RG-123 Test post image is required
RG-124 Add image validation request rules
RG-125 Test post description is optional
RG-126 Add description persistence
RG-127 Test source_url is optional
RG-128 Add source_url persistence
RG-129 Test origin_truth can be stored
RG-130 Add origin_truth persistence
RG-131 Test cuisine_truth can be stored
RG-132 Add cuisine_truth persistence
RG-133 Test tags can be attached to post
RG-134 Implement tag attach in CreatePostAction
```

---

# 13. Release

После завершения Phase 5:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.6-phase5-post-creation-backend
git push -u origin release/v0.0.6-phase5-post-creation-backend
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.6-phase5-post-creation-backend -m "RateGuru Phase 5 post creation backend"
git push origin v0.0.6-phase5-post-creation-backend
```
