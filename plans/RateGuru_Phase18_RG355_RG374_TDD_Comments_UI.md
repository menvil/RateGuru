# RateGuru — Phase 18 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 18 — Comments UI**  
Диапазон задач: **RG-355 → RG-374**  
Основа нумерации: исходный atomic backlog, где Phase 18 начинается с задачи 355 и заканчивается задачей 374.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 18 соответствует исходному блоку:

```txt
Phase 18 — Comments UI
```

Правильный диапазон Phase 18:

```txt
RG-355 — Create CommentsSection Livewire component
RG-356 — Test CommentsSection renders comments
RG-357 — Render comments list
RG-358 — Create CommentItem Blade component
RG-359 — Render CommentItem in UI kit
RG-360 — Render author in CommentItem
RG-361 — Render body in CommentItem
RG-362 — Render timestamp in CommentItem
RG-363 — Create CommentForm Livewire component
RG-364 — Test CommentForm creates comment
RG-365 — Render comment textarea
RG-366 — Render submit button
RG-367 — Render comment validation error
RG-368 — Clear comment body after submit
RG-369 — Refresh comments after submit
RG-370 — Add delete own comment button
RG-371 — Add moderator hide comment button
RG-372 — Add comments empty state
RG-373 — Add comments loading state
RG-374 — Compare comments UI with design checklist
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 18

Phase 18 добавляет пользовательский интерфейс комментариев поверх backend-а из Phase 17.

После Phase 18 пользователь должен уметь:

```txt
- видеть список видимых комментариев;
- видеть автора комментария;
- видеть текст комментария;
- видеть timestamp;
- authenticated user может написать комментарий;
- форма вызывает AddCommentAction;
- validation errors отображаются в UI;
- body очищается после успешного submit;
- comments list обновляется после submit;
- автор может удалить свой комментарий;
- moderator/admin может скрыть комментарий;
- empty state отображается, если комментариев нет;
- loading state отображается при действиях.
```

Phase 18 превращает comments backend в рабочий UI, но не добавляет reports, nested replies, editing или notifications.
---

# 3. Scope Phase 18

## Входит

```txt
- CommentsSection Livewire component;
- visible comments list;
- CommentItem Blade component;
- UI kit example для CommentItem;
- author/body/timestamp rendering;
- CommentForm Livewire component;
- textarea;
- submit button;
- validation error rendering;
- successful submit through AddCommentAction;
- body reset after submit;
- refresh comments after submit;
- delete own comment button;
- moderator hide comment button;
- empty state;
- loading state;
- design checklist review.
```

## Не входит

```txt
- reports for comments;
- report button on CommentItem;
- nested comments/replies;
- edit comment;
- comment likes;
- markdown/rich text;
- WYSIWYG editor;
- spam detection;
- rate limiting UI;
- notifications after comment;
- Filament comment admin;
- API endpoint.
```

Reports backend будет Phase 19.  
Reports UI будет Phase 20.  
Moderation backend будет Phase 21+.  
Filament comments resource будет Phase 26.
---

# 4. Architecture Rules

## 4.1. CommentsSection owns list rendering

`CommentsSection` отвечает за:

```txt
- загрузку visible comments для post;
- рендер списка;
- empty state;
- loading state;
- refresh after comment-created/comment-deleted/comment-hidden;
- подключение CommentForm.
```

Он не должен сам создавать/удалять/скрывать comment напрямую.

## 4.2. CommentForm owns input state

`CommentForm` отвечает за:

```txt
- body property;
- textarea;
- submit;
- calling AddCommentAction;
- validation error rendering;
- clearing body after success;
- dispatch comment-created event.
```

Он не должен сам грузить весь список комментариев.

## 4.3. CommentItem is dumb view component

`CommentItem` отвечает за отображение одного comment:

```txt
- author;
- body;
- timestamp;
- action buttons visibility.
```

Но actual delete/hide calls должны идти через Livewire parent/component action, не через Blade-only бизнес-логику.

## 4.4. Use backend actions from Phase 17

Нельзя делать в UI:

```php
Comment::create(...)
$comment->delete()
$comment->update(['status' => 'hidden'])
```

Правильно:

```php
AddCommentAction::handle(...)
DeleteCommentAction::handle(...)
HideCommentAction::handle(...)
```

## 4.5. Only visible comments render publicly

CommentsSection должен показывать только:

```txt
status = visible
deleted_at IS NULL, если SoftDeletes есть
```

Нельзя показывать:

```txt
hidden comments;
deleted comments;
comments on hidden/unpublished posts in public UI.
```

## 4.6. Integrate into existing detail surfaces

Phase 11/12 уже оставили comments placeholders:

```txt
PostDrawer comments slot
PostShow comments section placeholder
```

Phase 18 должна заменить placeholders на `CommentsSection`, где это уместно:

```blade
<livewire:comments.comments-section :post-id="$post->id" />
```

Это не отдельная задача в исходном списке, но без интеграции Comments UI не будет виден.  
Разумно включить интеграцию в RG-357/RG-369/RG-374, не добавляя новых ID.
---

# 5. Product Decisions

## 5.1. Комментарии сортируются oldest-first или newest-first?

Для MVP фиксируем:

```txt
oldest first
```

Почему:

```txt
- проще читать обсуждение;
- комментарии не являются лентой;
- не нужно pagination/infinite scroll на этом этапе.
```

Позже можно добавить sorting/pagination.

## 5.2. Максимальная длина UI должна совпадать с backend

Phase 17 зафиксировала max length:

```txt
1000 characters
```

CommentForm не должен обещать больше.  
Можно добавить helper text:

```txt
1000 characters max
```

Но реальная защита остаётся в `AddCommentAction`.

## 5.3. Guest UI

Guest видит comments list, но не может отправить comment.

MVP-поведение:

```txt
- если guest: показать login prompt вместо CommentForm;
- не рендерить активную textarea;
- backend всё равно защищён.
```

Не делать login modal в Phase 18.

## 5.4. Delete own comment UI

Owner видит кнопку Delete только на своих comments.

После удаления:

```txt
- comment исчезает из списка;
- comments_count уже обновляется backend action;
- CommentsSection refreshes.
```

## 5.5. Moderator hide UI

Moderator/admin видит кнопку Hide.

Normal user не видит Hide.

После hide:

```txt
- comment исчезает из публичного списка;
- comments_count обновляется backend action;
- CommentsSection refreshes.
```
---

# 6. Design Constraints

Comments UI должен продолжать dark-first RateGuru style:

```txt
- dark card/surface;
- compact author row;
- readable body;
- muted timestamp;
- subtle separators;
- purple primary submit;
- destructive delete/hide buttons should be visually secondary;
- mobile safe;
- drawer safe;
- post show safe.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-11-drawer-ui-review.md
docs/design/phase-12-post-show-page-review.md
```

Если один из review docs отсутствует, зафиксировать в Phase 18 review notes, но не блокировать implementation.
---

# 7. GitFlow для Phase 18

## Base branch

Все задачи Phase 18 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-355-create-comments-section-livewire-component
feature/RG-363-create-comment-form-livewire-component
feature/RG-374-compare-comments-ui-with-design-checklist
```

