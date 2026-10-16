# Content Studio — Phase 0: технический аудит RateGuru

Аналитический этап перед разработкой Content Studio. Документ фиксирует фактическое
состояние ветки `develop` (коммит `bdfbb3e2`, 16 октября 2026): какие существующие
модели, Actions, сервисы, политики, механизмы публикации, модерации, обработки
изображений, голосования и фоновых заданий переиспользуются, что требует точечного
расширения и что придётся создать. Код приложения, миграции, интерфейс, зависимости и
инфраструктура в рамках этапа не менялись.

Как читать документ:

- Ссылка `path/File.php:12-34` указывает на строки файла в `develop` на момент аудита.
  Номера строк сместятся при правках; имена классов и методов остаются ориентиром.
- «Отсутствует» означает: поиск по `app/`, `routes/`, `database/`, `config/`,
  `infrastructure/`, `tests/` не нашёл реализации. Что проверить не удалось,
  вынесено в конец соответствующего раздела и в раздел H.
- Решения из постановки (персоны как роль в `users`, ручное создание контента,
  исторические даты через доверенный внутренний механизм, переиспользование
  `MediaAsset`, отказ от универсальной системы ИИ, отложенная география) приняты как
  данность и не пересматриваются.
- Версии: `laravel/framework v13.35.0`, `filament/filament v5.10.1`,
  `livewire/livewire v4.4.7` (`composer.lock`). Основная СУБД на staging/production —
  PostgreSQL 18; SQLite и MariaDB — цели совместимости в CI
  (`docs/architecture/database-support.md:5-13`).

Разделы A–H соответствуют требуемой структуре. Приложение в конце отвечает по пунктам
на вопросы аудита по областям (пользователи, публикации, комментарии, голоса, медиа,
фоновые задания, ИИ, география, админка), чтобы постановка задач на следующие этапы не
требовала повторного изучения кода.

---

## A. Executive Summary

**Архитектура готова к Content Studio без переписывания доменного слоя.** Каждая
доменная запись идёт через Action с единым контрактом «заблокировать актора →
заблокировать цель → перепроверить состояние на свежих строках → записать»
(`app/Models/Concerns/LocksActorForWrite.php:18-27`, порядок блокировок описан в
`docs/architecture/user-lifecycle.md`). Три точки входа, которые Content Studio обязан
вызывать, существуют и покрыты тестами: `CreatePostAction::handle(User, CreatePostData)`
(`app/Actions/Posts/CreatePostAction.php:44`), `AddCommentAction::handle(?User, Post,
string, ?Comment)` (`app/Actions/Comments/AddCommentAction.php:35`) с фасадом
`CommentReplyService`, и три действия голосования `VotePostAction`, `VoteCommentAction`,
`VoteRatingOptionAction`. Медиа-конвейер `ImageUploadStorer → MediaAssetCreator →
GenerateMediaVariantsJob` не зависит от `Post` и годится для сохранения фотографии на
этапе черновика. Инфраструктура фоновых заданий уже развёрнута: Redis-очередь с одним
воркером на цель и cron `schedule:run` каждую минуту.

**Чего нет совсем.** Ни одной сущности для планов, запланированных действий, настроек
персон и правил ответов. Ни один Action не принимает временные метки, готовый
`MediaAsset` или флаг подавления уведомлений. Нет административного пути создания
пользователя (у `UserResource` только страницы `index` и `edit`). Ни один Job не
использует `ShouldBeUnique`, `WithoutOverlapping`, `Bus::chain` или `onQueue()`.
Интеграция с OpenAI существует только в форме движка переводов с контрактом
`translateBatch()`, текстовым входом и схемой учёта, привязанной к локалям.

**Критические несовместимости** (подробно в разделе D):

- Код трактует любую роль, отличную от `UserRole::User`, как персонал
  (`CreatePostAction.php:88-89`, `ListPosts.php:553`, `ListUsers.php:149`), а
  `ListUsers::roleLabel()` — исчерпывающий `match` без ветки по умолчанию: одна персона в
  базе роняет список пользователей (`ListUsers.php:161-168`). `Rule::enum(UserRole::class)`
  в `EditUser` автоматически сделает `persona` допустимым значением (`EditUser.php:222`).
- Гейт входа `canAuthenticate()` статусный (запрещён только tombstone), поэтому персона с
  паролем, OAuth-идентичностью или провайдерски подтверждённым email войдёт в систему.
- `VotePostAction` — переключатель: любой повторный вызов удаляет существующий голос
  (`VotePostAction.php:86-94`); повторная доставка задания снимет голос персоны.
- `MediaReferenceChecker` знает только `posts.image_asset_id` и `users.avatar_asset_id`:
  ассет черновика будет помечен аудитом как `active_unreferenced_asset`, а кнопка
  Release в Media diagnostics мягко удалит его.
- Уведомления подписчикам и автору поста создаются в момент исполнения независимо от
  исторической даты; `updated_at` перезаписывается при первом же взаимодействии.
- Глубина обсуждения — ровно один уровень ответов; дерево глубже отвергается.
- Лимиты скорости по пользователю (загрузка 5/600 с, комментарии 10/60 с, голоса
  60/60 с) применяются к персонам как к обычным пользователям.

**Рекомендуемая последовательность** (раздел F): (1) роль `Persona` и закрытие проверок
персонала и входа; (2) настройки персоны и раздел Personas с реальным созданием и
редактированием — первый функциональный этап; (3) доверенные расширения существующих
Actions: исторические даты, готовый ассет, флаги уведомлений, идемпотентный режим
голосов, ссылка черновика в `MediaReferenceChecker`; (4) планы публикаций и редактор;
(5) очередь действий и исполнитель; (6) подготовленное обсуждение и зависимости; далее
правила ответов, интеграция OpenAI и общий анализ SFW/NSFW.

---

## B. Existing Components Inventory

