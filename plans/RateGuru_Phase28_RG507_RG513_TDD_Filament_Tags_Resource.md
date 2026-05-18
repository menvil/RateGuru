# RateGuru — Phase 28 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 28 — Filament Tags Resource**  
Диапазон задач: **RG-507 → RG-513**  
Основа нумерации: исходный atomic backlog, где Phase 28 начинается с задачи 507 и заканчивается задачей 513.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 28 соответствует исходному блоку:

```txt
Phase 28 — Filament Tags Resource
```

Правильный диапазон Phase 28:

```txt
RG-507 — Create TagResource
RG-508 — Add name column
RG-509 — Add slug column
RG-510 — Add posts_count column
RG-511 — Add create tag form
RG-512 — Add edit tag form
RG-513 — Add delete tag action
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 29 начинается с `RG-514` и делает **Moderation Dashboard**. Поэтому Phase 28 не должна создавать dashboard widgets, moderation tables или report queues.
---

# 2. Цель Phase 28

Phase 28 добавляет Filament-ресурс для управления тегами/таксономией в `/admin`.

После Phase 28 admin/moderator должен уметь:

```txt
- открыть список tags;
- видеть name;
- видеть slug;
- видеть posts_count;
- создать новый tag;
- отредактировать существующий tag;
- удалить tag, если он не используется постами.
```

Это маленькая фаза, но она важна: теги — часть навигации, поиска и фильтрации. Нельзя относиться к ним как к “простому справочнику”, потому что сломанные slug или удалённые используемые tags будут ломать feed/search/category UX.
---

# 3. Scope Phase 28

## Входит

```txt
- TagResource;
- ListTags page;
- CreateTag page/form;
- EditTag page/form;
- name column;
- slug column;
- posts_count column;
- create form validation;
- edit form validation;
- safe delete action;
- tests/smoke tests for resource access, forms and delete guard.
```

## Не входит

```txt
- public tags page;
- tag autocomplete in upload form;
- tag merge tool;
- tag aliases/synonyms;
- tag moderation queue;
- bulk delete tags;
- bulk merge tags;
- tag popularity analytics;
- dashboard widgets;
- API endpoints.
```

Если нужны merge/aliases — это отдельная taxonomy phase, не Phase 28.
---

# 4. Critical Decisions

## 4.1. TagResource is taxonomy admin, not moderation

Phase 28 не должна смешивать tags с moderation.

Правильно:

```txt
TagResource:
- manage name;
- manage slug;
- see posts_count;
- create/edit/delete safe tags.
```

Неправильно:

```txt
- approve tags;
- report tags;
- hide tags;
- moderate posts through tags;
- dashboard widgets.
```

## 4.2. Slug должен быть стабильным и уникальным

Slug используется/будет использоваться для:

```txt
- URL query filters;
- public tag pages;
- SEO-friendly links;
- internal references.
```

Правила:

```txt
- slug required;
- slug unique;
- slug lowercase;
- slug URL-safe;
- slug auto-generated from name on create;
- slug editable by admin/moderator, но с validation.
```

Если проект уже имеет `Tag::generateSlug()` или mutator — использовать его.  
Если нет — использовать `Str::slug()`.

## 4.3. Можно ли редактировать slug?

Да, но осторожно.

Phase 28 позволяет edit slug, потому что backlog требует edit tag form. Но форма должна ясно валидировать uniqueness.

Не нужно добавлять redirect history для старых slug в этой фазе.  
Если позже появятся public tag pages, slug rename history можно вынести отдельно.

## 4.4. posts_count should use relationship count

Не добавлять denormalized `tags.posts_count` column, если её нет.

Правильно:

```php
Tag::query()->withCount('posts')
```

Column:

```txt
posts_count
```

Это read-side count из relationship.

## 4.5. Delete tag must be safe

Главное правило:

```txt
Tag нельзя удалить, если он привязан к постам.
```

Причина:

```txt
- silent detach ломает классификацию постов;
- cascade delete может удалить связи без audit trail;
- удаление используемого tag — не простая операция, а taxonomy migration.
```

Delete action должна:

```txt
- показываться только admin/moderator, если принято;
- требовать confirmation;
- блокировать удаление при posts_count > 0;
- показывать error/notification вместо silent failure;
- удалять только unused tag.
```

Если нужно удалять используемые tags, сначала нужна отдельная `MergeTagAction` или `DetachTagFromPostsAction`.

## 4.6. Кто может управлять tags

Так как Phase 23 дала `/admin` доступ admin/moderator, Phase 28 может разрешить TagResource для:

```txt
admin
moderator
```

Но delete tag можно сделать строже:

```txt
create/edit: admin/moderator
delete: admin only
```

Почему:

```txt
- удаление taxonomy имеет долгосрочные последствия;
- moderator может модерировать контент, но taxonomy cleanup лучше оставить admin.
```
---

# 5. Architecture Rules

## 5.1. Resource location

Использовать conventions из Phase 24–27.

Likely paths:

```txt
app/Filament/Resources/TagResource.php
app/Filament/Resources/TagResource/Pages/ListTags.php
app/Filament/Resources/TagResource/Pages/CreateTag.php
app/Filament/Resources/TagResource/Pages/EditTag.php
```

## 5.2. Use existing Tag model and relations

Expected model:

```txt
App\Models\Tag
```

Expected relation:

```php
Tag has many/belongsToMany posts
Post belongsToMany tags
```

Do not invent a second taxonomy model.

## 5.3. No direct unsafe delete

Built-in Filament `DeleteAction` is acceptable only if wrapped/guarded:

```txt
- visible admin-only;
- before/delete guard checks posts_count;
- confirmation required.
```

If Filament built-in action cannot cleanly block used tags, create custom action.

## 5.4. No public UI changes

Do not touch:

```txt
SearchBar
CategoryTabs
UploadPostForm
PostFeed
PostCard
```

unless tests from existing tag functionality break.

## 5.5. Tests should not over-couple to Filament HTML

Use:

```txt
- resource URL smoke tests;
- Livewire/Filament test helpers for form create/edit;
- database assertions;
- action tests for delete guard.
```

Avoid fragile exact HTML snapshots.
---

# 6. GitFlow для Phase 28

## Base branch

Все задачи Phase 28 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-507-create-tag-resource
feature/RG-511-add-create-tag-form
feature/RG-513-add-delete-tag-action
```

