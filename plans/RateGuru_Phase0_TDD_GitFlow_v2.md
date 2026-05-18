# RateGuru — Phase 0 Implementation Plan

Версия: 0.3  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Стек Phase 0: **Laravel + SQLite + Livewire + Filament + Pest + Tailwind + Alpine**  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Цель Phase 0

Phase 0 не реализует продуктовые функции RateGuru.  
Её задача — подготовить технический фундамент, чтобы дальше можно было безопасно идти по TDD:

```txt
clone repo
→ gitflow rules
→ Laravel bootstrap
→ SQLite config
→ tests
→ Livewire
→ Filament
→ Tailwind/Alpine
→ base layouts
→ agent rules
→ smoke checks
```

После Phase 0 должно быть понятно:

```txt
- где живёт код;
- как называются ветки;
- как запускаются тесты;
- как запускается приложение;
- как работает локальная SQLite-база;
- как агент должен брать задачу;
- как писать тест перед кодом;
- как проверять DoD;
- как готовить PR.
```

---

# 2. GitFlow-lite Strategy

## 2.1. Основные ветки

```txt
main        production-ready branch
develop     integration/staging branch
```

## 2.2. Правило деплоя

Для RateGuru на старте лучше не деплоить каждый merge в `main` автоматически.

Рекомендуемая стратегия:

```txt
develop → staging deploy
main    → production-ready
tag     → production deploy
```

Production deploy делается только по тегу:

```txt
v0.0.1-phase0-bootstrap
v0.1.0-mvp-alpha
v0.2.0-public-beta
```

Почему так лучше:

```txt
- main остаётся стабильной веткой;
- релиз можно откатить по тегу;
- release notes проще вести;
- агент не сможет случайно задеплоить полурабочий main;
- hotfix-процесс понятнее.
```

## 2.3. Feature branches

Формат:

```txt
feature/RG-001-clone-repository
feature/RG-004-add-agent-rules
feature/RG-014-add-livewire
```

Каждая atomic-задача делается в отдельной ветке, если задача меняет код.

## 2.4. Release branches

Формат:

```txt
release/v0.0.1-phase0-bootstrap
```

Release branch создаётся от `develop`, когда Phase 0 готова к стабилизации.

## 2.5. Hotfix branches

Формат:

```txt
hotfix/RG-999-fix-production-env
```

Hotfix создаётся от `main`, потом мержится обратно в:

```txt
main
develop
```

## 2.6. Commit format

```txt
RG-001: Clone repository
RG-004: Add agent rules file
RG-010: Add SQLite configuration
```

Запрещены коммиты:

```txt
fix
final
changes
update
wip
test
asdf
```

Если нужен временный WIP-коммит, он должен быть squash/rebase до merge.

---

# 3. Agent Rules File

В проект добавляем файл:

```txt
AGENTS.md
```

Это не `CLAUDE.md`, потому что `AGENTS.md` нейтральнее: его могут читать Claude, Cursor, Codex, Devin, локальные агенты и человек.

`AGENTS.md` должен описывать:

```txt
- стек проекта;
- TDD-цикл;
- правила веток;
- правила коммитов;
- запрет на большие задачи;
- запрет на бизнес-логику в Livewire;
- структуру Actions;
- правила тестирования;
- правила UI-соответствия;
- команды запуска;
- Definition of Done;
- что нельзя делать без отдельной задачи.
```

---

# 4. Universal Task Template

Каждая задача оформляется так:

```txt
ID: RG-XXX
Title: English title
Area: Backend / UI / DB / Auth / Admin / Infra / Tests / Docs
Type: Test / Feature / Refactor / Config / Docs / Migration
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Коротко: что должно появиться.

TDD step:
Какой тест пишем первым. Если тест напрямую невозможен, пишем:
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
- Изменения маленькие и соответствуют задаче
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 5. Phase 0 Atomic Tasks

---

## RG-001 — Clone Repository

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** не требуется, выполняется локально  
**Depends on:** none

### Goal

Склонировать репозиторий RateGuru локально и убедиться, что работа ведётся внутри правильного git-репозитория.

### TDD step

No direct test — это локальная bootstrap-задача до появления приложения.

### Implementation

Выполнить:

```bash
git clone https://github.com/menvil/rateguru.git
cd rateguru
git remote -v
git status
```

Проверить, что remote указывает на:

```txt
https://github.com/menvil/rateguru
```

### Acceptance criteria

- Репозиторий склонирован локально.
- Команда `git status` работает без ошибок.
- Remote origin указывает на правильный GitHub-репозиторий.
- Рабочая директория чистая или понятна текущая причина изменений.

### Definition of Done

- Репозиторий доступен локально.
- Разработчик/агент находится в корне проекта.
- Следующая задача может выполняться из этой директории.

### Files likely touched

```txt
none
```

---

## RG-002 — Create Develop Branch

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** выполняется из main  
**Depends on:** RG-001

### Goal

Создать базовую ветку `develop`, от которой дальше будут идти feature branches.

### TDD step

No direct test — git branch setup.

### Implementation

Проверить текущую ветку:

```bash
git branch --show-current
```

Создать `develop`, если её ещё нет:

```bash
git checkout -b develop
git push -u origin develop
```

Если ветка уже есть:

```bash
git fetch origin
git checkout develop
git pull origin develop
```

### Acceptance criteria

- Ветка `develop` существует локально.
- Ветка `develop` существует на GitHub.
- Feature-задачи можно создавать от `develop`.

### Definition of Done

- `git branch --show-current` показывает `develop`.
- `git status` чистый.
- `git push` выполнен, если ветка создавалась впервые.

### Files likely touched

```txt
none
```

---

## RG-003 — Add Base Gitignore

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-003-add-base-gitignore`  
**Depends on:** RG-002

### Goal

Добавить корректный `.gitignore` для Laravel-проекта, чтобы в репозиторий не попадали зависимости, локальные env-файлы, SQLite-база, storage runtime-файлы и IDE-мусор.

### TDD step

No direct test — конфигурационный файл. Проверка через `git status`.

### Implementation

Создать или обновить `.gitignore`.

Минимальный состав:

```gitignore
/vendor
/node_modules

/.env
/.env.*
!.env.example

/database/*.sqlite
/database/*.sqlite-*
/database/database.sqlite

/storage/*.key
/storage/app/*
!/storage/app/.gitignore
/storage/app/public/*
!/storage/app/public/.gitignore
/storage/framework/cache/*
!/storage/framework/cache/.gitignore
/storage/framework/sessions/*
!/storage/framework/sessions/.gitignore
/storage/framework/testing/*
!/storage/framework/testing/.gitignore
/storage/framework/views/*
!/storage/framework/views/.gitignore
/storage/logs/*
!/storage/logs/.gitignore

/bootstrap/cache/*.php

/public/storage
/public/hot
/public/build

/.phpunit.cache
/.pest.cache
/coverage

.DS_Store
.idea
.vscode
```

Если проект будет создан через Laravel installer, сверить с дефолтным `.gitignore` и не удалить важные Laravel-исключения.

### Acceptance criteria

- `.gitignore` существует.
- `.env` не попадает в git.
- `vendor/` не попадает в git.
- `node_modules/` не попадает в git.
- SQLite runtime-файлы не попадают в git.
- `.env.example` остаётся разрешённым для коммита.

### Definition of Done

- `git status --ignored` показывает, что runtime-файлы игнорируются.
- `.gitignore` закоммичен.
- Коммит: `RG-003: Add base gitignore`

### Files likely touched

```txt
.gitignore
```

---

## RG-004 — Add Agent Rules

**Area:** Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-004-add-agent-rules`  
**Depends on:** RG-003

### Goal

Добавить `AGENTS.md` — файл с правилами для AI-агентов и людей, которые будут выполнять атомарные задачи.

### TDD step

No direct test — документационная задача.

### Implementation

Создать файл:

```txt
AGENTS.md
```

Содержимое должно включать:

```txt
# RateGuru Agent Rules

## Project
RateGuru is a Laravel + Livewire + Alpine + Filament application.

## Core stack
- Laravel
- Livewire
- Alpine.js
- Filament
- SQLite first
- Pest/PHPUnit
- Tailwind

## Branch rules
- Work from develop.
- One task = one branch.
- Branch format: feature/RG-XXX-short-title.
- Commit format: RG-XXX: English commit title.

## TDD rules
- Write test first when task is testable.
- Run related test before implementation.
- Implement minimal code.
- Run related test after implementation.
- Do not skip failing tests.

## Architecture rules
- Controllers and Livewire components must stay thin.
- Business logic goes into app/Actions.
- Technical helpers go into app/Services.
- Authorization goes into Policies.
- Background work goes into Jobs.
- Do not put business rules directly in Blade views.