## Commit format

```txt
RG-355: Create CommentsSection Livewire component
RG-363: Create CommentForm Livewire component
RG-374: Compare comments UI with design checklist
```

## Release branch

После выполнения `RG-355`–`RG-374`:

```txt
release/v0.1.9-phase18-comments-ui
```

## Tag

После merge release branch в `main`:

```txt
v0.1.9-phase18-comments-ui
```
---

# 8. TDD Rules for Phase 18

## Для CommentsSection

Писать Livewire tests:

```txt
- component renders;
- visible comments render;
- hidden comments do not render;
- deleted comments do not render;
- empty state renders;
- loading state markup exists.
```

## Для CommentItem

Писать Blade render tests:

```txt
- author visible;
- body visible;
- timestamp visible;
- owner delete button visibility;
- moderator hide button visibility.
```

## Для CommentForm

Писать Livewire tests:

```txt
- submit creates comment through AddCommentAction;
- validation error visible;
- body clears after success;
- event dispatched after success;
- guest sees prompt or cannot submit.
```

## Для delete/hide UI

Писать Livewire tests:

```txt
- owner can delete own comment through UI;
- non-owner does not see/cannot call delete;
- moderator can hide comment through UI;
- normal user does not see/cannot call hide.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Tests
Type: Test / Feature / Component / Integration / Layout
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

# 10. Phase 18 Atomic Tasks
---

## RG-355 — Create CommentsSection Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-355-create-comments-section-livewire-component`  
**Base branch:** develop
**Depends on:** RG-354

### Goal

Создать Livewire-компонент `CommentsSection`.

### TDD step

Livewire test:

```php
it('can render comments section component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertStatus(200);
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```bash
php artisan make:livewire Comments/CommentsSection
```

Файлы:

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/livewire/comments/comments-section.blade.php
```

Минимальный class:

```php
final class CommentsSection extends Component
{
    public int $postId;

    public function render(): View
    {
        return view('livewire.comments.comments-section', [
            'comments' => collect(),
        ]);
    }
}
```

Минимальный view:

```blade
<section data-testid="comments-section">
    <h3>Comments</h3>
</section>
```

Пока не грузить list. Это RG-356/RG-357.

### Acceptance criteria

- `CommentsSection` component существует.
- Принимает `postId`.
- Component рендерится.
- View содержит `data-testid="comments-section"`.
- Нет business logic.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-355: Create CommentsSection Livewire component`

### Files likely touched

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/livewire/comments/comments-section.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-356 — Test CommentsSection Renders Comments

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-356-test-comments-section-renders-comments`  
**Base branch:** develop
**Depends on:** RG-355

