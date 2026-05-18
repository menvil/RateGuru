# RateGuru — Phase 2 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 2 — Authentication & User Foundation**  
Диапазон задач: **RG-039 → RG-055**  
Основа нумерации: исходный atomic backlog, где Phase 2 начинается с задачи 039 и заканчивается задачей 055.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 2 соответствует исходному блоку:

```txt
Phase 2 — Authentication & User Foundation
```

Правильный диапазон Phase 2:

```txt
RG-039 — Add UserRole enum
RG-040 — Add UserStatus enum
RG-041 — Add username field to users table
RG-042 — Add avatar_url field to users table
RG-043 — Add role field to users table
RG-044 — Add status field to users table
RG-045 — Add trust_level field to users table
RG-046 — Add user factory states for admin and moderator
RG-047 — Add user factory state for banned user
RG-048 — Add user factory state for trusted user
RG-049 — Add auth scaffolding
RG-050 — Test guest can see feed route
RG-051 — Test guest can access login page
RG-052 — Test authenticated user can access dashboard/feed
RG-053 — Test banned user cannot create content
RG-054 — Add UserPolicy skeleton
RG-055 — Register UserPolicy
```

Префикс `RG-` используется вместо `PLR-`, потому что проект теперь называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 2

Phase 2 создаёт основу пользователей, ролей, статусов и authentication flow.

После Phase 2 должно быть готово:

```txt
- UserRole enum;
- UserStatus enum;
- users table расширена нужными полями;
- User model умеет работать с role/status/trust_level/avatar/username;
- factories умеют создавать admin, moderator, banned, trusted users;
- auth scaffold установлен без React/Vue/Inertia;
- guest может видеть публичную feed route;
- guest может открыть login page;
- authenticated user может открыть dashboard/feed;
- banned user ограничен в создании контента;
- UserPolicy skeleton существует;
- UserPolicy зарегистрирован.
```

Важно: Phase 2 не создаёт реальные Post/Comment/Vote модели.  
Проверка banned user в `RG-053` должна быть сделана через минимальный guard/policy/action placeholder, а не через настоящую post creation domain logic. Реальная post creation начинается позже, в Phase 5.

---

# 3. Scope Phase 2

## Входит

```txt
- enums для ролей и статусов пользователя;
- миграции users table;
- casts в User model;
- user factory states;
- auth scaffold;
- базовые auth routes/views;
- guest/auth access tests;
- minimal feed/dashboard route access;
- UserPolicy skeleton;
- registration of UserPolicy.
```

## Не входит

```txt
- Post model;
- Comment model;
- Vote model;
- CreatePostAction;
- upload;
- real feed query;
- real moderation;
- Filament resources;
- profile page;
- password reset polish;
- social login;
- OAuth;
- roles package типа spatie/laravel-permission;
- API auth;
- Sanctum;
- Redis;
- PostgreSQL.
```

---

# 4. Design Constraint

Phase 2 затрагивает auth pages.  
Если auth scaffold создаёт дефолтный светлый Laravel UI, его нельзя оставлять как финальный вид.

Минимальное правило:

```txt
- auth pages должны использовать RateGuru guest layout;
- тёмный фон должен сохраняться;
- формы должны использовать или постепенно перейти на x-ui.input / x-ui.button;
- если это слишком большой объём для Phase 2, допустим минимальный dark wrapper, а полировка форм уйдёт в отдельные UI-задачи.
```

Нельзя добавлять React/Vue/Inertia только ради auth scaffold.

---

# 5. GitFlow для Phase 2

## Base branch

