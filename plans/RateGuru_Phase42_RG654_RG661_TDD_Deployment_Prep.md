# RateGuru — Phase 42 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 42 — Deployment Prep**  
Диапазон задач: **RG-654 → RG-661**  
Основа нумерации: исходный atomic backlog, где Phase 42 начинается с задачи 654 и заканчивается задачей 661.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 42 соответствует исходному блоку:

```txt
Phase 42 — Deployment Prep
```

Правильный диапазон Phase 42:

```txt
RG-654 — Add production environment checklist
RG-655 — Add storage symlink command docs
RG-656 — Add queue worker docs
RG-657 — Add migration deploy command docs
RG-658 — Add admin user creation command
RG-659 — Test admin user creation command
RG-660 — Add backup strategy note for SQLite
RG-661 — Add migration note from SQLite to PostgreSQL
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 41 заканчивается на RG-653.
Phase 42 занимает RG-654 → RG-661.
```

Phase 42 — это подготовка к deployment, а не сам production deployment.
---

# 2. Цель Phase 42

Phase 42 добавляет минимальный набор deployment-prep документов и tooling, чтобы проект можно было безопасно готовить к staging/production.

После Phase 42 в проекте должно быть:

```txt
- production environment checklist;
- документация по storage symlink;
- документация по queue worker;
- документация по migration deploy commands;
- artisan command для создания admin user;
- тест admin user creation command;
- backup strategy note для SQLite;
- migration note from SQLite to PostgreSQL.
```

Главная цель: убрать “ручную магию” из запуска и дать понятный operational checklist.
---

# 3. Scope Phase 42

## Входит

```txt
- docs/deployment/production-environment-checklist.md;
- docs/deployment/storage-symlink.md;
- docs/deployment/queue-worker.md;
- docs/deployment/migrations.md;
- admin user creation command;
- tests for admin creation command;
- docs/deployment/sqlite-backup-strategy.md;
- docs/deployment/sqlite-to-postgresql-migration.md;
- optional README links to deployment docs.
```

## Не входит

```txt
- реальный production deploy;
- Dockerfile / Docker Compose production stack;
- GitHub Actions deploy workflow;
- server provisioning;
- SSL/Nginx config;
- real queue worker installation;
- Redis setup;
- PostgreSQL migration implementation;
- database dump conversion scripts;
- cloud storage setup;
- CDN setup;
- backup automation job;
- monitoring/alerting;
- secrets manager integration.
```

Phase 42 — это docs + безопасная admin bootstrap command. Не надо превращать её в DevOps-проект.
---

# 4. Critical Decisions

## 4.1. Deployment prep ≠ deployment automation

Неправильно:

```txt
- добавить GitHub Actions deploy to production;
- написать ansible/terraform;
- настроить Nginx;
- включить Redis;
- мигрировать на PostgreSQL.
```

Правильно:

```txt
- документировать, что должно быть проверено перед production;
- зафиксировать команды;
- сделать безопасный способ создать первого admin;
- описать риски SQLite и путь на PostgreSQL.
```

## 4.2. Admin user creation command is the only executable feature

В этой фазе только одна реальная команда с изменением данных:

```txt
php artisan rateguru:admin:create
```

или похожее имя.

Она нужна, потому что demo admin из Phase 36 **local/test-only** и не должен попадать в production.

Production admin должен создаваться явно через безопасную команду.

## 4.3. Admin command must not use demo credentials

Запрещено:

```txt
admin@rateguru.test / password
```

для production admin.

Команда должна принимать:

```txt
--email
--username
--name
```

и пароль:

```txt
- через prompt;
- с confirmation;
- скрытый ввод;
```

В тестах можно использовать option `--password` только если он явно помечен как testing/non-interactive convenience.

Рекомендация:

```txt
Command supports --password for non-interactive/test usage,
but docs recommend interactive hidden prompt for real environments.
```

## 4.4. Admin command should be idempotent but safe

Если admin with email exists:

```txt
- не создавать дубль;
- либо обновить role to admin after confirmation/--force;
- либо fail with clear message.
```

Recommended MVP:

```txt
- if user does not exist: create admin;
- if user exists and --promote-existing is not passed: fail safely;
- if user exists and --promote-existing is passed: promote to admin.
```

Но backlog не требует promote. Чтобы задача была маленькой:

```txt
Phase 42 MVP:
- fail if email already exists;
- create new admin only.
```

Это проще и безопаснее.

## 4.5. Admin command must hash password

Нельзя сохранять plain password.

Use:

```php
Hash::make($password)
```

Test must assert:

```txt
Hash::check($password, $user->password) === true
$user->password !== $password
```

## 4.6. Queue worker docs without queue dependency

Phase 31 notifications intentionally did not require queues. Phase 42 only documents future/production queue worker patterns.

Docs should say:

```txt
Current MVP can run with sync queue unless async jobs are introduced.
If QUEUE_CONNECTION=database/redis is used, run a worker.
```

Do not require Redis.

Suggested docs:

```bash
php artisan queue:work --tries=3 --timeout=90
```

and supervisor example as illustrative, not mandatory.

## 4.7. Migration deploy docs must be cautious

Production migrations should not be:

```bash
php artisan migrate:fresh
```

Docs must explicitly warn:

```txt
Never run migrate:fresh in production.
Use php artisan migrate --force.
Take backup before migrations.
Run migrations during deployment window if destructive.
```

## 4.8. SQLite backup strategy is real, not hand-wavy

If MVP starts with SQLite, backup docs must include:

```txt
- DB path;
- copy backup command;
- sqlite .backup command;
- app downtime / consistency notes;
- backup rotation suggestion;
- restore command;
- file permissions.
```

SQLite is fine for MVP, but losing one file loses the whole database. Say that directly.

## 4.9. SQLite → PostgreSQL note is a strategy note, not migration implementation

Do not write conversion scripts in Phase 42.

Document:

```txt
- when to migrate;
- high-level steps;
- schema compatibility checks;
- data export/import options;
- staging rehearsal;
- cutover plan;
- rollback plan.
```

## 4.10. Docs should be testable

Docs-only tasks should still have light tests:

```txt
- file exists;
- contains key command/warning;
- mentions production safety.
```

This prevents accidental deletion or empty docs.
---

# 5. Suggested Deployment Docs Structure

```txt
docs/deployment/
  README.md
  production-environment-checklist.md
  storage-symlink.md
  queue-worker.md
  migrations.md
  sqlite-backup-strategy.md
  sqlite-to-postgresql-migration.md
```

Optional:

```txt
docs/deployment/phase-42-deployment-prep-review.md
```

with final checklist.
---

# 6. Suggested Command Naming

Recommended artisan command:

```bash
php artisan rateguru:admin:create
```

Options:

```txt
--email=
--username=
--name=
--password=
```

Optional:

```txt
--force
```

Not recommended in MVP unless needed.

Example:

```bash
php artisan rateguru:admin:create \
  --email=admin@example.com \
  --username=admin \
  --name="Admin User"
```

Then prompt password interactively.

For tests:

```bash
php artisan rateguru:admin:create \
  --email=admin@example.test \
  --username=admin \
  --name="Admin User" \
  --password=secret-password
```
---

# 7. GitFlow для Phase 42

## Base branch

Все задачи Phase 42 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-654-add-production-environment-checklist
feature/RG-658-add-admin-user-creation-command
feature/RG-661-add-migration-note-from-sqlite-to-postgresql
```

## Commit format

```txt
RG-654: Add production environment checklist
RG-658: Add admin user creation command
RG-661: Add migration note from SQLite to PostgreSQL
```

## Release branch

После выполнения `RG-654`–`RG-661`:

```txt
release/v0.2.23-phase42-deployment-prep
```

## Tag

После merge release branch в `main`:

```txt
v0.2.23-phase42-deployment-prep
```

Почему `v0.2.23`: Phase 41 использует `v0.2.22`, Phase 42 следующий release.
---

# 8. TDD Rules for Phase 42

## Для docs

Тестировать:

```txt
- файл существует;
- содержит критичные команды;
- содержит production warnings;
- содержит local/staging/production distinction.
```

## Для admin command

Тестировать:

```txt
- command creates admin user;
- role = admin;
- status = active;
- password hashed;
- command fails when email already exists;
- required inputs validated;
- no demo credentials used by default.
```

## Для deployment docs

Проверять:

```txt
- no instruction says migrate:fresh in production;
- docs mention backup before production migrations;
- SQLite backup doc mentions copying DB file / sqlite .backup;
- PostgreSQL migration note says strategy only, no implementation yet.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Deployment Prep / Docs / Artisan Command
Type: Docs / Command / Test
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Для docs — file/content test.
Для command — failing command test.

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
- Docs do not claim production is already automated
- No Redis/PostgreSQL migration implementation unless explicitly required
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 42 Atomic Tasks
---