### Goal

Написать падающий тест: `CommentsSection` показывает visible comments для post.

### TDD step

Livewire test:

```php
it('renders visible comments for post', function () {
    $post = Post::factory()->published()->create();

    $visible = Comment::factory()
        ->for($post)
        ->create([
            'body' => 'Looks delicious.',
            'status' => CommentStatus::Visible,
        ]);

    Comment::factory()
        ->for($post)
        ->create([
            'body' => 'Hidden comment',
            'status' => CommentStatus::Hidden,
        ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Looks delicious.')
        ->assertDontSee('Hidden comment');
});
```

Если SoftDeletes есть:

```php
$deleted = Comment::factory()->for($post)->create(['body' => 'Deleted comment']);
$deleted->delete();

->assertDontSee('Deleted comment')
```

Тест должен упасть до RG-357.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Visible comment виден.
- Hidden comment не виден.
- Deleted comment не виден, если soft deletes есть.
- Тест падает до RG-357.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-356: Test CommentsSection renders comments`

### Files likely touched

```txt
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-357 — Render Comments List

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-357-render-comments-list`  
**Base branch:** develop
**Depends on:** RG-356

### Goal

Реализовать загрузку и рендер списка visible comments.

### TDD step

Использовать падающий тест из RG-356.

### Implementation

В `CommentsSection`:

```php
public function getCommentsProperty(): Collection
{
    return Comment::query()
        ->where('post_id', $this->postId)
        ->where('status', CommentStatus::Visible)
        ->with('user')
        ->oldest()
        ->get();
}
```

Если Livewire v3 computed properties используются через атрибут:

```php
#[Computed]
public function comments(): Collection
```

В view:

```blade
<section data-testid="comments-section">
    <h3>Comments</h3>

    <div class="space-y-3">
        @foreach($this->comments as $comment)
            <article>
                {{ $comment->body }}
            </article>
        @endforeach
    </div>
</section>
```

Интегрировать CommentsSection в drawer и/or post show вместо placeholder:

```blade
<livewire:comments.comments-section :post-id="$post->id" />
```

Не использовать CommentItem пока. Это RG-358+.

### Acceptance criteria

- CommentsSection загружает comments по postId.
- Показывает только visible comments.
- Hidden/deleted comments не показываются.
- Comments sorted oldest-first.
- Eager loads user.
- Тест проходит.

### Definition of Done

- Query/list реализованы.
- Тест проходит.
- Коммит: `RG-357: Render comments list`

### Files likely touched

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/livewire/comments/comments-section.blade.php
resources/views/livewire/feed/post-drawer.blade.php
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-358 — Create CommentItem Blade Component

**Area:** UI / Blade  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-358-create-comment-item-blade-component`  
**Base branch:** develop
**Depends on:** RG-357

### Goal

Создать reusable Blade component для отображения одного comment.

### TDD step

Blade render test:

```php
it('renders comment item component', function () {
    $comment = Comment::factory()->make([
        'body' => 'Looks delicious.',
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('Looks delicious.');
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```txt
resources/views/components/comments/comment-item.blade.php
```

API:

```blade
<x-comments.comment-item :comment="$comment" />
```

Минимальный markup:

```blade
<article data-testid="comment-item">
    {{ $comment->body }}
</article>
```

Не добавлять full author/timestamp/action UI пока.

### Acceptance criteria

- `x-comments.comment-item` существует.
- Принимает `comment`.
- Рендерит body.
- Есть `data-testid="comment-item"`.
- Render test проходит.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-358: Create CommentItem Blade component`

### Files likely touched

```txt
resources/views/components/comments/comment-item.blade.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-359 — Render CommentItem In UI Kit

**Area:** UI / Dev  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-359-render-comment-item-in-ui-kit`  
**Base branch:** develop
**Depends on:** RG-358

### Goal

Добавить `CommentItem` preview в `/dev/ui-kit`.

### TDD step

Feature test:

```php
it('renders comment item example in ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Comment Item')
        ->assertSee('Looks delicious.');
});
```

### Implementation

В `resources/views/dev/ui-kit.blade.php` добавить секцию:

```txt
Comments
```

Example без database dependency:

```blade
@php
    $comment = new \App\Models\Comment([
        'body' => 'Looks delicious.',
        'created_at' => now()->subMinutes(5),
    ]);

    $comment->setRelation('user', new \App\Models\User([
        'name' => 'Demo User',
        'username' => 'demo_user',
    ]));
@endphp

<x-comments.comment-item :comment="$comment" />
```

### Acceptance criteria

- UI Kit показывает CommentItem section.
- Example не требует database.
- Тест проходит.
- Component visually inspectable.

### Definition of Done