## Commit format

```txt
RG-507: Create TagResource
RG-511: Add create tag form
RG-513: Add delete tag action
```

## Release branch

После выполнения `RG-507`–`RG-513`:

```txt
release/v0.2.9-phase28-filament-tags-resource
```

## Tag

После merge release branch в `main`:

```txt
v0.2.9-phase28-filament-tags-resource
```
---

# 7. TDD Rules for Phase 28

## Для Resource

Тестировать:

```txt
- admin can access TagResource index;
- moderator can access TagResource index;
- resource uses Tag model.
```

## Для columns

Тестировать:

```txt
- name visible;
- slug visible;
- posts_count visible.
```

## Для forms

Тестировать:

```txt
- admin/moderator can create tag;
- name required;
- slug required;
- slug unique;
- slug auto-generated from name on create if empty;
- admin/moderator can edit name/slug;
- edit unique slug ignores current record.
```

## Для delete

Тестировать:

```txt
- admin can delete unused tag;
- admin cannot delete tag with posts;
- moderator cannot delete tag if delete is admin-only;
- deletion is confirmed/safe;
- no posts/tags pivot data is silently destroyed for used tags.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Tags / Tests
Type: Test / Feature / Resource / Table / Form / Action
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
- Slug validation работает
- Delete не ломает связанные posts
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 28 Atomic Tasks
---

## RG-507 — Create TagResource

**Area:** Admin / Filament  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-507-create-tag-resource`  
**Base branch:** develop
**Depends on:** RG-506

### Goal

