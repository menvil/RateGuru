# Food Domain Hardcode Audit

Phase: 43 - Domain Refactor: Generic Rating Platform
Task: RG-662 - Audit Food Domain Hardcodes
Scan date: 2026-06-03

## Scan

Command used:

```bash
rg -n -i "cuisine|dish|dishes|origin|homemade|restaurant|food|meal|recipe" app resources tests database docs
```

Result summary:

- 614 files searched.
- 145 files contained matches.
- 1,638 matched lines were returned.
- Terms found include Cuisine, Origin, Dish, dishes, food, homemade, restaurant, meal, and recipe.

This audit intentionally does not recommend a blind rename. Database columns, model names, enum values, migration names, and older tests are bound to the current legacy schema and should be handled through compatibility wrappers in Phase 43 or replaced by the generic rating schema in Phase 44.

## Classification Rules

- A. Must rename in Phase 43: active public UI/admin copy, visible labels, route-facing browser assertions, and reusable UI component copy.
- B. Keep temporarily as legacy until Phase 44: DB-bound models, enums, actions, services, relationships, factories, and tests that directly exercise legacy storage.
- C. Historical migration, do not touch: migration files and legacy column/table names in migration tests.
- D. Demo/user content, optional: seed/demo titles, tags, comments, screenshots, and sample test data.
- E. Future preset content, not part of Phase 43: project preset or domain preset material. No such config exists in this scan.

## A. Must Rename In Phase 43

These files contain active user-facing or admin-facing wording. Replace visible food-specific copy with generic terms such as Post, Source, Category, Option A, Option B, or Image. No DB migration is required.

| File | Current terms | Risk | Proposed replacement | DB migration required | Target phase | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `resources/views/components/feed/post-card.blade.php` | cuisine, origin, homemade, restaurant | Medium | Category, Source, Option A, Option B | No | 43 | Rename visible labels and displayed vote text. |
| `resources/views/livewire/feed/feed-page.blade.php` | dishes | Low | posts | No | 43 | Rename feed heading/copy. |
| `resources/views/livewire/feed/post-drawer.blade.php` | cuisine, origin, homemade, restaurant | Medium | Category, Source, Option A, Option B | No | 43 | Rename drawer labels. |
| `resources/views/livewire/feed/post-feed.blade.php` | origin, cuisine | Medium | Source, Category | No | 43 | Update labels and component references when wrappers exist. |
| `resources/views/livewire/feed/search-bar.blade.php` | dishes | Low | posts | No | 43 | Rename placeholder/search copy. |
| `resources/views/livewire/feed/sort-dropdown.blade.php` | origin | Low | source | No | 43 | Check visible wording; keep CSS `origin-*` utilities if present. |
| `resources/views/livewire/feed/upload-post-form.blade.php` | dish, food, origin, cuisine, homemade, restaurant | Medium | post, image, Source, Category, Option A, Option B | No | 43 | Rename upload form labels and helper text. |
| `resources/views/livewire/posts/cuisine-voting.blade.php` | Cuisine | Medium | Category | No | 43 | Rename public voting group label. |
| `resources/views/livewire/posts/origin-voting.blade.php` | Origin, Homemade, Restaurant | Medium | Source, Option A, Option B | No | 43 | Rename public voting group and options. |
| `resources/views/livewire/posts/post-show.blade.php` | cuisine, origin, homemade, restaurant | Medium | Category, Source, Option A, Option B | No | 43 | Rename post show labels. |
| `resources/views/livewire/profile/profile-page.blade.php` | dishes, food | Low | posts | No | 43 | Rename profile stats, tabs, and empty states. |
| `resources/views/livewire/reports/report-modal.blade.php` | dish, food | Low | post, content | No | 43 | Rename report modal copy. |
| `resources/views/layouts/app.blade.php` | dishes | Low | posts | No | 43 | Rename shell/nav/search copy if visible. |
| `resources/views/dev/ui-kit.blade.php` | Dish, cuisine, homemade, dish image | Medium | Post, Category, Option A, image | No | 43 | Update reusable component samples after public copy changes. |
| `resources/views/dev/partials/platerate-comments-panel.blade.php` | restaurant, homemade, cuisine | Low | generic sample comments | No | 43 | Neutralize UI kit sample comments. |
| `resources/views/dev/partials/platerate-detail-post.blade.php` | Homemade, restaurant, dish-placeholder | Medium | Source/options and generic image placeholder | No | 43 | Update UI kit reference sample. |
| `resources/views/dev/partials/platerate-feed-tabs.blade.php` | Homemade, Restaurant | Low | Source A, Source B or neutral tabs | No | 43 | Neutralize UI kit tabs. |
| `resources/views/dev/partials/platerate-post-card.blade.php` | Homemade, restaurant, dishLabel, dishPalette, cuisine | Medium | generic post labels and category copy | No | 43 | Update UI kit card sample. |
| `resources/views/dev/partials/platerate-reference-composition.blade.php` | Homemade, restaurant, dishLabel, dishPalette | Medium | generic post labels | No | 43 | Keep composition visually equivalent with neutral copy. |
| `resources/views/dev/partials/platerate-results-panel.blade.php` | Homemade, Restaurant, Cuisine | Low | Source A, Source B, Category | No | 43 | Neutralize result labels. |
| `resources/views/dev/partials/platerate-sidebar.blade.php` | Homemade, Restaurant | Low | neutral categories | No | 43 | Neutralize UI kit sidebar labels. |
| `resources/views/dev/partials/platerate-topbar.blade.php` | dishes | Low | posts | No | 43 | Rename search placeholder. |
| `resources/views/components/ui/image-placeholder.blade.php` | dish-placeholder | Low | post/image placeholder component when safe | No | 43/44 | Visible copy can change in 43; component rename may wait. |
| `app/Providers/AppServiceProvider.php` | Homemade, Restaurant, food-like categories | Medium | neutral demo categories | No | 43 | Rename navigation/demo categories if user-facing. |
| `app/Enums/ReportReason.php` | NotFood | Medium | generic reason label/value if safe | Possible if enum value persisted | 43/44 | Rename visible label in Phase 43; persisted enum value may wait. |

