# RateGuru — Phase 20 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 20 — Reports UI**  
Диапазон задач: **RG-395 → RG-406**  
Основа нумерации: исходный atomic backlog, где Phase 20 начинается с задачи 395 и заканчивается задачей 406.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 20 соответствует исходному блоку:

```txt
Phase 20 — Reports UI
```

Правильный диапазон Phase 20:

```txt
RG-395 — Create ReportModal Livewire component
RG-396 — Test ReportModal renders reasons
RG-397 — Add Alpine open/close behavior for report modal
RG-398 — Render report reason selector
RG-399 — Render report message textarea
RG-400 — Test ReportModal submits post report
RG-401 — Connect ReportModal to ReportContentAction
RG-402 — Render report success state
RG-403 — Render report validation errors
RG-404 — Add report button to PostCard menu
RG-405 — Add report button to PostDrawer
RG-406 — Add report button to CommentItem
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: следующая фаза **Phase 21 — Moderation Backend** начинается с `RG-407`, поэтому Phase 20 не должна захватывать moderation actions.
---

# 2. Цель Phase 20

Phase 20 добавляет UI для отправки жалоб на контент поверх backend-а из Phase 19.

После Phase 20 пользователь должен уметь:

```txt
- открыть report modal для post;
- открыть report modal для comment;
- выбрать reason;
- оставить optional message/details;
- отправить report через ReportContentAction;
- увидеть success state;
- увидеть validation/backend errors;
- пожаловаться из PostCard menu;
- пожаловаться из PostDrawer;
- пожаловаться из CommentItem.
```

Phase 20 не модерирует контент. Она только создаёт report.
---

# 3. Scope Phase 20

## Входит

```txt
- ReportModal Livewire component;
- report reasons list;
- Alpine open/close behavior;
- reason selector;
- optional message textarea;
- submit post report;
- submit comment report через тот же component;
- integration with ReportContentAction;
- success state;
- validation/backend error rendering;
- report button in PostCard menu;
- report button in PostDrawer;
- report button in CommentItem.
```

## Не входит

```txt
- moderation backend actions;
- approve/reject/hide/restore actions;
- admin moderation dashboard;
- Filament reports resource;
- notifications to moderators;
- report rate limiting;
- anti-abuse scoring;
- report history UI;
- report button in PostShow, если нет отдельной задачи;
- auto-hide content after report;
- resolve report UI.
```

Phase 21 — Moderation Backend.  
Phase 27 — Filament Reports Resource.  
Phase 29 — Moderation Dashboard.  
Phase 34 — Rate Limiting & Abuse Guards.
---

# 4. Product / UX Decisions

## 4.1. One generic ReportModal

Используем один generic component:

```txt
ReportModal
```

Он должен уметь принимать target:

```txt
reportableType
reportableId
```

Поддерживаемые значения:

```txt
post
comment
```

Не создавать отдельные:

```txt
PostReportModal
CommentReportModal
```

Это быстро приведёт к дублированию.

## 4.2. Report buttons visibility

MVP-поведение:

```txt
- guest может видеть report button, но при открытии modal видит login prompt;
- authenticated active user видит form;
- banned user видит error/prompt или form disabled.
```

Почему не скрывать полностью:

```txt
- пользователь понимает, что report существует;
- backend всё равно защищён;
- Phase 20 не делает полноценный login modal.
```

Если хочется проще, можно скрыть report button для guest, но тогда UX хуже.  
Рекомендуемый вариант: button виден, modal показывает “Log in to report”.

## 4.3. Report reason required

Reason обязателен.  
Default state:

```txt
reason = ''
```

Пользователь должен выбрать reason явно.

Не выставлять default `spam`, иначе часть жалоб будет неверно классифицирована.

## 4.4. Message optional

Message/details optional.

Rules:

```txt
- optional;
- trim before submit;
- empty trimmed message becomes null;
- max length = 1000 chars.
```

Это должно совпадать с backend Phase 19.

## 4.5. Duplicate report UX

Если backend вернул duplicate report error:

```txt
You have already reported this content.
```

UI должен показать error state, а не падать.

Не пытаться заранее проверять duplicate report в UI.  
Backend is source of truth.

## 4.6. Success state

После успешного submit modal показывает success state:

```txt
Report submitted
Thanks for helping keep RateGuru useful.
```

Не закрывать modal мгновенно.  
Пользователь должен увидеть, что действие прошло.

Можно добавить close button.

## 4.7. No auto-moderation feedback

Не писать:

```txt
This content will be removed
We will ban the user
This post is now hidden
```

Это ложное обещание. Phase 20 только отправляет report.
---

# 5. Architecture Rules

## 5.1. ReportModal calls ReportContentAction

Нельзя делать в component:

```php
Report::create(...)
```

Правильно:

```php
app(ReportContentAction::class)->handle(
    user: auth()->user(),
    content: $this->resolveReportable(),
    reason: ReportReason::from($this->reason),
    message: $this->message,
);
```

## 5.2. ReportModal resolves reportable safely

Component должен принимать только безопасные target types:

```txt
post
comment
```

Не принимать arbitrary model class from browser.

Правильно:

```php
match ($this->reportableType) {
    'post' => Post::query()->published()->findOrFail($this->reportableId),
    'comment' => Comment::query()->where('status', visible)->findOrFail($this->reportableId),
    default => abort(404),
}
```

Нельзя передавать `reportable_type = App\Models\Post` из client side без whitelist.

## 5.3. Comment reports must target visible comments

ReportModal не должен позволять жаловаться на hidden/deleted comments.

Query для comment target:

```txt
status = visible
not soft-deleted
```

## 5.4. Report buttons do not contain business logic

PostCard/PostDrawer/CommentItem только открывают modal.  
Они не создают reports.

## 5.5. No moderation state changes

ReportModal не должен:

```txt
- менять post status;
- менять comment status;
- выставлять needs_review напрямую;
- resolve report;
- hide content.
```

`ReportContentAction` сам обновляет counters и threshold flag.
---

# 6. Design Constraints

Report UI должен продолжать RateGuru visual language:

```txt
- dark modal surface;
- rounded panel;
- reason selector as clean radio/cards/select;
- optional textarea;
- purple submit button;
- muted helper text;
- red/error state readable;
- success state clear but not noisy;
- mobile safe.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-11-drawer-ui-review.md
docs/design/phase-18-comments-ui-review.md
```

Если review docs отсутствуют, зафиксировать в Phase 20 review notes, но не блокировать implementation.
---

# 7. GitFlow для Phase 20

## Base branch

Все задачи Phase 20 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-395-create-report-modal-livewire-component
feature/RG-401-connect-report-modal-to-report-content-action
feature/RG-406-add-report-button-to-comment-item
```

