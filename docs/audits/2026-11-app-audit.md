# App audit, November 2026

An audit of the application code as it was on `develop` at `85553ca2`. Once
its fixes are merged, `develop` is tagged `audit-2026-11` there, and the next
audit reviews what changed since that tag.

## Scope

- **Reviewed:** `app/`, the views and their scripts, routes, config and bootstrap, the migrations, seeders and factories, and the repository's own tooling under `tools/`.
  - 1,219 files, 111,396 lines.
- **Not reviewed:** tests, translations, docs and CSS.
- **Reviewed separately:** `infrastructure/`, in the infrastructure audit.

## Method

- **Batches:** the code went through 19 review-only pull requests, one batch each.
  - Each pull request's base was `develop` with the batch's files removed, so every file showed as added in full.
  - CodeRabbit and cubic reviewed each batch as existing production code. The batches were closed unmerged.
- **Triage:**
  - Every bot finding, and CodeRabbit's full list beyond its inline comments, was checked against the code of the snapshot, its callers, tests and docs, and merged with its duplicates within its batch.
  - Each got one verdict:
    - **BUG:** a real defect with a concrete failure.
    - **MINOR:** correct but weak, worth a cheap fix.
    - **INVALID:** the claim is false, handled elsewhere, or against documented intent.
    - **SKIP:** style, or a change worth less than it costs.
- **Fixes:**
  - The findings worth fixing went into themed pull requests, each change with a regression test.
  - Every new test was checked to fail without its change.

## Results

- **Findings and verdicts:** 956 findings after merging duplicates within each batch.
  - A few defects were found again in a later batch and counted there too, such as the tag check that missed author-deleted posts and the comment status default. Each is fixed once, by the pull request that lists it first.
  - 43 bugs and 283 minor issues.
  - 167 claims were invalid and 463 were skipped.
  - Roughly two findings in three needed no change, about the share the bots' earlier reviews of this repository had.
- **Fixes:** 320 of the 326 bugs and minor issues are fixed in the pull requests below.
  - One, the lock order of member votes and comments, gets a change of its own.
  - Five were reconsidered against the running code and left as they are, for the reasons given below.
- **Bugs that mattered most:**
  - **Access and privacy:**
    - a URL import that a percent-encoded host could steer past its pinned address;
    - SQL bindings — user input among them — sent to Nightwatch, Sentry and the logs;
    - comments of a hidden post readable through an unlocked Livewire property.
  - **Accounts and sessions:**
    - an email-verification race;
    - sessions surviving a password change;
    - a login email matched case-sensitively.
  - **Feed and pages:**
    - a hot score that never decayed;
    - post cards on profiles and saved posts that opened nothing with the overlay mode off.
- **Tooling:** the architecture rules missed common forms of what they forbid, among them nullable receivers and static calls. None of those forms was in `app/`, and PHPStan stayed at 0 errors once the rules caught them.

## Left for a change of its own

- **Lock order of member votes and comments** (`LocksActorForWrite`). A post vote or comment can deadlock with an operation that locks the same two users the other way round. The fix that orders those locks starts a new deadlock with Content Studio's executor.
  - The clean fix is one post update per transaction, or notification recipients locked without their key.
  - Both change the executor's documented lock reasoning, so they need a change of their own.

## Decided after the review

- **`IMPORT_FROM_URL_ENABLED` is dropped**, in the config change. It fed `import.enabled`, which no code read; the project flag `allow_url_imports` is what switches URL import on and off. Enforcing it instead would have switched the import off on every target whose environment ships it `false`.
  - A target's environment file may keep the key: the deploy's contract check reports an extra key and lets it pass.
- **The inert `wire:lazy` is dropped.** Livewire 4.4 reads only `lazy` and `defer`, so the feed drawers and the report and moderation menus rendered with the page anyway. Real lazy loading changes how a selection scrolls, and is left until it is wanted.

## Reconsidered and left as they are

- **The moderation profile's status decision:** the mismatched decision can never be shown.
- **An admin textarea's counter after a suggestion:** Livewire's redraw writes the new count.
- **The Translation AI model combobox's focus:** Livewire gives the focus back to the new trigger.
- **The admin sidebar's new-tab links:** no navigation item opens in a new tab.
- **The post votes editor before a cleared author is saved:** the label stays true of the saved plan.

## Numbers

| Verdict | Count |
|---|---|
| BUG | 43 |
| MINOR | 283 |
| INVALID | 167 |
| SKIP | 463 |
| **Total** | **956** |

### The review batches

| Batch | Review PR | Files | Lines | Bugs | Minors |
|---|---|---|---|---|---|
| 01 | #1361 | 50 | 1919 | 5 | 7 |
| 02 | #1362 | 87 | 7161 | 6 | 18 |
| 03 | #1363 | 48 | 5886 | 1 | 7 |
| 04 | #1364 | 46 | 4884 | 6 | 23 |
| 05 | #1365 | 92 | 6899 | 3 | 15 |
| 06 | #1366 | 95 | 6902 | 1 | 9 |
| 07 | #1367 | 12 | 6948 | 1 | 13 |
| 08 | #1368 | 24 | 7087 | 1 | 11 |
| 09 | #1369 | 29 | 7016 | 2 | 11 |
| 10 | #1370 | 63 | 7188 | 0 | 8 |
| 11 | #1371 | 83 | 7176 | 2 | 9 |
| 12 | #1372 | 100 | 4076 | 0 | 5 |
| 13 | #1373 | 37 | 1580 | 0 | 3 |
| 14 | #1374 | 100 | 5884 | 0 | 20 |
| 15 | #1375 | 78 | 7150 | 1 | 18 |
| 16 | #1376 | 49 | 7131 | 4 | 28 |
| 17 | #1377 | 74 | 7187 | 9 | 42 |
| 18 | #1378 | 99 | 5349 | 1 | 13 |
| 19 | #1379 | 53 | 3973 | 0 | 23 |

### Findings by fix pull request

#### security-and-privacy — #1380

- `05-01` bug, `app/Support/Import/UrlImportValidator.php` — normalizeHost() only lowercases and strips a trailing dot, with no hostname-character check, so hosts containing %, \ or spaces reach DNS and the transport.
- `05-02` bug, `app/Support/Observability/NightwatchPrivacy.php` — redactException only rewrites the 'Key (...)=(...)' / 'Duplicate entry' fragments, but a QueryException message also carries ' (Connection: ..., SQL: ...)' with the bindings substituted, so emails, names and password hashes still reach Nightwatch.
- `04-01` bug, `app/Livewire/Comments/CommentsSection.php` — CommentsSection's public $postId is client-writable and CommentListQuery never checks the parent post, so the comment thread of a hidden, author-deleted or otherwise non-public post can be read.
- `10-14` minor, `app/Support/TranslationEngine/Providers/OpenAiTranslationProvider.php` — All four OpenAI clients accept an http:// base URL, so the bearer API key (and the prompt) would be sent in cleartext if one is configured.
- `06-08` minor, `app/Services/Media/Exceptions/ImageDecodeException.php` — couldNotReadUploadedFile() embeds the client-supplied original filename in the exception message, which is reported to observability.
- `02-41` bug, `app/Actions/Import/StoreImportedImageAction.php` — download() bypasses the allow_url_imports feature gate, so remote URLs are still fetched after imports are disabled.
- `04-02` bug, `app/Livewire/Feed/UploadPostForm.php` — submit() downloads the client-settable importedImageUrl without checking allow_url_imports, and before the create ability, the upload rate limit and field validation run.
- `09-02` bug, `app/Filament/Resources/StudioPlans/Concerns/EditsStudioPlan.php` — The Studio editor's direct-image import downloads the link without checking the allow_url_imports switch, while its social-post import honours it.
- `12-02` minor, `app/Exceptions/Import/UnsafeImportUrlException.php` — invalidUrl() embeds the full raw URL in the exception message, so query tokens or other URL contents reach logs/Sentry wherever the exception is reported.
- `12-03` minor, `app/Exceptions/Import/ImportFetchException.php` — sanitizeUrl() redacts only top-level query keys containing token/key/auth/password/secret, so signed-URL credentials (X-Amz-Signature, sig, hm) and nested keys (a[token]) survive into exception messages.
- `04-07` minor, `app/Livewire/Comments/CommentsSection.php` — visibleCount is client-writable and CommentListQuery applies it as an uncapped limit, so one request can load a post's entire comment tree.
- `05-04` minor, `app/Support/Observability/NightwatchPrivacy.php` — The Key/Duplicate-entry redaction patterns stop at the first ')' or "'" inside the value, so part of an email or username can still leak.
- `05-14` minor, `app/Support/Import/Net/PublicIpClassifier.php` — The IPv6 blocklist misses 6to4 (2002::/16), Teredo (2001::/32), local-use NAT64 (64:ff9b:1::/48) and deprecated site-local (fec0::/10), so addresses embedding or reaching private space are classified public.
- `05-11` minor, `app/Support/AbuseGuards/ActionRateLimiter.php` — hitOrFail() checks tooManyAttempts and then hits in two separate cache operations, so concurrent requests can all pass the check and exceed the limit (plus: add a concurrent-admission test).
- `03-11` minor, `app/Actions/Studio/Concerns/SettlesStudioPlan.php` — settlePlan's open-action check is a plain read; under REPEATABLE READ it uses a snapshot taken before the plan lock wait, so two concurrent final actions can both see the other open and leave the plan Running forever.
- `17-02` bug, `config/sentry.php` — Sentry cache spans and cache breadcrumbs transmit raw cache keys, and the login throttle key contains the email address and client IP.
- `17-14` minor, `config/nightwatch.php` — NIGHTWATCH_REDACT_HEADERS replaces the privacy-critical header list wholesale, and a blank value yields [''] so nothing is redacted.
- `17-15` minor, `config/nightwatch.php` — $nightwatchBool maps any unrecognised value to false, so a typo in NIGHTWATCH_IGNORE_MAIL/NOTIFICATIONS silently turns off a privacy default.
- `17-20` minor, `config/sentry.php` — Sentry HTTP client breadcrumbs (and spans) include the outgoing request query string, which can carry tokens.