| Область | Существующий файл/класс | Что делает | Как переиспользуем |
|---|---|---|---|
| Пользователи | `app/Enums/UserRole.php:5-10` | Три роли: `User`, `Moderator`, `Admin`; методов нет | Добавляем `case Persona = 'persona'`; все остальные места перечислены в разделе C |
| Пользователи | `app/Enums/UserStatus.php:34-116` | Единственный источник capability-правил; все участия только для `Active`; `canAuthenticate()` запрещает только `Deleted` (`:103-106`) | Персона живёт со статусом `Active`; статус не трогаем, роль — отдельное измерение (`:9-12`) |
| Пользователи | `app/Models/User.php` | `#[Fillable]` включает `role`, `status`, `trust_level` (`:36`); `booted()` ставит `trust_level = 10` новым аккаунтам (`:50-57`); `canAccessPanel()` требует `isAdmin() \|\| isModerator()` (`:325-336`); `notify()` молчит для tombstone (`:197-204`); `avatarAsset()` (`:264-268`) | Персона без изменений не попадает в админку; аватар через `avatar_asset_id`; ролевой предикат входа добавляется рядом с `canAuthenticate()` (`:151-154`) |
| Пользователи | `app/Policies/UserPolicy.php:45-75` | `canSanction`: активный админ управляет любым не-админом; `markTrusted` только для `User` | Админ сможет редактировать и санкционировать персон без изменений политики |
| Пользователи | `app/Actions/Users/UpdateUserAccountAction.php:31-64` | Админская правка name/username/email/role/trust/password под парной блокировкой `LocksAndAuthorizesModerationPair` | База для редактирования персоны; нужны ограничения на пароль и переходы роли |
| Пользователи | `app/Console/Commands/CreateAdminUserCommand.php:62-69` | Единственный не-регистрационный путь создания пользователя (`User::create` с ролью и статусом) | Образец для `CreatePersonaAction`; его allow-list в `tests/Feature/Architecture/UserLifecycleWriteBoundaryTest.php:17-23` |
| Пользователи | `app/Actions/Auth/RegisterSocialUserAction.php:48-64` | Создание аккаунта без пароля (`'password' => null`), `email_verified_at` по подтверждению провайдера | Образец «аккаунт без пароля с подтверждённым email» для персон |
| Пользователи | `app/Actions/Users/GenerateUniqueUsernameAction.php`, `app/Actions/Auth/Concerns/CreatesUserWithUniqueUsername.php` | Генерация уникального `username` с повтором при коллизии | Генерация handle персоны |
| Пользователи | `app/Actions/Profile/AnonymizeUserAccountAction.php:63-89, 186-189` | Tombstone; email вида `deleted-{id}-{uuid}@deleted.invalid` (RFC 2606); сбрасывает роль в `User` (`:83`) | Образец немаршрутизируемого адреса для персон; решение о роли при удалении персоны |
| Пользователи | `app/Actions/Profile/UpdateUserProfileAction.php:39-41, 64-82, 100-105` | Аватар: `ImageUploadStorer` → `MediaAssetCreator(Avatar)` → `users.avatar_asset_id`, soft-delete прежнего, `GenerateMediaVariantsJob` | Пять шагов переносятся в админский Action установки аватара персоны |
| Вход | `app/Actions/Auth/AuthenticateUserAction.php:23-52` | `Auth::attempt` + гейт `canAuthenticate()`; `password = NULL` никогда не совпадает | Персона без пароля не войдёт по паролю; гейт дополняется ролью |
| Вход | `app/Actions/Auth/ResolveSocialLoginAction.php:70-76, 87-115` | OAuth: известная идентичность, совпадение email (claim при подтверждённом провайдером адресе), регистрация | Все три ветки проходят `canAuthenticate()` — единые точки для ролевого гейта |
| Вход | `app/Actions/Auth/ClaimAccountWithVerifiedEmailAction.php`, `LinkSocialAccountAction.php`, `ResetPasswordAction.php`, `SendPasswordResetLinkAction.php:10-20` | Захват аккаунта по подтверждённому email, привязка провайдера, сброс пароля | Точки, где персона должна быть отвергнута |
| Вход | `app/Support/Auth/RememberSessionGenerationOnLogin.php:9-25`, `app/Providers/AppServiceProvider.php:75` | Единственный слушатель `Illuminate\Auth\Events\Login` для всех способов входа | Готовая точка для будущей истории успешных входов |
| Вход | `app/Http/Middleware/EnsureAccountIsNotTombstoned.php:24-38` | На каждом запросе завершает сессию, если `! canAuthenticate()` | Делается ролевым, чтобы живая сессия не пережила перевод аккаунта в персону |
| Публикации | `app/Actions/Posts/CreatePostAction.php:44-174` | Единственный путь создания поста: policy `create`, лимит `upload`, файл до транзакции, `lockActor`, статус по доверию и email/роли (`:88-91`), `MediaAssetCreator`, `Post::create` без `created_at` (`:97-106`), job подписчикам при `Published` (`:136-147`), варианты `afterCommit()` (`:149-171`) | Вызывается исполнителем без изменений логики; расширяется DTO |
| Публикации | `app/Data/Posts/CreatePostData.php:8-28` | Readonly DTO: title, description, sourceUrl, tagIds, `?UploadedFile $image`, `ImageInputSource`, categoryId, authorAnswerOptionIds; единственный конструктор в `app/Livewire/Feed/UploadPostForm.php:106-115` | Добавляются необязательные доверенные поля (даты, `imageAssetId`, флаг уведомлений) |
| Публикации | `app/Models/Post.php` | `$guarded = []` (`:33`); `scopePublished` (`:48-51`); `canReceiveVotes/Comments/RatingVotes` = не trashed и `Published` (`:89-112`); наблюдателей нет | Явно переданные `created_at`/`published_at` сохраняются Eloquent без изменений модели; предикаты доступности для отмены зависимостей |
| Публикации | `app/Actions/Moderation/ApprovePostAction.php:41-45, 88-97` | `published_at = $locked->published_at ?? now()`; job подписчикам | Историческая `published_at` сохраняется при одобрении; персоны должны публиковаться сразу, чтобы одобрение не требовалось |
| Публикации | `app/Actions/Ranking/RecalculatePostScoreAction.php:14-31`, `app/Support/Ranking/HotScoreCalculator.php:17-35` | Hot score от `posts.created_at` и счётчиков; при создании не вычисляется (`hot_score` остаётся 0) | Исполнитель вызывает после создания исторического поста |
| Публикации | `app/Actions/Counters/RecalculatePostCountersAction.php:13-48` | Полный пересчёт голосов под блокировкой строки поста | Без изменений |
| Публикации | `app/Queries/Feed/FeedQuery.php:82-87` | «Новое» упорядочено по `published_at DESC, created_at DESC, id DESC` | Исторический пост занимает хронологическое место автоматически |
| Публикации | `app/Support/Cache/PostListCacheManager.php:10-18` | Заглушка: `remember()` вызывает callback, `invalidateForPost()` пуст; вызовы закреплены тестом | Вызовы сохраняем, кеша нет |
| Публикации | `app/Jobs/NotifyFollowersAboutNewPostJob.php:15-19`, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php:42-66` | Уведомление подписчикам (database), дедупликация по существующим уведомлениям, проверка только статуса | Нужен флаг подавления для исторического контента |
| Публикации | `app/Policies/PostPolicy.php:11-14, 73-77` | `create` = `canCreateContent()`; `vote` = `canVote()` и не свой пост | Персона-автор не голосует за свой пост — проверка остаётся |
| Комментарии | `app/Actions/Comments/AddCommentAction.php:35-161` | Лимит `comment` 10/60 с, тело ≤ 1000, родитель только верхнего уровня (`:75`, `:111`), перечитывание родителя под блокировкой (`:107-119`), `Comment::create` без дат (`:121-127`), пересчёт `comments_count` и hot score (`:129-130`), `PostCommentedNotification` автору поста (`:135-158`) | Вызывается исполнителем; добавляются `$createdAt` и флаг уведомления |
| Комментарии | `app/Services/Comments/CommentReplyService.php:19-56` | `canStartReply(postId, commentId)` и `createReply(...)`; цель ответа: тот же пост, `Visible`, `parent_id IS NULL`, не удалён | Предикат «родитель доступен» для исполнителя |
| Комментарии | `app/Models/Comment.php:24, 97-108` | `$guarded = []`; `canReceiveVotes()`; `isStructuralTombstone()` | Исторические даты через `create`; предикаты отмены |
| Комментарии | `app/Queries/Comments/CommentListQuery.php:26-29, 40-41` | Корни по `created_at DESC, id DESC`; ответы `created_at ASC, id ASC` | Историческая дата ставит комментарий в правильное место |
| Комментарии | `app/Actions/Comments/HideCommentAction.php`, `DeleteCommentAction.php` | Скрытие/удаление не трогает детей; корень становится структурным tombstone | Модерация сохраняется; зависимые действия отменяет исполнитель по предикатам |
| Голоса | `app/Actions/Votes/VotePostAction.php:30-103` | Переключатель: существующий голос удаляется, иначе создаётся (`:86-94`); пересчёт счётчиков и hot score в транзакции | Нужен режим «обеспечить голос» |
| Голоса | `app/Actions/Votes/VoteCommentAction.php:27-116` | Тот же тип → удалить, другой → обновить (`:100-112`); проверка «не свой комментарий» (`:41-43`, `:89-92`) | Нужен режим «обеспечить голос» |
| Голоса | `app/Actions/Rating/VoteRatingOptionAction.php:27-115` | `upsert` по `(user_id, post_id, rating_group_id)`; повтор с тем же вариантом — без изменений; `created_at` сохраняется при конфликте (`:96-107`) | Идемпотентен уже сейчас; `created_at => now()` параметризуется |
| Голоса | `app/Models/PostVote.php:15`, `CommentVote.php:15`, `RatingVote.php:13` | `PostVote` `$guarded = []`; `CommentVote` `$fillable` без `created_at`; `RatingVote` `$fillable = ['rating_option_id']` | Для исторических дат `CommentVote` требует `forceFill()` |
| Голоса | Миграции `2026_05_14_185608...:16`, `2026_05_26_000004...:18`, `2026_06_05_000003...:26` | Уникальные индексы `(post_id,user_id)`, `(comment_id,user_id)`, `(user_id,post_id,rating_group_id)`; все FK `restrictOnDelete` | Страховка от дубликатов при повторной доставке |
| Голоса | `app/Support/AbuseGuards/ActionRateLimiter.php:10-24`, `config/rate_limits.php` | Ключ `rate-limit:{action}:user:{id}`, срабатывает до транзакции; голоса 60/60 с общим ведром | Пейсинг исполнителя или отдельный ключ (вопрос H) |
| Медиа | `app/Support/Media/ImageUploadStorer.php:27-37` | `UploadedFile` → `ImageIngestor::ingest` → `MediaStorage::storeNormalized` → `StoredMedia` | Сохранение фотографии черновика тем же вызовом с `MediaStoreRequest::forPostImage($personaId)` |
| Медиа | `app/Services/Media/ImageIngestor.php`, `GdImageIngestor.php`, `ImageIngestPolicy.php`, `config/uploads.php` | Проверка формата по содержимому, лимиты (5120 KB, 6000×6000, 16 MP), EXIF-поворот, перекодирование, очистка метаданных | Без изменений; единственная граница для всех пользовательских изображений |
| Медиа | `app/Support/Media/MediaAssetCreator.php:23-50` | Строка `media_assets` со `status => Ready`, `visibility => Public`, `owner_user_id` | Создание ассета черновика; точка для будущего SFW/NSFW |
| Медиа | `app/Services/Media/MediaStoreRequest.php:23-45` | `forPostImage(?int $ownerUserId)`, `forAvatar(int $ownerUserId)` | Без изменений |
| Медиа | `app/Jobs/GenerateMediaVariantsJob.php:23-32`, `app/Services/Media/MediaVariantGenerator.php:40-99` | Генерация недостающих вариантов; пропуск готовых; требует `status = Ready` и raster MIME | Диспетчеризация при сохранении черновика; повторная при публикации — дешёвый no-op |
| Медиа | `app/Services/Media/MediaReferenceChecker.php:24-52` | «Ассет используется» = `posts.image_asset_id` (с trashed) или `users.avatar_asset_id` | Расширяется ссылкой из плана |
| Медиа | `app/Services/Media/MediaLifecycleService.php:46-78, 119-196, 224-249` | Purge только soft-удалённых и просроченных (7 дней) и не используемых; `releaseUnreferenced()` мягко удаляет неиспользуемые; блокировка `Cache::store('database')` | Активный ассет не удаляется автоматически; риск только через Release |
| Медиа | `app/Services/Media/MediaAuditService.php:59-88`, `app/Enums/MediaAuditIssueType.php`, `app/Filament/Pages/MediaDiagnosticsPage.php:632-636` | Активный неиспользуемый ассет → issue `active_unreferenced_asset` (Warning) с кнопкой Release | После расширения чекера черновики перестают быть ложными срабатываниями |
| Медиа | `app/Actions/Import/StoreImportedImageAction.php:25-112, 164-171` | URL → временный `UploadedFile` (0600, лимит байт, allowlist MIME); `cleanup()` за вызывающим | Источник «по URL» для редактора публикации без новых механизмов |
| Медиа | `app/Services/Media/MediaUrlResolver.php`, `FilesystemMediaUrlResolver.php` | URL только для `MediaVisibility::Public` | Готовый гейт показа для будущего «ожидает проверки» |
| Фон | `config/queue.php:16`, `infrastructure/templates/environment/*.env.example`, `infrastructure/config/environment-contract.json` (`QUEUE_CONNECTION`) | По умолчанию `database`, локально `sync`; на всех целях `QUEUE_CONNECTION=redis` | Исполнитель работает на существующем Redis-воркере |
| Фон | `infrastructure/config/supervisor/rateguru-staging-queue.conf:6,9`, `infrastructure/scripts/install-bootstrap-services:1091`, `infrastructure/config/deployment-targets.json` | Один процесс `queue:work redis --queue=<цель> --sleep=3 --tries=3 --timeout=120 --max-time=3600 --max-jobs=1000`; имена очередей `rateguru-staging`, `rateguru-tits-guru` | Диспетчеризуем в очередь по умолчанию цели; отдельная очередь — инфраструктурная задача |
| Фон | `infrastructure/config/cron/rateguru-staging-scheduler:4`, `install-bootstrap-services:1147`, `routes/console.php:16-38` | cron `schedule:run` каждую минуту; пять ежедневных команд с `withoutOverlapping()` | Добавляется ежеминутная команда диспетчера |
| Фон | `infrastructure/scripts/deploy:450-562` | `queue:restart` обязателен при деплое | Без изменений |
| Фон | `config/cache.php:18`, `database/migrations/0001_01_01_000001_create_cache_table.php` | Кеш по умолчанию `database`; таблица `cache_locks` существует | Блокировки исполнителя по образцу медиа |
| Фон | `app/Services/Media/MediaVariantWriter.php:114-130`, `MediaLifecycleService.php:266-275`, `app/Jobs/RunMediaAuditJob.php:44-55` | Атомарные блокировки `Cache::store('database')->getStore()->lock(...)`, неблокирующий `get()` + `finally release()` | Шаблон блокировки «одно действие — один исполнитель» |
| Фон | `app/Support/Translations/Generation/ProjectTranslationGenerationStore.php:235-257`, `app/Jobs/Translations/GenerateProjectTranslationChunkJob.php:36-49, 73-77, 164-179` | Claim «только queued можно забрать» под блокировкой; `tries = 1`, `timeout = 75` < `retry_after 90` < воркер 120; `failed()` помечает состояние | Прямой образец exactly-once для исполнителя действий |
| Фон | `app/Models/Concerns/LocksActorForWrite.php`, `LocksUsersInOrder.php`, `app/Services/Posts/PostRetentionPurgeService.php:49-90` | `lockForUpdate` + перепроверка состояния как compare-and-set | Claim строки действия под блокировкой |
| Фон | `app/Actions/Posts/TogglePostSaveAction.php:87-100`, `CreatesUserWithUniqueUsername.php:37-58` | Уникальное ограничение как идемпотентный барьер (`UniqueConstraintViolationException`) | Страховка для голосов |
| Фон | `app/Models/FailedJob.php`, `app/Services/Media/FailedMediaJobReader.php` | Чтение `failed_jobs` для медиа-заданий; повтор — свежая диспетчеризация, не `queue:retry` | Тот же подход для Action queue (повтор — бизнес-решение администратора) |
| Фон | `docs/architecture/http-and-database-boundaries.md`, `tools/phpstan/src/Rules/*` | Запреты `DB::table`, записей вне Actions, прямых capability-вызовов в презентации (`NoDirectControllerPermissionCheckRule.php:22-35`) | Новые методы вроде `isPersona()`/`canSignIn()` добавляются в список правила |
| ИИ | `app/Support/TranslationEngine/Providers/OpenAiTranslationProvider.php:110-116, 194-217, 294-310, 357-367` | Responses API через `Http`: `withToken`, `acceptJson`, `asJson`, `withoutRedirecting`, таймауты 5/45 с, без повторов; строгий `json_schema`; один `input_text`; классификация ошибок по статусу и транспорту | Копируем рецепт транспорта, валидации настроек, классификации ошибок и разбора ответа в отдельный клиент Content Studio |
| ИИ | `app/Support/TranslationEngine/Contracts/TranslationProvider.php:27-47`, `TranslationService.php:62`, `TranslationPromptBuilder.php` | Контракт `translateBatch(TranslationBatchRequest)`, лимиты по символам, промпт перевода | Не используем напрямую: форма «перевод пакета» |
| ИИ | `app/Models/TranslationAiProviderCall.php`, `database/migrations/2026_10_11_100000_create_translation_ai_provider_calls_table.php` | Учёт вызова: provider, model, consumer, `target_locale` NOT NULL, счётчики items NOT NULL, токены, стоимость и ставки | Схема переводная — нужна аналогичная отдельная таблица учёта |
| ИИ | `app/Support/Translations/Telemetry/TranslationAiPricing.php:36-55`, `config/translation.php:49-71` | Точная десятичная стоимость через `BigDecimal`, ставки хранятся с вызовом, неизвестная модель → `null` | Принцип и арифметика копируются |
| ИИ | `config/translation.php:83-106`, `infrastructure/config/environment-contract.json` (`TRANSLATION_OPENAI_*`) | Блок провайдера: `api_key`, `model`, `base_url`, таймауты; ключ только в `shared/.env` цели | Шаблон блока конфигурации и механизм контракта окружения для новых ключей |
| ИИ | `tests/Pest.php` (`openAiResponseBody()`, `configureOpenAiTranslation()`), `tests/Feature/TranslationEngine/OpenAiTranslationProviderTest.php` | `Http::preventStrayRequests()` + `Http::fake(['*/responses' => ...])`, проверка отсутствия утечки ключа | Образец тестов для клиента Content Studio |
| География | `database/migrations/0001_01_01_000000_create_users_table.php:30-34`, `app/Models/Session.php` | Таблица `sessions` хранит `ip_address`, `user_agent`; модель только для массовых удалений | Единственное место, где уже лежит IP; истории входов нет |
| География | `bootstrap/app.php:21-37`, `app/Actions/Auth/AuthenticateUserAction.php:75-78`, `infrastructure/config/nginx/rateguru-staging:49-118` | `trustProxies` не настроен; `$request->ip()` используется только в ключе троттлинга входа; nginx сам завершает TLS и передаёт запрос в PHP-FPM через unix-сокет (`:108`), без `proxy_pass`/`real_ip`; DNS production указывает прямо на хост (`infrastructure/runbooks/public-site.md:21-23`) | Сегодня `$request->ip()` — реальный адрес клиента; при появлении CDN/балансировщика потребуется `trustProxies` |
| География | `app/Support/Observability/NightwatchPrivacy.php:148-157`, `config/sentry.php:125-130`, `config/observability.php:22-29` | IP считается персональными данными: Nightwatch стирает его целиком, Sentry без PII, но ключ `ip` в логах не редактируется | Хранение IP в базе — осознанное изменение политики с удержанием и анонимизацией |
| География | `users.locale`, `app/Http/Middleware/SetLocale.php`, `app/Actions/Users/UpdateUserLocaleAction.php` | Локаль пользователя; таймзоны на пользователя нет | Поля страны/города добавляются рядом в профиль |
| География | `infrastructure/scripts/deploy:1796-1812, 1885-1926`, `infrastructure/scripts/backup:402-405`, `infrastructure/scripts/restore-storage:22-33` | Мутабельные файлы живут в `<target>/shared/storage` и переживают релизы; весь `shared/storage/app` целиком попадает в ночной бэкап (без исключений) и заменяется при restore | Будущая база GeoLite2 под `shared/storage/app` будет бэкапиться и откатываться вместе с данными; другое место под `shared/` ничем не создаётся |
| Админка | `app/Providers/Filament/AdminPanelProvider.php:42-144` | Панель `admin`: `navigationGroups(AdminNavigationGroup::all())`, автообнаружение Resources/Pages, собственные `Sidebar`/`Topbar`, хуки `TOPBAR_END` на страницу, `darkMode(false)` | Новые страницы Content Studio регистрируются автообнаружением и получают свои хуки верхней панели |
| Админка | `app/Filament/Support/AdminNavigationGroup.php:14-39` | Шесть секций: Overview, Moderation, Content, Localization, Configuration, System | Добавляется секция Content Studio |
| Админка | `app/Filament/Support/AdminShellNavigation.php:42-56`, `tests/Feature/Admin/AdminShellTest.php:37-44, 259-285`, `tests/Unit/Filament/AdminNavigationGroupTest.php:14-23` | Карта иконок по классу destination; тесты закрепляют точный список секций и 13 destination с иконками | Для пяти экранов Content Studio добавляются иконки и обновляются оба теста |
| Админка | `resources/views/components/admin/ui/icon.blade.php:9-13` | Реестр Lucide-иконок, встроенных вручную; есть `bot`, `star`, `upload`; нет `newspaper`, `messages-square`, `scroll-text`, `list-checks`, которые использует макет | Недостающие иконки добавляются в реестр в том же изменении |
| Админка | Весь `app/Filament` | Ни одного использования `Filament\Forms`, `Filament\Tables`, `Filament\Schemas`, `Filament\Actions`, `Wizard`, `RelationManager`; экраны — собственные Blade на `x-admin.ui.*`; загрузки файлов в админке нет (`WithFileUploads` только в публичных компонентах) | Редактор публикации и загрузка фото в админке строятся на Livewire `WithFileUploads` и компонентах кита, а не на Filament Forms |
| Админка | `app/Filament/Resources/Users/UserResource.php:38-57`, `Pages/ListUsers.php`, `Pages/EditUser.php`, `Concerns/ModeratesUsers.php` | Список с вкладками и URL-фильтрами на сервере (`ModerationUsersQuery`), страница человека с черновиком и сохранением из верхней панели через `UpdateUserAccountAction`, диалоги санкций | Образец для списка Personas и редактора персоны; фильтр по роли уже есть |
| Админка | `resources/views/filament/pages/editor.topbar`, `x-admin.ui.*` (segmented, radio-cards, switch, textarea с presets, filter-dropdown, combobox, drawer OVL-02, dialog OVL-01, toast FBK-01) | Компоненты Admin v2 по контракту `docs/design/admin/design-contract.md` | Четырёхшаговый редактор публикации собирается из них; мастера (`Wizard`) в коде нет |
| Админка | `app/Filament/Pages/MediaDiagnosticsPage.php` | Таблица проблем с действиями, инспектор в drawer, повтор заданий | Образец экрана Action queue |
| Админка | `app/Filament/Resources/Posts/Pages/ListPosts.php` | Список постов с просмотром оригинала изображения, bulk-действиями, модерацией через Actions | Образец списка Publications & plans и шага Image |

---

## C. Required Extensions

Приоритет: **К** — критично (без этого персона ломает или компрометирует существующее),
**В** — высокий (нужно для первого функционального этапа или исполнителя),
**С** — средний, **Н** — низкий. Этапы соответствуют разделу F.