## Commit format

```txt
RG-395: Create ReportModal Livewire component
RG-401: Connect ReportModal to ReportContentAction
RG-406: Add report button to CommentItem
```

## Release branch

После выполнения `RG-395`–`RG-406`:

```txt
release/v0.2.1-phase20-reports-ui
```

## Tag

После merge release branch в `main`:

```txt
v0.2.1-phase20-reports-ui
```
---

# 8. TDD Rules for Phase 20

## Для ReportModal

Писать Livewire tests:

```txt
- component renders;
- reasons render;
- submit creates post report;
- submit creates comment report, если покрываем в RG-401;
- validation errors render;
- success state renders;
- duplicate report error renders.
```

## Для Alpine behavior

Unit-тестом проверяем markup:

```txt
- x-data;
- x-show;
- @click;
- @keydown.escape.window;
- x-cloak.
```

Фактическое open/close поведение проверяется вручную.

## Для UI integration

Писать Blade/Livewire tests:

```txt
- PostCard has report button/menu entry;
- PostDrawer has report button;
- CommentItem has report button;
- buttons include ReportModal or dispatch open report modal event.
```

## Для security

ReportModal tests должны проверять:

```txt
- unsupported reportable type fails;
- guest does not create report;
- hidden comment cannot be reported, если удобно.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Reports / Tests
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

# 10. Phase 20 Atomic Tasks
---

## RG-395 — Create ReportModal Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-395-create-report-modal-livewire-component`  
**Base branch:** develop
**Depends on:** RG-394

### Goal

Создать Livewire component `ReportModal`.

### TDD step

Livewire test:

```php
it('can render report modal component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])->assertStatus(200);
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```bash
php artisan make:livewire Reports/ReportModal
```

Файлы:

```txt
app/Livewire/Reports/ReportModal.php
resources/views/livewire/reports/report-modal.blade.php
```

Class skeleton:

```php
final class ReportModal extends Component
{
    public string $reportableType;
    public int $reportableId;

    public string $reason = '';
    public ?string $message = null;

    public bool $submitted = false;

    public function render(): View
    {
        return view('livewire.reports.report-modal');
    }
}
```

View skeleton:

```blade
<div data-testid="report-modal">
    Report content
