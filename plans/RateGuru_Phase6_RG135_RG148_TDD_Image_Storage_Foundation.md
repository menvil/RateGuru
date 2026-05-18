# RateGuru — Phase 6 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 6 — Image Storage Foundation**  
Диапазон задач: **RG-135 → RG-148**  
Основа нумерации: исходный atomic backlog, где Phase 6 начинается с задачи 135 и заканчивается задачей 148.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 6 соответствует исходному блоку:

```txt
Phase 6 — Image Storage Foundation
```

Правильный диапазон Phase 6:

```txt
RG-135 — Create ImageStorage interface
RG-136 — Create LocalImageStorage implementation
RG-137 — Bind ImageStorage to LocalImageStorage in service container
RG-138 — Test LocalImageStorage stores uploaded file
RG-139 — Implement local image storage
RG-140 — Test CreatePostAction calls ImageStorage
RG-141 — Store image_path on post
RG-142 — Add thumbnail_url nullable field handling
RG-143 — Create ProcessUploadedImageJob skeleton
RG-144 — Test ProcessUploadedImageJob can be dispatched
RG-145 — Dispatch ProcessUploadedImageJob after post creation
RG-146 — Add image cleanup helper placeholder
RG-147 — Add CloudinaryImageStorage placeholder class
RG-148 — Add storage config switch
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 6

Phase 6 добавляет минимальную инфраструктуру хранения изображений.

После Phase 6 должно быть готово:

```txt
- ImageStorage interface;
- LocalImageStorage implementation;
- binding ImageStorage → LocalImageStorage;
- тест, что LocalImageStorage сохраняет UploadedFile;
- CreatePostAction вызывает ImageStorage, если image передан;
- post.image_path сохраняется;
- thumbnail_url остаётся nullable и безопасно обрабатывается;
- ProcessUploadedImageJob skeleton;
- job dispatch после создания post;
- image cleanup helper placeholder;
- CloudinaryImageStorage placeholder;
- storage config switch.
```

---

# 3. Важное ограничение Phase 6

Phase 6 — это **foundation**, а не полноценный image processing pipeline.

В эту фазу НЕ входит:

```txt
- реальное создание thumbnail;
- resize/crop;
- оптимизация webp/avif;
- EXIF strip;
- Cloudinary upload;
- R2/S3 upload;
- queue worker production setup;
- image moderation;
- async processing result handling;
- UI preview;
- Livewire upload form;
- CDN strategy.
```

Эти вещи будут позже.

---

# 4. Scope Phase 6

## Входит

```txt
- app/Services/Images/ImageStorage.php;
- app/Services/Images/StoredImage.php или ImageStorageResult.php;
- app/Services/Images/LocalImageStorage.php;
- binding в service provider;
- config/rateguru.php или config/image-storage.php;
- CreatePostAction integration;
- ProcessUploadedImageJob skeleton;
- image cleanup helper placeholder;
- CloudinaryImageStorage placeholder.
```

## Не входит

```txt
- реальный Cloudinary SDK;
- real thumbnail generation;
- image optimization;
- Livewire upload UI;
- API upload endpoint;
- PostFeed UI;
- Filament media admin;
- moderation scanner;
- Redis/Horizon;
- PostgreSQL.
```

---

# 5. Design Decisions

## 5.1. ImageStorage interface должен быть маленьким

Не надо делать огромный abstraction layer.

Минимально:

```php
public function storePostImage(UploadedFile $file, User $user): StoredImage;
```

В Phase 6 достаточно хранить:

```txt
path
url nullable
thumbnailUrl nullable
disk
```

## 5.2. Stored result лучше отдельным readonly DTO

Не возвращать просто строку.  
Иначе через 2 фазы придётся ломать сигнатуры.

Рекомендуемый DTO:

```php
final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $url = null,
        public ?string $thumbnailUrl = null,
        public string $disk = 'public',
    ) {}
}
```

## 5.3. Local storage first

На старте:

```txt
ImageStorage → LocalImageStorage
```

Файлы складываем в:

```txt
storage/app/public/posts/{user_id}/...
```

или через Laravel disk:

```txt
posts/{user_id}/...
```

В БД сохраняем `image_path`.

`image_url` и `thumbnail_url` можно оставить null или заполнить через `Storage::disk($disk)->url($path)`, если public disk правильно настроен.

## 5.4. CreatePostAction не должен знать реализацию storage

Правильно:

```php
public function __construct(
    private ImageStorage $imageStorage,
) {}
```

Неправильно:

```php
Storage::disk('public')->putFile(...) прямо в CreatePostAction
```

## 5.5. ProcessUploadedImageJob skeleton не должен делать реальную обработку

Job пока должен быть безопасным placeholder:

```txt
- принимает post id;
- может загрузить post;
- ничего destructive не делает;
- готов к будущему resize/thumbnail pipeline.
```

---

# 6. GitFlow для Phase 6

## Base branch

Все задачи Phase 6 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-135-create-image-storage-interface
feature/RG-139-implement-local-image-storage
feature/RG-145-dispatch-process-uploaded-image-job-after-post-creation
```