- UI Kit обновлён.
- Тест проходит.
- Коммит: `RG-359: Render CommentItem in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-360 — Render Author In CommentItem

**Area:** UI / Blade  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-360-render-author-in-comment-item`  
**Base branch:** develop
**Depends on:** RG-359

### Goal

Отобразить автора в CommentItem.

### TDD step

Blade render test:

```php
it('renders comment author', function () {
    $user = User::factory()->make([
        'name' => 'Ivan',
        'username' => 'ivan',
    ]);

    $comment = Comment::factory()->make([
        'body' => 'Nice.',
    ]);

    $comment->setRelation('user', $user);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('Ivan');
    expect($html)->toContain('@ivan');
});
```

### Implementation

В `comment-item.blade.php`:

```blade
<div class="flex items-center gap-2">
    <x-ui.avatar :src="$comment->user?->avatar_url" :name="$comment->user?->name ?? 'User'" size="sm" />

    <div>
        <span>{{ $comment->user?->name ?? 'Unknown user' }}</span>

        @if($comment->user?->username)
            <span>{{ '@' . $comment->user->username }}</span>
        @endif
    </div>
</div>
```

### Acceptance criteria

- Author name visible.
- Username visible if exists.
- Avatar component used.
- Missing user relation does not break component.
- Тест проходит.

### Definition of Done

- Тест написан.
- Author block добавлен.
- Тест проходит.
- Коммит: `RG-360: Render author in CommentItem`

### Files likely touched

```txt
resources/views/components/comments/comment-item.blade.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-361 — Render Body In CommentItem

**Area:** UI / Blade  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-361-render-body-in-comment-item`  
**Base branch:** develop
**Depends on:** RG-360

### Goal

Оформить body в CommentItem.

### TDD step

Blade render test:

```php
it('renders escaped comment body', function () {
    $comment = Comment::factory()->make([
        'body' => '<script>alert("x")</script> Nice.',
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('&lt;script&gt;');
    expect($html)->not->toContain('<script>alert');
});
```

### Implementation

В component:

```blade
<p class="...">
    {{ $comment->body }}
</p>
```

Не использовать `{!! $comment->body !!}`.  
Не добавлять markdown в Phase 18.

### Acceptance criteria

- Body rendered.
- HTML escaped.
- Long text wraps.
- No markdown/rich text.
- Тест проходит.

### Definition of Done

- Тест написан.
- Body block добавлен.
- Тест проходит.
- Коммит: `RG-361: Render body in CommentItem`

### Files likely touched

```txt
resources/views/components/comments/comment-item.blade.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-362 — Render Timestamp In CommentItem

**Area:** UI / Blade  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-362-render-timestamp-in-comment-item`  
**Base branch:** develop
**Depends on:** RG-361

### Goal

Отобразить timestamp комментария.

### TDD step

Blade render test:

```php
it('renders comment timestamp', function () {
    $comment = Comment::factory()->make([
        'created_at' => now()->subMinutes(5),
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('ago');
});
```

Тест на `ago` может зависеть от локали. Более стабильный вариант:

```blade
<time datetime="{{ $comment->created_at?->toIso8601String() }}">
```

Test:

```php
expect($html)->toContain('<time');
expect($html)->toContain('datetime=');
```

### Implementation

В component:

```blade
@if($comment->created_at)
    <time
        datetime="{{ $comment->created_at->toIso8601String() }}"
        class="text-xs text-rg-muted"
    >
        {{ $comment->created_at->diffForHumans() }}
    </time>
@endif
```

### Acceptance criteria

- Timestamp visible if created_at exists.
- `<time datetime="">` used.
- Missing created_at does not break UI.
- Тест проходит.

### Definition of Done

- Тест написан.
- Timestamp block добавлен.
- Тест проходит.
- Коммит: `RG-362: Render timestamp in CommentItem`

### Files likely touched

```txt
resources/views/components/comments/comment-item.blade.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-363 — Create CommentForm Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-363-create-comment-form-livewire-component`  
**Base branch:** develop
**Depends on:** RG-362

### Goal

Создать Livewire-компонент `CommentForm`.

### TDD step

Livewire test:

```php
it('can render comment form component for authenticated user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertStatus(200);
});
```

Guest prompt test:

```php
it('renders login prompt for guest', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('Log in to comment');
});
```

### Implementation

Создать:

```bash
php artisan make:livewire Comments/CommentForm
```

Файлы:

```txt
app/Livewire/Comments/CommentForm.php
resources/views/livewire/comments/comment-form.blade.php
```

Class:

```php
final class CommentForm extends Component
{
    public int $postId;
    public string $body = '';

    public function render(): View
    {
        return view('livewire.comments.comment-form');
    }
}
```

View skeleton:

```blade
@if(auth()->check())
    <form data-testid="comment-form">
        Comment form
    </form>
@else
    <x-ui.empty-state title="Log in to comment" />
@endif
```

Пока не submit. Это RG-364+.

### Acceptance criteria