</div>
```

Не подключать action пока.

### Acceptance criteria

- `ReportModal` exists.
- Accepts `reportableType`.
- Accepts `reportableId`.
- Has `reason`, `message`, `submitted` properties.
- View has `data-testid="report-modal"`.
- Test passes.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-395: Create ReportModal Livewire component`

### Files likely touched

```txt
app/Livewire/Reports/ReportModal.php
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-396 — Test ReportModal Renders Reasons

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-396-test-report-modal-renders-reasons`  
**Base branch:** develop
**Depends on:** RG-395

### Goal

Написать тест: ReportModal показывает все report reasons.

### TDD step

Livewire test:

```php
it('renders report reasons', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('Spam')
        ->assertSee('Harassment')
        ->assertSee('Nudity')
        ->assertSee('Violence')
        ->assertSee('Hate')
        ->assertSee('Copyright')
        ->assertSee('Illegal')
        ->assertSee('Other');
});
```

Если enum labels отличаются, тестировать actual labels из `ReportReason`.

### Implementation

В `ReportModal` добавить:

```php
public function getReasonsProperty(): array
{
    return collect(ReportReason::cases())
        ->map(fn (ReportReason $reason) => [
            'value' => $reason->value,
            'label' => $this->labelForReason($reason),
        ])
        ->all();
}
```

В view временно вывести список:

```blade
@foreach($this->reasons as $reason)
    {{ $reason['label'] }}
@endforeach
```

### Acceptance criteria

- Все reasons из `ReportReason` рендерятся.
- `reason` не выбран по умолчанию.
- Test passes.
- No submit logic yet.

### Definition of Done

- Тест написан.
- Reasons rendering добавлен.
- Тест проходит.
- Коммит: `RG-396: Test ReportModal renders reasons`

### Files likely touched

```txt
app/Livewire/Reports/ReportModal.php
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-397 — Add Alpine Open/Close Behavior For Report Modal

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-397-add-alpine-open-close-behavior-for-report-modal`  
**Base branch:** develop
**Depends on:** RG-396

### Goal

Добавить Alpine open/close behavior для report modal.

### TDD step

Markup test:

```php
it('has alpine report modal open close behavior', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('x-data', false)
        ->assertSee('reportOpen', false)
        ->assertSee('x-show', false)
        ->assertSee('@keydown.escape.window', false);
});
```

Тест проверяет markup, не реальное браузерное поведение.

### Implementation

В `report-modal.blade.php`:

```blade
<div
    x-data="{ reportOpen: false }"
    @keydown.escape.window="reportOpen = false"
    data-testid="report-modal-wrapper"
>
    <button
        type="button"
        data-testid="open-report-modal"
        @click="reportOpen = true"
    >
        Report
    </button>

    <div x-show="reportOpen" x-cloak>
        <x-ui.modal title="Report content">
            <button
                type="button"
                data-testid="close-report-modal"
                @click="reportOpen = false"
            >
                Close
            </button>

            ...
        </x-ui.modal>
    </div>
</div>
```

Если `x-ui.modal` уже имеет close slot, использовать его.

### Acceptance criteria

- Wrapper has `x-data`.
- Trigger opens modal.
- Modal uses `x-show`.
- Escape closes modal.
- Close button exists.
- `x-cloak` used.
- Markup test passes.
- Manual open/close check выполнен.

### Definition of Done

- Alpine behavior добавлен.
- Тест проходит.
- Manual check выполнен.
- Коммит: `RG-397: Add Alpine open/close behavior for report modal`

### Files likely touched

```txt
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-398 — Render Report Reason Selector

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-398-render-report-reason-selector`  
**Base branch:** develop
**Depends on:** RG-397

### Goal

Оформить selector для выбора reason.

### TDD step

Livewire test:

```php
it('renders report reason selector and updates reason', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('name="reason"', false)
        ->set('reason', ReportReason::Spam->value)
        ->assertSet('reason', ReportReason::Spam->value);
});
```

### Implementation

В view:

```blade
<fieldset data-testid="report-reason-selector">
    <legend>Reason</legend>

    @foreach($this->reasons as $reason)
        <label>
            <input
                type="radio"
                name="reason"
                value="{{ $reason['value'] }}"
                wire:model.live="reason"
            >
            <span>{{ $reason['label'] }}</span>
        </label>
    @endforeach
</fieldset>
```

Можно оформить как cards/pills.  
Не использовать default selected reason.