## Commit format

```txt
RG-135: Create ImageStorage interface
RG-139: Implement local image storage
RG-145: Dispatch ProcessUploadedImageJob after post creation
```

## Release branch

После выполнения `RG-135`–`RG-148`:

```txt
release/v0.0.7-phase6-image-storage-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.0.7-phase6-image-storage-foundation
```

---

# 7. TDD Rules for Phase 6

## Для interface/DTO

Пишем unit tests на DTO и container binding, где уместно.

## Для LocalImageStorage

Используем Laravel storage fake:

```php
Storage::fake('public');
```

и fake uploaded image:

```php
UploadedFile::fake()->image('dish.jpg');
```

## Для CreatePostAction integration

Мокаем `ImageStorage` через container и проверяем:

```txt
- ImageStorage вызывается;
- image_path сохраняется;
- action не вызывает конкретный LocalImageStorage напрямую.
```

## Для jobs

Тестируем dispatch через Bus fake:

```php
Bus::fake();
Bus::assertDispatched(ProcessUploadedImageJob::class);
```

---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Storage / Tests / Config
Type: Test / Feature / Service / Job / Config
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

# 9. Phase 6 Atomic Tasks

---

## RG-135 — Create ImageStorage Interface

**Area:** Backend / Storage  
**Type:** Service  
**Priority:** P0  
**Branch:** `feature/RG-135-create-image-storage-interface`  
**Depends on:** RG-134

### Goal

Создать контракт `ImageStorage`, чтобы `CreatePostAction` не зависел от конкретного storage backend.

### TDD step

Unit test на наличие interface и DTO:

```php
it('has image storage interface contract', function () {
    expect(interface_exists(ImageStorage::class))->toBeTrue();
});
```

Тест должен упасть до создания interface.

### Implementation

Создать директорию:

```txt
app/Services/Images/
```

Создать interface:

```txt
app/Services/Images/ImageStorage.php
```

Пример:

```php
namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage;
}
```

Создать DTO:

```txt
app/Services/Images/StoredImage.php
```

Пример:

```php
namespace App\Services\Images;

final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $url = null,
        public ?string $thumbnailUrl = null,
        public string $disk = 'public',
    ) {}
}
```

### Acceptance criteria

- `ImageStorage` interface существует.
- У interface есть `storePostImage(UploadedFile $file, User $user): StoredImage`.
- `StoredImage` DTO существует.
- DTO содержит `path`, `url`, `thumbnailUrl`, `disk`.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Interface создан.
- DTO создан.
- Тест проходит.
- Коммит: `RG-135: Create ImageStorage interface`

### Files likely touched

```txt
app/Services/Images/ImageStorage.php
app/Services/Images/StoredImage.php
tests/Unit/Services/ImageStorageContractTest.php
```

---

## RG-136 — Create LocalImageStorage Implementation

**Area:** Backend / Storage  
**Type:** Service  
**Priority:** P0  
**Branch:** `feature/RG-136-create-local-image-storage-implementation`  
**Depends on:** RG-135

### Goal

Создать class skeleton `LocalImageStorage`, который реализует `ImageStorage`.

### TDD step

Unit test:

```php
it('local image storage implements image storage interface', function () {
    expect(new LocalImageStorage())->toBeInstanceOf(ImageStorage::class);
});
```

Если constructor требует disk/config, использовать container или передать defaults.

### Implementation

Создать:

```txt
app/Services/Images/LocalImageStorage.php
```

Skeleton:

```php
namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final class LocalImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

В этой задаче не реализовывать сохранение. Это RG-139.

### Acceptance criteria

- `LocalImageStorage` существует.
- Реализует `ImageStorage`.
- Метод `storePostImage()` существует.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Class skeleton создан.
- Тест проходит.
- Коммит: `RG-136: Create LocalImageStorage implementation`

### Files likely touched

```txt
app/Services/Images/LocalImageStorage.php
tests/Unit/Services/LocalImageStorageTest.php
```

---

## RG-137 — Bind ImageStorage To LocalImageStorage In Service Container

**Area:** Backend / Config  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-137-bind-image-storage-to-local-image-storage`  
**Depends on:** RG-136

### Goal

Зарегистрировать binding `ImageStorage` → `LocalImageStorage`.

### TDD step

Feature/unit test:

```php
it('resolves image storage contract to local implementation', function () {
    $storage = app(ImageStorage::class);

    expect($storage)->toBeInstanceOf(LocalImageStorage::class);
});
```

Тест должен упасть до binding.

### Implementation

Добавить binding в service provider.

Вариант:

```php
// app/Providers/AppServiceProvider.php

public function register(): void
{
    $this->app->bind(ImageStorage::class, LocalImageStorage::class);
}
```

Если уже есть отдельный provider для RateGuru services — использовать его.

### Acceptance criteria

- `app(ImageStorage::class)` возвращает `LocalImageStorage`.
- Binding не зависит от controller/Livewire.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Binding добавлен.
- Тест проходит.
- Коммит: `RG-137: Bind ImageStorage to LocalImageStorage in service container`

### Files likely touched

```txt
app/Providers/AppServiceProvider.php
tests/Feature/Services/ImageStorageBindingTest.php
```

---

## RG-138 — Test LocalImageStorage Stores Uploaded File

**Area:** Storage / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-138-test-local-image-storage-stores-uploaded-file`  
**Depends on:** RG-137

### Goal

Написать падающий тест: `LocalImageStorage` сохраняет uploaded image на public disk.

### TDD step

Feature test:

```php
it('stores uploaded post image locally', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $stored = app(ImageStorage::class)->storePostImage($file, $user);

    expect($stored->path)->not->toBeEmpty();
    expect($stored->disk)->toBe('public');

    Storage::disk('public')->assertExists($stored->path);
});
```

Тест должен упасть до RG-139.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест использует `Storage::fake('public')`.
- Тест использует `UploadedFile::fake()->image()`.
- Тест проверяет `StoredImage`.
- Тест проверяет файл на disk.
- Тест падает до RG-139.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-138: Test LocalImageStorage stores uploaded file`

### Files likely touched

```txt
tests/Feature/Services/LocalImageStorageTest.php
```

---

## RG-139 — Implement Local Image Storage

**Area:** Backend / Storage  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-139-implement-local-image-storage`  
**Depends on:** RG-138

### Goal

Реализовать локальное сохранение uploaded image.

### TDD step

Использовать падающий тест из RG-138.

### Implementation

В `LocalImageStorage::storePostImage()`:

```php
public function storePostImage(UploadedFile $file, User $user): StoredImage
{
    $path = $file->storePublicly(
        path: "posts/{$user->id}",
        options: ['disk' => 'public']
    );

    return new StoredImage(
        path: $path,
        url: Storage::disk('public')->url($path),
        thumbnailUrl: null,
        disk: 'public',
    );
}
```

Если текущая версия Laravel не поддерживает named args для `storePublicly`, использовать обычный вызов:

```php
$path = $file->storePublicly("posts/{$user->id}", 'public');
```

Важно:

```txt
- не делать resize;
- не делать thumbnail;
- не делать Cloudinary;
- не делать EXIF strip.
```

### Acceptance criteria

- LocalImageStorage сохраняет файл на public disk.
- Возвращает `StoredImage`.
- `path` заполнен.
- `url` nullable или корректно заполнен через disk url.
- `thumbnailUrl` = null.
- Тест RG-138 проходит.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-139: Implement local image storage`

### Files likely touched

```txt
app/Services/Images/LocalImageStorage.php
tests/Feature/Services/LocalImageStorageTest.php
```