Создать Filament `TagResource` для модели `Tag`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access tag resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});
```

Moderator access test:

```php
it('allows moderator to access tag resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});
```

Тест должен упасть до создания resource.

### Implementation

Создать resource:

```bash
php artisan make:filament-resource Tag
```

Минимальный resource:

```php
protected static ?string $model = Tag::class;
```

Pages:

```txt
ListTags
CreateTag
EditTag
```

Set navigation group:

```txt
Taxonomy
```

### Acceptance criteria

- `TagResource` exists.
- Resource uses `Tag::class`.
- Admin can access index.
- Moderator can access index.
- Resource appears under Taxonomy navigation group.
- ListTags page exists.
- CreateTag/EditTag pages may exist but forms are completed in RG-511/RG-512.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Resource создан.
- Tests pass.
- Коммит: `RG-507: Create TagResource`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
app/Filament/Resources/TagResource/Pages/ListTags.php
app/Filament/Resources/TagResource/Pages/CreateTag.php
app/Filament/Resources/TagResource/Pages/EditTag.php
tests/Feature/Filament/TagResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-508 — Add Name Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-508-add-name-column`  
**Base branch:** develop
**Depends on:** RG-507

### Goal

Добавить name column в TagResource table.

### TDD step

Feature test:

```php
it('renders tag name in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create([
        'name' => 'Italian',
    ]);

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Italian');
});
```

### Implementation

Add column:

```php
TextColumn::make('name')
    ->label('Name')
    ->searchable()
    ->sortable()
```

### Acceptance criteria

- Tag name visible.
- Searchable.
- Sortable.
- Handles long names reasonably.
- Test passes.

### Definition of Done

- Тест написан.
- Name column добавлен.
- Test passes.
- Коммит: `RG-508: Add name column`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
tests/Feature/Filament/TagResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-509 — Add Slug Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-509-add-slug-column`  
**Base branch:** develop
**Depends on:** RG-508

### Goal

Добавить slug column в TagResource table.

### TDD step

Feature test:

```php
it('renders tag slug in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    Tag::factory()->create([
        'name' => 'Asian Food',
        'slug' => 'asian-food',
    ]);

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk()
        ->assertSee('asian-food');
});
```

### Implementation

Add column:

```php
TextColumn::make('slug')
    ->label('Slug')
    ->searchable()
    ->sortable()
    ->copyable()
```

Optional:

```php
->fontFamily('mono')
```

### Acceptance criteria

- Slug visible.
- Searchable.
- Sortable.
- Copyable if Filament supports it.
- Visually distinct from name.
- Test passes.

### Definition of Done

- Тест написан.
- Slug column добавлен.
- Test passes.
- Коммит: `RG-509: Add slug column`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
tests/Feature/Filament/TagResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-510 — Add Posts_Count Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-510-add-posts-count-column`  
**Base branch:** develop
**Depends on:** RG-509

### Goal

Добавить posts_count column через relationship count.

### TDD step

Feature/table test:

```php
it('renders posts count in tag resource table', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $posts = Post::factory()->count(2)->published()->create();

    foreach ($posts as $post) {
        $post->tags()->attach($tag);
    }

    $this->actingAs($admin)
        ->get(TagResource::getUrl('index'))
        ->assertOk()
        ->assertSee('2');
});
```

Adapt relation name if different.

### Implementation

Override query:

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

Do not add a physical `posts_count` column to `tags`.

### Acceptance criteria

- posts_count visible.
- Count comes from `withCount('posts')`.
- Sortable.
- No new `tags.posts_count` DB column.
- Test passes.

### Definition of Done

- Тест написан.
- withCount добавлен.
- posts_count column добавлен.
- Test passes.
- Коммит: `RG-510: Add posts_count column`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
tests/Feature/Filament/TagResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-511 — Add Create Tag Form

