# Content Studio — Phase 0: технический аудит RateGuru

Аналитический этап перед разработкой Content Studio. Документ фиксирует фактическое
состояние ветки `develop` (коммит `bdfbb3e2`, 16 октября 2026): какие существующие
модели, Actions, сервисы, политики, механизмы публикации, модерации, обработки
изображений, голосования и фоновых заданий переиспользуются, что требует точечного
расширения и что придётся создать. Код приложения, миграции, интерфейс, зависимости и
инфраструктура в рамках этапа не менялись. Редакция от 17 октября 2026 учитывает
окончательные архитектурные решения, принятые после обсуждения первой версии аудита, и
последующие технические уточнения (транзакционные границы исполнителя, порядок
блокировок, пересчёт рейтинга в транзакции, удержание исторического `updated_at`,
политика уведомлений, фактический порядок деплоя); фактическая инвентаризация кода
сохранена, изменены выводы и приоритеты этапов.

Как читать документ:

- Ссылка `path/File.php:12-34` указывает на строки файла в `develop` на момент аудита.
  Номера строк сместятся при правках; имена классов и методов остаются ориентиром.
- «Отсутствует» означает: поиск по `app/`, `routes/`, `database/`, `config/`,
  `infrastructure/`, `tests/` не нашёл реализации. Что проверить не удалось,
  вынесено в конец соответствующего раздела и в раздел H (категория H3).
- Решения из постановки (персоны как роль в `users`, ручное создание контента,
  исторические даты через доверенный внутренний механизм, переиспользование
  `MediaAsset`, отказ от универсальной системы ИИ, отложенная география) приняты как
  данность и не пересматриваются. Документ различает три вида утверждений:
  **утверждённые решения** (помечены «решение»), **конкретные изменения существующего
  кода**, необходимые для реализации (раздел C и этапы раздела F), и **будущие
  возможности**, отложенные до своего этапа. Предварительные предложения по именам
  таблиц, полей, статусов и классов помечены как «предложение» и не являются
  техническим контрактом.
- Главный принцип: Content Studio — не отдельная социальная платформа и не независимый
  механизм публикации, а административная система подготовки и исполнения действий от
  имени управляемых пользовательских аккаунтов. Она использует существующие `User`,
  `Post`, `Comment`, `PostVote`, `CommentVote`, `RatingVote`, существующие Actions
  публикаций, комментариев, голосований и модерации, систему `MediaAsset`, Laravel Queue
  на Redis, Scheduler, PostgreSQL и Admin v2. Новое создаётся только там, где
  существующего действительно нет. Сначала — работающие Personas, создание публикаций
  и обсуждений через UI; не универсальная инфраструктура на годы вперёд.
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
персон и правил ответов. Ни один Action не принимает временные метки или готовый
`MediaAsset`. Нет административного пути создания
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
- Каждый технический `save()` — одобрение, пересчёт счётчиков и hot score — переписывает
  `updated_at` реальным временем; без удержания историческое значение, заданное при
  создании, исчезает уже на пути «создать → одобрить».
- Глубина обсуждения — ровно один уровень ответов; дерево глубже отвергается.
- Лимиты скорости по пользователю (загрузка 5/600 с, комментарии 10/60 с, голоса
  60/60 с) применяются к персонам как к обычным пользователям.

**Утверждённые архитектурные решения** (подробно в разделе E):

- Персона остаётся ролью `UserRole::Persona` в `users`; отдельного измерения
  `account_type` и новой системы ролей нет. Все проверки вида `role !== UserRole::User`,
  определяющие административные полномочия, заменяются явной проверкой
  `Admin`/`Moderator`.
- Синтетический email персоны — только технический идентификатор; он никогда не
  считается подтверждённым. Персона не получает пароль, не подтверждает почту, не
  сбрасывает пароль и не привязывает OAuth. Право публиковать даёт утверждение плана
  администратором: исполнитель создаёт пост существующим `CreatePostAction`
  (для персоны он ложится в `Pending`) и в той же транзакции одобряет его существующим
  `ApprovePostAction` от имени утвердившего администратора, сохраняя нужную дату
  публикации.
- Одно запланированное действие = одна строка PostgreSQL = одно логическое задание
  Laravel Queue с единственным аргументом — id действия. Каждое действие атомарно само по
  себе; одной транзакции на весь план нет. Зависимости задают порядок; независимые
  действия друг на друга не влияют. Первая версия работает на существующем одиночном
  воркере каждого окружения без новых очередей, процессов и изменений Supervisor.
- Гарантия — однократное применение результата к доменным данным PostgreSQL (блокировки,
  транзакции, проверка состояния действия, уникальные ограничения), а не «exactly-once
  execution» очереди: Redis допускает повторную доставку, и внешние эффекты (задания
  подписчикам, файлы, обращения к ИИ) гарантией не покрываются. Исполнение действия —
  две транзакционные границы: короткая зафиксированная транзакция захвата
  (`dispatched → running`, `claimed_at`) и основная транзакция с доменной записью,
  необходимыми пересчётами и `running → done`; откат основной оставляет `running`, и
  восстановление безопасно повторяет действие.
- Порядок блокировок проекта (актор → другие пользователи по возрастанию id → пост →
  комментарий → строки голосов) обязателен и для исполнителя: он не блокирует строки
  голосов до вызова Action и заранее берёт в этом порядке все строки `users`, которые
  затронет шаг (персона, утвердивший администратор, получатель уведомления).
- Защита от повторной доставки и обработка уже существующего голоса — задачи исполнителя
  и предпроверки плана; универсальный режим «обеспечить голос» в существующие Actions не
  вводится, публичное поведение голосования не меняется.
- Временный сбой родителя не отменяет дочерние действия: они ждут. Отмена только при
  окончательной невозможности или по решению администратора. Зависшие
  `dispatched`/`running` обнаруживаются и восстанавливаются; попытки ограничены.
- Существующий деплой сохраняется как есть: подготовка релиза и миграции → атомарное
  переключение `current` → перезагрузка PHP-FPM → HTTP health check → `queue:restart` →
  плавная смена процесса воркера Supervisor. Задания Content Studio переносят только id и
  читают состояние из PostgreSQL; включение новых заданий — только после готовности
  воркера соответствующей версии.
- Исторические даты записываются в обычные поля `created_at`/`updated_at`/`published_at`
  создаваемых постов, комментариев и голосов; реальное время исполнения хранится в
  журнале действий. Техническое одобрение, первоначальная публикация и пересчёты
  счётчиков и рейтинга при исполнении плана не уничтожают историческое `updated_at`;
  последующее настоящее редактирование обновляет его обычным образом. Отдельная система
  исторических записей не создаётся.
- Уведомления не подавляются из-за исторических дат: `CreatePostAction`,
  `ApprovePostAction` и `AddCommentAction` создают database-уведомления своими
  существующими механизмами без специальных флагов; уведомления сохраняют реальное время
  создания, персоны не входят в систему, поэтому накопленные уведомления для них не
  проблема. Запрещена только отправка почты на синтетические адреса персон.
- OpenAI нужен уже при подготовке публикации (анализ фотографии, варианты текстов, затем
  обсуждение) и появляется сразу после планов с черновиком изображения; универсальный
  AI Engine, реестр моделей и общий мониторинг расходов отложены.
- SFW/NSFW — самостоятельная подсистема с отдельным выбором модели, общая для
  пользовательских загрузок и Content Studio; география и история входов — отдельное
  направление вне MVP.

