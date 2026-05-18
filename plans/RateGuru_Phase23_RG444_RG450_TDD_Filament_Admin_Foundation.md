# RateGuru — Phase 23 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 23 — Filament Admin Foundation**  
Диапазон задач: **RG-444 → RG-450**  
Основа нумерации: исходный atomic backlog, где Phase 23 начинается с задачи 444 и заканчивается задачей 450.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 23 соответствует исходному блоку:

```txt
Phase 23 — Filament Admin Foundation
```

Правильный диапазон Phase 23:

```txt
RG-444 — Configure Filament admin panel path
RG-445 — Restrict Filament access to admin/moderator
RG-446 — Test normal user cannot access Filament
RG-447 — Test moderator can access Filament
RG-448 — Create Filament dashboard placeholder
RG-449 — Add basic admin navigation groups
RG-450 — Add admin panel branding
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 24 начинается с `RG-451` и делает **Filament Post Resource**. Поэтому Phase 23 не должна создавать `PostResource`, `UserResource`, `CommentResource`, `ReportResource` или какие-либо таблицы ресурсов.
---

# 2. Цель Phase 23

Phase 23 создаёт базовую админскую оболочку на Filament.

После Phase 23 должно быть готово:

```txt
- рабочий Filament admin panel path;
- доступ в админку только для admin/moderator;
- normal user не может открыть Filament;
- moderator может открыть Filament;
- dashboard placeholder;
- базовые navigation groups;
- branding RateGuru в admin panel.
```

Это foundation для:

```txt
Phase 24 — Filament Post Resource
Phase 25 — Filament User Resource
Phase 26 — Filament Comments Resource
Phase 27 — Filament Reports Resource
Phase 28 — Filament Tags Resource
Phase 29 — Moderation Dashboard
```
---

# 3. Scope Phase 23

## Входит

```txt
- Filament admin panel path configuration;
- AdminPanelProvider настройка;
- User::canAccessPanel / FilamentUser access rule;
- access tests для normal user и moderator;
- admin dashboard placeholder;
- basic navigation groups constants/config;
- RateGuru admin branding.
```

## Не входит

```txt
- PostResource;
- UserResource;
- CommentResource;
- ReportResource;
- TagResource;
- ModerationDashboard;
- Filament table actions;
- Filament forms;
- bulk moderation;
- admin widgets with real data;
- user ban/shadowban UI;
- report resolution UI;
- custom Filament theme build;
- multi-panel architecture;
- MFA;
- permissions package / Filament Shield.
```

Фаза короткая специально. Её задача — не построить админку, а поставить правильную дверь, права доступа и каркас.
---

# 4. Product / Access Decisions

## 4.1. Admin panel path

Фиксируем path:

```txt
/admin
```

Почему не `/dashboard`, `/moderation`, `/control`:

```txt
- стандартный путь Filament обычно /admin;
- будущие ресурсы и ссылки проще строить;
- Phase 22 “Open in admin” link сможет безопасно начать указывать сюда после появления resources.
```

Если проект уже использует `/admin` для другой страницы — это конфликт, который надо решить в RG-444 до продолжения.

## 4.2. Кто имеет доступ

Доступ к Filament panel:

```txt
admin      → yes
moderator  → yes
normal user → no
guest      → redirect/login or 403 depending on auth state
banned     → no
shadowbanned → no
```

Moderator получает доступ в админку, потому что в следующих фазах ему нужны post/comment/report moderation screens.

## 4.3. Доступ через User model, не через route middleware hack

Правильный слой:

```php
User implements FilamentUser
User::canAccessPanel(Panel $panel): bool
```

Нельзя ограничиться только custom middleware вокруг `/admin`, потому что Filament Livewire requests и panel access должны проверяться системно.

## 4.4. Admin dashboard placeholder

Dashboard placeholder должен быть минимальным:

```txt
- title: RateGuru Admin
- short description: moderation and content operations will appear here
- links/placeholders to future sections
```

Не добавлять реальные widgets в Phase 23.

## 4.5. Navigation groups

Phase 23 добавляет только имена групп, которые будут использоваться future resources:

```txt
Content
Moderation
Users
Taxonomy
System
```

Не создавать resources ради navigation groups.

## 4.6. Branding

Branding должен быть простым:

```txt
- brand name: RateGuru
- optional logo placeholder if asset already exists
- dark-friendly colors if supported by panel config
```

Не делать custom Filament theme build в этой фазе.
---

# 5. Architecture Rules

## 5.1. Filament version

Использовать версию Filament, уже установленную в проекте.

Если Filament ещё не установлен, установить current compatible stable version через Composer в рамках RG-444, но не смешивать это с ресурсами.

Проверить:

```bash
composer show filament/filament
```

Если package отсутствует:

```bash
composer require filament/filament
php artisan filament:install --panels
```

Команды могут отличаться в зависимости от версии Filament. Исполнитель обязан свериться с установленной версией и документацией перед запуском.

## 5.2. AdminPanelProvider is the source of panel config

Основная настройка должна жить в:

```txt
app/Providers/Filament/AdminPanelProvider.php
```

Или в актуальном provider path для установленной версии Filament.

Настройки, которые ожидаются в Phase 23:

```txt
- id('admin')
- path('admin')
- login()
- colors/brandName where supported
- discovery paths for resources/pages/widgets
```

## 5.3. Access rule belongs to User model

В `User` model:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }
}
```