Все задачи Phase 2 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-039-add-user-role-enum
feature/RG-041-add-username-field-to-users-table
feature/RG-049-add-auth-scaffolding
```

## Commit format

```txt
RG-039: Add UserRole enum
RG-041: Add username field to users table
RG-049: Add auth scaffolding
```

## Release branch

После выполнения `RG-039`–`RG-055`:

```txt
release/v0.0.3-phase2-auth-user-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.0.3-phase2-auth-user-foundation
```

---

# 6. TDD Rules for Phase 2

## Для enum задач

Сначала тест:

```txt
- enum contains expected cases;
- enum values are stable strings;
- helper methods work, if added.
```

## Для migration задач

Сначала feature/schema test:

```txt
- users table has expected column;
- model can persist value;
- cast works, if applicable.
```

## Для factory задач

Сначала factory test:

```txt
- factory state creates correct user role/status/trust level.
```

## Для auth задач

Сначала HTTP feature tests:

```txt
- guest can access login page;
- guest can see public feed route;
- authenticated user can access dashboard/feed;
- banned user cannot create content placeholder.
```

## Для policy задач

Сначала policy test:

```txt
- policy class exists;
- registered policy resolves;
- basic methods return expected values.
```

---

# 7. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Auth / DB / Backend / Tests / UI / Docs
Type: Test / Feature / Migration / Config / Refactor
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
- Все связанные тесты проходят
- Код отформатирован
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 8. Phase 2 Atomic Tasks

---

## RG-039 — Add UserRole Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-039-add-user-role-enum`  
**Depends on:** RG-038

### Goal

Добавить enum ролей пользователя.

Роли нужны для разграничения обычных пользователей, модераторов и администраторов.

### TDD step

Сначала написать unit test:

```php
it('contains expected user roles', function () {
    expect(UserRole::User->value)->toBe('user');
    expect(UserRole::Moderator->value)->toBe('moderator');
    expect(UserRole::Admin->value)->toBe('admin');
});
```

Тест должен упасть до создания enum.

### Implementation

Создать:

```txt
app/Enums/UserRole.php
```

Содержимое:

```php
enum UserRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';
}
```

Если в проекте уже есть `app/Enums`, использовать существующую директорию.

Можно добавить helper:

```php
public function isStaff(): bool
{
    return in_array($this, [self::Moderator, self::Admin], true);
}
```

Но только если это сразу покрывается тестом. Если helper не нужен прямо сейчас — не добавлять.

### Acceptance criteria

- `UserRole` enum существует.
- Есть значения `user`, `moderator`, `admin`.
- Enum значения стабильные string values.
- Unit test проходит.
- Нет зависимости от внешнего roles package.

### Definition of Done

- Тест написан до реализации.
- Enum создан.
- Тест проходит.
- `composer test` проходит.
- Коммит: `RG-039: Add UserRole enum`

### Files likely touched

```txt
app/Enums/UserRole.php
tests/Unit/Enums/UserRoleTest.php
```

---

## RG-040 — Add UserStatus Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-040-add-user-status-enum`  
**Depends on:** RG-039

### Goal

Добавить enum статусов пользователя.

Статусы нужны для ограничения banned/limited/shadowbanned пользователей и для trusted-flow.

### TDD step

Сначала unit test:

```php
it('contains expected user statuses', function () {
    expect(UserStatus::Active->value)->toBe('active');
    expect(UserStatus::Limited->value)->toBe('limited');
    expect(UserStatus::Banned->value)->toBe('banned');
    expect(UserStatus::Shadowbanned->value)->toBe('shadowbanned');
});
```

Добавить тест helper-а:

```php
it('knows whether a status can create content', function () {
    expect(UserStatus::Active->canCreateContent())->toBeTrue();
    expect(UserStatus::Banned->canCreateContent())->toBeFalse();
    expect(UserStatus::Limited->canCreateContent())->toBeFalse();
    expect(UserStatus::Shadowbanned->canCreateContent())->toBeFalse();
});
```

### Implementation

Создать:

```txt
app/Enums/UserStatus.php
```

Содержимое:

```php
enum UserStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Banned = 'banned';
    case Shadowbanned = 'shadowbanned';

    public function canCreateContent(): bool
    {
        return $this === self::Active;
    }
}
```

### Acceptance criteria

- `UserStatus` enum существует.
- Есть значения `active`, `limited`, `banned`, `shadowbanned`.
- `canCreateContent()` возвращает true только для active.
- Unit tests проходят.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Helper покрыт тестом.
- Тест проходит.
- Коммит: `RG-040: Add UserStatus enum`

