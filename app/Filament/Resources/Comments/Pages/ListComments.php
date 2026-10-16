<?php

namespace App\Filament\Resources\Comments\Pages;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Exceptions\Moderation\CannotFinalizeRemovalException;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\Comment;
use App\Models\User;
use App\Queries\Comments\ModerationCommentsQuery;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Comments in Admin v2: every comment, a tab at a time, searched on the
 * server and read 25 at a time. Comments publish at once, so moderation
 * comes after the fact: a visible comment is hidden with an optional reason,
 * a hidden one restored at once — both with Undo — and an administrator
 * finalizes the removal of a hidden one, which nothing takes back. A comment
 * its author deleted stays listed, read-only, as audit history. Every change
 * goes through the action that checks the moderator, locks the comment and
 * writes the moderation log.
 */
final class ListComments extends Page
{
    /** The tabs, in order, and their labels. */
    public const TABS = [
        'all' => 'All',
        'visible' => 'Visible',
        'hidden' => 'Hidden',
        'deleted' => 'Author deleted',
        'reported' => 'Reported',
    ];

    /** How long a moderation reason may be. */
    public const REASON_LIMIT = 500;

    protected static string $resource = CommentResource::class;

    protected string $view = 'filament.pages.comments';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The open tab. Typed loosely: whatever a link puts here reads as a tab. */
    #[Url(except: 'all')]
    public mixed $status = 'all';

    /** The search over the text, the author, the post and the comment's id. */
    #[Url(except: '')]
    public mixed $q = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /**
     * The confirmation on screen: what it confirms and for which comment.
     *
     * @var array{action: 'hide'|'finalize', id: int}|null
     */
    #[Locked]
    public ?array $confirming = null;

    public string $reason = '';

    /** The acknowledgement an irreversible confirmation asks for. */
    public bool $acknowledged = false;

    public function getTitle(): string
    {
        return 'Comments';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new tab or search starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'q'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->pageNumber = 1;
    }

    public function askToHide(int $id): void
    {
        $this->ask('hide', $id);
    }

    public function askToFinalize(int $id): void
    {
        $comment = app(ModerationCommentsQuery::class)->find($id);

        if ($comment !== null && $this->moderator()->can('finalizeRemoval', $comment)) {
            $this->ask('finalize', $id);
        }
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->reason = '';
        $this->acknowledged = false;
        $this->resetErrorBag();
    }

    /** Does what the confirmation on screen confirms, with its reason. */
    public function confirm(): void
    {
        if ($this->confirming === null) {
            return;
        }

        ['action' => $action, 'id' => $id] = $this->confirming;
        $finalizing = $action === 'finalize';

        $this->validate(
            [
                'reason' => [$finalizing ? 'required' : 'nullable', 'string', 'min:3', 'max:'.self::REASON_LIMIT],
                'acknowledged' => $finalizing ? ['accepted'] : [],
            ],
            [
                'reason.required' => 'Give the internal reason.',
                'reason.min' => 'Say a little more: at least 3 characters.',
                'reason.max' => 'Keep it to '.number_format(self::REASON_LIMIT).' characters or fewer.',
                'acknowledged.accepted' => 'Tick the box to confirm this can’t be undone.',
            ],
        );

        $reason = trim($this->reason) !== '' ? trim($this->reason) : null;
        $comment = app(ModerationCommentsQuery::class)->find($id);

        $this->closeConfirmation();

        if ($comment === null) {
            return;
        }

        if ($finalizing) {
            if ($this->attempt(fn () => app(FinalizeCommentRemovalAction::class)->handle($this->moderator(), $comment, (string) $reason))) {
                $this->toast('Removal of comment #'.$id.' finalized');
            }

            return;
        }

        // Hiding a comment that is already hidden does nothing, quietly: say
        // so rather than toast a hide, and an Undo, that were someone else's.
        if ($comment->trashed() || $comment->status !== CommentStatus::Visible) {
            $this->changedMeanwhile();

            return;
        }

        if ($this->attempt(fn () => app(HideCommentAction::class)->handle($this->moderator(), $comment, $reason))) {
            $this->toast('Comment #'.$id.' hidden', ['label' => 'Undo', 'event' => 'restore-comment', 'params' => ['id' => $id]]);
        }
    }