Нужно учитывать status:

```php
return $this->status === UserStatus::Active
    && ($this->isAdmin() || $this->isModerator());
```

Если admin/moderator factory users могут иметь `status = active` по умолчанию — хорошо. Если нет, исправить factory states.

## 5.4. Tests must hit the real admin route

Не тестировать только `canAccessPanel()` unit-тестом. Нужны HTTP tests:

```txt
normal user cannot access /admin
moderator can access /admin
admin can access /admin
```

Если Filament redirects guests to login, тест должен учитывать redirect.

## 5.5. No resources in Phase 23

Запрещено создавать:

```txt
app/Filament/Resources/PostResource.php
app/Filament/Resources/UserResource.php
app/Filament/Resources/CommentResource.php
app/Filament/Resources/ReportResource.php
```

Это задачи Phase 24–27.
---

# 6. GitFlow для Phase 23

## Base branch

Все задачи Phase 23 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-444-configure-filament-admin-panel-path
feature/RG-445-restrict-filament-access-to-admin-moderator
feature/RG-450-add-admin-panel-branding
```

## Commit format

```txt
RG-444: Configure Filament admin panel path
RG-445: Restrict Filament access to admin/moderator
RG-450: Add admin panel branding
```

## Release branch

После выполнения `RG-444`–`RG-450`:

```txt
release/v0.2.4-phase23-filament-admin-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.2.4-phase23-filament-admin-foundation
```
---

# 7. TDD Rules for Phase 23

## Для panel path

Писать HTTP smoke test:

```txt
/admin exists and does not 404
```

Ожидаемый response может быть:

```txt
redirect to login for guest
200 for authorized authenticated moderator/admin
403 for unauthorized authenticated normal user
```

## Для access

Тестировать реальные роли:

```txt
normal user cannot access Filament
moderator can access Filament
admin can access Filament
banned moderator cannot access Filament, если добавлен safety test
```

## Для dashboard placeholder

Тестировать rendered output:

```txt
RateGuru Admin
Content moderation tools will appear here
```

## Для navigation/branding

Часть можно тестировать через provider/config smoke tests, часть — rendered output.

Если прямой тест хрупкий из-за Filament internals:

```txt
TDD step: No direct stable test — Filament provider configuration task.
Acceptance проверяется HTTP smoke test + snapshot/manual check.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Auth / Tests / Config
Type: Test / Feature / Config / UI / Smoke
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
- Нет resources вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 23 Atomic Tasks
---

## RG-444 — Configure Filament Admin Panel Path

**Area:** Admin / Filament / Config  
**Type:** Config / Smoke  
**Priority:** P0  
**Branch:** `feature/RG-444-configure-filament-admin-panel-path`  
**Base branch:** develop
**Depends on:** RG-443

### Goal

Настроить Filament admin panel на path:

```txt
/admin
```

### TDD step

HTTP smoke test:

```php
it('has filament admin panel path', function () {
    $this->get('/admin')
        ->assertStatus(fn (int $status) => in_array($status, [200, 302, 403], true));
});
```

Лучше сделать более точный guest expectation после понимания auth flow:

```php
it('redirects guest from admin panel to login', function () {
    $this->get('/admin')
        ->assertRedirect();
});
```

Если Filament ещё не установлен, тест упадёт с 404.

### Implementation

Проверить наличие package:

```bash
composer show filament/filament
```

Если Filament отсутствует:

```bash
composer require filament/filament
php artisan filament:install --panels
```