- `CommentForm` exists.
- Accepts `postId`.
- Authenticated user sees form shell.
- Guest sees login prompt.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Component создан.
- Тесты проходят.
- Коммит: `RG-363: Create CommentForm Livewire component`

### Files likely touched

```txt
app/Livewire/Comments/CommentForm.php
resources/views/livewire/comments/comment-form.blade.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-364 — Test CommentForm Creates Comment

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-364-test-comment-form-creates-comment`  
**Base branch:** develop
**Depends on:** RG-363

### Goal

Написать падающий тест: CommentForm вызывает AddCommentAction и создаёт comment.

### TDD step

Livewire test:

```php
it('creates comment from form submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Looks delicious.')
        ->call('submit')
        ->assertDispatched('comment-created');

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Looks delicious.',
    ]);
});
```

Тест должен упасть до реализации submit.

### Implementation

В `CommentForm`:

```php
public ?string $submitError = null;

public function submit(AddCommentAction $addCommentAction): void
{
    $this->submitError = null;

    try {
        $post = Post::query()->published()->findOrFail($this->postId);

        $comment = $addCommentAction->handle(
            user: auth()->user(),
            post: $post,
            body: $this->body,
        );

        $this->dispatch('comment-created', postId: $this->postId, commentId: $comment->id);
    } catch (CannotCommentException $e) {
        $this->addError('body', $e->getMessage());
    }
}
```

Не делать body reset пока. Это RG-368.

### Acceptance criteria

- submit method exists.
- Calls AddCommentAction.
- Comment created.
- Dispatches `comment-created`.
- Backend exception converted into validation-like error.
- Test passes.

### Definition of Done

- Тест написан.
- Submit logic добавлена.
- Тест проходит.
- Коммит: `RG-364: Test CommentForm creates comment`

### Files likely touched

```txt
app/Livewire/Comments/CommentForm.php
resources/views/livewire/comments/comment-form.blade.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-365 — Render Comment Textarea

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-365-render-comment-textarea`  
**Base branch:** develop
**Depends on:** RG-364

### Goal

Добавить textarea для comment body.

### TDD step

Livewire/markup test:

```php
it('renders comment textarea', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('name="body"', false)
        ->assertSee('Write a comment');
});
```

### Implementation

В `comment-form.blade.php`:

```blade
<x-ui.label for="comment-body">Comment</x-ui.label>

<x-ui.textarea
    id="comment-body"
    name="body"
    wire:model.defer="body"
    rows="3"
    maxlength="1000"
    placeholder="Write a comment..."
/>
```

Если `x-ui.label` нет, использовать plain label.

### Acceptance criteria

- Textarea visible for authenticated user.
- Guest does not see active textarea.
- Textarea binds to `body`.
- `maxlength="1000"` present.
- Test passes.

### Definition of Done

- Тест написан.
- Textarea добавлена.
- Тест проходит.
- Коммит: `RG-365: Render comment textarea`

### Files likely touched

```txt
resources/views/livewire/comments/comment-form.blade.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-366 — Render Submit Button

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-366-render-submit-button`  
**Base branch:** develop
**Depends on:** RG-365

### Goal

Добавить submit button.

### TDD step

Livewire/markup test:

```php
it('renders comment submit button', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->assertSee('Post comment')
        ->assertSee('wire:submit', false);
});
```

### Implementation

В view:

```blade
<form wire:submit.prevent="submit" data-testid="comment-form">
    ...
    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
        <span wire:loading.remove wire:target="submit">Post comment</span>
        <span wire:loading wire:target="submit">Posting...</span>
    </x-ui.button>
</form>
```

### Acceptance criteria

- Form uses `wire:submit.prevent="submit"`.
- Submit button visible.
- Button disabled during submit.
- Loading label present.
- Test passes.

### Definition of Done

- Тест написан.
- Submit button добавлен.
- Тест проходит.
- Коммит: `RG-366: Render submit button`

### Files likely touched

```txt
resources/views/livewire/comments/comment-form.blade.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-367 — Render Comment Validation Error

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-367-render-comment-validation-error`  
**Base branch:** develop
**Depends on:** RG-366

### Goal

Отображать validation/backend errors под textarea.

### TDD step

Livewire test:

```php
it('renders comment validation error', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', '')
        ->call('submit')
        ->assertSee('data-testid="comment-body-error"', false);
});
```

Не завязываться на точный текст exception, чтобы не ломаться от wording.

### Implementation

В view:

```blade
<div data-testid="comment-body-error">
    <x-ui.field-error :message="$errors->first('body')" />
</div>
```

Если `submitError` используется отдельно:

```blade
@if($submitError)
    <x-ui.error-message :message="$submitError" />
@endif
```

### Acceptance criteria

- Error placeholder visible.
- Empty comment submit shows body error.
- Error uses RateGuru field/error component.
- Test passes.

### Definition of Done