| Компонент | Текущая проблема или ограничение | Точное необходимое изменение | Приоритет | Будущий этап |
|---|---|---|---|---|
| `app/Enums/UserRole.php:5-10` | Нет роли персоны | `case Persona = 'persona'`; желательно `isStaff(): bool` и `label(): string`, чтобы убрать рукописные карты меток | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:161-168` | `roleLabel()` — `match` без `default`; падает `UnhandledMatchError` на первой персоне в списке | Добавить ветку `UserRole::Persona => 'Persona'` (или `UserRole::label()`) | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:149` | `'staff' => $person->role !== UserRole::User` — персона получит бейдж персонала | `in_array($person->role, [UserRole::Admin, UserRole::Moderator], true)` или `isStaff()` | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:118-123` | Фильтр ролей захардкожен тремя значениями; `normalize()` (`:174`) уже примет `?role=persona` | Добавить вариант `Persona` в список фильтра | В | 1 |
| `app/Filament/Resources/Users/Pages/EditUser.php:222, 130` | `Rule::enum(UserRole::class)` сделает `persona` допустимым значением сохранения в обе стороны | Явный список допустимых ролей для цели (`Rule::in([...])`); переход `user ↔ persona` через эту страницу запретить | К | 1 |
| `app/Filament/Resources/Users/Pages/EditUser.php:198-203`, `resources/views/filament/pages/user.blade.php:82, 191, 201` | Карты `roles`/`roleHelp` без персоны; диалог подтверждения индексирует `$roles[$role]` → undefined key на странице персоны | Добавить метку и подсказку персоны; для персоны показывать роль только для чтения | К | 1 |
| `resources/views/filament/pages/user.blade.php:92-109`, `app/Actions/Users/UpdateUserAccountAction.php:56-58` | Админ может задать пароль любому аккаунту, что откроет персоне вход по паролю | В Action: для персоны пароль всегда `null`, попытка задать — исключение; карточку пароля скрыть | К | 1 |
| `app/Actions/Posts/CreatePostAction.php:88-89` | `role !== UserRole::User` освобождает персону от подтверждённого email как «персонал» | Заменить на явную проверку персонала (метод модели или Policy); персоны получают `email_verified_at` при создании и публикуются сразу по доверию и подтверждённому email | К | 1 |
| `app/Filament/Resources/Posts/Pages/ListPosts.php:553` | Та же трактовка в подписи доверия | Зеркально поправить | С | 1 |
| `app/Models/User.php:151-154`, `AuthenticateUserAction.php:41`, `ResolveSocialLoginAction.php:70, 90`, `ClaimAccountWithVerifiedEmailAction.php:57`, `LinkSocialAccountAction.php:51`, `ResetPasswordAction.php:34`, `EnsureAccountIsNotTombstoned.php:28` | Все гейты входа статусные: активная персона с паролем, OAuth-идентичностью или подтверждённым провайдером email войдёт | Ввести ролевой предикат входа (например `User::canSignIn()` = `canAuthenticate() && role !== Persona`) и применить во всех семи точках; не переопределять `canAuthenticate()`, он нужен персонам для записей настроек | К | 1 |
| `app/Models/User.php` (`sendPasswordResetNotification` не переопределён), `SendPasswordResetLinkAction.php:12` | `Password::sendResetLink` создаст токен и отправит письмо на синтетический адрес персоны | Переопределить `sendPasswordResetNotification()` как no-op для персон (или гейт в Action) | В | 1 |
| `tools/phpstan/src/Rules/NoDirectControllerPermissionCheckRule.php:22-35` | Список методов, запрещённых в презентации, не знает новых предикатов | Добавить `isPersona()`/`canSignIn()`; презентация ходит через Gate/Policy | С | 1 |
| `docs/architecture/user-lifecycle.md:9-27`, `tests/Unit/Enums/UserRoleTest.php` и тесты из раздела G | Таблица роль×статус и тесты перечисляют три роли | Добавить строку Persona и регрессионные случаи (вход запрещён всеми путями, панель закрыта, список/страница рендерятся) | К | 1 |
| Создание управляемого аккаунта | Нет Create-страницы (`UserResource::getPages()` — `index`, `edit`) и нет Action создания пользователя из админки | `CreatePersonaAction` по образцу `CreateAdminUserCommand`/`RegisterSocialUserAction`: `password => null`, синтетический email на зарезервированном домене (`*.invalid`), `email_verified_at => now()`, `status` по умолчанию, без события `Registered`; страница Personas (список + создание + редактирование) в секции Content Studio | В | 2 |
| Настройки персоны | В `users` нет колонок характера, манеры речи, тем, ограничений, интенсивности, дневного лимита | Новая таблица `persona_profiles` (1:1 к `users`), модель и Action обновления; аватар — существующий `users.avatar_asset_id`, публичное описание — `users.bio` | В | 2 |
| Аватар персоны | `UpdateUserProfileAction` — только self-service (`canUpdateProfile()` актора, поля профиля из валидации) | Админский Action установки аватара, повторяющий пять шагов (`:39-41, 67-72, 82, 105`) | В | 2 |
| `app/Filament/Support/AdminNavigationGroup.php`, `AdminShellNavigation::ICONS`, `resources/views/components/admin/ui/icon.blade.php`, `tests/Feature/Admin/AdminShellTest.php:37-44, 259-285`, `tests/Unit/Filament/AdminNavigationGroupTest.php:14-23` | Нет секции Content Studio; тесты закрепляют точный список секций и 13 destination; в реестре иконок нет `newspaper`, `messages-square`, `scroll-text`, `list-checks` | Константа `CONTENT_STUDIO` в `all()`, иконки для пяти экранов (геометрия Lucide в реестр), обновить `toHaveCount`, карту `ICONS` и список секций; по чеклисту UI пункт навигации без готовой страницы запрещён — добавлять по мере появления экранов | В | 2 |
| `docs/design/admin/migration-plan.md`, `tests/Feature/Docs/AdminDesignDocsTest.php:249-266` | Content Studio в плане миграции не упомянут; тест закрепляет строки таблицы «remaining sections» и отсутствие строки `| 9 | Dashboard |` | Дополнить план разделом Content Studio своим изменением и синхронно обновить тест | С | 2 |
| `app/Actions/Profile/AnonymizeUserAccountAction.php:83` | При удалении роль сбрасывается в `User`; админского пути удаления нет | Решить, сохраняется ли `Persona` у tombstone; при необходимости — админский Action вывода персоны из эксплуатации | Н | 2+ |
| `app/Data/Posts/CreatePostData.php:10-27`, `CreatePostAction.php:91, 97-106` | Нет исторических `created_at`/`updated_at`/`published_at` | Необязательные поля DTO (`?CarbonImmutable`); `$publishesAtOnce ? ($data->publishedAt ?? now()) : null`; `created_at`/`updated_at` в массив `Post::create` при наличии (модель `$guarded = []`) | В | 3 |
| `CreatePostData`, `CreatePostAction.php:66-68, 93-95` | Нет создания поста из готового `MediaAsset` | `?int $imageAssetId` (взаимоисключим с `$image`); при наличии — пропустить `ImageUploadStorer`, загрузить ассет под `lockForUpdate()`, проверить `kind = PostImage`, `status = Ready`, не trashed, принадлежность; `StoredMediaCleaner` не затрагивается | В | 3 |
| `CreatePostAction.php:136-147` | Уведомление подписчикам при каждом `Published` | Флаг `notifyFollowers` в DTO (по умолчанию `true`) | В | 3 |
| `CreatePostAction.php:138`, `ApprovePostAction.php:89` | `NotifyFollowersAboutNewPostJob::dispatch` без `afterCommit()` — внутри внешней транзакции исполнителя задание уйдёт до фиксации | Добавить `->afterCommit()` | С | 3 |
| `app/Actions/Comments/AddCommentAction.php:35, 121-127, 135-158`, `CommentReplyService.php:26-45` | Нет исторических дат; уведомление автору поста всегда | Необязательный `?CarbonImmutable $createdAt` и флаг `notify`; прокинуть через `createReply()` | В | 3 |
| `app/Exceptions/Comments/CannotCommentException.php` | Недоступность цели ответа не отличима от ошибок тела (общий `becauseBodyIsInvalid`) | Отдельная причина `becauseReplyTargetUnavailable()` для трёх точек (`CommentReplyService.php:36`, `AddCommentAction.php:72, 76, 117`) | С | 6 |
| `app/Actions/Votes/VotePostAction.php:86-94`, `VoteCommentAction.php:100-112` | Переключатель: повтор снимает голос | Режим «обеспечить голос» (параметр с умолчанием, сохраняющим текущее поведение): тот же тип → no-op внутри транзакции, другой → `update`; необязательный `$castAt` для `created_at`; `CommentVote` — через `forceFill()` или расширение `$fillable` | В | 3 |
| `app/Actions/Rating/VoteRatingOptionAction.php:102-103` | `created_at => now()` в `upsert` | `$castAt ?? now()`; остальное уже идемпотентно | Н | 3 |
| `ActionRateLimiter` в `CreatePostAction:50-55`, `AddCommentAction:49-58`, `Vote*:45-57` | Лимиты по пользователю срабатывают до транзакции и считают повторы | Решение: пейсинг `run_after` в планировщике или отдельный ключ/флаг доверенного исполнителя с собственной записью в `config/rate_limits.php` | В | 5 |
| `app/Actions/Ranking/RecalculatePostScoreAction.php` | Не вызывается при создании | Исполнитель вызывает после создания исторического поста (образец `database/seeders/DemoFillSeeder.php:812-823`) | С | 5 |
| `app/Services/Media/MediaReferenceChecker.php:24-52` | Ссылка только из `posts` и `users`; ассет черновика — «активный неиспользуемый», доступен Release | Добавить третий источник (`studio_plans.image_asset_id`) в `isReferenced()` и `referencedAssetIds()`; обновить `tests/Feature/Services/Media/MediaReferenceCheckerTest.php:69` (2 → 3 запроса) и `docs/architecture/media.md` | В | 4 |
| `app/Support/Media/MediaAssetCreator.php:47-48` | `status`/`visibility` зашиты (`Ready`/`Public`) | Параметризовать visibility (по умолчанию `Public`); сюда же `->afterCommit()` диспетчеризация будущего анализа SFW/NSFW | С | 9 |
| Таблицы планов и действий | Нет ничего похожего (полный список таблиц в приложении) | `studio_plans`, `studio_plan_actions` (раздел E) | В | 4, 5 |
| `routes/console.php:16-38` | Только ежедневные команды; ежеминутных нет | `Schedule::command('studio:dispatch-due')->everyMinute()->withoutOverlapping()` | В | 5 |
| Job исполнителя | В репозитории нет `ShouldBeUnique`/middleware; есть образец claim-before-run | `ExecuteStudioActionJob`: скалярный id, `tries = 1`, `timeout` ≤ 80 с (при `retry_after` 90), `failOnTimeout`, `failed()`; claim строки под `lockForUpdate` + условный `UPDATE ... WHERE status = 'dispatched'` | В | 5 |
| Имя очереди | Воркер слушает ровно `--queue=<цель>`; `onQueue()` в коде не используется | Первая версия — очередь по умолчанию цели; выделенная очередь = отдельная инфраструктурная задача (`deployment-targets.json`, `install-bootstrap-services:1091`, `rateguru-staging-queue.conf:6`, `tests/Feature/Architecture/ProvisionTargetTest.php:609-618`, `InstallBootstrapServicesTest.php:2474-2495`) | С | 5 |
| Комментарии `CreatePostAction.php:161-164`, `UpdateUserProfileAction.php:109-112`, `docs/deployment/queue-worker.md:5, 44` | Утверждают «`QUEUE_CONNECTION=sync` — единственное соединение», что противоречит контракту окружения | Поправить при первом касании файлов | Н | 3 |
| Клиент OpenAI для Content Studio | Движок переводов принимает только `input_text`, контракт `translateBatch`, тест закрепляет ключи тела (`OpenAiTranslationProviderTest.php:84`) | Отдельный клиент Responses API вне `app/Support/TranslationEngine` с `input_image`, конфиг на фичу, enum ошибок, таблица учёта вызовов, ключи в `environment-contract.json` | С | 8 |

---

## D. Critical Compatibility Issues

### D1. Роль Persona и существующие проверки персонала

Код не знает понятия «персонал» и в трёх местах выводит его как «не `User`»:

- `app/Actions/Posts/CreatePostAction.php:88-89` — публикация без подтверждённого email
  для `$lockedActor->role !== UserRole::User`. Персона получит это исключение случайно.
  Нужная семантика для персон — публиковаться сразу, но через явно заданный
  `email_verified_at` при создании и `trust_level = 10` (ставится `User::booted()`),
  а не через лазейку для персонала.
- `app/Filament/Resources/Users/Pages/ListUsers.php:149` — бейдж персонала.
- `app/Filament/Resources/Posts/Pages/ListPosts.php:553` — подпись «Email not confirmed»
  только для `User`.

Исчерпывающий `match` в `ListUsers::roleLabel()` (`:161-168`) бросит
`UnhandledMatchError` на первой строке с персоной, то есть список пользователей
перестанет открываться. На странице персоны в `EditUser` сегментированный контрол роли не
содержит её значения, а диалог подтверждения смены роли обращается к `$roles[$role]` и
`$roles[$saved['role']]` (`user.blade.php:186-201`) — undefined key при любой попытке
сохранить роль. Безопасные места:
`Sidebar.php:57-61` (есть `default`), `ModerationUsersQuery::staff()` (явный список
`Moderator`/`Admin`), все Policies (`isAdmin()`/`isModerator()`), `User::canAccessPanel()`.
`UserRole::cases()` в коде не используется; единственное автоматическое расширение —
`Rule::enum(UserRole::class)` в `EditUser.php:222` и `UserRole::tryFrom()` в
`ListUsers.php:103, 124, 174`.

### D2. Авторизация и OAuth

Гейт `canAuthenticate()` статусный (`UserStatus.php:103-106`). Персона со статусом
`Active`:

- войдёт по паролю, если пароль когда-либо будет установлен (`EditUser` позволяет это
  любому аккаунту — `user.blade.php:92-109`, `UpdateUserAccountAction.php:56-58`);
  `password = NULL` безопасен сам по себе (`EloquentUserProvider` отвергает пустой хеш);
- войдёт через OAuth, если к ней привяжут идентичность (`ResolveSocialLoginAction.php:70-76`)
  или если её `email` окажется адресом, который Google/Facebook подтвердят: тогда
  `ClaimAccountWithVerifiedEmailAction` передаст аккаунт постороннему (`:87-100`);
- получит письмо сброса пароля (`Password::sendResetLink`) на синтетический адрес.

Защиты, которые уже есть: `/admin/login` Filament отвергает любого без
`canAccessPanel()`; `LinkSocialAccountAction.php:80-87` не привяжет идентичность, чей
email принадлежит другому аккаунту; `RegisterUserAction`/`RegisterSocialUserAction`
никогда не читают роль из ввода — публичная регистрация персоны невозможна. Требуемые
изменения перечислены в разделе C (ролевой предикат входа в семи точках, запрет пароля,
домен `*.invalid` по образцу tombstone).

### D3. Подтверждение email при публикации

Условие публикации сразу: `trust_level >= 10 && (hasVerifiedEmail() || role !== User)`
(`CreatePostAction.php:88-89`). Иначе пост ложится в `Pending`, `published_at` остаётся
`null`, и одобрение проставит `published_at = now()` (`ApprovePostAction.php:43`) —
историческая дата будет потеряна. Следствие: персона должна создаваться с
`email_verified_at`, а событие `Registered` при её создании не должно отправляться
(фреймворк по умолчанию шлёт письмо подтверждения; `app/Providers` не содержит
`EventServiceProvider`). Отдельного «принудительно опубликовать» вводить не нужно.

### D4. Повторное выполнение голосований

| Действие | Повтор с тем же значением | Повтор с другим значением | Идемпотентно |
|---|---|---|---|
| `VotePostAction` (`:86-94`) | удаляет голос | удаляет голос (новый тип не пишется; закреплено `tests/Feature/Actions/VotePostActionTest.php:82-108`) | нет |
| `VoteCommentAction` (`:100-112`) | удаляет голос | обновляет тип на месте | нет |
| `VoteRatingOptionAction` (`:96-109`) | без изменений (`$changed === false`) | обновляет `rating_option_id` | да |

Защита «тот же голос → ничего не делать» в публичном UI существует только в
`app/Livewire/Posts/PostVoting.php:60-64` вне транзакции. Для исполнителя проверка
должна быть внутри транзакции под `lockForUpdate` строки голоса (раздел E). Запрет
голосовать за свой контент остаётся: `PostPolicy::vote` (`:73-77`) для постов и
рейтингов, инлайн-проверка `comment->user_id === user->id` для комментариев
(`VoteCommentAction.php:41-43, 89-92`). Планировщик обязан не ставить персону голосовать
за собственный контент — иначе действие завершится исключением.

### D5. Исторические даты

- Ни один Action не принимает временные метки; `created_at`/`updated_at` ставит Eloquent.
  Модели `Post`, `Comment`, `PostVote` незащищены (`$guarded = []`), поэтому явно
  переданные значения сохраняются без изменений моделей; `CommentVote` и `RatingVote`
  требуют `forceFill()` или `upsert` с явным значением.
- `updated_at` недолговечен: каждый пересчёт счётчиков, hot score, скрытие или
  одобрение вызывают `save()` (`RecalculatePostCountersAction.php:39-42`,
  `RecalculateCommentCountersAction.php`, `HideCommentAction.php:60`). Историческое
  `updated_at` живёт до первого взаимодействия; в продукте оно не отображается.
- Hot score считается от `posts.created_at` и текущего времени
  (`HotScoreCalculator.php:31`) и не вычисляется при создании. Пост с датой пять дней
  назад получит ≈0.0007 после первого пересчёта — это и есть ожидаемое поведение для
  исторического контента, но пересчёт надо вызвать явно.
- Лента «Новое» и профиль упорядочены по `published_at`, обсуждение — по `created_at`:
  исторические записи встают в хронологическое место. Корректность порядка внутри ветки
  (ответ не раньше корня) никто не проверяет — ответственность планировщика.
- Макет Content Studio описывает «редакционную дату» и обещает никогда не изменять
  `created_at` задним числом (раздел П10а, шаг Publication). Утверждённое решение 1.3
  требует обратного; документ следует решению, подпись макета — предмет правки.
- Доверенность: `CreatePostData` конструируется только в `UploadPostForm.php:106-115`;
  новые поля с умолчанием `null` недостижимы из публичных запросов по построению.
  Репозиторий уже защищает такие инварианты grep-тестами
  (`tests/Unit/Actions/UserFacingImageFlowsUseIngestorTest.php:18-34`,
  `tests/Feature/Architecture/UserLifecycleWriteBoundaryTest.php`); аналогичный тест
  должен запретить `app/Livewire`, `app/Http`, `app/Filament` задавать новые поля.

### D6. Ограничение глубины комментариев

Числовой константы глубины нет. Правило структурное: родитель ответа обязан иметь
`parent_id IS NULL` (`CommentReplyService.php:55`, `AddCommentAction.php:75` и `:111`
под блокировкой). Ответ на ответ отвергается `CannotCommentException` с сообщением
`ui.comments.errors.reply_target_unavailable` (закреплено
`tests/Feature/Domain/CommentTombstoneLifecycleTest.php:231-236`;
`docs/architecture/comment-lifecycle.md:39-40`). База глубину не ограничивает
(`DemoFillSeeder` вставляет три уровня сырыми запросами), но UI рендерит только
`replies` корней (`CommentListQuery.php:17-31`). Следствие для генератора обсуждений:
дерево — ровно два уровня (корни и их ответы); «ответ на ответ» моделируется как ещё
один ответ тому же корню с адресацией в тексте. Правило не меняем.

### D7. Хранение MediaAsset до публикации

Ассет, созданный на этапе черновика, активен (`status = ready`), но не используется ни
постом, ни пользователем. Автоматически его ничто не удалит: purge видит только
`onlyTrashed()` (`MediaPurgeCommand.php:65-67`), `isGraceExpired()` для активной строки
`false` (`MediaLifecycleService.php:73-75`), сканер сирот пропускает файлы со строкой в
базе. Но каждый `media:audit` отметит его `active_unreferenced_asset`, здоровье
библиотеки станет `Warning`, а Media diagnostics предложит Release
(`MediaDiagnosticsPage.php:543, 632-636`) — один клик мягко удалит ассет, и через 7 дней
`media:purge` удалит файлы. Исполнение плана с soft-удалённым ассетом даст пост без
изображения (`Post::imageAsset` — `belongsTo` к модели с `SoftDeletes`). Единственное
надёжное решение — сделать план источником ссылки в `MediaReferenceChecker` (раздел C).
Повторная обработка не нужна: `GenerateMediaVariantsJob` при публикации пропустит уже
созданные варианты (`MediaVariantGenerator.php:69-71`).

### D8. Счётчики, кеш и рейтинг

Счётчики — полный пересчёт под блокировкой строки, внутри транзакций Actions
(`RecalculatePostCountersAction`, `RecalculateCommentCountersAction`,
`RefreshesPostCommentsCount`); они не зависят от дат голосов и комментариев. Кеша ленты
нет (`PostListCacheManager` — заглушка, вызовы закреплены
`tests/Feature/Actions/VoteCacheInvalidationPlaceholderTest.php`). Единственные реальные
кеши — боковая панель категорий/тегов (300 с, `AppServiceProvider.php:135, 169`).
Рейтинговые голоса агрегируются на лету (`RatingVoteCountsQuery`), персистентных
столбцов нет. `VoteRatingOptionAction` блокирует строку `RatingGroup` на время голоса —
глобальная точка сериализации для массовых рейтинговых голосов.

### D9. Уведомления от исторических действий

Все продуктовые уведомления — канал `database`, синхронные: `PostCommentedNotification`
автору поста (`AddCommentAction.php:135-158`), `PostApprovedNotification`,
`FollowedAuthorPostedNotification` подписчикам (`NotifyFollowersAboutNewPostJob`).
Ни одно не смотрит на `published_at`/`created_at` контента; колокольчик показывает
`created_at` самой строки уведомления. Исторический пост или комментарий породит
уведомление «только что» о событии «пять дней назад». Уведомления автору родительского
комментария не существует вовсе — реальный пользователь не узнает об ответе персоны без
нового класса уведомления (это отдельное продуктовое решение). Персоны как живые
аккаунты получают строки `notifications` (например, `PostCommentedNotification` о
комментариях к их постам); при анонимизации персоны эти строки и уведомления с её
`actor_id`/`author_id` удаляются (`AnonymizeUserAccountAction.php:108-130`).

### D10. Блокировки и модерация

Правила сохраняются без изменений: `UserStatus` для персоны `Active`; санкции
(`Limited`/`Banned`/`Shadowbanned`) мгновенно блокируют её участие через capability-методы
и перепроверки под блокировкой в каждом Action; `HidePostAction`/`HideCommentAction`
не трогают дочерний контент, но делают его недоступным для новых голосов и ответов
(`Post::canReceiveComments/Votes`, `Comment::canReceiveVotes`, цель ответа только
`Visible`). Исполнитель обязан проверять те же предикаты перед запуском и трактовать
исключения `CannotCommentException`/`CannotVoteException`/`CannotVoteCommentException`
о недоступности цели как «отменено», а не «ошибка». Порядок блокировок (актор → другие
пользователи по возрастанию id → пост → комментарий/группа → рёбра) обязателен и для
новых Actions.

---

## E. Minimal Architecture

### E1. Принципы

1. Content Studio не создаёт контент сам: каждая публикация, комментарий, ответ и голос
   проходит через существующий Action с его политиками, блокировками, счётчиками и
   логами. Новый код добавляет только доверенные параметры с умолчаниями, сохраняющими
   текущее поведение.
2. Бизнес-состояние (планы, действия, их статусы, зависимости, результаты) живёт в
   таблицах PostgreSQL и видно администратору. Техническая очередь (Redis-список и
   `failed_jobs`) переносит только идентификаторы.
3. Exactly-once обеспечивается транзакцией: доменная запись и перевод строки действия в
   `done` фиксируются вместе; повторная доставка задания видит `done` и ничего не делает.
4. Новые сущности — только там, где существующих недостаточно; для каждой объяснено,
   почему нельзя использовать существующую.

### E2. Новые сущности

**`PersonaProfile` (таблица `persona_profiles`, 1:1 к `users`).** Характер, манера речи,
темы, ограничения, внутреннее описание, интенсивность автоматических ответов
(`Off` отключает только самостоятельные ответы), дневной лимит, автор записи. Аватар и
публичное описание остаются в `users` (`avatar_asset_id`, `bio`). Почему не `users`:
колонки нужны только персонам, таблица `users` — общая с реальными людьми, а её записи
статуса охраняет `UserLifecycleWriteBoundaryTest`; отдельная таблица не трогает ни
регистрацию, ни анонимизацию, ни фабрики.

**`StudioPlan` (таблица `studio_plans`) — одна публикация, один план.** Статус
(черновик → готов → утверждён → исполняется → завершён / отменён), автор-персона
(FK `users`, restrict), создатель-администратор, `image_asset_id` (FK `media_assets`),
черновой текст, окончательные заголовок и описание, категория, теги, ответы автора,
время исполнения, историческое время публикации, ссылка на созданный `post_id`,
отметки утверждения. Почему не `Post` со статусом `Draft`: `PostStatus::Draft` сегодня
не создаётся ни одним Action (только фабрикой), а `PostPolicy::update` для черновиков
не имеет вызывающих; пост требует автора и участвует в FK-графе, авторском удалении
(`AUTHOR_DELETABLE_STATUSES`) и модерационных списках; план должен хранить
подготовленное обсуждение и голоса до появления поста, а также расписание и
исторические даты, которым в `posts` нет места. Почему не `jobs`: payload непрозрачен,
строка исчезает после выполнения, нет статусов, зависимостей и видимости в админке.

**`StudioAction` (таблица `studio_plan_actions`) — одна запланированная доменная запись.**
`plan_id`, `parent_action_id` (self-FK, nullable), вид (`create_post`, `create_comment`,
`create_reply`, `vote_post`, `vote_comment`, `vote_rating`), актор-персона, payload
(тело, тип голоса, вариант рейтинга), `occurs_at` (историческое время для штампа),
`run_after` (реальное время, не раньше которого можно исполнять), статус (`pending`,
`dispatched`, `running`, `done`, `cancelled`, `failed`), попытки, `claimed_at`,
`finished_at` (реальное время исполнения — «история исполнения действий» из постановки),
результат (`result_type`/`result_id` по образцу морфов `reports.target_type/target_id`),
код и текст причины отказа. Почему не `moderation_logs`/`notifications`/`failed_jobs`:
это журналы других доменов с другой семантикой; `failed_jobs` хранит только ошибки.

**Правила ответов (`persona_reply_rules`) и журнал ответов** появляются на этапе
автоответов. Рекомендация: журнал ответов — строки той же таблицы действий с видом
`auto_reply` и ссылкой на комментарий-триггер (`plan_id` допускает `null`), чтобы не
плодить второй исполнитель; окончательное решение — на этапе 7 (раздел H).

### E3. Исполнитель действий

1. **Диспетчер** — команда `studio:dispatch-due`, зарегистрированная в `routes/console.php`
   как `everyMinute()->withoutOverlapping()` рядом с пятью ежедневными. Выбирает
   ограниченное число строк `status = pending AND run_after <= now()` с завершённым
   родителем (или без родителя), в порядке `run_after, id` (правило детерминированной
   пагинации из `docs/architecture/http-and-database-boundaries.md`), переводит каждую в
   `dispatched` условным обновлением `WHERE status = 'pending'` и диспетчеризует
   `ExecuteStudioActionJob($actionId)` с `afterCommit()`. Читает «просроченное в прошлом»,
   поэтому переживает паузу воркера и cron во время restore
   (`infrastructure/scripts/restore-target:500-596`).
2. **Job** — `ExecuteStudioActionJob` с единственным скалярным аргументом (как все четыре
   существующих Job), `tries = 1`, `timeout` не выше 80 с (Redis `retry_after` 90 с,
   воркер 120 с — контракт `GenerateProjectTranslationChunkJob.php:36-49`),
   `failOnTimeout = true`, `failed()` переводит строку в `failed`. Повтор — решение
   администратора в Action queue, не очереди (как «fresh dispatch» в Media diagnostics).
3. **Action исполнения** в `app/Actions` (презентация и Jobs не пишут модели напрямую —
   `NoPresentationModelMutationRule`). Внутри одной `DB::transaction`:
   `lockForUpdate` строки действия → если статус не `dispatched`, выйти (повторная
   доставка) → перечитать родительский контент и проверить предикаты
   (`Post::canReceiveComments()/canReceiveVotes()`, `CommentReplyService::canStartReply()`,
   `Comment::canReceiveVotes()`, актор `Active`) → если цель недоступна, `cancelled` с
   причиной и каскадная отмена поддерева → иначе вызвать существующий Action с
   доверенными параметрами (`occurs_at` как `created_at`/`published_at`, уведомления
   выключены, голоса в режиме «обеспечить») → записать `result_*`, `done`, `finished_at`.
   Вложенные `DB::transaction` Actions становятся savepoint; `GenerateMediaVariantsJob`
   уже ждёт внешний commit (`afterCommit()`, проверено
   `tests/Feature/Actions/CreatePostActionMediaVariantDispatchTest.php:73`);
   `NotifyFollowersAboutNewPostJob::dispatch` требует добавить `afterCommit()`.
4. **После фиксации** для `create_post` — `RecalculatePostScoreAction::handle($post)`.
5. **Исключения Actions о недоступности цели** (`becausePostIsNotPublic`,
   `reply_target_unavailable`, `becauseCommentIsNotVisible`) → `cancelled`; прочие →
   `failed` с классом исключения; тексты, ключи и prompt в журнал не пишутся
   (образец `DomainLogger`).
6. **Параллельность.** Строковый `lockForUpdate` плюс условный `UPDATE` — compare-and-set
   на PostgreSQL (единственная развёрнутая СУБД). Дополнительно, по образцу
   `MediaLifecycleService::purgeLock()`, допустима неблокирующая атомарная блокировка
   `Cache::store('database')` с ключом по id действия и TTL больше `timeout` Job.

### E4. Доверенные исторические даты

Параметры добавляются к существующим DTO/сигнатурам с умолчанием `null`:
`CreatePostData::$publishedAt/$createdAt/$updatedAt`, `AddCommentAction::handle(...,
?CarbonImmutable $createdAt = null)` и `CommentReplyService::createReply(...)`,
`VotePostAction/VoteCommentAction/VoteRatingOptionAction::handle(..., ?CarbonImmutable
$castAt = null)`. Значения применяются внутри транзакций после всех проверок и
блокировок, не обходя их. Единственный вызывающий — Action исполнения; grep-тест
запрещает презентации передавать эти параметры. Реальное время исполнения хранится в
`studio_plan_actions.finished_at`; `moderation_logs` и `notifications` сохраняют
реальные даты.

### E5. Зависимости и отмена

`parent_action_id` задаёт дерево: пост → комментарии → ответы и голоса за комментарии;
пост → голоса за пост и рейтинги. При исполнении ребёнок читает `result_id` родителя и
передаёт модель в Action. Отмена: если родительское действие не `done`, или контент
исчез/не проходит предикат, ребёнок и всё его поддерево получают `cancelled` в той же
транзакции (`whereIn('parent_action_id', ...)->whereIn('status', [pending, dispatched])`);
независимые действия (без родителя или со здоровым родителем) не затрагиваются, потому
что выбор идёт по строкам. Образец «провалить остаток, сохранить сделанное» —
`StartProjectTranslationGenerationAction.php:156-161`. Режим `Off` у персоны отключает
только автоответы и не отменяет утверждённые планы — проверка интенсивности живёт в
правилах ответов, а не в исполнителе планов.

### E6. Бизнес-очередь и техническая очередь

| | Бизнес-очередь Content Studio | Техническая очередь Laravel |
|---|---|---|
| Где | `studio_plans`, `studio_plan_actions` (PostgreSQL, в периметре бэкапа) | Redis-список `queues:<имя>`, `failed_jobs` |
| Что хранит | план, действие, статус, зависимости, `occurs_at`/`run_after`, результат, причина отказа, `finished_at` | сериализованное задание с одним id |
| Кто меняет | диспетчер, Action исполнения, администратор (отмена, повтор) | воркер (`queue:work redis --queue=<цель>`) |
| Видимость | экран Action queue | только `failed_jobs` через Media diagnostics (медиа-подмножество) |
| Потеря | нет (транзакционная БД) | допустима: диспетчер заново выдаст `pending`/зависшие `dispatched` по тайм-ауту claim |

Имя очереди: в первой версии — очередь цели по умолчанию (воркер слушает только её);
отдельное имя потребует изменения реестра целей, `install-bootstrap-services`,
коммитнутого supervisor-конфига и архитектурных тестов — это самостоятельная
инфраструктурная задача.

### E7. Персоны

Персона — строка `users` с `role = persona`, `status = active`, `password = NULL`,
`email` на зарезервированном домене (`*.invalid`, как у tombstone), `email_verified_at`
проставлен, `trust_level = 10` по умолчанию, `username` сгенерирован. Создание — новый
Action (образцы `CreateAdminUserCommand`, `RegisterSocialUserAction`), без события
`Registered`. Вход закрыт ролевым предикатом во всех семи точках (раздел C); панель уже
закрыта `canAccessPanel()`. Аватар — админский Action по пяти шагам
`UpdateUserProfileAction`. В разделе Users персона видна через фильтр роли; санкции
(`UserPolicy::canSanction`) к ней применимы.

### E8. Медиа на этапе черновика

Шаг Image редактора сохраняет файл сразу: `ImageUploadStorer::store($file,
MediaStoreRequest::forPostImage($personaId))` → `MediaAssetCreator::create($stored,
MediaKind::PostImage, $persona)` → `GenerateMediaVariantsJob::dispatch($asset->id)`;
для URL-источника перед этим `StoreImportedImageAction::download()` с обязательным
`cleanup()`. `studio_plans.image_asset_id` становится третьей ссылкой в
`MediaReferenceChecker`. При исполнении `CreatePostData::$imageAssetId` передаёт готовый
ассет; повторная диспетчеризация вариантов — no-op. Общий анализ SFW/NSFW в будущем
подключается одним Job по id ассета из `MediaAssetCreator::create()` (`afterCommit()`),
читая уже нормализованный мастер через `MediaStorage::readStream()` как
`MediaVariantGenerator::readMasterBytes()`; гейт показа — `MediaVisibility::Private`
(уже реализован в резолверах URL), гейт вариантов — `MediaStatus` (генератор требует
`Ready`). Модель и пакеты на этом этапе не выбираются.

### E9. Что не создаём

Отдельный `StudioPost`; свои таблицы голосов; универсальный AI Engine/Provider Registry
(существующий `TranslationProviderRouter` — ровно то, что отложено); массовый импорт,
content packs и сидеры рабочего контента; географический справочник, GeoIP и журнал
входов; кеш ленты.

---

## F. Implementation Dependencies

Первый функциональный этап — Personas с полноценным UI — это MR 1 и MR 2 вместе.

### MR 1 — роль Persona и закрытие проверок

- **До начала:** ничего; документ принят.
- **Существующие компоненты:** `UserRole`, `User`, `UserPolicy`, `ListUsers`, `EditUser`,
  `ModeratesUsers`, `UpdateUserAccountAction`, `CreatePostAction`, `ListPosts`, семь гейтов
  входа, `EnsureAccountIsNotTombstoned`, `NoDirectControllerPermissionCheckRule`.
- **Новое:** `case Persona`, ролевой предикат входа (`canSignIn()` или аналог),
  `isStaff()`/`label()`, no-op `sendPasswordResetNotification()` для персон, запрет пароля
  и переходов роли в `UpdateUserAccountAction`, фабричное состояние `persona()`, строка в
  `docs/architecture/user-lifecycle.md`.
- **Риски:** пропущенная точка входа; регрессия списка пользователей; изменение условия
  публикации для реальных админов/модераторов (сохранить их освобождение явно).
- **Критерий на staging:** персона, созданная фабрикой/тинкером, видна в Users с фильтром
  роли; её страница открывается; вход по паролю (если выставлен напрямую в БД), через
  Google/Facebook и сброс пароля отвергаются с общим сообщением; `/admin` закрыт;
  обычный пользователь и модератор публикуют как раньше.

### MR 2 — настройки персоны и раздел Personas

- **До начала:** MR 1.
- **Существующие компоненты:** `ListUsers`/`EditUser` как образец страницы Admin v2
  (черновик, сохранение из верхней панели, `filament.pages.editor.topbar`), `x-admin.ui.*`,
  `UpdateUserAccountAction`, `GenerateUniqueUsernameAction`, `ImageUploadStorer`,
  `MediaAssetCreator`, `GenerateMediaVariantsJob`, `AdminNavigationGroup`,
  `AdminShellNavigation`.
- **Новое:** миграция и модель `PersonaProfile`, `CreatePersonaAction`,
  `UpdatePersonaAction`, Action установки аватара, Filament-страницы Personas
  (список/создание/редактирование) в секции Content Studio, иконки и обновлённый
  `AdminShellTest`, allow-list в `UserLifecycleWriteBoundaryTest` при явной записи статуса.
- **Риски:** дублирование логики аватара вместо выделения общих шагов; нарушение
  PHPStan-правил записи из презентации; несоответствие дизайн-контракту
  (`docs/design/admin/ui-review-checklist.md`).
- **Критерий на staging:** администратор создаёт персону с аватаром и настройками,
  редактирует их, видит персону в Users; у персоны есть публичный профиль `/u/{username}`;
  персона не может войти ни одним способом.

### MR 3 — доверенные расширения Actions

- **До начала:** MR 1 (персона как автор).
- **Существующие компоненты:** `CreatePostData`, `CreatePostAction`, `AddCommentAction`,
  `CommentReplyService`, три `Vote*Action`, `NotifyFollowersAboutNewPostJob`.
- **Новое:** поля DTO и параметры (даты, `imageAssetId`, `notifyFollowers`, `notify`,
  режим «обеспечить голос», `castAt`), `afterCommit()` на job подписчикам, grep-тест на
  недоступность параметров из презентации, правка устаревших комментариев про `sync`.
- **Риски:** изменение публичного поведения голосов (режим по умолчанию обязан остаться
  переключателем); пропуск проверок при загрузке готового ассета.
- **Критерий на staging:** Pest-сценарии: пост от персоны с датой в прошлом встаёт в
  ленте на место даты, подписчики не уведомлены, hot score пересчитан; комментарий и
  голос с историческими `created_at`; повторный вызов «обеспечить голос» не меняет
  состояние.

### MR 4 — планы и редактор публикации

- **До начала:** MR 2, MR 3 (пункт про `MediaReferenceChecker` можно внести здесь).
- **Существующие компоненты:** `ImageUploadStorer`, `MediaAssetCreator`,
  `StoreImportedImageAction`, `GenerateMediaVariantsJob`, `MediaReferenceChecker`,
  `RatingConfigurationManager` (активные группы и варианты для ответов автора),
  `ListPosts` (просмотр изображения), компоненты Admin v2.
- **Новое:** миграция и модель `StudioPlan`, Actions создания/обновления/утверждения
  плана, страница Publications & plans и редактор из четырёх шагов (Image, Post,
  Discussion, Publication; шаг Discussion может быть заглушкой до MR 6), расширение
  `MediaReferenceChecker` с тестом и правкой `docs/architecture/media.md`.
- **Риски:** ассет черновика без ссылки (Release в Media diagnostics); разрастание
  редактора в собственную логику публикации — публикация остаётся делом исполнителя.
- **Критерий на staging:** план с фотографией сохраняется; `media:audit` не отмечает его
  ассет как неиспользуемый; план утверждается с расписанием.

### MR 5 — очередь действий и исполнитель

- **До начала:** MR 3, MR 4.
- **Существующие компоненты:** `routes/console.php`, cron `schedule:run`, Redis-воркер,
  `LocksActorForWrite`, образцы `GenerateProjectTranslationChunkJob` и
  `MediaLifecycleService::purgeLock()`, `RecalculatePostScoreAction`, `FailedMediaJobReader`
  как образец повтора, `MediaDiagnosticsPage` как образец экрана.
- **Новое:** миграция и модель `StudioAction`, команда `studio:dispatch-due`,
  `ExecuteStudioActionJob`, Action исполнения, экран Action queue (статусы, причины,
  повтор, отмена), решение по лимитам скорости (пейсинг или отдельный ключ).
- **Риски:** `timeout` Job выше `retry_after` 90 с; двойное исполнение при отсутствии
  условного обновления; исчерпание лимитов `upload`/`comment`/`vote` персоной;
  единственный воркер делит пропускную способность с медиа и переводами.
- **Критерий на staging:** утверждённый план создаёт реальный пост персоны в нужное
  время с историческими датами; повторный запуск задания (искусственно) не создаёт
  дубликатов; действие с отменённым планом не исполняется; записи в Action queue
  содержат реальное `finished_at`.

### MR 6 — подготовленное обсуждение и зависимости

- **До начала:** MR 5.
- **Существующие компоненты:** `AddCommentAction`, `CommentReplyService::canStartReply()`,
  `VoteCommentAction`, предикаты `Post`/`Comment`, `CannotCommentException`.
- **Новое:** шаг Discussion редактора (дерево в два уровня с датами и голосами),
  построение `parent_action_id`, каскадная отмена, отдельная причина
  `becauseReplyTargetUnavailable()`, проверка монотонности дат внутри ветки при
  планировании.
- **Риски:** попытка глубины больше двух; ответ датой раньше корня; персона голосует за
  собственный комментарий.
- **Критерий на staging:** скрытие поста модератором отменяет ожидающие комментарии и
  голоса плана; скрытие корневого комментария отменяет его ответы и голоса, остальные
  действия продолжаются.

### MR 7 — правила ответов и журнал ответов

- **До начала:** MR 6, решение по триггерам (новое уведомление автору родительского
  комментария сегодня отсутствует; `PostCommentedNotification` даёт только комментарии к
  постам персоны).
- **Существующие компоненты:** `AddCommentAction`, `CommentReplyService`, таблица
  действий как журнал, `PersonaProfile` (интенсивность, дневной лимит, `Off`).
- **Новое:** `persona_reply_rules`, детектор триггеров, генерация текста (зависит от
  MR 8), экраны Reply rules и Reply log.
- **Риски:** лимит `comment` 10/60 с; зацикливание персон между собой; `Off` обязан
  останавливать только автоответы.
- **Критерий на staging:** реальный комментарий к посту персоны порождает запись в
  журнале и, при включённой интенсивности, ответ персоны не чаще дневного лимита.

### MR 8 — интеграция OpenAI для Content Studio

- **До начала:** MR 4 (есть изображение и черновик).
- **Существующие компоненты (копируются, не импортируются):** рецепт `Http` и валидация
  настроек из `OpenAiTranslationProvider`, классификация ошибок, разбор Responses API,
  `DomainLogger`, `BigDecimal`-арифметика стоимости, механизм контракта окружения,
  тестовые фикстуры `openAiResponseBody()`.
- **Новое:** клиент Responses API с `input_image`, конфиг на фичу (ключ, базовый URL,
  таймауты, модель на задачу), enum ошибок, таблица учёта вызовов и команда очистки,
  ключи в `environment-contract.json` и шаблонах, тесты с `Http::fake`.
- **Риски:** переиспользование переводного ключа `TRANSLATION_OPENAI_API_KEY` под
  расходы Content Studio (противоречит правилу именования); повторы, удваивающие
  платные вызовы.
- **Критерий на staging:** анализ фотографии и черновик заголовка появляются в
  редакторе; вызов учтён с токенами и оценкой стоимости; при пустом ключе функция
  отвергается как «не настроена», остальное приложение работает.

### MR 9 — общий анализ SFW/NSFW

- **До начала:** MR 8 (клиент) и продуктовое решение о модели.
- **Существующие компоненты:** `MediaAssetCreator::create()`, `MediaStorage::readStream()`,
  `MediaVisibility`, `MediaStatus`, `MediaVariantGenerator`, Media diagnostics.
- **Новое:** Job анализа по id ассета, параметр visibility в `MediaAssetCreator`,
  гейт показа/вариантов, отображение результата в Media diagnostics и редакторе.
- **Риски:** `MediaStatusTest` закрепляет четыре значения; `private` на локальном диске
  — флаг, а не контроль доступа (`docs/architecture/media.md`).
- **Критерий на staging:** новая загрузка пользователя и фотография плана проходят анализ
  один раз без повторной нормализации; результат виден администратору.

---

## G. Test Coverage Matrix

### G1. Существующие тесты по областям

| Область | Тесты |
|---|---|
| Роли, политики, доступ в панель | `tests/Unit/Enums/UserRoleTest.php`, `tests/Unit/Enums/UserStatusTest.php`, `tests/Feature/Policies/UserPolicyTest.php`, `tests/Feature/Policies/PostPolicyTest.php`, `CommentPolicyTest.php`, `ModerationPolicyTest.php`, `tests/Feature/Models/UserLifecycleTest.php`, `tests/Feature/Admin/FilamentAdminAccessTest.php`, `tests/Feature/UserSchemaTest.php` |
| Регистрация, вход, OAuth, сброс пароля, подтверждение email | `tests/Feature/Auth/RegistrationTest.php`, `AuthenticationTest.php`, `SocialLoginTest.php`, `SocialAccountClaimTest.php`, `SocialAccountLinkingTest.php`, `SocialOnlyAccountPasswordTest.php`, `PasswordResetTest.php`, `EmailVerificationTest.php`, `TombstonedSessionTest.php`, `BannedUserContentTest.php`, `tests/Feature/Actions/AuthBoundaryActionsTest.php` |
| Users в админке | `tests/Feature/Filament/UserResourceTest.php`, `UserResourceActionsTest.php`, `UserResourceTombstoneTest.php`, `tests/Feature/Queries/ModerationUsersQueryTest.php`, `tests/Feature/Actions/UpdateUserAccountActionTest.php`, `MarkUserTrustedActionTest.php`, `tests/Browser/AdminUsersBrowserTest.php` |
| Профиль, аватар, анонимизация | `tests/Feature/Livewire/EditProfileFormTest.php`, `AvatarUploadTest.php`, `ProfilePageTest.php`, `tests/Feature/Actions/AvatarMediaLifecycleTest.php`, `UpdateUserProfileActionMediaVariantDispatchTest.php`, `AnonymizeUserAccountActionTest.php`, `tests/Feature/Architecture/UserLifecycleWriteBoundaryTest.php` |
| Создание поста, статус, медиа | `tests/Feature/Actions/CreatePostActionTest.php`, `CreatePostActionMediaVariantDispatchTest.php`, `CreatePostAuthorAnswersTest.php`, `CreatePostRateLimitTest.php`, `tests/Feature/Livewire/UploadPostFormTest.php`, `UploadPostFormImportIntegrationTest.php`, `tests/Unit/Actions/UserFacingImageFlowsUseIngestorTest.php` |
| Модерация постов | `tests/Feature/Actions/ApprovePostActionTest.php`, `ApprovePostActionNotificationTest.php`, `RejectPostActionTest.php`, `HidePostActionTest.php`, `RestorePostActionTest.php`, `FinalizeRemovalActionsTest.php`, `tests/Feature/Domain/ModeratorStaleActorProtectionTest.php`, `PostDeleteModerationRaceTest.php` |
| Удаление, восстановление, purge постов | `tests/Feature/Actions/DeletePostActionTest.php`, `RestoreDeletedPostActionTest.php`, `tests/Feature/Services/PostRetentionPurgeServiceTest.php`, `PostGraphDeletionDepthTest.php`, `tests/Feature/Database/CommunityFkSafetyTest.php`, `tests/Feature/Console/PostsPurgeCommandTest.php` |
| Счётчики, hot score, лента | `tests/Feature/Actions/RecalculatePostCountersActionTest.php`, `RecalculatePostScoreActionTest.php`, `VotePostActionHotScoreTest.php`, `AddCommentActionHotScoreTest.php`, `tests/Unit/Support/Ranking/HotScoreCalculatorTest.php`, `tests/Feature/Queries/FeedQueryTest.php`, `tests/Feature/Actions/VoteCacheInvalidationPlaceholderTest.php` |
| Уведомления | `tests/Feature/Notifications/NotifyFollowersAboutNewPostActionTest.php`, `PreventDuplicateFollowedAuthorNotificationsTest.php`, `PostCommentedNotificationTest.php`, `PostApprovedNotificationTest.php`, `NotificationLifecycleSafetyTest.php`, `tests/Feature/Actions/AddCommentActionNotificationTest.php` |
| Комментарии и ответы | `tests/Feature/Actions/AddCommentActionTest.php`, `AddCommentRateLimitTest.php`, `DeleteCommentActionTest.php`, `HideCommentActionTest.php`, `RestoreCommentActionTest.php`, `tests/Feature/Domain/CommentTombstoneLifecycleTest.php`, `CommentParentPostLifecycleTest.php`, `tests/Feature/Queries/CommentListQueryTest.php`, `CommentListQueryTombstoneTest.php`, `tests/Feature/Livewire/CommentsSectionTest.php`, `CommentFormTest.php` |
| Голоса | `tests/Feature/Actions/VotePostActionTest.php`, `VoteCommentActionTest.php`, `VoteRatingOptionActionTest.php`, `VoteRateLimitTest.php`, `tests/Feature/Livewire/PostVotingTest.php`, `CommentVotingTest.php`, `RatingVotingTest.php`, `tests/Feature/Database/PostVotesTableTest.php`, `CommentVotesTableTest.php`, `RatingVotesTableTest.php`, `tests/Feature/Models/RatingModelsTest.php` |
| Медиа: загрузка, варианты, lifecycle | `tests/Feature/Support/Media/ImageUploadStorerTest.php`, `tests/Feature/Services/Media/GdImageIngestorTest.php`, `MediaVariantGeneratorTest.php`, `MediaVariantWriterTest.php`, `MediaReferenceCheckerTest.php`, `MediaLifecycleServiceEligibilityTest.php`, `MediaLifecycleServicePurgeTest.php`, `MediaOrphanScannerTest.php`, `MediaAuditServiceTest.php`, `tests/Feature/Jobs/GenerateMediaVariantsJobTest.php`, `RunMediaAuditJobTest.php`, `tests/Feature/Console/MediaPurgeCommandTest.php`, `MediaPurgeScheduleTest.php`, `tests/Feature/Filament/MediaDiagnosticsPageTest.php`, `tests/Unit/Enums/MediaStatusTest.php` |
| URL-импорт | `tests/Feature/Import/StoreImportedImageActionTest.php`, `ImportFromUrlActionTest.php`, `RemoteImageIngestIntegrationTest.php`, `SafeImportHttpClientTest.php`, `DnsRebindingRegressionTest.php` |
| Фоновые задания, планировщик, блокировки | `tests/Feature/Console/MediaPurgeScheduleTest.php`, `PostsPurgeScheduleTest.php`, `ContentCleanupScheduleTest.php`, `tests/Feature/I18n/GenerateProjectTranslationChunkJobTest.php`, `ProjectTranslationGenerationStoreTest.php`, `tests/Feature/Concurrency/CategoryDeletionAndSettingsLockTest.php`, `ProjectSettingsFirstWriteRaceTest.php`, `tests/Feature/Observability/SentryExceptionIntegrationTest.php`, `tests/Feature/Services/Media/FailedMediaJobReaderTest.php` |
| Инфраструктура очереди и деплоя | `tests/Feature/Architecture/DeployTest.php`, `InstallBootstrapServicesTest.php`, `ProvisionTargetTest.php`, `RestoreTargetRuntimeQuiesceTest.php`, `EnvironmentContractTest.php`, `EnvironmentContractCompletenessTest.php`, `DeploymentTargetRegistryTest.php` |
| ИИ (переводы) | `tests/Feature/TranslationEngine/OpenAiTranslationProviderTest.php`, `TranslationServiceTest.php`, `TranslationProviderRouterTest.php`, `TranslationEngineArchitectureGuardTest.php`, `tests/Feature/I18n/TranslationAiTelemetryTest.php`, `TranslationAiPricingTest.php`, `tests/Feature/Docs/TranslationEngineDocsTest.php` |
| Админка Admin v2 | `tests/Feature/Admin/AdminShellTest.php`, `AdminGlobalSearchTest.php`, `AdminDesignSystemSeparationTest.php`, `AdminUiKitPageTest.php`, `tests/Feature/Docs/AdminDesignDocsTest.php`, `tests/Feature/Filament/*` |
| Архитектурные стражи | `tests/Feature/Architecture/ReleaseBookkeepingTest.php`, `HttpAndDatabaseBoundariesTest.php`, `ArchitectureReviewGuardrailsTest.php`, `tests/PHPStan/*` |

### G2. Что дополнить по этапам

| Этап | Регрессионные тесты, которые нужно дополнить или добавить |
|---|---|
| MR 1 | `UserRoleTest` (четыре роли); `UserLifecycleTest` (персона: панель закрыта при любом статусе); `UserPolicyTest` (персона санкционируема, не помечается доверенной); `UserResourceTest` (список и страница с персоной рендерятся, фильтр роли); `AuthenticationTest`, `SocialLoginTest`, `SocialAccountClaimTest`, `PasswordResetTest`, `TombstonedSessionTest` (персона отвергнута всеми путями, живая сессия завершается); `CreatePostActionTest` (персона публикует по подтверждённому email, не по роли; админ/модератор освобождены явно); `UpdateUserAccountActionTest` (пароль персоне не задаётся, переход `user ↔ persona` закрыт); `RegistrationTest` (роль не принимается из ввода) |
| MR 2 | Новые тесты `CreatePersonaAction`/`UpdatePersonaAction`/аватара (по образцу `AvatarMediaLifecycleTest`, `UpdateUserProfileActionMediaVariantDispatchTest`); Filament-тесты страниц Personas (по образцу `UserResourceTest`); `AdminShellTest` (новые destination и иконки); `UserLifecycleWriteBoundaryTest` при записи статуса; `AdminDesignSystemSeparationTest` для новых Blade |
| MR 3 | `CreatePostActionTest` (исторические даты, готовый ассет: пропуск storer, отказ на trashed/не Ready/не PostImage, флаг подписчиков); `CreatePostActionMediaVariantDispatchTest` (без повторной генерации); `NotifyFollowersAboutNewPostActionTest`; `AddCommentActionTest`/`AddCommentActionNotificationTest` (дата, подавление уведомления); `VotePostActionTest`/`VoteCommentActionTest`/`VoteRatingOptionActionTest` (режим «обеспечить», `castAt`, публичный переключатель не изменён); `PostVotingTest`/`CommentVotingTest` (UI без изменений); новый grep-тест на недоступность доверенных параметров из презентации |
| MR 4 | Новые тесты модели/Actions плана; `MediaReferenceCheckerTest` (третий источник, число запросов); `MediaAuditServiceTest` и `MediaDiagnosticsPageTest` (ассет плана не «неиспользуемый», Release не предлагается); `MediaLifecycleServicePurgeTest` (план удерживает ассет); Filament-тесты редактора; `StoreImportedImageActionTest` без изменений |
| MR 5 | Новые тесты диспетчера (`ContentCleanupScheduleTest` как образец для выражения расписания и `withoutOverlapping`), Job (контракт `tries`/`timeout` по образцу `GenerateProjectTranslationChunkJobTest.php:44-62`; повторная доставка ничего не делает), Action исполнения (транзакция, claim, `finished_at`, `RecalculatePostScoreAction`), экрана Action queue; `VoteRateLimitTest`/`CreatePostRateLimitTest`/`AddCommentRateLimitTest` при изменении ключей лимитов |
| MR 6 | `CommentTombstoneLifecycleTest`/`CommentParentPostLifecycleTest` (каскадная отмена по тем же предикатам); тесты зависимостей и причин отмены; тест новой причины исключения |
| MR 7 | Новые тесты правил, лимитов и `Off`; `PostCommentedNotificationTest` при добавлении триггеров |
| MR 8 | Тесты клиента по образцу `OpenAiTranslationProviderTest` (`Http::preventStrayRequests`, статусы, транспорт, отказ модели, утечка ключа); тесты учёта по образцу `TranslationAiTelemetryTest`; `EnvironmentContractCompletenessTest` и `EnvironmentContractTest` для новых ключей; `TranslationEngineArchitectureGuardTest` остаётся зелёным (новый код вне `app/Support/TranslationEngine`) |
| MR 9 | `MediaStatusTest`/`MediaVisibilityTest` при расширении enum; `MediaVariantGeneratorTest` (порядок анализ → Ready → варианты); `FilesystemMediaUrlResolverTest`, `PostImagePresenter*Test`, `AvatarUrlResolverResponsiveTest` (приватный ассет без URL); `GdImageIngestorTest` без изменений (повторной нормализации нет) |

---

## H. Open Technical Questions

1. **Имя очереди и воркер для заданий Content Studio.** Воркер каждой цели слушает ровно
   одно имя (`--queue=rateguru-staging`/`rateguru-tits-guru`, `numprocs=1`); `onQueue()` в
   коде не используется. Открыто: достаточно ли очереди по умолчанию, или нужна
   выделенная с изменением реестра целей и установщика. Подтверждённые ограничения:
   значение `REDIS_QUEUE` на хостах операторское и не проверяется скриптами. Решать на
   этапе 5, при необходимости отдельной инфраструктурной задачей.
2. **Хранилище блокировок и мьютекса планировщика.** Медиа-блокировки намеренно
   используют `Cache::store('database')` со ссылкой на запрет Redis в `AGENTS.md`; при
   этом очередь и хранилище черновиков переводов работают на Redis, а `CACHE_STORE` на
   хостах пуст в шаблонах (значение операторское). Открыто: какой store считать
   каноническим для блокировок исполнителя и `withoutOverlapping()` новой команды.
   Решать на этапе 5.
3. **Лимиты скорости для доверенного исполнителя.** `ActionRateLimiter` срабатывает до
   транзакции с ключом по пользователю (загрузка 5/600 с, комментарии 10/60 с, голоса
   60/60 с). Открыто: пейсинг `run_after` без изменения кода или отдельный ключ/флаг для
   исполнителя. Подтверждено: `tests/Feature/Support/ActionRateLimiterTest.php` требует
   положительных лимитов у каждого настроенного действия. Решать на этапе 5.
4. **Политика уведомлений для исторического контента.** Подавлять ли уведомления
   подписчикам и автору поста целиком (флаги) или допускать их с реальной датой.
   Подтверждено: отложить уведомление «в прошлое» нельзя — колокольчик показывает дату
   строки уведомления. Решать на этапах 3 и 5.
5. **Домен синтетического email персон и поведение почтовых каналов.** Образец —
   `@deleted.invalid` у tombstone. Открыто: конкретный домен и нужно ли глушить любые
   `mail`-уведомления персонам (сейчас mail используют только уведомления о социальных
   аккаунтах, недостижимые для персон). Решать на этапе 1.
6. **Триггер автоответов.** Уведомления автору родительского комментария нет; есть только
   `PostCommentedNotification` автору поста (database). Открыто: детектировать ответы
   реальных пользователей запросом к `comments` или добавить новое уведомление/событие.
   Решать на этапе 7.
7. **Журнал ответов как часть таблицы действий или отдельная таблица.** Решать на этапе 7.
8. **Ставки, модели и параметры анализа изображений.** Переводная конфигурация знает одну
   модель и ставки `gpt-6-luna`; клиенту Content Studio нужны модель на задачу и свои
   ставки. Открыто: имена ключей окружения (переиспользовать `TRANSLATION_OPENAI_API_KEY`
   нельзя по правилу именования). Решать на этапе 8.
9. **Семантика «ожидает проверки» для SFW/NSFW.** Через `MediaVisibility::Private` (гейт
   показа, файл остаётся под публичным симлинком) или через `MediaStatus` (гейт вариантов).
   Решать на этапе 9 вместе с выбором модели.
10. **Расхождения макета с утверждёнными решениями и дизайн-контрактом.** Подпись шага
    Publication в макете («Real timestamps such as created_at … are never backdated»)
    противоречит решению об исторических датах; дерево обсуждения макета допускает
    `Nested reply` (третий уровень), который код отвергает; выбор автора из `Editorial
    accounts`/`Users` противоречит решению «только персоны»; drawer редактора персоны
    680 px при максимуме кита 480; сохранение Reply rules из нижней панели против
    требования контракта сохранять из верхней; размер страницы Action queue 15 против 25;
    фильтр статуса выпадающим списком против вкладок NAV-03. Подтверждено: по контракту
    эталон побеждает документ, кроме задокументированных отступлений
    (`docs/design/admin/design-contract.md:30-31, 1203-1307`). Решать при постановке MR 4–7
    как записи в таблице отступлений.
11. **Куда подключать запись успешного входа.** Единственный общий хук — событие
    `Login`, но оно срабатывает до гейта tombstone в `AuthenticateUserAction` (`Auth::attempt`
    уже вызвал `login()`), повторяется при оживлении сессии «запомнить меня» и при входе в
    `/admin/login` Filament, а id сессии регенерируется уже после события. Альтернатива —
    явные вызовы в трёх контроллерах входа (образец `CompletePendingSocialLinkAction`), но
    они не видят вход в админку. Подтверждено: `trustProxies` не нужен сегодня (nginx —
    граница сети), а `SESSION_DRIVER` на хостах — операторское значение, поэтому
    заполненность `sessions.ip_address` неизвестна. Решать при проектировании географии.

---

## Приложение. Ответы на вопросы аудита по областям

### П2. Пользователи и роли

1. **Файлы при добавлении `UserRole::Persona`** — таблица раздела C (строки с этапом 1):
   `UserRole`, `User`, `ListUsers`, `EditUser` и его Blade, `ModeratesUsers` (подсказка),
   `UpdateUserAccountAction`, `CreatePostAction`, `ListPosts`, семь гейтов входа,
   `EnsureAccountIsNotTombstoned`, `UserFactory`, `NoDirectControllerPermissionCheckRule`,
   `docs/architecture/user-lifecycle.md`, перечисленные тесты.
2. **Где меняются условия роли** — `CreatePostAction.php:88-89`, `ListPosts.php:553`,
   `ListUsers.php:149, 161-168`, `EditUser.php:198-203, 222`. Прочие ролевые проверки
   (`UserPolicy::markTrusted/canSanction/viewAdmin`, `MarkUserTrustedAction.php:43`,
   `ModeratesUsers.php:161, 171`, `ModerationUsersQuery::staff()`, все `isAdmin()`/
   `isModerator()`) корректны для не-персонала и не меняются.
3. **Безопасное создание управляемого аккаунта** — раздел E7: `email` уникален и
   обязателен (`0001_01_01_000000_create_users_table.php:17`), поэтому нужен
   немаршрутизируемый адрес; `password` nullable с миграции
   `2026_09_11_100000_make_users_password_nullable.php`; `email_verified_at`, `status`
   (по умолчанию `active`), `trust_level` (хук модели); без `Registered`; создание только
   через Action (PHPStan-правило `NoPresentationModelMutationRule`).
4. **Переиспользуемые механизмы профиля и аватара** — публичный профиль `/u/{username}`
   (`app/Livewire/Profile/ProfilePage.php`) не ветвится по роли; поля `display_name`,
   `bio`, `profile_website_url`, `rating_activity_visibility`
   (`app/Support/Profile/ProfileValidationRules.php`); аватар — пять шагов
   `UpdateUserProfileAction` и `AvatarUrlResolver`.
5. **Проверки при смене роли обычного пользователя** — `UserPolicy::canSanction` (активный
   админ, не себя, не другого админа, не tombstone), парная блокировка и повторная
   авторизация (`LocksAndAuthorizesModerationPair`), подтверждение смены роли в UI
   (`EditUser.php:117-121`), `Rule::enum`. Отсутствуют: защита «последний админ», аудит
   смены роли в `moderation_logs`, инвалидация сессии при смене роли.
6. **Тесты для расширения** — раздел G2, этап 1.

Не проверено: внутреннее устройство мокапа редактора персоны (см. П10).

### П3. Публикации

1. **Как создаётся публикация** — только `UploadPostForm::submit()` →
   `CreatePostAction::handle()`; контроллеров, API и команд создания постов нет.
   Последовательность: политика → лимит → валидация категории и ответов автора → файл
   (`ImageUploadStorer`) до транзакции → транзакция (`lockActor`, статус, `MediaAsset`,
   `Post::create`, теги, ответы автора) → компенсация файла при ошибке → лог
   `posts.created` → job подписчикам при `Published` → job вариантов `afterCommit()`.
2. **Статус** — `Published` при `trust_level >= 10` и (`hasVerifiedEmail()` или роль не
   `User`), иначе `Pending` (`CreatePostAction.php:88-91`).
3. **Даты** — `created_at`/`updated_at` ставит Eloquent (`Post::create` без них);
   `published_at = now()` только при публикации сразу, иначе `null` до одобрения
   (`ApprovePostAction.php:43`, `RestorePostAction.php:43` сохраняют существующее).
4. **Подтверждение публикации** — `ApprovePostAction` под блокировкой поста из `Pending`,
   лог `ApprovePost`, `PostApprovedNotification` автору, job подписчикам.
5. **Уведомления** — `CreatePostAction.php:136-147`, `ApprovePostAction.php:65-97`,
   `AddCommentAction.php:135-158`; все database и синхронные (job подписчикам — очередь).
6. **Связь с медиа** — `posts.image_asset_id` (FK `media_assets`, `nullOnDelete`),
   записывается только в `CreatePostAction.php:105`; `Post::imageAsset()`.
7. **Историческое время** — раздел E4: необязательные поля `CreatePostData`, применение в
   `CreatePostAction.php:91, 97-106`.
8. **Пост из сохранённого `MediaAsset`** — раздел C: `imageAssetId` в DTO, ветка в
   `CreatePostAction.php:93-95`, пропуск storer в `:66-68`, проверки kind/status/trashed.
9. **Без дублирования** — исполнитель вызывает `CreatePostAction::handle()` с DTO; никакой
   собственной записи в `posts`. `UpdatePostAction` не существует; редактирование
   опубликованного поста в продукте отсутствует.

### П4. Комментарии и ответы

1. **Обычный комментарий** — `CommentForm::submit()` → `AddCommentAction::handle(user,
   post, body)`; проверки: `canComment()` (только `Active`), `canReceiveComments()`,
   лимит 10/60 с, тело 1–1000 символов; статус всегда `Visible` (очереди модерации нет).
2. **Ответ** — `CommentsSection::submitReply()` → `CommentReplyService::createReply()` →
   `AddCommentAction::handle(..., parent)`.
3. **Глубина** — один уровень ответов (раздел D6).
4. **Доступность родителя** — предварительно `canStartReply()`; авторитетно —
   перечитывание под блокировкой (`AddCommentAction.php:107-119`): тот же пост, корень,
   `Visible`, не удалён.
5. **Исторические даты** — `Comment::$guarded = []`; необязательный `$createdAt` в
   `handle()` и `createReply()`, применяемый в `Comment::create([...])`.
6. **Запрет ответа на удалённый/скрытый** — уже обеспечен (`:107-119`); исполнитель
   дополнительно проверяет `canStartReply()` и трактует исключение как отмену.
7. **Компоненты для дерева обсуждений** — `AddCommentAction`, `CommentReplyService`,
   предикаты `Comment`/`Post`, `CommentListQuery` (порядок по датам); уведомления автору
   родительского комментария нет.

### П5. Голосования и рейтинги

1–2. **Где создаётся, обновляется, снимается** — таблица раздела D4.
3. **Запрет голосовать за своё** — `PostPolicy::vote` (`:73-77`) для постов и рейтингов;
   инлайн в `VoteCommentAction.php:41-43, 89-92`. Подтверждение email и доверие на
   голосование не влияют.
4. **Один голос** — уникальные индексы плюс `lockForUpdate` строки голоса (посты,
   комментарии) и блокировка `RatingGroup` плюс `upsert` (рейтинги).
5. **Историческая дата** — `castAt` в трёх Actions; `PostVote` принимает `created_at`
   через `create`, `CommentVote` — `forceFill()`, `RatingVote` — значение в `upsert`.
6. **Исключение повторного исполнения** — режим «обеспечить» внутри транзакции плюс
   статус `done` строки действия; `VoteRatingOptionAction` идемпотентен уже сейчас.
7. **Через существующие Actions** — все три; ни одной записи в таблицы голосов извне
   Actions. Отдельных таблиц голосов для персон не создаём.

### П6. Медиа

1. **Путь изображения** — `UploadPostForm` (валидация `image|mimes|max|dimensions`) →
   `CreatePostData::$image` → `ImageUploadStorer::store()` → `GdImageIngestor::ingest()`
   (формат по содержимому, лимиты, EXIF, перекодирование, очистка метаданных) →
   `FilesystemMediaStorage::storeNormalized()` (путь `media/post-images/Y/m/{uuid}.ext`) →
   в транзакции `MediaAssetCreator::create()` → `Post::create` → `GenerateMediaVariantsJob`
   (`afterCommit()`) → `MediaVariantGenerator` (варианты `post_feed_640/1280`,
   `post_detail_1920` webp, `open_graph` jpeg; для аватаров `avatar_128/256`).
2. **Проверка, нормализация, изменение размеров** — проверка и нормализация в
   `GdImageIngestor`, размеры только в варианте (`GdImageVariantProcessor`).
3. **Когда создаётся `MediaAsset`** — внутри транзакции `CreatePostAction`, перед `Post`.
4. **Сохранение для черновика** — раздел E8.
5. **Защита от удаления** — третья ссылка в `MediaReferenceChecker` (раздел D7).
6. **Расширение `CreatePostAction`** — раздел C.
7. **Точка SFW/NSFW** — `MediaAssetCreator::create()` с диспетчеризацией `afterCommit()`
   Job по id ассета (раздел E8).
8. **Без повторной обработки** — Job читает уже нормализованный мастер через
   `MediaStorage::readStream()`; варианты не пересчитываются.

Прочее: `ImageInputSource::Generated` не используется; поля `checksum_sha256`,
`processing_error`, `metadata` в `media_assets` приложением не заполняются; `media:audit`
не запланирован (запускается из Media diagnostics); `MediaStatus::Uploaded/Processing/
Failed` приложением не записываются.

### П7. Фоновые задания и планирование

1. **Хранение утверждённых действий** — таблица `studio_plan_actions` (раздел E2).
   Полный список таблиц приложения: `cache`, `cache_locks`, `categories`, `comment_votes`,
   `comments`, `failed_jobs`, `follows`, `job_batches`, `jobs`, `media_assets`,
   `media_audit_issues`, `media_audit_runs`, `media_variants`, `moderation_logs`,
   `notifications`, `password_reset_tokens`, `post_author_answers`, `post_saves`,
   `post_tag`, `post_votes`, `posts`, `project_settings`, `rating_groups`,
   `rating_options`, `rating_votes`, `reports`, `sessions`, `social_accounts`, `tags`,
   `translation_ai_provider_calls`, `users` — ничего похожего на планы или действия.
2. **Передача обработчикам** — ежеминутный диспетчер и Job с id (раздел E3); отложенные
   `delay()` не рекомендуются (не отзываются при отмене, невидимы администратору).
3. **Одновременное выполнение** — `lockForUpdate` + условный `UPDATE` статуса;
   опционально атомарная блокировка `Cache::store('database')`.
4. **Однократное применение** — доменная запись и статус `done` в одной транзакции.
5. **Зависимости** — `parent_action_id` и `result_type/result_id` (раздел E5).
6. **Исторические даты** — доверенные параметры Actions (раздел E4).
7. **Отмена зависимых** — предикаты и каскад по поддереву (раздел E5).
8. **Продолжение независимых** — отбор по строкам, сиблинги не затрагиваются.
9. **Бизнес-очередь и техническая** — раздел E6.

Факты инфраструктуры: Redis установлен и используется на хостах (`install-bootstrap-runtime`
ставит `redis-server` и `php8.5-redis`; `config/translation.php:43` жёстко задаёт
`cache_store => 'redis'`); `jobs` и `job_batches` на хостах не используются,
`failed_jobs` — используется (`QUEUE_FAILED_DRIVER` по умолчанию `database-uuids`);
Sentry получает падения заданий единым путём (`bootstrap/app.php:38-47`); `queue:retry`/
`queue:prune-failed` не запланированы; `health-check` и `status` очередь не проверяют.

### П8. ИИ

1. **Переиспользуемое как рецепт** — транспорт `Http` (`OpenAiTranslationProvider.php:
   110-116`), конечная точка `{base_url}/responses` (`:357-367`), чтение настроек в
   конструкторе и ключ только в заголовке (`:65-84`), `store: false`, строгий `json_schema`
   (`:208-215`), классификация ошибок (`:117-123, 294-310`), разбор `output[].content[]`
   с `output_text`/`refusal`, `usage`, `id`, `x-request-id` (`:139-147, 232-266`),
   `DomainLogger`, `BigDecimal`-стоимость, механизм контракта окружения, фикстуры тестов.
2. **Строго переводное** — `TranslationProvider` (`translateBatch`), `TranslationService`,
   `TranslationPromptBuilder`, `TranslationBatchChunker`, DTO пакетов/элементов,
   `TranslationProviderRouter` (и есть тот «реестр провайдеров», который отложен),
   `TranslationErrorCode` с переводными сообщениями, `TranslationAiProviderCall` и
   `TranslationAiCallRecorder` (NOT NULL `target_locale`, счётчики элементов).
3. **Минимальные компоненты для Content Studio** — раздел F, MR 8.
4. **Ограничения** — только `input_text`; `input_image`, файлы, moderation endpoint в коде
   отсутствуют; лимиты запроса 50 элементов / 60 000 символов, пакета 500 / 500 000;
   тест закрепляет ключи тела запроса (`OpenAiTranslationProviderTest.php:84`); движок
   ничего не кеширует и не дедуплицирует платные вызовы.
5. **Отложить** — общий реестр провайдеров и маршрутизация по классификации данных,
   единая таблица учёта и страница статистики, общий ключ `OPENAI_API_KEY`, политика
   повторов/фоллбэка, Batch API, SDK.

### П9. География и история входов

- **Поля страны/города в `users`** — отсутствуют. Профильные колонки: `display_name`,
  `bio`, `profile_website_url`, `rating_activity_visibility`
  (`2026_06_11_102934_add_profile_fields_to_users_table.php`), `locale`,
  `theme_preference`, `notify_followed_author_posts`, `avatar_asset_id`, `anonymized_at`,
  `session_generation`. Таймзоны на пользователя нет.
- **Редактирование публичного профиля** — `ProfileController` + `ProfileUpdateRequest`
  (идентичность), Livewire `EditProfileForm` → `UpdateUserProfileAction` (поля профиля и
  аватар), правила в `ProfileValidationRules`. Точка для страны/города — те же форма,
  правила и Action.
- **События входа** — единый хук `Illuminate\Auth\Events\Login` →
  `RememberSessionGenerationOnLogin` (`AppServiceProvider.php:73-75`) для пароля, OAuth,
  регистрации, «запомнить меня» и входа в `/admin/login`; `app/Listeners` и
  `EventServiceProvider` отсутствуют. Журнала входов (таблицы, модели, колонок
  `last_login*`) нет; `sessions` хранит `ip_address` и `user_agent`, но их никто не читает,
  а заполненность зависит от `SESSION_DRIVER` хоста. Предостережения для будущего
  журнала: событие срабатывает до гейта tombstone (`AuthenticateUserAction.php:39-52`),
  повторяется при оживлении «запомнить меня», id сессии регенерируется после события
  (`AuthenticatedSessionController.php:41`, `SocialAuthController.php:116`).
- **IP** — `trustProxies` не настроен (`bootstrap/app.php:21-37`); nginx завершает TLS и
  вызывает PHP-FPM напрямую через unix-сокет (`infrastructure/config/nginx/rateguru-staging:108`),
  `proxy_pass`/`real_ip`/CDN перед приложением нет (`infrastructure/runbooks/public-site.md:21-23,
  165`), поэтому `$request->ip()` — реальный клиент. Единственное применение сегодня —
  ключ троттлинга входа `email|ip` (`AuthenticateUserAction.php:75-78`); доменные лимиты
  ключуются только по `user_id` (`RateLimitKey.php`). Телеметрия считает IP персональными
  данными и стирает его (`NightwatchPrivacy.php:148-157`, `SENTRY_SEND_DEFAULT_PII=false`);
  ключ `ip` в редакторе логов (`config/observability.php:22-29`) отсутствует.
- **Файлы вне релизов** — `<target>/shared/.env`, `shared/storage`, `public/storage` →
  `shared/storage/app/public` симлинками из релиза (`deploy:1796-1812`); каталоги
  `framework/*`, `logs`, `app`, `app/public` создаёт деплой (`:1885-1926`). Весь
  `shared/storage/app` без исключений входит в ночной бэкап (`backup:402-405`) и целиком
  заменяется при restore (`restore-storage:22-33`); другие каталоги под `shared/` ничем не
  создаются. Новые ключи окружения проходят через `environment-contract.json`, иначе деплой
  отказывает (`infrastructure/runbooks/deployment-targets.md:409-413`). Внешних загрузок
  данных по расписанию нет; пакета geoip/maxmind в `composer.lock` нет.
- **Локаль и время** — `config/app.php:68` `timezone => 'UTC'`; таймзоны на пользователя
  нет; даты рендерятся относительно (`diffForHumans()`) или в UTC.
- **Конфликты** — анонимизация стирает жёсткий список колонок
  (`AnonymizeUserAccountAction.php:63-88`) и приватное состояние; страна/город и журнал
  входов должны войти в этот список и в таблицу `docs/architecture/user-lifecycle.md`;
  FK журнала входов — `restrictOnDelete` (физического удаления пользователей нет);
  приватность профиля по умолчанию (`docs/profile/profile-privacy-rules.md`, закрытый DTO
  `UserPublicProfilePresenter`); `ProfileActivityVisibility` регулирует только активность
  рейтингов; поля формы профиля дублируются в компоненте, правилах и Action
  (`EditProfileForm.php:19-29`, `ProfileValidationRules.php:13-18`,
  `UpdateUserProfileAction.php:28-35`); паритет переводов на 39 языков
  (`tests/Feature/Architecture/TranslationParityTest.php`); портируемость на три СУБД
  (тип `string(45)` для IP как у `sessions.ip_address`). Устаревшие документы:
  `docs/profile/profile-2.md:13-25` ещё описывает удалённые `avatar_path`/`avatar_url`.

### П10. Административный интерфейс

- **Регистрация страниц** — автообнаружение `app/Filament/Resources` и `app/Filament/Pages`
  (`AdminPanelProvider.php:119-120`); секция через `getNavigationGroup()` и константы
  `AdminNavigationGroup`; иконка в `AdminShellNavigation::ICONS` (обязательна по тесту).
- **Создание и редактирование** — образец `EditUser`: Livewire-страница с черновиком,
  `#[Locked]` id, валидация `rules()`, сохранение из верхней панели через события
  `save-*`/`discard-*` (хук `TOPBAR_END` в `AdminPanelProvider`), запись только через
  Action. Create-страниц у Users нет; у Rating groups есть `CreateRatingGroup`.
- **Фильтры** — URL-свойства `#[Url]` и серверные запросы (`ModerationUsersQuery` и т.п.);
  компонент `x-admin.ui.filter-dropdown`.
- **Повторно используемые элементы** — `x-admin.ui.*` по контракту (поля, textarea с
  presets, segmented, radio-cards, switch, combobox, drawer, dialog, toast, badge, range
  slots), `ModerationListFormat`, партиал причины модерации.
- **Права** — Filament не в strict-режиме: без метода политики доступ разрешён всем
  пользователям панели; `UserResource::canEdit()` → `UserPolicy::manage`; именованные
  Gate (`moderate-content`, `manage-project-settings`, `view-media-diagnostics`). Для
  Content Studio нужны собственные abilities/Gate (администратор; доступ модераторов —
  продуктовое решение).
- **Макет** — `docs/design/admin/reference/original/RateGuru-Admin.html` (самораспаковывающийся
  бандл, редактировать запрещено, контрольная сумма закреплена
  `tests/Feature/Docs/AdminDesignDocsTest.php`) содержит секцию Content Studio:
  Publications & plans с редактором публикации, Personas с редактором персоны, Reply
  rules, Reply log, Action queue (`docs/design/admin/design-contract.md:18`). В плане
  миграции (`docs/design/admin/migration-plan.md`) Content Studio не упомянут. Детали
  полей макета, подтверждённые из бандла, перечислены ниже в П10а; что не найдено, не
  домысливается.

### П10а. Содержимое макета Content Studio

Источник: распакованные ресурсы бандла `RateGuru-Admin.html` (манифест `__bundler/manifest`,
gzip+base64). Экраны: `StudioPublications`, `StudioEditor`, `StudioImage`, `StudioTexts`,
`StudioPostVotes`, `StudioVotes`, `StudioDiscussion`, `StudioPublish`, `StudioPersonas`,
`PersonaEditor`, `StudioReplyRules`, `StudioReplyLog`, `StudioQueue`; вспомогательные
`AuthorPicker`, `VoterSelect`, `DateTimeField`, `MediaPicker`, `ConfirmDialog`. Ниже —
только буквальные подписи макета (на английском, как в файле). Что в макете отсутствует,
отмечено отдельно; детали не домысливаются.

**Навигация.** Секция `Content Studio`: `Publications & plans` (иконка `newspaper`),
`Personas` (`bot`), `Reply rules` (`messages-square`), `Reply log` (`scroll-text`),
`Action queue` (`list-checks`, счётчик ошибок тоном danger).

**Publications & plans.** Статистика `Total publications`, `Drafts`, `Scheduled`,
`Published`; поиск `Search title, ID or author`; фильтр `Status: All | Drafts | Scheduled |
Published | Archived`; колонки `Publication`, `Author`, `Date · launch`, `Comments`, `State`,
`Actions`. Состояния: `Draft` (outline), `Scheduled` (neutral), `Publishing` (warning),
`Published`, `Plan running`, `Plan complete` (success), `Error` (danger), `Cancelled`
(neutral). Действия строки: `Open editor`, `Open plan`, `Unschedule`, `View errors`,
`View post`. Диалог `Unschedule CS-…?`: «The publication goes back to drafts. Its pending
actions are cancelled; the prepared texts and discussion are kept.» Боковая панель плана:
`Author`, `Editorial date`/`Launch`, `Actually ran`, `Comments · votes`, счётчики `Done`,
`Upcoming`, `Errors`, `Cancelled`, кнопки `Open in queue`, `Edit plan`, `Unschedule`.

**Редактор публикации.** Шаги: `Image` — `Upload & analysis`; `Post` — `Texts, author &
votes` (вкладки `Titles & texts`, `Votes`); `Discussion` — `Comments & their votes`;
`Publication` — `Plan & timeline`. Подвал: `Back`, `Step n of 4`, `Continue`, `Review and
confirm`. Диалог `Confirm this publication?` со сводкой (`Post: +x / −y votes and n answers
from m personas`, `Discussion: …`, `n actions in the plan`, `n overdue actions`).

**Шаг Image.** Источники `Upload`, `From link`, `From social post`; дропзона `JPEG, PNG or
WebP, up to 20 MB`; платформы `Instagram`, `Reddit`, `X`, `Facebook`, `Pinterest`, `TikTok`,
`Flickr`; карточка `Photo analysis` — «Technical context for the generators. Not shown to
visitors.», состояния `Analysing`, `Done`, `Not started`, кнопка `Re-run analysis`; детали
`File`, `Dimensions`, `Size`, `Format`, `Source`, `Source link`. Расхождения с кодом: лимит
загрузки в приложении 5120 KB (`config/uploads.php`), а не 20 MB; `ImportProvider` знает
`DirectImage`, `OpenGraph`, `Facebook`, `Instagram`, `X`, `Pinterest`, но не Reddit, TikTok,
Flickr.

**Шаг Post · Titles & texts.** `Tone of new variants`: `Playful`, `Humour`, `Cheeky`, `Irony`,
`Neutral`; колонки `10 titles`, `10 descriptions`, `Generate 10 more`; `Final title`
(`n / 120`), `Final description` (`n / 500`); `Post author` с `Pick random`, группы
`Editorial accounts`, `Users`, `Personas`, бейджи `Persona`, `Banned · can't publish`,
`Shadowbanned · can't publish`, `Deleted account · can't publish`; подсказка «A persona
publishes this post. It can't vote on its own post, so it's left out of the post voters.»
Расхождение с решением: макет допускает авторов `Editorial accounts` и `Users`; утверждено —
автор выбирается только из персон.

**Шаг Post · Votes.** `Up and down votes on the post` (`Up votes`, `Down votes`), `Rating
groups` с ответами на вопросы (`Answers`, `Even split`), вкладка `Timing` (`First vote
after`, `Spread over`, чипы `5 min` … `1 week`, «Nothing can arrive before it»), `Post voters`
(`Random`/`Choose`, `Number of personas`, `Pick others`, `Author of this post · can't vote on
it`).

**Шаг Discussion.** `Discussion settings`: `Total comments` (0–40), `Top-level comments`
(«First-level comments. The rest are replies.»), `Number of personas`, `Personas`
`Random`/`Manual`; `Comment votes`: `Total votes` (0–500), `Positive share`, `Distribution`
`To leaders`/`Even`/`Mixed`; уведомление «Persona votes count like any other account's on
the site. Content Studio lists them so each one can be checked or cancelled.»; `Generate
tree`/`Regenerate tree`; дерево с ссылками `C1`, `↳ C1 · persona`, приоритетами `Main`/`High`,
действиями `Reply`, `Edit`, `Regenerate`, `Delete`; вкладки `Comments`, `Comment votes`,
`Voters`, `Timing`; позиции `Top-level`/`Reply`/`Nested reply`; `Timing`: `Offset from
post`, `Dependency`, `Fix all`, «n actions are planned before what they depend on». Диалог
`Delete C1 and its n replies?` — «Replies can't stay without their parent, so the whole
branch leaves the plan.» Расхождение с кодом: позиция `Nested reply` и диалог с полем
`Replies to` допускают третий уровень, который `AddCommentAction` отвергает (раздел D6).

**Шаг Publication.** `Publication mode`: `Publish now` — «Visible right after confirmation.
Actions are timed from that moment.»; `On a chosen date` — «Future, present or past. Past
dates make actions overdue.»; `Publication date and time`; `Overdue actions`: `Run overdue
now`, `Spread from now`, `Skip overdue` («Actions that depend on them are skipped too.»);
`Final publication card`; `Action plan` с состояниями `Scheduled`, `Ready to run`,
`Overdue`, `Done`, `Error`, `Cancelled`, колонками `Planned`, `Part`, `Action`, `Executor`,
`Runs at`, `State`, подвалом «Nothing is queued until the publication is confirmed; the
queue then records the real execution time of every action.» **Противоречие с утверждённым
решением:** текст макета «The post appears when it's confirmed and keeps {date} as its
editorial date. Real timestamps such as created_at are recorded as actions run and are
never backdated» описывает отдельную «редакционную дату», тогда как решение 1.3 требует
записывать запланированные исторические `created_at`/`updated_at`/`published_at`, а
реальное время хранить в истории исполнения. Решение имеет приоритет; подпись макета
подлежит правке (раздел H).

**Personas.** Статистика `Active`, `Inactive`, `Replies a day`, `Replies · 7 days`; поиск
`Search by login, name or character`; фильтры `Status` (`Active`/`Inactive`), `Engagement`
(`Off`/`Low`/`Normal`/`High`), `Plans` (`Used in plans`/`Not used yet`); колонки `Persona`,
`Character`, `Status`, `Plans`, `Engagement`, `Max a day`, `7 days`, `Now a day`, `Actions`;
действия `Edit`, `Deactivate`/`Activate`.

**Редактор персоны** (drawer 680 px; в ките максимум 480). Подзаголовок «A regular
rateguru.com account run by Content Studio». `Profile`: `Upload photo`, `Generate avatar`,
`Use initials`; `Display name`, `Login` («Public profile: rateguru.com/u/{login}»), `City`,
`Country` («Shown on the profile and used in geo analytics like any user.»); уведомление «On
the site this is a normal account. Its votes, comments and replies count like anyone
else's. Only the admin shows it as automated.» `Character`: `Short description`,
`Character`, `Speech style`, `Topics and typical jokes`, `Never writes about`, `Emoji`
(`None`/`Occasional`/`Frequent`). `Activity`: `Engagement for this account`
(`Off` — «Writes nothing on its own. Prepared comments in publication plans still run.»,
`Low`, `Normal`, `High`), `Max replies a day` (0–50), `Active from`, `Active until`.
`Try it`: `Another reply`. Подвал: «Creates a regular account. Nothing is published until a
plan or reply rule uses it.», `Save persona`. Соответствие постановке: «характер» —
`Character`, «манера речи» — `Speech style`, «темы» — `Topics and typical jokes`,
«ограничения» — `Never writes about`, «интенсивность автоответов» — `Engagement`,
«дневной лимит» — `Max replies a day`; поля `City`/`Country` и часы `Active from/until`
есть в макете, но постановка откладывает географию и не требует почасового расписания.

**Reply rules.** `Overall level` — `Reply to about n %` («0% turns replies off»), расчёт
«About n replies a day»; `When a reply is allowed`: `Who personas reply to` (`Real users
only`/`Users and personas`), глубина диалога персон (1–4), правила `Wait for a real reply
first`, `One persona per user comment`, `Always answer a newcomer's first comment`, `Only
in each persona's active hours`; постоянное правило «personas never answer reported,
hidden or deleted content, and never reply to their own comments»; `Scale down as the
community grows` (`Start reducing at`, `Stop completely at`). Сохранение в макете — нижняя
панель `Discard`/`Save changes`; контракт требует сохранения из верхней панели.

**Reply log.** Статистика `Persona replies · 7 days`, `To real users`, `To personas`,
`Queued now`; фильтры `Replied to` и `Status` (`Queued`/`Sent`/`Cancelled`); колонки `When`,
`Persona`, `Replied to`, `Post`, `Reply`, `Status`, `Action`; чипы `Real user`, `New user ·
first comment`, `Persona`; действие `Cancel` для `Queued`; подвал «persona replies are
regular comments on the site and count like any other account».

**Action queue.** Бейдж «Demo data · not the Laravel queue»; описание «Planned and actual
times are kept separately»; статистика `Pending`, `Running`, `Errors`; фильтры `Status`,
`Type` (`Post`, `Comment`, `Reply`, `Comment vote`, `Post vote`, `Rating answer`),
`Publication`; сортировка `Planned: newest first`/`oldest first`; колонки `Publication`,
`Action`, `Executor`, `Planned`, `Actual`, `State`, `Actions`; состояния `Pending`,
`Running`, `Done`, `Error`, `Cancelled`; действия `Cancel`, `Retry`, `Details`
(`Open plan`, `Open post on site`, `Open comment`); примечание «Historical plan: planned
times follow the editorial date, actual times are real». Размер страницы в макете 15,
контракт требует 25.

**Отсутствует в макете** (подписи из постановки, которых нет буквально): `Auto-reply
intensity` (есть `Engagement`), `Daily limit` (есть `Max a day`/`Max replies a day`),
`Restrictions` (есть `Never writes about`), поле `Avatar` (есть кнопки загрузки/генерации),
отдельное поле `Description` персоны (есть `Short description`). Состояния плана, которых
нет в коде: все — модели плана и очереди в приложении отсутствуют.
