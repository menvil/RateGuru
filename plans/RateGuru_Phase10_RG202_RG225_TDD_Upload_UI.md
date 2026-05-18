# RateGuru — Phase 10 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 10 — Upload UI**  
Диапазон задач: **RG-202 → RG-225**  
Основа нумерации: исходный atomic backlog, где Phase 10 начинается с задачи 202 и заканчивается задачей 225.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 10 соответствует исходному блоку:

```txt
Phase 10 — Upload UI
```

Правильный диапазон Phase 10:

```txt
RG-202 — Create UploadPostForm Livewire component
RG-203 — Test UploadPostForm renders for authenticated user
RG-204 — Test UploadPostForm blocks guest
RG-205 — Add upload modal shell
RG-206 — Add Alpine open/close behavior for upload modal
RG-207 — Add title input to UploadPostForm
RG-208 — Add description textarea to UploadPostForm
RG-209 — Add image file input to UploadPostForm
RG-210 — Add Alpine image preview
RG-211 — Add source_url input to UploadPostForm
RG-212 — Add origin_truth selector to UploadPostForm
RG-213 — Add cuisine_truth selector to UploadPostForm
RG-214 — Add tag input placeholder to UploadPostForm
RG-215 — Test successful upload creates post
RG-216 — Connect UploadPostForm to CreatePostAction
RG-217 — Test validation error for missing image
RG-218 — Render validation errors in UploadPostForm
RG-219 — Test validation error for missing title
RG-220 — Add successful upload event
RG-221 — Close upload modal after success
RG-222 — Refresh feed after upload success
RG-223 — Add upload loading state
RG-224 — Add upload error state
RG-225 — Compare upload modal with design checklist
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 10

Phase 10 добавляет пользовательский интерфейс загрузки поста.

После Phase 10 пользователь должен уметь:

```txt
- открыть upload modal;
- заполнить title;
- заполнить optional description;
- выбрать image;
- увидеть image preview;
- заполнить optional source_url;
- выбрать origin_truth;
- выбрать cuisine_truth;
- увидеть placeholder для tags;
- отправить форму;
- получить validation errors;
- увидеть loading/error/success states;
- после успешной загрузки modal закрывается;
- лента обновляется.
```

Phase 10 использует backend, созданный в предыдущих фазах:

```txt
- StorePostRequest rules из Phase 5;
- CreatePostAction из Phase 5;
- ImageStorage из Phase 6;
- FeedPage/PostFeed из Phase 8;
- search/category/sort state из Phase 9.
```

---

# 3. Scope Phase 10

## Входит

```txt
- UploadPostForm Livewire component;
- modal shell для загрузки;
- Alpine open/close behavior;
- Livewire form fields;
- image file input;
- image preview;
- validation errors;
- successful submit;
- integration with CreatePostAction;
- success event;
- close modal after success;
- refresh feed after success;
- loading state;
- error state;
- design checklist pass.
```

## Не входит

```txt
- post detail drawer;
- voting;
- comments;
- reports;
- moderation;
- edit post;
- delete post;
- advanced tag creation;
- autocomplete tags;
- drag-and-drop image upload;
- multiple images;
- crop/resize UI;
- Cloudinary UI;
- queue progress UI;
- API endpoint.
```

Post drawer начнётся в Phase 11.  
Voting — Phase 13+.  
Comments — Phase 16+.  
Reports — Phase 20+.  
Advanced image processing — отдельные будущие фазы.

---

# 4. Architecture Rules

## 4.1. UploadPostForm uses CreatePostAction

Нельзя создавать post прямо в Livewire через:

```php
Post::create(...)
```

Правильно:

```php
app(CreatePostAction::class)->handle($user, $data);
```

UploadPostForm отвечает за:

```txt
- UI state;
- validation;
- file input;
- converting form fields to CreatePostData;
- emitting success/error events.
```

Business logic остаётся в `CreatePostAction`.

## 4.2. Validation should reuse StorePostRequest rules where practical

Нельзя продублировать правила так, чтобы они разъехались.

Допустимые варианты:

```txt
Option A: UploadPostForm defines rules() by reusing StorePostRequest::rules().
Option B: extract shared rules into PostValidationRules class.
Option C: initially mirror rules but add explicit TODO and tests.
```

Лучший вариант для чистоты:

```txt
app/Support/Validation/PostValidationRules.php
```

Но если это раздувает фазу, можно в Phase 10 использовать те же правила явно и покрыть тестами. Главное — не потерять validation behavior.

## 4.3. Modal shell should use existing UI component

Upload modal должен использовать:

```txt
x-ui.modal
x-ui.button
x-ui.input
x-ui.textarea
x-ui.field-error
x-ui.badge
```

Не писать новый хаотичный modal HTML, если `x-ui.modal` уже есть.

## 4.4. Alpine only controls local UI behavior

Alpine используется для:

```txt
- open/close modal;
- image preview;
- small local transitions.
```

Alpine не должен:

```txt
- создавать post;
- валидировать backend rules;
- менять database;
- обходить Livewire.
```

## 4.5. Guest cannot upload

Guest может видеть feed, но не должен создавать post.

Поведение на MVP:

```txt
- upload button может быть видимым, но при клике redirect/login prompt;
- или upload modal доступен только authenticated user.
```

Для Phase 10 лучше проще:

```txt
- UploadPostForm blocks guest;
- если guest пытается открыть/submit — redirect/login message или authorization error.
```

---

# 5. Design Constraints

Upload modal должен соответствовать исходной визуальной идее:

```txt
- dark modal surface;
- rounded card/panel;
- clear image upload zone;
- compact fields;
- purple primary action;
- mobile-safe modal;
- desktop-centered modal;
- validation errors читаемы на тёмном фоне.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
```

В конце Phase 10 обязательно создать/обновить:

```txt
docs/design/phase-10-upload-ui-review.md
```

---

# 6. GitFlow для Phase 10

## Base branch

Все задачи Phase 10 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-202-create-upload-post-form-livewire-component
feature/RG-216-connect-upload-post-form-to-create-post-action
feature/RG-225-compare-upload-modal-with-design-checklist
```