- Тест написан.
- Error rendering добавлен.
- Тест проходит.
- Коммит: `RG-367: Render comment validation error`

### Files likely touched

```txt
resources/views/livewire/comments/comment-form.blade.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-368 — Clear Comment Body After Submit

**Area:** Livewire / UX  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-368-clear-comment-body-after-submit`  
**Base branch:** develop
**Depends on:** RG-367

### Goal

Очищать textarea после успешного submit.

### TDD step

Livewire test:

```php
it('clears comment body after successful submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CommentForm::class, ['postId' => $post->id])
        ->set('body', 'Looks delicious.')
        ->call('submit')
        ->assertSet('body', '');
});
```

Validation failure должен не очищать body:

```php
it('keeps comment body after validation failure when body is too long', function () {
    ...
});
```

Этот второй тест полезен, но не обязателен.

### Implementation

В `submit()` после успешного action:

```php
$this->reset('body');
```

или:

```php
$this->body = '';
```

Делать reset только после success, не в catch.

### Acceptance criteria

- Body clears after successful submit.
- Body does not clear before successful action.
- comment-created event still dispatches.
- Test passes.

### Definition of Done

- Тест написан.
- Body reset добавлен.
- Тест проходит.
- Коммит: `RG-368: Clear comment body after submit`

### Files likely touched

```txt
app/Livewire/Comments/CommentForm.php
tests/Feature/Livewire/CommentFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-369 — Refresh Comments After Submit

**Area:** Livewire / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-369-refresh-comments-after-submit`  
**Base branch:** develop
**Depends on:** RG-368

### Goal

Обновлять CommentsSection после успешного добавления comment.

### TDD step

Livewire test:

```php
it('refreshes comments section after comment-created event', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()
        ->for($post)
        ->create([
            'body' => 'New comment',
            'status' => CommentStatus::Visible,
        ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->dispatch('comment-created', postId: $post->id)
        ->assertSee('New comment');
});
```

Более интеграционный test можно сделать на parent surface позже.  
Минимально CommentsSection должен слушать event.

### Implementation

В `CommentsSection`:

```php
#[On('comment-created')]
public function refreshAfterCommentCreated(int $postId): void
{
    if ($postId !== $this->postId) {
        return;
    }

    // no-op; method call triggers re-render
}
```

Если используется computed comments caching — сбросить cache.  
Также добавить listener для delete/hide events позже:

```txt
comment-deleted
comment-hidden
```

но можно добавить сразу.

В view использовать CommentItem:

```blade
@foreach($this->comments as $comment)
    <x-comments.comment-item :comment="$comment" />
@endforeach
```

### Acceptance criteria

- CommentsSection listens to `comment-created`.
- Refresh happens only for same postId.
- New comment appears after event.
- CommentsSection uses CommentItem for list rendering.
- Test passes.

### Definition of Done

- Listener добавлен.
- CommentItem интегрирован.
- Тест проходит.
- Коммит: `RG-369: Refresh comments after submit`

### Files likely touched

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/livewire/comments/comments-section.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-370 — Add Delete Own Comment Button

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-370-add-delete-own-comment-button`  
**Base branch:** develop
**Depends on:** RG-369

### Goal

Добавить кнопку удаления собственного комментария.

### TDD step

Livewire tests:

```php
it('allows owner to delete own comment from comments section', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()
        ->for($user)
        ->for($post)
        ->create([
            'body' => 'My comment',
            'status' => CommentStatus::Visible,
        ]);

    Livewire::actingAs($user)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Delete')
        ->call('deleteComment', $comment->id)
        ->assertDontSee('My comment')
        ->assertDispatched('comment-deleted');
});
```

Non-owner visibility/call test:

```php
it('does not render delete button for non owner', ...)
```

### Implementation

В `CommentsSection` добавить method:

```php
public function deleteComment(int $commentId, DeleteCommentAction $deleteCommentAction): void
{
    $comment = Comment::query()
        ->where('post_id', $this->postId)
        ->findOrFail($commentId);

    $deleteCommentAction->handle(auth()->user(), $comment);

    $this->dispatch('comment-deleted', postId: $this->postId, commentId: $commentId);
}
```

В `CommentItem` добавить optional props:

```blade
@props([
    'comment',
    'canDelete' => false,
    'canHide' => false,
])
```

Button:

```blade
@if($canDelete)
    <button
        type="button"
        wire:click="deleteComment({{ $comment->id }})"
        wire:confirm="Delete this comment?"
    >
        Delete
    </button>
@endif
```

В CommentsSection view:

```blade
<x-comments.comment-item
    :comment="$comment"
    :can-delete="auth()->id() === $comment->user_id"