**Последовательность этапов** (раздел F): Phase 1 Personas с полноценным UI →
Phase 2 планы публикаций и черновик с изображением → Phase 3 OpenAI: анализ изображения
и тексты → Phase 4 публикация, исторические даты, исполнитель с восстановлением и
безопасным деплоем → Phase 5 генерация обсуждения → Phase 6 голоса и рейтинги →
Phase 7 общая классификация SFW/NSFW (может быть выполнена раньше, после готовности
медиа-пути) → Phase 8 автоматические ответы → Phase 9 очистка и доводка.

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
| Пользователи | `app/Actions/Auth/RegisterSocialUserAction.php:48-64` | Создание аккаунта без пароля (`'password' => null`), `email_verified_at` только по подтверждению провайдера | Образец «аккаунт без пароля» для персон; `email_verified_at` у персоны остаётся пустым (решение) |
| Пользователи | `app/Actions/Users/GenerateUniqueUsernameAction.php`, `app/Actions/Auth/Concerns/CreatesUserWithUniqueUsername.php` | Генерация уникального `username` с повтором при коллизии | Генерация handle персоны |
| Пользователи | `app/Actions/Profile/AnonymizeUserAccountAction.php:63-89, 186-189` | Tombstone; email вида `deleted-{id}-{uuid}@deleted.invalid` (RFC 2606); сбрасывает роль в `User` (`:83`) | Образец немаршрутизируемого адреса для персон; решение о роли при удалении персоны |
| Пользователи | `app/Actions/Profile/UpdateUserProfileAction.php:39-41, 64-82, 100-105` | Аватар: `ImageUploadStorer` → `MediaAssetCreator(Avatar)` → `users.avatar_asset_id`, soft-delete прежнего, `GenerateMediaVariantsJob` | Пять шагов переносятся в админский Action установки аватара персоны |
| Вход | `app/Actions/Auth/AuthenticateUserAction.php:23-52` | `Auth::attempt` + гейт `canAuthenticate()`; `password = NULL` никогда не совпадает | Персона без пароля не войдёт по паролю; гейт дополняется ролью |
| Вход | `app/Actions/Auth/ResolveSocialLoginAction.php:70-76, 87-115` | OAuth: известная идентичность, совпадение email (claim при подтверждённом провайдером адресе), регистрация | Все три ветки проходят `canAuthenticate()` — единые точки для ролевого гейта |
| Вход | `app/Actions/Auth/ClaimAccountWithVerifiedEmailAction.php`, `LinkSocialAccountAction.php`, `ResetPasswordAction.php`, `SendPasswordResetLinkAction.php:10-20` | Захват аккаунта по подтверждённому email, привязка провайдера, сброс пароля | Точки, где персона должна быть отвергнута |
| Вход | `app/Support/Auth/RememberSessionGenerationOnLogin.php:9-25`, `app/Providers/AppServiceProvider.php:75` | Единственный слушатель `Illuminate\Auth\Events\Login` для всех способов входа | Готовая точка для будущей истории успешных входов |
| Вход | `app/Http/Middleware/EnsureAccountIsNotTombstoned.php:24-38` | На каждом запросе завершает сессию, если `! canAuthenticate()` | Делается ролевым, чтобы живая сессия не пережила перевод аккаунта в персону |
| Публикации | `app/Actions/Posts/CreatePostAction.php:44-174` | Единственный путь создания поста: policy `create`, лимит `upload`, файл до транзакции, `lockActor`, статус по доверию и email/роли (`:88-91`), `MediaAssetCreator`, `Post::create` без `created_at` (`:97-106`), job подписчикам при `Published` (`:136-147`), варианты `afterCommit()` (`:149-171`) | Вызывается исполнителем без изменений логики; расширяется DTO |
| Публикации | `app/Data/Posts/CreatePostData.php:8-28` | Readonly DTO: title, description, sourceUrl, tagIds, `?UploadedFile $image`, `ImageInputSource`, categoryId, authorAnswerOptionIds; единственный конструктор в `app/Livewire/Feed/UploadPostForm.php:106-115` | Добавляются необязательные доверенные поля (даты, `imageAssetId`); флагов уведомлений нет (решение) |
| Публикации | `app/Models/Post.php` | `$guarded = []` (`:33`); `scopePublished` (`:48-51`); `canReceiveVotes/Comments/RatingVotes` = не trashed и `Published` (`:89-112`); наблюдателей нет | Явно переданные `created_at`/`published_at` сохраняются Eloquent без изменений модели; предикаты доступности для отмены зависимостей |
| Публикации | `app/Actions/Moderation/ApprovePostAction.php:27-98` | Из `Pending` в `Published` под блокировкой актора и поста (`LocksAndAuthorizesPostModeration.php:30-32`), `published_at = $locked->published_at ?? now()` (`:43`), `save()` переписывает `updated_at`, лог `ApprovePost`, `PostApprovedNotification` автору через `LifecycleSafeDatabaseNotifier` (`:65-86`), job подписчикам | Путь публикации персоны: исполнитель одобряет созданный `Pending`-пост от имени утвердившего администратора; нужен доверенный необязательный параметр даты публикации вместо `now()`; уведомление автору создаётся штатно |
| Публикации | `app/Actions/Ranking/RecalculatePostScoreAction.php:14-31`, `app/Support/Ranking/HotScoreCalculator.php:17-35` | Hot score от `posts.created_at` и счётчиков; при создании не вычисляется (`hot_score` остаётся 0); `save()` без собственной блокировки — вызывающие уже держат строку поста (`VotePostAction.php:98-99`, `AddCommentAction.php:129-130`) | Исполнитель вызывает в той же транзакции, что создание и одобрение поста, под уже удерживаемой блокировкой поста |
| Публикации | `app/Actions/Counters/RecalculatePostCountersAction.php:13-48` | Полный пересчёт голосов под блокировкой строки поста; `save()` переписывает `updated_at` | Без изменений; технические `save()` при исполнении плана идут под `Post::withoutTimestamps()` (E7) |
| Публикации | `app/Queries/Feed/FeedQuery.php:82-87` | «Новое» упорядочено по `published_at DESC, created_at DESC, id DESC` | Исторический пост занимает хронологическое место автоматически |
| Публикации | `app/Support/Cache/PostListCacheManager.php:10-18` | Заглушка: `remember()` вызывает callback, `invalidateForPost()` пуст; вызовы закреплены тестом | Вызовы сохраняем, кеша нет |
| Публикации | `app/Jobs/NotifyFollowersAboutNewPostJob.php:15-19`, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php:42-66` | Уведомление подписчикам (database), дедупликация по существующим уведомлениям, проверка только статуса | Без изменений поведения (решение): подписчики уведомляются штатно и для исторических постов; диспетчеризации нужен только `afterCommit()` |
| Публикации | `app/Policies/PostPolicy.php:11-14, 73-77` | `create` = `canCreateContent()`; `vote` = `canVote()` и не свой пост | Персона-автор не голосует за свой пост — проверка остаётся |
| Комментарии | `app/Actions/Comments/AddCommentAction.php:35-161` | Лимит `comment` 10/60 с, тело ≤ 1000, родитель только верхнего уровня (`:75`, `:111`), перечитывание родителя под блокировкой (`:107-119`), `Comment::create` без дат (`:121-127`), пересчёт `comments_count` и hot score (`:129-130`), `PostCommentedNotification` автору поста (`:135-158`) | Вызывается исполнителем; добавляется только `$createdAt`; уведомление автору поста создаётся штатно (решение) |
| Комментарии | `app/Services/Comments/CommentReplyService.php:19-56` | `canStartReply(postId, commentId)` и `createReply(...)`; цель ответа: тот же пост, `Visible`, `parent_id IS NULL`, не удалён | Предикат «родитель доступен» для исполнителя |
| Комментарии | `app/Models/Comment.php:24, 97-108` | `$guarded = []`; `canReceiveVotes()`; `isStructuralTombstone()` | Исторические даты через `create`; предикаты отмены |
| Комментарии | `app/Queries/Comments/CommentListQuery.php:26-29, 40-41` | Корни по `created_at DESC, id DESC`; ответы `created_at ASC, id ASC` | Историческая дата ставит комментарий в правильное место |
| Комментарии | `app/Actions/Comments/HideCommentAction.php`, `DeleteCommentAction.php` | Скрытие/удаление не трогает детей; корень становится структурным tombstone | Модерация сохраняется; зависимые действия отменяет исполнитель по предикатам |
| Голоса | `app/Actions/Votes/VotePostAction.php:30-103` | Переключатель: существующий голос удаляется, иначе создаётся (`:86-94`); прямой голос «вниз» без предшествующего «вверх» допустим; пересчёт счётчиков и hot score в транзакции | Вызывается исполнителем как есть; защита от повтора — статус действия, проверка существующего голоса — предпроверка плана и исполнитель (раздел E); на этапе голосований потребуется только необязательный параметр даты |
| Голоса | `app/Actions/Votes/VoteCommentAction.php:27-116` | Тот же тип → удалить, другой → обновить (`:100-112`); проверка «не свой комментарий» (`:41-43`, `:89-92`) | Так же: публичное поведение не меняется |
| Голоса | `app/Actions/Rating/VoteRatingOptionAction.php:27-115` | `upsert` по `(user_id, post_id, rating_group_id)`; повтор с тем же вариантом — без изменений; `created_at` сохраняется при конфликте (`:96-107`) | Идемпотентен уже сейчас; на этапе голосований `created_at => now()` получает необязательный параметр даты |
| Голоса | `app/Models/PostVote.php:15`, `CommentVote.php:15`, `RatingVote.php:13` | `PostVote` `$guarded = []`; `CommentVote` `$fillable` без `created_at`; `RatingVote` `$fillable = ['rating_option_id']` | Для исторических дат `CommentVote` требует `forceFill()` |
| Голоса | Миграции `2026_05_14_185608...:16`, `2026_05_26_000004...:18`, `2026_06_05_000003...:26` | Уникальные индексы `(post_id,user_id)`, `(comment_id,user_id)`, `(user_id,post_id,rating_group_id)`; все FK `restrictOnDelete` | Страховка от дубликатов при повторной доставке |
| Голоса | `app/Support/AbuseGuards/ActionRateLimiter.php:10-24`, `config/rate_limits.php` | Ключ `rate-limit:{action}:user:{id}`, срабатывает до транзакции; голоса 60/60 с общим ведром | Пейсинг исполнителя или отдельные лимиты доверенного исполнителя — открытый вопрос до Phase 4 (H3) |
| Медиа | `app/Support/Media/ImageUploadStorer.php:27-37`, `app/Services/Media/MediaPathGenerator.php:35-37` | `UploadedFile` → `ImageIngestor::ingest` → `MediaStorage::storeNormalized` → `StoredMedia`; путь изображения поста `media/post-images/Y/m/{uuid}` не содержит владельца (в отличие от аватара) | Сохранение фотографии черновика тем же вызовом; смена автора плана не требует перезагрузки файла |
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
| Фон | `infrastructure/scripts/deploy:450-460, 505-575, 1945-1961, 1987-1999, 2014-2026`, `tests/Feature/Architecture/DeployTest.php:893` | Релизная последовательность: подготовка в каталоге релиза (`config:cache`, `rateguru:sharing:verify`, `view:cache`, `migrate --force`, backfill переводов через `list --raw`) → атомарное переключение `current` (`ln -sfn` + `mv -Tf`) → `systemctl reload` PHP-FPM → HTTP health check → `queue:restart` (обязателен при работающем воркере) → плавная смена процесса Supervisor: воркер завершает текущее задание и выходит, Supervisor поднимает процесс нового релиза (комментарий `:505-536`) | Без изменений; исполнитель проектируется под эти правила (раздел E8) |
| Фон | `config/cache.php:18`, `database/migrations/0001_01_01_000001_create_cache_table.php` | Кеш по умолчанию `database`; таблица `cache_locks` существует | Для исполнителя не требуется, если достаточно транзакций и блокировок PostgreSQL (решение) |
| Фон | `app/Services/Media/MediaVariantWriter.php:114-130`, `MediaLifecycleService.php:266-275`, `app/Jobs/RunMediaAuditJob.php:44-55` | Атомарные блокировки `Cache::store('database')->getStore()->lock(...)`, неблокирующий `get()` + `finally release()` | Образец на случай, если понадобится блокировка вне строки; в первой версии исполнителя не используется |
| Фон | `app/Support/Translations/Generation/ProjectTranslationGenerationStore.php:235-257`, `app/Jobs/Translations/GenerateProjectTranslationChunkJob.php:36-49, 73-77, 164-179` | Claim «только queued можно забрать» под блокировкой; `tries = 1`, `timeout = 75` < `retry_after 90` < воркер 120; `failed()` помечает состояние | Образец однократного применения результата и контракта тайм-аутов для исполнителя действий |
| Фон | `app/Actions/Import/ImportFromUrlAction.php:35-37`, `app/Support/Settings/ProjectSettingsManager.php:45` | Feature-флаг проекта (`featureEnabled('allow_url_imports')`) с умолчанием в менеджере настроек | Образец выключателя для безопасного включения диспетчера после деплоя |
| Фон | `app/Models/Concerns/LocksActorForWrite.php:18-27`, `LocksUsersInOrder.php:17-37`, `app/Services/Posts/PostRetentionPurgeService.php:49-90` | Единый порядок блокировок (актор → другие пользователи по возрастанию id → пост → комментарий/группа → рёбра); `lockForUpdate` + перепроверка состояния как compare-and-set | Захват строки действия под блокировкой; исполнитель берёт строки `users` шага по возрастанию id тем же приёмом (E4) |
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
**В** — высокий (нужно для завершения своего этапа), **С** — средний, **Н** — низкий.
Этап — номер Phase из раздела F; расширение существующего кода вносится на том этапе,
где появляется его потребитель, а не заранее. Имена новых классов, таблиц и полей —
предложения.

| Компонент | Текущая проблема или ограничение | Точное необходимое изменение | Приоритет | Будущий этап |
|---|---|---|---|---|
| `app/Enums/UserRole.php:5-10` | Нет роли персоны | `case Persona = 'persona'` (решение: `User`, `Persona`, `Moderator`, `Admin`); желательно `isStaff(): bool` и `label(): string`, чтобы убрать рукописные карты меток | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:161-168` | `roleLabel()` — `match` без `default`; падает `UnhandledMatchError` на первой персоне в списке | Добавить ветку `UserRole::Persona => 'Persona'` (или `UserRole::label()`) | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:149` | `'staff' => $person->role !== UserRole::User` — персона получит бейдж персонала | `in_array($person->role, [UserRole::Admin, UserRole::Moderator], true)` или `isStaff()` | К | 1 |
| `app/Filament/Resources/Users/Pages/ListUsers.php:118-123` | Фильтр ролей захардкожен тремя значениями; `normalize()` (`:174`) уже примет `?role=persona` | Добавить вариант `Persona` в список фильтра — так персоны находятся через обычный раздел Users (решение) | В | 1 |
| `app/Filament/Resources/Users/Pages/EditUser.php:222, 130` | `Rule::enum(UserRole::class)` сделает `persona` допустимым значением сохранения в обе стороны | Явный список допустимых ролей для цели (`Rule::in([...])`); переход `user ↔ persona` через эту страницу запретить — персоны создаются и редактируются только в Content Studio | К | 1 |
| `app/Filament/Resources/Users/Pages/EditUser.php:198-203`, `resources/views/filament/pages/user.blade.php:82, 186-201` | Карты `roles`/`roleHelp` без персоны; диалог подтверждения индексирует `$roles[$role]` → undefined key | Добавить метку и подсказку персоны; для персоны роль только для чтения, ссылка на редактор персоны в Content Studio | К | 1 |
| `resources/views/filament/pages/user.blade.php:92-109`, `app/Actions/Users/UpdateUserAccountAction.php:56-58` | Админ может задать пароль любому аккаунту, что откроет персоне вход по паролю | В Action: для персоны пароль всегда `null`, попытка задать — исключение; карточку пароля скрыть | К | 1 |
| `app/Actions/Users/UpdateUserAccountAction.php:44-54` | Смена email сбрасывает `email_verified_at`; для персоны email — технический идентификатор | Для персоны email не редактируется через эту страницу (или только синтетический домен); никакого проставления `email_verified_at` | В | 1 |
| `app/Actions/Posts/CreatePostAction.php:88-89` | `role !== UserRole::User` трактует персону как персонал и освобождает от подтверждённого email | Заменить на явную проверку `Admin`/`Moderator` (метод модели/Policy). Для персоны результат — `Pending`; это ожидаемо: публикует её утверждение плана через `ApprovePostAction` (раздел E9). Никакого обхода через ложный персонал или фиктивную верификацию | К | 1 |
| `app/Filament/Resources/Posts/Pages/ListPosts.php:553` | Та же трактовка в подписи доверия | Зеркально поправить | С | 1 |
| `app/Models/User.php:151-154`, `AuthenticateUserAction.php:41`, `ResolveSocialLoginAction.php:70, 90`, `ClaimAccountWithVerifiedEmailAction.php:57`, `LinkSocialAccountAction.php:51`, `ResetPasswordAction.php:34`, `EnsureAccountIsNotTombstoned.php:28` | Все гейты входа статусные: активная персона с паролем, OAuth-идентичностью или подтверждённым провайдером email войдёт | Ввести ролевой предикат входа (например `User::canSignIn()` = `canAuthenticate() && role !== Persona`) и применить во всех семи точках; `canAuthenticate()` не переопределять — он нужен персонам для записей настроек | К | 1 |
| `app/Models/User.php` (`sendPasswordResetNotification` не переопределён), `SendPasswordResetLinkAction.php:12`, `SendEmailVerificationNotificationAction`, событие `Registered` | `Password::sendResetLink` создаст токен и отправит письмо на синтетический адрес; `Registered` по умолчанию шлёт письмо подтверждения | Переопределить `sendPasswordResetNotification()` как no-op для персон (или гейт в Action); при создании персоны не вызывать `Registered` и не отправлять подтверждение | В | 1 |
| `tools/phpstan/src/Rules/NoDirectControllerPermissionCheckRule.php:22-35` | Список методов, запрещённых в презентации, не знает новых предикатов | Добавить `isPersona()`/`canSignIn()`/`isStaff()`; презентация ходит через Gate/Policy | С | 1 |
| `docs/architecture/user-lifecycle.md:9-27`, `tests/Unit/Enums/UserRoleTest.php` и тесты из раздела G | Таблица роль×статус и тесты перечисляют три роли | Добавить строку Persona и регрессионные случаи (вход запрещён всеми путями, панель закрыта, список/страница рендерятся, email не считается подтверждённым) | К | 1 |
| Создание управляемого аккаунта | Нет Create-страницы (`UserResource::getPages()` — `index`, `edit`) и нет Action создания пользователя из админки | `CreatePersonaAction` по образцу `CreateAdminUserCommand`/`RegisterSocialUserAction`: `password => null`, уникальный технический email на зарезервированном домене `.invalid` (решение; точный формат генерации — на этапе, без изменения существующих email-механизмов пользователей), `email_verified_at => null`, `status` по умолчанию, без события `Registered` и без писем; страницы Personas (список, создание, редактирование) в секции Content Studio | В | 1 |
| Настройки персоны | В `users` нет колонок характера, манеры речи, тем, ограничений, интенсивности, дневного лимита | Новая таблица `persona_profiles` (1:1 к `users`), модель и Action обновления; аватар — существующий `users.avatar_asset_id`, публичное описание — `users.bio`; географических полей не добавлять (решение) | В | 1 |
| Аватар персоны | `UpdateUserProfileAction` — только self-service (`canUpdateProfile()` актора, поля профиля из валидации) | Админский Action установки аватара, повторяющий пять шагов (`:39-41, 67-72, 82, 105`) | В | 1 |
| `app/Filament/Support/AdminNavigationGroup.php`, `AdminShellNavigation::ICONS`, `resources/views/components/admin/ui/icon.blade.php`, `tests/Feature/Admin/AdminShellTest.php:37-44, 259-285`, `tests/Unit/Filament/AdminNavigationGroupTest.php:14-23` | Нет секции Content Studio; тесты закрепляют точный список секций и 13 destination; в реестре иконок нет `newspaper`, `messages-square`, `scroll-text`, `list-checks` | Константа `CONTENT_STUDIO` в `all()`, иконки по мере появления экранов (сначала Personas), обновить `toHaveCount`, карту `ICONS` и список секций; пункт навигации без готовой страницы запрещён чеклистом UI | В | 1 |
| `docs/design/admin/migration-plan.md`, `tests/Feature/Docs/AdminDesignDocsTest.php:249-266` | Content Studio в плане миграции не упомянут; тест закрепляет строки таблицы «remaining sections» и отсутствие строки `| 9 | Dashboard |` | Дополнить план разделом Content Studio своим изменением и синхронно обновить тест | С | 1 |
| `app/Actions/Profile/AnonymizeUserAccountAction.php:83` | При удалении роль сбрасывается в `User`; админского пути удаления нет | Решить, сохраняется ли `Persona` у tombstone; при необходимости — админский Action вывода персоны из эксплуатации | Н | 1+ |
| Планы публикаций | Нет ничего похожего (полный список таблиц в приложении) | Таблица `studio_plans` (предложение), модель, Actions создания/обновления/утверждения, редактор Image → Post → Discussion → Publication; автор — только персона (решение) | В | 2 |
| `app/Services/Media/MediaReferenceChecker.php:24-52` | Ссылка только из `posts` и `users`; ассет черновика — «активный неиспользуемый», доступен Release | Добавить третий источник (`studio_plans.image_asset_id`) в `isReferenced()` и `referencedAssetIds()`; обновить `tests/Feature/Services/Media/MediaReferenceCheckerTest.php:69` (2 → 3 запроса) и `docs/architecture/media.md` | В | 2 |
| Клиент OpenAI для Content Studio | Движок переводов принимает только `input_text`, контракт `translateBatch`, тест закрепляет ключи тела (`OpenAiTranslationProviderTest.php:84`) | Отдельный минимальный клиент Responses API вне `app/Support/TranslationEngine` с `input_image` и структурированным ответом, конфиг на фичу (ключ, базовый URL, таймауты, модель), enum ошибок, ключи в `environment-contract.json`; минимальный технический учёт допустим, общий мониторинг не является предпосылкой | В | 3 |
| `app/Data/Posts/CreatePostData.php:10-27`, `CreatePostAction.php:97-106` | Нет исторических `created_at`/`updated_at` | Необязательные доверенные поля DTO (`?CarbonImmutable $createdAt`, `$updatedAt`); при наличии — в массив `Post::create` (модель `$guarded = []`; явно переданные значения Eloquent не перезаписывает — `HasTimestamps::updateTimestamps()` пропускает «грязные» колонки); статусная логика `:88-91` не меняется | В | 4 |
| `ApprovePostAction.php:41-45`, `RecalculatePostScoreAction.php:26-28`, `RecalculatePostCountersAction.php:39-42`, `RefreshesPostCommentsCount.php:29`, `RecalculateCommentCountersAction.php:33-36` | Каждый `save()` переписывает `updated_at` реальным временем: одобрение и пересчёты уничтожают историческое значение сразу при исполнении | Сами Actions не меняются; исполнитель выполняет технические записи шага под `Post::withoutTimestamps()` / `Comment::withoutTimestamps()` (`HasTimestamps.php:214-240`: `usesTimestamps()` → `false`, `performUpdate` не трогает `updated_at`, явные даты в `create()` сохраняются). Применяется к постам (одобрение, первоначальная публикация, hot score), комментариям (`comments_count`, hot score поста, счётчики комментария) и голосам (пересчёты после голоса персоны). Последующее настоящее редактирование и модерация обновляют `updated_at` обычным образом (решение) | В | 4 (посты), 5 (комментарии), 6 (голоса) |
| `CreatePostData`, `CreatePostAction.php:66-68, 93-95` | Нет создания поста из готового `MediaAsset` | `?int $imageAssetId` (взаимоисключим с `$image`); при наличии — пропустить `ImageUploadStorer`, загрузить ассет под `lockForUpdate()`, проверить `kind = PostImage`, `status = Ready`, не trashed, что на него ссылается исполняемый план и не ссылается другой пост; `StoredMediaCleaner` не затрагивается | В | 4 |
| `app/Actions/Moderation/ApprovePostAction.php:27, 41-45` | `published_at = $locked->published_at ?? now()` — историческая дата публикации теряется при одобрении | Необязательный доверенный параметр даты публикации: `$locked->published_at ?? $publishedAt ?? now()`; вызывается исполнителем от имени утвердившего администратора сразу после `CreatePostAction` в той же транзакции. Порядок блокировок: строки персоны и администратора исполнитель берёт заранее по возрастанию id, поэтому `lockActor` обоих Actions повторно берёт уже удерживаемые строки (E4) | В | 4 |
| `CreatePostAction.php:136-147`, `ApprovePostAction.php:65-97`, `AddCommentAction.php:135-158` | Уведомления подписчикам и автору создаются при любой публикации и любом комментарии | Не меняется (решение): database-уведомления создаются существующими механизмами без флагов Content Studio, с реальным временем создания; персоны не входят в систему, их накопленные уведомления — не проблема; поведение уведомлений подписчиков и реальных пользователей вне Content Studio не изменяется | — | 4 |
| `CreatePostAction.php:138`, `ApprovePostAction.php:89` | `NotifyFollowersAboutNewPostJob::dispatch` без `afterCommit()` — внутри внешней транзакции исполнителя задание уйдёт до фиксации | Добавить `->afterCommit()` | В | 4 |
| `app/Actions/Ranking/RecalculatePostScoreAction.php:14-31` | Не вызывается при создании; `save()` без собственной блокировки | Исполнитель вызывает его в **той же** транзакции, что `CreatePostAction`, `ApprovePostAction` и перевод действия в `done`, под уже удерживаемой блокировкой строки поста (как `VotePostAction.php:98-99`), а не незащищённой операцией после фиксации; логика Action не меняется | В | 4 |
| Очередь действий | Нет таблицы действий, диспетчера и исполнителя | Таблица `studio_plan_actions` (предложение), команда диспетчера `everyMinute()->withoutOverlapping()` в `routes/console.php`, Job с единственным id, Action исполнения с двумя транзакционными границами (захват, затем доменная запись с `done`), фиксация исходов отдельной короткой транзакцией, восстановление зависших и экран Action queue — всё в первом же MR, вводящем исполнитель | В | 4 |
| Выключатель диспетчера | Старый воркер после `queue:restart` ещё работает и не знает новых классов заданий | Диспетчер выключен по умолчанию и включается существующим механизмом feature-флагов проекта после подтверждения, что воркер нового релиза запущен (образец `ImportFromUrlAction.php:35-37`) | В | 4 |
| `ActionRateLimiter` в `CreatePostAction:50-55`, `AddCommentAction:49-58`, `Vote*:45-57` | Лимиты по пользователю срабатывают до транзакции и считают повторы | Открытый вопрос до Phase 4 (H3): пейсинг реального времени запуска или отдельные ограничения доверенного исполнителя с собственной записью в `config/rate_limits.php`; безусловный обход защиты через публичные Actions не допускается, лимиты обычных пользователей заранее не меняются | В | 4 |
| Комментарии `CreatePostAction.php:161-164`, `UpdateUserProfileAction.php:109-112`, `docs/deployment/queue-worker.md:5, 44` | Утверждают «`QUEUE_CONNECTION=sync` — единственное соединение», что противоречит контракту окружения | Поправить при первом касании файлов | Н | 4 |
| `app/Actions/Comments/AddCommentAction.php:35, 121-127`, `CommentReplyService.php:26-45` | Нет исторических дат | Необязательный `?CarbonImmutable $createdAt` (и `updated_at` тем же значением), прокинутый через `createReply()`; `PostCommentedNotification` автору поста (`:135-158`) остаётся без флагов (решение) | В | 5 |
| `app/Exceptions/Comments/CannotCommentException.php` | Недоступность цели ответа не отличима от ошибок тела (общий `becauseBodyIsInvalid`) | Отдельная причина `becauseReplyTargetUnavailable()` для трёх точек (`CommentReplyService.php:36`, `AddCommentAction.php:72, 76, 117`), чтобы исполнитель отличал отмену от ошибки | С | 5 |
| Генерация обсуждения | Клиента для текстов комментариев нет | Расширение клиента OpenAI этапа 3 на генерацию связного дерева с учётом фотографии, текстов и профилей персон; глубина — один уровень ответов (D6) | В | 5 |
| `app/Actions/Votes/VotePostAction.php:89-93`, `VoteCommentAction.php:107-111`, `app/Actions/Rating/VoteRatingOptionAction.php:102-103`, `app/Models/CommentVote.php:15` | Нет исторических дат голосов; `CommentVote::$fillable` без `created_at`; `upsert` рейтинга пишет `created_at`/`updated_at` = `now()` | Необязательный параметр даты в трёх Actions (`CommentVote` через `forceFill()`; в `upsert` — обе колонки); режим «обеспечить голос» не вводится — защита от повтора и проверка существующего голоса живут в исполнителе и предпроверке плана (решение); исполнитель читает существующий голос только после блокировок актора и поста в порядке проекта и не блокирует строки голосов до вызова Action (E4) | В | 6 |
| `app/Support/Media/MediaAssetCreator.php:47-48` | `status`/`visibility` зашиты (`Ready`/`Public`) | Точка подключения общей классификации: параметр visibility (по умолчанию `Public`) и `->afterCommit()`-диспетчеризация задания классификации по id ассета; модель и пакеты выбираются на своём этапе | С | 7 |
| Правила и журнал ответов | Отсутствуют | `persona_reply_rules` (предложение), детектор триггеров, журнал как строки таблицы действий или отдельная таблица (решение отложено до Phase 8, H2), экраны Reply rules и Reply log; повторно используются существующие комментарии и исполнитель Content Studio | В | 8 |

---

## D. Critical Compatibility Issues

### D1. Роль Persona и существующие проверки персонала

Код не знает понятия «персонал» и в трёх местах выводит его как «не `User`»:

- `app/Actions/Posts/CreatePostAction.php:88-89` — публикация без подтверждённого email
  для `$lockedActor->role !== UserRole::User`. Персона получила бы это освобождение
  случайно. Решение: заменить на явную проверку `Admin`/`Moderator`; персона не является
  персоналом, её пост создаётся как `Pending`, а публикует его утверждение плана
  (раздел D3, E9). Никакого обхода пользовательских ограничений через ложное
  определение персоны как сотрудника.
- `app/Filament/Resources/Users/Pages/ListUsers.php:149` — бейдж персонала.
- `app/Filament/Resources/Posts/Pages/ListPosts.php:553` — подпись «Email not confirmed»
  только для `User`.

Исчерпывающий `match` в `ListUsers::roleLabel()` (`:161-168`) бросит
`UnhandledMatchError` на первой строке с персоной, то есть список пользователей
перестанет открываться. На странице персоны в `EditUser` сегментированный контрол роли не
содержит её значения, а диалог подтверждения смены роли обращается к `$roles[$role]` и
`$roles[$saved['role']]` (`user.blade.php:186-201`) — undefined key при любой попытке
сохранить роль. Безопасные места: `Sidebar.php:57-61` (есть `default`),
`ModerationUsersQuery::staff()` (явный список `Moderator`/`Admin`), все Policies
(`isAdmin()`/`isModerator()`), `User::canAccessPanel()`. `UserRole::cases()` в коде не
используется; единственное автоматическое расширение — `Rule::enum(UserRole::class)` в
`EditUser.php:222` и `UserRole::tryFrom()` в `ListUsers.php:103, 124, 174`.

Решение: название `Persona` и роль в `UserRole` остаются; порядок перечисления `User`,
`Persona`, `Moderator`, `Admin`. Дополнительное измерение `account_type` и новая система
ролей не вводятся.

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
никогда не читают роль из ввода — публичная регистрация персоны невозможна.

Решение: персона не может самостоятельно авторизоваться ни через обычный вход, ни через
OAuth; она не получает пароль, не подтверждает почту, не сбрасывает пароль и не привязывает
OAuth. Требуемые изменения перечислены в разделе C (ролевой предикат входа в семи точках,
запрет пароля, no-op сброса пароля, синтетический email на зарезервированном домене по
образцу tombstone).

### D3. Публикация без фиктивной верификации email

Условие публикации сразу: `trust_level >= 10 && (hasVerifiedEmail() || role !== User)`
(`CreatePostAction.php:88-89`). После замены ролевой части на явную проверку
`Admin`/`Moderator` пост персоны (email не подтверждён) всегда ложится в `Pending` с
`published_at = null`.

Решение: не проставлять персоне `email_verified_at` — синтезированный адрес на домене
`.invalid` не является подтверждённым, и верификация не должна быть способом разрешить
управляемому аккаунту публикацию. Доверенное разрешение на публикацию — утверждение
плана администратором. Минимальная интеграция с существующим кодом:

1. В основной транзакции исполнитель сначала блокирует строки `users` персоны и
   утвердившего администратора **по возрастанию id** (приём `LocksUsersInOrder.php:27-33`).
   Последовательный вызов двух Actions иначе взял бы строки в порядке «персона →
   администратор», который для пары пользователей с меньшим id администратора обратен
   порядку проекта (`docs/architecture/user-lifecycle.md`, «Lock ordering») и мог бы
   столкнуться с санкцией или уведомлением, блокирующими ту же пару по возрастанию id.
   Повторный `lockForUpdate` уже удерживаемой строки в той же транзакции не блокируется,
   поэтому `lockActor` внутри обоих Actions остаётся без изменений.
2. Исполнитель вызывает `CreatePostAction::handle($persona, CreatePostData)` с готовым
   `MediaAsset` и историческими `created_at`/`updated_at`; статусная логика не меняется —
   пост `Pending`.
3. В той же транзакции исполнитель вызывает `ApprovePostAction::handle($approvingAdmin,
   $post, null, $publishedAt)` от имени администратора, утвердившего план:
   `PostPolicy::approve` проверяет его как модератора (`PostPolicy.php:25-28, 90-96`),
   `lockAndAuthorizePostModeration` блокирует актора и пост (`:30-32`) и требует статус
   `Pending` (`ApprovePostAction.php:37`), запись в `moderation_logs` фиксирует одобрение,
   `published_at` берётся из доверенного параметра вместо `now()` (единственное изменение
   Action: `$locked->published_at ?? $publishedAt ?? now()` в `:43`).
   `PostApprovedNotification` автору-персоне создаётся существующим механизмом
   (`:65-86`, `LifecycleSafeDatabaseNotifier` снова берёт ту же пару строк по возрастанию
   id) и фиксируется вместе с постом — уведомления не подавляются (D9).
4. В той же транзакции исполнитель вызывает `RecalculatePostScoreAction::handle($post)` под
   уже удерживаемой блокировкой поста — так же, как это делают `VotePostAction.php:98-99` и
   `AddCommentAction.php:129-130`, — и переводит действие в `done`. Пост никогда не виден
   как `Pending` и никогда не остаётся опубликованным без рейтинга: создание, одобрение,
   пересчёт и завершение действия фиксируются одной транзакцией.
5. Если утвердивший администратор к моменту исполнения потерял права (санкция,
   понижение), `ApprovePostAction` отвергнет одобрение — транзакция откатывается, действие
   переходит в класс «неожиданная ошибка» (D11) и ждёт решения администратора.

Публичный API и `UploadPostForm` параметрами административной публикации не расширяются:
`CreatePostData` конструируется только в `UploadPostForm.php:106-115`, новые поля с
умолчанием `null` недостижимы из публичных запросов, что закрепляется grep-тестом.
Событие `Registered` при создании персоны не вызывается (фреймворк по умолчанию шлёт
письмо подтверждения; `app/Providers` не содержит `EventServiceProvider`).

### D4. Повторное выполнение голосований

| Действие | Повтор с тем же значением | Повтор с другим значением | Идемпотентно |
|---|---|---|---|
| `VotePostAction` (`:86-94`) | удаляет голос | удаляет голос (новый тип не пишется; закреплено `tests/Feature/Actions/VotePostActionTest.php:82-108`) | нет |
| `VoteCommentAction` (`:100-112`) | удаляет голос | обновляет тип на месте | нет |
| `VoteRatingOptionAction` (`:96-109`) | без изменений (`$changed === false`) | обновляет `rating_option_id` | да |

Обычное голосование: `VoteType::Up`/`VoteType::Down`, пользователь может сразу поставить
голос «вниз», уникальные индексы (`post_votes (post_id, user_id)`, `comment_votes
(comment_id, user_id)`, `rating_votes (user_id, post_id, rating_group_id)`) не допускают
двух записей одного пользователя за один объект. Переключатель важен для очереди:
повторная доставка задания, вызвавшего `VotePostAction`, снимет голос.

Решение: публичное поведение Actions не меняется, режим «обеспечить голос» в них не
вводится. Защита живёт в исполнителе и предпроверке плана — это две разные задачи:

- **Повторная доставка.** Доменная запись голоса и статус `done` строки действия
  фиксируются одной транзакцией; повторно доставленное задание видит `done` и Action не
  вызывает (раздел E5).
- **Уже существующий голос.** Предпроверка плана отклоняет повторные или конфликтующие
  запланированные голоса одной персоны по одному объекту. Исполнитель перед вызовом
  переключателя читает фактический голос персоны **в порядке блокировок проекта**: в
  основной транзакции сначала `lockActor` персоны, затем `Post::withTrashed()->
  lockForUpdate()`, для голоса за комментарий — затем строка комментария; только после
  этого — чтение строки голоса. Строки `PostVote`/`CommentVote` заранее не блокируются:
  все публичные пути голосования начинают с тех же блокировок актора и поста
  (`VotePostAction.php:60-84`, `VoteCommentAction.php:57-98`,
  `VoteRatingOptionAction.php:57-77`), поэтому удерживаемая блокировка поста уже
  исключает параллельного писателя по тому же посту, а блокировка строки голоса «до
  User и Post» обратила бы порядок и грозила взаимной блокировкой. Нужный голос уже
  есть — действие завершается `done` без вызова Action; неожиданный голос
  противоположного типа — не удаляется и не меняется молча, действие переходит в явную
  обработку конфликта (класс C, D11) в рамках семантики плана. Action, вызванный после
  этих проверок, повторно берёт те же блокировки в том же порядке.

Защита «тот же голос → ничего не делать» в публичном UI существует только в
`app/Livewire/Posts/PostVoting.php:60-64` вне транзакции и образцом для исполнителя не
служит. Запрет голосовать за свой контент остаётся: `PostPolicy::vote` (`:73-77`) для
постов и рейтингов, инлайн-проверка `comment->user_id === user->id` для комментариев
(`VoteCommentAction.php:41-43, 89-92`); предпроверка плана отклоняет такие действия до
утверждения. Если на этапе голосований конкретный сценарий потребует небольшого
расширения доменного Action, оно обосновывается там, а не создаётся заранее.

### D5. Исторические даты

Решение окончательное: при первоначальном создании `Post.created_at`,
`Post.updated_at` и `Post.published_at` получают запланированные даты; `Comment.created_at`
и `updated_at` — запланированную дату; новые `PostVote`, `CommentVote`, `RatingVote` —
аналогично. Реальное время исполнения хранится в журнале действий. Факты кода:

- Ни один Action не принимает временные метки; `created_at`/`updated_at` ставит Eloquent.
  Модели `Post`, `Comment`, `PostVote` незащищены (`$guarded = []`), поэтому явно
  переданные значения сохраняются без изменений моделей; `CommentVote` и `RatingVote`
  требуют `forceFill()` или `upsert` с явным значением.
- `updated_at` без защиты недолговечен: каждый `save()` при пересчёте счётчиков и hot
  score, при одобрении, скрытии или восстановлении переписывает его реальным временем
  (`ApprovePostAction.php:41-45`, `RecalculatePostCountersAction.php:39-42`,
  `RecalculatePostScoreAction.php:26-28`, `RefreshesPostCommentsCount.php:29`,
  `RecalculateCommentCountersAction.php:33-36`, `HideCommentAction.php:60`). На пути
  «создать → одобрить» `Post.updated_at` стал бы реальным временем уже после одобрения, а
  первый подготовленный голос или комментарий переписал бы его снова. Решение:
  техническое одобрение, первоначальная публикация и пересчёты счётчиков и рейтинга,
  выполняемые исполнителем плана, не уничтожают историческое `updated_at`. Механизм —
  существующий `Model::withoutTimestamps()` (`HasTimestamps.php:214-240`): исполнитель
  оборачивает технические записи шага в `Post::withoutTimestamps()` и
  `Comment::withoutTimestamps()`; при этом `usesTimestamps()` возвращает `false`,
  `performUpdate` не трогает `updated_at`, а явно переданные даты в `create()`
  сохраняются. Действует для `Post` (одобрение, hot score, `comments_count`, счётчики
  голосов), `Comment` (счётчики голосов) и голосов (у `PostVote`/`CommentVote` обе колонки
  задаются при создании; `RatingVote` получает их в `upsert`). Последующее настоящее
  редактирование, модерация и активность реальных пользователей обновляют `updated_at`
  обычным образом; отдельная система исторических записей не создаётся.
- Hot score считается от `posts.created_at` и текущего времени
  (`HotScoreCalculator.php:31`) и не вычисляется при создании. Пост с датой пять дней
  назад получит ≈0.0007 после первого пересчёта — ожидаемое поведение для исторического
  контента; пересчёт вызывает исполнитель.
- Лента «Новое» и профиль упорядочены по `published_at`, обсуждение — по `created_at`:
  исторические записи встают в хронологическое место. Порядок внутри ветки (ответ не
  раньше родителя, комментарии не раньше поста) проверяет предпроверка плана — код этого
  не делает.
- Исторические действия, время которых уже прошло, исполняются при подтверждении плана
  по зависимостям и в хронологическом порядке `occurs_at`. Даты сущностей всегда берутся
  из плана (`occurs_at`), а не из факта опоздания: техническая задержка исполнения
  публикации «сейчас» или «по будущему расписанию» не делает действие историческим и не
  меняет его обработку.
- Макет Content Studio описывает «редакционную дату» и обещает никогда не изменять
  `created_at` задним числом (раздел П10а, шаг Publication). Решение требует обратного;
  документ следует решению, подпись макета — предмет правки на UI-этапе.
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
один ответ тому же корню с адресацией в тексте. Правило не меняем, пока не принято
решение расширять его.

### D7. Хранение MediaAsset до публикации

Ассет, созданный на этапе черновика, активен (`status = ready`), но не используется ни
постом, ни пользователем. Автоматически его ничто не удалит: purge видит только
`onlyTrashed()` (`MediaPurgeCommand.php:65-67`), `isGraceExpired()` для активной строки
`false` (`MediaLifecycleService.php:73-75`), сканер сирот пропускает файлы со строкой в
базе. Но каждый `media:audit` отметит его `active_unreferenced_asset`, здоровье
библиотеки станет `Warning`, а Media diagnostics предложит Release
(`MediaDiagnosticsPage.php:543, 632-636`) — один клик мягко удалит ассет, и через 7 дней
`media:purge` удалит файлы. Исполнение плана с soft-удалённым ассетом даст пост без
изображения (`Post::imageAsset` — `belongsTo` к модели с `SoftDeletes`). Решение: план
становится источником ссылки в `MediaReferenceChecker` (раздел C, этап 2). Повторная
обработка не нужна: `GenerateMediaVariantsJob` при публикации пропустит уже созданные
варианты (`MediaVariantGenerator.php:69-71`). Владение ассетом черновика и смена автора
— раздел E10.

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

Все продуктовые уведомления — канал `database`: `PostCommentedNotification` автору поста
(`AddCommentAction.php:135-158`), `PostApprovedNotification` автору (`ApprovePostAction.php:
65-86`), `FollowedAuthorPostedNotification` подписчикам (`NotifyFollowersAboutNewPostJob`).
Ни одно не смотрит на `published_at`/`created_at` контента; колокольчик показывает
`created_at` самой строки уведомления. Исторический пост или комментарий породит
уведомление с реальным временем о событии «пять дней назад».

Решение окончательное: это допустимо, и уведомления **не подавляются** из-за
исторических дат.

- Исторические планы создают посты, комментарии, ответы и голоса между персонами;
  database-уведомления создаются существующими механизмами `CreatePostAction`,
  `ApprovePostAction` и `AddCommentAction` — никаких флагов подавления или специальных
  параметров Content Studio в этих Actions, DTO и `NotifyFollowersAboutNewPostAction`.
- Уведомления сохраняют фактическое время своего создания; исторические даты относятся
  к самим постам, комментариям и голосам (D5, E7).
- Персоны не входят в систему (D2), поэтому накопленные для них строки `notifications`
  (`PostApprovedNotification` при одобрении плана, `PostCommentedNotification` о
  комментариях к их постам) никому не показываются и проблемы не представляют; при
  анонимизации персоны эти строки и уведомления с её `actor_id`/`author_id` удаляются
  (`AnonymizeUserAccountAction.php:108-130`).
- Поведение уведомлений подписчиков и обычная отправка уведомлений реальным
  пользователям вне Content Studio не изменяются.
- Запрещена только отправка **почты** на синтетические адреса персон. Сегодня канал
  `mail` используют лишь `SocialAccountConnectedNotification`/`SocialAccountDisconnectedNotification`
  (недостижимы для персон — OAuth им закрыт), письмо сброса пароля и письмо
  подтверждения email; все три перекрываются Phase 1: no-op `sendPasswordResetNotification()`,
  отсутствие события `Registered` и запрет входа (без входа нельзя запросить повторное
  письмо подтверждения). Любой будущий `mail`-канал обязан исключать персон.

Уведомления автору родительского комментария не существует вовсе — реальный
пользователь не узнает об ответе персоны без нового класса уведомления (отдельное
продуктовое решение, Phase 8). Внутри транзакции исполнителя `PostApprovedNotification`
и `PostCommentedNotification` фиксируются вместе с доменной записью (их `DB::transaction`
становится savepoint); задание подписчикам уходит после фиксации (`afterCommit()`) и
остаётся внешним эффектом с дедупликацией по существующим строкам `notifications`
(`NotifyFollowersAboutNewPostAction.php:28-40`).

### D10. Блокировки, модерация и состояния персон

Правила модерации сохраняются без изменений и ради Content Studio не расширяются:
`UserStatus` для персоны `Active`; санкции (`Limited`/`Banned`/`Shadowbanned`) мгновенно
блокируют её участие через capability-методы и перепроверки под блокировкой в каждом
Action; `HidePostAction`/`HideCommentAction` не трогают дочерний контент, но делают его
недоступным для новых голосов и ответов (`Post::canReceiveComments/Votes`,
`Comment::canReceiveVotes`, цель ответа только `Visible`). Порядок блокировок (актор →
другие пользователи по возрастанию id → пост → комментарий/группа → рёбра) обязателен и
для новых Actions.

Два независимых понятия: **активность автоматических ответов** в настройках персоны
(`Off`, `Low`, `Normal`, `High`) и **состояние аккаунта** (`Active`, `Limited`, `Banned`,
`Shadowbanned`, `Deleted`). Обязательная политика исполнения (решение):

| Состояние персоны | Запланированные действия плана |
|---|---|
| `Active` | исполняются |
| `Off` в настройках | запрещает только самостоятельные ответы; утверждённые действия планов исполняются |
| `Limited` | недопустимые действия приостанавливаются; после снятия ограничения накопленные действия не исполняются автоматически — возобновление контролируемое, через администратора |
| `Shadowbanned` | недопустимые действия приостанавливаются до административного решения |
| `Banned` | невыполненные действия персоны отменяются |
| `Deleted` | невыполненные действия персоны отменяются |

Допустимость каждого действия определяют существующие правила `UserStatus` и Policies
(`canCreateContent`, `canComment`, `canVote` только для `Active`); исполнитель их не
обходит и проверяет **допустимость актора** и **доступность цели** отдельно. Отмена
учитывает реальные зависимости: если персона должна создать пост и окончательно потеряла
такую возможность, создание поста отменяется вместе с зависимыми действиями; если пост
уже создан и остаётся опубликованным, запрет его автора не отменяет будущие комментарии
и голоса других активных персон. Связанные действия физически не удаляются.

### D11. Технические сбои и окончательные отмены

Управляемые аккаунты, известные планы и утверждение администратором позволяют
обнаруживать ошибки бизнес-логики до запуска: предпроверка плана перед подтверждением
проверяет наличие и допустимый статус персон, доступность исходных объектов,
возможность создания поста, готовность фотографии (`MediaAsset` `Ready`, не trashed),
полноту данных публикации, запрет голосования за собственный контент, повторные голоса,
допустимую структуру комментариев (D6), корректность и монотонность дат, зависимости,
наличие авторов и участников и прочие ограничения доменных Actions. Заведомо невалидный
план к исполнению не допускается.

Три класса ситуаций при исполнении (решение):

| Класс | Примеры | Поведение |
|---|---|---|
| **A. Временный технический сбой** | недоступность PostgreSQL, перезапуск сервера, прерывание воркера, временная ошибка | безопасное автоматическое восстановление, ограниченные повторные попытки; зависимые действия остаются в ожидании; ничего успешно выполненного не откатывается |
| **B. Действие стало недопустимым после утверждения** | пост скрыт модератором, родительский комментарий удалён, персона удалена, цель окончательно недоступна | действие отменяется с указанием причины; отменяется только зависимая ветка; независимые действия продолжаются; история сохраняется |
| **C. Неожиданная ошибка или исчерпание попыток** | исключение Action вне класса B, конфликт голоса, потеря прав утвердившего администратора | ветка приостанавливается, проблема видна в Action queue, администратор повторяет или отменяет; план автоматически не уничтожается |

Важно: временный сбой родителя не отменяет дочерние действия. Если создание поста
завершилось технической ошибкой, комментарии и голоса ждут восстановления поста;
отмена — только при окончательной невозможности создания родителя или явной
административной отмене. Модель состояний различает временное ожидание/блокировку,
окончательную отмену и техническую ошибку (раздел E3). Исключения существующих Actions о
недоступности цели (`becausePostIsNotPublic`, `reply_target_unavailable`,
`becauseCommentIsNotVisible`) относятся к классу B; остальные — к классу C.

### D12. Повторная доставка и однократное применение

Laravel Queue не даёт «exactly-once execution»: Redis-очередь выдаёт задание повторно
после `retry_after` (90 с по умолчанию, `config/queue.php:71`) при зависшем воркере, а
при `tries = 1` падение задания не повторяется очередью. Требуемая гарантия —
однократное применение результата к доменным данным PostgreSQL. Она достигается
блокировками строк, транзакциями, проверкой состояния действия и уникальными
ограничениями (раздел E5). Существующий образец — `GenerateProjectTranslationChunkJob`:
«only a queued chunk can be claimed, so a job delivered twice sends nothing the second
time» (`:45-49`), `tries = 1`, `timeout = 75` под `retry_after` (`:36-49, 73-77`). Для
внешних эффектов — задания подписчикам, файлов, обращений к ИИ — гарантия автоматически
не распространяется: они выполняются отдельно, идемпотентно или с дедупликацией
(варианты изображений — пропуск готовых, уведомления подписчикам — дедупликация по
`notifications`). Database-уведомления, которые Actions создают внутри транзакции
исполнителя (`PostApprovedNotification`, `PostCommentedNotification`), фиксируются вместе
с доменной записью и повторной доставкой не дублируются.

---

## E. Minimal Architecture

Имена таблиц, полей, статусов и классов в этом разделе — предложения для постановки
задач; утверждённые решения помечены явно.

### E1. Принципы

1. Content Studio не создаёт контент сам: каждая публикация, комментарий, ответ и голос
   проходит через существующий Action с его политиками, блокировками, счётчиками и
   логами. Новый код добавляет к Actions только необязательные доверенные параметры с
   умолчаниями, сохраняющими текущее поведение, и только на том этапе, где появляется их
   потребитель.
2. Бизнес-состояние (планы, действия, их статусы, зависимости, результаты, реальное время
   исполнения) живёт в таблицах PostgreSQL и видно администратору. Техническая очередь
   (Redis-список и `failed_jobs`) переносит только идентификаторы (решение).
3. Одно запланированное действие = одна строка PostgreSQL = одно логическое задание
   Laravel Queue. Каждое действие атомарно само по себе; одной транзакции на весь план
   или на группу действий одного времени нет (решение).
4. Гарантия — однократное применение результата к доменным данным PostgreSQL, не
   «exactly-once execution» очереди (решение; D12).
5. Новые сущности — только там, где существующих недостаточно; для каждой объяснено,
   почему нельзя использовать существующую. Дополнительные блокировки Redis/Cache не
   вводятся, пока достаточно транзакций, условных обновлений и блокировок PostgreSQL
   (решение).

### E2. Новые сущности

**`PersonaProfile` (таблица `persona_profiles`, 1:1 к `users`).** Характер, манера речи,
темы, ограничения («о чём не пишет»), внутреннее описание, интенсивность автоматических
ответов (`Off`, `Low`, `Normal`, `High`; `Off` отключает только самостоятельные ответы),
дневной лимит ответов, автор записи. Аватар и публичное описание остаются в `users`
(`avatar_asset_id`, `bio`). Почасовое расписание активности и географические поля не
добавляются (решение). Почему не `users`: колонки нужны только персонам, таблица `users`
— общая с реальными людьми, а её записи статуса охраняет `UserLifecycleWriteBoundaryTest`;
отдельная таблица не трогает ни регистрацию, ни анонимизацию, ни фабрики.

**`StudioPlan` (таблица `studio_plans`) — одна публикация, один план.** Статус плана
(черновик → утверждён → исполняется → завершён / отменён), автор-персона (FK `users`,
restrict; только роль `Persona` — решение), создатель и утвердивший администратор с
временем утверждения, `image_asset_id` (FK `media_assets`), результат анализа фотографии
и варианты текстов (этап 3), окончательные заголовок и описание, категория, теги, ответы
автора, режим публикации («сейчас», «на дату» — будущую или прошлую), запланированное
время публикации, ссылка на созданный `post_id`. Почему не `Post` со статусом `Draft`:
`PostStatus::Draft` сегодня не создаётся ни одним Action (только фабрикой), а
`PostPolicy::update` для черновиков не имеет вызывающих; пост требует автора и
участвует в FK-графе, авторском удалении (`AUTHOR_DELETABLE_STATUSES`) и модерационных
списках; план должен хранить подготовленное обсуждение и голоса до появления поста,
расписание и исторические даты, которым в `posts` нет места. Почему не `jobs`: payload
непрозрачен, строка исчезает после выполнения, нет статусов, зависимостей и видимости в
админке.

**`StudioAction` (таблица `studio_plan_actions`) — одна запланированная доменная запись.**
`plan_id`, `parent_action_id` (self-FK, nullable), вид (`create_post`, `create_comment`,
`create_reply`, `vote_post`, `vote_comment`, `vote_rating`), актор-персона, payload
(тело, тип голоса, вариант рейтинга), `occurs_at` (запланированное время события —
штамп для `created_at`/`published_at`), `run_after` (необходимое реальное время запуска),
статус (E3), `attempts`, `dispatched_at`, `claimed_at`, `finished_at` (фактическое время
исполнения), результат (`result_type`/`result_id` по образцу морфов
`reports.target_type/target_id`), код и текст причины отказа. Почему не
`moderation_logs`/`notifications`/`failed_jobs`: это журналы других доменов с другой
семантикой; `failed_jobs` хранит только ошибки.

**Правила ответов и журнал ответов** появляются на этапе автоматических ответов (Phase 8):
`persona_reply_rules` и либо строки той же таблицы действий с видом `auto_reply` и ссылкой
на комментарий-триггер, либо отдельная таблица — решение отложено до Phase 8 (H2).

### E3. Модель действия и состояний

Предлагаемые состояния строки действия и их смысл:

| Статус | Смысл | Кто переводит |
|---|---|---|
| `pending` | ждёт `run_after` и завершения родителя | план при утверждении; администратор при повторе |
| `held` | временная блокировка: актор `Limited`/`Shadowbanned` или решение администратора; ждёт явного возобновления | исполнитель, администратор |
| `dispatched` | задание отправлено в Redis, ещё не захвачено; `attempts` увеличен на этой отправке | диспетчер (первая отправка); восстановление (повторная отправка просроченных `dispatched`/`running`) |
| `running` | захват зафиксирован отдельной короткой транзакцией (`claimed_at`); доменная запись ещё не зафиксирована; статус переживает откат основной транзакции | исполнитель (транзакция захвата) |
| `done` | результат применён; `result_*`, `finished_at` заполнены | исполнитель (в основной транзакции вместе с доменной записью и пересчётами) |
| `failed` | неожиданная ошибка (класс C) или исчерпание попыток; ждёт администратора (повторить → `pending`, отменить → `cancelled`) | исполнитель (отдельной короткой транзакцией после отката основной); восстановление (предел попыток) |
| `cancelled` | окончательно, с кодом причины (цель недоступна, актор заблокирован/удалён, родитель отменён, администратор отменил) | исполнитель (отдельной короткой транзакцией после отката основной), администратор |

Временное ожидание выражается статусом `pending` при незавершённом родителе и статусом
`held`; окончательная отмена — `cancelled`; техническая ошибка — `failed`. Строка в
`running` с просроченным `claimed_at` — признак оборванной попытки (класс A): её
возвращает в `dispatched` восстановление (E5), сам исполнитель `running` не захватывает.
Завершённые действия (`done`) никогда не откатываются из-за ошибки другого действия и
никогда не исполняются повторно. `attempts` увеличивается ровно один раз на попытку — при
отправке в очередь; захват и завершение счётчик не трогают.

### E4. Диспетчер и исполнитель

1. **Диспетчер** — команда `studio:dispatch-due` (предложение), зарегистрированная в
   `routes/console.php` как `everyMinute()->withoutOverlapping()` рядом с пятью
   ежедневными командами. Выбирает ограниченное число строк `pending` с `run_after <=
   now()` и завершённым родителем (или без родителя) в порядке `run_after, id`
   (детерминированная сортировка по правилу `docs/architecture/http-and-database-
   boundaries.md`), переводит каждую в `dispatched` условным обновлением `WHERE status =
   'pending'` с `dispatched_at = now()` и `attempts + 1` и после фиксации диспетчеризует
   `ExecuteStudioActionJob($actionId)` (`afterCommit()`). Та же команда выполняет
   восстановление зависших строк (E5). Диспетчер читает «просроченное в прошлом», поэтому
   переживает паузу воркера и cron во время restore
   (`infrastructure/scripts/restore-target:500-596`).
2. **Job** — `ExecuteStudioActionJob` с единственным скалярным аргументом id (как все
   четыре существующих Job), `tries = 1`, собственный `timeout` ниже Redis `retry_after`
   (90 с; контракт `GenerateProjectTranslationChunkJob.php:36-49` и тест
   `GenerateProjectTranslationChunkJobTest.php:56-59`), `failOnTimeout = true`. Job не
   решает судьбу строки: исходы фиксирует Action исполнения, а `failed()` лишь логирует —
   после тайм-аута, обрыва соединения или падения воркера строка остаётся `running` и
   достаётся восстановлению (класс A). Повтор — решение администратора в Action queue
   или автоматическое восстановление, не повтор очереди.
3. **Action исполнения** в `app/Actions` (презентация и Jobs не пишут модели напрямую —
   `NoPresentationModelMutationRule`). Две транзакционные границы (решение):
   - **Транзакция захвата** — короткая и фиксируемая отдельно: `SELECT … FOR UPDATE`
     строки действия; допустимый исходный статус — только `dispatched`; перевод в
     `running`, `claimed_at = now()`; `attempts` не меняется (он увеличен при отправке).
     Иной статус (`done`, `cancelled`, `held`, `pending`, `failed` или чужой `running`) —
     выход без изменений: это повторная доставка, отменённое или уже выполненное
     действие. Исполнитель запоминает номер попытки `attempts`, с которым захватил
     строку.
   - **Основная транзакция** — одна `DB::transaction`, внутри неё по порядку:
     повторная блокировка строки действия и проверка `status = running` с тем же номером
     попытки (иначе выход: строку успело переотправить восстановление); блокировка всех
     строк `users`, которые затронет шаг, **по возрастанию id** (актор-персона,
     утвердивший администратор для `create_post`, автор поста как получатель уведомления
     для `create_comment`/`create_reply`; приём `LocksUsersInOrder.php:27-33`), затем
     поста, затем комментария — то есть в порядке проекта (`LocksActorForWrite.php:12-14`);
     проверка допустимости актора (политика D10) и доступности цели
     (`Post::canReceiveComments()/canReceiveVotes()`, `CommentReplyService::canStartReply()`,
     `Comment::canReceiveVotes()`); для голосов — чтение фактического голоса персоны уже
     под блокировками актора и поста, без предварительной блокировки строк
     `PostVote`/`CommentVote` (D4); вызов существующего Action с доверенными параметрами
     (даты из `occurs_at`, готовый `MediaAsset`) под `Post::withoutTimestamps()` /
     `Comment::withoutTimestamps()` (E7); для `create_post` — `CreatePostAction`, затем
     `ApprovePostAction` от имени утвердившего администратора, затем
     `RecalculatePostScoreAction::handle($post)` под той же блокировкой поста (D3) —
     пересчёт не выносится за фиксацию `done`; запись `result_*`, `done`, `finished_at`.
     Вложенные `DB::transaction` Actions становятся savepoint; их повторные
     `lockForUpdate` берут уже удерживаемые строки и не блокируются.
   - **Фиксация исхода при ошибке** — исключение внутри основной транзакции откатывает
     её целиком (доменных изменений нет), после чего отдельная короткая транзакция
     условно переводит `running` с тем же номером попытки в `cancelled` (класс B) или
     `failed` (класс C) с кодом и текстом причины. Если процесс погиб между откатом и
     этой записью, строка остаётся `running` и подбирается восстановлением — так же,
     как при любой аварии класса A.
   `GenerateMediaVariantsJob` уже ждёт внешний commit (`afterCommit()`, проверено
   `tests/Feature/Actions/CreatePostActionMediaVariantDispatchTest.php:73`);
   `NotifyFollowersAboutNewPostJob::dispatch` требует добавить `afterCommit()`.
4. **После фиксации** `done` исполнитель ничего не дописывает в доменные таблицы:
   все пересчёты уже внутри основной транзакции. Завершение родителя может сразу
   поставить готовые дочерние действия (`pending`, все зависимости `done`, `run_after`
   наступил) в очередь тем же условным обновлением, не дожидаясь следующей минуты;
   периодический диспетчер остаётся страховкой.
5. **Классификация исходов**: исключения Actions о недоступности цели → `cancelled` с
   причиной и отмена зависимой ветки (класс B); конфликт голоса, потеря прав
   администратора, прочие исключения → `failed` с классом исключения (класс C); потеря
   соединения, прерывание воркера, тайм-аут → основная транзакция откатывается, строка
   остаётся `running` с `claimed_at` до восстановления (класс A). Тексты, ключи и prompt в
   журнал не пишутся (образец `DomainLogger`).
6. **Порядок блокировок и параллельность** (решение): исполнитель никогда не берёт
   блокировку строки голоса, комментария или поста раньше строк `users`, и не берёт
   строки `users` в произвольном порядке. Обязательны тесты параллельных операций без
   взаимных блокировок: публичный голос реального пользователя за тот же пост во время
   исполнения; санкция персоны администратором во время исполнения (`LocksAndAuthorizes
   ModerationPair` блокирует ту же пару по возрастанию id); скрытие поста модератором
   во время создания комментария персоны; два действия одного плана по одному посту на
   одном воркере последовательно и на PostgreSQL с реальной конкуренцией (вторым
   соединением) — ни один сценарий не должен завершаться ошибкой взаимной блокировки
   PostgreSQL, проигравшая сторона отвергается перепроверкой под блокировкой, как в
   `tests/Feature/Domain/PostDeleteModerationRaceTest.php`.
7. **Лимиты скорости** (`ActionRateLimiter` до транзакции, ключ по пользователю) считают и
   повторы; решение между пейсингом `run_after` и отдельными ограничениями доверенного
   исполнителя принимается на Phase 4 (H3); безусловный обход защиты через публичные
   Actions не допускается.

### E5. Однократное применение и восстановление

- **Захват.** Исполнитель захватывает действие строковой блокировкой `SELECT … FOR
  UPDATE` и переводом `dispatched → running` с `claimed_at` в короткой транзакции, которая
  фиксируется **до** доменной работы; второй процесс с тем же id либо ждёт блокировку и
  видит `running`/`done`, либо получает ноль затронутых строк в условном `UPDATE` — и
  выходит. На PostgreSQL (единственная развёрнутая СУБД) это compare-and-set;
  дополнительные блокировки Cache/Redis не нужны.
- **Фиксация.** Доменная запись (пост, комментарий, голос), необходимые пересчёты и
  `running → done` фиксируются одной основной транзакцией. Авария **до** фиксации:
  изменения PostgreSQL откатываются, но статус `running` и `claimed_at` зафиксированы
  транзакцией захвата и остаются — именно поэтому захват и доменная работа разделены:
  статус, выставленный внутри откатываемой транзакции, откат не пережил бы. Авария
  **после** фиксации: строка `done`, повторная доставка Redis видит `done` и ничего не
  делает. Разрыв между обновлением статуса в PostgreSQL и отправкой в Redis
  (`dispatched` без задания: Redis недоступен, процесс умер до `afterCommit`, задание
  потеряно при `queue:flush`, старый воркер не знает класс) не теряет действие навсегда —
  его подбирает восстановление.
- **Ограждение поздней фиксации.** Основная транзакция перед доменной работой повторно
  блокирует строку действия и проверяет `status = running` и номер попытки `attempts`,
  с которым строка была захвачена. Если восстановление успело вернуть строку в
  `dispatched` (и, возможно, другой процесс уже захватил её с большим `attempts`), поздний
  исполнитель откатывается без доменных изменений. Контракт тайм-аутов делает этот
  случай маловероятным; проверка делает его безопасным.
- **Обнаружение мёртвого исполнителя.** Критерий временной: `running` с `claimed_at`
  старше `timeout` Job плюс запас (контракт тайм-аутов гарантирует, что Laravel прервал
  задание, а Supervisor `stopwaitsecs=130` и `--max-time=3600` завершили процесс);
  `dispatched` с `dispatched_at` старше порога ожидания захвата (обычно секунды, порог —
  минуты) считается потерянным. Оба порога — конфигурация, не константы кода; конкретные
  значения подбираются на Phase 4 (H2).
- **Восстановление.** Диспетчер тем же ежеминутным проходом возвращает просроченные
  `dispatched`/`running` строки в оборот: условным обновлением (`WHERE status = ? AND
  <штамп> < ?`) снова в `dispatched` с новым `dispatched_at` и `attempts + 1`, затем
  диспетчеризация. Повторная отправка — новая попытка, и `attempts` растёт только здесь и
  при первой отправке: захват и завершение его не меняют, поэтому одна оборванная
  попытка никогда не считается дважды. Восстановленное действие исполняется безопасно:
  доменных изменений от оборванной попытки нет (откат), а `done` строки не трогаются,
  `pending` ждут своей очереди.
- **Ограничение попыток и отсутствие бесконечного цикла.** При достижении предела
  (конфигурация) восстановление вместо повторной отправки переводит строку в `failed` с
  кодом «попытки исчерпаны» и показывает администратору; повторная отправка не раньше
  истечения порога, так что один сбой не порождает шторм повторов. Требования
  однократного применения, восстановления и конечности повторов утверждены; отложен
  только выбор чисел (H2), и он не является основанием менять архитектуру.
- **Побочные эффекты вне транзакции** (задание вариантов изображения, задание
  уведомлений подписчикам, вызовы ИИ) гарантией не покрываются: они идемпотентны или
  дедуплицированы (пропуск готовых вариантов, дедупликация уведомлений по строкам
  `notifications`), повторный запуск — через существующие механизмы («fresh dispatch» в
  Media diagnostics). Database-уведомления, созданные Actions внутри основной
  транзакции, фиксируются вместе с ней (D9, D12).
- **Лимиты скорости** сбрасываются не транзакцией: каждая попытка потребляет лимит
  `ActionRateLimiter`; при восстановлении это учитывается пейсингом или отдельными
  ограничениями (E4, п. 7).

### E6. Зависимости, ожидание и отмена

`parent_action_id` задаёт дерево: пост → комментарии → ответы и голоса за комментарии;
пост → голоса за пост и рейтинги. Правила (решение):

- пока родитель не `done`, зависимое действие остаётся `pending` и не диспетчеризуется;
  после `done` родителя готовые дочерние становятся доступными (сразу или следующим
  проходом диспетчера);
- ребёнок читает `result_id` родителя и передаёт модель в Action; голос за комментарий
  невозможен до создания комментария;
- ошибка одного комментария не блокирует независимые комментарии: выбор идёт по строкам,
  сиблинги не затрагиваются;
- временный сбой родителя (класс A, `running`/`failed` после технической ошибки) не
  отменяет детей — они ждут; после восстановления родителя исполнение продолжается
  автоматически;
- окончательная отмена родителя (класс B или решение администратора) отменяет поддерево
  в той же транзакции (`whereIn('parent_action_id', …)->whereIn('status', [pending,
  held, dispatched])`), независимые действия продолжаются;
- доступность цели и допустимость актора проверяются отдельно (D10): запрет автора уже
  опубликованного поста не отменяет комментарии и голоса других активных персон.

Образец «провалить остаток, сохранить сделанное» — `StartProjectTranslationGenerationAction.php:156-161`.
Режим `Off` у персоны отключает только автоответы и не отменяет утверждённые планы;
проверка интенсивности живёт в правилах ответов (Phase 8), не в исполнителе планов.

### E7. Доверенные исторические даты

Решение окончательное (D5). Параметры добавляются к существующим DTO/сигнатурам с
умолчанием `null` на этапе, где появляется потребитель: Phase 4 —
`CreatePostData::$createdAt/$updatedAt` и дата публикации в `ApprovePostAction`; Phase 5 —
`AddCommentAction::handle(..., ?CarbonImmutable $createdAt = null)` и
`CommentReplyService::createReply(...)`; Phase 6 — параметр даты в трёх Actions
голосования. Значения применяются внутри транзакций после всех проверок и блокировок,
не обходя их. Единственный вызывающий — Action исполнения; grep-тест запрещает
презентации передавать эти параметры. Фактическое время исполнения — `finished_at`
строки действия; `moderation_logs` и `notifications` сохраняют реальные даты.

Удержание исторического `updated_at` (решение, D5): при первоначальном создании
`created_at` и `updated_at` получают запланированную дату; технические записи того же
шага — одобрение, первоначальная публикация, пересчёт `hot_score`, `comments_count` и
счётчиков голосов — исполнитель выполняет под `Post::withoutTimestamps()` и
`Comment::withoutTimestamps()`, поэтому историческое `updated_at` переживает исполнение
плана. Голоса получают обе даты при создании (`PostVote`, `CommentVote`) или в `upsert`
(`RatingVote`). Последующее настоящее редактирование обновляет `updated_at` обычным
образом; отдельная система исторических записей не создаётся. Даты всегда берутся из
`occurs_at` плана: опоздание исполнения не делает действие историческим.

### E8. Деплой во время исполнения действий

Существующий механизм сохраняется и не переписывается заранее. Фактический порядок
`infrastructure/scripts/deploy` (`perform_deploy`):

1. **Подготовка и миграции** в каталоге нового релиза: `config:cache`,
   `rateguru:sharing:verify`, `view:cache`, `migrate --force` при `--migrate`
   (`deploy:1945-1961`), затем backfill переводов, обнаруживаемый через `list --raw`
   (`:486`). Порядок artisan-вызовов закреплён `DeployTest.php:893`.
2. **Атомарное переключение `current`** — `ln -sfn` + `mv -Tf` (`:1987-1995`).
3. **Перезагрузка PHP-FPM** — `systemctl reload` (`:1999`): веб-запросы и cron
   (`cd …/current && artisan schedule:run`, `infrastructure/config/cron/rateguru-staging-scheduler:4`)
   с этого момента выполняются кодом нового релиза.
4. **HTTP health check** (`:2014-2020`); провал — откат ссылок (`:1474-1536`).
5. **`queue:restart`** — `perform_queue_transition` (`:2026`, `:505-575`): при уже
   работающем воркере сигнал обязателен (`issue_queue_restart`, `:450-460`), иначе воркер
   бесконечно обслуживал бы старый релиз.
6. **Плавная смена процесса Supervisor**: воркер завершает текущее задание и выходит,
   Supervisor поднимает процесс, который делает `cd current && exec … queue:work` уже в
   новом релизе (`install-bootstrap-services:1091`); смена pid не является условием
   деплоя — скрипт ждёт лишь состояния RUNNING (`wait_for_queue_program_running`).

Следствия для Content Studio: между шагами 3 и 6 веб-интерфейс и планировщик работают
новым кодом, а воркер ещё может быть старым (до минут при длинном задании); миграции
применены ещё до переключения `current`, то есть старый воркер работает с новой схемой.
Обычный деплой не останавливает Content Studio: начатое действие завершается старым
воркером (его основная транзакция либо фиксируется, либо откатывается — E5), новые
исполняются новым.

Требования к заданиям и миграциям Content Studio (решение):

1. Задания переносят только id действия; содержимое читается из PostgreSQL.
2. Никаких сериализованных Eloquent-моделей или объектов старого релиза в payload.
3. Миграции совместимы с предыдущим релизом, пока старый воркер работает: `migrate`
   выполняется на шаге 1, до переключения `current` и до `queue:restart`, поэтому новые
   миграции должны быть аддитивными для таблиц, которые читает старый код; схему, нужную
   старому воркеру, нельзя удалять или менять до его завершения.
4. `timeout` задания меньше Redis `retry_after`.
5. Новые классы заданий, неизвестные старому воркеру: `queue:restart` на шаге 5 не
   означает мгновенного исчезновения старого процесса, а планировщик нового релиза
   начинает работать уже после шага 3. Безопасная последовательность первого внедрения и
   будущих несовместимых изменений: релиз с исполнителем выходит с **выключенным**
   диспетчером (feature-флаг проекта по образцу `ImportFromUrlAction.php:35-37`,
   умолчание в `ProjectSettingsManager`); после подтверждения, что воркер нового релиза
   запущен (`supervisorctl status` RUNNING с новым pid после `queue:restart`), флаг
   включается. Если задание всё же попало к старому воркеру, оно падает, а строка
   остаётся `dispatched` и подбирается восстановлением (E5).
6. Проверки совместимости перед включением: миграции применены, воркер нового релиза
   запущен, `REDIS_QUEUE` хоста соответствует имени очереди воркера (операторское значение,
   скриптами не проверяется). Инфраструктура, Supervisor и скрипт деплоя не меняются.

### E9. Персоны и путь публикации

Персона — строка `users` с `role = persona`, `status = active`, `password = NULL`,
синтетическим уникальным `email` на зарезервированном домене (`*.invalid`, как у tombstone),
`email_verified_at = NULL` (решение: синтетический адрес не считается подтверждённым),
`trust_level = 10` по умолчанию (`User::booted()`), сгенерированным `username`. Создание —
новый Action (образцы `CreateAdminUserCommand`, `RegisterSocialUserAction`), без события
`Registered`. Вход закрыт ролевым предикатом во всех семи точках (раздел C); панель уже
закрыта `canAccessPanel()`. Аватар — админский Action по пяти шагам
`UpdateUserProfileAction`. В разделе Users персона видна через фильтр роли; санкции
(`UserPolicy::canSanction`) к ней применимы; создаётся и редактируется только через
Content Studio.

Путь публикации (D3) — одна основная транзакция исполнителя: блокировка строк `users`
персоны и утвердившего администратора по возрастанию id →
`CreatePostAction::handle($persona, $data)` → пост `Pending` с историческими
`created_at`/`updated_at` и готовым `MediaAsset` → `ApprovePostAction::handle($approvingAdmin,
$post, null, $publishedAt)` → `Published` с запланированной `published_at`, запись
`ApprovePost` в `moderation_logs`, `PostApprovedNotification` автору штатно →
`RecalculatePostScoreAction::handle($post)` под удерживаемой блокировкой поста →
`running → done`. Технические записи идут под `Post::withoutTimestamps()` (E7); задания
вариантов и подписчикам уходят после фиксации (`afterCommit()`). Доверенное разрешение на
публикацию — утверждение плана; ни ложное определение персоны как персонала, ни
фиктивная верификация email не используются.

### E10. Медиа на этапе черновика

Шаг Image редактора сохраняет файл сразу: `ImageUploadStorer::store($file,
MediaStoreRequest::forPostImage($uploaderAdminId))` → `MediaAssetCreator::create($stored,
MediaKind::PostImage, $uploaderAdmin)` → `GenerateMediaVariantsJob::dispatch($asset->id)`;
для URL-источника перед этим `StoreImportedImageAction::download()` с обязательным
`cleanup()`. Владение и жизненный цикл ассета черновика (предложение в рамках
существующей системы, без нового хранилища):

- **Владелец до публикации** — загрузивший администратор (`owner_user_id` по
  документированной семантике «загрузивший, не потребитель», `docs/architecture/media.md`);
  путь файла поста `media/post-images/Y/m/{uuid}` владельца не содержит
  (`MediaPathGenerator.php:35-37`), поэтому **смена автора плана ничего не делает с
  файлом**; ассет не привязан к текущей персоне.
- **Ссылка черновика** — `studio_plans.image_asset_id`, третий источник в
  `MediaReferenceChecker::isReferenced()/referencedAssetIds()`; пока план существует и
  ссылается на ассет, audit не считает его неиспользуемым и Release не предлагается.
- **Право использовать ассет при публикации** — исполнитель передаёт в `CreatePostData`
  только `image_asset_id` исполняемого плана; `CreatePostAction` под `lockForUpdate`
  проверяет `kind = PostImage`, `status = Ready`, не trashed, отсутствие ссылки из другого
  поста. Владелец не сравнивается с автором.
- **Переход к посту** — в момент фиксации `create_post`: `posts.image_asset_id`
  заполняется; `GenerateMediaVariantsJob` при повторной диспетчеризации пропускает
  готовые варианты.
- **Снятие ссылки черновика** — при замене фотографии в черновике или удалении/отмене
  неисполненного плана: ссылка плана снимается и вызывается
  `MediaLifecycleService::releaseUnreferenced([$id])` — ассет мягко удаляется, если на
  него не ссылается ни пост, ни другой план; физические файлы удалит `media:purge` после
  7 дней грации.
- **Очистка неиспользуемых черновиков** — только через снятие ссылки; автоматического
  удаления активных ассетов нет и не добавляется.

Общая классификация SFW/NSFW — отдельная подсистема (E12), не часть этого пути.

### E11. Бизнес-очередь и техническая очередь

| | Бизнес-очередь Content Studio | Техническая очередь Laravel |
|---|---|---|
| Где | `studio_plans`, `studio_plan_actions` (PostgreSQL, в периметре бэкапа) — источник истины | Redis-список `queues:<имя>`, `failed_jobs` — транспорт заданий |
| Что хранит | план, действие, статус, зависимости, `occurs_at`/`run_after`, результат, причина отказа, `finished_at` | сериализованное задание с одним id |
| Кто меняет | диспетчер, Action исполнения, администратор (отмена, повтор, возобновление) | воркер (`queue:work redis --queue=<цель>`) |
| Видимость | экран Action queue | только `failed_jobs` через Media diagnostics (медиа-подмножество) |
| Потеря | нет (транзакционная БД) | допустима: восстановление E5 заново выдаёт просроченные `dispatched`/`running` |

Имя очереди: очередь цели по умолчанию (воркер слушает только её); отдельная очередь,
дополнительные процессы и изменения Supervisor в первой версии не требуются (решение).

### E12. Будущие подсистемы вне ядра

- **Классификация SFW/NSFW** — самостоятельный этап с отдельным выбором модели (локальная
  CPU-модель на VPS, внешний поставщик, иное); общая для пользовательских загрузок и
  Content Studio; одно изображение не обрабатывается дважды (чтение нормализованного
  мастера через `MediaStorage::readStream()` как в `MediaVariantGenerator::readMasterBytes()`);
  ошибка классификации не ломает загрузку и остальные механизмы; результат нужен для
  правил отображения, фильтрации, размытия и модерации, а не как «ошибка» для
  эротического контента; потенциальный `MediaVisibility::Private` не считается полной
  защитой публичного файла без дополнительной проверки (файл остаётся под публичным
  симлинком, `docs/architecture/media.md`); не зависит от клиента OpenAI. Точка
  подключения — `MediaAssetCreator::create()` с `afterCommit()`-диспетчеризацией задания
  по id ассета.
- **География и история входов** — отдельное инфраструктурно-прикладное направление:
  сначала изучение данных MaxMind (страны, города, идентификаторы, обновления, точность),
  затем справочник, поля профиля и журнал входов; в `users` и `persona_profiles`
  географические поля сейчас не добавляются; механизм записи успешных входов
  проектируется на этапе GeoIP с учётом факта, что событие `Login` срабатывает до гейта
  tombstone (П9).

### E13. Что не создаём

Отдельный `StudioPost`; свои таблицы голосов; фиктивные пользовательские действия;
универсальный AI Engine/Provider Registry (существующий `TranslationProviderRouter` —
ровно то, что отложено), реестр моделей, интерфейс выбора поставщиков, общий мониторинг
расходов ИИ; режим «обеспечить голос» в публичных Actions; флаги подавления уведомлений
в Actions, DTO и заданиях; отдельную систему исторических записей; дополнительные
блокировки Redis/Cache для исполнителя; отдельную очередь и новые процессы Supervisor;
одну транзакцию на весь план; массовый импорт, content packs и сидеры рабочего
контента; географический справочник, GeoIP и журнал входов; кеш ленты.

---

## F. Implementation Dependencies

Этапы идут по пользовательским сценариям: сначала работающие Personas, затем планы с
изображением, ИИ для текстов, публикация с исполнителем, обсуждения, голоса. Каждый этап
делится на небольшие MR и завершается проверяемым на staging результатом. Правила для
всех этапов (решение): расширения Actions вносятся не раньше появления их потребителя;
исполнитель не строится заранее под будущие сценарии, но восстановление после сбоя и
однократное применение входят в первый же MR, вводящий рабочий исполнитель; критическая
безопасность не откладывается; пункт навигации не ведёт на несуществующую страницу;
совместимость со staging и production сохраняется. GeoIP, география профилей, история
входов и универсальная система управления ИИ — отдельные направления вне этого плана.

### Phase 0 — Technical Audit

Текущий документ. Только анализ; код не менялся.

### Phase 1 — Personas

Два MR допустимы, но этап один, и он не считается завершённым после добавления enum и
фабрики.

**MR 1.1 — роль, авторизация, проверки прав и совместимость.**

- **До начала:** документ принят.
- **Существующие компоненты:** `UserRole`, `User`, `UserPolicy`, `ListUsers`, `EditUser`,
  `ModeratesUsers`, `UpdateUserAccountAction`, `CreatePostAction`, `ListPosts`, семь гейтов
  входа, `EnsureAccountIsNotTombstoned`, `NoDirectControllerPermissionCheckRule`.
- **Новое:** `case Persona`, ролевой предикат входа, `isStaff()`/`label()`, no-op
  `sendPasswordResetNotification()` для персон, запрет пароля и переходов роли в
  `UpdateUserAccountAction`, явная проверка `Admin`/`Moderator` в `CreatePostAction` и
  `ListPosts`, фабричное состояние `persona()`, строка в `docs/architecture/user-lifecycle.md`.
- **Риски:** пропущенная точка входа; регрессия списка пользователей; случайное изменение
  условия публикации для реальных админов/модераторов (их освобождение сохраняется явно).
- **Критерий на staging:** персона, созданная фабрикой, видна в Users с фильтром роли, её
  страница открывается; вход по паролю (если выставлен напрямую в БД), через
  Google/Facebook и сброс пароля отвергаются с общим сообщением; `/admin` закрыт; пост
  персоны (создан тинкером) ложится в `Pending`; обычный пользователь и модератор публикуют
  как раньше.

**MR 1.2 — `PersonaProfile`, Actions управления и полноценный интерфейс.**

- **До начала:** MR 1.1.
- **Существующие компоненты:** `ListUsers`/`EditUser` как образец страницы Admin v2
  (черновик, сохранение из верхней панели, `filament.pages.editor.topbar`), `x-admin.ui.*`,
  `GenerateUniqueUsernameAction`, `ImageUploadStorer`, `MediaAssetCreator`,
  `GenerateMediaVariantsJob`, `AdminNavigationGroup`, `AdminShellNavigation`, публичный
  профиль `/u/{username}`.
- **Новое:** миграция и модель `PersonaProfile`, `CreatePersonaAction`,
  `UpdatePersonaAction`, Action установки аватара, страницы Personas (список, создание,
  редактирование) в секции Content Studio с иконкой и обновлёнными `AdminShellTest`/
  `AdminNavigationGroupTest`, allow-list в `UserLifecycleWriteBoundaryTest` при явной записи
  статуса, раздел Content Studio в `migration-plan.md` с синхронным обновлением
  `AdminDesignDocsTest`.
- **Риски:** дублирование логики аватара вместо выделения общих шагов; нарушение
  PHPStan-правил записи из презентации; несоответствие дизайн-контракту
  (`docs/design/admin/ui-review-checklist.md`); добавление географических полей или
  почасового расписания из макета (не делаем).
- **Критерий завершения этапа:** администратор создаёт и редактирует персону
  исключительно через Content Studio, загружает аватар, задаёт характер, манеру речи,
  темы, ограничения, интенсивность ответов и дневной лимит, видит публичный профиль
  `/u/{username}` и находит персону в Users через фильтр роли; персона не может войти ни
  одним способом.

### Phase 2 — Publication Plans & Image Draft

- **До начала:** Phase 1.
- **Существующие компоненты:** `ImageUploadStorer`, `MediaAssetCreator`,
  `StoreImportedImageAction`, `GenerateMediaVariantsJob`, `MediaReferenceChecker`,
  `RatingConfigurationManager` (активные группы и варианты для ответов автора),
  `ListPosts` (просмотр изображения), компоненты Admin v2.
- **Новое:** минимальная модель `StudioPlan`, Actions создания/обновления плана, страница
  Publications & plans, каркас редактора Image → Post → Discussion → Publication с
  сохранением черновика, выбор автора только из персон, сохранение фотографии через
  существующий `MediaAsset` с владельцем-загрузчиком, третий источник в
  `MediaReferenceChecker` с тестом и правкой `docs/architecture/media.md`, снятие ссылки
  и `releaseUnreferenced()` при замене фотографии или удалении черновика. Без очереди и
  исполнителя.
- **Риски:** ассет черновика без ссылки (Release в Media diagnostics); жёсткая привязка
  файла к персоне; разрастание редактора в собственную логику публикации.
- **Критерий на staging:** план с фотографией сохраняется и переоткрывается; смена автора
  не требует перезагрузки файла; `media:audit` не отмечает ассет плана как
  неиспользуемый; удаление черновика освобождает ассет.

### Phase 3 — OpenAI Image Understanding & Post Texts

- **До начала:** Phase 2 (есть изображение и черновик).
- **Существующие компоненты (образец, копируется, не импортируется):** рецепт `Http`,
  валидация настроек и защита ключа из `OpenAiTranslationProvider`, классификация
  ошибок, разбор Responses API, `DomainLogger`, механизм контракта окружения, тестовые
  фикстуры `openAiResponseBody()`; `MediaStorage::readStream()` для чтения
  нормализованного мастера.
- **Новое:** минимальный клиент Responses API для Content Studio (конкретная модель
  OpenAI, `input_image` + структурированный ответ), конфиг на фичу и ключи в
  `environment-contract.json` и шаблонах, enum ошибок, анализ изображения на шаге Image,
  генерация вариантов заголовков и описаний на шаге Post с выбором и редактированием
  финального текста, использование характера персоны как контекста где нужно,
  минимальный технический учёт вызовов (без общего мониторинга), тесты с `Http::fake`.
- **Риски:** переиспользование переводного ключа под расходы Content Studio (противоречит
  правилу именования); повторы, удваивающие платные вызовы; расползание в универсальный
  движок.
- **Критерий на staging:** анализ фотографии и варианты заголовков/описаний появляются
  в редакторе, финальный текст выбирается и редактируется; при пустом ключе функция
  отвергается как «не настроена», остальное приложение работает.

### Phase 4 — Publishing & Historical Actions

- **До начала:** Phase 2 (план с изображением и текстами); Phase 3 желательна, но не
  блокирует.
- **Существующие компоненты:** `CreatePostAction`, `CreatePostData`, `ApprovePostAction`,
  `PostPolicy::approve`, `RecalculatePostScoreAction`, `NotifyFollowersAboutNewPostJob`,
  `routes/console.php`, cron `schedule:run`, Redis-воркер, `LocksActorForWrite`, образцы
  `GenerateProjectTranslationChunkJob` и `PostRetentionPurgeService`, механизм
  feature-флагов проекта, `FailedMediaJobReader`/`MediaDiagnosticsPage` как образцы
  повтора и экрана.
- **Новое (один этап, восстановление и однократность — в первом же MR исполнителя):**
  утверждение плана с предпроверкой (D11), `CreatePostData::$imageAssetId`,
  `$createdAt`/`$updatedAt`, доверенный параметр даты публикации в `ApprovePostAction`,
  `afterCommit()` на job подписчикам, таблица `StudioAction`, диспетчер
  `everyMinute()->withoutOverlapping()` с восстановлением зависших строк,
  `ExecuteStudioActionJob` и Action исполнения с двумя транзакционными границами
  (зафиксированный захват `dispatched → running`, затем основная транзакция: блокировка
  строк `users` по возрастанию id → `CreatePostAction` → `ApprovePostAction` →
  `RecalculatePostScoreAction` → `done`) под `Post::withoutTimestamps()`, фиксация
  исходов отдельной транзакцией, выключатель диспетчера, экран Action queue (статусы,
  причины, повтор, отмена, возобновление `held`), решение по лимитам скорости (H3),
  grep-тест на недоступность доверенных параметров из презентации, правка устаревших
  комментариев про `sync`. Уведомления (`PostApprovedNotification` персоне, задание
  подписчикам) создаются штатно — флагов подавления нет.
- **Риски:** `timeout` Job выше `retry_after` 90 с; двойное исполнение без условного
  обновления и ограждения номером попытки; статус `running`, выставленный внутри
  откатываемой транзакции; двойной счёт `attempts` на одну попытку; старый воркер
  получает новый класс задания (выключатель и восстановление); потеря прав утвердившего
  администратора к моменту исполнения; исчерпание лимита `upload` персоной; технический
  `save()` вне `withoutTimestamps()`, стирающий историческое `updated_at`; блокировка
  строк не в порядке проекта (персона → администратор вместо возрастания id) и
  взаимная блокировка с санкцией или публичным голосом.
- **Критерий на staging:** утверждённый план создаёт реальный пост персоны «сейчас», в
  будущем и с датой в прошлом; пост в ленте стоит на месте своей даты, имеет запись
  `ApprovePost` в журнале модерации, `hot_score`, рассчитанный в той же транзакции, и
  `updated_at`, равный исторической дате после одобрения и пересчёта; искусственно
  повторенное задание не создаёт дубликата; убитый посреди исполнения воркер оставляет
  строку `running`, действие восстанавливается и завершается один раз с `attempts = 2`;
  потерянное задание Redis переотправляется; параллельные санкция персоны и публичный
  голос за тот же пост не приводят к взаимной блокировке; деплой во время исполнения не
  теряет и не дублирует действие; Action queue показывает реальное `finished_at`.

### Phase 5 — Discussion Generation

- **До начала:** Phase 3 и Phase 4.
- **Существующие компоненты:** `AddCommentAction`, `CommentReplyService::canStartReply()`,
  предикаты `Post`/`Comment`, `CannotCommentException`, `CommentListQuery` (порядок по
  датам), клиент OpenAI этапа 3.
- **Новое:** генерация связного обсуждения (фотография, заголовок, описание, характер и
  манера речи каждой персоны; связность комментариев и ответов; глубина — один уровень
  ответов), редактирование и перегенерация отдельных частей до утверждения, шаг
  Discussion редактора, виды действий `create_comment`/`create_reply` с
  `parent_action_id`, параметр `$createdAt` в `AddCommentAction`/`createReply()`,
  отдельная причина `becauseReplyTargetUnavailable()`, предпроверка монотонности дат и
  глубины, отмена недоступных веток; исполнитель блокирует комментатора и автора поста
  (получателя `PostCommentedNotification`) по возрастанию id до вызова Action и выполняет
  шаг под `Post::withoutTimestamps()` / `Comment::withoutTimestamps()`, чтобы
  `comments_count` и hot score не стирали историческое `updated_at` поста.
- **Риски:** попытка глубины больше двух; ответ датой раньше корня; лимит `comment`
  10/60 с при пачке комментариев одной персоны; взаимная блокировка пары «комментатор —
  автор поста» при неверном порядке блокировок.
- **Выполнено (MR 5.1, хранение и черновик обсуждения):** таблица
  `studio_plan_discussion_nodes` (`StudioDiscussionNode`, `StudioPlan::discussionNodes()`)
  — по строке на комментарий и ответ, с устойчивыми идентификаторами, ровно два
  уровня, до 40 записей, смещение `offset_seconds` от публикации поста вместо
  абсолютной даты; единые правила `StudioDiscussionValidationRules` для ручного
  редактора и будущего генератора; `SaveStudioDiscussionTreeAction` атомарно
  сохраняет дерево целиком под блокировками `users` по возрастанию id → план и
  `lock_version` (устаревшая версия отвергается); удаление корня удаляет его
  ответы. Ничего не публикуется: ни `Comment`, ни `StudioAction`, ни заданий, ни
  вызовов ИИ, ни интерфейса. До этапа публикации обсуждений (MR 5.4) предпроверка
  утверждения отказывала плану с обсуждением (`discussion_not_publishable` →
  `unsupported_discussion`), чтобы пост не был опубликован без него. Подробности —
  `docs/architecture/studio-plans.md`, «The discussion».
- **Выполнено (MR 5.2, генерация обсуждения ИИ):** четыре режима —
  `full_discussion`, `regenerate_comment`, `regenerate_reply`, `regenerate_branch` —
  по запросу администратора через очередь (`RequestStudioDiscussionGenerationAction`,
  `GenerateStudioDiscussionJob`, `Start`/`Complete`/`Fail…Action`), один платный
  запрос на генерацию, без автоповторов. Контекст — окончательные заголовок и
  описание поста, категория и теги, завершённый анализ фото (не само фото),
  профили участвующих Persona (`PersonaProfile`, без приватных данных аккаунта) и
  текущее дерево; всё как данные, псевдонимы вместо ID. Ответ — строгий
  Structured Output, проверяемый целиком через `StudioDiscussionProposal` и
  `StudioDiscussionValidationRules`. История — `studio_plan_discussion_generations`
  с хешами анализа, поста с деревом и профилей Persona; результат — предложение,
  отдельно от сохранённого обсуждения. Принятие — `ApplyStudioDiscussionGenerationAction`
  через `SaveStudioDiscussionTreeAction` атомарно с `applied_at`, только при
  неизменном контексте (`stale_context`), один раз (`already_applied`); адресная
  перегенерация меняет только тексты, сохраняя ID, авторов, родителей, порядок и
  время. Интерфейса нет. Подробности — `docs/architecture/studio-plans.md`,
  «Discussion generation».
- **Выполнено (MR 5.3, шаг Discussion редактора):** `StudioPlanDiscussionEditor` —
  шаг Discussion редактора, устроенный как в прототипе: Discussion settings (Total
  comments, Top-level comments, Number of personas, Personas — Random, выбор среди
  активных при генерации, или Manual, отметка активных по одной, не больше 8),
  полоса Generate tree и Discussion tree с вкладками Comments и Timing. Комментарий
  пишется, отвечается и правится в диалоге (персона, Replies to, текст, время в
  min/h/d), удаляется после подтверждения, переписывается кнопкой Regenerate;
  Delete и Regenerate отменяются через Undo в тосте — возвращаются только удалённые
  комментарии или прежний текст переписанного, а любая следующая запись или Reload
  делают Undo недействительным. Каждое изменение сразу записывается в план через
  `SaveStudioDiscussionTreeAction`, отдельной кнопки сохранения нет. Генерация — всё
  дерево (с вопросом перед заменой непустого) или один комментарий — идёт через
  очередь; пока она в пути, компонент опрашивает сервер раз в 3 секунды, а готовый
  результат сразу принимается через `ApplyStudioDiscussionGenerationAction`. Что
  ждёт страница, определяется только по возвращённой генерации (режим и целевой
  комментарий), а не по нажатой кнопке. Завершённый, но непринятый результат,
  который план ещё может принять, принимается при открытии шага и возвращается
  повторному запросу вместо нового платного вызова. Полная
  генерация хранит в параметрах отпечаток final title и description
  (`source_text_fingerprint`); если тексты изменились, шаг говорит, что обсуждение
  написано для прежних, с Regenerate и Keep as is (`KeepStudioDiscussionAction`).
  Одна `lock_version` на все записи страницы синхронизируется событием
  `studio-plan-version-moved`, которое принимается только при совпадении версии в
  базе и отпечатка своих данных (пост или дерево); Reload одного редактора
  сообщается другому (`studio-plan-reloaded`). Ничего не публикуется. Подробности —
  `docs/architecture/studio-plans.md`, «The editor».
- **Выполнено (MR 5.4, исполнение обсуждения):** утверждение плана
  (`ApproveStudioPlanAction`) в одной транзакции с `create_post` пишет
  `create_comment` на каждый комментарий (родитель — `create_post`) и
  `create_reply` на каждый ответ (родитель — `create_comment` его комментария),
  каждое от Persona своего узла, `pending`, с неизменяемым `payload`
  (`discussion_node_id`, нормализованный `body`); `occurs_at` = дата поста +
  `offset_seconds`, `run_after` = max(момент утверждения, `occurs_at`), всё в UTC до
  секунды. Перед блокировкой плана блокируются администратор, автор и все Persona
  обсуждения по возрастанию id; состав, изменившийся к блокировке, — отказ как
  устаревшее состояние. Временный отказ `discussion_not_publishable` заменён полной
  предпроверкой (`StudioPlanApprovalPreflight`) на правилах
  `StudioDiscussionValidationRules` плюс готовность каждой Persona комментировать —
  восемь кодов `discussion_*`, отказ `invalid_discussion`. Исполнитель
  (`ExecuteStudioAction::RUNNABLE` = пост, комментарий, ответ) в прежних двух
  транзакциях: захват и основная; комментарий пишется через `AddCommentAction` с
  доверенной датой `$createdAt` (только исполнитель — `StudioTrustedParametersTest`)
  под `Post::withoutTimestamps()`/`Comment::withoutTimestamps()`; порядок блокировок —
  действие → комментатор и автор поста по возрастанию id → план → пост → родительский
  комментарий; на PostgreSQL строка автора поста берётся `FOR NO KEY UPDATE`, потому
  что двойное обновление поста в публичном комментарии или голосе проверяет
  внешний ключ через `FOR KEY SHARE` на авторе. Зависимость перепроверяется при
  захвате и перед записью; окончательная отмена родителя каскадно отменяет ветку
  (`CancelsDependentStudioActions`, `parent_cancelled`); скрытый или удалённый пост и
  родительский комментарий — `post_unavailable`/`reply_target_unavailable`;
  ограничение частоты комментариев типизировано в `CannotCommentException`
  (`isRateLimited()`, `retryAfterSeconds()`) и возвращает действие в `pending` через
  `deferrals`/`max_deferrals`. Уведомления `PostCommentedNotification` — штатные
  `AddCommentAction`, фиксируются вместе с комментарием. Гонки на PostgreSQL — в
  `StudioExecutorRaceTest` и `StudioPlanRaceTest`. Подробности —
  `docs/architecture/studio-plans.md`, «Approval», «Running a plan».
- **Дальше:** Phase 5 закрыта в коде; проверка критерия на staging ждёт включения
  исполнителя (`content_studio_execution_enabled` по умолчанию выключен). Экран
  Publication ещё не сделан; голоса — Phase 6.
- **Критерий на staging:** план с обсуждением создаёт настоящие комментарии и ответы
  персон с историческими датами в правильном порядке; `PostCommentedNotification` автору
  поста создаётся штатно с реальным временем; `updated_at` поста и комментариев остаётся
  историческим после всех пересчётов плана; скрытие корневого комментария отменяет
  только его ветку; техническая ошибка создания поста оставляет комментарии в ожидании
  и они исполняются после восстановления.

### Phase 6 — Votes & Ratings

- **До начала:** Phase 4; Phase 5 для голосов за комментарии.
- **Существующие компоненты:** `VotePostAction`, `VoteCommentAction`,
  `VoteRatingOptionAction`, `PostPolicy::vote`, `RecalculatePostCountersAction`,
  `RecalculateCommentCountersAction`, уникальные индексы голосов.
- **Новое:** виды действий `vote_post`/`vote_comment`/`vote_rating`, зависимости от
  постов и комментариев, предпроверка повторных и конфликтующих голосов и голосов за
  собственный контент, проверка фактического голоса исполнителем после блокировок
  актора и поста в порядке проекта — без предварительной блокировки строк
  `PostVote`/`CommentVote` (D4), необязательный параметр даты в трёх Actions
  (`CommentVote` через `forceFill()`, обе колонки в `upsert` рейтинга), пересчёты
  счётчиков под `Post::withoutTimestamps()` / `Comment::withoutTimestamps()`, шаги Votes
  редактора. Публичное поведение голосования не меняется.
- **Риски:** снятие голоса повтором; молчаливая смена противоположного голоса; лимит
  `vote` 60/60 с; сериализация на строке `RatingGroup` при массовых рейтинговых голосах;
  взаимная блокировка с публичным голосованием при блокировке строки голоса раньше
  строк `users` и `posts`.
- **Выполнено (MR 6.1, модели и правила запланированных голосов):** таблица
  `studio_plan_votes` (`StudioPlanVote`, `StudioPlan::votes()`) — по строке на
  намерение одной Persona проголосовать за одну цель: `vote_post` (вверх или вниз
  за пост), `vote_comment` (вверх или вниз за запланированный комментарий или
  ответ по `discussion_node_id`; составной внешний ключ с `studio_plan_id` не
  пускает к чужому плану) и `vote_rating` (вариант рейтинга, группа — его
  собственная), со смещением `offset_seconds` от поста и местом `sort_order`; ни
  `post_id`, ни `comment_id` — в черновике их нет. Цель — вычисляемый моделью
  `target_key` (`post`, `comment:{узел}`, `rating:{группа}`), уникальность
  `(studio_plan_id, persona_user_id, target_key)` без nullable-колонок — одна
  Persona голосует за цель один раз, в любую сторону; форма каждого вида и
  границы держатся `CHECK` на PostgreSQL, SQLite и MariaDB. Удаление черновика
  удаляет его голоса, удаление комментария — голоса за него и за его ответы;
  ссылки на рейтинг без внешнего ключа, так что архивирование и удаление
  вариантов не ломаются, а выбранный вариант никогда не подменяется. Единые
  правила `StudioVoteValidationRules`: черновик (`validated()`,
  `validatedAgainst()` — до 1 000 голосов, поля своего вида, Persona не удалена,
  не за свой пост и не за свой комментарий, не раньше комментария, новый выбор —
  только активный вариант активной группы, даты в пределах, что может нести пост)
  отдельно от готовности к исполнению (`readinessErrors()` — `Active` и
  `canVote()`, вариант всё ещё открыт). `SaveStudioVotesAction` атомарно сохраняет
  набор целиком под блокировками `users` по возрастанию id → план и `lock_version`,
  сохраняя ID и сопоставляя временные `key`. Смена даты поста
  (`UpdateStudioPlanAction`) и сохранение обсуждения проверяют голоса: дата, при
  которой голос выходит за предел, комментарий позже голоса за него или отданный
  голосующей за него Persona — отказ с конкретной ошибкой. До MR 6.4 предпроверка
  отказывает плану с голосами (`votes_not_publishable` → `unsupported_votes`).
  Ничего не голосует: ни `PostVote`/`CommentVote`/`RatingVote`, ни действий, ни
  интерфейса. Гонки на PostgreSQL — `StudioVoteRaceTest` и `StudioPlanRaceTest`.
  Подробности — `docs/architecture/studio-plans.md`, «The votes».
- **Выполнено (MR 6.2, редактор голосов за пост):** у шага Post две вкладки, как в
  прототипе, — Titles & texts и Votes; Continue и Back проходят их по очереди,
  четыре шага не меняются. `StudioPlanPostVotesEditor` (вкладка Votes) рисуется
  один раз на странице и скрыт на других шагах; новая публикация до черновика
  просит сначала сохранить черновик. Слева Post voters: автор поста не голосует,
  Random — число активных Persona с Pick others, Choose — поиск на сервере
  (`PersonasQuery`), автор в списке недоступен. Справа Post votes: Up · down,
  Answers, Post voters; вкладки Votes & answers (голоса вверх и вниз, рейтинговые
  группы с Answers, Even split и переносом ответа между вариантами), Voters и
  Timing (First vote after, Spread over, лента). Настройки — рецепт, он не
  хранится: `BuildStudioPostVotesData` по нему делает конкретные `StudioVoteData`
  (seed фиксирует выбор; сохранённые голоса той же Persona на той же цели
  предпочитаются, их ID сохраняются; `offset(i) = first + floor(i·spread/(n−1))`;
  нехватка Persona, чужой вариант, выход за 30 дней или 2038 и больше 1 000 голосов
  — отказ целиком, без урезания). Build preview показывает набор, Save votes
  сохраняет ровно его через `SaveStudioVotesAction` вместе с голосами обсуждения,
  прочитанными на той же версии; Remove post votes удаляет только голоса за пост
  и рейтинг. Повторно открытый редактор показывает сохранённые голоса: количества,
  Persona (как выбранные вручную), ответы, время; проблемы готовности —
  `readinessErrors()`. Источник событий версии — `post_votes`; несохранённая смена
  автора блокирует подготовку и сохранение. Миграций нет; голоса не голосуют,
  утверждение с голосами по-прежнему отклоняется (`unsupported_votes`).
  Подробности — `docs/architecture/studio-plans.md`, «The Post votes editor».
- **Критерий на staging для MR 6.2:** администратор на шаге Post → Votes задаёт
  голоса вверх и вниз, ответы по рейтинговым группам (вручную и Even split),
  Persona (Random и Choose) и время, видит предварительный набор и сохраняет
  ровно его; после перезагрузки видны те же сохранённые голоса; голоса за
  комментарии не теряются; сохранения поста, обсуждения и голосов сменяют версию
  без ложного конфликта; ни реальных голосов, ни `StudioAction`, ни заданий не
  появляется.
- **Выполнено (MR 6.3, голоса за комментарии в Discussion editor):** на шаге
  Discussion — `StudioPlanDiscussionVotesEditor`, отдельно от самого обсуждения,
  его вкладок и диалогов: карточки Comment voters и Comment votes — в верхнем
  ряду рядом с Discussion settings, как три карточки прототипа; разбор
  комментария — справа от дерева; голосующие — на вкладке Voters дерева. Comment
  voters: Random — число
  активных Persona с Pick others, Choose — поиск на сервере (`PersonasQuery`);
  автор комментария в списке помечен («Writes C3 · can’t vote on it») и может быть
  выбран — он не голосует только за свой комментарий. Comment votes: Total up и
  Total down, Distribute automatically делит их по весам приоритетов 1 / 2 / 3
  методом наибольших остатков, равные остатки — по порядку дерева, без
  случайности; First vote after и Spread over — от момента каждого комментария,
  одно значение для всех. В дереве у каждого комментария рядом со временем —
  голоса вверх и вниз в том виде, как они заданы, пометка «Not saved», пока они
  отличаются от сохранённых, и See breakdown: справа от дерева (на узком экране —
  вместо него) открывается разбор комментария — голоса вверх и вниз вручную,
  приоритет Low / Normal / High, собственные First vote after и Spread over и
  каждый голос: кто, куда и когда. Вкладки дерева: Comments, Voters (All,
  Voting, Also commenting, Not used; Reshuffle) и Timing (с голосами каждого
  комментария и их окном).
  `BuildStudioDiscussionVotesData` по рецепту делает конкретные `StudioVoteData`
  `vote_comment`: автор исключён на своей цели, один голос Persona на комментарий,
  новые голоса — только `Active` и `canVote()`, ограниченная Persona сохраняет
  свой голос; `offset = comment + first + floor(i·spread/(n−1))`; ID сохраняются
  по Persona и комментарию; нехватка Persona по комментарию («…need 4 personas
  who can vote on it; 2 can — 2 short»), выход за 30 дней или 2038 и больше
  1 000 голосов вместе с голосами поста — отказ целиком с ключом комментария.
  Логика выбора Persona общая с голосами поста (`DrawsPersonaVotes`). Голоса
  собираются сразу, как только настройки отличаются от сохранённых, и
  пересобираются при каждом изменении настроек, выбора или дерева; Save votes (под
  деревом и в разборе) сохраняет ровно их через `SaveStudioVotesAction`
  полным набором: голоса за пост и рейтинг читаются на той же версии и не
  меняются, а если один из них больше не проходит проверку — конкретная причина и
  отсылка к Post · Votes; совпадающий с сохранённым набор ничего не пишет.
  Pick others / Reshuffle (новый seed), Discard; Remove comment votes с
  подтверждением удаляет только `vote_comment`. Back, Continue и шаги редактора
  при несохранённых голосах спрашивают: Save and continue, Discard changes или
  Keep editing; уход со страницы — через вопрос браузера. Повторно открытый редактор
  показывает сохранённые голоса, приоритеты — Normal. Источник событий версии —
  `discussion_votes`; ход обсуждения принимается, если голоса за комментарии лишь
  ушли вместе с удалёнными комментариями, остальные — при совпадении голосов и
  дерева; при изменённом дереве голоса собираются заново; иначе Conflict и
  Reload. Миграций нет; `VotePostAction`, `VoteCommentAction`,
  `VoteRatingOptionAction`, очередь, исполнитель и публичное голосование не
  менялись; `content_studio_execution_enabled` выключен; утверждение с голосами
  по-прежнему отклоняется (`votes_not_publishable` → `unsupported_votes`).
  Подробности — `docs/architecture/studio-plans.md`, «The Comment votes editor».
- **Критерий на staging для MR 6.3:** администратор на шаге Discussion выбирает
  Persona для голосов за комментарии (Random и Choose), задаёт приоритеты и
  Total up / Total down, распределяет их автоматически и правит вручную, задаёт
  время после комментария, видит голоса на комментариях дерева с пометкой
  «Not saved» и в разборе комментария (See breakdown) и сохраняет ровно их; при
  уходе с шага с несохранёнными голосами получает вопрос; после перезагрузки
  видны те же голоса; голоса за пост и рейтинг не меняются;
  автор комментария за него не голосует; удаление комментария удаляет голоса за
  него без ложного конфликта; ни реальных голосов, ни `StudioAction`, ни заданий
  не появляется.
- **Дальше:** действия голосования, их исполнение с историческими датами и снятие
  временного отказа (MR 6.4).
- **Критерий на staging:** голоса персон вверх и вниз, за комментарии и за варианты
  рейтинга создаются с историческими датами, счётчики и рейтинг корректны, `updated_at`
  поста и комментария остаётся историческим; повтор задания не меняет голос;
  существующий такой же голос завершает действие без вызова Action; конфликтующий голос
  виден администратору как проблема; одновременные публичные голоса за тот же пост
  проходят без взаимной блокировки.

### Phase 7 — Shared NSFW Classification

- **До начала:** общий медиа-путь (Phase 2); не зависит от Phase 5/6 и может быть
  выполнен раньше них.
- **Существующие компоненты:** `MediaAssetCreator::create()`, `MediaStorage::readStream()`,
  `MediaVisibility`, `MediaStatus`, `MediaVariantGenerator`, Media diagnostics.
- **Новое:** экспериментальное сравнение моделей (локальная CPU-модель на VPS, внешние
  поставщики, иное) и выбор реализации; задание классификации по id ассета с
  `afterCommit()`; сохранение результата; правила отображения, фильтрации, размытия и
  модерации; общая обработка для пользовательских загрузок и Content Studio.
- **Риски:** `MediaStatusTest` закрепляет четыре значения; `Private` на локальном диске —
  флаг, а не контроль доступа; ошибка классификации не должна ломать загрузку; не
  привязывать к клиенту OpenAI.
- **Критерий на staging:** новая загрузка пользователя и фотография плана проходят
  классификацию один раз без повторной нормализации; результат виден администратору и
  применяется в правилах отображения; сбой классификации не блокирует загрузку.

### Phase 8 — Automatic Replies

- **До начала:** Phase 5 (генерация текста персоны), Phase 4 (исполнитель); решение по
  триггерам (уведомления автору родительского комментария нет; `PostCommentedNotification`
  даёт только комментарии к постам персоны).
- **Существующие компоненты:** `AddCommentAction`, `CommentReplyService`, таблица действий
  и исполнитель, `PersonaProfile` (интенсивность, дневной лимит, `Off`).
- **Новое:** `persona_reply_rules`, детектор триггеров, лимиты и интенсивность, защита от
  циклических ответов персон друг другу, журнал Reply log, экраны Reply rules и Reply log,
  интеграция с Action queue.
- **Риски:** лимит `comment` 10/60 с; зацикливание; `Off` обязан останавливать только
  автоответы; реакция на скрытый/удалённый контент.
- **Критерий на staging:** реальный комментарий к посту персоны порождает запись в
  журнале и, при включённой интенсивности, ответ персоны не чаще дневного лимита; `Off`
  останавливает автоответы, но не утверждённые планы.

### Phase 9 — Cleanup & Polish

- **До начала:** все предыдущие этапы.
- **Новое:** удаление временных компонентов и выключателей, проверка, что расхождения с
  Admin v2 и дизайн-контрактом (ширины панелей, вкладки вместо выпадающего фильтра,
  размер страницы 25, сохранение из верхней панели) устранены при реализации
  соответствующих экранов (H2), очистка демонстрационных механизмов, если они больше не
  используются, финальные регрессионные проверки.
- **Критерий на staging:** все экраны Content Studio проходят
  `docs/design/admin/ui-review-checklist.md`; временных флагов не осталось.

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
| Phase 1 | `UserRoleTest` (четыре роли); `UserLifecycleTest` (персона: панель закрыта при любом статусе); `UserPolicyTest` (персона санкционируема, не помечается доверенной); `UserResourceTest` (список и страница с персоной рендерятся, фильтр роли, роль персоны только для чтения); `AuthenticationTest`, `SocialLoginTest`, `SocialAccountClaimTest`, `SocialAccountLinkingTest`, `PasswordResetTest`, `TombstonedSessionTest` (персона отвергнута всеми путями, письмо сброса не уходит, живая сессия завершается); `CreatePostActionTest` (пост персоны → `Pending`; админ/модератор освобождены явно; синтетический email не считается подтверждённым); `UpdateUserAccountActionTest` (пароль персоне не задаётся, переход `user ↔ persona` закрыт); `RegistrationTest` (роль не принимается из ввода); новые тесты `CreatePersonaAction`/`UpdatePersonaAction`/аватара (по образцу `AvatarMediaLifecycleTest`, `UpdateUserProfileActionMediaVariantDispatchTest`); Filament-тесты страниц Personas (по образцу `UserResourceTest`); `AdminShellTest`/`AdminNavigationGroupTest` (новая секция и destination); `AdminDesignDocsTest` при правке плана миграции; `UserLifecycleWriteBoundaryTest` при записи статуса; `AdminDesignSystemSeparationTest` для новых Blade; `ProfilePageTest` (публичный профиль персоны) |
| Phase 2 | Новые тесты модели и Actions плана (автор только персона, смена автора без перезагрузки файла); `MediaReferenceCheckerTest` (третий источник, число запросов 2 → 3); `MediaAuditServiceTest` и `MediaDiagnosticsPageTest` (ассет плана не «неиспользуемый», Release не предлагается); `MediaLifecycleServicePurgeTest` (план удерживает ассет; снятие ссылки освобождает его); Filament-тесты редактора; `StoreImportedImageActionTest` без изменений |
| Phase 3 | Тесты клиента по образцу `OpenAiTranslationProviderTest` (`Http::preventStrayRequests`, статусы, транспорт, отказ модели, невалидный ответ, утечка ключа, тело запроса с `input_image`); `EnvironmentContractCompletenessTest` и `EnvironmentContractTest` для новых ключей; `TranslationEngineArchitectureGuardTest` остаётся зелёным (новый код вне `app/Support/TranslationEngine`); тесты шагов Image/Post (анализ, варианты, выбор финального текста, «не настроено» при пустом ключе) |
| Phase 4 | `CreatePostActionTest` (исторические `created_at`/`updated_at`; готовый ассет: пропуск storer, отказ на trashed/не Ready/не PostImage/чужую ссылку); `ApprovePostActionTest` (доверенная дата публикации, `now()` без неё, отказ при потере прав администратора; `PostApprovedNotification` создаётся как раньше); `ApprovePostActionNotificationTest`/`PostApprovedNotificationTest` без изменений поведения; `CreatePostActionMediaVariantDispatchTest` (без повторной генерации); `NotifyFollowersAboutNewPostActionTest` (`afterCommit()`); `RecalculatePostScoreActionTest` (вызов под `Post::withoutTimestamps()` не меняет `updated_at`); новые тесты диспетчера (образец `ContentCleanupScheduleTest` для расписания и `withoutOverlapping`), Job (контракт `tries`/`timeout` по образцу `GenerateProjectTranslationChunkJobTest.php:44-62`), Action исполнения и экрана Action queue — обязательные сценарии: **повторная доставка одного действия** (второй запуск не меняет данные); **захват зафиксирован отдельно** (после исключения в основной транзакции строка `running` с `claimed_at` сохраняется, доменных строк нет); **сбой до фиксации** (откат, строка `running`, восстановление, один пост в итоге, `attempts = 2`, не 3) и **сбой после фиксации** (`done`, повтор — no-op); **ограждение поздней фиксации** (строка переотправлена восстановлением — поздний исполнитель откатывается без доменных изменений); **потерянное задание Redis** (`dispatched` без задания переотправляется); **зависшие действия** (`running` с просроченным `claimed_at` восстанавливаются, предел попыток → `failed`); **исходы класса B/C** (`cancelled`/`failed` записываются после отката, доменных изменений нет); **пост, одобрение и hot score в одной транзакции** (нет состояния «опубликован без рейтинга»; `updated_at` равен исторической дате после одобрения и пересчёта); **уведомления штатно** (`PostApprovedNotification` персоне и задание подписчикам созданы с реальным временем, флагов нет); **порядок блокировок и параллельность** (на PostgreSQL вторым соединением: санкция персоны, публичный голос за тот же пост и скрытие поста модератором во время исполнения — без ошибки взаимной блокировки, проигравшая сторона отвергается перепроверкой; образцы `PostDeleteModerationRaceTest`, `ModeratorStaleActorProtectionTest`); **деплой во время выполняющегося задания** (выключенный диспетчер не отправляет; включение после подтверждения воркера); **совместимость старого воркера с новыми миграциями** (аддитивность); **ошибка создания родительского Post** (дети остаются `pending`); **восстановление зависимых действий** после успеха родителя; **окончательная отмена родителя** (каскад) и **продолжение независимых действий**; **блокировка и удаление персоны** (`Limited`/`Shadowbanned` → `held`, `Banned`/`Deleted` → `cancelled`, чужие действия продолжаются); **исторические временные метки** поста в ленте и профиле; `VoteRateLimitTest`/`CreatePostRateLimitTest`/`AddCommentRateLimitTest` при изменении ключей лимитов; новый grep-тест на недоступность доверенных параметров из презентации |
| Phase 5 | `AddCommentActionTest` (дата; `created_at` = `updated_at` = историческая); `AddCommentActionNotificationTest`/`PostCommentedNotificationTest` без изменений поведения (уведомление автору поста создаётся с реальным временем, флагов нет); `AddCommentActionHotScoreTest` (под `Post::withoutTimestamps()` `updated_at` поста не меняется); `CommentTombstoneLifecycleTest`/`CommentParentPostLifecycleTest` (отмена ветки по тем же предикатам; глубина не больше двух); тест новой причины `becauseReplyTargetUnavailable()`; тесты исполнителя: блокировка комментатора и автора поста по возрастанию id, параллельное скрытие поста без взаимной блокировки; тесты генерации обсуждения (структура, глубина, монотонность дат, редактирование и перегенерация части); `CommentListQueryTest` (исторические даты в правильном порядке) |
| Phase 6 | `VotePostActionTest`/`VoteCommentActionTest`/`VoteRatingOptionActionTest` (параметр даты, обе колонки в `upsert`; публичный переключатель не изменён; **прямое голосование вниз**); `PostVotingTest`/`CommentVotingTest`/`RatingVotingTest` (UI без изменений); тесты исполнителя: **существующий голос от того же актора** (действие `done` без вызова Action), конфликтующий голос → проблема в Action queue, повтор задания не снимает голос, **чтение голоса только после блокировок актора и поста** (строки `PostVote`/`CommentVote` заранее не блокируются), **параллельный публичный голос** за тот же пост на PostgreSQL без взаимной блокировки; предпроверка плана (повторные голоса, голос за своё); `RecalculatePostCountersActionTest`/`RecalculateCommentCountersActionTest` (счётчики после исторических голосов; под `withoutTimestamps()` `updated_at` поста и комментария не меняется); `FeedQueryTest` (hot/top после пересчёта) |
| Phase 7 | `MediaStatusTest`/`MediaVisibilityTest` при расширении enum; `MediaVariantGeneratorTest` (порядок классификация → `Ready` → варианты, если выбран статусный гейт); `FilesystemMediaUrlResolverTest`, `PostImagePresenter*Test`, `AvatarUrlResolverResponsiveTest` (приватный ассет без URL); `GdImageIngestorTest` без изменений (повторной нормализации нет); тест «сбой классификации не ломает загрузку» для `UploadPostFormTest`/`AvatarUploadTest` |
| Phase 8 | Новые тесты правил, лимитов, `Off`, защиты от циклов; `PostCommentedNotificationTest` при добавлении триггеров; тесты журнала ответов и его отмены |
| Phase 9 | Повторный прогон `tests/Feature/Filament/*`, `tests/Feature/Admin/*`, `AdminDesignDocsTest`; удаление тестов временных флагов |

---

## H. Open Technical Questions

Раздел разделяет три категории: **H1** — продуктовые и архитектурные решения, которые
уже приняты и открытыми вопросами не являются; **H2** — конкретные технические
решения, намеренно отложенные до своего этапа (архитектуру они не меняют); **H3** —
действительно нерешённые вопросы, требующие дополнительного исследования. Ссылки на
«H» в разделах C–G указывают категорию.

### H1. Принято и не пересматривается

- **Исполнитель и очередь.** Отдельная очередь и воркер не нужны (первая версия —
  существующий одиночный воркер); одной транзакции на весь план нет; исполнение действия
  — зафиксированный захват `dispatched → running`, затем основная транзакция с доменной
  записью, пересчётами и `done`; откат основной оставляет `running` восстановлению;
  `attempts` растёт только при отправке; дополнительные блокировки Redis/Cache не
  вводятся; временный сбой родителя не отменяет детей (E3–E6).
- **Порядок блокировок.** Исполнитель следует порядку проекта: строки `users` по
  возрастанию id → пост → комментарий → строки голосов; строки `PostVote`/`CommentVote` до
  вызова Action не блокируются; `RecalculatePostScoreAction` вызывается в той же
  транзакции под удерживаемой блокировкой поста (D3, D4, E4).
- **Публикация персоны.** `UserRole::Persona`, без `account_type`; синтетический email —
  технический идентификатор, `email_verified_at` остаётся `null`; публикует утверждение
  плана через `CreatePostAction` + `ApprovePostAction`; режим «обеспечить голос» не
  вводится, публичное голосование не меняется (D1–D4, E9).
- **Синтетический email.** Уникальный технический адрес на зарезервированном домене
  `.invalid` (образец tombstone); персона не получает пароль и не может авторизоваться
  через пароль, OAuth или восстановление доступа; почта на синтетические адреса не
  отправляется (D2, D9, E9).
- **Уведомления.** Database-уведомления не подавляются из-за исторических дат и
  создаются существующими механизмами `CreatePostAction`, `ApprovePostAction`,
  `AddCommentAction` без специальных флагов; они сохраняют реальное время создания;
  персоны не входят в систему, поэтому накопленные уведомления для них не проблема;
  поведение уведомлений подписчиков и уведомления реальных пользователей вне Content
  Studio не изменяются; запрещена только почта на синтетические адреса (D9).
- **Исторические временные метки.** При первоначальном создании `created_at` и
  `updated_at` получают запланированную дату; техническое одобрение, первоначальная
  публикация и пересчёты счётчиков и рейтинга не уничтожают историческое `updated_at`
  (`withoutTimestamps()` в исполнителе); последующее настоящее редактирование обновляет
  его обычным образом; принцип действует для `Post`, `Comment` и голосов по фактической
  модели данных; отдельная система исторических записей не создаётся; опоздание
  исполнения не делает действие историческим (D5, E7).
- **Деплой.** Порядок `infrastructure/scripts/deploy` сохраняется: подготовка и миграции →
  переключение `current` → PHP-FPM → health check → `queue:restart` → смена воркера;
  инфраструктура не меняется; диспетчер выходит выключенным и включается после
  подтверждения нового воркера (E8).
- **Восстановление действий.** Требования однократного применения, восстановления
  зависших `dispatched`/`running` и конечности повторов утверждены (E5); открыт только
  выбор чисел (H2).
- **ИИ.** OpenAI появляется на Phase 3, до обсуждений и автоответов; универсальная
  система моделей, провайдеров и расходов не строится; движок переводов не
  переписывается (E12, E13, П8).
- **SFW/NSFW.** Отдельная подсистема, общая для пользовательских загрузок и Content
  Studio, не привязанная к OpenAI или иному конкретному поставщику (E12).
- **Admin v2.** Зафиксированные продуктовые решения имеют приоритет перед
  противоречащими подписями старого макета: автор публикации — только персоны;
  исторические даты пишутся в обычные поля сущностей, реальное время — отдельно;
  обсуждение ограничено поддерживаемой глубиной; почасовое расписание персоны не
  требуется; `Off` не отменяет утверждённые планы; географические поля отложены;
  интерфейс строится на Admin v2 и его контракте. Исходный макет в Phase 0 не
  исправляется (П10а).
- **GeoIP.** MaxMind/GeoLite2, географические справочники, страна/город профиля и
  история входов остаются за пределами Content Studio; реализация начинается после
  отдельного анализа доступных географических данных; Phase 1 Personas от этого не
  зависит (E12, П9).

### H2. Отложено до своего этапа

| Вопрос | Что уже решено | Что определить на этапе | Этап |
|---|---|---|---|
| Формат синтетического email | Домен `.invalid`, уникальность, `email_verified_at = null`, никаких писем | Точный формат генерации уникального адреса — без изменения существующих email-механизмов пользователей | Phase 1 |
| Пороги восстановления и предел попыток | Временной критерий «потерянное `dispatched`» и «зависшее `running`», `attempts` только при отправке, `failed` при исчерпании, конфигурация вместо констант | Конкретные значения порогов и число попыток; подбираются на staging и не являются основанием менять архитектуру | Phase 4 |
| Клиент OpenAI | Отдельный минимальный клиент Responses API вне `app/Support/TranslationEngine`, свой блок конфигурации и ключи через `environment-contract.json` (`TRANSLATION_OPENAI_API_KEY` не переиспользуется) | Конкретная модель, тарифы, настройки, имена ключей окружения, минимальный учёт вызовов | Phase 3 |
| Автоматические ответы | Повторное использование существующих комментариев и исполнителя Content Studio; уведомления автору родительского комментария сегодня нет (только `PostCommentedNotification` автору поста) | Триггер ответов (запрос к `comments` или новое уведомление/событие) и форма журнала (строки таблицы действий с видом `auto_reply` или отдельная таблица); заранее не строится | Phase 8 |
| Расхождения макета с дизайн-контрактом | Правило приоритета решений над подписями макета (H1) | Технические расхождения — drawer редактора персоны 680 px при максимуме кита 480, сохранение Reply rules из нижней панели против верхней, размер страницы Action queue 15 против 25, фильтр статуса выпадающим списком против вкладок NAV-03 — решаются при реализации соответствующих экранов; записи в таблице отступлений контракта — при постановке UI-этапов | Phase 1, 2, 4, 8 (свои экраны) |

### H3. Действительно открыто — требует исследования

1. **Лимиты скорости для доверенного исполнителя.** `ActionRateLimiter` срабатывает до
   транзакции с ключом по пользователю (загрузка 5/600 с, комментарии 10/60 с, голоса
   60/60 с) и считает повторы; `tests/Feature/Support/ActionRateLimiterTest.php` требует
   положительных лимитов у каждого настроенного действия. Рассматриваются распределение
   нагрузки по времени (`run_after`) и отдельные ограничения доверенного исполнителя с
   собственной записью в `config/rate_limits.php`. Границы: безусловный обход защиты от
   злоупотреблений через публичные Actions не разрешается; существующие лимиты обычных
   пользователей заранее не меняются. Решать на Phase 4 после измерения реальной
   плотности действий плана.
2. **Реализация SFW/NSFW и правила для непроверенных изображений.** Выбор между
   локальной CPU-моделью на VPS, внешним поставщиком и иным требует экспериментального
   сравнения; гейт показа — через `MediaVisibility::Private` (файл остаётся под публичным
   симлинком) или `MediaStatus` (варианты) — зависит от выбранной модели. Система должна
   быть общей для пользовательских загрузок и Content Studio и не привязываться к
   конкретному поставщику. Решать на Phase 7.
3. **География и запись успешных входов.** Пригодность данных MaxMind (страны, города,
   идентификаторы, обновления, точность) для справочника и профиля не изучена. Для
   будущей истории входов единственный общий хук — событие `Login`, но оно срабатывает
   до гейта tombstone в `AuthenticateUserAction` (`Auth::attempt` уже вызвал `login()`),
   повторяется при оживлении сессии «запомнить меня» и при входе в `/admin/login`
   Filament, а id сессии регенерируется уже после события; альтернатива — явные вызовы в
   трёх контроллерах входа (образец `CompletePendingSocialLinkAction`), но они не видят
   вход в админку. Подтверждено: `trustProxies` не нужен сегодня (nginx — граница сети),
   `SESSION_DRIVER` на хостах — операторское значение. Решать на отдельном этапе GeoIP
   вне плана Content Studio.

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
3. **Безопасное создание управляемого аккаунта** — раздел E9: `email` уникален и
   обязателен (`0001_01_01_000000_create_users_table.php:17`), поэтому нужен синтетический
   немаршрутизируемый адрес как технический идентификатор; `email_verified_at` остаётся
   `NULL` — адрес не считается подтверждённым (решение); `password` nullable с миграции
   `2026_09_11_100000_make_users_password_nullable.php` и остаётся `NULL`; `status` по
   умолчанию `active`, `trust_level` — хук модели; без `Registered`; создание только через
   Action (PHPStan-правило `NoPresentationModelMutationRule`). Публикацию разрешает
   утверждение плана администратором (D3), а не свойства аккаунта.
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
   `User`), иначе `Pending` (`CreatePostAction.php:88-91`). После замены ролевой части на
   явную проверку `Admin`/`Moderator` пост персоны всегда `Pending`; публикует его
   `ApprovePostAction` от имени утвердившего администратора в той же транзакции
   исполнителя (D3).
3. **Даты** — `created_at`/`updated_at` ставит Eloquent (`Post::create` без них);
   `published_at = now()` только при публикации сразу, иначе `null` до одобрения
   (`ApprovePostAction.php:43`, `RestorePostAction.php:43` сохраняют существующее);
   доверенная дата публикации передаётся в `ApprovePostAction` необязательным параметром
   (`$locked->published_at ?? $publishedAt ?? now()`).
4. **Подтверждение публикации** — `ApprovePostAction` под блокировкой поста из `Pending`,
   лог `ApprovePost`, `PostApprovedNotification` автору, job подписчикам.
5. **Уведомления** — `CreatePostAction.php:136-147`, `ApprovePostAction.php:65-97`,
   `AddCommentAction.php:135-158`; все database и синхронные (job подписчикам — очередь).
6. **Связь с медиа** — `posts.image_asset_id` (FK `media_assets`, `nullOnDelete`),
   записывается только в `CreatePostAction.php:105`; `Post::imageAsset()`.
7. **Историческое время** — раздел E7: необязательные поля `CreatePostData`
   (`createdAt`/`updatedAt`) в массиве `Post::create` (`CreatePostAction.php:97-106`) и дата
   публикации в `ApprovePostAction.php:43`; статусная логика `:88-91` не меняется;
   `save()` одобрения и пересчётов выполняются исполнителем под
   `Post::withoutTimestamps()`, поэтому историческое `updated_at` сохраняется; уведомления
   создаются штатно с реальным временем (D9).
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
5. **Историческая дата** — необязательный параметр даты в трёх Actions на Phase 6;
   `PostVote` принимает `created_at` через `create`, `CommentVote` — `forceFill()`,
   `RatingVote` — значение в `upsert`.
6. **Исключение повторного исполнения** — статус `done` строки действия, фиксируемый
   одной транзакцией с голосом (повторная доставка Action не вызывает), плюс чтение
   фактического голоса исполнителем после блокировок актора и поста в порядке проекта
   (строки голосов заранее не блокируются) перед вызовом переключателя и предпроверка
   плана на повторные/конфликтующие голоса (D4); режим «обеспечить голос» в Actions не
   вводится; `VoteRatingOptionAction` идемпотентен уже сейчас.
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
4. **Сохранение для черновика** — раздел E10 (владелец — загрузивший администратор,
   ссылка плана как третий источник `MediaReferenceChecker`, смена автора без
   перезагрузки файла).
5. **Защита от удаления** — третья ссылка в `MediaReferenceChecker` (раздел D7).
6. **Расширение `CreatePostAction`** — раздел C.
7. **Точка SFW/NSFW** — `MediaAssetCreator::create()` с диспетчеризацией `afterCommit()`
   Job по id ассета (раздел E12); отдельная подсистема с отдельным выбором модели,
   независимая от клиента OpenAI.
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
2. **Передача обработчикам** — ежеминутный диспетчер (он же восстанавливает зависшие
   `dispatched`/`running`) и Job с единственным id (раздел E4); завершение родителя может
   сразу ставить готовых детей; отложенные `delay()` не рекомендуются (не отзываются при
   отмене, невидимы администратору).
3. **Одновременное выполнение** — `lockForUpdate` + условный `UPDATE` статуса на
   PostgreSQL; захват `dispatched → running` фиксируется отдельной короткой транзакцией;
   дополнительных блокировок Cache/Redis нет (решение, раздел E5); строки `users`
   берутся по возрастанию id, затем пост, затем комментарий — порядок проекта (E4).
4. **Однократное применение** — доменная запись, пересчёты и статус `done` в одной
   основной транзакции; откат оставляет `running` для восстановления; гарантия относится
   к данным PostgreSQL, не к внешним эффектам (D12).
5. **Зависимости** — `parent_action_id` и `result_type/result_id` (раздел E6).
6. **Исторические даты** — доверенные параметры Actions на своих этапах (раздел E7).
7. **Отмена зависимых** — только окончательная (класс B или администратор), каскадом по
   поддереву; временный сбой родителя оставляет детей в ожидании (разделы D11, E6).
8. **Продолжение независимых** — отбор по строкам, сиблинги не затрагиваются.
9. **Бизнес-очередь и техническая** — раздел E11; деплой во время исполнения — E8.

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
3. **Минимальные компоненты для Content Studio** — раздел F, Phase 3 (сразу после планов
   с черновиком изображения): минимальный клиент Responses API с `input_image`, конфиг на
   фичу, enum ошибок, минимальный технический учёт; общий мониторинг и управление
   расходами — позже, вместе с переводами.
4. **Ограничения** — только `input_text`; `input_image`, файлы, moderation endpoint в коде
   отсутствуют; лимиты запроса 50 элементов / 60 000 символов, пакета 500 / 500 000;
   тест закрепляет ключи тела запроса (`OpenAiTranslationProviderTest.php:84`); движок
   ничего не кеширует и не дедуплицирует платные вызовы.
5. **Отложить** — общий реестр провайдеров и маршрутизация по классификации данных,
   единая таблица учёта и страница статистики, общий ключ `OPENAI_API_KEY`, политика
   повторов/фоллбэка, Batch API, SDK.

### П9. География и история входов

Решение: направление отложено и не входит в MVP Content Studio. Сначала изучаются реальные
данные MaxMind (страны, города, идентификаторы объектов, пригодность для выпадающих
списков, обновления базы, точность и полнота); до этого собственный справочник не
создаётся, географические поля в `users` и `persona_profiles` не добавляются, Phase 1
Personas справочником не блокируется. Поля `City`/`Country` в макете редактора персоны
не реализуются. Точный механизм записи успешных входов (время, пользователь, IP,
предполагаемые страна и город, источник определения) проектируется на этапе GeoIP с
учётом факта, что событие `Login` не во всех ситуациях означает окончательно разрешённую
авторизацию (ниже).

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
реальное время хранить в истории исполнения. Решение имеет приоритет (H1); подпись
макета правится при реализации шага Publication, не в Phase 0.

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
есть в макете, но по решению география отложена, а почасовое расписание активности не
требуется — эти поля не реализуются. Группы авторов `Editorial accounts`/`Users` в
`AuthorPicker` не реализуются: автор — только персона.

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