## B. Keep Temporarily As Legacy Until Phase 44

These files are bound to current storage, relationships, events, exceptions, or enums. They should not be mass-renamed in Phase 43. Where Phase 43 needs generic vocabulary, add wrappers or aliases and document legacy names. The target Phase 44 schema is RatingGroup, RatingOption, and RatingVote.

| File | Current terms | Risk | Proposed replacement | DB migration required | Target phase | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `app/Actions/Counters/RecalculatePostCountersAction.php` | origin, homemade, restaurant | High | RatingVote counters | Yes | 44 | Keep legacy counters until rating_votes migration. |
| `app/Actions/Posts/CreatePostAction.php` | OriginType, CuisineType | High | generic metadata/options | Yes | 44 | Keep DB-bound inputs in 43; hide food wording in UI. |
| `app/Actions/Reports/IgnoreReportAction.php` | origin string in report model context | Low | none if CSS/technical meaning | No | 43 | Verify whether match is business wording or technical term. |
| `app/Actions/Reports/ResolveReportAction.php` | origin string in report model context | Low | none if technical | No | 43 | Verify before changing. |
| `app/Actions/Votes/VoteCuisineAction.php` | Cuisine | High | VoteCategoryAction or RatingVote action | Yes | 44 | Keep legacy action; later wrap with Category behavior. |
| `app/Actions/Votes/VoteOriginAction.php` | Origin | High | VoteSourceAction or RatingVote action | Yes | 44 | Keep legacy action; later wrap with Source behavior. |
| `app/Data/Counters/PostCounterSnapshot.php` | homemade, restaurant | High | source option counts | Yes | 44 | Keep legacy counter data object until schema migration. |
| `app/Data/Posts/CreatePostData.php` | origin_truth, cuisine_truth | High | generic initial labels/options | Yes | 44 | Keep fields in 43; UI wording changes only. |
| `app/Enums/CuisineType.php` | CuisineType and cuisine values | High | CategoryOption legacy enum or RatingOption | Yes | 44 | Keep as legacy enum; avoid public labels. |
| `app/Enums/OriginType.php` | OriginType, Homemade, Restaurant | High | SourceOption legacy enum or RatingOption | Yes | 44 | Keep as legacy enum; avoid public labels. |
| `app/Exceptions/Votes/CannotVoteCuisineException.php` | Cuisine | Medium | CannotVoteCategoryException or RatingVote exception | No/Yes depending rename | 44 | Keep legacy exception unless wrapper needs alias. |
| `app/Exceptions/Votes/CannotVoteOriginException.php` | Origin | Medium | CannotVoteSourceException or RatingVote exception | No/Yes depending rename | 44 | Keep legacy exception unless wrapper needs alias. |
| `app/Http/Requests/StorePostRequest.php` | origin_truth, cuisine_truth | High | generic vote metadata request fields | Yes | 44 | Keep request field names until DB changes. |
| `app/Livewire/Feed/FeedPage.php` | origin, cuisine filters | High | source/category public names, legacy backing params | Maybe | 43/44 | Public labels in 43; query params may be deferred. |
| `app/Livewire/Feed/PostDrawer.php` | origin/cuisine listeners or data | Medium | source/category wrappers | No | 43/44 | Rename visible events only if wrappers support compatibility. |
| `app/Livewire/Feed/PostFeed.php` | origin/cuisine filter state and distributions | High | source/category wrapper-facing aliases | Maybe | 43/44 | Keep internals in 43 unless safe aliases are added. |
| `app/Livewire/Feed/UploadPostForm.php` | OriginType, CuisineType, originTruth, cuisineTruth | High | Source/Category labels with legacy storage fields | Yes | 43/44 | Rename visible labels in 43; keep persisted fields. |
| `app/Livewire/Posts/CuisineVoting.php` | CuisineVoting | High | CategoryVoting wrapper | No | 43 | Keep legacy component; add wrapper in RG-674. |
| `app/Livewire/Posts/OriginVoting.php` | OriginVoting | High | SourceVoting wrapper | No | 43 | Keep legacy component; add wrapper in RG-673. |
| `app/Livewire/Posts/PostShow.php` | origin-voted, cuisine-voted events | Medium | source/category events with compatibility | No | 43/44 | Keep legacy events until wrappers define migration path. |
| `app/Models/CuisineVote.php` | CuisineVote | High | RatingVote | Yes | 44 | Keep legacy model until schema migration. |
| `app/Models/OriginVote.php` | OriginVote | High | RatingVote | Yes | 44 | Keep legacy model until schema migration. |
| `app/Models/Post.php` | origin_truth, cuisine_truth, originVotes, cuisineVotes | High | rating relationships | Yes | 44 | Keep legacy relationships until Phase 44. |
| `app/Queries/Feed/FeedQuery.php` | origin/cuisine filters | High | source/category facade over legacy filters | Maybe | 43/44 | UI labels in 43; route/query names can be revisited. |
| `app/Services/PostVoteResultService.php` | origin/cuisine distribution, homemade/restaurant counters | High | source/category distribution or rating distribution | Yes | 43/44 | Public labels in 43; final replacement in Phase 44. |
| `app/View/Components/Feed/PostCard.php` | origin/cuisine distributions | Medium | source/category view props when safe | No | 43/44 | Rename public props only if view impact is controlled. |
| `app/View/Components/Ui/BinaryChoice.php` | homemade/restaurant selected values | Medium | source option selected values | No/Maybe | 43/44 | Visible labels in 43; values can remain legacy. |
| `app/View/Components/Ui/DishPlaceholder.php` | DishPlaceholder | Medium | PostImagePlaceholder | No | 43/44 | Rename component only if usage update is safe; visible labels first. |
| `database/factories/CuisineVoteFactory.php` | CuisineVote, CuisineType | High | RatingVote factory | Yes | 44 | Keep legacy factory. |
| `database/factories/OriginVoteFactory.php` | OriginVote, OriginType | High | RatingVote factory | Yes | 44 | Keep legacy factory. |
| `database/factories/PostFactory.php` | origin_truth, cuisine_truth, counters | High | generic defaults | Yes | 44 | Keep schema fields; neutral default content only. |

