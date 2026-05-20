# Phase 22 — Inline Moderation UI — Design Review

Дата: 2026-05-20  
Релиз: `v0.2.3-phase22-inline-moderation-ui`

## Checklist

- [x] Moderator visibility checked — panel виден только при `auth()->user()->isModerator() || isAdmin()`.
- [x] Normal user / guest hidden checked — отрицательные тесты в `tests/Feature/Livewire/InlinePostModerationTest.php`.
- [x] Pending / published / hidden button states checked — каждый статус показывает только разрешённые actions, отрицательные тесты на каждое неверное сочетание.
- [x] Hide confirmation modal checked — Alpine `x-data="{ confirmHideOpen: false }"`, открывается из Hide button, закрывается на Cancel / Confirm hide.
- [x] Reason input checked — `name="moderation_reason"`, `maxlength="1000"`, `wire:model.defer="reason"`, передаётся в backend actions через `normalizedReason()`.
- [x] Card refresh checked — `post-moderated` event с `postId`/`action` слушается `PostFeed` через `#[On('post-moderated')]`, что вызывает re-render очереди.
- [x] Backend exceptions surfaced — `CannotModeratePostException` ловится в `runModerationAction()`, текст пишется в `$error`, success не выставляется, event не диспатчится.
- [x] Open in admin link — рендерится только для moderator/admin, ссылка реальна если есть `filament.admin.resources.posts.edit`, иначе показывается placeholder без `href`.

## Notes

- Prior phase review docs (`phase-18-comments-ui-review.md`, `phase-20-reports-ui-review.md`, `design-contract.md`, `ui-review-checklist.md`) в репозитории отсутствуют — записано в этой заметке, имплементация не блокируется.
- Inline panel выводится в `PostCard` под guard `@if ($post->exists)` — иначе dev UI kit ломался на demo-посте без id.
- `InlinePostModeration` намеренно загружает пост свежим перед каждым action (`Post::query()->findOrFail($this->postId)`), чтобы не зависеть от устаревшего объекта родителя.
- User moderation (`Ban`, `Shadowban`) в Phase 22 не выводится — это Phase 25 (Filament User Resource).
