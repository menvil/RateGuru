# RateGuru — Phase 22 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 22 — Inline Moderation UI**  
Диапазон задач: **RG-429 → RG-443**  
Основа нумерации: исходный atomic backlog, где Phase 22 начинается с задачи 429 и заканчивается задачей 443.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 22 соответствует исходному блоку:

```txt
Phase 22 — Inline Moderation UI
```

Правильный диапазон Phase 22:

```txt
RG-429 — Create InlinePostModeration Livewire component
RG-430 — Test InlinePostModeration hidden for normal user
RG-431 — Test InlinePostModeration visible for moderator
RG-432 — Render approve button for pending post
RG-433 — Render hide button for published post
RG-434 — Render reject button for pending post
RG-435 — Render restore button for hidden post
RG-436 — Add confirmation modal for hide action
RG-437 — Add reason input for moderation action
RG-438 — Connect approve button to ApprovePostAction
RG-439 — Connect hide button to HidePostAction
RG-440 — Connect reject button to RejectPostAction
RG-441 — Connect restore button to RestorePostAction
RG-442 — Refresh post card after moderation action
RG-443 — Add “open in admin” link for moderators
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 23 начинается с `RG-444` и делает **Filament Admin Foundation**. Поэтому Phase 22 не должна добавлять Filament, admin resources или dashboard.
---

# 2. Цель Phase 22

Phase 22 добавляет inline UI для модерации постов прямо в пользовательском интерфейсе.

После Phase 22 moderator/admin должен уметь:

```txt
- видеть inline moderation block на post card/drawer/detail surfaces;
- approve pending post;
- reject pending post;
- hide published post;
- restore hidden post;
- указывать reason для moderation action;
- подтверждать опасные действия через modal;
- видеть обновлённую карточку/состояние после moderation action;
- открыть post в будущей admin area через “open in admin” link.
```

Это UI-слой поверх backend actions из Phase 21:

```txt
ApprovePostAction
RejectPostAction
HidePostAction
RestorePostAction
```
---

# 3. Scope Phase 22

## Входит

```txt
- InlinePostModeration Livewire component;
- visibility rules for normal user vs moderator/admin;
- approve button for pending post;
- reject button for pending post;
- hide button for published post;
- restore button for hidden post;
- confirmation modal for hide action;
- reason input for moderation actions;
- wiring to ApprovePostAction;
- wiring to HidePostAction;
- wiring to RejectPostAction;
- wiring to RestorePostAction;
- refresh post card after moderation action;
- open in admin link placeholder for moderators.
```

## Не входит

```txt
- Filament installation/configuration;
- Filament PostResource;
- Filament table actions;
- moderation dashboard;
- bulk moderation;
- report resolution UI;
- user ban/shadowban UI;
- comment moderation UI beyond existing HideComment button from Phase 18;
- moderation queue;
- notifications;
- auto moderation;
- AI moderation.
```

Phase 23 — Filament Admin Foundation.  
Phase 24 — Filament Post Resource.  
Phase 25 — Filament User Resource.  
Phase 29 — Moderation Dashboard.
---

# 4. Product / UX Decisions

## 4.1. Inline moderation is only for moderators/admins

Normal users must not see moderation UI.

Rules:

```txt
guest        → hidden
normal user  → hidden
banned user  → hidden
moderator    → visible
admin        → visible
```

Backend actions still enforce authorization. UI hiding is not security.

## 4.2. Buttons depend on post status

Allowed UI buttons:

```txt
pending post:
- Approve
- Reject

published post:
- Hide