---

## RG-140 — Test CreatePostAction Calls ImageStorage

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-140-test-create-post-action-calls-image-storage`  
**Depends on:** RG-139

### Goal

Написать падающий тест: `CreatePostAction` вызывает `ImageStorage`, если в `CreatePostData` передан image.

### TDD step

Feature test с mock:

```php
it('calls image storage when image is provided', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $fakeStorage = new class implements ImageStorage {
        public bool $called = false;

        public function storePostImage(UploadedFile $file, User $user): StoredImage
        {
            $this->called = true;

            return new StoredImage(
                path: 'posts/1/dish.jpg',
                url: '/storage/posts/1/dish.jpg',
                thumbnailUrl: null,
                disk: 'public',
            );
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    ));

    expect($fakeStorage->called)->toBeTrue();
});
```

Тест должен упасть до RG-141.

### Implementation

Только добавить тест.  
Не менять `CreatePostAction` в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест подменяет `ImageStorage` через container.
- Тест проверяет вызов `storePostImage`.
- Тест падает до RG-141.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-140: Test CreatePostAction calls ImageStorage`

### Files likely touched

```txt
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-141 — Store Image Path On Post

**Area:** Backend / Storage  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-141-store-image-path-on-post`  
**Depends on:** RG-140

### Goal

Интегрировать `ImageStorage` в `CreatePostAction` и сохранять `image_path` на post.

### TDD step

Использовать падающий тест из RG-140.

Добавить assertion:

```php
expect($post->fresh()->image_path)->toBe('posts/1/dish.jpg');
expect($post->fresh()->image_url)->toBe('/storage/posts/1/dish.jpg');
```

### Implementation

В `CreatePostAction` добавить constructor dependency:

```php
public function __construct(
    private readonly ImageStorage $imageStorage,
) {}
```

В `handle()`:

```php
$storedImage = null;

if ($data->image !== null) {
    $storedImage = $this->imageStorage->storePostImage($data->image, $user);
}

$post = Post::create([
    // ...
    'image_path' => $storedImage?->path,
    'image_url' => $storedImage?->url,
    'thumbnail_url' => $storedImage?->thumbnailUrl,
]);
```

Сохранить существующую логику:

```txt
- pending normal user;
- published trusted user;
- banned guard;
- description/source_url/origin_truth/cuisine_truth;
- tags attach.
```

### Acceptance criteria

- CreatePostAction вызывает ImageStorage, если image передан.
- image_path сохраняется.
- image_url сохраняется, если Storage возвращает url.
- thumbnail_url сохраняется как null, если thumbnailUrl null.
- Если image null, action не падает.
- Все CreatePostAction tests проходят.

### Definition of Done

- Тест RG-140 проходит.
- Integration добавлена.
- Старые action tests не сломаны.
- Коммит: `RG-141: Store image_path on post`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-142 — Add Thumbnail Url Nullable Field Handling