## C. Historical Migration, Do Not Touch

These files define or test historical schema. They should not be renamed in Phase 43, because Phase 44 will replace the legacy vote schema directly with generic rating tables. Do not introduce `source_votes` or `category_votes` as intermediate tables.

| File | Current terms | Risk | Proposed replacement | DB migration required | Target phase | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `database/migrations/2026_05_14_183710_create_posts_table.php` | origin_truth, cuisine_truth, homemade/restaurant counters | High | rating metadata/counters | Yes | 44 | Leave historical migration unchanged. |
| `database/migrations/2026_05_14_183852_create_cuisine_votes_table.php` | cuisine_votes | High | rating_votes | Yes | 44 | Leave historical migration unchanged. |
| `database/migrations/2026_05_14_183852_create_origin_votes_table.php` | origin_votes | High | rating_votes | Yes | 44 | Leave historical migration unchanged. |
| `database/migrations/2026_05_14_185608_add_unique_index_to_cuisine_votes_table.php` | cuisine_votes | High | rating_votes constraints | Yes | 44 | Leave historical migration unchanged. |
| `database/migrations/2026_05_14_185608_add_unique_index_to_origin_votes_table.php` | origin_votes | High | rating_votes constraints | Yes | 44 | Leave historical migration unchanged. |
| `tests/Feature/Database/CuisineVotesTableTest.php` | cuisine_votes | High | rating_votes schema tests | Yes | 44 | Keep until schema changes. |
| `tests/Feature/Database/OriginVotesTableTest.php` | origin_votes | High | rating_votes schema tests | Yes | 44 | Keep until schema changes. |
| `tests/Feature/Database/PostVotesTableTest.php` | origin in index naming or data | Medium | none until schema migration | Yes | 44 | Keep legacy schema assertions. |
| `tests/Feature/Database/PostsTableTest.php` | origin_truth, cuisine_truth, counters | High | generic metadata columns | Yes | 44 | Keep legacy schema assertions. |