Проверить provider:

```txt
app/Providers/Filament/AdminPanelProvider.php
```

Настроить panel path:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->login();
}
```

Если версия Filament использует другой provider/registration style, адаптировать под установленную версию, но сохранить итоговый `/admin`.

### Acceptance criteria

- `/admin` не возвращает 404.
- Admin panel id/path настроены как `admin`.
- Guest попадает в ожидаемый auth flow.
- `AdminPanelProvider` существует.
- Не создано ни одного Filament Resource.

### Definition of Done

- Smoke test добавлен.
- Panel path настроен.
- Тест проходит.
- `composer test` для связанных тестов проходит.
- Коммит: `RG-444: Configure Filament admin panel path`

### Files likely touched

```txt
composer.json
composer.lock
app/Providers/Filament/AdminPanelProvider.php
config/app.php
tests/Feature/Admin/FilamentAdminAccessTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-445 — Restrict Filament Access To Admin/Moderator

**Area:** Admin / Auth / Filament  
**Type:** Feature / Security  
**Priority:** P0  
**Branch:** `feature/RG-445-restrict-filament-access-to-admin-moderator`  
**Base branch:** develop
**Depends on:** RG-444

### Goal

Ограничить доступ в Filament panel только для:

```txt
admin
moderator
```

### TDD step

Model-level tests:

```php
it('allows admin to access filament panel', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->canAccessPanel(app(Panel::class)))->toBeTrue();
});
```

Этот тест может быть неудобен из-за получения `Panel` instance. Если прямой unit test хрупкий, писать HTTP tests в RG-446/RG-447 и в этой задаче сделать implementation.

Safety test желательно:

```php
it('does not allow banned moderator to access filament panel', function () {
    $moderator = User::factory()->moderator()->banned()->create();

    // assert false through helper or HTTP 403
});
```

### Implementation

В `User` model:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active
            && ($this->isAdmin() || $this->isModerator());
    }
}
```

Если helpers отсутствуют:

```php
public function isAdmin(): bool
{
    return $this->role === UserRole::Admin;
}

public function isModerator(): bool
{
    return $this->role === UserRole::Moderator;
}
```

Если enum casts ещё не настроены, не сравнивать строки хаотично. Привести User role/status casts в порядок.

### Acceptance criteria

- User implements `FilamentUser`.
- `canAccessPanel(Panel $panel)` exists.
- Admin active user can access.
- Moderator active user can access.
- Normal active user cannot access.
- Banned/shadowbanned user cannot access even if role is moderator/admin, если status rule применяется.
- No route-only hack.

### Definition of Done

- Access rule implemented.
- Helper methods added if needed.
- Related tests pass.
- Коммит: `RG-445: Restrict Filament access to admin/moderator`

### Files likely touched

```txt
app/Models/User.php
app/Enums/UserRole.php
app/Enums/UserStatus.php
tests/Feature/Admin/FilamentAdminAccessTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-446 — Test Normal User Cannot Access Filament