/>
```

### Acceptance criteria

- Owner sees Delete button.
- Non-owner does not see Delete button.
- Owner can delete own comment.
- Deleted comment disappears.
- `comment-deleted` dispatched.
- Backend DeleteCommentAction used.
- Test passes.

### Definition of Done

- Tests написаны.
- Delete button/action wiring добавлены.
- Tests проходят.
- Коммит: `RG-370: Add delete own comment button`

### Files likely touched

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/components/comments/comment-item.blade.php
resources/views/livewire/comments/comments-section.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-371 — Add Moderator Hide Comment Button

**Area:** Livewire / UI / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-371-add-moderator-hide-comment-button`  
**Base branch:** develop
**Depends on:** RG-370

### Goal

Добавить кнопку Hide для moderator/admin.

### TDD step

Livewire tests:

```php
it('allows moderator to hide comment from comments section', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $comment = Comment::factory()
        ->for($post)
        ->create([
            'body' => 'Bad comment',
            'status' => CommentStatus::Visible,
        ]);

    Livewire::actingAs($moderator)
        ->test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('Hide')
        ->call('hideComment', $comment->id)
        ->assertDontSee('Bad comment')
        ->assertDispatched('comment-hidden');

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});
```

Normal user test:

```php
it('does not render hide button for normal user', ...)
```

### Implementation

В `CommentsSection`:

```php
public function hideComment(int $commentId, HideCommentAction $hideCommentAction): void
{
    $comment = Comment::query()
        ->where('post_id', $this->postId)
        ->findOrFail($commentId);

    $hideCommentAction->handle(auth()->user(), $comment);

    $this->dispatch('comment-hidden', postId: $this->postId, commentId: $commentId);
}
```

Visibility helper:

```php
private function userCanHideComments(): bool
{
    $user = auth()->user();

    return $user && ($user->isModerator() || $user->isAdmin());
}
```

В CommentItem:

```blade
@if($canHide)
    <button
        type="button"
        wire:click="hideComment({{ $comment->id }})"
        wire:confirm="Hide this comment?"
    >
        Hide
    </button>
@endif
```

### Acceptance criteria

- Moderator/admin sees Hide button.
- Normal user does not see Hide button.
- Moderator can hide comment.
- Hidden comment disappears.
- `comment-hidden` dispatched.
- Backend HideCommentAction used.
- Test passes.

### Definition of Done

- Tests написаны.
- Hide button/action wiring добавлены.
- Tests проходят.
- Коммит: `RG-371: Add moderator hide comment button`

### Files likely touched

```txt
app/Livewire/Comments/CommentsSection.php
resources/views/components/comments/comment-item.blade.php
resources/views/livewire/comments/comments-section.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-372 — Add Comments Empty State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-372-add-comments-empty-state`  
**Base branch:** develop
**Depends on:** RG-371

### Goal

Добавить empty state, когда у post нет visible comments.

### TDD step

Livewire test:

```php
it('renders comments empty state when no visible comments exist', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()
        ->for($post)
        ->create([
            'body' => 'Hidden comment',
            'status' => CommentStatus::Hidden,
        ]);

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('No comments yet')
        ->assertDontSee('Hidden comment');
});
```

### Implementation

В `comments-section.blade.php`:

```blade
@if($this->comments->isEmpty())
    <x-ui.empty-state
        title="No comments yet"
        description="Be the first to comment."
    />
@else
    ...
@endif
```

### Acceptance criteria

- Empty state appears when no visible comments.
- Hidden/deleted comments do not prevent empty state.
- Empty state uses `x-ui.empty-state`.
- Test passes.

### Definition of Done

- Тест написан.
- Empty state добавлен.
- Тест проходит.
- Коммит: `RG-372: Add comments empty state`

### Files likely touched

```txt
resources/views/livewire/comments/comments-section.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-373 — Add Comments Loading State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-373-add-comments-loading-state`  
**Base branch:** develop
**Depends on:** RG-372

### Goal

Добавить loading state для comments actions.

### TDD step

Markup test:

```php
it('has comments loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertSee('data-testid="comments-loading"', false)
        ->assertSee('wire:loading', false);
});
```

### Implementation

В CommentsSection:

```blade
<div
    wire:loading
    wire:target="deleteComment,hideComment"
    data-testid="comments-loading"
>
    <x-ui.skeleton shape="line" width="70%" />
</div>
```

В CommentForm уже есть submit loading из RG-366.  
Если нет — добавить `wire:loading` на submit button.

### Acceptance criteria

- CommentsSection has loading markup.
- Delete/hide buttons show disabled/loading behavior.
- Uses `x-ui.skeleton` or consistent loading UI.
- Test passes.

### Definition of Done

- Loading state добавлен.
- Тест проходит.
- Коммит: `RG-373: Add comments loading state`

### Files likely touched