#### authentication — #1381

- `01-01` bug, `app/Actions/Auth/VerifyEmailAction.php` — Email verification checks the link's email hash against the request-start user instance but marks the locked current row verified, so an email changed in between gets verified without proof.
- `02-12` bug, `app/Actions/Auth/VerifyEmailAction.php` — Verification can confirm a newly changed email with a link signed for the previous address during a concurrent profile update.
- `01-02` bug, `app/Actions/Auth/UpdatePasswordAction.php` — Changing the password leaves every other session and the remember-me token valid, so a stolen session survives the owner's password change.
- `01-03` bug, `app/Http/Requests/Auth/LoginRequest.php` — Login does not normalize the email, so on PostgreSQL a differently-cased address never matches the stored (lowercase) email.
- `11-11` bug, `app/Console/Commands/CreateAdminUserCommand.php` — rateguru:admin:create trims the email but does not lowercase it, so the admin row can hold a mixed-case address that lowercase login does not match.
- `01-07` minor, `routes/auth.php` — POST confirm-password (and the password-update check) has no attempt limit, so an authenticated session can guess the account password without limit.
- `02-05` minor, `app/Actions/Auth/ConfirmPasswordAction.php` — Password confirmation has no attempt limit, so a session holder can guess the account password without limit.
- `02-01` minor, `app/Actions/Auth/AuthenticateUserAction.php` — The login lockout check and the failure hit are separate steps around bcrypt, so parallel requests all pass tooManyAttempts and the 5-attempt limit does not hold under concurrency.
- `02-03` minor, `app/Actions/Auth/ClaimAccountWithVerifiedEmailAction.php` — The admin panel middleware lacks EnsureSessionGenerationIsCurrent, so a claim that starts a new session generation does not end panel sessions of a password-less account under a non-database session store.
- `01-04` bug, `app/Http/Controllers/ProfileController.php` — ProfileController::update does not handle CannotUpdateProfileException, so a refused update returns a 500.
- `02-55` minor, `app/Actions/Profile/AnonymizeUserAccountAction.php` — Every account deletion scans and JSON-decodes the whole notifications table inside its transaction; filter data->author_id / data->actor_id in the database first.
- `05-10` minor, `app/Support/Profile/ConnectedAccountsResponse.php` — When no provider is listed, the Connected accounts card is not rendered, so a connected-accounts status or error flashed by ConnectedAccountsResponse is never shown.
- `17-10` minor, `routes/auth.php` — POST /register has no rate limit, so one client can create accounts, and trigger verification mail, without bound.
- `17-11` minor, `app/Http/Requests/ProfileUpdateRequest.php` — The account email can be changed without the current password or confirmation from the old mailbox.
- `17-17` minor, `bootstrap/app.php` — EnsureSessionGenerationIsCurrent is only on the web group, so Filament panel pages never run the session-generation guard.
- `17-24` minor, `routes/auth.php` — POST confirm-password and PUT password check the current password with no attempt limit, so a session holder can guess it without limit.
- `17-25` minor, `routes/auth.php` — POST /forgot-password has no per-client throttle. Only the broker's per-email throttle applies.
- `18-08` minor, `database/migrations/2026_09_29_100000_add_session_generation_to_users_table.php` — The admin panel's middleware stack does not run EnsureSessionGenerationIsCurrent, so a stale session survives an account claim there.
- `17-21` minor, `resources/views/profile/edit.blade.php` — Hiding the Connected accounts card when providers() is empty also hides its success and error flash messages.

#### authorization — #1382

- `01-05` bug, `app/Policies/ProjectSettingsPolicy.php` — manage-project-settings checks only the role, so a Limited/Banned/Shadowbanned admin still passes it.
- `01-06` minor, `app/Policies/RatingGroupPolicy.php` — RatingGroupPolicy, RatingOptionPolicy, TagPolicy::delete (and MediaDiagnosticsPolicy) check isAdmin() only, so a sanctioned admin keeps those abilities, and CategoryPolicy's docblock wrongly says every privileged policy checks lifecycle.
- `03-02` minor, `app/Policies/TagPolicy.php` — TagPolicy::delete checks only isAdmin(), so a sanctioned (Limited/Banned/Shadowbanned) administrator keeps the ability to delete tags; the check also runs outside the transaction.
- `09-03` minor, `app/Policies/TagPolicy.php` — Tag deletion checks only isAdmin(), so a sanctioned administrator still passes TagPolicy::delete.
- `02-73` minor, `app/Actions/Reports/ReportContentAction.php` — Self-reporting is blocked only in the UI for comments and user profiles; the action refuses it only for posts.
- `04-20` minor, `app/Livewire/Reports/ReportModal.php` — The user branch of resolveReportable() lets a member report their own account through a crafted request.

#### stale-admin-saves — #1394

- `07-01` bug, `app/Livewire/Admin/UserEditor.php` — UserEditor's save writes name, username, email and role from the draft without checking they are still what the drawer was opened with, so a stale drawer silently overwrites changes made meanwhile.
- `08-01` bug, `app/Filament/Pages/ProjectSettingsPage.php` — Project settings are saved as a full snapshot of the draft taken when the page loaded, so a stale tab silently reverts settings another administrator changed since, including switched-off sign-in providers and safety flags.
- `02-08` minor, `app/Actions/Auth/RegisterUserAction.php` — A concurrent or double-submitted registration with the same email escapes as a 500 instead of a field error.
- `01-11` minor, `app/Actions/Profile/UpdateUserIdentityAction.php` — The unique username/email checks run before the write, so a concurrent request taking the same value ends in a unique-constraint QueryException (500).
- `03-05` minor, `app/Actions/Users/UpdateUserAccountAction.php` — A username/email claimed by another account after UserEditor's unique validation makes $locked->save() throw a unique-constraint exception that surfaces as a 500.
- `07-02` minor, `app/Livewire/Admin/UserEditor.php` — The username/email unique checks are only a preflight, so a value claimed concurrently makes the save throw an uncaught unique-constraint exception (500) instead of a field error.
- `07-03` minor, `app/Livewire/Admin/UserEditor.php` — After Mark trusted from the drawer, the trust selector and its saved baseline still say Not trusted.
- `07-05` minor, `app/Livewire/Admin/GlobalSearch.php` — strip_tags() is applied to plain-string search titles, mangling literal text such as '<b>' that Blade would escape anyway.
- `15-11` minor, `app/Filament/Resources/Comments/Pages/ListComments.php` — A concurrent hide makes the confirmation report a false success and offer Undo for another moderator's action.
- `15-12` minor, `app/Filament/Pages/LanguagesPage.php` — Concurrent enable/disable requests overwrite each other because the full enabled-locale list is computed before the settings-row lock.
- `16-02` bug, `app/Actions/Users/UpdateUserAccountAction.php` — The user editor submits its whole draft and UpdateUserAccountAction writes every field, so a stale editor silently overwrites changes made by another administrator meanwhile.
- `16-27` minor, `app/Livewire/Admin/UserEditor.php` — After Mark trusted is confirmed in the editor, the trust radio and its baseline still say 'Not trusted', so choosing Not trusted registers no change.