hidden post:
- Restore
```

Do not show invalid action buttons:

```txt
pending post should not show Hide or Restore
published post should not show Approve or Reject
hidden post should not show Approve or Reject
rejected post should not show Restore unless future workflow adds it
```

## 4.3. Dangerous actions need confirmation

At minimum, Hide requires confirmation because it removes visible content from public feed.

Recommended confirmation coverage:

```txt
Hide    → confirmation required
Reject  → confirmation recommended
Restore → confirmation optional
Approve → no confirmation needed
```

Backlog only explicitly says `confirmation modal for hide action`, so Phase 22 must implement hide confirmation. It may reuse same modal pattern for reject/restore, but should not delay the phase.

## 4.4. Reason input

Reason input should be shared across moderation actions.

MVP behavior:

```txt
- optional for approve;
- recommended/optional for hide;
- recommended/optional for reject;
- optional for restore.
```

Backend actions accept `?string $reason`, so UI can pass empty/null.

Do not block action because reason is empty unless backend later requires it.

## 4.5. Refresh behavior

After moderation action:

```txt
- component refreshes;
- parent post card can refresh/remove itself if status no longer matches current feed;
- event is dispatched:
  post-moderated
```

Example:

```txt
published feed + Hide action:
- post status becomes hidden;
- card should disappear from published feed or show updated hidden state for moderator.
```

For MVP, safer behavior:

```txt
- dispatch post-moderated with postId/status/action;
- FeedPage listens and refreshes list.
```

## 4.6. Open in admin link

Phase 22 adds link placeholder:

```txt
Open in admin
```

But Phase 23/24 Filament routes may not exist yet.

Correct implementation:

```txt
- render link only if admin route exists;
- otherwise render disabled/placeholder link or hide it with TODO.
```

Do not hardcode broken URL that causes 404 in production.

Recommended:

```php
Route::has('filament.admin.resources.posts.edit')
```

If route missing:

```txt
show “Admin link coming soon” for moderators, or do not render link yet.
```

Backlog requires “open in admin” link, so at least marker/placeholder must exist.
---

# 5. Architecture Rules

## 5.1. InlinePostModeration calls Phase 21 actions

Do not update post status directly:

```php
$post->update(['status' => PostStatus::Hidden])
```

Correct:

```php
app(HidePostAction::class)->handle($moderator, $post, $reason);
```

## 5.2. Component resolves post safely

`InlinePostModeration` accepts:

```txt
postId
```

and loads post fresh before each action.

Do not trust a stale post object passed from parent for moderation operations.

## 5.3. Component must not duplicate authorization logic completely

UI can have helper:

```php
canModeratePosts()
```

But backend actions remain source of truth.

The component should use simple visibility:

```php
$user && ($user->isModerator() || $user->isAdmin())
```

Action will still enforce exact rules.

## 5.4. Component must handle backend exceptions

If action throws `CannotModeratePostException`, UI should show error instead of crashing.

```txt
- show error message;
- do not update success state;
- do not dispatch post-moderated.
```

## 5.5. No Filament dependency

Do not import Filament classes in Phase 22.  
The “open in admin” link must be conditional or placeholder.

## 5.6. No user moderation in InlinePostModeration

This component moderates posts only.  
It must not ban/shadowban users.
---

# 6. Integration Targets

`InlinePostModeration` should be available where moderators inspect posts.

Minimum integration targets:

```txt
PostCard
PostDrawer
PostShow
```

Backlog only says refresh post card and open in admin link, but if moderation component is not mounted anywhere, it is useless.

Recommended:

```blade
<livewire:moderation.inline-post-moderation :post-id="$post->id" />
```

Use stable keys:

```blade
:key="'inline-post-moderation-'.$post->id"
```

Do not show it in UI kit unless useful for visual inspection.
---

# 7. Design Constraints

Inline moderation UI should be visually present but not dominate normal product UI.

Recommended design:

```txt
- compact dark card or pill group;
- small “Moderator” label/badge;
- buttons:
  Approve = positive/primary
  Reject = destructive/secondary
  Hide = destructive/secondary
  Restore = neutral/primary
- reason input compact textarea;
- confirmation modal dark surface;
- error/success messages small and clear.
```

Before final review, check:

```txt
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-18-comments-ui-review.md
docs/design/phase-20-reports-ui-review.md
```

If prior review docs are missing, record it in Phase 22 notes, but do not block implementation.
---

# 8. GitFlow для Phase 22

## Base branch

Все задачи Phase 22 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-429-create-inline-post-moderation-livewire-component
feature/RG-438-connect-approve-button-to-approve-post-action
feature/RG-443-add-open-in-admin-link-for-moderators
```