**Area:** Backend / Storage  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-142-add-thumbnail-url-nullable-field-handling`  
**Depends on:** RG-141

### Goal

Зафиксировать безопасную обработку `thumbnail_url`: поле может быть null, и action/model не должны требовать thumbnail.

### TDD step

Feature test:

```php
it('allows created post to have null thumbnail url', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish without thumbnail',
        image: null,
    ));

    expect($post->fresh()->thumbnail_url)->toBeNull();
});
```

Или test with fake storage returning null thumbnail:

```php
expect($post->fresh()->thumbnail_url)->toBeNull();
```

### Implementation

Проверить, что:

```txt
- posts.thumbnail_url nullable в migration;
- PostFactory default thumbnail_url = null;
- CreatePostAction использует $storedImage?->thumbnailUrl;
- null не заменяется на пустую строку.
```

Если всё уже работает — задача фиксирует regression test.

### Acceptance criteria

- thumbnail_url может быть null.
- CreatePostAction не требует thumbnail.
- LocalImageStorage возвращает thumbnailUrl null.
- Тест проходит.

### Definition of Done

- Regression test добавлен.
- Null handling подтверждён.
- Коммит: `RG-142: Add thumbnail_url nullable field handling`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
database/factories/PostFactory.php
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-143 — Create ProcessUploadedImageJob Skeleton

**Area:** Backend / Jobs  
**Type:** Job  
**Priority:** P0  
**Branch:** `feature/RG-143-create-process-uploaded-image-job-skeleton`  
**Depends on:** RG-141

### Goal

Создать skeleton job для будущей обработки загруженного изображения.

### TDD step

Unit test:

```php
it('has process uploaded image job', function () {
    $post = Post::factory()->create();

    $job = new ProcessUploadedImageJob($post->id);

    expect($job)->toBeInstanceOf(ProcessUploadedImageJob::class);
});
```

### Implementation

Создать job:

```bash
php artisan make:job ProcessUploadedImageJob
```

Рекомендуемая сигнатура:

```php
final class ProcessUploadedImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $postId,
    ) {}

    public function handle(): void
    {
        $post = Post::query()->find($this->postId);

        if (! $post) {
            return;
        }

        // Placeholder. Real image processing later.
    }
}
```

Важно:

```txt
- job ничего не меняет destructive;
- job не создаёт thumbnail;
- job не требует Redis;
- QUEUE_CONNECTION=sync/database достаточно.
```

### Acceptance criteria

- `ProcessUploadedImageJob` существует.
- Job implements ShouldQueue.
- Job принимает post id.
- handle безопасен, если post не найден.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Job skeleton создан.
- Тест проходит.
- Коммит: `RG-143: Create ProcessUploadedImageJob skeleton`

### Files likely touched

```txt
app/Jobs/ProcessUploadedImageJob.php
tests/Unit/Jobs/ProcessUploadedImageJobTest.php
```

---

## RG-144 — Test ProcessUploadedImageJob Can Be Dispatched

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-144-test-process-uploaded-image-job-can-be-dispatched`  
**Depends on:** RG-143

### Goal

Написать тест, что `ProcessUploadedImageJob` можно dispatch-ить.

### TDD step

Feature test:

```php
it('can dispatch process uploaded image job', function () {
    Bus::fake();

    $post = Post::factory()->create();

    ProcessUploadedImageJob::dispatch($post->id);

    Bus::assertDispatched(ProcessUploadedImageJob::class, function ($job) use ($post) {
        return $job->postId === $post->id;
    });
});
```

### Implementation

Только добавить тест.  
Если job уже dispatchable — тест сразу зелёный. Это нормально как regression test.

### Acceptance criteria

- Тест использует Bus::fake.
- Тест dispatch-ит job.
- Тест проверяет postId.
- Тест проходит.

### Definition of Done

- Dispatch test добавлен.
- Тест проходит.
- Коммит: `RG-144: Test ProcessUploadedImageJob can be dispatched`

### Files likely touched

```txt
tests/Feature/Jobs/ProcessUploadedImageJobDispatchTest.php
```

---

## RG-145 — Dispatch ProcessUploadedImageJob After Post Creation

**Area:** Backend / Jobs  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-145-dispatch-process-uploaded-image-job-after-post-creation`  
**Depends on:** RG-144

### Goal

Dispatch `ProcessUploadedImageJob` после создания post с image.

### TDD step

Feature test:

```php
it('dispatches process uploaded image job after post with image is created', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish',
        image: $file,
    ));

    Bus::assertDispatched(ProcessUploadedImageJob::class);
});
```

Дополнительный тест:

```php
it('does not dispatch process uploaded image job when no image is provided', function () {
    Bus::fake();

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish',
        image: null,
    ));

    Bus::assertNotDispatched(ProcessUploadedImageJob::class);
});
```

### Implementation

В `CreatePostAction` после создания post и сохранения image:

```php
if ($storedImage !== null) {
    ProcessUploadedImageJob::dispatch($post->id);
}
```

Не делать dispatch до создания post, потому что нужен post id.

### Acceptance criteria

- Job dispatch происходит, если image передан.
- Job не dispatch-ится, если image null.
- Dispatch происходит после post creation.
- Тесты проходят.

### Definition of Done

- Тесты написаны.
- Dispatch добавлен.
- Тесты проходят.
- Коммит: `RG-145: Dispatch ProcessUploadedImageJob after post creation`

### Files likely touched

```txt
app/Actions/Posts/CreatePostAction.php
tests/Feature/Actions/CreatePostActionTest.php
```

---

## RG-146 — Add Image Cleanup Helper Placeholder

**Area:** Backend / Storage  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-146-add-image-cleanup-helper-placeholder`  
**Depends on:** RG-139

