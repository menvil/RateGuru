<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Reports\IgnoreReportAction;
use App\Actions\Reports\ReopenReportAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Exceptions\Reports\CannotIgnoreReportException;
use App\Exceptions\Reports\CannotReopenReportException;
use App\Exceptions\Reports\CannotResolveReportException;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Queries\Reports\ModerationReportsQuery;
use Carbon\CarbonInterface;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Reports in Admin v2: each report points to one post, comment or person.
 * The list, a tab at a time, searched and narrowed to a kind of target on
 * the server; and the selected report's chain (DOM-02) beside it — the
 * report, its target, the target's author — docked where the window leaves
 * room, over the list where it does not.
 *
 * Acting on the content and closing the report are separate steps: Hide
 * target and Ban author leave the report open, and Resolve or Ignore closes
 * that one report, with Undo, and opens the next open one. A closed report
 * can be reopened. Every change goes through its action.
 */
final class ListReports extends Page
{
    /** The tabs, in order, and their labels. */
    public const TABS = ['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'];

    /** The kinds of target a list is narrowed to, and their labels. */
    public const TYPES = ['all' => 'All', 'post' => 'Posts', 'comment' => 'Comments', 'user' => 'People'];

    /** How long a resolution note or a moderation reason may be. */
    public const NOTE_LIMIT = 500;

    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.pages.reports';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The open tab. Typed loosely: whatever a link puts here reads as a tab. */
    #[Url(except: 'open')]
    public mixed $status = 'open';

    /** The search over the reported content, the reporter, the reason, the message and the report's id. */
    #[Url(except: '')]
    public mixed $q = '';

    /** The kind of target, or every kind. */
    #[Url(except: 'all')]
    public mixed $type = 'all';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** The report whose chain is open, in the address so a link opens it. */
    #[Url(except: null)]
    public mixed $report = null;

    /** The note the open report is closed with. */
    public string $note = '';

    /** The confirmation on screen for the open report: hiding its target or banning the target's author. */
    #[Locked]
    public ?string $confirming = null;

    public string $reason = '';

    public function getTitle(): string
    {
        return 'Reports';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new tab, search or kind of target starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'q', 'type'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
    }

    /** Opens a report's chain. */
    public function select(int $id): void
    {
        if (app(ModerationReportsQuery::class)->find($id) !== null) {
            $this->report = $id;
            $this->note = '';
            $this->resetErrorBag();
        }
    }

    public function closePanel(): void
    {
        $this->report = null;
        $this->note = '';
    }

    /** The report before (-1) or after (1) the open one in the list, or the list's first. */
    public function step(int $direction): void
    {
        $query = app(ModerationReportsQuery::class);
        $current = $this->report !== null ? $query->find((int) $this->report) : null;
        $id = $current !== null
            ? $query->adjacent((string) $this->status, (string) $this->q, $this->kind(), $current, $direction <=> 0)
            : $query->first((string) $this->status, (string) $this->q, $this->kind());

        if ($id !== null) {
            $this->select($id);
        }
    }

    /** Resolves a report — the open one, with its note, or one from the list — and opens the next open one. */
    public function resolve(?int $id = null): void
    {
        $this->close($id, ReportStatus::Resolved);
    }

    /** Ignores a report — the open one, with its note, or one from the list — and opens the next open one. */
    public function ignore(?int $id = null): void
    {
        $this->close($id, ReportStatus::Ignored);
    }

    /** Undo after closing: the report is open again, and its chain with it. */
    #[On('reopen-report')]
    public function reopen(int $id): void
    {
        $report = app(ModerationReportsQuery::class)->find($id);

        if ($report !== null && $this->attempt(fn () => app(ReopenReportAction::class)->handle($this->moderator(), $report))) {
            $this->select($id);
            $this->toast('Report #'.$id.' is open again');
        }
    }