### Acceptance criteria

- Reason selector visible.
- All reasons available.
- `reason` property updates.
- No default reason.
- Selected state visually clear.
- Test passes.

### Definition of Done

- Тест написан.
- Reason selector добавлен.
- Тест проходит.
- Коммит: `RG-398: Render report reason selector`

### Files likely touched

```txt
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-399 — Render Report Message Textarea

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-399-render-report-message-textarea`  
**Base branch:** develop
**Depends on:** RG-398

### Goal

Добавить optional message/details textarea.

### TDD step

Livewire test:

```php
it('renders report message textarea', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('name="message"', false)
        ->assertSee('Optional details')
        ->set('message', 'This content is spam.')
        ->assertSet('message', 'This content is spam.');
});
```

### Implementation

В view:

```blade
<x-ui.label for="report-message">Optional details</x-ui.label>

<x-ui.textarea
    id="report-message"
    name="message"
    wire:model.defer="message"
    rows="4"
    maxlength="1000"
    placeholder="Add context for moderators..."
/>

<p class="text-xs text-rg-muted">
    Optional. Max 1000 characters.
</p>
```

### Acceptance criteria

- Message textarea visible.
- Message optional.
- `maxlength="1000"` present.
- Property updates.
- Uses `x-ui.textarea`.
- Test passes.

### Definition of Done

- Тест написан.
- Textarea добавлена.
- Тест проходит.
- Коммит: `RG-399: Render report message textarea`

### Files likely touched

```txt
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-400 — Test ReportModal Submits Post Report

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-400-test-report-modal-submits-post-report`  
**Base branch:** develop
**Depends on:** RG-399

### Goal

Написать падающий тест: ReportModal submit создаёт report для post.

### TDD step

Livewire test:

```php
it('submits post report from report modal', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->set('message', 'This looks like spam.')
        ->call('submit')
        ->assertSet('submitted', true);

    $this->assertDatabaseHas('reports', [
        'user_id' => $user->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
    ]);
});
```

Add comment report test either here or RG-401:

```php
it('submits comment report from report modal', ...)
```

Лучше добавить comment test в RG-401, где подключается action fully.

Тест должен упасть до RG-401.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Submit with reason/message creates post report.
- `submitted` becomes true.
- Test падает до RG-401.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-400: Test ReportModal submits post report`

### Files likely touched

```txt
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-401 — Connect ReportModal To ReportContentAction

**Area:** Livewire / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-401-connect-report-modal-to-report-content-action`  
**Base branch:** develop
**Depends on:** RG-400

### Goal

Реализовать submit в ReportModal и подключить ReportContentAction.

### TDD step

Использовать падающий тест из RG-400.

Добавить comment report test:

```php
it('submits comment report from report modal', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'comment',
            'reportableId' => $comment->id,
        ])
        ->set('reason', ReportReason::Harassment->value)
        ->call('submit')
        ->assertSet('submitted', true);

    $this->assertDatabaseHas('reports', [
        'user_id' => $user->id,
        'reportable_type' => Comment::class,
        'reportable_id' => $comment->id,
        'reason' => ReportReason::Harassment->value,
    ]);
});
```

Unsupported type test:

```php
it('rejects unsupported reportable type', ...)
```

### Implementation

В `ReportModal`:

```php
public function submit(ReportContentAction $reportContentAction): void
{
    $this->resetErrorBag();

    try {
        $this->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $reportContentAction->handle(
            user: auth()->user(),
            content: $this->resolveReportable(),
            reason: ReportReason::from($this->reason),
            message: $this->message,
        );

        $this->submitted = true;
        $this->dispatch('content-reported', type: $this->reportableType, id: $this->reportableId);
    } catch (CannotReportContentException $e) {
        $this->addError('report', $e->getMessage());
    }
}
```

Resolve method:

```php
private function resolveReportable(): Model
{
    return match ($this->reportableType) {
        'post' => Post::query()
            ->published()
            ->findOrFail($this->reportableId),

        'comment' => Comment::query()
            ->where('status', CommentStatus::Visible)
            ->findOrFail($this->reportableId),

        default => abort(404),
    };
}
```

If `published()` scope does not exist, use explicit status check.

### Acceptance criteria

- Post report submit works.
- Comment report submit works.
- Uses ReportContentAction.
- Unsupported target type rejected.
- Hidden/deleted comment cannot be reported.
- Dispatches `content-reported`.
- `submitted = true` after success.
- Tests pass.

### Definition of Done

- Submit method implemented.
- Target resolution whitelist added.
- Tests pass.
- Коммит: `RG-401: Connect ReportModal to ReportContentAction`

### Files likely touched

```txt
app/Livewire/Reports/ReportModal.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-402 — Render Report Success State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-402-render-report-success-state`  
**Base branch:** develop
**Depends on:** RG-401

### Goal

Показывать success state после успешного report submit.

### TDD step

Livewire test:

```php
it('renders report success state after submit', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', ReportReason::Spam->value)
        ->call('submit')
        ->assertSee('Report submitted')
        ->assertSee('Thanks for helping');
});
```

### Implementation

В view:

```blade
@if($submitted)
    <x-ui.empty-state
        title="Report submitted"
        description="Thanks for helping keep RateGuru useful."
    />

    <x-ui.button type="button" @click="reportOpen = false">
        Close
    </x-ui.button>