## Commit format

```txt
RG-202: Create UploadPostForm Livewire component
RG-216: Connect UploadPostForm to CreatePostAction
RG-225: Compare upload modal with design checklist
```

## Release branch

После выполнения `RG-202`–`RG-225`:

```txt
release/v0.1.1-phase10-upload-ui
```

## Tag

После merge release branch в `main`:

```txt
v0.1.1-phase10-upload-ui
```

---

# 7. TDD Rules for Phase 10

## Для Livewire component

Писать Livewire tests:

```txt
- renders for authenticated user;
- blocks guest;
- fields update;
- validation errors appear;
- successful submit creates post;
- events are dispatched.
```

## Для modal/open-close

Чистое open/close через Alpine полностью не тестируется unit-тестом.  
Проверяем:

```txt
- markup contains x-data;
- markup contains x-show;
- close button exists;
- modal shell exists;
- manual check.
```

## Для image preview

Проверяем:

```txt
- markup contains preview area;
- Alpine FileReader logic exists;
- selected file updates Livewire image property;
- manual browser check.
```

## Для CreatePostAction integration

Не мокать сам action в финальном happy-path тесте.  
Нужно проверить, что post реально создаётся.

Для отдельных тестов ошибок можно мокать/подменять action, если нужно проверить error state.

---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Tests
Type: Test / Feature / Component / Wiring / Validation / Layout
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
- Проверен design checklist, если это visual task
- Нет логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 9. Phase 10 Atomic Tasks

---

## RG-202 — Create UploadPostForm Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-202-create-upload-post-form-livewire-component`  
**Base branch:** develop
**Depends on:** RG-201

### Goal

Создать Livewire-компонент `UploadPostForm`.

### TDD step

Livewire test:

```php
it('can render upload post form component', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertStatus(200);
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```bash
php artisan make:livewire Feed/UploadPostForm
```

Файлы:

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
```

Минимальный class:

```php
final class UploadPostForm extends Component
{
    public string $title = '';
    public ?string $description = null;
    public ?string $sourceUrl = null;
    public ?string $originTruth = null;
    public ?string $cuisineTruth = null;
    public array $tagIds = [];
    public $image = null;

    public function render(): View
    {
        return view('livewire.feed.upload-post-form');
    }
}
```

Добавить `WithFileUploads` позже, когда появится image input.

Минимальный view:

```blade
<div>
    Upload post
</div>
```

### Acceptance criteria

- `UploadPostForm` существует.
- Component рендерится для authenticated user.
- Есть базовые public properties.
- Пока нет submit logic.
- Livewire test проходит.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-202: Create UploadPostForm Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-203 — Test UploadPostForm Renders For Authenticated User

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-203-test-upload-post-form-renders-for-authenticated-user`  
**Base branch:** develop
**Depends on:** RG-202

### Goal

Закрепить regression test: authenticated user видит upload form.

### TDD step

Livewire test:

```php
it('renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Upload')
        ->assertStatus(200);
});
```

Если форма должна показывать другой текст, использовать стабильный marker:

```txt
Create post
```

### Implementation

Обновить view, если нужно:

```blade
<div data-testid="upload-post-form">
    <h2>Create post</h2>
</div>
```

### Acceptance criteria

- Тест существует.
- Authenticated user видит form marker.
- Component возвращает status 200.
- Тест проходит.

### Definition of Done

- Regression test добавлен.
- View содержит стабильный marker.
- Коммит: `RG-203: Test UploadPostForm renders for authenticated user`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-204 — Test UploadPostForm Blocks Guest

**Area:** Livewire / Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-204-test-upload-post-form-blocks-guest`  
**Base branch:** develop
**Depends on:** RG-203

### Goal

Гость не должен иметь доступ к форме создания поста.

### TDD step

Livewire test:

```php
it('blocks guest users', function () {
    Livewire::test(UploadPostForm::class)
        ->assertForbidden();
});
```

Если Livewire не даёт простой `assertForbidden()` для компонента, тестировать submit:

```php
Livewire::test(UploadPostForm::class)
    ->call('submit')
    ->assertForbidden();
```

или ожидать redirect to login.

Решение должно быть стабильным: выбрать один behavior.

Рекомендация:

```txt
render guest → forbidden
```

### Implementation

В `UploadPostForm` добавить authorization guard:

```php
public function mount(): void
{
    abort_unless(auth()->check(), 403);
}
```