## Commit format

```txt
RG-429: Create InlinePostModeration Livewire component
RG-438: Connect approve button to ApprovePostAction
RG-443: Add open in admin link for moderators
```

## Release branch

После выполнения `RG-429`–`RG-443`:

```txt
release/v0.2.3-phase22-inline-moderation-ui
```

## Tag

После merge release branch в `main`:

```txt
v0.2.3-phase22-inline-moderation-ui
```
---

# 9. TDD Rules for Phase 22

## Для InlinePostModeration

Писать Livewire tests:

```txt
- component renders;
- hidden for normal user;
- visible for moderator;
- visible for admin;
- correct buttons by post status;
- action methods call backend actions;
- errors render when backend blocks action;
- post-moderated event dispatched on success.
```

## Для Alpine confirmation modal

Писать markup tests:

```txt
- x-data exists;
- x-show exists;
- hide confirmation state exists;
- close/cancel button exists.
```

Actual browser open/close проверяется вручную.

## Для parent refresh

Писать Livewire tests:

```txt
- FeedPage listens to post-moderated;
- post card disappears or status refreshes after moderation.
```

Если parent refresh слишком сложен, test at least event dispatch from component.

## Для admin link

Писать markup/route tests:

```txt
- moderator sees open in admin marker;
- normal user does not;
- link does not break if Filament route missing.
```
---

# 10. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Moderation / Tests
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

# 11. Phase 22 Atomic Tasks
---

## RG-429 — Create InlinePostModeration Livewire Component

**Area:** Livewire / Moderation UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-429-create-inline-post-moderation-livewire-component`  
**Base branch:** develop
**Depends on:** RG-428

### Goal

Создать Livewire component `InlinePostModeration`.

### TDD step

Livewire test:

```php
it('can render inline post moderation component', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertStatus(200);
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```bash
php artisan make:livewire Moderation/InlinePostModeration
```

Файлы:

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
```

Class skeleton:

```php
final class InlinePostModeration extends Component
{
    public int $postId;
    public ?string $reason = null;
    public ?string $error = null;
    public ?string $success = null;

    public function render(): View
    {
        return view('livewire.moderation.inline-post-moderation', [
            'post' => Post::query()->findOrFail($this->postId),
        ]);
    }
}
```

View skeleton:

```blade
<div data-testid="inline-post-moderation">
    Inline moderation
</div>
```

Не добавлять buttons пока.

### Acceptance criteria

- `InlinePostModeration` exists.
- Accepts `postId`.
- Has `reason`, `error`, `success` properties.
- Loads post by id.
- View has `data-testid="inline-post-moderation"`.
- Test passes.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-429: Create InlinePostModeration Livewire component`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-430 — Test InlinePostModeration Hidden For Normal User

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-430-test-inline-post-moderation-hidden-for-normal-user`  
**Base branch:** develop
**Depends on:** RG-429

### Goal

Написать тест: normal user не видит inline moderation UI.

### TDD step

Livewire test:

```php
it('hides inline post moderation for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="inline-post-moderation-panel"', false)
        ->assertDontSee('Approve')
        ->assertDontSee('Reject')
        ->assertDontSee('Hide')
        ->assertDontSee('Restore');
});
```

Guest test желательно:

```php
it('hides inline post moderation for guest', ...)
```

Тест должен упасть, если skeleton сейчас показывает всем.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Normal user cannot see moderation panel.
- Guest cannot see moderation panel, если test добавлен.
- No moderation buttons visible.
- Test fails before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-430: Test InlinePostModeration hidden for normal user`

### Files likely touched