@else
    {{-- form --}}
@endif
```

Do not auto-close immediately.

### Acceptance criteria

- Success state visible after submit.
- Form hidden after success.
- Close button remains available.
- No false moderation promise.
- Test passes.

### Definition of Done

- Тест написан.
- Success state добавлен.
- Тест проходит.
- Коммит: `RG-402: Render report success state`

### Files likely touched

```txt
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-403 — Render Report Validation Errors

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-403-render-report-validation-errors`  
**Base branch:** develop
**Depends on:** RG-402

### Goal

Показывать validation/backend errors в modal.

### TDD step

Livewire tests:

```php
it('renders validation error when reason is missing', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(ReportModal::class, [
            'reportableType' => 'post',
            'reportableId' => $post->id,
        ])
        ->set('reason', '')
        ->call('submit')
        ->assertHasErrors(['reason'])
        ->assertSee('data-testid="report-reason-error"', false);
});
```

Duplicate report error:

```php
it('renders backend error for duplicate report', function () {
    ...
    ->call('submit')
    ->call('submit')
    ->assertSee('data-testid="report-submit-error"', false);
});
```

### Implementation

In view:

```blade
<div data-testid="report-reason-error">
    <x-ui.field-error :message="$errors->first('reason')" />
</div>

<div data-testid="report-message-error">
    <x-ui.field-error :message="$errors->first('message')" />
</div>

@if($errors->has('report'))
    <div data-testid="report-submit-error">
        <x-ui.error-message :message="$errors->first('report')" />
    </div>
@endif
```

If duplicate report creates `report` error, render it.

### Acceptance criteria

- Missing reason error visible.
- Too-long message error visible.
- Duplicate/backend error visible.
- Errors use RateGuru UI components.
- Test passes.

### Definition of Done

- Tests written.
- Error rendering added.
- Tests pass.
- Коммит: `RG-403: Render report validation errors`

### Files likely touched

```txt
resources/views/livewire/reports/report-modal.blade.php
tests/Feature/Livewire/ReportModalTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-404 — Add Report Button To PostCard Menu

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-404-add-report-button-to-post-card-menu`  
**Base branch:** develop
**Depends on:** RG-403

### Goal

Добавить report entry в PostCard menu.

### TDD step

Blade render test:

```php
it('renders report button in post card menu', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', [
        'post' => $post,
    ]);

    expect($html)->toContain('data-testid="post-card-report"');
    expect($html)->toContain('Report');
});
```

### Implementation

В `resources/views/components/feed/post-card.blade.php`:

```blade
@if($post->exists)
    <div data-testid="post-card-report">
        <livewire:reports.report-modal
            reportable-type="post"
            :reportable-id="$post->id"
            :key="'post-card-report-'.$post->id"
        />
    </div>
@endif
```

Если PostCard имеет dropdown/menu area, вставить туда.  
Если нет — создать compact menu area, но не раздувать UI.

Важно: UI Kit unsaved post не должен ломаться:

```blade
@if($post->exists)
    ...
@endif
```

### Acceptance criteria

- PostCard has Report button/menu entry.
- ReportModal target is post.
- Uses post id.
- Unsaved UI Kit post does not break.
- No report business logic in PostCard.
- Test passes.

### Definition of Done

- Test written.
- PostCard integration added.
- Test passes.
- Коммит: `RG-404: Add report button to PostCard menu`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-405 — Add Report Button To PostDrawer

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-405-add-report-button-to-post-drawer`  
**Base branch:** develop
**Depends on:** RG-404

### Goal