И в submit тоже проверить user.

Не полагаться только на UI hide.

### Acceptance criteria

- Guest не может рендерить UploadPostForm или submit.
- Authenticated user может.
- Тест проходит.
- Нет обхода через прямой Livewire call.

### Definition of Done

- Тест написан.
- Guard добавлен.
- Тест проходит.
- Коммит: `RG-204: Test UploadPostForm blocks guest`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-205 — Add Upload Modal Shell

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-205-add-upload-modal-shell`  
**Base branch:** develop
**Depends on:** RG-204

### Goal

Добавить upload modal shell на FeedPage и разместить внутри `UploadPostForm`.

### TDD step

HTTP/Livewire test:

```php
it('renders upload modal shell for authenticated user on feed page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Create post')
        ->assertSee('data-testid="upload-modal"', false);
});
```

Тест должен упасть до внедрения modal.

### Implementation

В `FeedPage` view добавить upload trigger area и modal shell.

Пример:

```blade
@if(auth()->check())
    <div x-data="{ uploadOpen: false }">
        <x-ui.button @click="uploadOpen = true">
            Upload
        </x-ui.button>

        <div data-testid="upload-modal">
            <x-ui.modal title="Create post">
                <livewire:feed.upload-post-form />
            </x-ui.modal>
        </div>
    </div>
@endif
```

Но полноценный Alpine behavior будет RG-206.  
В RG-205 достаточно добавить shell и форму.

Использовать `x-ui.modal`.

### Acceptance criteria

- Authenticated user видит upload entry point.
- Upload modal shell есть в DOM.
- Внутри shell рендерится UploadPostForm.
- Guest не видит форму или не может открыть её.
- Используется `x-ui.modal`.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Modal shell добавлен.
- Тест проходит.
- Коммит: `RG-205: Add upload modal shell`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-206 — Add Alpine Open/Close Behavior For Upload Modal

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-206-add-alpine-open-close-behavior-for-upload-modal`  
**Base branch:** develop
**Depends on:** RG-205

### Goal

Добавить Alpine open/close behavior для upload modal.

### TDD step

Markup test:

```php
it('has alpine upload modal open close behavior', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertSee('x-data', false)
        ->assertSee('uploadOpen', false)
        ->assertSee('x-show', false)
        ->assertSee('@click', false);
});
```

Тест проверяет наличие markup. Поведение открыть/закрыть проверяется вручную.

### Implementation

В `feed-page.blade.php`:

```blade
<div x-data="{ uploadOpen: false }">
    <x-ui.button type="button" @click="uploadOpen = true">
        Upload
    </x-ui.button>

    <div x-show="uploadOpen" x-cloak>
        <x-ui.modal title="Create post">
            <livewire:feed.upload-post-form />
        </x-ui.modal>
    </div>
</div>
```

Close behavior:

```txt
- close button внутри modal;
- @keydown.escape.window="uploadOpen = false";
- after success event close handled in RG-221.
```

Если `x-ui.modal` уже имеет close slot/behavior, использовать его.

### Acceptance criteria

- Upload button opens modal через Alpine.
- Modal can be closed через close button.
- Escape closes modal, если добавлено.
- `x-cloak` используется, чтобы modal не мигал при загрузке.
- Markup test проходит.
- Manual open/close check выполнен.

### Definition of Done

- Alpine behavior добавлен.
- Тест проходит.
- Коммит: `RG-206: Add Alpine open/close behavior for upload modal`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-207 — Add Title Input To UploadPostForm

**Area:** UI / Livewire / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-207-add-title-input-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-206

### Goal

Добавить title input в UploadPostForm.

### TDD step

Livewire test:

```php
it('has title input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Title')
        ->assertSee('name="title"', false);
});
```

И property test:

```php
Livewire::actingAs($user)
    ->test(UploadPostForm::class)
    ->set('title', 'Homemade pasta')
    ->assertSet('title', 'Homemade pasta');
```

### Implementation

В view:

```blade
<x-ui.label for="title">Title</x-ui.label>
<x-ui.input
    id="title"
    name="title"
    wire:model.defer="title"
    placeholder="Dish title"
/>
<x-ui.field-error :message="$errors->first('title')" />
```

Если `x-ui.label` отсутствует, использовать existing label component или добавить plain label, но лучше Phase 1 уже должен был создать label.

### Acceptance criteria

- Title input виден.
- Title связан с Livewire property.
- Используется `x-ui.input`.
- Есть place for validation error.
- Тест проходит.

### Definition of Done

- Тест написан.
- Input добавлен.
- Тест проходит.
- Коммит: `RG-207: Add title input to UploadPostForm`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-208 — Add Description Textarea To UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-208-add-description-textarea-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-207

### Goal

Добавить optional description textarea.

### TDD step

Livewire test:

```php
it('has description textarea', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Description')
        ->assertSee('name="description"', false);
});
```

Property test:

```php
->set('description', 'Fresh pasta with basil')
->assertSet('description', 'Fresh pasta with basil');
```

### Implementation

В view:

```blade
<x-ui.label for="description">Description</x-ui.label>
<x-ui.textarea
    id="description"
    name="description"
    wire:model.defer="description"
    rows="4"
    placeholder="Optional details"
/>
<x-ui.field-error :message="$errors->first('description')" />
```