#### feed-and-ranking — #1383

- `02-64` bug, `app/Actions/Ranking/RecalculatePostScoreAction.php` — Inactive posts' hot scores never decay with age, because hot_score is only recalculated on activity.
- `05-03` bug, `app/Support/Ranking/HotScoreCalculator.php` — The Hot feed orders by a stored hot_score computed at the last activity, so scores never decay for posts without new votes/comments.
- `02-63` minor, `app/Actions/Ranking/RecalculatePostScoreAction.php` — RecalculatePostScoreAction refreshes and saves without locking the post, so the standalone hot-score command can overwrite a concurrent vote's newer score with one computed from stale counters.
- `11-07` minor, `app/Console/Commands/RecalculateHotScoresCommand.php` — The hot-score sweep can overwrite a concurrent vote's newer score with one calculated from stale counters.
- `02-24` bug, `app/Actions/Comments/DeleteCommentAction.php` — Deleting, hiding or restoring a comment updates comments_count but leaves hot_score computed from the old count.
- `04-05` bug, `app/Livewire/Feed/PostDrawer.php` — Deleting a post from the drawer never emits post-deleted, so the list behind it keeps showing the deleted card.
- `06-03` minor, `app/Services/Feed/FeedPostDeletionService.php` — The pre-lookup Post::query()->find() excludes soft-deleted posts, so deleting a stale card of an already-deleted post returns skipped and the card stays.
- `04-17` minor, `app/Livewire/Feed/FeedPage.php` — A failed delete from a feed card sets $deleteError, which nothing renders, so the failure is silent.
- `04-18` minor, `app/Livewire/Feed/PostDrawer.php` — A failed deletion message persists when the overlay drawer shows another post.
- `04-22` minor, `app/Livewire/Feed/FeedPage.php` — effectiveSearch() uses strlen, so the 3-character minimum is measured in bytes and not enforced for Cyrillic input.

#### feature-flags — #1388

