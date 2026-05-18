# RateGuru — Phase 25 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 25 — Filament User Resource**  
Диапазон задач: **RG-469 → RG-482**  
Основа нумерации: исходный atomic backlog, где Phase 25 начинается с задачи 469 и заканчивается задачей 482.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 25 соответствует исходному блоку:

```txt
Phase 25 — Filament User Resource
```

Правильный диапазон Phase 25:

```txt
RG-469 — Create UserResource
RG-470 — Add username column
RG-471 — Add email column
RG-472 — Add role column
RG-473 — Add status column
RG-474 — Add posts_count column
RG-475 — Add reports_count column placeholder
RG-476 — Add active filter
RG-477 — Add banned filter
RG-478 — Add trusted filter
RG-479 — Add ban user action
RG-480 — Add unban user action
RG-481 — Add mark trusted action
RG-482 — Add shadowban action
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 26 начинается с `RG-483` и делает **Filament Comments Resource**. Поэтому Phase 25 не должна создавать CommentResource, ReportResource, TagsResource или Moderation Dashboard.
---

# 2. Цель Phase 25

Phase 25 добавляет Filament-ресурс для управления пользователями в `/admin`.

После Phase 25 admin/moderator должен уметь в Filament:

```txt
- открыть список users;
- видеть username/email/role/status/posts_count/reports_count placeholder;
- фильтровать users по active/banned/trusted;
- admin может ban user;
- admin может unban user;
- admin может mark trusted user;
- admin может shadowban user.
```

При этом действия с пользователями должны быть безопасными:

```txt
- moderator не может банить/разбанивать/shadowban/mark trusted;
- admin не может случайно менять другого admin без явного правила;
- user actions должны писать moderation log;
- Filament не должен напрямую менять user status без backend action.
```
---

# 3. Scope Phase 25

## Входит

```txt
- UserResource;
- ListUsers page;
- username/email/role/status/posts_count/reports_count placeholder columns;
- active/banned/trusted filters;
- ban user table action;
- unban user table action;
- mark trusted table action;
- shadowban table action;
- минимальные backend actions для unban/trusted, если их ещё нет;
- tests/smoke tests для resource access, filters и critical actions.
```

## Не входит

```txt
- User edit form;
- password reset from admin;
- impersonation;
- user profile preview;
- user reports history page;
- user posts relation manager;
- user comments relation manager;
- bulk ban/shadowban;
- UserResource create form;
- CommentResource;
- ReportResource;
- moderation dashboard;
- notification emails.
```

UserResource в Phase 25 — это table-first moderation/admin resource, не полноценная CRM.
---

# 4. Critical Decisions

## 4.1. UserResource is admin/moderation resource, not profile editor

Не делать полноценное редактирование пользователей.

Правильный MVP:

```txt
- ListUsers page;
- table columns;
- filters;
- status/role actions.
```

Неправильно:

```txt
- edit profile form;
- change email form;
- change password;
- avatar upload;
- personal data editor.
```

Это отдельные high-risk tasks.

## 4.2. Filament actions must call backend actions

Для действий, которые меняют статус пользователя, нельзя делать:

```php
$user->update(['status' => UserStatus::Banned]);
```

Правильно:

```php
app(BanUserAction::class)->handle(auth()->user(), $record, $reason);
```

Это сохраняет:

```txt
- authorization;
- protected target guards;
- moderation logs;
- consistency with future moderation UI.
```

## 4.3. Existing backend actions

Phase 21 уже должна была создать:

```txt
BanUserAction
ShadowbanUserAction
CreateModerationLogAction
```

Но Phase 21 не создавала:

```txt
UnbanUserAction
MarkTrustedUserAction
```

Поэтому Phase 25 должна либо:

```txt
- создать эти minimal backend actions внутри RG-480/RG-481;
```

либо:

```txt
- явно отложить unban/trusted до backend phase.
```

Отложить нельзя, потому что backlog Phase 25 прямо требует table actions.  
Значит, RG-480 и RG-481 должны включать minimal backend action + Filament action.

## 4.4. reports_count placeholder

Backlog говорит:

```txt
RG-475 — Add reports_count column placeholder
```

Это не значит, что нужно изобретать полноценную user reports aggregation.

Правильная трактовка:

```txt
- показать placeholder column;
- если user_reports_count уже есть — использовать его;
- если нет — показывать 0 / "—";
- добавить TODO/note, что полноценная агрегация будет позже.
```

Нельзя добавлять новую большую систему user reports в Phase 25.

## 4.5. trusted filter / mark trusted

Нужно решить, что такое `trusted`.

Возможные модели:

```txt
Option A: UserStatus::Trusted
Option B: users.is_trusted boolean
Option C: UserRole::Trusted
```

Рекомендация:

```txt
Option A: UserStatus::Trusted
```

Почему:

```txt
- backlog ставит trusted рядом с active/banned filters;
- active/banned — это status, не role;
- trusted здесь логичнее как пользовательский статус доверия;
- role лучше оставить для admin/moderator/user.
```

Если в проекте уже есть `is_trusted`, использовать существующую схему и не создавать новый status.

## 4.6. unban behavior

Unban возвращает пользователя:

```txt
banned/shadowbanned/trusted? → active
```

Лучше ограничить:

```txt
banned → active
shadowbanned → active
```

Trusted user не должен попадать под unban.

Для Phase 25:

```txt
UnbanUserAction применяется к banned/shadowbanned users.
```

Если нужен “remove trusted”, это отдельная future action.

## 4.7. protected users

User moderation actions не должны позволять:

```txt
- admin ban self;
- admin shadowban self;
- admin unban self не нужен;
- admin ban another admin;
- admin shadowban another admin;
- moderator запускать user moderation actions.
```

Mark trusted для admin/moderator не нужен. Trusted — для normal users.

## 4.8. Moderation logs обязательны

User status actions должны писать moderation logs:

```txt
ban_user
unban_user
mark_trusted_user
shadowban_user
```

Phase 21 уже добавила logs для ban/shadowban.  
Phase 25 должна добавить logs для unban/trusted.

Если `ModerationActionType` не содержит values:

```txt
UnbanUser
MarkTrustedUser
```

добавить их в RG-480/RG-481.
---

# 5. Architecture Rules

## 5.1. Resource location

Adapt to installed Filament version.

Likely paths:

```txt
app/Filament/Resources/UserResource.php
app/Filament/Resources/UserResource/Pages/ListUsers.php
```

Use the conventions already established by Phase 24 `PostResource`.

## 5.2. No direct status mutation in Resource

Wrong:

```php
Action::make('ban')->action(fn (User $record) => $record->update([...]))
```

Correct:

```php
Action::make('ban')->action(fn (User $record, array $data) =>
    app(BanUserAction::class)->handle(auth()->user(), $record, $data['reason'] ?? null)
)
```

## 5.3. UserResource query should expose counts safely

For posts_count:

```php
User::query()->withCount('posts')
```

If `posts_count` exists as denormalized column, prefer explicit column only if already maintained.  
Better:

```txt
use withCount for table display
```

because it avoids stale counts.

For reports_count placeholder:

```txt
do not create expensive aggregate by default.
```

## 5.4. Actions visible only where valid

Visibility:

```txt
ban:
- admin only
- target is not admin
- target is not self
- target status is active/trusted/shadowbanned depending policy