**Area:** Admin / Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-446-test-normal-user-cannot-access-filament`  
**Base branch:** develop
**Depends on:** RG-445

### Goal

Зафиксировать, что normal authenticated user не может открыть Filament.

### TDD step

HTTP test:

```php
it('does not allow normal user to access filament admin panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});
```

Если Filament redirects unauthorized users instead of returning 403 in installed version, adapt expectation:

```php
->assertStatus(403)
```

или:

```php
->assertRedirect('/login')
```

Но для authenticated unauthorized user лучше требовать 403. Redirect authenticated user на login — слабый сигнал.

### Implementation

Если тест не проходит:

```txt
- проверить User::canAccessPanel;
- проверить role/status factory defaults;
- проверить Filament auth middleware;
- проверить, что тест использует web guard.
```

### Acceptance criteria

- Normal authenticated user cannot access `/admin`.
- Response is 403 or equivalent unauthorized behavior.
- Normal user не видит dashboard content.
- Тест проходит.

### Definition of Done

- Test добавлен.
- Access behavior подтверждён.
- Коммит: `RG-446: Test normal user cannot access Filament`

### Files likely touched

```txt
tests/Feature/Admin/FilamentAdminAccessTest.php
app/Models/User.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-447 — Test Moderator Can Access Filament

**Area:** Admin / Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-447-test-moderator-can-access-filament`  
**Base branch:** develop
**Depends on:** RG-446

### Goal

Зафиксировать, что moderator может открыть Filament admin panel.

### TDD step

HTTP test:

```php
it('allows moderator to access filament admin panel', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru');
});
```

Admin test желательно добавить здесь же:

```php
it('allows admin to access filament admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});
```

### Implementation

Если тест падает:

```txt
- проверить moderator factory role/status;
- проверить User::canAccessPanel;
- проверить email verification requirement, если Filament panel включает verified middleware;
- проверить route path /admin.
```

Если Filament требует email verification, либо factories должны создавать verified users, либо panel config не должен требовать verification для MVP.

### Acceptance criteria

- Moderator active user gets 200 on `/admin`.
- Admin active user gets 200 on `/admin`, если test добавлен.
- Dashboard placeholder/brand visible.
- Normal user test from RG-446 still passes.

### Definition of Done

- Test добавлен.
- Access behavior подтверждён.
- Коммит: `RG-447: Test moderator can access Filament`

### Files likely touched

```txt
tests/Feature/Admin/FilamentAdminAccessTest.php
database/factories/UserFactory.php
app/Models/User.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-448 — Create Filament Dashboard Placeholder

**Area:** Admin / Filament / UI  
**Type:** Feature / UI  
**Priority:** P0  
**Branch:** `feature/RG-448-create-filament-dashboard-placeholder`  
**Base branch:** develop
**Depends on:** RG-447

### Goal

Создать минимальный dashboard placeholder для admin panel.

### TDD step

HTTP test:

```php
it('renders filament dashboard placeholder', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru Admin')
        ->assertSee('Moderation and content tools will appear here');
});
```

Точный URL dashboard может быть `/admin`, `/admin/dashboard` или Filament-generated route. Использовать реальный path после RG-444.

### Implementation

Вариант A — кастомная Filament Page:

```bash
php artisan make:filament-page Dashboard
```

или использовать встроенный dashboard page и добавить widget/placeholder.

Рекомендуемый минимальный вариант:

```txt
app/Filament/Pages/Dashboard.php
resources/views/filament/pages/dashboard.blade.php
```

Содержимое:

```txt
RateGuru Admin
Moderation and content tools will appear here.
```

Не добавлять real widgets.

### Acceptance criteria

- Dashboard page renders for moderator/admin.
- Placeholder text visible.
- No real widgets with database queries.
- Normal user still blocked.
- Test passes.

### Definition of Done

- Dashboard placeholder created.
- Test passes.
- Коммит: `RG-448: Create Filament dashboard placeholder`

### Files likely touched

```txt
app/Filament/Pages/Dashboard.php
resources/views/filament/pages/dashboard.blade.php
app/Providers/Filament/AdminPanelProvider.php
tests/Feature/Admin/FilamentDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-449 — Add Basic Admin Navigation Groups

**Area:** Admin / Filament / Config  
**Type:** Config / UI  
**Priority:** P1  
**Branch:** `feature/RG-449-add-basic-admin-navigation-groups`  
**Base branch:** develop
**Depends on:** RG-448

### Goal

Добавить базовые navigation group names для будущих Filament resources.

### TDD step

No direct stable test — Filament navigation internals can be brittle before resources exist.

Smoke/contract test can check constants if we introduce them:

```php
it('defines admin navigation groups', function () {
    expect(AdminNavigationGroup::CONTENT)->toBe('Content');
    expect(AdminNavigationGroup::MODERATION)->toBe('Moderation');
    expect(AdminNavigationGroup::USERS)->toBe('Users');
    expect(AdminNavigationGroup::TAXONOMY)->toBe('Taxonomy');
    expect(AdminNavigationGroup::SYSTEM)->toBe('System');
});
```

### Implementation

Создать lightweight constants class или enum:

```txt
app/Filament/Support/AdminNavigationGroup.php
```

Example:

```php
final class AdminNavigationGroup
{
    public const CONTENT = 'Content';
    public const MODERATION = 'Moderation';
    public const USERS = 'Users';
    public const TAXONOMY = 'Taxonomy';
    public const SYSTEM = 'System';
}
```

Будущие resources будут использовать:

```php
protected static ?string $navigationGroup = AdminNavigationGroup::CONTENT;
```

Если Filament version supports grouped navigation config at panel level, можно добавить базовую настройку там. Но не создавать fake resources ради меню.

### Acceptance criteria

- Navigation group names defined in one place.
- Groups include Content, Moderation, Users, Taxonomy, System.
- Future resources can import constants.
- No resources created.
- Test passes if constants test added.

### Definition of Done

- Constants/enum added.
- Optional test passes.
- Коммит: `RG-449: Add basic admin navigation groups`

### Files likely touched

```txt
app/Filament/Support/AdminNavigationGroup.php
tests/Unit/Filament/AdminNavigationGroupTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-450 — Add Admin Panel Branding

**Area:** Admin / Filament / Branding  
**Type:** Config / UI  
**Priority:** P0  
**Branch:** `feature/RG-450-add-admin-panel-branding`  
**Base branch:** develop
**Depends on:** RG-449

### Goal

Добавить RateGuru branding в Filament admin panel.

### TDD step

HTTP test:

```php
it('renders RateGuru branding in admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru');
});
```

Если dashboard placeholder уже содержит RateGuru, тест может быть слишком слабым. Добавить более конкретный marker если возможно:

```txt
data-testid="admin-branding"
```

Но Filament layout не всегда удобно тестировать через testid.

### Implementation

В `AdminPanelProvider`:

```php
return $panel
    ->brandName('RateGuru');
```

Если logo asset уже есть:

```php
->brandLogo(asset('images/rateguru-admin-logo.svg'))
```

Если logo asset отсутствует:

```txt
не создавать плохой временный логотип;
использовать brandName only;
добавить TODO в docs/design/admin-branding.md если нужно.
```

Можно добавить colors if supported:

```php
->colors([
    'primary' => Color::Purple,
])
```

Не делать custom Filament theme build.

Создать review note:

```txt
docs/design/phase-23-filament-admin-foundation-review.md
```

Содержимое:

```md
# Phase 23 Filament Admin Foundation Review

- [ ] /admin path works
- [ ] guest redirected/blocked
- [ ] normal user forbidden
- [ ] moderator allowed
- [ ] admin allowed
- [ ] dashboard placeholder visible
- [ ] RateGuru branding visible
- [ ] no Filament resources created in this phase
- [ ] composer test passes
- [ ] npm run build passes
```

### Acceptance criteria

- Admin panel shows `RateGuru` branding.
- Brand config lives in `AdminPanelProvider` or current Filament panel config.
- No custom theme build.
- No fake logo unless real asset exists.
- Review note exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Branding added.
- Test passes.
- Review note added.
- Full relevant tests pass.
- Build passes.
- Коммит: `RG-450: Add admin panel branding`

### Files likely touched

```txt
app/Providers/Filament/AdminPanelProvider.php
resources/views/filament/pages/dashboard.blade.php
docs/design/phase-23-filament-admin-foundation-review.md
tests/Feature/Admin/FilamentDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 10. Phase 23 Completion Criteria

Phase 23 завершена, когда:

```txt
- RG-444–RG-450 выполнены;
- /admin path работает;
- Filament AdminPanelProvider настроен;
- User implements FilamentUser or equivalent current Filament access contract;
- canAccessPanel restricts access to active admin/moderator;
- guest не получает прямой доступ;
- normal user cannot access /admin;
- moderator can access /admin;
- admin can access /admin;
- dashboard placeholder exists;
- basic navigation group names exist;
- RateGuru branding visible;
- no Filament resources were created;
- no moderation dashboard was created;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 23

Без отдельной задачи нельзя:

```txt
- создавать PostResource;
- создавать UserResource;
- создавать CommentResource;
- создавать ReportResource;
- создавать TagResource;
- добавлять table columns/actions/filters;
- добавлять bulk moderation;
- создавать ModerationDashboard;
- добавлять ban/shadowban UI;
- добавлять report resolution UI;
- устанавливать Filament Shield или permissions package;
- делать custom Filament theme build;
- делать multi-panel architecture;
- добавлять MFA;
- добавлять API endpoint.
```
---

# 12. Recommended Execution Order

```txt
RG-444 Configure Filament admin panel path
RG-445 Restrict Filament access to admin/moderator
RG-446 Test normal user cannot access Filament
RG-447 Test moderator can access Filament
RG-448 Create Filament dashboard placeholder
RG-449 Add basic admin navigation groups
RG-450 Add admin panel branding
```
---

# 13. Release

После завершения Phase 23:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.4-phase23-filament-admin-foundation
git push -u origin release/v0.2.4-phase23-filament-admin-foundation
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.4-phase23-filament-admin-foundation -m "RateGuru Phase 23 Filament admin foundation"
git push origin v0.2.4-phase23-filament-admin-foundation
```