### Acceptance criteria

- Description textarea виден.
- Description optional.
- Textarea связан с Livewire property.
- Используется `x-ui.textarea`.
- Тест проходит.

### Definition of Done

- Тест написан.
- Textarea добавлен.
- Тест проходит.
- Коммит: `RG-208: Add description textarea to UploadPostForm`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-209 — Add Image File Input To UploadPostForm

**Area:** UI / Livewire / Upload  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-209-add-image-file-input-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-208

### Goal

Добавить file input для изображения.

### TDD step

Livewire test:

```php
it('has image file input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Image')
        ->assertSee('type="file"', false)
        ->assertSee('name="image"', false);
});
```

Property/upload test:

```php
it('accepts image upload property', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file)
        ->assertSet('image', $file);
});
```

### Implementation

В component class добавить:

```php
use Livewire\WithFileUploads;

use WithFileUploads;

public $image = null;
```

В view:

```blade
<x-ui.label for="image">Image</x-ui.label>
<input
    id="image"
    name="image"
    type="file"
    accept="image/*"
    wire:model="image"
/>
<x-ui.field-error :message="$errors->first('image')" />
```

Можно использовать кастомную upload zone, но не делать drag-and-drop.

### Acceptance criteria

- Image file input есть.
- Accept = image/*.
- Livewire property принимает UploadedFile.
- Используется `WithFileUploads`.
- Validation error slot есть.
- Тест проходит.

### Definition of Done

- Тест написан.
- File input добавлен.
- Тест проходит.
- Коммит: `RG-209: Add image file input to UploadPostForm`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-210 — Add Alpine Image Preview

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-210-add-alpine-image-preview`  
**Base branch:** develop
**Depends on:** RG-209

### Goal

Добавить client-side preview выбранного изображения.

### TDD step

Markup test:

```php
it('has alpine image preview markup', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('x-data', false)
        ->assertSee('previewUrl', false)
        ->assertSee('FileReader', false);
});
```

Это не полноценный browser test. Поведение preview проверяется вручную.

### Implementation

В upload form view добавить Alpine wrapper:

```blade
<div
    x-data="{ previewUrl: null }"
>
    <input
        type="file"
        accept="image/*"
        wire:model="image"
        @change="
            const file = $event.target.files[0];
            if (!file) { previewUrl = null; return; }
            const reader = new FileReader();
            reader.onload = e => previewUrl = e.target.result;
            reader.readAsDataURL(file);
        "
    />

    <template x-if="previewUrl">
        <img :src="previewUrl" alt="Selected image preview" />
    </template>

    <div x-show="!previewUrl">
        <x-ui.image-placeholder label="Image preview" ratio="video" />
    </div>
</div>
```

### Acceptance criteria

- Selected image preview area есть.
- Preview использует Alpine local state.
- Placeholder показывается до выбора image.
- Не отправляет файл напрямую через JS.
- Livewire image property сохраняется.
- Markup test проходит.
- Manual preview check выполнен.

### Definition of Done

- Alpine preview добавлен.
- Тест проходит.
- Коммит: `RG-210: Add Alpine image preview`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-211 — Add Source Url Input To UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-211-add-source-url-input-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-210

### Goal

Добавить optional `source_url` input.

### TDD step

Livewire test:

```php
it('has source url input', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Source URL')
        ->assertSee('name="source_url"', false);
});
```

Property test:

```php
->set('sourceUrl', 'https://example.com/original')
->assertSet('sourceUrl', 'https://example.com/original');
```

### Implementation

В view:

```blade
<x-ui.label for="source_url">Source URL</x-ui.label>
<x-ui.input
    id="source_url"
    name="source_url"
    type="url"
    wire:model.defer="sourceUrl"
    placeholder="https://example.com/original"
/>
<x-ui.field-error :message="$errors->first('sourceUrl')" />
<x-ui.field-error :message="$errors->first('source_url')" />
```

В Livewire property называется `sourceUrl`, но validation key может быть `sourceUrl` или `source_url`. Лучше внутри Livewire использовать `source_url` property?  

Рекомендация: для Livewire forms использовать camelCase property `sourceUrl`, а при создании DTO мапить в `sourceUrl`.

Validation errors должны совпадать с chosen property names.

### Acceptance criteria

- Source URL input есть.
- Input type = url.
- Property обновляется.
- Optional.
- Error area есть.
- Тест проходит.

### Definition of Done

- Тест написан.
- Input добавлен.
- Тест проходит.
- Коммит: `RG-211: Add source_url input to UploadPostForm`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-212 — Add Origin Truth Selector To UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-212-add-origin-truth-selector-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-211

### Goal

Добавить selector для `origin_truth`.

### TDD step

Livewire test:

```php
it('has origin truth selector', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Homemade')
        ->assertSee('Restaurant')
        ->assertSee('Keep unknown');
});
```

Property test:

```php
->set('originTruth', OriginType::Homemade->value)
->assertSet('originTruth', OriginType::Homemade->value);
```

### Implementation

В class:

```php
public string $originTruth = OriginType::Unknown->value;
```

В view:

```blade
<x-ui.label>Origin</x-ui.label>

<select wire:model.defer="originTruth">
    <option value="{{ OriginType::Unknown->value }}">Keep unknown</option>
    <option value="{{ OriginType::Homemade->value }}">Homemade</option>
    <option value="{{ OriginType::Restaurant->value }}">Restaurant</option>
</select>
```

Можно оформить как segmented buttons, но select проще.  
Если делаем segmented UI, всё равно сохранять stable values.

### Acceptance criteria

- Selector есть.
- Options: unknown, homemade, restaurant.
- Default = unknown.
- Property обновляется.
- Error area есть.
- Тест проходит.

### Definition of Done

- Тест написан.
- Selector добавлен.
- Тест проходит.
- Коммит: `RG-212: Add origin_truth selector to UploadPostForm`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-213 — Add Cuisine Truth Selector To UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-213-add-cuisine-truth-selector-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-212

### Goal

Добавить selector для `cuisine_truth`.

### TDD step

Livewire test:

```php
it('has cuisine truth selector', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other')
        ->assertSee('Keep unknown');
});
```

Property test:

```php
->set('cuisineTruth', CuisineType::Italian->value)
->assertSet('cuisineTruth', CuisineType::Italian->value);
```

### Implementation

В class:

```php
public string $cuisineTruth = CuisineType::Unknown->value;
```

В view:

```blade
<select wire:model.defer="cuisineTruth">
    <option value="{{ CuisineType::Unknown->value }}">Keep unknown</option>
    <option value="{{ CuisineType::Italian->value }}">Italian</option>
    ...
</select>
```

### Acceptance criteria

- Selector есть.
- Options соответствуют `CuisineType`.
- Default = unknown.
- Property обновляется.
- Error area есть.
- Тест проходит.

### Definition of Done

- Тест написан.
- Selector добавлен.
- Тест проходит.
- Коммит: `RG-213: Add cuisine_truth selector to UploadPostForm`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-214 — Add Tag Input Placeholder To UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-214-add-tag-input-placeholder-to-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-213

### Goal

Добавить placeholder для будущего tag input, не реализуя полноценный tag picker/autocomplete.

### TDD step

Livewire test:

```php
it('renders tag input placeholder', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Tags')
        ->assertSee('Tag selection coming soon');
});
```

### Implementation

В view добавить block:

```blade
<div>
    <x-ui.label>Tags</x-ui.label>
    <div class="...">
        Tag selection coming soon
    </div>
</div>
```

Можно показать существующие selected tags, если `tagIds` уже есть, но не добавлять picker.

Не создавать tags из UI.  
Не делать autocomplete.

### Acceptance criteria

- Tags section виден.
- Ясно, что это placeholder.
- Нет неработающего input, который обещает функциональность.
- Не создаёт/не attach tags из UI пока.
- Тест проходит.

### Definition of Done

- Тест написан.
- Placeholder добавлен.
- Коммит: `RG-214: Add tag input placeholder to UploadPostForm`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-215 — Test Successful Upload Creates Post

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-215-test-successful-upload-creates-post`  
**Base branch:** develop
**Depends on:** RG-214

### Goal

Написать падающий тест: успешная отправка формы создаёт post.

### TDD step

Livewire test:

```php
it('creates post on successful upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('description', 'Fresh dinner')
        ->set('image', $file)
        ->call('submit');

    $this->assertDatabaseHas('posts', [
        'user_id' => $user->id,
        'title' => 'Homemade Pasta',
        'description' => 'Fresh dinner',
    ]);
});
```

Тест должен упасть до RG-216.

### Implementation

Только добавить тест.  
Не реализовывать submit logic в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест использует authenticated user.
- Тест использует fake image upload.
- Тест проверяет database post.
- Тест падает до RG-216.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-215: Test successful upload creates post`

### Files likely touched

```txt
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-216 — Connect UploadPostForm To CreatePostAction

**Area:** Livewire / Backend Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-216-connect-upload-post-form-to-create-post-action`  
**Base branch:** develop
**Depends on:** RG-215

### Goal

Реализовать `submit()` в UploadPostForm и подключить `CreatePostAction`.

### TDD step

Использовать падающий тест из RG-215.

### Implementation

В `UploadPostForm` добавить method:

```php
public function submit(CreatePostAction $createPostAction): void
{
    $this->validate();

    $post = $createPostAction->handle(auth()->user(), new CreatePostData(
        title: $this->title,
        description: $this->description,
        sourceUrl: $this->sourceUrl,
        originTruth: OriginType::from($this->originTruth),
        cuisineTruth: CuisineType::from($this->cuisineTruth),
        tagIds: $this->tagIds,
        image: $this->image,
    ));

    // success event in RG-220
}
```

Rules:

```php
protected function rules(): array
{
    return [
        'title' => ['required', 'string', 'min:3', 'max:120'],
        'description' => ['nullable', 'string', 'max:2000'],
        'image' => ['required', 'image', 'max:5120'],
        'sourceUrl' => ['nullable', 'url', 'max:2048'],
        'originTruth' => ['nullable', Rule::enum(OriginType::class)],
        'cuisineTruth' => ['nullable', Rule::enum(CuisineType::class)],
        'tagIds' => ['array', 'max:10'],
        'tagIds.*' => ['integer', 'exists:tags,id'],
    ];
}
```

Если хочется не дублировать StorePostRequest rules, можно extract shared rules, но не делать слишком большой refactor без необходимости.

### Acceptance criteria

- `submit()` существует.
- Submit вызывает CreatePostAction.
- Successful submit creates post.
- Image передаётся в CreatePostData.
- Validation запускается до action.
- Тест RG-215 проходит.
- Старые tests проходят.

### Definition of Done

- Submit logic реализована.
- Тест проходит.
- Коммит: `RG-216: Connect UploadPostForm to CreatePostAction`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-217 — Test Validation Error For Missing Image

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-217-test-validation-error-for-missing-image`  
**Base branch:** develop
**Depends on:** RG-216

### Goal

Написать тест: submit без image даёт validation error.

### TDD step

Livewire test:

```php
it('shows validation error when image is missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);
});
```

### Implementation

Если rules уже есть из RG-216, тест может быть зелёным. Это нормально как regression test.

### Acceptance criteria

- Тест существует.
- Missing image даёт validation error.
- Post не создаётся.
- Тест проходит.

### Definition of Done

- Тест добавлен.
- Тест проходит.
- Коммит: `RG-217: Test validation error for missing image`

### Files likely touched

```txt
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-218 — Render Validation Errors In UploadPostForm

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-218-render-validation-errors-in-upload-post-form`  
**Base branch:** develop
**Depends on:** RG-217

### Goal

Отображать validation errors в форме.

### TDD step

Livewire test:

```php
it('renders validation errors', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('submit')
        ->assertSee('field-error-title', false)
        ->assertSee('field-error-image', false);
});
```

Лучше не тестировать точный текст Laravel validation message, он может зависеть от локали.  
Использовать `data-testid`.

### Implementation

В view рядом с каждым полем:

```blade
<div data-testid="field-error-title">
    <x-ui.field-error :message="$errors->first('title')" />
</div>

<div data-testid="field-error-image">
    <x-ui.field-error :message="$errors->first('image')" />
</div>
```

Добавить errors для:

```txt
title
description
image
sourceUrl
originTruth
cuisineTruth
tagIds
```

### Acceptance criteria

- Error placeholders есть для ключевых fields.
- Ошибки видны после validation failure.
- Используется `x-ui.field-error`.
- Не ломает dark UI.
- Тест проходит.

### Definition of Done

- Error rendering добавлен.
- Тест проходит.
- Коммит: `RG-218: Render validation errors in UploadPostForm`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-219 — Test Validation Error For Missing Title

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-219-test-validation-error-for-missing-title`  
**Base branch:** develop
**Depends on:** RG-218

### Goal

Написать тест: submit без title даёт validation error.

### TDD step

Livewire test:

```php
it('shows validation error when title is missing', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['title' => 'required']);
});
```

Также проверить, что post не создан:

```php
expect(Post::query()->count())->toBe(0);
```

### Implementation

Если rules уже есть — тест должен пройти.  
Если нет — исправить rules.

### Acceptance criteria

- Тест существует.
- Missing title даёт validation error.
- Post не создаётся.
- Error rendering не ломается.
- Тест проходит.

### Definition of Done

- Тест добавлен.
- Тест проходит.
- Коммит: `RG-219: Test validation error for missing title`

### Files likely touched

```txt
tests/Feature/Livewire/UploadPostFormTest.php
app/Livewire/Feed/UploadPostForm.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-220 — Add Successful Upload Event

**Area:** Livewire / Events  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-220-add-successful-upload-event`  
**Base branch:** develop
**Depends on:** RG-216, RG-219

### Goal

После успешного upload dispatch event, который смогут использовать modal и feed.

### TDD step

Livewire test:

```php
it('dispatches successful upload event', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('image', $file)
        ->call('submit')
        ->assertDispatched('post-uploaded');
});
```

### Implementation

В `submit()` после успешного action:

```php
$this->dispatch('post-uploaded', postId: $post->id);
```

Также можно reset form:

```php
$this->reset(['title', 'description', 'sourceUrl', 'image', 'tagIds']);
$this->originTruth = OriginType::Unknown->value;
$this->cuisineTruth = CuisineType::Unknown->value;
```

Но если reset усложняет тесты с file upload, можно оставить reset для отдельной задачи. В рамках Phase 10 лучше reset сделать после success.

### Acceptance criteria

- После success dispatches `post-uploaded`.
- Event содержит `postId`.
- Post создаётся.
- Validation failure не dispatches event.
- Тест проходит.

### Definition of Done

- Event добавлен.
- Тест проходит.
- Коммит: `RG-220: Add successful upload event`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-221 — Close Upload Modal After Success

**Area:** UI / Alpine / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-221-close-upload-modal-after-success`  
**Base branch:** develop
**Depends on:** RG-220

### Goal

Закрывать upload modal после успешной загрузки.

### TDD step

Markup test:

```php
it('listens for post uploaded event to close upload modal', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertSee('post-uploaded.window', false)
        ->assertSee('uploadOpen = false', false);
});
```

Это проверяет Alpine listener. Полное поведение проверяется вручную.

### Implementation

В `FeedPage` upload wrapper:

```blade
<div
    x-data="{ uploadOpen: false }"
    @post-uploaded.window="uploadOpen = false"
>
```

Если Livewire event dispatch не всплывает как browser event в текущей версии, использовать совместимый способ:

```php
$this->dispatch('post-uploaded');
```

и Alpine listener должен ловить его.

### Acceptance criteria

- Modal слушает `post-uploaded`.
- После события Alpine закрывает modal.
- Тест markup проходит.
- Manual success upload closes modal.
- Validation failure не закрывает modal.

### Definition of Done

- Listener добавлен.
- Тест проходит.
- Manual check выполнен.
- Коммит: `RG-221: Close upload modal after success`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-222 — Refresh Feed After Upload Success

**Area:** Livewire / Events  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-222-refresh-feed-after-upload-success`  
**Base branch:** develop
**Depends on:** RG-220

### Goal

Обновлять PostFeed после успешной загрузки.

### TDD step

Livewire test:

```php
it('refreshes feed after upload success event', function () {
    $user = User::factory()->trusted()->create();

    Livewire::actingAs($user)
        ->test(FeedPage::class)
        ->dispatch('post-uploaded')
        ->assertStatus(200);
});
```

Более полезный тест:

```php
it('shows newly uploaded published post after upload event', function () {
    $user = User::factory()->trusted()->create();

    $post = Post::factory()->published()->create([
        'user_id' => $user->id,
        'title' => 'New Uploaded Dish',
    ]);

    Livewire::actingAs($user)
        ->test(PostFeed::class)
        ->dispatch('post-uploaded')
        ->assertSee('New Uploaded Dish');
});
```

Если PostFeed всегда query в render, достаточно слушать event `$refresh`.

### Implementation

В `PostFeed` добавить listener:

```php
#[On('post-uploaded')]
public function refreshAfterUpload(): void
{
    // no-op; Livewire re-renders component
}
```

или:

```php
protected $listeners = [
    'post-uploaded' => '$refresh',
];
```

Синтаксис зависит от Livewire версии.

Важно: если normal user создаёт pending post, публичная feed не покажет его. Тест для refresh должен использовать trusted user/published post или напрямую создать published post.

### Acceptance criteria

- PostFeed слушает `post-uploaded`.
- После события component re-renders.
- Published uploaded post появляется в feed.
- Pending uploaded post не обязан появляться.
- Тест проходит.

### Definition of Done

- Listener добавлен.
- Тест проходит.
- Коммит: `RG-222: Refresh feed after upload success`

### Files likely touched

```txt
app/Livewire/Feed/PostFeed.php
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-223 — Add Upload Loading State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-223-add-upload-loading-state`  
**Base branch:** develop
**Depends on:** RG-216

### Goal

Добавить loading state на submit/upload.

### TDD step

Markup test:

```php
it('has upload loading state markup', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('wire:loading', false)
        ->assertSee('Uploading', false);
});
```

### Implementation

В submit button area:

```blade
<x-ui.button type="submit" wire:loading.attr="disabled">
    <span wire:loading.remove wire:target="submit">Create post</span>
    <span wire:loading wire:target="submit">Uploading...</span>
</x-ui.button>
```

Для file upload:

```blade
<div wire:loading wire:target="image">
    Preparing image...
</div>
```

### Acceptance criteria

- Submit button disabled while submit loading.
- Button text changes to Uploading.
- Image upload has preparing indicator.
- Loading markup не ломает layout.
- Тест проходит.

### Definition of Done

- Loading state добавлен.
- Тест проходит.
- Коммит: `RG-223: Add upload loading state`

### Files likely touched

```txt
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-224 — Add Upload Error State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-224-add-upload-error-state`  
**Base branch:** develop
**Depends on:** RG-223

### Goal

Добавить общий error state для upload failures, не только field validation.

### TDD step

Livewire test with failing action.

Можно подменить `CreatePostAction` fake, который бросает exception:

```php
it('shows upload error state when action fails', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    app()->bind(CreatePostAction::class, function () {
        return new class {
            public function handle(User $user, CreatePostData $data): Post
            {
                throw new RuntimeException('Upload failed');
            }
        };
    });

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Homemade Pasta')
        ->set('image', $file)
        ->call('submit')
        ->assertSee('Something went wrong');
});
```

### Implementation

В component:

```php
public ?string $submitError = null;

public function submit(CreatePostAction $createPostAction): void
{
    $this->submitError = null;

    try {
        // validate + action
    } catch (Throwable $e) {
        report($e);

        $this->submitError = 'Something went wrong while creating your post.';
        return;
    }
}
```

Не ловить `ValidationException` как generic error. Validation должна работать стандартно.

В view:

```blade
@if($submitError)
    <x-ui.error-message
        title="Something went wrong"
        :message="$submitError"
    />
@endif
```

### Acceptance criteria

- Generic action/storage failure показывает error state.
- Validation errors не превращаются в generic error.
- Error state использует `x-ui.error-message`.
- Error сбрасывается перед новой попыткой.
- Тест проходит.

### Definition of Done

- Error state добавлен.
- Тест проходит.
- Коммит: `RG-224: Add upload error state`

### Files likely touched

```txt
app/Livewire/Feed/UploadPostForm.php
resources/views/livewire/feed/upload-post-form.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-225 — Compare Upload Modal With Design Checklist

**Area:** UI / Docs / QA  
**Type:** Docs / QA  
**Priority:** P0  
**Branch:** `feature/RG-225-compare-upload-modal-with-design-checklist`  
**Base branch:** develop
**Depends on:** RG-224

### Goal

Финально сверить upload modal с design contract, исходным reference и UI checklist.

### TDD step

No direct test — visual QA/documentation task.

Запустить:

```bash
composer test
npm run build
```

Проверить вручную:

```txt
/
 /dev/ui-kit
upload modal open/close
image preview
validation errors
success upload
loading state
error state
mobile width
desktop width
```

### Implementation

Создать:

```txt
docs/design/phase-10-upload-ui-review.md
```

Содержимое:

```md
# Phase 10 Upload UI Review

## Reference checked
- [ ] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/
- [ ] docs/design/design-contract.md
- [ ] docs/design/ui-review-checklist.md
- [ ] /dev/ui-kit

## Upload modal
- [ ] Dark modal surface preserved
- [ ] Rounded panel/card style preserved
- [ ] Purple primary action used
- [ ] Close behavior works
- [ ] Escape/outside close behavior checked, if implemented
- [ ] Mobile layout checked
- [ ] Desktop layout checked

## Form fields
- [ ] Title input exists
- [ ] Description textarea exists
- [ ] Image input exists
- [ ] Image preview works
- [ ] Source URL input exists
- [ ] Origin selector exists
- [ ] Cuisine selector exists
- [ ] Tags placeholder exists

## States
- [ ] Missing title validation visible
- [ ] Missing image validation visible
- [ ] Loading state visible
- [ ] Generic error state visible
- [ ] Success event closes modal
- [ ] Feed refreshes after success

## Known deviations
- ...
```

Если upload modal не совпадает с original PlateRate reference, записать:

```txt
- что отличается;
- почему допустимо;
- нужна ли будущая задача.
```

### Acceptance criteria

- `docs/design/phase-10-upload-ui-review.md` существует.
- Checklist заполнен.
- Known deviations описаны.
- `/` проверен вручную.
- `/dev/ui-kit` проверен вручную, если upload modal там есть.
- `composer test` проходит.
- `npm run build` проходит.

### Definition of Done

- UI review документ создан.
- Все tests/build проходят.
- Коммит: `RG-225: Compare upload modal with design checklist`

### Files likely touched

```txt
docs/design/phase-10-upload-ui-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 10. Phase 10 Completion Criteria

Phase 10 завершена, когда:

```txt
- RG-202–RG-225 выполнены;
- UploadPostForm существует;
- UploadPostForm доступен authenticated user;
- Guest blocked;
- Upload modal shell есть на FeedPage;
- Alpine open/close работает;
- title input есть;
- description textarea есть;
- image input есть;
- image preview есть;
- source_url input есть;
- origin_truth selector есть;
- cuisine_truth selector есть;
- tag placeholder есть;
- successful submit creates post через CreatePostAction;
- missing image validation работает;
- missing title validation работает;
- validation errors render correctly;
- post-uploaded event dispatches;
- modal closes after success;
- feed refreshes after success;
- loading state есть;
- generic error state есть;
- design checklist пройден;
- composer test проходит;
- npm run build проходит.
```

---

# 11. Что нельзя делать в Phase 10

Без отдельной задачи нельзя:

```txt
- делать post drawer;
- делать voting;
- делать comments;
- делать report modal;
- делать moderation;
- делать edit post;
- делать delete post;
- делать advanced tag picker;
- создавать tags из UI;
- делать autocomplete;
- делать drag-and-drop upload;
- делать multiple image upload;
- делать crop/resize UI;
- делать Cloudinary UI;
- делать queue progress UI;
- делать API endpoint;
- добавлять Redis;
- добавлять Vue/React/Inertia.
```

---

# 12. Recommended Execution Order

```txt
RG-202 Create UploadPostForm Livewire component
RG-203 Test UploadPostForm renders for authenticated user
RG-204 Test UploadPostForm blocks guest
RG-205 Add upload modal shell
RG-206 Add Alpine open/close behavior for upload modal
RG-207 Add title input to UploadPostForm
RG-208 Add description textarea to UploadPostForm
RG-209 Add image file input to UploadPostForm
RG-210 Add Alpine image preview
RG-211 Add source_url input to UploadPostForm
RG-212 Add origin_truth selector to UploadPostForm
RG-213 Add cuisine_truth selector to UploadPostForm
RG-214 Add tag input placeholder to UploadPostForm
RG-215 Test successful upload creates post
RG-216 Connect UploadPostForm to CreatePostAction
RG-217 Test validation error for missing image
RG-218 Render validation errors in UploadPostForm
RG-219 Test validation error for missing title
RG-220 Add successful upload event
RG-221 Close upload modal after success
RG-222 Refresh feed after upload success
RG-223 Add upload loading state
RG-224 Add upload error state
RG-225 Compare upload modal with design checklist
```

---

# 13. Release

После завершения Phase 10:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.1-phase10-upload-ui
git push -u origin release/v0.1.1-phase10-upload-ui
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.1-phase10-upload-ui -m "RateGuru Phase 10 upload UI"
git push origin v0.1.1-phase10-upload-ui
```