unban:
- admin only
- target status is banned or shadowbanned

mark trusted:
- admin only
- target role is normal user
- target status is active

shadowban:
- admin only
- target is not admin
- target is not self
- target status is active/trusted
```

Even if visibility hides actions, backend actions still enforce guards.

## 5.5. No bulk user sanctions

Phase 25 has no bulk actions.  
Bulk ban/shadowban is too dangerous and not in backlog.
---

# 6. GitFlow для Phase 25

## Base branch

Все задачи Phase 25 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-469-create-user-resource
feature/RG-479-add-ban-user-action
feature/RG-482-add-shadowban-action
```

## Commit format

```txt
RG-469: Create UserResource
RG-479: Add ban user action
RG-482: Add shadowban action
```

## Release branch

После выполнения `RG-469`–`RG-482`:

```txt
release/v0.2.6-phase25-filament-user-resource
```

## Tag

После merge release branch в `main`:

```txt
v0.2.6-phase25-filament-user-resource
```
---

# 7. TDD Rules for Phase 25

## Для Resource

Тестировать:

```txt
- admin can access UserResource index;
- moderator can access UserResource index;
- normal user cannot access admin remains covered by Phase 23;
- resource uses User model.
```

## Для columns

Тестировать:

```txt
- username visible;
- email visible;
- role visible;
- status visible;
- posts_count visible;
- reports_count placeholder visible.
```