**Area:** Admin / Filament / Form  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-511-add-create-tag-form`  
**Base branch:** develop
**Depends on:** RG-510

### Goal

Добавить create tag form с validation и slug handling.

### TDD step

Filament create form test:

```php
it('allows admin to create tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    livewire(CreateTag::class)
        ->actingAs($admin)
        ->fillForm([
            'name' => 'Mexican Food',
            'slug' => 'mexican-food',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Mexican Food',
        'slug' => 'mexican-food',
    ]);
});
```

Auto slug test:

```php
it('auto generates slug from name when creating tag', function () {
    $admin = User::factory()->admin()->create();

    livewire(CreateTag::class)
        ->actingAs($admin)
        ->fillForm([
            'name' => 'Street Food',
            'slug' => '',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tags', [
        'name' => 'Street Food',
        'slug' => 'street-food',
    ]);
});
```

Validation tests:

```txt
name is required
slug is unique
slug is URL-safe
```

### Implementation

In `TagResource::form()`:

```php
TextInput::make('name')
    ->label('Name')
    ->required()
    ->maxLength(80)
    ->live(onBlur: true)
    ->afterStateUpdated(function (string $operation, $state, Set $set): void {
        if ($operation !== 'create') {
            return;
        }

        $set('slug', Str::slug($state));
    })

TextInput::make('slug')
    ->label('Slug')
    ->maxLength(100)
    ->unique(ignoreRecord: true)
    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
```

Before create, normalize slug:

```php
protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['slug'] = Str::slug($data['slug'] ?: $data['name']);

    return $data;
}
```

### Acceptance criteria

- CreateTag page/form works.
- Name required.
- Slug required or auto-generated.
- Slug unique.
- Slug normalized to URL-safe lowercase.
- Admin can create tag.
- Moderator can create tag if allowed by resource access.
- Tests pass.

### Definition of Done

- Tests written.
- Create form added.
- Slug generation/validation added.
- Tests pass.
- Коммит: `RG-511: Add create tag form`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
app/Filament/Resources/TagResource/Pages/CreateTag.php
tests/Feature/Filament/TagResourceFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-512 — Add Edit Tag Form

**Area:** Admin / Filament / Form  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-512-add-edit-tag-form`  
**Base branch:** develop
**Depends on:** RG-511

### Goal

Добавить edit tag form с validation.

### TDD step

Filament edit form test:

```php
it('allows admin to edit tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    livewire(EditTag::class, ['record' => $tag->getRouteKey()])
        ->actingAs($admin)
        ->fillForm([
            'name' => 'New Name',
            'slug' => 'new-name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('New Name');
    expect($tag->fresh()->slug)->toBe('new-name');
});
```

Unique slug ignore current record:

```php
it('allows keeping current slug when editing tag', function () {
    ...
});
```

Duplicate slug test:

```php
it('rejects duplicate slug when editing tag', function () {
    ...
});
```

### Implementation

Use same form fields as create.

In EditTag page:

```php
protected function mutateFormDataBeforeSave(array $data): array
{
    $data['slug'] = Str::slug($data['slug'] ?: $data['name']);

    return $data;
}
```

Validation:

```php
TextInput::make('slug')
    ->required()
    ->unique(ignoreRecord: true)
```

Do not auto-regenerate slug on every name edit unless slug field is empty.  
Otherwise admin can accidentally break existing links just by changing name.

Recommended:

```txt
- on edit, changing name does not overwrite slug automatically;
- slug can be edited explicitly.
```

### Acceptance criteria

- EditTag page/form works.
- Admin can edit name.
- Admin can edit slug.
- Slug unique validation ignores current record.
- Duplicate slug rejected.
- Slug normalized to URL-safe lowercase.
- Name edit does not silently overwrite existing slug.
- Tests pass.

### Definition of Done