```txt
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-431 — Test InlinePostModeration Visible For Moderator

**Area:** Livewire / Tests  
**Type:** Test / Feature  
**Priority:** P0  
**Branch:** `feature/RG-431-test-inline-post-moderation-visible-for-moderator`  
**Base branch:** develop
**Depends on:** RG-430

### Goal

Сделать moderation panel видимой для moderator/admin и скрытой для обычных users.

### TDD step

Livewire test:

```php
it('shows inline post moderation for moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="inline-post-moderation-panel"', false)
        ->assertSee('Moderator');
});
```

Admin test:

```php
it('shows inline post moderation for admin', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($admin)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="inline-post-moderation-panel"', false);
});
```

### Implementation

В component:

```php
public function getCanModerateProperty(): bool
{
    $user = auth()->user();

    return $user !== null && ($user->isModerator() || $user->isAdmin());
}
```

В view:

```blade
@if($this->canModerate)
    <div data-testid="inline-post-moderation-panel">
        <x-ui.badge>Moderator</x-ui.badge>
    </div>
@endif
```

Normal user tests из RG-430 должны теперь проходить.

### Acceptance criteria

- Moderator sees panel.
- Admin sees panel.
- Normal user does not.
- Guest does not.
- Panel has moderator label/badge.
- Tests pass.

### Definition of Done

- Visibility logic implemented.
- Tests pass.
- Коммит: `RG-431: Test InlinePostModeration visible for moderator`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-432 — Render Approve Button For Pending Post

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-432-render-approve-button-for-pending-post`  
**Base branch:** develop
**Depends on:** RG-431

### Goal

Показывать Approve button для pending post.

### TDD step

Livewire tests:

```php
it('renders approve button for pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('Approve')
        ->assertSee('wire:click="approve"', false);
});
```

Negative test:

```php
it('does not render approve button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('Approve');
});
```

### Implementation

In view:

```blade
@if($post->status === \App\Enums\PostStatus::Pending)
    <x-ui.button
        type="button"
        wire:click="approve"
        data-testid="moderation-approve"
    >
        Approve
    </x-ui.button>
@endif
```

Add placeholder method so Livewire does not break if clicked before RG-438:

```php
public function approve(): void
{
    // implemented in RG-438
}
```

Or do not click in tests until RG-438.

### Acceptance criteria

- Approve visible for pending post.
- Approve hidden for non-pending post.
- Only moderator/admin can see it.
- Button has stable marker.
- Test passes.

### Definition of Done

- Tests written.
- Approve button rendered.
- Tests pass.
- Коммит: `RG-432: Render approve button for pending post`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
app/Livewire/Moderation/InlinePostModeration.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-433 — Render Hide Button For Published Post

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-433-render-hide-button-for-published-post`  
**Base branch:** develop
**Depends on:** RG-432

### Goal

Показывать Hide button для published post.

### TDD step

Livewire tests:

```php
it('renders hide button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('Hide')
        ->assertSee('data-testid="moderation-hide"', false);
});
```

Negative test:

```php
it('does not render hide button for pending post', ...)
```

### Implementation

In view:

```blade
@if($post->status === \App\Enums\PostStatus::Published)
    <x-ui.button
        type="button"
        data-testid="moderation-hide"
        @click="confirmHideOpen = true"
    >
        Hide
    </x-ui.button>
@endif
```

If Alpine state not added yet, use wire click placeholder for now and refine in RG-436.

### Acceptance criteria

- Hide visible for published post.
- Hide hidden for non-published post.
- Only moderator/admin can see it.
- Button has stable marker.
- Test passes.

### Definition of Done

- Tests written.
- Hide button rendered.
- Tests pass.
- Коммит: `RG-433: Render hide button for published post`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-434 — Render Reject Button For Pending Post

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-434-render-reject-button-for-pending-post`  
**Base branch:** develop
**Depends on:** RG-433

### Goal

Показывать Reject button для pending post.

### TDD step

Livewire tests:

```php
it('renders reject button for pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('Reject')
        ->assertSee('data-testid="moderation-reject"', false);
});
```

Negative test:

```php
it('does not render reject button for published post', ...)
```

### Implementation

In view:

```blade
@if($post->status === \App\Enums\PostStatus::Pending)
    <x-ui.button
        type="button"
        wire:click="reject"
        data-testid="moderation-reject"
        variant="danger"
    >
        Reject
    </x-ui.button>
@endif
```

Action wiring comes in RG-440.

### Acceptance criteria