## Для filters

Тестировать результат:

```txt
- active filter shows active users only;
- banned filter shows banned users only;
- trusted filter shows trusted users only.
```

## Для actions

Тестировать:

```txt
- admin can ban user;
- moderator cannot see/call ban action;
- admin can unban banned user;
- admin can mark active user as trusted;
- admin can shadowban active user;
- protected target guards hold;
- moderation logs created.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Users / Tests
Type: Test / Feature / Resource / Table / Filter / Action
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
- Нет direct status mutation в Filament resource
- Backend action используется для user status actions
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 25 Atomic Tasks
---

## RG-469 — Create UserResource

**Area:** Admin / Filament  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-469-create-user-resource`  
**Base branch:** develop
**Depends on:** RG-468

### Goal

Создать Filament `UserResource` для модели `User`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access user resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk();
});
```

Moderator access:

```php
it('allows moderator to access user resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(UserResource::getUrl('index'))
        ->assertOk();
});
```

Тест должен упасть до создания resource.

### Implementation

Создать resource:

```bash
php artisan make:filament-resource User
```

Минимальный resource:

```php
protected static ?string $model = User::class;
```

Pages:

```txt
ListUsers
```

Disable create/edit pages unless generated by default and explicitly needed.

Set navigation group:

```txt
Users
```

### Acceptance criteria

- `UserResource` exists.
- Resource uses `User::class`.
- Admin can access index.
- Moderator can access index.
- Resource appears under Users navigation group.
- No create/edit form required in this phase.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Resource создан.
- Tests pass.
- Коммит: `RG-469: Create UserResource`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
app/Filament/Resources/UserResource/Pages/ListUsers.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-470 — Add Username Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-470-add-username-column`  
**Base branch:** develop
**Depends on:** RG-469

### Goal

Добавить username column в UserResource table.

### TDD step

Feature test:

```php
it('renders username in user resource table', function () {
    $admin = User::factory()->admin()->create();

    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('chef_ivan');
});
```

### Implementation

Add column:

```php
TextColumn::make('username')
    ->label('Username')
    ->searchable()
    ->sortable()
```

If username nullable:

```php
->placeholder('—')
```

### Acceptance criteria

- Username visible.
- Searchable.
- Sortable.
- Handles missing username.
- Test passes.

### Definition of Done