- Tests written.
- Edit form added.
- Slug validation works.
- Tests pass.
- Коммит: `RG-512: Add edit tag form`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
app/Filament/Resources/TagResource/Pages/EditTag.php
tests/Feature/Filament/TagResourceFormTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-513 — Add Delete Tag Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-513-add-delete-tag-action`  
**Base branch:** develop
**Depends on:** RG-512

### Goal

Добавить safe delete action для tags.

### TDD step

Unused tag delete test:

```php
it('allows admin to delete unused tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Unused',
        'slug' => 'unused',
    ]);

    livewire(ListTags::class)
        ->actingAs($admin)
        ->callTableAction('delete', $tag);

    $this->assertDatabaseMissing('tags', [
        'id' => $tag->id,
    ]);
});
```

Used tag blocked test:

```php
it('does not allow deleting tag attached to posts', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Used',
        'slug' => 'used',
    ]);

    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    livewire(ListTags::class)
        ->actingAs($admin)
        ->callTableAction('delete', $tag);

    $this->assertDatabaseHas('tags', [
        'id' => $tag->id,
    ]);

    expect($post->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});
```

Moderator delete hidden/blocked test:

```php
it('does not show delete tag action to moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $tag = Tag::factory()->create();

    livewire(ListTags::class)
        ->actingAs($moderator)
        ->assertTableActionHidden('delete', $tag);
});
```

### Implementation

Do not use raw `DeleteAction` without guard.

Preferred custom action:

```php
Action::make('delete')
    ->label('Delete')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
    ->requiresConfirmation()
    ->action(function (Tag $record): void {
        if ($record->posts()->exists()) {
            Notification::make()
                ->title('Tag is used by posts')
                ->body('Detach or merge this tag before deleting it.')
                ->danger()
                ->send();

            return;
        }

        $record->delete();

        Notification::make()
            ->title('Tag deleted')
            ->success()
            ->send();
    })
```

If project wants strict action pattern everywhere, create small backend action inside this task:

```txt
DeleteTagAction
```

Action responsibility:

```txt
- admin-only;
- block if tag has posts;
- delete unused tag;
- never detach silently.
```

### Acceptance criteria

- Delete action visible only to admin.
- Hidden from moderator.
- Requires confirmation.
- Unused tag can be deleted.
- Used tag cannot be deleted.
- Used tag remains attached to posts.
- No silent detach.
- No cascade destruction of taxonomy relationships.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- Safe delete action added.
- Used-tag guard implemented.
- Tests/build pass.
- Коммит: `RG-513: Add delete tag action`

### Files likely touched

```txt
app/Filament/Resources/TagResource.php
app/Actions/Tags/DeleteTagAction.php
app/Exceptions/Tags/CannotDeleteTagException.php
tests/Feature/Filament/TagResourceActionsTest.php
tests/Feature/Actions/DeleteTagActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 28 Completion Criteria

Phase 28 завершена, когда:

```txt
- RG-507–RG-513 выполнены;
- TagResource exists;
- admin can access TagResource;
- moderator can access TagResource;
- table shows name;
- table shows slug;
- table shows posts_count from relationship count;
- create tag form works;
- create form validates name;
- create form validates/auto-generates slug;
- create form rejects duplicate slug;
- edit tag form works;
- edit form rejects duplicate slug except current record;
- delete action exists;
- delete is admin-only;
- delete requires confirmation;
- unused tag can be deleted;
- used tag cannot be deleted;
- deleting tag does not silently detach posts;
- no ModerationDashboard was created;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 28

Без отдельной задачи нельзя:

```txt
- создавать ModerationDashboard;
- создавать tag public pages;
- добавлять tag autocomplete в upload form;
- добавлять tag merge tool;
- добавлять tag aliases/synonyms;
- добавлять bulk delete tags;
- добавлять bulk merge tags;
- добавлять tag analytics;
- менять SearchBar/CategoryTabs;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-507 Create TagResource
RG-508 Add name column
RG-509 Add slug column
RG-510 Add posts_count column
RG-511 Add create tag form
RG-512 Add edit tag form
RG-513 Add delete tag action
```
---

# 13. Release

После завершения Phase 28:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.9-phase28-filament-tags-resource
git push -u origin release/v0.2.9-phase28-filament-tags-resource
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.9-phase28-filament-tags-resource -m "RateGuru Phase 28 Filament Tags Resource"
git push origin v0.2.9-phase28-filament-tags-resource
```