## UI rules
- UI must follow the RateGuru design contract.
- Reusable UI belongs in Blade components.
- Alpine is for local UI state: modal, drawer, dropdown, preview.
- Livewire is for server state: forms, voting, comments, filtering.

## Forbidden without separate task
- Adding React/Vue/Inertia.
- Adding Redis.
- Migrating to PostgreSQL.
- Adding external APIs.
- Adding large UI redesign.
- Changing auth stack.
- Changing GitFlow strategy.

## Definition of Done
- Task scope is respected.
- Tests pass.
- Code is formatted.
- No unrelated files changed.
- Acceptance criteria are satisfied.
```

### Acceptance criteria

- `AGENTS.md` существует в корне проекта.
- В файле есть TDD rules.
- В файле есть branch rules.
- В файле есть architecture rules.
- В файле есть forbidden without separate task.
- В файле есть Definition of Done.

### Definition of Done

- Файл закоммичен.
- Коммит: `RG-004: Add agent rules`

### Files likely touched

```txt
AGENTS.md
```

---

## RG-005 — Initialize Laravel Application

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-005-initialize-laravel-application`  
**Depends on:** RG-004

### Goal

Инициализировать Laravel-приложение в репозитории RateGuru.

### TDD step

No direct test — приложения ещё нет. Проверка через artisan/smoke после установки.

### Implementation

Если репозиторий пустой или содержит только README/.gitignore/AGENTS.md, можно использовать один из подходов.

Вариант A:

```bash
composer create-project laravel/laravel .
```

Если Composer отказывается устанавливать в непустую папку, использовать временную папку:

```bash
composer create-project laravel/laravel rateguru-tmp
rsync -av rateguru-tmp/ ./ --exclude .git
rm -rf rateguru-tmp
```

Важно: не потерять `.git`, `.gitignore`, `AGENTS.md`.

После установки:

```bash
php artisan --version
```

### Acceptance criteria

- В корне проекта есть Laravel-структура:
  - `app/`
  - `bootstrap/`
  - `config/`
  - `database/`
  - `public/`
  - `resources/`
  - `routes/`
  - `tests/`
  - `artisan`
  - `composer.json`
- `php artisan --version` работает.
- `.git` сохранён.
- `AGENTS.md` сохранён.

### Definition of Done

- Laravel установлен.
- `composer install` работает.
- `php artisan --version` работает.
- Коммит: `RG-005: Initialize Laravel application`

### Files likely touched

```txt
app/
bootstrap/
config/
database/
public/
resources/
routes/
tests/
artisan
composer.json
composer.lock
```

---

## RG-006 — Configure Environment Example

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-006-configure-environment-example`  
**Depends on:** RG-005

### Goal

Подготовить `.env.example` для локального RateGuru-окружения.

### TDD step

No direct test — конфигурация окружения. Проверка через копирование `.env.example` в `.env`.

### Implementation

Обновить `.env.example`:

```env
APP_NAME=RateGuru
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite

QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
```

Если Laravel-версия требует абсолютный путь к SQLite, оставить комментарий в документации, но не добавлять machine-specific path в `.env.example`.

### Acceptance criteria

- `.env.example` содержит `APP_NAME=RateGuru`.
- `.env.example` использует `DB_CONNECTION=sqlite`.
- `.env.example` не содержит секретов.
- `.env.example` можно скопировать в `.env`.

### Definition of Done

- `.env.example` обновлён.
- Коммит: `RG-006: Configure environment example`

### Files likely touched

```txt
.env.example
```

---

## RG-007 — Configure Local Environment

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** не обязательно коммитить, локальная задача  
**Depends on:** RG-006

### Goal

Создать локальный `.env`, сгенерировать application key и убедиться, что приложение может стартовать локально.

### TDD step

No direct test — локальная настройка окружения.

### Implementation

```bash
cp .env.example .env
php artisan key:generate
```

Проверить:

```bash
php artisan about
```

### Acceptance criteria

- `.env` существует локально.
- `APP_KEY` сгенерирован.
- `php artisan about` работает.
- `.env` не отслеживается git.

### Definition of Done

- `git status` не показывает `.env`.
- Приложение может читать окружение.

### Files likely touched

```txt
.env
```

---

## RG-008 — Configure SQLite Database

**Area:** DB  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-008-configure-sqlite-database`  
**Depends on:** RG-007

### Goal

Настроить локальную SQLite-базу для разработки и тестов.

### TDD step

No direct test — инфраструктурная DB-настройка. Проверка через `php artisan migrate`.