## RG-654 — Add Production Environment Checklist

**Area:** Deployment Prep / Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-654-add-production-environment-checklist`  
**Base branch:** develop
**Depends on:** RG-653

### Goal

Создать production environment checklist для первого безопасного staging/production запуска.

### TDD step

Docs test:

```php
it('has production environment checklist', function () {
    $path = base_path('docs/deployment/production-environment-checklist.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('APP_ENV=production');
    expect($content)->toContain('APP_DEBUG=false');
    expect($content)->toContain('APP_KEY');
    expect($content)->toContain('backup');
    expect($content)->toContain('php artisan migrate --force');
});
```

Negative warning test:

```php
it('does not recommend migrate fresh for production', function () {
    $content = file_get_contents(base_path('docs/deployment/production-environment-checklist.md'));

    expect($content)->not->toContain('migrate:fresh in production');
});
```

### Implementation

Create:

```txt
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
```

Checklist should include:

```txt
Application:
- APP_ENV=production
- APP_DEBUG=false
- APP_URL correct
- APP_KEY generated and stable
- LOG_CHANNEL configured
- timezone clear

Database:
- DB_CONNECTION set
- SQLite path or PostgreSQL credentials configured
- backup taken before deployment
- migrations run with --force only

Storage:
- storage symlink exists
- writable storage/app, storage/logs, bootstrap/cache
- upload disk configured

Security:
- HTTPS handled by web server/proxy
- demo seeders not run in production
- demo admin credentials not present
- real admin created via admin creation command

Queues:
- QUEUE_CONNECTION decision documented
- worker configured if async queue used

Build:
- composer install --no-dev --optimize-autoloader
- npm ci && npm run build
- php artisan config:cache
- php artisan route:cache
- php artisan view:cache

Verification:
- homepage/feed opens
- login works
- admin panel access works
- upload works
- logs clean
```

Do not include actual server-specific commands like Nginx config unless clearly example-only.

### Acceptance criteria

- Deployment docs directory exists.
- Production checklist exists.
- Checklist includes critical env variables.
- Checklist warns against demo seeders/credentials in production.
- Checklist includes migration and backup warnings.
- Checklist links to storage/queue/migration docs placeholders if created later.
- Tests pass.

### Definition of Done

- Docs tests written.
- Checklist added.
- Deployment README added.
- Tests pass.
- Коммит: `RG-654: Add production environment checklist`

### Files likely touched

```txt
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-655 — Add Storage Symlink Command Docs

**Area:** Deployment Prep / Storage Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-655-add-storage-symlink-command-docs`  
**Base branch:** develop
**Depends on:** RG-654

### Goal

Документировать storage symlink и права на storage для production/staging.

### TDD step

Docs test:

```php
it('has storage symlink deployment docs', function () {
    $path = base_path('docs/deployment/storage-symlink.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('php artisan storage:link');
    expect($content)->toContain('storage/app/public');
    expect($content)->toContain('public/storage');
    expect($content)->toContain('permissions');
});
```

README link test:

```php
it('links storage symlink docs from deployment readme', function () {
    $content = file_get_contents(base_path('docs/deployment/README.md'));

    expect($content)->toContain('storage-symlink.md');
});
```

### Implementation

Create:

```txt
docs/deployment/storage-symlink.md
```

Content:

```md
# Storage Symlink

## Purpose

Uploaded public images must be accessible through Laravel's public storage symlink.

## Command

```bash
php artisan storage:link
```

## Expected result

```txt
public/storage -> storage/app/public
```

## Permissions

The web/PHP user must be able to write:

```txt
storage/app
storage/app/public
storage/logs
bootstrap/cache
```

## Verification

Upload a test image locally/staging and confirm the generated public URL opens.

## Production warning

Do not chmod 777 blindly. Use correct ownership/groups for the deploy user and web server.
```

Update:

```txt
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
```

### Acceptance criteria

- `storage-symlink.md` exists.
- Docs mention `php artisan storage:link`.
- Docs explain expected symlink.
- Docs mention writable directories.
- Docs warn against careless `chmod 777`.
- Deployment README/checklist links storage docs.
- Tests pass.

### Definition of Done

- Docs tests written.
- Storage docs added.
- Links updated.
- Tests pass.
- Коммит: `RG-655: Add storage symlink command docs`

### Files likely touched

```txt
docs/deployment/storage-symlink.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-656 — Add Queue Worker Docs

**Area:** Deployment Prep / Queue Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-656-add-queue-worker-docs`  
**Base branch:** develop
**Depends on:** RG-655

### Goal

Документировать queue worker strategy без включения обязательного Redis/queue setup.

### TDD step

Docs test:

```php
it('has queue worker deployment docs', function () {
    $path = base_path('docs/deployment/queue-worker.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('QUEUE_CONNECTION');
    expect($content)->toContain('php artisan queue:work');
    expect($content)->toContain('sync');
    expect($content)->toContain('Redis is not required');
});
```

### Implementation

Create:

```txt
docs/deployment/queue-worker.md
```

Content should say directly:

```txt
Current MVP can run with QUEUE_CONNECTION=sync if no async jobs are enabled.
If future jobs use database/redis queues, run a worker.
Redis is not required by Phase 42.
```

Commands:

```bash
php artisan queue:work --tries=3 --timeout=90
```

Optional Supervisor example:

```ini
[program:rateguru-worker]
command=php /path/to/rateguru/artisan queue:work --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/rateguru/storage/logs/worker.log
```

Warnings:

```txt
- restart workers after deploy if code changes;
- php artisan queue:restart;
- ensure logs are monitored;
- do not enable async queue without a worker.
```

Update deployment README/checklist.

### Acceptance criteria

- `queue-worker.md` exists.
- Docs explain `QUEUE_CONNECTION=sync`.
- Docs include `php artisan queue:work`.
- Docs mention `queue:restart`.
- Docs explicitly say Redis is not required.
- Docs warn not to enable async queue without worker.
- Tests pass.

### Definition of Done

- Docs tests written.
- Queue docs added.
- Links updated.
- Tests pass.
- Коммит: `RG-656: Add queue worker docs`

### Files likely touched

```txt
docs/deployment/queue-worker.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-657 — Add Migration Deploy Command Docs

**Area:** Deployment Prep / Migration Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-657-add-migration-deploy-command-docs`  
**Base branch:** develop
**Depends on:** RG-656

### Goal

Документировать безопасный запуск migrations при deploy.

### TDD step

Docs test:

```php
it('has migration deployment docs', function () {
    $path = base_path('docs/deployment/migrations.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('php artisan migrate --force');
    expect($content)->toContain('backup');
    expect($content)->toContain('Never run migrate:fresh in production');
});
```

### Implementation

Create:

```txt
docs/deployment/migrations.md
```

Content:

```md
# Deployment Migrations

## Production command

```bash
php artisan migrate --force
```

## Before running migrations

- take a database backup;
- check pending migrations;
- review destructive migrations;
- ensure deployment rollback plan exists.

## Check status

```bash
php artisan migrate:status
```

## Forbidden in production

Never run:

```bash
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

against production data.

## Recommended deploy order

1. Put app in maintenance mode if needed.
2. Backup database.
3. Pull/release new code.
4. Run composer install.
5. Build assets.
6. Run migrations with `--force`.
7. Clear/cache config/routes/views.
8. Restart queue workers if used.
9. Smoke test feed/login/admin/upload.
```

Update deployment README/checklist.

### Acceptance criteria

- `migrations.md` exists.
- Docs include `php artisan migrate --force`.
- Docs include `php artisan migrate:status`.
- Docs warn against `migrate:fresh` in production.
- Docs require backup before migrations.
- Docs include safe deploy order.
- Tests pass.

### Definition of Done

- Docs tests written.
- Migration docs added.
- Links updated.
- Tests pass.
- Коммит: `RG-657: Add migration deploy command docs`

### Files likely touched

```txt
docs/deployment/migrations.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-658 — Add Admin User Creation Command

**Area:** Deployment Prep / Artisan Command / Admin Bootstrap  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-658-add-admin-user-creation-command`  
**Base branch:** develop
**Depends on:** RG-657

### Goal

Добавить artisan command для создания production admin user без demo seeders.

### TDD step

Command existence test:

```php
it('has admin user creation command', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
        '--password' => 'secret-password',
    ])->assertExitCode(0);
});
```

На этом шаге тест должен упасть до создания команды.

### Implementation

Create:

```bash
php artisan make:command CreateAdminUserCommand
```

Command:

```php
protected $signature = 'rateguru:admin:create
    {--email= : Admin email}
    {--username= : Admin username}
    {--name= : Admin display name}
    {--password= : Admin password for non-interactive/testing usage}';
```

Implementation outline:

```php
public function handle(): int
{
    $email = (string) $this->option('email');
    $username = (string) $this->option('username');
    $name = (string) $this->option('name') ?: $username;

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid --email is required.');
        return self::FAILURE;
    }

    if ($username === '') {
        $this->error('--username is required.');
        return self::FAILURE;
    }

    if (User::where('email', $email)->exists()) {
        $this->error('A user with this email already exists.');
        return self::FAILURE;
    }

    $password = $this->option('password') ?: $this->secret('Password');

    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Password must be at least 12 characters.');
        return self::FAILURE;
    }

    $confirmation = $this->option('password')
        ? $password
        : $this->secret('Confirm password');

    if ($password !== $confirmation) {
        $this->error('Passwords do not match.');
        return self::FAILURE;
    }

    User::create([
        'email' => $email,
        'username' => $username,
        'name' => $name,
        'password' => Hash::make($password),
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);

    $this->info('Admin user created.');

    return self::SUCCESS;
}
```

Adapt enum names/fields to actual project.

Important:

```txt
--password exists for non-interactive usage/tests.
Docs should recommend interactive hidden prompt for real production.
```

Do not seed demo admin. This is separate from Phase 36.

### Acceptance criteria

- Command `rateguru:admin:create` exists.
- Command accepts email/username/name.
- Command can use interactive hidden password.
- Command supports `--password` for test/non-interactive mode.
- Command creates user with admin role.
- Command creates active user.
- Password is hashed.
- Command fails safely if email already exists.
- No demo credentials hardcoded.
- Tests may still be completed in RG-659.

### Definition of Done

- Command skeleton/implementation added.
- Basic command execution works.
- No production demo credential risk.
- Коммит: `RG-658: Add admin user creation command`

### Files likely touched

```txt
app/Console/Commands/CreateAdminUserCommand.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-659 — Test Admin User Creation Command

**Area:** Deployment Prep / Artisan Command / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-659-test-admin-user-creation-command`  
**Base branch:** develop
**Depends on:** RG-658

### Goal

Полноценно протестировать admin user creation command.

### TDD step

Command creates admin:

```php
it('creates admin user from command options', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
        '--password' => 'secret-password',
    ])
        ->expectsOutput('Admin user created.')
        ->assertExitCode(0);

    $admin = User::where('email', 'admin@example.test')->firstOrFail();

    expect($admin->username)->toBe('admin');
    expect($admin->name)->toBe('Admin User');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->status)->toBe(UserStatus::Active);
    expect(Hash::check('secret-password', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('secret-password');
});
```

Existing email fails:

```php
it('fails when admin email already exists', function () {
    User::factory()->create([
        'email' => 'admin@example.test',
    ]);

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin2',
        '--name' => 'Admin Two',
        '--password' => 'secret-password',
    ])
        ->expectsOutput('A user with this email already exists.')
        ->assertExitCode(1);

    expect(User::where('email', 'admin@example.test')->count())->toBe(1);
});
```

Invalid email fails:

```php
it('fails with invalid email', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'not-an-email',
        '--username' => 'admin',
        '--name' => 'Admin User',
        '--password' => 'secret-password',
    ])->assertExitCode(1);
});
```

Short password fails:

```php
it('requires sufficiently long password', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
        '--password' => 'short',
    ])->assertExitCode(1);

    expect(User::where('email', 'admin@example.test')->exists())->toBeFalse();
});
```

### Implementation

Adjust command until tests pass.

Add docs:

```txt
docs/deployment/admin-user-creation.md
```

Content:

```bash
php artisan rateguru:admin:create \
  --email=admin@example.com \
  --username=admin \
  --name="Admin User"
```

Warn:

```txt
Do not use demo admin credentials in production.
Use a strong unique password.
```

Update deployment README/checklist.

### Acceptance criteria

- Command creates admin user.
- Role/status correct.
- Password hashed.
- Duplicate email fails.
- Invalid email fails.
- Short password fails.
- Docs explain command.
- Docs warn against demo credentials.
- Tests pass.

### Definition of Done

- Command tests written.
- Command adjusted.
- Admin command docs added.
- Tests pass.
- Коммит: `RG-659: Test admin user creation command`

### Files likely touched

```txt
app/Console/Commands/CreateAdminUserCommand.php
docs/deployment/admin-user-creation.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Console/CreateAdminUserCommandTest.php
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-660 — Add Backup Strategy Note For SQLite

**Area:** Deployment Prep / SQLite Backup Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-660-add-backup-strategy-note-for-sqlite`  
**Base branch:** develop
**Depends on:** RG-659

### Goal

Документировать backup strategy для SQLite MVP.

### TDD step

Docs test:

```php
it('has sqlite backup strategy note', function () {
    $path = base_path('docs/deployment/sqlite-backup-strategy.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('SQLite');
    expect($content)->toContain('database/database.sqlite');
    expect($content)->toContain('.backup');
    expect($content)->toContain('restore');
    expect($content)->toContain('before migrations');
});
```

### Implementation

Create:

```txt
docs/deployment/sqlite-backup-strategy.md
```

Content:

```md
# SQLite Backup Strategy

## Scope

This note is for MVP deployments that use SQLite.

## Database file

Default path:

```txt
database/database.sqlite
```

Confirm actual production path from `DB_DATABASE`.

## Simple cold backup

Stop writes or put app in maintenance mode, then copy:

```bash
cp database/database.sqlite backups/database-$(date +%Y%m%d-%H%M%S).sqlite
```

## SQLite online backup

Use sqlite shell:

```bash
sqlite3 database/database.sqlite ".backup 'backups/database-$(date +%Y%m%d-%H%M%S).sqlite'"
```

## Before migrations

Always create backup before:

```bash
php artisan migrate --force
```

## Restore

```bash
cp backups/database-YYYYMMDD-HHMMSS.sqlite database/database.sqlite
php artisan optimize:clear
```

## Risks

- SQLite is one file; losing it loses all data.
- Backups must be copied off-server.
- File permissions must be checked after restore.
- Test restore regularly.
```

Update deployment README/checklist/migrations docs.

### Acceptance criteria

- `sqlite-backup-strategy.md` exists.
- Docs include DB file path.
- Docs include copy backup command.
- Docs include SQLite `.backup`.
- Docs include restore steps.
- Docs say backup before migrations.
- Docs warn backups must be copied off-server.
- Tests pass.

### Definition of Done

- Docs tests written.
- SQLite backup docs added.
- Links updated.
- Tests pass.
- Коммит: `RG-660: Add backup strategy note for SQLite`

### Files likely touched

```txt
docs/deployment/sqlite-backup-strategy.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
docs/deployment/migrations.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-661 — Add Migration Note From SQLite To PostgreSQL

**Area:** Deployment Prep / Database Strategy Docs  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-661-add-migration-note-from-sqlite-to-postgresql`  
**Base branch:** develop
**Depends on:** RG-660

### Goal

Документировать будущий путь миграции с SQLite на PostgreSQL без реализации миграции сейчас.

### TDD step

Docs test:

```php
it('has sqlite to postgresql migration note', function () {
    $path = base_path('docs/deployment/sqlite-to-postgresql-migration.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('SQLite');
    expect($content)->toContain('PostgreSQL');
    expect($content)->toContain('staging rehearsal');
    expect($content)->toContain('rollback');
    expect($content)->toContain('not implemented in Phase 42');
});
```

### Implementation

Create:

```txt
docs/deployment/sqlite-to-postgresql-migration.md
```

Content:

```md
# SQLite to PostgreSQL Migration Note

## Phase 42 status

This is a strategy note. Phase 42 does not implement migration scripts.

## When to migrate

Consider PostgreSQL when:

- concurrent writes grow;
- SQLite write locks become visible;
- database file size grows materially;
- backup/restore needs become more serious;
- analytics queries become heavier;
- production uptime requirements increase.

## High-level migration path

1. Freeze schema changes.
2. Backup SQLite.
3. Create PostgreSQL database.
4. Configure `.env` for PostgreSQL in staging.
5. Run Laravel migrations on PostgreSQL.
6. Export data from SQLite.
7. Import data into PostgreSQL.
8. Verify row counts and critical relations.
9. Run test suite against PostgreSQL.
10. Run browser smoke tests.
11. Run visual screenshots if UI data changed.
12. Rehearse rollback.
13. Schedule production cutover.

## Things to verify

- enum/string compatibility;
- JSON columns;
- timestamps/timezones;
- foreign keys;
- indexes;
- full-text/search behavior if used;
- case sensitivity;
- unique constraints;
- file/image paths;
- admin user access.

## Rollback

Keep SQLite backup untouched until PostgreSQL is verified.

## Not implemented in Phase 42

- no automated conversion script;
- no production cutover;
- no PostgreSQL requirement;
- no Docker/Postgres service setup.
```

Update deployment README/checklist.

Add final review doc:

```txt
docs/deployment/phase-42-deployment-prep-review.md
```

Checklist:

```txt
- production checklist exists;
- storage docs exist;
- queue docs exist;
- migration docs exist;
- admin create command works;
- SQLite backup strategy exists;
- SQLite→PostgreSQL note exists;
- no real deploy automation added.
```

### Acceptance criteria

- `sqlite-to-postgresql-migration.md` exists.
- Docs explain when to migrate.
- Docs include high-level staged migration path.
- Docs include rollback note.
- Docs explicitly say migration implementation is not part of Phase 42.
- Deployment README links the note.
- Phase 42 review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Docs tests written.
- PostgreSQL migration note added.
- Deployment README/checklist updated.
- Phase 42 review doc added.
- Tests/build pass.
- Коммит: `RG-661: Add migration note from SQLite to PostgreSQL`

### Files likely touched

```txt
docs/deployment/sqlite-to-postgresql-migration.md
docs/deployment/phase-42-deployment-prep-review.md
docs/deployment/README.md
docs/deployment/production-environment-checklist.md
tests/Feature/Docs/DeploymentDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 11. Phase 42 Completion Criteria

Phase 42 завершена, когда:

```txt
- RG-654–RG-661 выполнены;
- production environment checklist exists;
- checklist includes APP_ENV=production;
- checklist includes APP_DEBUG=false;
- checklist includes APP_KEY and APP_URL checks;
- checklist warns against demo seeders/credentials in production;
- storage symlink docs exist;
- storage docs include php artisan storage:link;
- queue worker docs exist;
- queue docs do not require Redis;
- migration deploy docs exist;
- migration docs include php artisan migrate --force;
- migration docs warn against migrate:fresh in production;
- admin user creation command exists;
- admin user creation command is tested;
- admin command creates active admin;
- admin command hashes password;
- admin command rejects duplicate email;
- SQLite backup strategy note exists;
- SQLite backup docs include .backup and restore steps;
- SQLite to PostgreSQL migration note exists;
- PostgreSQL note is strategy-only, not implementation;
- deployment README links all deployment docs;
- Phase 42 review doc exists;
- no real production deploy automation added;
- no PostgreSQL migration implementation added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 42

Без отдельной задачи нельзя:

```txt
- добавлять GitHub Actions production deploy;
- добавлять Docker production stack;
- добавлять Nginx/SSL server configs;
- устанавливать Redis как обязательную зависимость;
- реально включать queue workers;
- добавлять PostgreSQL как обязательную БД;
- писать SQLite→PostgreSQL conversion script;
- делать backup automation cron/job;
- добавлять monitoring/alerting;
- добавлять secrets manager integration;
- менять runtime app behavior кроме admin creation command;
- добавлять API endpoints;
- добавлять UI features;
- добавлять React/Vue/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-654 Add production environment checklist
RG-655 Add storage symlink command docs
RG-656 Add queue worker docs
RG-657 Add migration deploy command docs
RG-658 Add admin user creation command
RG-659 Test admin user creation command
RG-660 Add backup strategy note for SQLite
RG-661 Add migration note from SQLite to PostgreSQL
```
---

# 14. Release

После завершения Phase 42:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
composer test:browser
php artisan visual:screenshot all

git checkout -b release/v0.2.23-phase42-deployment-prep
git push -u origin release/v0.2.23-phase42-deployment-prep
```

Если browser/screenshot команды не входят в обязательный local release check, минимум:

```bash
composer test
npm run build
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.23-phase42-deployment-prep -m "RateGuru Phase 42 Deployment Prep"
git push origin v0.2.23-phase42-deployment-prep
```