- Reject visible for pending post.
- Reject hidden for non-pending post.
- Only moderator/admin can see it.
- Button has stable marker.
- Test passes.

### Definition of Done

- Tests written.
- Reject button rendered.
- Tests pass.
- Коммит: `RG-434: Render reject button for pending post`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-435 — Render Restore Button For Hidden Post

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-435-render-restore-button-for-hidden-post`  
**Base branch:** develop
**Depends on:** RG-434

### Goal

Показывать Restore button для hidden post.

### TDD step

Livewire tests:

```php
it('renders restore button for hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('Restore')
        ->assertSee('data-testid="moderation-restore"', false);
});
```

Negative test:

```php
it('does not render restore button for published post', ...)
```

### Implementation

In view:

```blade
@if($post->status === \App\Enums\PostStatus::Hidden)
    <x-ui.button
        type="button"
        wire:click="restore"
        data-testid="moderation-restore"
    >
        Restore
    </x-ui.button>
@endif
```

Action wiring comes in RG-441.

### Acceptance criteria

- Restore visible for hidden post.
- Restore hidden for non-hidden post.
- Only moderator/admin can see it.
- Button has stable marker.
- Test passes.

### Definition of Done

- Tests written.
- Restore button rendered.
- Tests pass.
- Коммит: `RG-435: Render restore button for hidden post`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-436 — Add Confirmation Modal For Hide Action

**Area:** UI / Alpine / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-436-add-confirmation-modal-for-hide-action`  
**Base branch:** develop
**Depends on:** RG-435

### Goal

Добавить confirmation modal для hide action.

### TDD step

Markup test:

```php
it('renders hide confirmation modal markup', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="hide-confirmation-modal"', false)
        ->assertSee('confirmHideOpen', false)
        ->assertSee('Hide this post?', false);
});
```

### Implementation

Wrap panel with Alpine:

```blade
<div x-data="{ confirmHideOpen: false }">
```

Hide button:

```blade
<button type="button" @click="confirmHideOpen = true" data-testid="moderation-hide">
    Hide
</button>
```

Modal:

```blade
<div
    x-show="confirmHideOpen"
    x-cloak
    data-testid="hide-confirmation-modal"
>
    <x-ui.modal title="Hide this post?">
        <p>This will remove the post from public feeds.</p>

        <x-ui.button type="button" @click="confirmHideOpen = false">
            Cancel
        </x-ui.button>

        <x-ui.button
            type="button"
            variant="danger"
            wire:click="hide"
            @click="confirmHideOpen = false"
        >
            Confirm hide
        </x-ui.button>
    </x-ui.modal>
</div>
```

Actual `hide()` method connects in RG-439.

### Acceptance criteria

- Hide button opens confirmation modal.
- Confirmation modal exists.
- Cancel button closes modal.
- Confirm button calls `hide`.
- `x-cloak` used.
- Markup test passes.
- Manual check done.

### Definition of Done

- Confirmation modal added.
- Test passes.
- Manual check done.
- Коммит: `RG-436: Add confirmation modal for hide action`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-437 — Add Reason Input For Moderation Action

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-437-add-reason-input-for-moderation-action`  
**Base branch:** develop
**Depends on:** RG-436

### Goal

Добавить reason input, который передаётся в moderation actions.

### TDD step

Livewire test:

```php
it('renders moderation reason input and updates reason', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('name="moderation_reason"', false)
        ->set('reason', 'Image violates rules.')
        ->assertSet('reason', 'Image violates rules.');
});
```

### Implementation

In view:

```blade
<x-ui.label for="moderation-reason">Reason</x-ui.label>

<x-ui.textarea
    id="moderation-reason"
    name="moderation_reason"
    wire:model.defer="reason"
    rows="2"
    maxlength="1000"
    placeholder="Optional moderation note..."
/>
```

Reason should be visible only inside moderation panel for moderator/admin.

If UI gets too noisy, put reason input in compact disclosure area:

```txt
Reason / note
```

But tests should still find the field.

### Acceptance criteria

- Reason input visible for moderator/admin.
- Reason input hidden for normal user.
- Bound to `reason`.
- `maxlength="1000"` present.
- Test passes.

### Definition of Done

- Test written.
- Reason input added.
- Test passes.
- Коммит: `RG-437: Add reason input for moderation action`

### Files likely touched

```txt
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-438 — Connect Approve Button To ApprovePostAction