### Files likely touched

```txt
app/Enums/UserStatus.php
tests/Unit/Enums/UserStatusTest.php
```

---

## RG-041 — Add Username Field To Users Table

**Area:** DB / Auth  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-041-add-username-field-to-users-table`  
**Depends on:** RG-040

### Goal

Добавить `username` в таблицу `users`.

Username понадобится для профиля, отображения автора, URL `/u/{username}` и user mentions.

### TDD step

Сначала feature/schema test:

```php
it('has username column on users table', function () {
    expect(Schema::hasColumn('users', 'username'))->toBeTrue();
});
```

Также persistence test:

```php
$user = User::factory()->create(['username' => 'chef_ivan']);
expect($user->fresh()->username)->toBe('chef_ivan');
```

Тест должен упасть до миграции.

### Implementation

Создать миграцию:

```bash
php artisan make:migration add_username_to_users_table --table=users
```

Колонка:

```php
$table->string('username')->nullable()->unique()->after('name');
```

Почему nullable на старте:

```txt
- существующие пользователи из auth scaffold могут не иметь username;
- username можно сделать required позже в profile/onboarding.
```

Обновить `UserFactory`:

```php
'username' => fake()->unique()->userName(),
```

Обновить `$fillable` в `User`, если используется.

### Acceptance criteria

- `users.username` существует.
- `username` nullable.
- `username` unique.
- Factory создаёт username.
- User может быть сохранён с username.
- Тесты проходят.

### Definition of Done

- Migration test написан первым.
- Миграция реализована.
- Factory обновлена.
- Тест проходит.
- Коммит: `RG-041: Add username field to users table`

### Files likely touched

```txt
database/migrations/*_add_username_to_users_table.php
database/factories/UserFactory.php
app/Models/User.php
tests/Feature/UserSchemaTest.php
```

---

## RG-042 — Add Avatar Url Field To Users Table

**Area:** DB / Auth  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-042-add-avatar-url-field-to-users-table`  
**Depends on:** RG-041

### Goal

Добавить `avatar_url` в таблицу `users`.

Это нужно для будущего отображения автора в карточке поста, комментариях, профиле и меню пользователя.

### TDD step

Schema test:

```php
it('has avatar_url column on users table', function () {
    expect(Schema::hasColumn('users', 'avatar_url'))->toBeTrue();
});
```

Persistence test:

```php
$user = User::factory()->create(['avatar_url' => 'https://example.com/a.jpg']);
expect($user->fresh()->avatar_url)->toBe('https://example.com/a.jpg');
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration add_avatar_url_to_users_table --table=users
```

Колонка:

```php
$table->string('avatar_url')->nullable()->after('email');
```

Обновить `UserFactory`:

```php
'avatar_url' => null,
```

Обновить `$fillable`, если нужно.

### Acceptance criteria

- `users.avatar_url` существует.
- Поле nullable.
- User может быть сохранён с avatar_url.
- Factory не ломается.
- Тесты проходят.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Factory/User обновлены.
- Тест проходит.
- Коммит: `RG-042: Add avatar url field to users table`

### Files likely touched

```txt
database/migrations/*_add_avatar_url_to_users_table.php
database/factories/UserFactory.php
app/Models/User.php
tests/Feature/UserSchemaTest.php
```

---

## RG-043 — Add Role Field To Users Table

**Area:** DB / Auth  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-043-add-role-field-to-users-table`  
**Depends on:** RG-039, RG-042

### Goal

Добавить `role` в таблицу `users` и настроить cast на `UserRole`.

### TDD step

Schema + cast test:

```php
it('casts user role to UserRole enum', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect($user->fresh()->role)->toBe(UserRole::Admin);
});
```

Тест должен упасть до миграции/cast.

### Implementation

Создать миграцию:

```bash
php artisan make:migration add_role_to_users_table --table=users
```

Колонка:

```php
$table->string('role')->default(UserRole::User->value)->after('avatar_url');
```

Обновить `User` model casts:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
    ];
}
```

Если проект использует старый `$casts`, обновить его.

Обновить `UserFactory`:

```php
'role' => UserRole::User,
```

### Acceptance criteria

- `users.role` существует.
- Default role = `user`.
- User role кастится в `UserRole`.
- Factory создаёт обычного user.
- Тесты проходят.

### Definition of Done

- Тест написан первым.
- Migration реализована.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-043: Add role field to users table`

### Files likely touched

```txt
database/migrations/*_add_role_to_users_table.php
app/Models/User.php
database/factories/UserFactory.php
tests/Feature/UserSchemaTest.php
```

---

## RG-044 — Add Status Field To Users Table

**Area:** DB / Auth  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-044-add-status-field-to-users-table`  
**Depends on:** RG-040, RG-043

### Goal

Добавить `status` в таблицу `users` и настроить cast на `UserStatus`.

### TDD step

Schema + cast test:

```php
it('casts user status to UserStatus enum', function () {
    $user = User::factory()->create(['status' => UserStatus::Banned]);

    expect($user->fresh()->status)->toBe(UserStatus::Banned);
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration add_status_to_users_table --table=users
```

Колонка:

```php
$table->string('status')->default(UserStatus::Active->value)->after('role');
```

Обновить `User` casts:

```php
'status' => UserStatus::class,
```

Обновить factory:

```php
'status' => UserStatus::Active,
```

### Acceptance criteria

- `users.status` существует.
- Default status = `active`.
- User status кастится в `UserStatus`.
- Factory создаёт active user.
- Тесты проходят.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-044: Add status field to users table`

### Files likely touched

```txt
database/migrations/*_add_status_to_users_table.php
app/Models/User.php
database/factories/UserFactory.php
tests/Feature/UserSchemaTest.php
```

---

## RG-045 — Add Trust Level Field To Users Table

**Area:** DB / Auth  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-045-add-trust-level-field-to-users-table`  
**Depends on:** RG-044

### Goal

Добавить `trust_level` в `users`.

Trust level нужен для будущей логики:

```txt
- новые пользователи → pending moderation;
- trusted users → auto-publish;
- abusive users → limited flow.
```

### TDD step

Schema + persistence test:

```php
it('stores user trust level', function () {
    $user = User::factory()->create(['trust_level' => 10]);

    expect($user->fresh()->trust_level)->toBe(10);
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration add_trust_level_to_users_table --table=users
```

Колонка:

```php
$table->unsignedTinyInteger('trust_level')->default(0)->after('status');
```

Обновить factory:

```php
'trust_level' => 0,
```

Добавить cast:

```php
'trust_level' => 'integer',
```

### Acceptance criteria

- `users.trust_level` существует.
- Default = `0`.
- Поле integer.
- Factory задаёт trust_level.
- Тесты проходят.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Cast/factory обновлены.
- Тест проходит.
- Коммит: `RG-045: Add trust level field to users table`

### Files likely touched

```txt
database/migrations/*_add_trust_level_to_users_table.php
app/Models/User.php
database/factories/UserFactory.php
tests/Feature/UserSchemaTest.php
```

---

## RG-046 — Add User Factory States For Admin And Moderator

**Area:** Tests / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-046-add-user-factory-states-for-admin-and-moderator`  
**Depends on:** RG-043

### Goal

Добавить factory states для admin и moderator пользователей.

### TDD step

Factory tests:

```php
it('can create an admin user', function () {
    $user = User::factory()->admin()->create();

    expect($user->role)->toBe(UserRole::Admin);
});

it('can create a moderator user', function () {
    $user = User::factory()->moderator()->create();

    expect($user->role)->toBe(UserRole::Moderator);
});
```

### Implementation

В `database/factories/UserFactory.php` добавить:

```php
public function admin(): static
{
    return $this->state(fn () => [
        'role' => UserRole::Admin,
    ]);
}

public function moderator(): static
{
    return $this->state(fn () => [
        'role' => UserRole::Moderator,
    ]);
}
```

### Acceptance criteria

- `User::factory()->admin()` работает.
- `User::factory()->moderator()` работает.
- Admin имеет `UserRole::Admin`.
- Moderator имеет `UserRole::Moderator`.
- Тесты проходят.

### Definition of Done

- Factory tests написаны первыми.
- Factory states добавлены.
- Тесты проходят.
- Коммит: `RG-046: Add user factory states for admin and moderator`

### Files likely touched

```txt
database/factories/UserFactory.php
tests/Feature/UserFactoryTest.php
```

---

## RG-047 — Add User Factory State For Banned User

**Area:** Tests / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-047-add-user-factory-state-for-banned-user`  
**Depends on:** RG-044

### Goal

Добавить factory state для banned user.

### TDD step

Factory test:

```php
it('can create a banned user', function () {
    $user = User::factory()->banned()->create();

    expect($user->status)->toBe(UserStatus::Banned);
});
```

### Implementation

В `UserFactory` добавить:

```php
public function banned(): static
{
    return $this->state(fn () => [
        'status' => UserStatus::Banned,
    ]);
}
```

### Acceptance criteria

- `User::factory()->banned()` работает.
- Banned user имеет `UserStatus::Banned`.
- Тест проходит.

### Definition of Done

- Factory test написан.
- State добавлен.
- Тест проходит.
- Коммит: `RG-047: Add user factory state for banned user`

### Files likely touched

```txt
database/factories/UserFactory.php
tests/Feature/UserFactoryTest.php
```

---

## RG-048 — Add User Factory State For Trusted User

**Area:** Tests / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-048-add-user-factory-state-for-trusted-user`  
**Depends on:** RG-045

### Goal

Добавить factory state для trusted user.

Trusted user нужен для будущей auto-publish логики.

### TDD step

Factory test:

```php
it('can create a trusted user', function () {
    $user = User::factory()->trusted()->create();

    expect($user->trust_level)->toBeGreaterThanOrEqual(10);
});
```

### Implementation

В `UserFactory` добавить:

```php
public function trusted(): static
{
    return $this->state(fn () => [
        'trust_level' => 10,
        'status' => UserStatus::Active,
    ]);
}
```

Не создавать отдельный enum trust level в Phase 2. Это преждевременно.

### Acceptance criteria

- `User::factory()->trusted()` работает.
- Trusted user active.
- Trusted user имеет trust_level >= 10.
- Тест проходит.

### Definition of Done

- Factory test написан.
- State добавлен.
- Тест проходит.
- Коммит: `RG-048: Add user factory state for trusted user`

### Files likely touched

```txt
database/factories/UserFactory.php
tests/Feature/UserFactoryTest.php
```

---

## RG-049 — Add Auth Scaffolding

**Area:** Auth / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-049-add-auth-scaffolding`  
**Depends on:** RG-021, RG-022, RG-039, RG-040

### Goal

Добавить authentication scaffolding без React/Vue/Inertia.

Для RateGuru предпочтительный вариант:

```txt
Laravel Breeze Blade
```

Не использовать:

```txt
React
Vue
Inertia
Jetstream
Fortify manually
```

если нет отдельной задачи.

### TDD step

Сначала написать/подготовить tests:

```txt
- login page is accessible;
- register page is accessible;
- user can register;
- user can login.
```

Если Breeze уже генерирует тесты, проверить их и адаптировать под RateGuru.

### Implementation

Установить Breeze Blade, если ещё не установлен:

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade
npm install
npm run build
php artisan migrate
```

После установки:

```bash
composer test
```

Проверить, что scaffold не добавил React/Vue/Inertia.

Минимально адаптировать auth views под RateGuru guest layout:

```txt
resources/views/layouts/guest.blade.php
resources/views/auth/login.blade.php
resources/views/auth/register.blade.php
```

Не делать полную UI-полировку в этой задаче. Только избежать светлого дефолтного вида, если он явно выбивается.

### Acceptance criteria

- Login route существует.
- Register route существует.
- User может зарегистрироваться.
- User может залогиниться.
- Auth scaffold не использует React/Vue/Inertia.
- Auth views используют guest layout или совместимый RateGuru layout.
- `npm run build` проходит.
- `composer test` проходит.

### Definition of Done

- Auth tests есть и проходят.
- Breeze Blade установлен или auth scaffold уже существует и проверен.
- Нет запрещённых frontend stack additions.
- Коммит: `RG-049: Add auth scaffolding`

### Files likely touched

```txt
composer.json
composer.lock
routes/auth.php
routes/web.php
resources/views/auth/
resources/views/layouts/guest.blade.php
package.json
package-lock.json
tests/Feature/Auth/
```

---

## RG-050 — Test Guest Can See Feed Route

**Area:** Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-050-test-guest-can-see-feed-route`  
**Depends on:** RG-049

### Goal

Зафиксировать, что публичная feed route доступна гостю.

Важно: в Phase 2 ещё нет настоящей feed logic.  
Это должен быть минимальный placeholder route/view, который позже будет заменён Phase 8.

### TDD step

Сначала feature test:

```php
it('allows guests to see the feed route', function () {
    $this->get('/')->assertOk();
});
```

Дополнительно можно проверить текст:

```txt
RateGuru
```

### Implementation

Убедиться, что `/` ведёт на публичную страницу.

Минимальный вариант:

```php
Route::view('/', 'feed.placeholder')->name('feed');
```

или использовать существующий home view.

View должен использовать RateGuru app layout.

Не создавать Post model.  
Не создавать FeedQuery.  
Не создавать Livewire feed components.

### Acceptance criteria

- Guest получает HTTP 200 на `/`.
- Route имеет имя `feed` или явно зафиксированное имя.
- Страница содержит `RateGuru`.
- Не создаётся продуктовая feed logic.
- Тест проходит.

### Definition of Done

- Feature test написан.
- Placeholder route/view работает.
- Тест проходит.
- Коммит: `RG-050: Test guest can see feed route`

### Files likely touched

```txt
routes/web.php
resources/views/feed/placeholder.blade.php
tests/Feature/Auth/GuestFeedAccessTest.php
```

---

## RG-051 — Test Guest Can Access Login Page

**Area:** Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-051-test-guest-can-access-login-page`  
**Depends on:** RG-049

### Goal

Зафиксировать, что guest может открыть login page.

### TDD step

Feature test:

```php
it('allows guests to access the login page', function () {
    $this->get('/login')->assertOk();
});
```

Можно проверить наличие:

```txt
Email
Password
```

или auth-specific text, если scaffold использует другие labels.

### Implementation

Если Breeze уже добавил login route, убедиться, что тест проходит.

Если route отсутствует — исправить auth scaffold, а не писать кастомный login вручную.

### Acceptance criteria

- Guest получает HTTP 200 на `/login`.
- Login page содержит email/password fields или соответствующие labels.
- Login page не требует authentication.
- Тест проходит.

### Definition of Done

- Feature test добавлен.
- Login page работает.
- Коммит: `RG-051: Test guest can access login page`

### Files likely touched

```txt
tests/Feature/Auth/LoginPageAccessTest.php
resources/views/auth/login.blade.php
```

---

## RG-052 — Test Authenticated User Can Access Dashboard Feed

**Area:** Auth / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-052-test-authenticated-user-can-access-dashboard-feed`  
**Depends on:** RG-049, RG-050

### Goal

Зафиксировать, что authenticated user может открыть dashboard/feed.

RateGuru может не нуждаться в классическом `/dashboard`, но auth scaffold часто его создаёт.  
Нужно выбрать минимально устойчивое поведение.

Рекомендуемая фиксация:

```txt
authenticated user can access /
authenticated user can access /dashboard if it exists
```

Если `/dashboard` не нужен, он может редиректить на `/`.

### TDD step

Feature test:

```php
it('allows authenticated users to access the feed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();
});
```

Если dashboard route существует:

```php
$this->actingAs($user)->get('/dashboard')->assertOk();
```

или:

```php
->assertRedirect(route('feed'));
```

### Implementation

Проверить routes:

```bash
php artisan route:list
```

Если Breeze создал `/dashboard`, решить:

```txt
Option A: оставить dashboard как auth-only placeholder
Option B: redirect dashboard to feed
```

Для RateGuru лучше:

```txt
/dashboard → redirect('/')
```

пока отдельного dashboard не нужно.

### Acceptance criteria

- Authenticated user может открыть `/`.
- Если `/dashboard` есть, его поведение явно протестировано.
- Нет закрытия публичной feed route за auth.
- Тесты проходят.

### Definition of Done

- Feature test добавлен.
- Route behavior зафиксирован.
- Коммит: `RG-052: Test authenticated user can access dashboard feed`

### Files likely touched

```txt
routes/web.php
tests/Feature/Auth/AuthenticatedFeedAccessTest.php
```

---

## RG-053 — Test Banned User Cannot Create Content

**Area:** Auth / Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-053-test-banned-user-cannot-create-content`  
**Depends on:** RG-040, RG-047

### Goal

Зафиксировать базовое правило: banned user не может создавать контент.

В Phase 2 ещё нет `CreatePostAction`, поэтому нельзя делать настоящую post creation logic.  
Нужно сделать минимальную основу, которую позже будет использовать Phase 5.

### TDD step

Тест на User method:

```php
it('does not allow banned users to create content', function () {
    $user = User::factory()->banned()->create();

    expect($user->canCreateContent())->toBeFalse();
});
```

И тест active user:

```php
it('allows active users to create content', function () {
    $user = User::factory()->create();

    expect($user->canCreateContent())->toBeTrue();
});
```

Тест должен упасть до добавления метода.

### Implementation

В `User` model добавить метод:

```php
public function canCreateContent(): bool
{
    return $this->status->canCreateContent();
}
```

Не создавать Post model.  
Не создавать CreatePostAction.  
Не создавать upload route.

### Acceptance criteria

- Banned user `canCreateContent()` возвращает false.
- Active user `canCreateContent()` возвращает true.
- Метод покрыт тестом.
- Нет преждевременной post creation logic.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Метод добавлен.
- Тест проходит.
- Коммит: `RG-053: Test banned user cannot create content`

### Files likely touched

```txt
app/Models/User.php
tests/Feature/Auth/BannedUserContentTest.php
```

---

## RG-054 — Add UserPolicy Skeleton

**Area:** Auth / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-054-add-user-policy-skeleton`  
**Depends on:** RG-039, RG-040, RG-046, RG-047

### Goal

Создать `UserPolicy` skeleton для будущего управления пользователями, банами и профилями.

Phase 2 не должна реализовывать полноценную админскую авторизацию.  
Нужен skeleton с базовыми методами.

### TDD step

Policy test:

```php
it('allows admins to manage users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->manage($admin, $target))->toBeTrue();
});
```

И:

```php
it('does not allow normal users to manage users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->manage($user, $target))->toBeFalse();
});
```

### Implementation

Создать policy:

```bash
php artisan make:policy UserPolicy --model=User
```

Добавить минимальные методы:

```php
public function manage(User $actor, User $target): bool
{
    return $actor->role === UserRole::Admin;
}

public function ban(User $actor, User $target): bool
{
    return $actor->role === UserRole::Admin;
}

public function viewAdmin(User $actor): bool
{
    return in_array($actor->role, [UserRole::Admin, UserRole::Moderator], true);
}
```

Не реализовывать сложные правила:

```txt
- moderator ban;
- self-ban prevention;
- trust-level based permissions;
- audit logs.
```

Это будет позже.

### Acceptance criteria

- `UserPolicy` существует.
- Admin может manage users.
- Normal user не может manage users.
- Admin может ban users.
- Moderator может быть staff для viewAdmin, если метод добавлен.
- Policy tests проходят.

### Definition of Done

- Policy tests написаны первыми.
- Policy skeleton создан.
- Тесты проходят.
- Коммит: `RG-054: Add UserPolicy skeleton`

### Files likely touched

```txt
app/Policies/UserPolicy.php
tests/Unit/Policies/UserPolicyTest.php
```

---

## RG-055 — Register UserPolicy

**Area:** Auth / Backend / Tests  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-055-register-user-policy`  
**Depends on:** RG-054

### Goal

Зарегистрировать `UserPolicy`, чтобы Laravel authorization мог автоматически её находить.

### TDD step

Feature/unit test:

```php
it('resolves the registered user policy', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage', $target))->toBeTrue();
});
```

Тест должен упасть, если policy не зарегистрирована и auto-discovery не работает.

### Implementation

В зависимости от версии Laravel:

Вариант A — Laravel auto-discovery достаточно, если policy лежит в стандартном месте:

```txt
app/Policies/UserPolicy.php
```

Вариант B — явная регистрация:

```php
Gate::policy(User::class, UserPolicy::class);
```

в подходящем service provider.

Главное: не ломать framework conventions.

### Acceptance criteria

- `Gate::forUser($admin)->allows('manage', $target)` работает.
- Normal user получает false.
- Policy registration test проходит.
- Не добавляется внешний permissions package.
- `composer test` проходит.

### Definition of Done

- Тест написан первым.
- Policy зарегистрирована или подтверждена auto-discovery.
- Тест проходит.
- Коммит: `RG-055: Register UserPolicy`

### Files likely touched

```txt
app/Providers/AuthServiceProvider.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
tests/Feature/Auth/UserPolicyRegistrationTest.php
```

---

# 9. Phase 2 Completion Criteria

Phase 2 завершена, когда:

```txt
- RG-039–RG-055 выполнены;
- UserRole enum существует;
- UserStatus enum существует;
- users table содержит username, avatar_url, role, status, trust_level;
- User model casts role/status в enum;
- UserFactory умеет создавать admin, moderator, banned, trusted users;
- Auth scaffold установлен без React/Vue/Inertia;
- Guest может видеть feed route;
- Guest может открыть login page;
- Authenticated user может открыть feed/dashboard;
- Banned user не может создавать content по базовому guard методу;
- UserPolicy существует;
- UserPolicy зарегистрирован;
- composer test проходит;
- npm run build проходит.
```

---

# 10. Что нельзя делать в Phase 2

Без отдельной задачи нельзя:

```txt
- создавать Post model;
- создавать Comment model;
- создавать Vote models;
- делать CreatePostAction;
- делать upload;
- делать реальную ленту;
- делать profile page;
- делать real moderation;
- делать Filament UserResource;
- подключать spatie/laravel-permission;
- подключать Sanctum;
- подключать social login;
- добавлять Redis;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia;
- менять GitFlow;
- менять UI design contract.
```

---

# 11. Recommended Execution Order

```txt
RG-039 Add UserRole Enum
RG-040 Add UserStatus Enum
RG-041 Add Username Field To Users Table
RG-042 Add Avatar Url Field To Users Table
RG-043 Add Role Field To Users Table
RG-044 Add Status Field To Users Table
RG-045 Add Trust Level Field To Users Table
RG-046 Add User Factory States For Admin And Moderator
RG-047 Add User Factory State For Banned User
RG-048 Add User Factory State For Trusted User
RG-049 Add Auth Scaffolding
RG-050 Test Guest Can See Feed Route
RG-051 Test Guest Can Access Login Page
RG-052 Test Authenticated User Can Access Dashboard Feed
RG-053 Test Banned User Cannot Create Content
RG-054 Add UserPolicy Skeleton
RG-055 Register UserPolicy
```

---

# 12. Release

После завершения Phase 2:

```bash
git checkout develop
git pull origin develop

composer test
npm run build

git checkout -b release/v0.0.3-phase2-auth-user-foundation
git push -u origin release/v0.0.3-phase2-auth-user-foundation
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.3-phase2-auth-user-foundation -m "RateGuru Phase 2 auth and user foundation"
git push origin v0.0.3-phase2-auth-user-foundation
```