```txt
resources/views/livewire/comments/comments-section.blade.php
resources/views/components/comments/comment-item.blade.php
tests/Feature/Livewire/CommentsSectionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-374 — Compare Comments UI With Design Checklist

**Area:** UI / Docs / QA  
**Type:** Docs / QA  
**Priority:** P0  
**Branch:** `feature/RG-374-compare-comments-ui-with-design-checklist`  
**Base branch:** develop
**Depends on:** RG-373

### Goal

Финально сверить Comments UI с design contract и предыдущими detail surfaces.

### TDD step

No direct test — visual QA/documentation task.

Запустить:

```bash
composer test
npm run build
```

Проверить вручную:

```txt
/dev/ui-kit
post drawer comments
post show comments
guest state
authenticated form
validation error
successful submit
delete own comment
moderator hide comment
empty state
loading state
mobile drawer
desktop post show
```

### Implementation

Создать:

```txt
docs/design/phase-18-comments-ui-review.md
```

Содержимое:

```md
# Phase 18 Comments UI Review

## Reference checked
- [ ] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/
- [ ] docs/design/design-contract.md
- [ ] docs/design/ui-review-checklist.md
- [ ] /dev/ui-kit
- [ ] docs/design/phase-11-drawer-ui-review.md
- [ ] docs/design/phase-12-post-show-page-review.md

## Components
- [ ] CommentsSection renders visible comments
- [ ] CommentItem renders author
- [ ] CommentItem renders escaped body
- [ ] CommentItem renders timestamp
- [ ] CommentForm renders textarea
- [ ] CommentForm renders submit button
- [ ] CommentForm renders validation errors

## States
- [ ] Guest sees login prompt
- [ ] Empty state works
- [ ] Loading state works
- [ ] Submit clears body
- [ ] Comments refresh after submit
- [ ] Delete own comment works
- [ ] Moderator hide comment works

## Layout
- [ ] Drawer layout checked
- [ ] Post show layout checked
- [ ] Mobile checked
- [ ] Desktop checked

## Known deviations
- ...
```

Если CommentsSection не интегрирован в PostShow/Drawer, это не “маленькая недоделка”, а провал Phase 18: UI будет существовать, но не использоваться.

### Acceptance criteria

- `docs/design/phase-18-comments-ui-review.md` exists.
- Checklist filled.
- Known deviations documented.
- Comments UI visible in drawer and post show.
- `/dev/ui-kit` shows CommentItem.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- UI review документ создан.
- Manual checks выполнены.
- Tests/build проходят.
- Коммит: `RG-374: Compare comments UI with design checklist`

### Files likely touched

```txt
docs/design/phase-18-comments-ui-review.md
resources/views/livewire/feed/post-drawer.blade.php
resources/views/livewire/posts/post-show.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 11. Phase 18 Completion Criteria

Phase 18 завершена, когда:

```txt
- RG-355–RG-374 выполнены;
- CommentsSection exists;
- CommentsSection renders visible comments;
- Hidden/deleted comments do not render;
- Comments list uses CommentItem;
- CommentItem exists;
- CommentItem is in UI kit;
- CommentItem renders author;
- CommentItem renders escaped body;
- CommentItem renders timestamp;
- CommentForm exists;
- CommentForm creates comment through AddCommentAction;
- Comment textarea renders;
- Submit button renders;
- Validation errors render;
- Body clears after successful submit;
- Comments refresh after submit;
- Owner can delete own comment from UI;
- Non-owner cannot delete other user's comment from UI;
- Moderator/admin can hide comment from UI;
- Normal user cannot hide comment from UI;
- Empty state works;
- Loading state works;
- Comments UI is integrated into drawer and post show;
- design checklist review created;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 18

Без отдельной задачи нельзя:

```txt
- делать report comment button;
- создавать ReportModal;
- делать nested replies;
- делать edit comment;
- делать comment likes;
- делать markdown/rich text;
- делать WYSIWYG editor;
- делать spam detection;
- делать rate limiting UI;
- делать notifications after comment;
- делать Filament Comments Resource;
- делать API endpoint;
- добавлять Redis/cache layer;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-355 Create CommentsSection Livewire component
RG-356 Test CommentsSection renders comments
RG-357 Render comments list
RG-358 Create CommentItem Blade component
RG-359 Render CommentItem in UI kit
RG-360 Render author in CommentItem
RG-361 Render body in CommentItem
RG-362 Render timestamp in CommentItem
RG-363 Create CommentForm Livewire component
RG-364 Test CommentForm creates comment
RG-365 Render comment textarea
RG-366 Render submit button
RG-367 Render comment validation error
RG-368 Clear comment body after submit
RG-369 Refresh comments after submit
RG-370 Add delete own comment button
RG-371 Add moderator hide comment button
RG-372 Add comments empty state
RG-373 Add comments loading state
RG-374 Compare comments UI with design checklist
```
---

# 14. Release

После завершения Phase 18:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.9-phase18-comments-ui
git push -u origin release/v0.1.9-phase18-comments-ui
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.9-phase18-comments-ui -m "RateGuru Phase 18 comments UI"
git push origin v0.1.9-phase18-comments-ui
```