    public function restore(int $id): void
    {
        $comment = app(ModerationCommentsQuery::class)->find($id);

        if ($comment !== null && $this->attempt(fn () => app(RestoreCommentAction::class)->handle($this->moderator(), $comment))) {
            $this->toast('Comment #'.$id.' restored', ['label' => 'Undo', 'event' => 'hide-comment-again', 'params' => ['id' => $id]]);
        }
    }

    /** Undo after hiding: the comment is visible again. */
    #[On('restore-comment')]
    public function restoreAgain(int $id): void
    {
        $comment = app(ModerationCommentsQuery::class)->find($id);

        if ($comment !== null && $this->attempt(fn () => app(RestoreCommentAction::class)->handle($this->moderator(), $comment))) {
            $this->toast('Comment #'.$id.' is visible again');
        }
    }

    /** Undo after restoring: the comment is hidden again. */
    #[On('hide-comment-again')]
    public function hideAgain(int $id): void
    {
        $comment = app(ModerationCommentsQuery::class)->find($id);

        if ($comment !== null && $comment->status === CommentStatus::Visible && ! $comment->trashed()
            && $this->attempt(fn () => app(HideCommentAction::class)->handle($this->moderator(), $comment))) {
            $this->toast('Comment #'.$id.' is hidden again');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(ModerationCommentsQuery::class);
        $counts = $query->counts();
        $paginator = $query->paginate((string) $this->status, (string) $this->q, (int) $this->pageNumber);

        return [
            'tabs' => collect(self::TABS)->map(fn (string $label, string $id): array => [
                'id' => $id,
                'label' => $label,
                'count' => number_format($counts[$id]),
                'href' => CommentResource::getUrl('index', $id === 'all' ? [] : ['status' => $id]),
                'attributes' => ['wire:click.prevent' => "\$set('status', '{$id}')"],
            ])->values()->all(),
            'stats' => ['reported' => number_format($counts['reported']), 'hidden' => number_format($counts['hidden'])],
            'paginator' => $paginator,
            'rows' => array_map(fn (Comment $comment): array => $this->row($comment), $paginator->items()),
            'searching' => trim((string) $this->q) !== '',
            'confirmation' => $this->confirmation(),
            // The reasons visitors report a comment with.
            'reasons' => array_map(
                fn (ReportReason $reason): string => Str::ucfirst(str_replace('_', ' ', $reason->value)),
                array_filter(ReportReason::cases(), fn (ReportReason $reason): bool => ! in_array($reason, [ReportReason::WrongCategory, ReportReason::Other], true)),
            ),
        ];
    }

    /**
     * One comment as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(Comment $comment): array
    {
        $author = $comment->user;
        $post = $comment->post;
        $finalized = $comment->isModerationRemovalFinalized();
        $visible = ! $comment->trashed() && $comment->status === CommentStatus::Visible;
        $canFinalize = $this->moderator()->can('finalizeRemoval', $comment);
        $authorUrl = $author !== null && UserResource::canEdit($author) ? UserResource::getUrl('edit', ['record' => $author]) : null;
        $thread = $visible && $post !== null && ! $post->trashed() && $post->status === PostStatus::Published ? route('posts.show', $post).'#comment-'.$comment->id : null;
        $postUrl = $post !== null && PostResource::canAccess() ? ListPosts::getUrl(['status' => 'all', 'q' => '#'.$post->id]) : null;
        $finalize = match (true) {
            ! $canFinalize || $finalized => null,
            $comment->status === CommentStatus::Hidden => 'available',
            $visible => 'hide first',
            default => null,
        };

        return [
            'id' => (int) $comment->id,
            'text' => $comment->body,
            'thread' => $thread,
            'muted' => ! $visible,
            'readOnly' => match (true) {
                $comment->trashed() => 'Read-only · kept for audit',
                $finalized => 'Read-only',
                default => null,
            },
            'author' => $author === null ? null : [
                'name' => (string) ($author->username ?? $author->name),
                'initials' => ModerationListFormat::initials((string) ($author->username ?? $author->name)),
                'url' => $authorUrl,
            ],
            'post' => $post === null ? null : ['title' => $post->title, 'id' => (int) $post->id],
            'postUrl' => $postUrl,
            'reports' => (int) $comment->reports_count,
            'state' => self::state($comment),
            'created' => $comment->created_at !== null ? ModerationListFormat::when($comment->created_at) : '—',
            'hide' => $visible && $this->moderator()->can('hide', $comment),
            'restore' => $comment->isModeratorHidden() && ! $finalized && $this->moderator()->can('restore', $comment),
            'final' => $finalized,
            'finalize' => $finalize,
            'menu' => $thread !== null || $authorUrl !== null || $postUrl !== null || $finalize !== null,
        ];
    }

    /**
     * The confirmation on screen, with the comment it is about as it is now.
     *
     * @return array{action: string, id: int, text: string, meta: string}|null
     */
    private function confirmation(): ?array
    {
        $comment = $this->confirming !== null ? app(ModerationCommentsQuery::class)->find($this->confirming['id']) : null;

        if ($comment === null) {
            return null;
        }

        return [
            'action' => $this->confirming['action'],
            'id' => (int) $comment->id,
            'text' => $comment->body,
            'meta' => implode(' · ', array_filter([
                '#'.$comment->id,
                $comment->user?->username,
                $this->confirming['action'] === 'finalize'
                    ? self::state($comment)['label']
                    : ($comment->post !== null ? 'on '.Str::limit($comment->post->title, 42, '…') : null),
            ])),
        ];
    }

    /**
     * The badge a comment's state shows, and the note under it. Author
     * deletion reads as exactly that, even when moderation hid the comment
     * first; a finalized removal reads as final either way.
     *
     * @return array{label: string, tone: string, dot: bool, note: ?string}
     */
    private static function state(Comment $comment): array
    {
        return match (true) {
            $comment->isModerationRemovalFinalized() => ['label' => 'Removal finalized', 'tone' => 'neutral', 'dot' => false, 'note' => 'Retained internally'],
            $comment->isAuthorDeleted() => ['label' => 'Deleted by author', 'tone' => 'outline', 'dot' => false, 'note' => null],
            $comment->isModeratorHidden() => ['label' => 'Hidden by moderation', 'tone' => 'neutral', 'dot' => false, 'note' => 'Restorable'],
            default => ['label' => 'Visible', 'tone' => 'success', 'dot' => true, 'note' => null],
        };
    }

    private function ask(string $action, int $id): void
    {
        $this->confirming = ['action' => $action, 'id' => $id];
        $this->reason = '';
        $this->acknowledged = false;
        $this->resetErrorBag();
    }

    /**
     * Runs one moderation action, and says in a toast why it did not happen:
     * someone else moderated the comment meanwhile, or this moderator may not.
     */
    private function attempt(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (CannotHideCommentException|CannotRestoreCommentException|CannotFinalizeRemovalException) {
            $this->changedMeanwhile();

            return false;
        }
    }

    private function changedMeanwhile(): void
    {
        $this->toast('This comment changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');
    }

    /** @param array{label: string, event: string, params: array<string, mixed>}|null $action */
    private function toast(string $message, ?array $action = null, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    private function moderator(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->status = array_key_exists((string) $this->status, self::TABS) ? (string) $this->status : 'all';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
    }
}