### Implementation

Создать SQLite-файл локально:

```bash
touch database/database.sqlite
```

Проверить `.env`:

```env
DB_CONNECTION=sqlite
```

Если в `.env` есть `DB_DATABASE`, для SQLite можно либо удалить, либо указать абсолютный путь локально. Не коммитить machine-specific `.env`.

Запустить миграции:

```bash
php artisan migrate
```

### Acceptance criteria

- `database/database.sqlite` существует локально.
- SQLite-файл не отслеживается git.
- `php artisan migrate` проходит.
- Базовые Laravel-таблицы созданы.

### Definition of Done

- Миграции выполняются без ошибок.
- `git status` не содержит SQLite-файл.
- Если нужны изменения `.gitignore`, они уже есть в RG-003.

### Files likely touched

```txt
database/database.sqlite
```

---

## RG-009 — Add Pest Testing Framework

**Area:** Tests  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-009-add-pest-testing-framework`  
**Depends on:** RG-005

### Goal

Добавить Pest как основной тестовый runner для проекта.

### TDD step

No direct test — установка тестового инструмента. Проверка через запуск Pest.

### Implementation

Установить Pest:

```bash
composer require pestphp/pest --dev --with-all-dependencies
./vendor/bin/pest --init
```

Проверить запуск:

```bash
./vendor/bin/pest
```

### Acceptance criteria

- Pest установлен в `composer.json`.
- `tests/Pest.php` существует.
- `./vendor/bin/pest` запускается.
- Дефолтные тесты проходят или понятно исправлены под текущую версию Laravel.

### Definition of Done

- Pest установлен.
- Тестовый runner работает.
- Коммит: `RG-009: Add Pest testing framework`

### Files likely touched

```txt
composer.json
composer.lock
tests/Pest.php
phpunit.xml
tests/
```

---

## RG-010 — Add Application Boot Smoke Test

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-010-add-application-boot-smoke-test`  
**Depends on:** RG-009

### Goal

Добавить первый smoke-test, который подтверждает, что приложение открывает главную страницу без ошибки.

### TDD step

Сначала создать тест, который ожидает HTTP 200 на `/`.

Пример:

```php
it('loads the home page', function () {
    $this->get('/')->assertOk();
});
```

Если дефолтный Laravel route возвращает 200 — тест сразу будет зелёным. Это допустимо для smoke-test bootstrap-задачи.

### Implementation

Создать или обновить:

```txt
tests/Feature/ApplicationBootTest.php
```

Запустить:

```bash
./vendor/bin/pest tests/Feature/ApplicationBootTest.php
```

### Acceptance criteria

- Есть тест на загрузку `/`.
- Тест проходит.
- Тест запускается отдельно.
- Полный test suite проходит.

### Definition of Done

- Smoke-test добавлен.
- `./vendor/bin/pest` проходит.
- Коммит: `RG-010: Add application boot smoke test`

### Files likely touched

```txt
tests/Feature/ApplicationBootTest.php
```

---

## RG-011 — Add Test Database Configuration

**Area:** Tests  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-011-add-test-database-configuration`  
**Depends on:** RG-009

### Goal

Настроить тестовую базу так, чтобы тесты не зависели от локальной dev-базы.

### TDD step

Сначала добавить тест, который создаёт запись через модель Laravel или проверяет миграции через `RefreshDatabase`.

Пример:

```php
uses(RefreshDatabase::class);

it('uses an isolated test database', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
});
```

### Implementation

В `phpunit.xml` настроить:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Убедиться, что тесты используют in-memory SQLite.

### Acceptance criteria

- Тесты используют SQLite in-memory.
- Тесты не пишут в `database/database.sqlite`.
- `RefreshDatabase` работает.
- `./vendor/bin/pest` проходит.

### Definition of Done

- Конфигурация тестовой БД добавлена.
- Проверочный тест проходит.
- Коммит: `RG-011: Add test database configuration`

### Files likely touched

```txt
phpunit.xml
tests/Feature/TestDatabaseTest.php
```

---

## RG-012 — Add Composer Scripts

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-012-add-composer-scripts`  
**Depends on:** RG-009

### Goal

Добавить удобные Composer scripts для тестов и форматирования.

### TDD step

No direct test — dev tooling. Проверка через запуск команд.

### Implementation

В `composer.json` добавить scripts, если их нет:

```json
{
  "scripts": {
    "test": "pest",
    "test:feature": "pest tests/Feature",
    "test:unit": "pest tests/Unit"
  }
}
```