- `02-32` bug, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php` — Follower notifications are still sent after show_follow_buttons is disabled.
- `04-04` bug, `app/Livewire/SavedPosts/SavedPostsPage.php` — The /saved page and the profile's Saved tab ignore the show_saved_posts flag, so saved posts stay reachable after the feature is switched off.
- `04-06` bug, `app/Livewire/Notifications/NotificationBell.php` — The bell lists only the 10 latest notifications while the badge counts every unread one, so older unread notifications can never be reached or marked read.
- `02-38` minor, `app/Actions/Follows/ToggleFollowAuthorAction.php` — The toggle's own flag guard throws before FollowAuthorAction runs, so the security.feature_disabled_action_attempted event is never emitted on the only path the UI uses.
- `05-09` minor, `app/Support/Profile/ProfileStats.php` — The owner's saved count includes saves of posts that are no longer public, while the Saved tab (SavedPostsQuery) lists only published, non-deleted posts.
- `04-25` minor, `app/Livewire/SavedPosts/SavedPostsPage.php` — An out-of-range page on /saved shows the 'no saved posts' empty state with no pagination links.
- `04-27` minor, `app/Queries/UserRatingActivityQuery.php` — The rating-activity list orders only by created_at, so tied votes at the 30-row cut can change between requests.
- `15-01` bug, `app/Livewire/Feed/UploadPostForm.php` — Switching off allow_user_uploads only hides the upload UI; UploadPostForm::submit() still creates posts, so the site-wide pause can be bypassed.
- `17-03` bug, `routes/web.php` — Turning allow_guest_viewing off does not require sign-in: the feed, post, share and profile routes, and the feed's user-search results, stay public.
- `17-05` bug, `resources/views/livewire/posts/post-show.blade.php` — The standalone post page mounts the comment thread and comment form even when the show_comments feature flag is off.
- `17-09` bug, `app/Livewire/Notifications/NotificationBell.php` — The bell shows only the 10 latest notifications, so older unread ones can't be viewed or marked read, and the badge doesn't reflect the full unread set.
- `17-47` minor, `app/Livewire/Settings/NotificationPreferencesForm.php` — Saving notification preferences gives no confirmation, and a skipped save reports nothing.

#### media — #1384

- `06-01` bug, `app/Services/Media/GdImageIngestor.php` — Any warning raised by exif_read_data() is turned into an exception and treated as 'no EXIF', so a JPEG whose EXIF still yields a valid Orientation is stored unrotated (cubic's line 116 points at readImageSize, but its text describes the same EXIF catch).
- `04-03` bug, `app/Livewire/Profile/EditProfileForm.php` — After a successful save the $avatar upload is never cleared, so every later save on the same page re-ingests it as a new avatar.

#### admin-and-data-integrity — #1393

- `02-66` bug, `app/Actions/Rating/DeleteRatingOptionAction.php` — The delete guard checks only votes; an option used as a post author answer but with no votes passes, then delete() hits the restrict foreign key and the admin gets an uncaught 500.
- `09-01` bug, `app/Filament/Resources/RatingGroups/Pages/EditRatingGroup.php` — Delete is offered for an option with no votes even when post_author_answers references it, so deleting it hits the restrict foreign key and fails with an uncaught error.
- `03-01` bug, `app/Actions/Tags/DeleteTagAction.php` — The 'tag still used by posts' guard uses posts(), which hides soft-deleted posts, so a tag used only by author-deleted posts is deleted and the post_tag rows cascade away.
- `08-02` minor, `app/Filament/Resources/Comments/Pages/ListComments.php` — Status/sort normalization casts the URL value with (string) without checking it is a string, so an array query value breaks the page; same in ListPosts.
- `08-03` minor, `app/Filament/Resources/Personas/Pages/ListPersonas.php` — The URL page number is unbounded, so a huge ?page= reaches paginate() with an enormous offset.
- `08-04` minor, `app/Filament/Resources/Posts/Pages/ListPosts.php` — Posts and Comments show an empty 'queue clear' page when the current last page empties, even though earlier pages still have rows; the page number should be clamped to lastPage().
- `11-03` minor, `app/Console/Commands/CommentsPurgeDeletedCommand.php` — (int) saturates an --older-than above PHP_INT_MAX instead of rejecting it.
- `09-12` minor, `app/Filament/Support/TranslationAiDiagnostics.php` — The calls page number is only lower-bounded, so a forged ?page= produces an enormous OFFSET.
- `11-02` minor, `app/Console/Commands/PostsPurgeCommand.php` — posts:purge accepts a destructive --older-than shorter than the configured retention without the --force acknowledgement that comments:purge-deleted and moderation:purge-content require.
- `11-15` minor, `app/Console/Commands/PostsPurgeCommand.php` — A bare `--post` with no id falls through to the full candidate purge instead of a targeted run.
- `09-13` minor, `app/Filament/Resources/Reports/Pages/ListReports.php` — Reports (and Users) paginate at the stored page without clamping, so a page that empties shows the empty state although earlier pages have rows.
- `02-67` minor, `app/Actions/Rating/UnarchiveRatingOptionAction.php` — Unarchive deactivates any option passed to it, not just archived ones, without the group-minimum check.
- `02-68` minor, `app/Actions/Rating/UpdateRatingOptionAction.php` — UpdateRatingOptionAction can activate an archived option, and VoteRatingOptionAction then accepts votes for it because it checks only is_active.
- `09-05` minor, `app/Filament/Resources/RatingGroups/Pages/EditRatingGroup.php` — unarchiveOption accepts any option id and the action deactivates it without the range check, so the group can drop below its minimum.
- `09-06` minor, `app/Actions/Rating/UpdateRatingOptionAction.php` — UpdateRatingOptionAction does not recheck archived_at under the lock, so a save racing an archive can leave an option archived and active.
- `06-09` minor, `app/Models/Comment.php` — comments.status defaults to 'published', which CommentStatus (visible|hidden) cannot hydrate.
- `12-01` minor, `app/Enums/CommentStatus.php` — CommentStatus (visible|hidden) cannot hydrate rows created with the comments.status schema default 'published'.
- `09-11` minor, `app/Filament/Resources/Users/Pages/ListUsers.php` — normalize() casts the #[Url] status/role (Users) and status/type (Reports) to string before checking them, so an array query value raises an array-to-string warning that becomes a 500.
- `02-79` minor, `app/Actions/Settings/ApplyProjectPresetAction.php` — An explicit empty tags list keeps all existing tags although only null means 'leave tags unchanged'.
- `15-08` minor, `app/Filament/Resources/Comments/Pages/ListComments.php` — A page past the last one shows the empty state with no pager while the toolbar still counts comments; ListComments does not clamp pageNumber.
- `15-26` minor, `app/Filament/Pages/MediaDiagnosticsPage.php` — Past the last page, the unfiltered empty state says 'No issues — the last audit found nothing to fix' while the toolbar counts issues.
- `16-03` bug, `app/Actions/Tags/DeleteTagAction.php` — A tag attached only to soft-deleted (still restorable) posts is treated as unused and can be deleted, cascading the pivot rows so a restored post loses its tag.
- `16-45` minor, `app/Filament/Resources/Users/Pages/ListUsers.php` — An out-of-range page shows 'No people match' and hides pagination instead of clamping to the last page.
- `18-01` bug, `app/Actions/Tags/DeleteTagAction.php` — post_tag cascades on tag delete while DeleteTagAction's usage check ignores soft-deleted posts, so a restorable post silently loses the tag.
- `18-06` minor, `database/migrations/2026_05_14_183711_create_comments_table.php` — comments.status defaults to 'published', which is not a CommentStatus value.

#### studio-and-jobs — #1389

- `10-11` minor, `app/Jobs/Studio/GenerateStudioPostTextsJob.php` — The job does not refuse an incomplete analysis, so a batch claimed while the photo analysis is being re-run still makes a paid request from the description being replaced.
- `03-18` minor, `app/Actions/Studio/StartStudioImageAnalysisAction.php` — The reload after the claim is not constrained to the claimed attempt and Running status, so it can return a replacement attempt.
- `03-13` minor, `app/Actions/Studio/ExecuteStudioAction.php` — An unexpected exception in a run is turned into a failed action without being reported, so operators never get the stack trace.
- `10-01` minor, `app/Jobs/NotifyFollowersAboutNewPostJob.php` — The job's 120 s timeout exceeds the queue's 90 s retry_after, so a slow run can be redelivered while it still runs and followers are notified twice.
- `02-35` minor, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php` — The duplicate-notification check races with a concurrent or redelivered job, so a follower can be notified twice for the same post.
- `06-04` minor, `app/Services/Notifications/LifecycleSafeDatabaseNotifier.php` — The notifier serializes on the two user rows but does not re-check for an existing notification, so overlapping follower jobs can both insert one.
- `03-22` minor, `app/Actions/Studio/RequestStudioPostTextGenerationAction.php` — Batches ended as lost are marked Failed without finished_at.
- `03-15` minor, `app/Actions/Studio/StopStudioPlanAction.php` — Stop leaves a Running action to finish, but if that run is rate-limited, postpone() returns it to Pending and the dispatcher later runs it, so something is still published after the stop.
- `02-33` minor, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php` — Offset-based chunk() over a filtered follower query can skip followers when rows leave the filter during a large send; use chunkById.
- `02-37` minor, `app/Actions/Follows/NotifyFollowersAboutNewPostAction.php` — Per-recipient send failures are logged and swallowed, so the job succeeds and its retry policy never re-delivers them.
- `10-02` minor, `app/Jobs/NotifyFollowersAboutNewPostJob.php` — Per-recipient send failures are swallowed by the action, so the job succeeds and its 5 tries never re-deliver the missed notifications.
- `05-12` minor, `app/Support/Studio/Concerns/DrawsPersonaVotes.php` — drawn() compares xxh3 hex strings with <=>, which compares numerically when a hash looks numeric, so the comparator is not a total order and the draw can depend on input order.
- `18-07` minor, `database/migrations/2026_10_19_100000_create_studio_plans_table.php` — studio_plans.category_id is nullOnDelete, so deleting a category after approval makes the executor publish the post uncategorized.

#### media-maintenance — #1390

- `11-13` minor, `app/Console/Commands/MediaPurgeCommand.php` — With --orphans, the --asset and --older-than options are silently ignored, so a run the operator meant to narrow deletes every orphan.
- `11-23` minor, `app/Console/Commands/GenerateMediaVariantsCommand.php` — The missing-variant precheck runs outside the per-asset try, so one asset's exception aborts the whole backfill.
- `06-05` minor, `app/Services/Media/MediaVariantGenerator.php` — generateAll() passes nullable media_assets.width/height into wouldUpscale(int, int), so a dimensionless Ready asset throws a TypeError before any variant is generated.
- `05-13` minor, `app/Support/Media/OpenGraphImage.php` — openGraphFromMaster() passes the asset's nullable width/height into OpenGraphImage's non-nullable int parameters, so a dimensionless image makes the post page throw a TypeError.
- `06-06` minor, `app/Services/Media/MediaOrphanScanner.php` — A file that disappears between allFiles() and lastModified() makes lastModified() throw and aborts the whole orphan scan.
- `10-05` minor, `app/Jobs/RunMediaAuditJob.php` — If an issue insert fails, the failure handler flushes the still-populated buffer again, which throws again, so the run is never marked Failed and stays Running.
- `10-06` minor, `app/Jobs/RunMediaAuditJob.php` — pruneOldRuns() runs inside the audit's failure boundary, so a retention-cleanup error rewrites an already completed audit as Failed.
- `10-07` minor, `app/Jobs/RunMediaAuditJob.php` — A failed audit keeps all counters at zero, so the diagnostics history reports 'Stopped after 0 assets' even after real work was done.
- `06-07` minor, `app/Services/Media/MediaAuditService.php` — A soft-deleted asset that is still referenced is counted as soft_deleted_within_grace (no issue), even after its grace expired, so the audit reports a broken state as normal.
- `11-17` minor, `app/Services/Media/MediaAuditService.php` — The audit labels soft-deleted assets that are still referenced, including ones whose grace has expired, as 'Soft-deleted, within grace period'.
- `08-08` minor, `app/Filament/Pages/MediaDiagnosticsPage.php` — lastAuditRun() and recentRuns() order audit runs by started_at only, so runs started in the same second sort arbitrarily.
- `08-09` minor, `app/Filament/Pages/MediaDiagnosticsPage.php` — auditCurrentlyRunning() uses the raw configured lock TTL, while the job enforces max(TTL, timeout + 60), so a short configured TTL makes the page treat a still-locked audit as abandoned.
- `08-10` minor, `app/Filament/Pages/MediaDiagnosticsPage.php` — The inspector offers Release for an asset that is already released and Purge without checking the inspection's purgeable state.
- `11-09` minor, `app/Console/Commands/VerifySharingConfigurationCommand.php` — The deploy-time sharing verifier checks the `public` filesystem disk's URL instead of the disk media is actually stored on (config('media.disks.public')).
- `15-04` minor, `app/Filament/Pages/MediaDiagnosticsPage.php` — The inspector's explanation is derived from the audit-time issue type while the files and footer reflect the disk now, so they can contradict each other.
- `15-15` minor, `resources/views/filament/pages/media-diagnostics.blade.php` — Regenerate stays clickable during its request and each click dispatches a non-unique GenerateMediaVariantsJob, so double-clicks queue duplicate work.
- `15-22` minor, `resources/views/filament/pages/media-diagnostics/asset-inspector.blade.php` — The Copy path button shows 'Copied' even when the clipboard write did not happen.

#### visual-screenshots — #1385

- `11-01` bug, `app/Support/VisualRegression/PestVisualScreenshotRunner.php` — The screenshot runner's child Pest process is not isolated to a test database, so it runs against whatever database the parent environment points at.

#### observability — #1386

- `11-47` minor, `app/Console/Commands/ObservabilityHealthCommand.php` — A comment, and the operator warning, carry release bookkeeping ('Phase 6B') instead of describing the logging policy.
- `01-08` minor, `bootstrap/app.php` — AttachStructuredLogContext runs before session/auth and SetLocale, so the shared log context never has user_id and records the default locale.
- `05-07` minor, `app/Support/Observability/LogContext.php` — user_id and theme_preference never reach request logs because AttachStructuredLogContext evaluates LogContext::base() before the session starts.
- `05-06` minor, `app/Support/Observability/DomainLogger.php` — build() merges base() after the caller's context, so the signed-in administrator's user_id overwrites the persona id the persona events pass as user_id.
- `12-14` minor, `app/Providers/ObservabilityServiceProvider.php` — In queue workers the deployment facts (release, commit, target) are replaced by the values dehydrated into the job payload, so a job runs under the dispatcher's release rather than the worker's.
- `01-09` minor, `app/Http/Middleware/AttachRequestId.php` — The request-id regex rejects dots although the observability doc allows them, and '$' accepts a trailing newline.
- `05-05` minor, `app/Support/Observability/ExceptionContextBuilder.php` — build() calls LogContext::base(), which resolves the authenticated user from the database, so during a database failure the context callback throws while the original error is being reported.
- `04-24` minor, `app/Support/Observability/GuestVoteAttemptLog.php` — Every guest vote call writes a security-log line with no per-IP limit, so one visitor can grow the 30-day security log without bound.
- `17-16` minor, `config/sentry.php` — $sentryFloat casts non-numeric input such as 'abc' or '0,5' to 0.0, silently dropping all error reports.
- `17-18` minor, `bootstrap/app.php` — AttachStructuredLogContext runs before the session starts, so the shared log context never includes user_id for session-authenticated requests.
- `17-19` minor, `config/deployment.php` — The health command reports 'Sentry events will carry no release' whenever metadata is malformed, even when the release itself is valid and is sent.
- `17-26` minor, `routes/api.php` — The routes/api.php header comment names a release phase ('Phase 40 only prepares the route file...'), which CLAUDE.md forbids.

#### reports-and-moderation — #1395

- `02-71` minor, `app/Actions/Reports/IgnoreReportAction.php` — When the guarded UPDATE matches zero rows, Ignore/Resolve return normally, so the caller reports success and offers Undo for a close that did not happen.
- `02-74` minor, `app/Actions/Reports/ResolveReportAction.php` — Resolve matches status != resolved, so it can overwrite an ignored report and its processor metadata, unlike Ignore which requires status = open.
- `09-04` minor, `app/Actions/Reports/ResolveReportAction.php` — Resolving can overwrite a report another moderator ignored meanwhile, because ResolveReportAction accepts every status except resolved after the page's open check.
- `08-11` minor, `app/Filament/Resources/Posts/Pages/ListPosts.php` — When no selected post can be moderated, the bulk confirmation and the Undo handlers return silently instead of saying nothing was done.
- `08-12` minor, `app/Filament/Resources/Posts/Pages/ListPosts.php` — The bulk Undo toasts name the first selected post instead of the one actually restored or re-hidden when only one of several succeeded.
- `09-07` minor, `app/Filament/Widgets/ReportedPostsWidget.php` — The Reported posts card counts through Post::query(), which hides soft-deleted rows, while the Reported list it opens includes author-deleted posts.
- `09-08` minor, `app/Filament/Widgets/ReportedCommentsWidget.php` — The Reported comments card does not match the list it links to: it counts finalized removals the Reported tab excludes and misses author-deleted comments the tab includes.
- `04-28` minor, `app/Queries/Users/ModerationUsersQuery.php` — The Suspicious filter ignores author-deleted posts and comments, so deleting reported content takes a member off the list.
- `06-02` minor, `app/Services/Comments/CommentRetentionPurgeService.php` — The purges' open-report hold is a plain read that ReopenReportAction is not serialized with, so a report reopened between the hold check and the deletion is either orphaned (comment leaf purge) or deleted along with the post graph.
- `13-05` minor, `app/View/Components/Comments/CommentItem.php` — canReport ignores the viewer's lifecycle capability, so Limited, Banned and Shadowbanned users see a comment Report action that always fails.
- `16-23` minor, `app/Livewire/Comments/CommentVoting.php` — Restricted users see enabled comment vote buttons that always fail; votingDisabled should include canVote() as PostVoting does.
- `17-42` minor, `app/Livewire/Moderation/InlinePostModeration.php` — canRestore shows Restore for finalized removals, which RestorePostAction always rejects.
- `17-43` minor, `app/Livewire/Moderation/InlinePostModeration.php` — The 1000-character limit on the moderation reason is enforced only by the browser's maxlength.
- `17-44` minor, `resources/views/livewire/moderation/inline-post-moderation.blade.php` — The menu variant never renders `$error` (or `$success`), so a rejected hide or restore gives the moderator no feedback.

#### forms-and-validation — #1392

- `01-10` minor, `app/Http/Requests/DeleteUserRequest.php` — Without 'bail', the email closure still runs when email is an array and (string)$value raises a warning that becomes a 500.
- `01-12` minor, `app/Http/Requests/SubmitContactMessageRequest.php` — The contact form's 'lowercase' rule rejects valid mixed-case email addresses.
- `12-06` minor, `app/Http/Requests/SubmitContactMessageRequest.php` — The contact form accepts reply-to addresses that Symfony Mime rejects, so the queued mail fails after the visitor was told the message was sent.
- `04-08` minor, `app/Livewire/Comments/CommentsSection.php` — deleteComment/hideComment let CannotDeleteCommentException/CannotHideCommentException escape as a 500, and canDeleteComment() does not match all of the action's refusals.
- `04-26` minor, `app/Livewire/Comments/CommentForm.php` — A successful submission clears body but leaves an earlier body error displayed.
- `04-21` minor, `app/Livewire/Profile/EditProfileForm.php` — The avatar rule accepts formats the ingest pipeline rejects, so users get a generic error instead of a field error.
- `08-05` minor, `app/Filament/Resources/Personas/Concerns/EditsPersona.php` — The Persona photo rule accepts image formats the avatar ingest rejects, so a GIF/BMP passes validation and only fails after the rest of the Persona is saved.
- `08-06` minor, `app/Filament/Resources/Personas/Concerns/EditsPersona.php` — savePhoto() does not handle a MediaStorageException from SetPersonaAvatarAction, so a storage failure after the text was saved escapes as an error instead of the 'photo wasn't saved' path.
- `04-19` minor, `app/Livewire/Follows/FollowButton.php` — toggle() shows 'This author cannot be followed.' for both CannotFollowAuthorException reasons, blaming the author when the viewer's own account may not follow.
- `13-04` minor, `app/Rules/UniqueEffectiveTagSlug.php` — max:100 only covers a typed slug; a blank slug is derived from the name with no length check, and transliteration can make it longer than 100 characters.
- `02-19` minor, `app/Actions/Comments/AddCommentAction.php` — trim() does not remove Unicode-only whitespace or invisible format characters, so an effectively blank comment passes the empty-body check.
- `15-29` minor, `app/Filament/Resources/Posts/Pages/ListPosts.php` — The client trims the reason before its 3-character check but the server validates the untrimmed value with min:3, then stores the trimmed one.
- `17-22` minor, `resources/views/livewire/profile/edit-profile-form.blade.php` — The Livewire profile form's 'Saved' indicator reads the identity form's session flash. It never appears after its own save, and it appears beside the identity form's 'Saved' after that form submits.
- `17-23` minor, `resources/views/livewire/profile/edit-profile-form.blade.php` — Save can be submitted while the avatar upload is still running, so the profile saves without the selected avatar.
- `17-45` minor, `app/Livewire/Comments/CommentsSection.php` — The reply form stays open under a parent comment that was just deleted or hidden, because refreshComments does not reset replyingTo.

#### url-import — #1387

- `05-15` minor, `app/Support/Import/PinnedImportHttpTransport.php` — The transport catches only ConnectionException, so a size-cap abort that Laravel surfaces as RequestException escapes instead of becoming ImportFetchException.
- `05-16` minor, `app/Support/Import/Adapters/DirectImageImportAdapter.php` — The direct-image preview caps at import.max_image_bytes (8 MB) while the download caps at min(that, uploads.images.max_kilobytes*1024) (5 MB), so 5-8 MB images pass preview and then fail.
- `05-17` minor, `app/Support/Import/OpenGraphParser.php` — extractMeta() returns content untrimmed, so whitespace around an og:image value makes resolveUrl() produce a wrong URL.
- `05-18` minor, `app/Support/Import/OpenGraphParser.php` — A non-empty http og:image masks og:image:secure_url, and the https-only policy then rejects the http URL, so the page yields no image.
- `02-43` minor, `app/Actions/Import/StoreImportedImageAction.php` — A failed fwrite() raises a notice that Laravel turns into ErrorException, which catch (RuntimeException) does not match, so the partial temp file is never deleted.

#### feed-performance — #1391

- `04-09` minor, `app/Livewire/Comments/CommentVoting.php` — Voting one comment re-renders every CommentVoting component in the thread, because each listens to the global comment-voted event without filtering.
- `04-10` minor, `app/Livewire/Posts/SavePostButton.php` — SavePostButton::mount() runs Post::find() and a saved-state query for every card although a batched loader exists.
- `04-11` minor, `app/Livewire/Voting/RatingVoting.php` — Every RatingVoting child re-reads its rating group and options on each render, an N×G query pattern on the feed.
- `04-12` minor, `app/Livewire/Posts/PostVoting.php` — PostVoting, mounted per feed card with only a postId, re-queries the published post and the viewer's vote for every card.
- `04-13` minor, `app/Livewire/Comments/CommentVoting.php` — Each nested CommentVoting component queries its comment and the viewer's vote on render, an N+1 over the comment thread.
- `05-08` minor, `app/Support/SavedPosts/SavedPostState.php` — forUserAndPost runs a separate post_saves query for every save button mounted in the feed (N+1); load the states once with forUserAndPosts.
- `04-14` minor, `app/Livewire/Posts/SavePostButton.php` — toggle() inverts whatever the database holds rather than applying the state the button shows, and instances for the same post never sync, so a stale button undoes the user's intent.
- `17-27` minor, `resources/views/livewire/profile/profile-page.blade.php` — Profile post cards are rendered without preloaded rating state, so each nested RatingVoting loads its distribution and the viewer's vote on its own.
- `18-02` minor, `database/migrations/2026_05_14_183711_create_comments_table.php` — comments has no index on post_id / parent_id / status, so thread reads, counts and reply checks scan the whole table.
- `18-03` minor, `database/migrations/2026_07_29_100002_add_image_asset_id_to_posts_table.php` — posts.image_asset_id and users.avatar_asset_id are FKs without indexes.
- `18-04` minor, `database/migrations/2026_07_09_100002_add_category_id_to_posts_table.php` — posts.category_id has a foreign key but no index.
- `18-05` minor, `database/migrations/2026_05_14_183853_create_post_tag_table.php` — post_tag has no index leading with tag_id.

#### studio-editor — #1402

- `13-07` minor, `app/Actions/Studio/RequestStudioPostTextGenerationAction.php` — A plan whose photo has been removed is refused with a message about incomplete analysis instead of a missing-photo error telling the administrator to reload and add a photo.
- `08-07` minor, `app/Filament/Pages/StudioActionQueue.php` — The Action queue's 'late' flag uses an absolute difference and ignores cancelled actions, so an action cancelled long before its planned time is shown as late.
- `09-09` minor, `app/Filament/Resources/StudioPlans/Pages/EditStudioPlan.php` — Save with nothing to save re-draws the plan from the database at whatever version it now has, without telling the page's other editors and with a 'Nothing to save' toast; Generate then goes on as if the version were current.
- `07-26` minor, `app/Livewire/Admin/StudioPlanPostVotesEditor.php` — Even split on a question with no open options sets every count to zero, erasing the answers kept on closed options.
- `07-29` minor, `app/Livewire/Admin/StudioPlanPostVotesEditor.php` — setMoment() returns silently when the plan was deleted instead of marking the editor gone through writablePlan().
- `07-24` minor, `app/Livewire/Admin/StudioPlanPostVotesEditor.php` — setMoment() resets every moment to the saved offsets before applying the new one, dropping moment edits not saved yet.
- `07-28` minor, `app/Livewire/Admin/StudioPlanPostVotesEditor.php` — 'Select all' with an empty search chooses only the first 50 active Personas.
- `07-18` minor, `app/Livewire/Admin/StudioPlanDiscussionVotesEditor.php` — Changing Total up and Total down in one request loses the second change, because adjusting one total re-tallies both.
- `07-20` minor, `app/Livewire/Admin/StudioPlanDiscussionVotesEditor.php` — A failed preview is never rebuilt while the settings stay the same, because previewFor is recorded before the build.
- `07-14` minor, `app/Livewire/Admin/StudioPlanDiscussionEditor.php` — When the awaited generation was already taken in by another page, check() reloads the discussion but does not tell the other editors on the page.
- `07-30` minor, `app/Livewire/Admin/StudioPlanTextSuggestionsCard.php` — Generate after an author change silently returns the previous author's batch still on its way, so nothing is queued for the new author and nothing is said.
- `07-32` minor, `app/Livewire/Admin/StudioPlanTextSuggestionsCard.php` — An image analysis whose worker died keeps the suggestions card polling and saying it is waiting, forever.
- `07-25` minor, `app/Livewire/Admin/StudioPlanPostVotesEditor.php` — A rating option or question activated after the editor loaded is shown, but its inputs do nothing.
- `09-10` minor, `app/Filament/Resources/StudioPlans/Pages/CreateStudioPlan.php` — The first photo's Draft is always made with startAuthorId, so when that Persona has become unavailable the error asks to choose another author, but choosing one cannot help and every photo is refused.
- `15-14` minor, `resources/views/filament/pages/studio-plan/image.blade.php` — The client-side URL check rejects valid image links with an explicit port, and importFrom() never sends them to the server.
- `15-46` minor, `resources/views/filament/pages/studio-plan/image.blade.php` — A failed import leaves imported[source] set to the URL, so after switching source and back the same URL can neither be retried by typing nor via Try again.
- `16-17` minor, `app/Livewire/Admin/StudioPlanActionPlan.php` — stop() does not require the confirmation state, so a direct call cancels all remaining actions without the confirmation.
- `16-18` minor, `app/Filament/Resources/StudioPlans/Pages/EditStudioPlan.php` — EditStudioPlan::delete() never checks $confirmingDelete, so the 'asked first' deletion can be invoked directly.
- `16-21` minor, `resources/views/filament/pages/studio-plans/row.blade.php` — The plans list hides the not_persona author problem that StudioPlanFormat::author() reports and approval rejects.
- `16-22` minor, `resources/views/livewire/admin/studio-plan-vote-count.blade.php` — The stepper buttons send an absolute count rendered into the markup, so quick repeated clicks before the response collapse into one step.
- `16-28` minor, `resources/views/livewire/admin/studio-plan-offset-field.blade.php` — parseFloat accepts a numeric prefix, so '12x' is saved as 12.
- `16-29` minor, `resources/views/livewire/admin/studio-plan-offset-field.blade.php` — A rejected setMoment request leaves the typed offset in the field as if saved.
- `16-30` minor, `resources/views/livewire/admin/studio-plan-offset-field.blade.php` — The unit radiogroup lacks arrow-key navigation and roving focus.
- `16-31` minor, `resources/views/livewire/admin/studio-plan-discussion-votes-editor.blade.php` — The priority radiogroup lacks roving focus and arrow-key selection.
- `16-37` minor, `resources/views/livewire/admin/studio-plan-post-votes-editor.blade.php` — The vote-count steppers and voter-mode control stay enabled when the editor is not writable, producing 'Not saved' with Discard disabled.
- `16-38` minor, `resources/views/livewire/admin/studio-plan-post-votes-editor.blade.php` — The post-votes editor has no beforeunload warning for an unsaved change.
- `16-44` minor, `resources/views/livewire/admin/studio-plan-discussion-editor.blade.php` — The comment dialog's offset field can show a cleared or invalid value while Save still saves the previous offset.
- `16-52` minor, `resources/views/filament/pages/studio-plan/image.blade.php` — If Livewire's _startUpload request fails, the photo phase never resets and the analysis card stays on 'Uploading photo…'.

#### theme — #1396

- `04-23` minor, `app/Livewire/Theme/ThemeSwitcher.php` — The switcher marks 'system' when the user has no stored preference, ignoring the project default theme the layout actually applies.
- `10-17` minor, `resources/js/theme-bootstrap.js` — The client bootstrap gives localStorage precedence for every user, so a stale or guest value overrides a logged-in user's saved theme.
- `14-03` minor, `resources/js/app.js` — The livewire:navigated handler writes the server-rendered preference into localStorage, and the bootstrap then lets localStorage override the account/default preference, so later server-side changes never apply in that browser.
- `14-04` minor, `resources/js/theme-bootstrap.js` — The prefers-color-scheme listener is registered only when the page first loads with System, so choosing System later does not follow OS theme changes.
- `16-49` minor, `app/Livewire/Theme/ThemeSwitcher.php` — The mounted theme switchers do not synchronise their selected state when another switcher changes the theme.
- `17-50` minor, `app/Livewire/Theme/ThemeSwitcher.php` — After a reload, the guest theme switcher highlights System even when Light or Dark is applied.

#### a change of its own

- `06-10` minor, `app/Models/Concerns/LocksActorForWrite.php` — Member votes/comments lock Actor -> Post and then implicitly take a KEY SHARE lock on the post author's row, out of the documented order, so they can deadlock with two-user operations that lock the author first.

#### views — #1400

- `17-34` minor, `resources/views/livewire/feed/feed-page.blade.php` — The feed drawers are marked wire:lazy, which Livewire 4.4 does not read, so they render with the page; the report and moderation menus carry the same inert attribute.
- `14-44` minor, `resources/views/components/ui/search-form.blade.php` — request('search') can be an array (?search[]=x); echoing it in value="{{ $value }}" raises a TypeError and a 500 on every page with the header.
- `14-60` minor, `resources/views/auth/reset-password.blade.php` — An array-valued email query parameter or flashed old input makes the reset page throw and return 500.
- `14-22` minor, `resources/views/components/comments/comment-item.blade.php` — For a tombstoned author the comment shows the stored English name instead of the localized deleted-user label.
- `14-37` minor, `resources/views/components/brand-wordmark.blade.php` — The wordmark split fails for names that start with an acronym, such as the shipped AIGuru preset, which loses its accent-coloured tail.
- `14-08` minor, `resources/views/livewire/feed/post-drawer-content.blade.php` — x-ui.skeleton emits width/height as class names, but the post drawer passes CSS values (16rem, 70%, 45%), so its loading skeleton collapses and loses its widths.
- `14-43` minor, `resources/views/components/locale-switcher.blade.php` — The language switcher trigger exposes neither aria-haspopup nor aria-expanded.
- `14-25` minor, `resources/views/components/feed/post-card.blade.php` — A vote error shown under a feed card is never cleared after a later successful vote.
- `14-10` minor, `resources/views/components/danger-button.blade.php` — White text on bg-rg-danger is about 3.8:1 in the dark theme, below WCAG AA for 13px text.
- `14-17` minor, `resources/views/components/voting/rating-options.blade.php` — After voting in a two-option group, the results view never shows which option the user chose; option A is always highlighted.
- `14-21` minor, `resources/views/components/comments/comment-item.blade.php` — wire:keydown.stop / wire:click.stop on the comment's menu wrapper and footer swallow events, so Escape never closes the actions menu or report dialog and clicking Reply leaves the menu open.
- `14-26` minor, `resources/views/components/feed/post-card.blade.php` — Enter (and Space) on nested controls of a feed card bubbles to the card's keydown handlers, so select-post fires alongside the control's own action.
- `14-29` minor, `resources/views/components/ui/modal.blade.php` — Clicking the dimmed overlay does not close the modal, because a full-size wrapper sits above the backdrop.
- `14-30` minor, `resources/views/components/ui/modal.blade.php` — One Escape closes both the login dialog and the post drawer under it.
- `14-55` minor, `resources/views/components/share/copy-link-button.blade.php` — Interpolating the labels into quoted JS literals breaks the Alpine expression as soon as a translation contains an apostrophe.
- `04-15` minor, `app/Livewire/Feed/PostFeed.php` — PostFeed::$selectedPostId is not reactive, so selecting a post never marks its card as selected.
- `04-16` minor, `app/Livewire/Feed/PostDrawer.php` — In overlay mode a selection dispatches post-selected twice, and the second can overwrite the overlay's return-focus target with the panel itself.
- `16-01` bug, `resources/views/layouts/app.blade.php` — With post_detail_overlay_mode off, profile and saved-post cards have no detail host, so clicking a card does nothing.
- `16-39` minor, `resources/views/layouts/partials/app-sidebar.blade.php` — The fixed-height sticky desktop sidebar has no vertical scrolling, so content past the viewport is unreachable.
- `16-50` minor, `resources/views/layouts/app.blade.php` — The mobile navigation drawer declares aria-modal but does not move, trap or restore focus.
- `16-51` minor, `resources/views/layouts/app.blade.php` — The authenticated upload button has no accessible name below the sm breakpoint.
- `17-04` bug, `resources/views/livewire/posts/post-show.blade.php` — The post page and post drawer show the author's account name instead of their chosen display name, which can expose a real name the user replaced.
- `17-06` bug, `resources/views/livewire/feed/post-drawer.blade.php` — The mobile-only overlay's close handler returns early at the desktop breakpoint, so a drawer opened below it leaves the page scroll lock on after a resize, and Escape cannot release it.
- `17-07` bug, `resources/views/livewire/feed/feed-page.blade.php` — PostFeed's selectedPostId is passed as a non-reactive prop, so selecting or closing a post never updates the selected-card highlight.
- `17-08` bug, `resources/views/livewire/feed/upload-post-form.blade.php` — Switching image tabs leaves the client preview out of sync with the server: the URL tab clears the uploaded file server-side but the file tab keeps showing it.
- `17-28` minor, `resources/views/livewire/feed/post-drawer.blade.php` — The overlay's window Escape listener fires even while the drawer is closed, sending closeOverlay requests and refocusing the stale returnFocusEl.
- `17-29` minor, `resources/views/livewire/feed/post-drawer.blade.php` — Escape pressed in a dialog nested in the drawer closes the whole post overlay instead of only the topmost dialog.
- `17-30` minor, `resources/views/livewire/feed/post-drawer.blade.php` — The aria-modal post drawer does not trap keyboard focus, so Tab reaches the obscured feed behind it.
- `17-31` minor, `resources/views/livewire/feed/post-drawer-content.blade.php` — wire:keydown.stop on the drawer's footer wrappers swallows Escape, so it neither closes the actions menu nor the overlay while focus is inside them.
- `17-32` minor, `resources/views/livewire/feed/post-drawer-content.blade.php` — The post-not-found state in the drawer has no close control, so a phone user cannot dismiss the overlay.
- `17-33` minor, `resources/views/livewire/feed/feed-page.blade.php` — The drawer's comments button re-runs the feed's selection scroll, jumping the page back to the selected card.
- `17-35` minor, `resources/views/livewire/feed/upload-post-form.blade.php` — Hidden type=url inputs still take part in native constraint validation, so an invalid value typed before a tab switch silently blocks submit.
- `17-36` minor, `resources/views/livewire/feed/post-feed.blade.php` — With the following feed and a search both active, an empty result shows the following-feed message instead of the no-results message.
- `17-37` minor, `resources/views/livewire/saved-posts/saved-posts-page.blade.php` — An out-of-range ?page shows the 'nothing here' empty state with no pagination links, although earlier pages have items.
- `17-38` minor, `resources/views/livewire/notifications/notification-bell.blade.php` — Clicking outside the bell does not close the notifications dropdown.
- `17-39` minor, `resources/views/livewire/notifications/notification-bell.blade.php` — The bell toggle button doesn't expose its open state through aria-expanded.
- `17-40` minor, `resources/views/livewire/reports/_report-modal-content.blade.php` — The textarea id `report-message` is hard-coded, so it is duplicated when several ReportModal instances render and the label targets the wrong textarea.
- `17-41` minor, `resources/views/livewire/reports/report-modal.blade.php` — In the inline variant, the component's own x-data `reportOpen` shadows the caller's state, so Escape does not close the profile report modal.
- `17-46` minor, `resources/views/livewire/follows/follow-button.blade.php` — The role=status live region is inserted only once a message exists, so screen readers may not announce it.
- `17-48` minor, `resources/views/livewire/saved-posts/saved-posts-page.blade.php` — Saved-post cards in the loop are not keyed, and x-feed.post-card would not forward a wire:key to its root anyway.
- `17-49` minor, `resources/views/livewire/settings/user-locale-settings.blade.php` — The locale select has no accessible name.
- `17-51` minor, `resources/views/vendor/livewire/tailwind.blade.php` — An omitted scrollTo defaults to no scrolling, so changing page leaves the viewport where it was.

#### admin-views — #1401

- `16-04` bug, `resources/views/filament/pages/translation-center/script.blade.php` — Both generated-suggestion save handlers treat a response with saved: 0 as a transport failure, so a save in which every row was skipped is reported as 'the server did not answer' and its outcomes are never applied.
- `16-05` minor, `resources/views/filament/pages/translation-center/script.blade.php` — switchTo() drops every volatile draft before the locale $set succeeds, so a failed switch loses them.
- `16-06` minor, `resources/views/filament/pages/translation-center/script.blade.php` — A poll in flight when the component is destroyed still applies its result and schedules another timer, so the replaced Alpine instance can keep polling the old locale.
- `16-10` minor, `resources/views/filament/pages/translation-center/script.blade.php` — A successful Save all overwrites, via stored(), a newer draft typed into a row while the save was pending.
- `16-12` minor, `resources/views/filament/pages/translation-center/row.blade.php` — The placeholder-chip loop reuses $placeholder, so rows with placeholders show the last token as the field's placeholder text.
- `16-13` minor, `resources/views/filament/pages/translation-ai.blade.php` — After Refresh adds a model, the combobox keeps its key, so its Alpine maps are stale and searching the new model shows 'No model matches' beside the match.
- `16-16` minor, `resources/views/filament/pages/tags/drawer.blade.php` — The slug warning compares the raw slug field, so clearing it warns that links will break even when the slug made from the name is unchanged.
- `14-11` minor, `resources/views/components/admin/ui/toast-stack.blade.php` — When a fourth toast evicts the oldest one while focus is inside it, `focused` stays true and the remaining toasts never auto-dismiss.
- `14-33` minor, `resources/views/components/admin/ui/date-time-field.blade.php` — Minute stepping wraps within the hour instead of carrying, so '5 minutes later' at :55 goes back to :00 of the same hour.
- `14-59` minor, `resources/views/components/admin/ui/number-stepper.blade.php` — When an invalid value is restored on change, change-only listeners are never told the restored value.
- `15-02` minor, `resources/views/filament/pages/personas/row.blade.php` — wire:click.prevent on the Edit links cancels Ctrl/Cmd/Shift-click, so the links cannot be opened in a new tab as the docblock promises.
- `15-18` minor, `resources/views/filament/pages/rating-groups/legend.blade.php` — The legend has no entry for the 'over' slot state (an active option above the maximum).
- `15-20` minor, `resources/views/filament/pages/moderation/reason.blade.php` — The acknowledgement error is not tied to its checkbox: aria-invalid is set but there is no aria-describedby to the message.
- `15-30` minor, `resources/views/filament/pages/reports/panel.blade.php` — Escape closes the floating report panel only when focus is inside it, and opening a report leaves focus on the row button.
- `15-31` minor, `resources/views/filament/pages/studio-action-queue/row.blade.php` — Completed vote actions never have a link, but the fallback text says what they wrote is no longer on the site.
- `15-37` minor, `resources/views/filament/pages/languages/missing.blade.php` — The catalog-issues notice says the language 'cannot be enabled' even when it is already enabled.
- `15-42` minor, `resources/views/filament/pages/project-settings.blade.php` — The Translate links leave Project settings without warning, losing an unsaved draft.
- `15-45` minor, `resources/views/filament/pages/rating-group/confirmation.blade.php` — The above-max dialog presents the active-option count as the maximum instead of showing both values.

#### config — #1397

- `17-01` bug, `config/import.php` — IMPORT_FROM_URL_ENABLED=false does not disable URL imports because nothing reads import.enabled; only the project flag allow_url_imports is checked.
- `17-12` minor, `config/queue.php` — (int) env('REDIS_QUEUE_RETRY_AFTER', 90) becomes 0 when the variable is present but blank, as the templates ship it, making reserved jobs immediately re-runnable.
- `17-13` minor, `config/uploads.php` — Blank numeric env values (UPLOAD_IMAGE_*, RATE_LIMIT_*, MEDIA_PURGE_GRACE_DAYS) are cast to 0 instead of falling back to the default.

#### seeders — #1398

- `18-09` minor, `database/seeders/DefaultRatingConfigurationSeeder.php` — Default rating groups and options are upserted without a transaction, so a failure leaves a partial configuration.
- `18-10` minor, `database/seeders/DefaultCategorySeeder.php` — Under DatabaseSeeder (WithoutModelEvents) the category writes skip Category's cache invalidation.
- `18-11` minor, `database/seeders/DemoFillSeeder.php` — The large demo seeder writes replies to replies, a depth the product does not allow.
- `18-12` minor, `database/seeders/DemoReportsSeeder.php` — Resolved and ignored demo reports are attributed to the trusted member, who cannot process reports.
- `18-13` minor, `database/seeders/DemoReportsSeeder.php` — A demo fixture has Carla reporting her own post, which PostPolicy::report forbids.
- `18-14` minor, `database/seeders/Support/DemoPostMediaGenerator.php` — firstOrCreate ignores soft-deleted media assets, so reseeding after a demo image is released fails on the (disk, path) unique index.
- `19-39` minor, `database/factories/StudioActionFactory.php` — The default CreatePost action and StudioPlanFactory::status() build Approved plans with no author or approver, and an actor who is not the plan's author - states approval never produces.
- `19-41` minor, `database/factories/StudioPlanImageAnalysisFactory.php` — The (int) cast turns a plan without a photo into source_image_asset_id 0 instead of failing.
- `19-45` minor, `database/factories/ReportFactory.php` — resolved() attributes the resolution to a regular user, although only moderators and admins process reports.
- `19-46` minor, `database/factories/MediaVariantFactory.php` — MediaVariantFactory's default is a JPEG although display variants are WebP, and named() keeps PostFeed640's 640x360 JPEG metadata for every name.

#### tooling — #1399

- `19-02` minor, `tools/phpstan/src/Rules/NoPhysicalUserDeletionRule.php` — Deletes issued through a User-scoped builder or relation (User::query()->delete(), User::where(...)->delete()) are not rejected by the physical-deletion rule.
- `19-03` minor, `tools/phpstan/src/Rules/NoPhysicalUserDeletionRule.php` — (Found during triage) The strict `->yes()` receiver checks reject nullable receiver types, so `$request->user()->delete()` and `$this->post->update()` on a `?Post` property escape the rules.
- `19-04` minor, `tools/phpstan/src/Rules/NoPhysicalUserDeletionRule.php` — The banned method lists omit other physical deletions of users rows: User::truncate(), $user->deleteQuietly(), $user->deleteOrFail().
- `19-06` minor, `tools/phpstan/src/Rules/RestrictedDatabaseFacadeRule.php` — The DB facade blocklist omits query-executing methods such as cursor() and selectFromWriteConnection().
- `19-07` minor, `tools/phpstan/src/Rules/RestrictedDatabaseFacadeRule.php` — Only DB::transaction() counts as transaction management; DB::beginTransaction()/commit()/rollBack() pass in presentation classes, Query Objects and Policies.
- `19-08` minor, `tools/phpstan/src/Support/EloquentCallInspector.php` — Static calls through a class-string expression ($modelClass::create(), static::getModel()::query()) are never recognised as Eloquent calls.
- `19-09` minor, `tools/phpstan/src/Support/EloquentCallInspector.php` — Eloquent Collection receivers are not recognised, so `$users->load(...)` / `loadMissing()` in controllers bypass the controller-read rule.
- `19-10` minor, `tools/phpstan/src/Support/EloquentMutationDetector.php` — METHODS omits persisting calls such as createQuietly, createOrFirst, truncate, insertUsing, incrementEach/decrementEach.
- `19-11` minor, `tools/phpstan/src/Rules/NoUnvalidatedControllerInputRule.php` — RESTRICTED_METHODS omits common Request input readers (except, post, json, keys, allFiles, filled, missing, cookie...).
- `19-12` minor, `tools/phpstan/src/Rules/NoUnvalidatedControllerInputRule.php` — Request facade calls such as Request::input('status') are never checked.
- `19-13` minor, `tools/phpstan/src/Rules/RegisteredPaginationBoundaryRule.php` — Static model pagination such as Post::paginate(15) bypasses the registered-pagination boundary because the rule only dispatches MethodCall.
- `19-14` minor, `tools/phpstan/src/Support/BaselineInventory.php` — growthComparedTo() compares per entry rather than per key, so duplicating an existing suppression entry passes the growth guard.
- `19-15` minor, `tools/phpstan/src/Rules/RestrictedRawQueryRule.php` — Model instances are missing from the MethodCall receiver list, so `$model->whereRaw(...)` bypasses the raw-query rule.
- `19-16` minor, `tools/phpstan/src/Rules/NoReadOnlyLayerMutationRule.php` — Static model lock calls like Post::lockForUpdate()->first() are not flagged in Query Objects or Policies.
- `19-17` minor, `tools/phpstan/architecture.neon` — architecture.neon approves six raw-SQL boundaries that the documented reviewed-boundaries list does not mention.
- `19-22` minor, `tools/phpstan/src/Rules/NoControllerEloquentQueryRule.php` — rateguru.controller.eloquentQuery is ignorable, so ignoreErrors entries or generated baselines can suppress controller query violations.
- `19-24` minor, `tools/phpstan/src/Rules/NoInlineControllerValidationRule.php` — validate()/validateWithBag() calls through the ValidatesRequests controller trait are not detected.
- `19-30` minor, `tools/phpstan/src/Support/EloquentCallInspector.php` — Receiver checks require one supported class to cover the whole type, so unions of supported database types are not recognised.
- `19-35` minor, `tools/ci/bin/compare-runs.php` — Runs with unfinished jobs produce partial timings, or a nonsensical result when no job has finished.