Добавить report button в PostDrawer.

### TDD step

Livewire test:

```php
it('renders report button in post drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-report"', false)
        ->assertSee('Report');
});
```

### Implementation

В `post-drawer.blade.php` рядом с title/metadata/actions:

```blade
<div data-testid="post-drawer-report">
    <livewire:reports.report-modal
        reportable-type="post"
        :reportable-id="$post->id"
        :key="'post-drawer-report-'.$post->id"
    />
</div>
```

Не добавлять moderation buttons.  
Не добавлять resolve/hide behavior.

### Acceptance criteria

- PostDrawer has Report button.
- ReportModal target is post.
- Uses selected post id.
- No report business logic in drawer.
- Test passes.

### Definition of Done

- Test written.
- Drawer integration added.
- Test passes.
- Коммит: `RG-405: Add report button to PostDrawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-406 — Add Report Button To CommentItem

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-406-add-report-button-to-comment-item`  
**Base branch:** develop
**Depends on:** RG-405

### Goal

Добавить report button в CommentItem.

### TDD step

Blade render test:

```php
it('renders report button in comment item for persisted comment', function () {
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('data-testid="comment-report"');
    expect($html)->toContain('Report');
});
```

UI Kit unsaved comment safety test:

```php
it('does not break comment item report button for unsaved comment preview', function () {
    $comment = Comment::factory()->make(['body' => 'Preview']);

    $html = Blade::render('<x-comments.comment-item :comment="$comment" />', [
        'comment' => $comment,
    ]);

    expect($html)->toContain('Preview');
});
```

### Implementation

В `comment-item.blade.php`:

```blade
@if($comment->exists)
    <div data-testid="comment-report">
        <livewire:reports.report-modal
            reportable-type="comment"
            :reportable-id="$comment->id"
            :key="'comment-report-'.$comment->id"
        />
    </div>
@endif
```

Если CommentItem already has action row with Delete/Hide, add Report next to them.

Do not show report button for hidden/deleted comments because they should not render anyway.

### Acceptance criteria

- CommentItem has Report button for persisted visible comments.
- ReportModal target is comment.
- Uses comment id.
- Unsaved UI Kit comment does not break.
- No report business logic in CommentItem.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- CommentItem integration added.
- Tests pass.
- Build passes.
- Коммит: `RG-406: Add report button to CommentItem`

### Files likely touched

```txt
resources/views/components/comments/comment-item.blade.php
tests/Feature/ViewComponents/CommentItemComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 20 Completion Criteria

Phase 20 завершена, когда:

```txt
- RG-395–RG-406 выполнены;
- ReportModal exists;
- ReportModal renders all ReportReason options;
- ReportModal has Alpine open/close behavior;
- reason selector works;
- optional message textarea works;
- ReportModal submits post report;
- ReportModal submits comment report;
- ReportModal uses ReportContentAction;
- success state renders after submit;
- validation errors render;
- duplicate/backend error renders;
- report button exists in PostCard menu;
- report button exists in PostDrawer;
- report button exists in CommentItem;
- unsupported reportable type is blocked;
- hidden/deleted comments cannot be reported;
- no moderation actions were added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 20

Без отдельной задачи нельзя:

```txt
- делать approve/reject/hide/restore moderation actions;
- делать moderation dashboard;
- делать Filament Reports Resource;
- делать resolve report UI;
- автоматически скрывать content after report;
- банить users after report;
- отправлять notifications to moderators;
- добавлять report rate limiting;
- добавлять IP/device fingerprinting;
- делать abuse scoring;
- добавлять report history UI;
- добавлять report button in PostShow, если нет отдельной задачи;
- делать API endpoint;
- добавлять Redis/cache layer;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-395 Create ReportModal Livewire component
RG-396 Test ReportModal renders reasons
RG-397 Add Alpine open/close behavior for report modal
RG-398 Render report reason selector
RG-399 Render report message textarea
RG-400 Test ReportModal submits post report
RG-401 Connect ReportModal to ReportContentAction
RG-402 Render report success state
RG-403 Render report validation errors
RG-404 Add report button to PostCard menu
RG-405 Add report button to PostDrawer
RG-406 Add report button to CommentItem
```
---

# 14. Release

После завершения Phase 20:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.1-phase20-reports-ui
git push -u origin release/v0.2.1-phase20-reports-ui
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.1-phase20-reports-ui -m "RateGuru Phase 20 reports UI"
git push origin v0.2.1-phase20-reports-ui
```