**Area:** Livewire / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-438-connect-approve-button-to-approve-post-action`  
**Base branch:** develop
**Depends on:** RG-437

### Goal

Подключить Approve button к `ApprovePostAction`.

### TDD step

Livewire test:

```php
it('approves pending post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Valid post.')
        ->call('approve')
        ->assertDispatched('post-moderated');

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'moderatable_type' => Post::class,
        'moderatable_id' => $post->id,
    ]);
});
```

Forbidden user test:

```php
normal user calling approve should not change post and should show/throw error
```

### Implementation

In component:

```php
public function approve(ApprovePostAction $approvePostAction): void
{
    $this->runModerationAction(function () use ($approvePostAction) {
        $approvePostAction->handle(
            moderator: auth()->user(),
            post: $this->post(),
            reason: $this->normalizedReason(),
        );

        $this->success = 'Post approved.';
        $this->dispatch('post-moderated', postId: $this->postId, action: 'approved');
    });
}
```

Helpers:

```php
private function post(): Post
{
    return Post::query()->findOrFail($this->postId);
}

private function normalizedReason(): ?string
{
    $reason = trim((string) $this->reason);

    return $reason === '' ? null : $reason;
}
```

Error handling:

```php
catch (CannotModeratePostException $e) {
    $this->error = $e->getMessage();
}
```

### Acceptance criteria

- Approve action called.
- Pending post becomes published.
- Reason passed.
- `post-moderated` event dispatched.
- Success message shown.
- Backend exceptions render as error.
- Test passes.

### Definition of Done

- Tests written.
- Approve method wired.
- Tests pass.
- Коммит: `RG-438: Connect approve button to ApprovePostAction`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-439 — Connect Hide Button To HidePostAction

**Area:** Livewire / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-439-connect-hide-button-to-hide-post-action`  
**Base branch:** develop
**Depends on:** RG-438

### Goal

Подключить Hide confirmation к `HidePostAction`.

### TDD step

Livewire test:

```php
it('hides published post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Reported content.')
        ->call('hide')
        ->assertDispatched('post-moderated');

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});
```

### Implementation

In component:

```php
public function hide(HidePostAction $hidePostAction): void
{
    $this->runModerationAction(function () use ($hidePostAction) {
        $hidePostAction->handle(
            moderator: auth()->user(),
            post: $this->post(),
            reason: $this->normalizedReason(),
        );

        $this->success = 'Post hidden.';
        $this->dispatch('post-moderated', postId: $this->postId, action: 'hidden');
    });
}
```

Confirm button from RG-436 should call `hide`.

### Acceptance criteria

- Hide action called.
- Published post becomes hidden.
- Reason passed.
- `post-moderated` event dispatched.
- Success message shown.
- Backend exceptions render as error.
- Test passes.

### Definition of Done

- Tests written.
- Hide method wired.
- Tests pass.
- Коммит: `RG-439: Connect hide button to HidePostAction`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-440 — Connect Reject Button To RejectPostAction

**Area:** Livewire / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-440-connect-reject-button-to-reject-post-action`  
**Base branch:** develop
**Depends on:** RG-439

### Goal

Подключить Reject button к `RejectPostAction`.

### TDD step

Livewire test:

```php
it('rejects pending post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Invalid image.')
        ->call('reject')
        ->assertDispatched('post-moderated');

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);
});
```

### Implementation

In component:

```php
public function reject(RejectPostAction $rejectPostAction): void
{
    $this->runModerationAction(function () use ($rejectPostAction) {
        $rejectPostAction->handle(
            moderator: auth()->user(),
            post: $this->post(),
            reason: $this->normalizedReason(),
        );

        $this->success = 'Post rejected.';
        $this->dispatch('post-moderated', postId: $this->postId, action: 'rejected');
    });
}
```

### Acceptance criteria

- Reject action called.
- Pending post becomes rejected.
- Reason passed.
- `post-moderated` event dispatched.
- Success message shown.
- Backend exceptions render as error.
- Test passes.

### Definition of Done

- Tests written.
- Reject method wired.
- Tests pass.
- Коммит: `RG-440: Connect reject button to RejectPostAction`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-441 — Connect Restore Button To RestorePostAction

**Area:** Livewire / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-441-connect-restore-button-to-restore-post-action`  
**Base branch:** develop
**Depends on:** RG-440