### Goal

Добавить placeholder helper/service для будущей очистки изображений, не реализуя сложный cleanup pipeline.

### TDD step

Unit test:

```php
it('has image cleanup helper placeholder', function () {
    $cleanup = app(ImageCleanup::class);

    expect($cleanup)->toBeInstanceOf(ImageCleanup::class);
});
```

### Implementation

Создать:

```txt
app/Services/Images/ImageCleanup.php
```

Минимальный class:

```php
final class ImageCleanup
{
    public function delete(?string $path, string $disk = 'public'): void
    {
        if ($path === null || $path === '') {
            return;
        }

        // Real deletion can be enabled later.
    }
}
```

Есть два варианта:

## Вариант A — безопасный placeholder

Метод ничего не удаляет, только имеет сигнатуру.

## Вариант B — минимальное удаление через Storage

```php
Storage::disk($disk)->delete($path);
```

Для Phase 6 лучше Вариант A, если нет clear use-case.  
Но если хочется сразу полезность, можно Вариант B с тестом `Storage::fake`.

Рекомендация: сделать безопасный placeholder без вызова в production flow. Настоящее использование — отдельная задача при delete/replacement логике.

### Acceptance criteria

- `ImageCleanup` существует.
- У него есть метод `delete(?string $path, string $disk = 'public'): void`.
- Null/empty path безопасен.
- Сервис нигде автоматически не вызывается.
- Тест проходит.

### Definition of Done

- Тест написан.
- Placeholder создан.
- Тест проходит.
- Коммит: `RG-146: Add image cleanup helper placeholder`

### Files likely touched

```txt
app/Services/Images/ImageCleanup.php
tests/Unit/Services/ImageCleanupTest.php
```

---

## RG-147 — Add CloudinaryImageStorage Placeholder Class

**Area:** Backend / Storage  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-147-add-cloudinary-image-storage-placeholder-class`  
**Depends on:** RG-135

### Goal

Добавить placeholder class для будущей Cloudinary-интеграции без установки SDK и без реального upload.

### TDD step

Unit test:

```php
it('cloudinary image storage implements image storage interface', function () {
    expect(new CloudinaryImageStorage())->toBeInstanceOf(ImageStorage::class);
});
```

Если constructor позже будет требовать config/client, пока оставить no-arg constructor.

### Implementation

Создать:

```txt
app/Services/Images/CloudinaryImageStorage.php
```

Skeleton:

```php
final class CloudinaryImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        throw new RuntimeException('Cloudinary image storage is not configured yet.');
    }
}
```

Не устанавливать Cloudinary SDK.  
Не добавлять credentials.  
Не делать HTTP calls.

### Acceptance criteria

- `CloudinaryImageStorage` существует.
- Реализует `ImageStorage`.
- Метод бросает explicit exception, если его случайно использовать.
- Нет Cloudinary SDK dependency.
- Тест проходит.

### Definition of Done

- Тест написан.
- Placeholder создан.
- Тест проходит.
- Коммит: `RG-147: Add CloudinaryImageStorage placeholder class`

### Files likely touched

```txt
app/Services/Images/CloudinaryImageStorage.php
tests/Unit/Services/CloudinaryImageStorageTest.php
```

---

## RG-148 — Add Storage Config Switch

**Area:** Backend / Config  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-148-add-storage-config-switch`  
**Depends on:** RG-137, RG-147

### Goal

Добавить config switch, который определяет, какую реализацию `ImageStorage` использовать.

На старте default:

```txt
local
```

Cloudinary option есть, но не должна использоваться без явной настройки.

### TDD step

Feature/unit tests:

```php
it('uses local image storage by default', function () {
    config(['rateguru.images.driver' => 'local']);

    app()->forgetInstance(ImageStorage::class);

    expect(app(ImageStorage::class))->toBeInstanceOf(LocalImageStorage::class);
});
```

И:

```php
it('can resolve cloudinary image storage when configured', function () {
    config(['rateguru.images.driver' => 'cloudinary']);

    app()->forgetInstance(ImageStorage::class);

    expect(app(ImageStorage::class))->toBeInstanceOf(CloudinaryImageStorage::class);
});
```