Если в проекте уже есть Laravel scripts, не ломать их, а аккуратно дополнить.

### Acceptance criteria

- `composer test` запускает Pest.
- `composer test:feature` запускает feature tests.
- `composer test:unit` запускает unit tests.
- Существующие Laravel scripts не удалены.

### Definition of Done

- Composer scripts работают.
- Коммит: `RG-012: Add composer scripts`

### Files likely touched

```txt
composer.json
```

---

## RG-013 — Add Livewire

**Area:** UI  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-013-add-livewire`  
**Depends on:** RG-005

### Goal

Установить Livewire как основной слой интерактивного публичного интерфейса.

### TDD step

No direct test на установку пакета. После установки добавить простой Livewire smoke component test в отдельной задаче.

### Implementation

Установить Livewire:

```bash
composer require livewire/livewire
```

Проверить, что пакет установлен:

```bash
php artisan list | grep livewire
```

### Acceptance criteria

- Livewire установлен.
- `composer.json` содержит livewire.
- Artisan-команды Livewire доступны.
- Приложение не сломалось после установки.

### Definition of Done

- `composer test` проходит.
- Коммит: `RG-013: Add Livewire`

### Files likely touched

```txt
composer.json
composer.lock
```

---

## RG-014 — Add Livewire Smoke Component

**Area:** UI  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-014-add-livewire-smoke-component`  
**Depends on:** RG-013

### Goal

Подтвердить, что Livewire-компоненты создаются, рендерятся и тестируются.

### TDD step

Сначала создать тест, который ожидает наличие текста из компонента.

Пример:

```php
Livewire::test(App\Livewire\SmokeCounter::class)
    ->assertSee('Livewire works');
```

Тест сначала должен упасть, потому что компонента нет.

### Implementation

Создать компонент:

```bash
php artisan make:livewire SmokeCounter
```

В компоненте вывести:

```txt
Livewire works
```

Добавить route или временно рендерить компонент в тесте.

### Acceptance criteria

- Есть Livewire-компонент `SmokeCounter`.
- Есть Livewire test.
- Тест падает до создания компонента.
- Тест проходит после реализации.
- Компонент не используется как продуктовая функция, только smoke.

### Definition of Done

- Livewire smoke-test проходит.
- `composer test` проходит.
- Коммит: `RG-014: Add Livewire smoke component`

### Files likely touched

```txt
app/Livewire/SmokeCounter.php
resources/views/livewire/smoke-counter.blade.php
tests/Feature/LivewireSmokeTest.php
```

---

## RG-015 — Add Filament Admin

**Area:** Admin  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-015-add-filament-admin`  
**Depends on:** RG-005

### Goal

Установить Filament как основу будущей админки и модерации.

### TDD step

No direct test на установку пакета. Доступ к панели проверим отдельной smoke-задачей.

### Implementation

Установить Filament:

```bash
composer require filament/filament:"^4.0"
php artisan filament:install --panels
```

Если версия Laravel/Filament требует другую команду, использовать актуальную команду, но не менять выбранный подход: Filament panel для `/admin`.

### Acceptance criteria

- Filament установлен.
- В проекте есть panel provider.
- Админ-панель зарегистрирована.
- Приложение продолжает проходить тесты.

### Definition of Done

- Filament установлен.
- `composer test` проходит.
- Коммит: `RG-015: Add Filament admin`

### Files likely touched

```txt
composer.json
composer.lock
app/Providers/Filament/
```

---

## RG-016 — Add Filament Access Smoke Test

**Area:** Admin  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-016-add-filament-access-smoke-test`  
**Depends on:** RG-015

### Goal

Добавить первичную проверку доступа к `/admin`.

### TDD step

Написать тест, который проверяет, что guest не получает свободный доступ к `/admin`.

Примерный смысл:

```php
it('does not allow guest to access admin panel', function () {
    $this->get('/admin')->assertRedirect();
});
```

Точная проверка может зависеть от Filament auth flow.

### Implementation

Создать:

```txt
tests/Feature/AdminAccessTest.php
```

Проверить guest access.

Если Filament требует пользователя для панели, пока не добавлять роли. Роли будут в Phase 2.

### Acceptance criteria

- Guest не может свободно открыть `/admin`.
- Тест отражает текущее поведение Filament.
- Тест проходит.

### Definition of Done

- Admin access smoke-test добавлен.
- `composer test` проходит.
- Коммит: `RG-016: Add Filament access smoke test`