## D. Demo, Test, And Documentation Content

These files contain demo content, historical design notes, browser assertions, sample data, or test descriptions. They do not all require immediate logic changes, but Phase 43 follow-up tasks should neutralize public copy and test wording so tests no longer reinforce the food domain.

| File | Current terms | Risk | Proposed replacement | DB migration required | Target phase | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `database/seeders/DemoHiddenPostsSeeder.php` | restaurant, homemade, cuisine tags | Low | generic sample posts/tags | No | 43 | Neutralize in RG-676. |
| `database/seeders/DemoPendingPostsSeeder.php` | homemade, restaurant, cuisine tags | Low | generic sample posts/tags | No | 43 | Neutralize in RG-676. |
| `database/seeders/DemoPublishedPostsSeeder.php` | pasta, sushi, tacos, homemade, restaurant, cuisine tags | Low | sample post titles and generic tags | No | 43 | Neutralize in RG-676. |
| `database/seeders/DemoReportsSeeder.php` | restaurant sushi title lookup | Medium | lookup generic demo title after seed rename | No | 43 | Update with seed changes. |
| `database/seeders/DemoTagsSeeder.php` | food/cuisine tags | Low | generic tags | No | 43 | Neutralize in RG-676. |
| `database/seeders/DemoVotesSeeder.php` | OriginType, CuisineType demo votes | Medium | keep enum values, neutral comments | No | 43/44 | Keep storage values; avoid public labels. |
| `docs/dev/seed-data.md` | origin votes, cuisine votes | Low | legacy storage note or source/category copy | No | 43/44 | Update docs after compatibility note. |
| `docs/design/design-contract.md` | dish/food/cuisine/origin prototype language | Medium | generic platform wording with historical notes | No | 43 | Update design contract carefully. |
| `docs/design/phase-8-feed-ui-review.md` | dishes, cuisine/origin | Low | historical or generic wording | No | 43 | Historical docs can keep context; new docs should be generic. |
| `docs/design/phase-10-upload-ui-review.md` | upload dish, cuisine/origin | Low | historical or generic wording | No | 43 | Historical docs can keep context. |
| `docs/design/phase-11-drawer-ui-review.md` | homemade/restaurant | Low | historical or generic wording | No | 43 | Historical docs can keep context. |
| `docs/design/phase-18-comments-ui-review.md` | PlateRate original path | Low | none | No | 43 | Path reference only; no change required. |
| `docs/design/phase-37-ui-polish-review.md` | food-specific labels | Low | generic labels in future docs | No | 43 | Update if it describes current UI. |
| `docs/design/ui-review-checklist.md` | original prototype / PlateRate | Low | no change unless current UI copy stated | No | 43 | Keep prototype reference requirement. |
| `docs/design/visual-baselines.md` | dish placeholder/original prototype | Low | generic placeholder plus historical note | No | 43/44 | Update if current baseline labels change. |
| `docs/design/reference/original/PlateRate.html` | Rate the dish, food prototype copy | Low | none | No | none | Original prototype reference; keep unchanged. |
| `resources/css/theme.css` | food-like token/comment if any | Low | neutral comment/token names | No | 43 | Verify before editing. |
| `resources/views/components/dropdown.blade.php` | origin-top CSS utility | None | no replacement | No | none | Technical CSS term; do not rename. |
| `resources/views/components/ui/dropdown.blade.php` | origin-top CSS utility | None | no replacement | No | none | Technical CSS term; do not rename. |
| `tests/Browser/VotingBrowserTest.php` | Cuisine/Origin/Homemade/Restaurant assertions | Medium | Category/Source/Option A/Option B | No | 43 | Update in RG-678. |
| `tests/Feature/Actions/AddCommentActionHotScoreTest.php` | demo food title/comment | Low | generic post title/comment | No | 43 | Neutralize test content. |
| `tests/Feature/Actions/CreatePostActionTest.php` | OriginType/CuisineType | Medium | keep legacy values, generic descriptions | No | 43/44 | Rename test descriptions where safe. |
| `tests/Feature/Actions/IgnoreReportActionTest.php` | food/report data | Low | generic report data | No | 43 | Neutralize descriptions/copy. |
| `tests/Feature/Actions/RecalculateCommentCountersActionTest.php` | possible origin technical term | Low | no change if technical | No | 43 | Verify before editing. |
| `tests/Feature/Actions/RecalculatePostCountersActionTest.php` | homemade/restaurant counters | High | source option counters | Yes | 44 | Keep logic; rename descriptions only if safe. |
| `tests/Feature/Actions/ResolveReportActionTest.php` | food/report data | Low | generic report data | No | 43 | Neutralize copy. |
| `tests/Feature/Actions/VoteCacheInvalidationPlaceholderTest.php` | Cuisine/Origin actions | Medium | Category/Source behavior | No | 43 | Rename descriptions after wrappers. |
| `tests/Feature/Actions/VoteCuisineActionTest.php` | cuisine behavior | High | category option behavior | No/Yes | 43/44 | Rename descriptions; keep legacy action. |
| `tests/Feature/Actions/VoteOriginActionTest.php` | origin behavior | High | source option behavior | No/Yes | 43/44 | Rename descriptions; keep legacy action. |
| `tests/Feature/Actions/VoteRateLimitTest.php` | cuisine/origin | Medium | category/source voting | No | 43 | Rename descriptions. |
| `tests/Feature/ApplicationShellTest.php` | dishes search/navigation | Low | posts | No | 43 | Update shell assertions. |
| `tests/Feature/Console/RecalculatePostCountersCommandTest.php` | homemade/restaurant counters | High | source counters after schema | Yes | 44 | Keep behavior; rename descriptions if safe. |
| `tests/Feature/DevUiKitRouteTest.php` | dish/ui kit labels | Low | generic labels | No | 43 | Update with UI kit. |
| `tests/Feature/Docs/DomainDocsTest.php` | Cuisine, Origin, Dish audit assertions | Low | keep; this is compatibility documentation | No | 43 | New audit test. |
| `tests/Feature/Factories/CuisineVoteFactoryTest.php` | CuisineVote | High | RatingVote factory | Yes | 44 | Keep until schema migration. |
| `tests/Feature/Factories/OriginVoteFactoryTest.php` | OriginVote | High | RatingVote factory | Yes | 44 | Keep until schema migration. |
| `tests/Feature/Filament/PostResourceTest.php` | Homemade Pasta sample | Low | generic post sample | No | 43 | Neutralize sample title. |
| `tests/Feature/Filament/TagResourceFormTest.php` | Mexican Food, Street Food, Pasta Dishes | Low | generic tag examples | No | 43 | Neutralize sample data. |
| `tests/Feature/Filament/TagResourceTest.php` | Asian Food | Low | generic tag example | No | 43 | Neutralize sample data. |
| `tests/Feature/Filament/UserResourceTest.php` | original_username | None | no replacement | No | none | Technical string false positive. |
| `tests/Feature/Livewire/CuisineVotingTest.php` | CuisineVoting, Italian/Asian/etc. | High | CategoryVoting wrapper tests and generic descriptions | No | 43/44 | Rename descriptions; keep legacy component until wrapper. |
| `tests/Feature/Livewire/FeedPageQueryStringTest.php` | origin/cuisine query params | Medium | source/category public params if changed | Maybe | 43/44 | Keep until query strategy changes. |
| `tests/Feature/Livewire/FeedPageTest.php` | dishes, cuisine/origin copy | Medium | posts, Category/Source | No | 43 | Update with feed copy. |
| `tests/Feature/Livewire/OriginVotingTest.php` | OriginVoting, Homemade/Restaurant | High | SourceVoting wrapper tests and generic descriptions | No | 43/44 | Rename descriptions; keep legacy component until wrapper. |
| `tests/Feature/Livewire/PostDrawerTest.php` | food/voting labels | Medium | generic post labels | No | 43 | Update with drawer copy. |
| `tests/Feature/Livewire/PostFeedTest.php` | origin/cuisine filters | Medium | source/category labels | Maybe | 43/44 | Update visible assertions only. |
| `tests/Feature/Livewire/PostShowTest.php` | origin/cuisine visible labels | Medium | Source/Category | No | 43 | Update with post show copy. |
| `tests/Feature/Livewire/ProfilePageTest.php` | dishes/food profile copy | Low | posts | No | 43 | Update with profile copy. |
| `tests/Feature/Livewire/ReportModalTest.php` | dish/food report copy | Low | post/content | No | 43 | Update with report copy. |
| `tests/Feature/Livewire/UploadPostFormTest.php` | dish/food/upload labels | Medium | post/image/upload labels | No | 43 | Update with upload copy. |
| `tests/Feature/Notifications/PostApprovedNotificationTest.php` | Approved dish sample | Low | Approved post sample | No | 43 | Neutralize sample data/copy. |
| `tests/Feature/Notifications/PostCommentedNotificationTest.php` | dish/comment sample if present | Low | post/comment sample | No | 43 | Neutralize sample data/copy. |
| `tests/Feature/PostOpenGraphTest.php` | food placeholder/copy | Low | post image/placeholder | No | 43 | Update SEO copy if visible. |
| `tests/Feature/Queries/FeedQueryPerformanceTest.php` | origin/cuisine filters | Medium | source/category aliases or legacy storage | Maybe | 43/44 | Keep storage names until query migration. |
| `tests/Feature/Queries/FeedQueryTest.php` | origin/cuisine filters | Medium | source/category descriptions | Maybe | 43/44 | Rename descriptions where safe. |
| `tests/Feature/Relationships/CommentPostRelationshipTest.php` | origin false positive or sample content | Low | verify | No | 43 | Update only if user-facing. |
| `tests/Feature/Relationships/CommentUserRelationshipTest.php` | origin false positive or sample content | Low | verify | No | 43 | Update only if user-facing. |
| `tests/Feature/Relationships/PostCuisineVotesRelationshipTest.php` | cuisineVotes relationship | High | ratingVotes relationship | Yes | 44 | Keep until schema migration. |
| `tests/Feature/Relationships/PostOriginVotesRelationshipTest.php` | originVotes relationship | High | ratingVotes relationship | Yes | 44 | Keep until schema migration. |
| `tests/Feature/Relationships/PostPostVotesRelationshipTest.php` | origin false positive or sample content | Low | verify | No | 43 | Update only if user-facing. |
| `tests/Feature/Relationships/UserPostsRelationshipTest.php` | origin false positive or sample content | Low | verify | No | 43 | Update only if user-facing. |
| `tests/Feature/Requests/StorePostRequestTest.php` | origin_truth, cuisine_truth | High | generic request fields | Yes | 44 | Keep until upload schema changes. |
| `tests/Feature/Routes/FeedRouteTest.php` | dishes copy | Low | posts | No | 43 | Update with feed copy. |
| `tests/Feature/Routes/PostShowRouteTest.php` | origin/cuisine labels | Medium | Source/Category | No | 43 | Update with post show copy. |
| `tests/Feature/Seeders/DemoTagsSeederTest.php` | food tag content | Low | generic tags | No | 43 | Update with seed neutralization. |
| `tests/Feature/Seeders/DemoVotesSeederTest.php` | Origin/Cuisine demo votes | Medium | keep legacy storage; generic descriptions | No | 43/44 | Rename descriptions where safe. |
| `tests/Feature/Services/LocalImageStorageTest.php` | dish.jpg | Low | post.jpg | No | 43 | Neutralize filename. |
| `tests/Feature/ViewComponents/BinaryChoiceComponentTest.php` | Homemade/Restaurant | Medium | Option A/Option B | No | 43 | Update component labels when component changes. |
| `tests/Feature/ViewComponents/PostCardComponentTest.php` | cuisine/origin visible copy | Medium | Category/Source | No | 43 | Update card assertions. |
| `tests/Unit/Actions/VoteCuisineActionSkeletonTest.php` | VoteCuisineAction | Medium | wrapper or RatingVote skeleton later | No/Yes | 44 | Keep until action replacement. |
| `tests/Unit/Actions/VoteOriginActionSkeletonTest.php` | VoteOriginAction | Medium | wrapper or RatingVote skeleton later | No/Yes | 44 | Keep until action replacement. |
| `tests/Unit/Data/CreatePostDataTest.php` | origin/cuisine fields | High | generic fields | Yes | 44 | Keep until schema changes. |
| `tests/Unit/Enums/CuisineTypeTest.php` | CuisineType | High | RatingOption | Yes | 44 | Keep until schema changes. |
| `tests/Unit/Enums/OriginTypeTest.php` | OriginType | High | RatingOption | Yes | 44 | Keep until schema changes. |
| `tests/Unit/Enums/ReportReasonTest.php` | not_food | Medium | generic report reason | Maybe | 43/44 | Rename visible label first; persisted value may wait. |
| `tests/Unit/Models/CuisineVoteModelTest.php` | CuisineVote | High | RatingVote | Yes | 44 | Keep until schema changes. |
| `tests/Unit/Models/OriginVoteModelTest.php` | OriginVote | High | RatingVote | Yes | 44 | Keep until schema changes. |