Если binding singleton мешает тесту, использовать fresh app или container rebind в тесте.

### Implementation

Создать config:

```txt
config/rateguru.php
```

Содержимое:

```php
return [
    'images' => [
        'driver' => env('RATEGURU_IMAGE_DRIVER', 'local'),
        'disk' => env('RATEGURU_IMAGE_DISK', 'public'),
    ],
];
```

Обновить binding:

```php
$this->app->bind(ImageStorage::class, function () {
    return match (config('rateguru.images.driver')) {
        'local' => app(LocalImageStorage::class),
        'cloudinary' => app(CloudinaryImageStorage::class),
        default => app(LocalImageStorage::class),
    };
});
```

Обновить `.env.example`:

```env
RATEGURU_IMAGE_DRIVER=local
RATEGURU_IMAGE_DISK=public
```

В `AGENTS.md` добавить правило:

```txt
Do not switch image driver to cloudinary until real implementation exists.
```

### Acceptance criteria

- `config/rateguru.php` существует.
- Default image driver = local.
- `.env.example` содержит `RATEGURU_IMAGE_DRIVER=local`.
- Binding выбирает LocalImageStorage для local.
- Binding может выбрать CloudinaryImageStorage для cloudinary.
- Cloudinary driver не делает реальный upload.
- Тесты проходят.

### Definition of Done

- Config добавлен.
- Binding обновлён.
- `.env.example` обновлён.
- Тесты проходят.
- Коммит: `RG-148: Add storage config switch`

### Files likely touched

```txt
config/rateguru.php
.env.example
app/Providers/AppServiceProvider.php
AGENTS.md
tests/Feature/Services/ImageStorageBindingTest.php
```

---

# 10. Phase 6 Completion Criteria

Phase 6 завершена, когда:

```txt
- RG-135–RG-148 выполнены;
- ImageStorage interface существует;
- StoredImage DTO существует;
- LocalImageStorage существует и сохраняет UploadedFile через public disk;
- ImageStorage binding работает;
- CreatePostAction вызывает ImageStorage при наличии image;
- image_path сохраняется на post;
- image_url сохраняется, если storage возвращает url;
- thumbnail_url может быть null;
- ProcessUploadedImageJob skeleton существует;
- job можно dispatch-ить;
- CreatePostAction dispatch-ит job после создания post с image;
- ImageCleanup placeholder существует;
- CloudinaryImageStorage placeholder существует;
- storage config switch существует;
- composer test проходит;
- npm run build проходит;
- нет реального Cloudinary/thumbnail/resize logic вне scope.
```

---

# 11. Что нельзя делать в Phase 6

Без отдельной задачи нельзя:

```txt
- устанавливать Cloudinary SDK;
- делать реальный Cloudinary upload;
- делать S3/R2 storage;
- делать image resize;
- делать thumbnail generation;
- делать EXIF strip;
- делать image moderation;
- добавлять Redis/Horizon;
- делать Livewire UploadPostForm;
- делать Upload UI;
- делать Feed UI;
- делать Filament media manager;
- делать API upload endpoint;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia.
```

---

# 12. Recommended Execution Order

```txt
RG-135 Create ImageStorage interface
RG-136 Create LocalImageStorage implementation
RG-137 Bind ImageStorage to LocalImageStorage in service container
RG-138 Test LocalImageStorage stores uploaded file
RG-139 Implement local image storage
RG-140 Test CreatePostAction calls ImageStorage
RG-141 Store image_path on post
RG-142 Add thumbnail_url nullable field handling
RG-143 Create ProcessUploadedImageJob skeleton
RG-144 Test ProcessUploadedImageJob can be dispatched
RG-145 Dispatch ProcessUploadedImageJob after post creation
RG-146 Add image cleanup helper placeholder
RG-147 Add CloudinaryImageStorage placeholder class
RG-148 Add storage config switch
```

---

# 13. Release

После завершения Phase 6:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.7-phase6-image-storage-foundation
git push -u origin release/v0.0.7-phase6-image-storage-foundation
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.7-phase6-image-storage-foundation -m "RateGuru Phase 6 image storage foundation"
git push origin v0.0.7-phase6-image-storage-foundation
```