### Files likely touched

```txt
tests/Feature/AdminAccessTest.php
```

---

## RG-017 — Add Tailwind Pipeline

**Area:** UI  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-017-add-tailwind-pipeline`  
**Depends on:** RG-005

### Goal

Убедиться, что Tailwind CSS pipeline установлен и может собирать frontend assets.

### TDD step

No direct PHP test — frontend build setup. Проверка через `npm run build`.

### Implementation

Проверить наличие:

```txt
package.json
vite.config.js
resources/css/app.css
resources/js/app.js
```

Установить npm-зависимости:

```bash
npm install
```

Проверить build:

```bash
npm run build
```

Если Tailwind не установлен дефолтно, установить и настроить:

```bash
npm install -D tailwindcss postcss autoprefixer
```

### Acceptance criteria

- `npm install` проходит.
- `npm run build` проходит.
- CSS подключён в layout.
- Build artifacts не коммитятся, если они должны быть в `.gitignore`.

### Definition of Done

- Frontend pipeline работает.
- `composer test` проходит.
- Коммит: `RG-017: Add Tailwind pipeline`

### Files likely touched

```txt
package.json
package-lock.json
vite.config.js
resources/css/app.css
resources/js/app.js
```

---

## RG-018 — Add Alpine Bootstrap

**Area:** UI  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-018-add-alpine-bootstrap`  
**Depends on:** RG-017

### Goal

Добавить Alpine.js как слой локальной UI-интерактивности для модалок, dropdown, drawer и preview.

### TDD step

No direct PHP test — frontend behavior. Проверка через build и маленький manual smoke.

### Implementation

Установить Alpine, если его нет:

```bash
npm install alpinejs
```

В `resources/js/app.js`:

```js
import Alpine from 'alpinejs'

window.Alpine = Alpine
Alpine.start()
```

### Acceptance criteria

- Alpine установлен.
- `npm run build` проходит.
- Alpine доступен в браузере.
- Нет конфликтов с Livewire.

### Definition of Done

- Alpine bootstrap добавлен.
- Коммит: `RG-018: Add Alpine bootstrap`

### Files likely touched

```txt
package.json
package-lock.json
resources/js/app.js
```

---

## RG-019 — Add Base Application Layout

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-019-add-base-application-layout`  
**Depends on:** RG-017

### Goal

Создать базовый layout RateGuru, в который позже будут встроены Livewire-страницы.

### TDD step

Сначала добавить feature-test на наличие текста `RateGuru` на главной странице.

```php
it('renders the RateGuru shell', function () {
    $this->get('/')->assertSee('RateGuru');
});
```

Тест должен упасть, если layout ещё не показывает название.

### Implementation

Создать/обновить:

```txt
resources/views/layouts/app.blade.php
routes/web.php
resources/views/home.blade.php
```

Базовая структура:

```txt
html
head
body dark background
header with RateGuru logo/name
main content slot
```

### Acceptance criteria

- Главная страница открывается.
- На странице есть `RateGuru`.
- Layout подключает Vite assets.
- Layout имеет тёмный базовый фон.
- Тест проходит.

### Definition of Done

- Base layout добавлен.
- Smoke-test проходит.
- `npm run build` проходит.
- Коммит: `RG-019: Add base application layout`

### Files likely touched

```txt
resources/views/layouts/app.blade.php
resources/views/home.blade.php
routes/web.php
tests/Feature/ApplicationShellTest.php
```

---

## RG-020 — Add Guest Layout

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-020-add-guest-layout`  
**Depends on:** RG-019

### Goal

Добавить отдельный layout для guest/auth страниц, чтобы логин/регистрация не смешивались с будущей лентой.

### TDD step

Если auth scaffolding ещё не установлен, direct test можно отложить.  
На Phase 0 достаточно smoke-test Blade render через route `/login` только если route существует.

### Implementation

Создать:

```txt
resources/views/layouts/guest.blade.php
```

Минимум:

```txt
- dark background
- centered auth card
- RateGuru brand
- content slot
```

### Acceptance criteria

- `guest.blade.php` существует.
- Layout не зависит от продуктовых Livewire-компонентов.
- Layout подключает CSS/JS.
- Визуально соответствует тёмному стилю RateGuru.

### Definition of Done

- Guest layout создан.
- Нет падения тестов.
- Коммит: `RG-020: Add guest layout`

### Files likely touched

```txt
resources/views/layouts/guest.blade.php
```