## E. Future Preset Content

No `config/project_presets.php`, admin preset editor, or explicit food/cats/generic preset branch was found. Phase 43 should not introduce preset branching. Phase 45 can add presets after Phase 44 provides generic rating groups/options.

## Immediate Phase 43 Plan

1. Add vocabulary and compatibility docs so legacy storage has a documented boundary.
2. Rename public UI copy first: feed, post show/drawer, upload, profile, notifications, reports, moderation, and UI kit samples.
3. Change public voting labels from Origin/Cuisine to Source/Category while keeping legacy storage untouched.
4. Add SourceVoting and CategoryVoting wrappers over OriginVoting and CuisineVoting behavior.
5. Replace public component usage with wrappers where safe.
6. Neutralize demo seed and factory content without changing enum/table values.
7. Add a forbidden words guardrail for active UI/application code with a minimal allowlist for legacy storage.

## Explicit Non-Actions For Phase 43

- Do not rename `origin_votes` or `cuisine_votes`.
- Do not rename `origin_truth` or `cuisine_truth` columns.
- Do not migrate `OriginVote` or `CuisineVote` to `RatingVote`.
- Do not add `rating_groups`, `rating_options`, or `rating_votes`.
- Do not add source/category intermediate migrations.
- Do not replace the original PlateRate prototype reference file.