    public function askToHideTarget(): void
    {
        $report = $this->current();

        if ($report !== null && ($report->target instanceof Post || $report->target instanceof Comment) && $this->moderator()->can('hide', $report->target)) {
            $this->confirming = 'hide';
            // The reason the report gives, as a starting point.
            $this->reason = self::reasonLabel($report);
            $this->resetErrorBag();
        }
    }

    public function askToBan(): void
    {
        $author = $this->current() !== null ? self::author($this->current()) : null;

        if ($author !== null && $this->moderator()->can('ban', $author) && $author->status !== UserStatus::Banned) {
            $this->confirming = 'ban';
            $this->reason = '';
            $this->resetErrorBag();
        }
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->reason = '';
        $this->resetErrorBag();
    }

    /** Hides the open report's target, or bans its author; the report stays open either way. */
    public function confirm(): void
    {
        $report = $this->current();

        if ($report === null || $this->confirming === null) {
            return;
        }

        $target = $report->target;
        $hidingPost = $this->confirming === 'hide' && $target instanceof Post;

        $this->validate(
            ['reason' => [$hidingPost ? 'required' : 'nullable', 'string', 'min:3', 'max:'.self::NOTE_LIMIT]],
            [
                'reason.required' => 'Give the reason, or choose one above.',
                'reason.min' => 'Say a little more: at least 3 characters.',
                'reason.max' => 'Keep it to '.number_format(self::NOTE_LIMIT).' characters or fewer.',
            ],
        );

        $reason = trim($this->reason) !== '' ? trim($this->reason) : null;
        $action = $this->confirming;
        $this->closeConfirmation();

        if ($action === 'hide') {
            // Hiding a comment already hidden does nothing, quietly: say so.
            if ($target instanceof Comment && ($target->trashed() || $target->status !== CommentStatus::Visible)) {
                $this->changedMeanwhile();

                return;
            }

            $hidden = $this->attempt(fn () => match (true) {
                $target instanceof Post => app(HidePostAction::class)->handle($this->moderator(), $target, $reason),
                $target instanceof Comment => app(HideCommentAction::class)->handle($this->moderator(), $target, $reason),
                default => throw CannotModeratePostException::becausePostStatusIsInvalid(),
            });

            if ($hidden) {
                $open = (int) (app(ModerationReportsQuery::class)->find($report->id)?->getAttribute('open_on_target') ?? 0);
                $this->toast(($target instanceof Post ? 'Post' : 'Comment').' #'.$target->getKey().' hidden. '.$open.' open '.Str::plural('report', $open).' '.($open === 1 ? 'remains' : 'remain').' on it.');
            }

            return;
        }

        $author = self::author($report);

        if ($author !== null && $this->attempt(fn () => app(BanUserAction::class)->handle($this->moderator(), $author, $reason))) {
            $this->toast($author->username.' banned. Report #'.$report->id.' is still open.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(ModerationReportsQuery::class);
        $counts = $query->counts();
        $paginator = $query->paginate((string) $this->status, (string) $this->q, $this->kind(), (int) $this->pageNumber);
        $oldest = $query->oldestOpenAt();
        $current = $this->current();

        return [
            'tabs' => collect(self::TABS)->map(fn (string $label, string $id): array => [
                'id' => $id,
                'label' => $label,
                'count' => number_format($counts[$id]),
                'href' => ReportResource::getUrl('index', $id === 'open' ? [] : ['status' => $id]),
                'attributes' => ['wire:click.prevent' => "\$set('status', '{$id}')"],
            ])->values()->all(),
            'stats' => [
                'open' => number_format($counts['open']),
                'targets' => number_format($query->openTargets()),
                'oldest' => $oldest !== null ? ModerationListFormat::age($oldest) : '—',
            ],
            'types' => collect(self::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'paginator' => $paginator,
            'rows' => array_map(fn (Report $report): array => $this->row($report), $paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $this->type !== 'all',
            'detail' => $current !== null ? $this->detail($current) : null,
            'reasons' => ['Spam', 'Offensive', 'Fake', 'Copyright', 'Wrong category'],
            'banReasons' => ['Repeated spam', 'Harassment', 'Scam', 'Ban evasion'],
        ];
    }

    /**
     * One report as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(Report $report): array
    {
        $target = self::target($report);
        $open = $report->status === ReportStatus::Open;
        $siblings = (int) $report->getAttribute('open_on_target');

        return [
            'id' => (int) $report->id,
            'kind' => $target['kind'],
            'icon' => $target['icon'],
            'preview' => $target['preview'],
            'meta' => implode(' · ', array_filter([$target['id'], $target['state']['label'] ?? null, $open && $siblings > 1 ? $siblings.' open reports' : null])),
            'reason' => self::reasonLabel($report),
            'reporter' => $report->reporter->username ?? '—',
            'status' => self::status($report),
            'created' => self::short($report->created_at),
            'open' => $open,
            'selected' => (int) $this->report === (int) $report->id,
        ];
    }

    /**
     * The open report's chain: the report, its target, the target's author,
     * and how it was closed.
     *
     * @return array<string, mixed>
     */
    private function detail(Report $report): array
    {
        $target = self::target($report);
        $author = self::author($report);
        $open = $report->status === ReportStatus::Open;
        $siblings = (int) $report->getAttribute('open_on_target');
        $entity = $report->target;
        $posts = $author?->posts()->count() ?? 0;

        return [
            'id' => (int) $report->id,
            'created' => $report->created_at !== null ? self::full($report->created_at) : '—',
            'short' => self::short($report->created_at),
            'status' => self::status($report),
            'open' => $open,
            'reason' => 'Reported as '.Str::lower(self::reasonLabel($report)),
            'reporter' => $report->reporter !== null ? ['name' => $report->reporter->username, 'initials' => ModerationListFormat::initials((string) $report->reporter->username)] : null,
            'message' => filled($report->message) ? (string) $report->message : null,
            'siblings' => $open && $siblings > 1 ? $siblings.' open reports point to this '.Str::lower($target['kind']).'. Each one is closed separately.' : null,
            'target' => [
                ...$target,
                'title' => $entity instanceof User ? 'Person · '.$entity->username : $target['kind'].' '.$target['id'],
                'canHide' => ($entity instanceof Post || $entity instanceof Comment) && $this->moderator()->can('hide', $entity),
                'hiddenNotice' => $open && (($entity instanceof Post && $entity->status === PostStatus::Hidden) || ($entity instanceof Comment && $entity->status === CommentStatus::Hidden)),
            ],
            'author' => $author === null ? null : [
                'name' => $author->username,
                'initials' => ModerationListFormat::initials((string) $author->username),
                'status' => ModerationListFormat::personStatus($author),
                'trust' => ModerationListFormat::trust($author),
                'stats' => number_format($posts).' '.Str::plural('post', $posts).' · '.number_format(app(ModerationReportsQuery::class)->reportsOnContentOf($author)).' reports on their content',
                'url' => UserResource::canEdit($author) ? UserResource::getUrl('edit', ['record' => $author]) : null,
                'ban' => match (true) {
                    $author->status === UserStatus::Banned => 'Already banned',
                    $author->status === UserStatus::Deleted || $author->isTombstoned() => 'Account deleted',
                    ! $this->moderator()->can('ban', $author) => 'Administrators only',
                    default => null,
                },
            ],
            'closed' => $open ? null : [
                'title' => ($report->status === ReportStatus::Resolved ? 'Resolved' : 'Ignored').($report->resolver !== null ? ' by '.$report->resolver->name : '').($report->resolved_at !== null ? ' · '.ModerationListFormat::when($report->resolved_at) : ''),
                'note' => filled($report->resolution_note) ? (string) $report->resolution_note : ($report->status === ReportStatus::Resolved ? 'Resolved without a note.' : 'Ignored without a note.'),
            ],
            'confirmation' => $this->confirming,
        ];
    }

    private function close(?int $id, ReportStatus $status): void
    {
        $fromPanel = $id === null;
        $query = app(ModerationReportsQuery::class);
        $report = $query->find($id ?? (int) $this->report);

        if ($report === null) {
            return;
        }

        if ($fromPanel) {
            $this->validate(['note' => ['nullable', 'string', 'max:'.self::NOTE_LIMIT]], ['note.max' => 'Keep it to '.number_format(self::NOTE_LIMIT).' characters or fewer.']);
        }

        if ($report->status !== ReportStatus::Open) {
            $this->changedMeanwhile();

            return;
        }

        $note = $fromPanel && trim($this->note) !== '' ? trim($this->note) : null;
        $closed = $this->attempt(fn () => $status === ReportStatus::Resolved
            ? app(ResolveReportAction::class)->handle($this->moderator(), $report, $note)
            : app(IgnoreReportAction::class)->handle($this->moderator(), $report, $note));

        if (! $closed) {
            return;
        }

        // The next open report opens: the one after where this one stood, or the first.
        if ((int) $this->report === (int) $report->id) {
            $this->note = '';
            $this->report = $query->adjacent('open', (string) $this->q, $this->kind(), $report, 1) ?? $query->first('open', (string) $this->q, $this->kind()) ?? $report->id;
        }

        $this->toast(
            'Report #'.$report->id.($status === ReportStatus::Resolved ? ' resolved' : ' ignored'),
            ['label' => 'Undo', 'event' => 'reopen-report', 'params' => ['id' => (int) $report->id]],
        );
    }

    private function current(): ?Report
    {
        return $this->report !== null ? app(ModerationReportsQuery::class)->find((int) $this->report) : null;
    }

    /**
     * What a report points to, as the list and the chain show it.
     *
     * @return array{kind: string, icon: string, id: string, preview: string, meta: ?string, state: ?array{label: string, tone: string, dot: bool}, url: ?string}
     */
    private static function target(Report $report): array
    {
        $target = $report->target;

        return match (true) {
            $target instanceof Post => [
                'kind' => 'Post',
                'icon' => 'image',
                'id' => '#'.$target->id,
                'preview' => $target->title,
                'meta' => implode(' · ', array_filter([$target->category?->name, $target->created_at !== null ? ModerationListFormat::when($target->created_at) : null, $target->status === PostStatus::Pending ? 'waiting for moderation' : null])),
                'state' => self::postState($target),
                'url' => ! $target->trashed() && $target->status === PostStatus::Published ? route('posts.show', $target) : null,
            ],
            $target instanceof Comment => [
                'kind' => 'Comment',
                'icon' => 'message-square',
                'id' => '#'.$target->id,
                'preview' => $target->body,
                'meta' => $target->post !== null ? 'Comment on “'.Str::limit($target->post->title, 48, '…').'”' : null,
                'state' => self::commentState($target),
                'url' => ! $target->trashed() && $target->status === CommentStatus::Visible && $target->post !== null && ! $target->post->trashed() && $target->post->status === PostStatus::Published
                    ? route('posts.show', $target->post).'#comment-'.$target->id
                    : null,
            ],
            $target instanceof User => [
                'kind' => 'Person',
                'icon' => 'user',
                'id' => '@'.$target->username,
                'preview' => (string) $target->username,
                'meta' => $target->created_at !== null ? 'Joined '.$target->created_at->format('d M Y') : null,
                'state' => ModerationListFormat::personStatus($target),
                'url' => ! $target->isTombstoned() ? route('profile.show', $target->username) : null,
            ],
            default => ['kind' => 'Content', 'icon' => 'circle-x', 'id' => '#'.$report->target_id, 'preview' => 'Target unavailable', 'meta' => null, 'state' => null, 'url' => null],
        };
    }

    /** Who a report is ultimately about: the author of the post or comment, or the person reported. */
    private static function author(Report $report): ?User
    {
        $target = $report->target;

        return match (true) {
            $target instanceof Post, $target instanceof Comment => $target->user,
            $target instanceof User => $target,
            default => null,
        };
    }

    /** @return array{label: string, tone: string, dot: bool} */
    private static function postState(Post $post): array
    {
        return match (true) {
            $post->isAuthorDeleted() => ['label' => 'Deleted by author', 'tone' => 'outline', 'dot' => false],
            $post->isModerationRemovalFinalized() => ['label' => 'Removal finalized', 'tone' => 'neutral', 'dot' => false],
            $post->status === PostStatus::Published => ['label' => 'Published', 'tone' => 'success', 'dot' => true],
            $post->status === PostStatus::Pending => ['label' => 'Pending', 'tone' => 'warning', 'dot' => false],
            $post->status === PostStatus::Hidden => ['label' => 'Hidden', 'tone' => 'neutral', 'dot' => false],
            $post->status === PostStatus::Rejected => ['label' => 'Rejected', 'tone' => 'danger', 'dot' => false],
            default => ['label' => Str::ucfirst($post->status->value), 'tone' => 'outline', 'dot' => false],
        };
    }

    /** @return array{label: string, tone: string, dot: bool} */
    private static function commentState(Comment $comment): array
    {
        return match (true) {
            $comment->isModerationRemovalFinalized() => ['label' => 'Removal finalized', 'tone' => 'neutral', 'dot' => false],
            $comment->isAuthorDeleted() => ['label' => 'Deleted by author', 'tone' => 'outline', 'dot' => false],
            $comment->isModeratorHidden() => ['label' => 'Hidden', 'tone' => 'neutral', 'dot' => false],
            default => ['label' => 'Visible', 'tone' => 'success', 'dot' => true],
        };
    }

    /** @return array{label: string, tone: string} */
    private static function status(Report $report): array
    {
        return match ($report->status) {
            ReportStatus::Open => ['label' => 'Open', 'tone' => 'warning'],
            ReportStatus::Resolved => ['label' => 'Resolved', 'tone' => 'success'],
            ReportStatus::Ignored => ['label' => 'Ignored', 'tone' => 'neutral'],
        };
    }

    private static function reasonLabel(Report $report): string
    {
        return Str::ucfirst(str_replace('_', ' ', $report->reason->value));
    }

    /** “09:12” today, “Yesterday”, “02 Oct”, or with the year when it is not this one. */
    private static function short(?CarbonInterface $at): string
    {
        return match (true) {
            $at === null => '—',
            $at->isToday() => $at->format('H:i'),
            $at->isYesterday() => 'Yesterday',
            $at->isCurrentYear() => $at->format('d M'),
            default => $at->format('d M Y'),
        };
    }

    /** “Today · 06:02”, “Yesterday · 19:58”, “02 Oct 2026 · 22:58”. */
    private static function full(CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'Today · '.$at->format('H:i'),
            $at->isYesterday() => 'Yesterday · '.$at->format('H:i'),
            default => $at->format('d M Y · H:i'),
        };
    }

    /**
     * Runs one action, and says in a toast why it did not happen: someone
     * changed the report or its target meanwhile, or this moderator may not.
     */
    private function attempt(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (CannotResolveReportException|CannotIgnoreReportException|CannotReopenReportException|CannotModeratePostException|CannotHideCommentException|CannotModerateUserException) {
            $this->changedMeanwhile();

            return false;
        }
    }

    private function changedMeanwhile(): void
    {
        $this->toast('This changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');
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

    private function kind(): ?string
    {
        return $this->type === 'all' ? null : (string) $this->type;
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->status = array_key_exists((string) $this->status, self::TABS) ? (string) $this->status : 'open';
        $this->type = array_key_exists((string) $this->type, self::TYPES) ? (string) $this->type : 'all';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
        $this->report = is_numeric($this->report) && app(ModerationReportsQuery::class)->find((int) $this->report) !== null ? (int) $this->report : null;
    }
}