- Тест написан.
- Username column добавлен.
- Test passes.
- Коммит: `RG-470: Add username column`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-471 — Add Email Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-471-add-email-column`  
**Base branch:** develop
**Depends on:** RG-470

### Goal

Добавить email column.

### TDD step

Feature test:

```php
it('renders email in user resource table', function () {
    $admin = User::factory()->admin()->create();

    User::factory()->create([
        'email' => 'user@example.com',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('user@example.com');
});
```

### Implementation

Add column:

```php
TextColumn::make('email')
    ->label('Email')
    ->searchable()
    ->sortable()
    ->copyable()
```

### Acceptance criteria

- Email visible.
- Searchable.
- Sortable.
- Copyable if Filament supports it.
- Test passes.

### Definition of Done

- Тест написан.
- Email column добавлен.
- Test passes.
- Коммит: `RG-471: Add email column`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-472 — Add Role Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-472-add-role-column`  
**Base branch:** develop
**Depends on:** RG-471

### Goal

Добавить role column.

### TDD step

Feature test:

```php
it('renders role in user resource table', function () {
    $admin = User::factory()->admin()->create();

    User::factory()->moderator()->create([
        'email' => 'mod@example.com',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('moderator');
});
```

### Implementation

Add column:

```php
TextColumn::make('role')
    ->label('Role')
    ->badge()
    ->sortable()
```

If role is enum:

```php
->formatStateUsing(fn (UserRole $state) => $state->value)
```

Optional colors:

```php
admin => danger
moderator => warning
user => gray
```

### Acceptance criteria

- Role visible.
- Rendered as badge.
- Sortable.
- Handles enum/string role.
- Test passes.

### Definition of Done

- Тест написан.
- Role column добавлен.
- Test passes.
- Коммит: `RG-472: Add role column`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-473 — Add Status Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-473-add-status-column`  
**Base branch:** develop
**Depends on:** RG-472

### Goal

Добавить status column.

### TDD step

Feature test:

```php
it('renders status in user resource table', function () {
    $admin = User::factory()->admin()->create();

    User::factory()->banned()->create([
        'email' => 'banned@example.com',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('banned');
});
```

### Implementation

Add column:

```php
TextColumn::make('status')
    ->label('Status')
    ->badge()
    ->sortable()
```

Color mapping:

```txt
active       → success
trusted      → info/success
banned       → danger
shadowbanned → warning
```

Adapt to actual `UserStatus` enum.

### Acceptance criteria

- Status visible.
- Rendered as badge.
- Sortable.
- Color mapping exists.
- Test passes.

### Definition of Done

- Тест написан.
- Status column добавлен.
- Test passes.
- Коммит: `RG-473: Add status column`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-474 — Add Posts_Count Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-474-add-posts-count-column`  
**Base branch:** develop
**Depends on:** RG-473

### Goal

Добавить posts_count column.

### TDD step

Feature/table test:

```php
it('renders posts count in user resource table', function () {
    $admin = User::factory()->admin()->create();

    $user = User::factory()->create([
        'username' => 'poster',
    ]);

    Post::factory()->count(2)->for($user)->published()->create();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('2');
});
```

### Implementation

Use `withCount`:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->withCount('posts');
}
```

Add column:

```php
TextColumn::make('posts_count')
    ->label('Posts')
    ->numeric()
    ->sortable()
```

If `posts_count` denormalized column already exists, avoid naming conflict by checking behavior. In Eloquent, `withCount('posts')` creates `posts_count`.

### Acceptance criteria

- Posts count visible.
- Count comes from relationship withCount.
- Sortable.
- Avoids N+1.
- Test passes.

### Definition of Done

- Тест написан.
- withCount добавлен.
- posts_count column добавлен.
- Test passes.
- Коммит: `RG-474: Add posts_count column`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-475 — Add Reports_Count Column Placeholder

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-475-add-reports-count-column-placeholder`  
**Base branch:** develop
**Depends on:** RG-474

### Goal

Добавить placeholder column для user reports_count.

### TDD step

Feature test:

```php
it('renders reports count placeholder in user resource table', function () {
    $admin = User::factory()->admin()->create();

    User::factory()->create([
        'username' => 'reported_user_placeholder',
    ]);

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Reports');
});
```

Если Filament table headers трудно assert через HTML, test class-level or skip exact header and assert placeholder value `—`.

### Implementation

Add placeholder column:

```php
TextColumn::make('reports_count_placeholder')
    ->label('Reports')
    ->state(fn (User $record): string => '—')
    ->tooltip('User-level report aggregation is not implemented yet.')
```

Do not create fake persisted `reports_count` on users.

Add TODO comment:

```php
// TODO Phase later: replace placeholder with user-level report aggregate.
```

### Acceptance criteria

- Reports column/header exists.
- Shows `—` or `0` placeholder.
- Does not create new DB column.
- Does not run expensive aggregate query.
- Tooltip/TODO explains placeholder.
- Test passes.

### Definition of Done

- Тест написан.
- Placeholder column добавлен.
- No schema change.
- Test passes.
- Коммит: `RG-475: Add reports_count column placeholder`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-476 — Add Active Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-476-add-active-filter`  
**Base branch:** develop
**Depends on:** RG-475

### Goal

Добавить active filter.

### TDD step

Filament filter test:

```php
it('filters active users in user resource', function () {
    $admin = User::factory()->admin()->create();

    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'username' => 'active_user',
    ]);

    $banned = User::factory()->banned()->create([
        'username' => 'banned_user',
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->filterTable('active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$banned]);
});
```

Adapt to installed Filament test helpers.

### Implementation

Add filter:

```php
Filter::make('active')
    ->label('Active')
    ->query(fn (Builder $query) => $query->where('status', UserStatus::Active))
```

### Acceptance criteria

- Active filter exists.
- Shows active users.
- Hides banned/shadowbanned/trusted if trusted is separate status.
- Test passes.

### Definition of Done

- Тест написан.
- Active filter добавлен.
- Test passes.
- Коммит: `RG-476: Add active filter`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-477 — Add Banned Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-477-add-banned-filter`  
**Base branch:** develop
**Depends on:** RG-476

### Goal

Добавить banned filter.

### TDD step

Filament filter test:

```php
it('filters banned users in user resource', function () {
    $admin = User::factory()->admin()->create();

    $banned = User::factory()->banned()->create([
        'username' => 'banned_user',
    ]);

    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'username' => 'active_user',
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->filterTable('banned')
        ->assertCanSeeTableRecords([$banned])
        ->assertCanNotSeeTableRecords([$active]);
});
```

### Implementation

Add filter:

```php
Filter::make('banned')
    ->label('Banned')
    ->query(fn (Builder $query) => $query->where('status', UserStatus::Banned))
```

### Acceptance criteria

- Banned filter exists.
- Shows banned users.
- Hides active/trusted/shadowbanned users.
- Active filter still works.
- Test passes.

### Definition of Done

- Тест написан.
- Banned filter добавлен.
- Test passes.
- Коммит: `RG-477: Add banned filter`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-478 — Add Trusted Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-478-add-trusted-filter`  
**Base branch:** develop
**Depends on:** RG-477

### Goal

Добавить trusted filter.

### TDD step

Filament filter test:

```php
it('filters trusted users in user resource', function () {
    $admin = User::factory()->admin()->create();

    $trusted = User::factory()->create([
        'status' => UserStatus::Trusted,
        'username' => 'trusted_user',
    ]);

    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'username' => 'active_user',
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->filterTable('trusted')
        ->assertCanSeeTableRecords([$trusted])
        ->assertCanNotSeeTableRecords([$active]);
});
```

If project uses `is_trusted` instead of `UserStatus::Trusted`, adapt test.

### Implementation

If `UserStatus::Trusted` does not exist and there is no existing trusted field, add enum value:

```php
UserStatus::Trusted
```

Potential migration is usually not needed if status is string.  
If status is DB enum, migration required.

Add factory state:

```php
public function trusted(): static
{
    return $this->state(['status' => UserStatus::Trusted]);
}
```

Add filter:

```php
Filter::make('trusted')
    ->label('Trusted')
    ->query(fn (Builder $query) => $query->where('status', UserStatus::Trusted))
```

### Acceptance criteria

- Trusted filter exists.
- Trusted users can be represented in schema.
- Shows trusted users.
- Hides non-trusted users.
- Test passes.

### Definition of Done

- Тест написан.
- Trusted status/field support confirmed.
- Trusted filter добавлен.
- Test passes.
- Коммит: `RG-478: Add trusted filter`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
app/Enums/UserStatus.php
database/factories/UserFactory.php
tests/Feature/Filament/UserResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-479 — Add Ban User Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-479-add-ban-user-action`  
**Base branch:** develop
**Depends on:** RG-478

### Goal

Добавить ban user table action.

### TDD step

Filament action test:

```php
it('allows admin to ban user from user resource table action', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->callTableAction('ban', $target, data: [
            'reason' => 'Repeated abuse.',
        ]);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'moderatable_type' => User::class,
        'moderatable_id' => $target->id,
    ]);
});
```

Moderator hidden test:

```php
it('does not show ban action to moderator', ...)
```

Protected admin target test:

```php
it('does not allow banning admin user from resource', ...)
```

### Implementation

Add table action:

```php
Action::make('ban')
    ->label('Ban')
    ->color('danger')
    ->icon('heroicon-o-no-symbol')
    ->visible(fn (User $record): bool =>
        auth()->user()?->isAdmin()
        && auth()->id() !== $record->id
        && ! $record->isAdmin()
        && $record->status !== UserStatus::Banned
    )
    ->form([
        Textarea::make('reason')
            ->label('Reason')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (User $record, array $data): void {
        app(BanUserAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

Do not direct update status.

### Acceptance criteria

- Ban action visible only to admin.
- Hidden from moderator.
- Hidden for self/admin targets.
- Calls BanUserAction.
- Target becomes banned.
- Moderation log written.
- Test passes.

### Definition of Done

- Тест написан.
- Ban action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-479: Add ban user action`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-480 — Add Unban User Action

**Area:** Admin / Filament / Action / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-480-add-unban-user-action`  
**Base branch:** develop
**Depends on:** RG-479

### Goal

Добавить unban user table action и backend action, если его ещё нет.

### TDD step

Backend action test first:

```php
it('allows admin to unban banned user', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->banned()->create();

    app(UnbanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Appeal accepted.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});
```

Filament action test:

```php
it('allows admin to unban user from user resource table action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->banned()->create();

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->callTableAction('unban', $target, data: [
            'reason' => 'Appeal accepted.',
        ]);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});
```

Moderator hidden test:

```php
it('does not show unban action to moderator', ...)
```

### Implementation

Create backend action if missing:

```txt
app/Actions/Moderation/UnbanUserAction.php
```

Action:

```php
final class UnbanUserAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        if (! $admin->isAdmin()) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        if (! in_array($target->status, [UserStatus::Banned, UserStatus::Shadowbanned], true)) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $oldStatus = $target->status;

        $target->forceFill([
            'status' => UserStatus::Active,
        ])->save();

        $this->createModerationLog->handle(
            moderator: $admin,
            action: ModerationActionType::UnbanUser,
            target: $target,
            reason: $reason,
            metadata: [
                'from_status' => $oldStatus->value,
                'to_status' => UserStatus::Active->value,
            ],
        );
    }
}
```

Add enum value:

```php
ModerationActionType::UnbanUser
```

Filament action:

```php
Action::make('unban')
    ->visible(fn (User $record): bool =>
        auth()->user()?->isAdmin()
        && in_array($record->status, [UserStatus::Banned, UserStatus::Shadowbanned], true)
    )
    ->form([...])
    ->requiresConfirmation()
    ->action(fn (User $record, array $data) =>
        app(UnbanUserAction::class)->handle(auth()->user(), $record, $data['reason'] ?? null)
    )
```

### Acceptance criteria

- UnbanUserAction exists if missing.
- Admin can unban banned user.
- Admin can unban shadowbanned user, if rule accepted.
- Moderator cannot unban.
- Unban action visible only for banned/shadowbanned users.
- Target becomes active.
- Moderation log written.
- Test passes.

### Definition of Done

- Backend action/test written.
- Filament action/test written.
- ModerationActionType updated.
- Test passes.
- Коммит: `RG-480: Add unban user action`

### Files likely touched

```txt
app/Actions/Moderation/UnbanUserAction.php
app/Enums/ModerationActionType.php
app/Filament/Resources/UserResource.php
tests/Feature/Actions/UnbanUserActionTest.php
tests/Feature/Filament/UserResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-481 — Add Mark Trusted Action

**Area:** Admin / Filament / Action / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-481-add-mark-trusted-action`  
**Base branch:** develop
**Depends on:** RG-480

### Goal

Добавить mark trusted table action и backend action, если его ещё нет.

### TDD step

Backend action test first:

```php
it('allows admin to mark active user as trusted', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    app(MarkUserTrustedAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Reliable contributor.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Trusted);
});
```

Filament action test:

```php
it('allows admin to mark user trusted from user resource table action', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->callTableAction('markTrusted', $target, data: [
            'reason' => 'Reliable contributor.',
        ]);

    expect($target->fresh()->status)->toBe(UserStatus::Trusted);
});
```

### Implementation

Create backend action:

```txt
app/Actions/Moderation/MarkUserTrustedAction.php
```

Action:

```php
final class MarkUserTrustedAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        if (! $admin->isAdmin()) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        if ($admin->id === $target->id || $target->isAdmin() || ! $target->isRegularUser()) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        if ($target->status !== UserStatus::Active) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $oldStatus = $target->status;

        $target->forceFill([
            'status' => UserStatus::Trusted,
        ])->save();

        $this->createModerationLog->handle(
            moderator: $admin,
            action: ModerationActionType::MarkUserTrusted,
            target: $target,
            reason: $reason,
            metadata: [
                'from_status' => $oldStatus->value,
                'to_status' => UserStatus::Trusted->value,
            ],
        );
    }
}
```

If `isRegularUser()` does not exist, implement helper or check role enum directly.

Add Filament action:

```php
Action::make('markTrusted')
    ->label('Mark trusted')
    ->color('success')
    ->visible(fn (User $record): bool =>
        auth()->user()?->isAdmin()
        && $record->status === UserStatus::Active
        && ! $record->isAdmin()
        && ! $record->isModerator()
    )
    ->form([...])
    ->requiresConfirmation()
    ->action(fn (User $record, array $data) =>
        app(MarkUserTrustedAction::class)->handle(auth()->user(), $record, $data['reason'] ?? null)
    )