### Goal

Подключить Restore button к `RestorePostAction`.

### TDD step

Livewire test:

```php
it('restores hidden post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Restored after review.')
        ->call('restore')
        ->assertDispatched('post-moderated');

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
```

### Implementation

In component:

```php
public function restore(RestorePostAction $restorePostAction): void
{
    $this->runModerationAction(function () use ($restorePostAction) {
        $restorePostAction->handle(
            moderator: auth()->user(),
            post: $this->post(),
            reason: $this->normalizedReason(),
        );

        $this->success = 'Post restored.';
        $this->dispatch('post-moderated', postId: $this->postId, action: 'restored');
    });
}
```

### Acceptance criteria

- Restore action called.
- Hidden post becomes published.
- Reason passed.
- `post-moderated` event dispatched.
- Success message shown.
- Backend exceptions render as error.
- Test passes.

### Definition of Done

- Tests written.
- Restore method wired.
- Tests pass.
- Коммит: `RG-441: Connect restore button to RestorePostAction`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-442 — Refresh Post Card After Moderation Action

**Area:** Livewire / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-442-refresh-post-card-after-moderation-action`  
**Base branch:** develop
**Depends on:** RG-441

### Goal

Обновлять post card/feed после moderation action.

### TDD step

Component event test:

```php
it('dispatches post moderated event with post id and action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->call('hide')
        ->assertDispatched('post-moderated', postId: $post->id, action: 'hidden');
});
```

FeedPage listener test if FeedPage exists:

```php
it('refreshes feed after post moderated event', function () {
    $post = Post::factory()->published()->create(['title' => 'Visible post']);

    Livewire::test(FeedPage::class)
        ->assertSee('Visible post')
        ->dispatch('post-moderated', postId: $post->id, action: 'hidden')
        ->assertDontSee('Visible post');
});
```

This depends on FeedPage implementation. If brittle, document and test event dispatch only.

### Implementation

In parent feed component:

```php
#[On('post-moderated')]
public function refreshAfterPostModerated(int $postId, string $action): void
{
    // no-op forces re-render if query is in render()
}
```

If FeedPage caches posts, clear/reset cached collection.

Integrate `InlinePostModeration` into `PostCard`:

```blade
@if($post->exists)
    <livewire:moderation.inline-post-moderation
        :post-id="$post->id"
        :key="'inline-moderation-card-'.$post->id"
    />
@endif
```

If already integrated earlier, verify it refreshes.

For drawer/show surfaces, event can refresh or simply show updated status on next render.

### Acceptance criteria

- `post-moderated` event includes `postId` and `action`.
- Feed/PostCard refreshes after moderation action.
- Hidden post disappears from published feed or changes status for moderators.
- InlinePostModeration is mounted in PostCard.
- No direct status update in UI.
- Tests pass.

### Definition of Done

- Event payload standardized.
- Feed/Card refresh wiring added.
- Tests pass.
- Коммит: `RG-442: Refresh post card after moderation action`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
app/Livewire/Feed/FeedPage.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Livewire/InlinePostModerationTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-443 — Add “Open In Admin” Link For Moderators

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-443-add-open-in-admin-link-for-moderators`  
**Base branch:** develop
**Depends on:** RG-442

### Goal

Добавить “Open in admin” link для moderators/admins.

### TDD step

Livewire test:

```php
it('renders open in admin link placeholder for moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('Open in admin')
        ->assertSee('data-testid="open-in-admin-link"', false);
});
```

Normal user hidden test:

```php
it('does not render open in admin link for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('Open in admin');
});
```

### Implementation

In component:

```php
public function getAdminPostUrlProperty(): ?string
{
    if (Route::has('filament.admin.resources.posts.edit')) {
        return route('filament.admin.resources.posts.edit', ['record' => $this->postId]);
    }

    return null;
}
```

In view:

```blade
<div data-testid="open-in-admin-link">
    @if($this->adminPostUrl)
        <a href="{{ $this->adminPostUrl }}" target="_blank" rel="noopener">
            Open in admin
        </a>
    @else
        <span class="text-rg-muted">
            Open in admin
        </span>
    @endif
</div>
```

This satisfies backlog without hardcoding broken route before Phase 23/24.

Add final Phase 22 review doc:

```txt
docs/design/phase-22-inline-moderation-ui-review.md
```

At minimum record:

```txt
- moderator visibility checked;
- normal user hidden checked;
- pending/published/hidden button states checked;
- confirmation modal checked;
- reason input checked;
- card refresh checked.
```

### Acceptance criteria

- Moderator/admin sees “Open in admin”.
- Normal user/guest does not.
- Link is real if admin route exists.
- No broken hardcoded Filament URL if route missing.
- Placeholder is acceptable until Phase 23/24.
- Final tests pass.
- `composer test` passes.
- `npm run build` passes.
- Design review note exists.

### Definition of Done

- Tests written.
- Admin link/placeholder added.
- Review note added.
- Tests/build pass.
- Коммит: `RG-443: Add open in admin link for moderators`

### Files likely touched

```txt
app/Livewire/Moderation/InlinePostModeration.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
docs/design/phase-22-inline-moderation-ui-review.md
tests/Feature/Livewire/InlinePostModerationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 12. Phase 22 Completion Criteria

Phase 22 завершена, когда:

```txt
- RG-429–RG-443 выполнены;
- InlinePostModeration exists;
- normal user/guest do not see moderation UI;
- moderator/admin see moderation UI;
- pending post shows Approve and Reject;
- published post shows Hide;
- hidden post shows Restore;
- invalid buttons are not shown for wrong statuses;
- hide action has confirmation modal;
- reason input exists and passes reason to actions;
- approve button calls ApprovePostAction;
- hide button calls HidePostAction;
- reject button calls RejectPostAction;
- restore button calls RestorePostAction;
- backend exceptions render as UI error;
- successful moderation dispatches post-moderated event;
- post card/feed refreshes after moderation action;
- open in admin link/placeholder exists for moderators;
- no Filament implementation was added;
- composer test passes;
- npm run build passes.
```
---

# 13. Что нельзя делать в Phase 22

Без отдельной задачи нельзя:

```txt
- устанавливать/настраивать Filament;
- создавать Filament resources;
- создавать moderation dashboard;
- добавлять bulk moderation;
- добавлять user ban/shadowban UI;
- добавлять report resolution UI;
- добавлять moderation queue;
- добавлять notification dispatch;
- добавлять AI moderation;
- добавлять rate limiting;
- менять backend moderation rules из Phase 21 без отдельной задачи;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 14. Recommended Execution Order

```txt
RG-429 Create InlinePostModeration Livewire component
RG-430 Test InlinePostModeration hidden for normal user
RG-431 Test InlinePostModeration visible for moderator
RG-432 Render approve button for pending post
RG-433 Render hide button for published post
RG-434 Render reject button for pending post
RG-435 Render restore button for hidden post
RG-436 Add confirmation modal for hide action
RG-437 Add reason input for moderation action
RG-438 Connect approve button to ApprovePostAction
RG-439 Connect hide button to HidePostAction
RG-440 Connect reject button to RejectPostAction
RG-441 Connect restore button to RestorePostAction
RG-442 Refresh post card after moderation action
RG-443 Add “open in admin” link for moderators
```
---

# 15. Release

После завершения Phase 22:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.3-phase22-inline-moderation-ui
git push -u origin release/v0.2.3-phase22-inline-moderation-ui
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.3-phase22-inline-moderation-ui -m "RateGuru Phase 22 inline moderation UI"
git push origin v0.2.3-phase22-inline-moderation-ui
```