---

## RG-021 — Add Auth Scaffolding Placeholder

**Area:** Auth  
**Type:** Config  
**Priority:** P0  
**Branch:** `feature/RG-021-add-auth-scaffolding-placeholder`  
**Depends on:** RG-020

### Goal

Определить, какой auth starter используется, и подготовить минимальную auth-структуру.

### TDD step

No direct test до установки starter. После установки добавить тесты login/register в Phase 2.

### Implementation

Для Livewire-подхода предпочтительно использовать Laravel Breeze с Blade/Livewire-совместимым минимальным auth.

Если установка делается сейчас:

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade
npm install
npm run build
php artisan migrate
```

Важно: не добавлять React/Vue/Inertia.

### Acceptance criteria

- В проекте есть login/register routes.
- Auth не использует React/Vue/Inertia.
- `npm run build` проходит.
- `composer test` проходит.

### Definition of Done

- Auth scaffold установлен или явно зафиксирован как placeholder с TODO на Phase 2.
- Коммит: `RG-021: Add auth scaffolding placeholder`

### Files likely touched

```txt
routes/auth.php
resources/views/auth/
resources/views/layouts/guest.blade.php
composer.json
package.json
```

---

## RG-022 — Add Project README

**Area:** Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-022-add-project-readme`  
**Depends on:** RG-005

### Goal

Обновить `README.md`, чтобы новый разработчик или агент мог поднять проект локально.

### TDD step

No direct test — документация.

### Implementation

README должен содержать:

```txt
# RateGuru

## Stack
Laravel, Livewire, Alpine, Filament, SQLite, Pest, Tailwind.

## Local setup
git clone
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve

## Tests
composer test

## Branch strategy
main/develop/feature/release/hotfix

## Agent rules
See AGENTS.md
```

### Acceptance criteria

- README объясняет локальную установку.
- README содержит test command.
- README содержит GitFlow-lite summary.
- README ссылается на `AGENTS.md`.
- README не содержит устаревшего PlateRate-нейминга.

### Definition of Done

- README обновлён.
- Коммит: `RG-022: Add project README`

### Files likely touched

```txt
README.md
```

---

## RG-023 — Add Phase Zero Checklist

**Area:** Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-023-add-phase-zero-checklist`  
**Depends on:** RG-022

### Goal

Добавить чеклист завершения Phase 0, чтобы можно было объективно понять, что этап закрыт.

### TDD step

No direct test — документация.

### Implementation

Создать:

```txt
docs/phase-0-checklist.md
```

Содержимое:

```md
# Phase 0 Completion Checklist

- [ ] Repository cloned
- [ ] develop branch exists
- [ ] .gitignore added
- [ ] AGENTS.md added
- [ ] Laravel installed
- [ ] .env.example configured
- [ ] SQLite local DB works
- [ ] Pest installed
- [ ] Application smoke test exists
- [ ] Test DB isolated
- [ ] Composer test scripts work
- [ ] Livewire installed
- [ ] Livewire smoke component tested
- [ ] Filament installed
- [ ] Filament guest access smoke-tested
- [ ] Tailwind build works
- [ ] Alpine bootstrapped
- [ ] Base app layout exists
- [ ] Guest layout exists
- [ ] README updated
```

### Acceptance criteria

- `docs/phase-0-checklist.md` существует.
- Чеклист покрывает все Phase 0 задачи.
- Чеклист можно использовать перед release branch.

### Definition of Done

- Документ создан.
- Коммит: `RG-023: Add phase zero checklist`

### Files likely touched

```txt
docs/phase-0-checklist.md
```

---

## RG-024 — Add Fresh Clone Verification Guide

**Area:** Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-024-add-fresh-clone-verification-guide`  
**Depends on:** RG-023

### Goal

Добавить инструкцию, как проверить проект с нуля после клонирования. Это важно для агентов и для контроля качества Phase 0.

### TDD step

No direct test — документация. Проверяется ручным fresh clone.

### Implementation

Создать:

```txt
docs/fresh-clone-verification.md
```

Содержимое:

```md
# Fresh Clone Verification

```bash
git clone https://github.com/menvil/rateguru.git rateguru-check
cd rateguru-check

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

npm install
npm run build

composer test
php artisan serve
```

Expected result:

- composer install passes
- migrations pass
- npm build passes
- tests pass
- home page opens
- /admin redirects guest or asks for auth
```

### Acceptance criteria