```

### Acceptance criteria

- MarkUserTrustedAction exists.
- Admin can mark active normal user trusted.
- Moderator cannot mark trusted.
- Cannot mark admin/moderator/self trusted.
- Filament action visible only for valid targets.
- Target becomes trusted.
- Moderation log written.
- Test passes.

### Definition of Done

- Backend action/test written.
- Filament action/test written.
- Enum/status support added if missing.
- Test passes.
- Коммит: `RG-481: Add mark trusted action`

### Files likely touched

```txt
app/Actions/Moderation/MarkUserTrustedAction.php
app/Enums/UserStatus.php
app/Enums/ModerationActionType.php
app/Filament/Resources/UserResource.php
app/Models/User.php
tests/Feature/Actions/MarkUserTrustedActionTest.php
tests/Feature/Filament/UserResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-482 — Add Shadowban Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-482-add-shadowban-action`  
**Base branch:** develop
**Depends on:** RG-481

### Goal

Добавить shadowban table action в UserResource.

### TDD step

Filament action test:

```php
it('allows admin to shadowban user from user resource table action', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->callTableAction('shadowban', $target, data: [
            'reason' => 'Suspicious behavior.',
        ]);

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'moderatable_type' => User::class,
        'moderatable_id' => $target->id,
    ]);
});
```

Moderator hidden test:

```php
it('does not show shadowban action to moderator', ...)
```

Protected target test:

```php
it('does not allow shadowbanning admin from resource', ...)
```

### Implementation

Use existing Phase 21 backend action:

```php
ShadowbanUserAction
```

Add Filament action:

```php
Action::make('shadowban')
    ->label('Shadowban')
    ->color('warning')
    ->icon('heroicon-o-eye-slash')
    ->visible(fn (User $record): bool =>
        auth()->user()?->isAdmin()
        && auth()->id() !== $record->id
        && ! $record->isAdmin()
        && $record->status !== UserStatus::Shadowbanned
        && $record->status !== UserStatus::Banned
    )
    ->form([
        Textarea::make('reason')
            ->label('Reason')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (User $record, array $data): void {
        app(ShadowbanUserAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

Do not direct update status.

### Acceptance criteria

- Shadowban action visible only to admin.
- Hidden from moderator.
- Hidden for self/admin/banned/shadowbanned targets.
- Calls ShadowbanUserAction.
- Target becomes shadowbanned.
- Moderation log written.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Test written.
- Shadowban action added.
- Backend action used.
- Tests/build pass.
- Коммит: `RG-482: Add shadowban action`

### Files likely touched

```txt
app/Filament/Resources/UserResource.php
tests/Feature/Filament/UserResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 25 Completion Criteria

Phase 25 завершена, когда:

```txt
- RG-469–RG-482 выполнены;
- UserResource exists;
- admin can access UserResource;
- moderator can access UserResource;
- table shows username;
- table shows email;
- table shows role;
- table shows status;
- table shows posts_count;
- table shows reports_count placeholder;
- active filter works;
- banned filter works;
- trusted filter works;
- ban action works via BanUserAction;
- unban action works via UnbanUserAction or equivalent backend action;
- mark trusted action works via MarkUserTrustedAction or equivalent backend action;
- shadowban action works via ShadowbanUserAction;
- moderator cannot execute user sanction actions;
- protected user targets are guarded;
- moderation logs are created for user actions;
- no CommentResource/ReportResource created;
- no bulk user sanctions added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 25

Без отдельной задачи нельзя:

```txt
- создавать CommentResource;
- создавать ReportResource;
- создавать TagResource;
- создавать ModerationDashboard;
- делать user edit form;
- менять email/password через admin;
- делать impersonation;
- делать relation manager для user posts/comments;
- делать user report history page;
- добавлять bulk ban/shadowban;
- добавлять notifications;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-469 Create UserResource
RG-470 Add username column
RG-471 Add email column
RG-472 Add role column
RG-473 Add status column
RG-474 Add posts_count column
RG-475 Add reports_count column placeholder
RG-476 Add active filter
RG-477 Add banned filter
RG-478 Add trusted filter
RG-479 Add ban user action
RG-480 Add unban user action
RG-481 Add mark trusted action
RG-482 Add shadowban action
```
---

# 13. Release

После завершения Phase 25:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.6-phase25-filament-user-resource
git push -u origin release/v0.2.6-phase25-filament-user-resource
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.6-phase25-filament-user-resource -m "RateGuru Phase 25 Filament User Resource"
git push origin v0.2.6-phase25-filament-user-resource
```