- Документ существует.
- Инструкция начинается с чистого clone.
- Указаны все команды.
- Указан ожидаемый результат.

### Definition of Done

- Документ создан.
- Коммит: `RG-024: Add fresh clone verification guide`

### Files likely touched

```txt
docs/fresh-clone-verification.md
```

---

## RG-025 — Create Phase Zero Release Branch

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** `release/v0.0.1-phase0-bootstrap`  
**Depends on:** RG-001 through RG-024

### Goal

Создать release branch для стабилизации Phase 0.

### TDD step

No direct test — release management task.

### Implementation

После выполнения всех задач:

```bash
git checkout develop
git pull origin develop
composer test
npm run build

git checkout -b release/v0.0.1-phase0-bootstrap
git push -u origin release/v0.0.1-phase0-bootstrap
```

### Acceptance criteria

- Все Phase 0 задачи выполнены.
- `composer test` проходит.
- `npm run build` проходит.
- Release branch создан от `develop`.
- Release branch запушен.

### Definition of Done

- Release branch существует.
- Phase 0 checklist заполнен.
- Готово к merge в `main`.

### Files likely touched

```txt
none
```

---

## RG-026 — Tag Phase Zero Release

**Area:** Infra  
**Type:** Config  
**Priority:** P0  
**Branch:** main  
**Depends on:** RG-025

### Goal

Создать production-ready tag для Phase 0.

### TDD step

No direct test — release tagging task.

### Implementation

После review и merge release branch в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.1-phase0-bootstrap -m "RateGuru Phase 0 bootstrap"
git push origin v0.0.1-phase0-bootstrap
```

### Acceptance criteria

- Tag создан на `main`.
- Tag запушен в origin.
- Tag соответствует стабильному состоянию Phase 0.
- Production deploy, если настроен, должен запускаться по тегу, а не по любому merge в `main`.

### Definition of Done

- `v0.0.1-phase0-bootstrap` существует на GitHub.
- README и checklist соответствуют состоянию проекта.

### Files likely touched

```txt
none
```

---

# 6. Phase 0 Completion Criteria

Phase 0 завершена, когда:

```txt
- репозиторий склонирован;
- develop существует;
- .gitignore корректный;
- AGENTS.md есть;
- Laravel установлен;
- .env.example настроен под RateGuru и SQLite;
- локальный .env создаётся из example;
- SQLite работает;
- Pest установлен;
- smoke-test главной страницы есть;
- тестовая БД изолирована;
- composer test работает;
- Livewire установлен и smoke-tested;
- Filament установлен;
- /admin защищён от guest access;
- Tailwind build работает;
- Alpine загружен;
- base app layout есть;
- guest layout есть;
- README объясняет setup;
- docs/phase-0-checklist.md есть;
- docs/fresh-clone-verification.md есть;
- release branch создан;
- tag v0.0.1-phase0-bootstrap создан.
```

---

# 7. Что нельзя делать в Phase 0

Без отдельной задачи нельзя:

```txt
- делать модели Post/Comment/Vote;
- делать upload;
- делать feed;
- делать voting;
- делать comments;
- делать reports;
- делать moderation resources;
- добавлять Redis;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia;
- добавлять Cloudinary;
- строить дизайн-систему;
- делать полноценную админку;
- менять GitFlow.
```

Phase 0 — это фундамент. Если в неё начать запихивать продуктовые функции, она перестанет быть контролируемой.

---

# 8. Recommended Execution Order

```txt
RG-001 Clone Repository
RG-002 Create Develop Branch
RG-003 Add Base Gitignore
RG-004 Add Agent Rules
RG-005 Initialize Laravel Application
RG-006 Configure Environment Example
RG-007 Configure Local Environment
RG-008 Configure SQLite Database
RG-009 Add Pest Testing Framework
RG-010 Add Application Boot Smoke Test
RG-011 Add Test Database Configuration
RG-012 Add Composer Scripts
RG-013 Add Livewire
RG-014 Add Livewire Smoke Component
RG-015 Add Filament Admin
RG-016 Add Filament Access Smoke Test
RG-017 Add Tailwind Pipeline
RG-018 Add Alpine Bootstrap
RG-019 Add Base Application Layout
RG-020 Add Guest Layout
RG-021 Add Auth Scaffolding Placeholder
RG-022 Add Project README
RG-023 Add Phase Zero Checklist
RG-024 Add Fresh Clone Verification Guide
RG-025 Create Phase Zero Release Branch
RG-026 Tag Phase Zero Release
```
