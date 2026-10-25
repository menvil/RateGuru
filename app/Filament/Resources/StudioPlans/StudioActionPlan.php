<?php

namespace App\Filament\Resources\StudioPlans;

use App\Data\Studio\PlannedStudioAction;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\Comment;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Studio\StudioActionsQuery;
use App\Support\Studio\StudioPlanSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * A plan's Action plan as the Publication step shows it: every action of the
 * plan with when it happens, the part of the plan it belongs to, what it does
 * and after what, who does it, when it runs and how it stands — over a
 * timeline of the post's part and the discussion's.
 *
 * Before approval it is a preview: exactly the set approval would write now
 * (StudioPlanSchedule), drawn from the saved plan, with nothing written and
 * nothing sent. Once approved it is the plan's own rows of the business queue,
 * as they stand. Either way 25 a page, narrowed to the post's part or the
 * discussion's on the server.
 */
final class StudioActionPlan
{
    /** The parts of a plan an Action plan can be narrowed to, and the kinds of action each holds. */
    public const PARTS = [
        'post' => [StudioActionType::CreatePost, StudioActionType::VotePost, StudioActionType::VoteRating],
        'discussion' => [StudioActionType::CreateComment, StudioActionType::CreateReply, StudioActionType::VoteComment],
    ];

    /** How long a comment's text runs in a row before it is cut. */
    private const EXCERPT = 70;

    /** The most marks a lane draws: actions planned close together share one. */
    private const MARK_STEPS = 200;

    public function __construct(
        private readonly StudioPlanSchedule $schedule,
        private readonly StudioActionsQuery $actions,
    ) {}

    /**
     * What approving the plan now would write, and how it would run: the
     * figures the Publication step, its overdue notice and the confirmation
     * show.
     *
     * @return array{total: int, overdue: int, future: int, comments: int, replies: int, postUp: int, postDown: int, answers: int, postVoters: int, commentVotes: int, commentVoters: int, past: bool, editorial: ?string}
     */
    public function summary(StudioPlan $plan, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC')->startOfSecond();
        $planned = $this->schedule->of($plan, $now);
        $count = fn (callable $matching): int => count(array_filter($planned, $matching));
        $voters = fn (StudioActionType ...$types): int => count(array_unique(array_map(
            fn (PlannedStudioAction $item): ?int => $item->actorId,
            array_filter($planned, fn (PlannedStudioAction $item): bool => in_array($item->type, $types, true)),
        )));
        $way = fn (StudioActionType $type, string $way): int => $count(fn (PlannedStudioAction $item): bool => $item->type === $type && ($item->payload['vote_type'] ?? null) === $way);
        $post = $planned[0];

        return [
            'total' => count($planned),
            'overdue' => $count(fn (PlannedStudioAction $item): bool => self::overdue($plan, $item->key, $item->occursAt, $now)),
            'future' => $count(fn (PlannedStudioAction $item): bool => $item->runAfter->greaterThan($now)),
            'comments' => $count(fn (PlannedStudioAction $item): bool => $item->type === StudioActionType::CreateComment),
            'replies' => $count(fn (PlannedStudioAction $item): bool => $item->type === StudioActionType::CreateReply),
            'postUp' => $way(StudioActionType::VotePost, 'up'),
            'postDown' => $way(StudioActionType::VotePost, 'down'),
            'answers' => $count(fn (PlannedStudioAction $item): bool => $item->type === StudioActionType::VoteRating),
            'postVoters' => $voters(StudioActionType::VotePost, StudioActionType::VoteRating),
            'commentVotes' => $count(fn (PlannedStudioAction $item): bool => $item->type === StudioActionType::VoteComment),
            'commentVoters' => $voters(StudioActionType::VoteComment),
            'past' => $plan->publication_mode === StudioPlanPublicationMode::AtDate && $post->occursAt->lessThan($now),
            'editorial' => $plan->publication_mode === StudioPlanPublicationMode::AtDate && $plan->publish_at !== null ? self::at($post->occursAt) : null,
        ];
    }

    /**
     * The preview of a Draft: what approving it now would write.
     *
     * @return array<string, mixed>
     */
    public function preview(StudioPlan $plan, string $part, int $page): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $planned = $this->schedule->of($plan, $now);
        $labels = $this->labels($plan, array_map(fn (PlannedStudioAction $item): array => ['actorId' => $item->actorId, 'payload' => $item->payload], $planned));
        $order = array_flip(array_map(fn (PlannedStudioAction $item): string => $item->key, $planned));

        // By when each happens, then as approval writes them.
        usort($planned, fn (PlannedStudioAction $a, PlannedStudioAction $b): int => [$a->occursAt->getTimestamp(), $order[$a->key]] <=> [$b->occursAt->getTimestamp(), $order[$b->key]]);

        $timeline = array_map(fn (PlannedStudioAction $item): array => [
            'part' => self::partOf($item->type),
            'at' => $item->occursAt,
            'tone' => self::overdue($plan, $item->key, $item->occursAt, $now) ? 'overdue' : ($item->runAfter->greaterThan($now) ? 'scheduled' : 'ready'),
            'title' => self::at($item->occursAt).' · '.$this->what($item->type, $item->payload, $labels)['action'],
        ], $planned);

        $shown = array_values(array_filter($planned, fn (PlannedStudioAction $item): bool => $part === 'all' || self::partOf($item->type) === $part));
        [$page, $paginator] = self::page($shown, $page);

        $rows = array_map(function (PlannedStudioAction $item) use ($plan, $labels, $now): array {
            $what = $this->what($item->type, $item->payload, $labels);
            $ready = ! $item->runAfter->greaterThan($now);

            return [
                'key' => $item->key,
                'planned' => self::at($item->occursAt),
                'overdue' => self::overdue($plan, $item->key, $item->occursAt, $now),
                ...self::part($item->type),
                ...$what,
                'after' => $this->after($plan, $item->key, $item->type, $item->payload, $labels, $item->occursAt, $now),
                'executor' => $this->executor($item->actorId, $labels),
                'runs' => $ready ? 'On approval' : self::at($item->runAfter),
                'runsNote' => null,
                'state' => $ready ? ['label' => 'Ready to run', 'tone' => 'info', 'dot' => false] : ['label' => 'Scheduled', 'tone' => 'neutral', 'dot' => false],
                'reason' => null,
                'links' => [],
            ];
        }, $paginator->items());

        $overdue = count(array_filter($timeline, fn (array $mark): bool => $mark['tone'] === 'overdue'));

        return [
            'preview' => true,
            'meta' => 'Calculated '.self::at($now).' · from the post’s date and the times set on the Post and Discussion steps',
            'stats' => [
                ['label' => 'Total actions', 'value' => count($planned), 'tone' => null],
                ['label' => 'Overdue', 'value' => $overdue, 'tone' => $overdue > 0 ? 'due' : null],
                ['label' => 'In the future', 'value' => count(array_filter($timeline, fn (array $mark): bool => $mark['tone'] === 'scheduled')), 'tone' => null],
            ],
            'legend' => [['label' => 'Scheduled', 'tone' => 'scheduled'], ['label' => 'Ready to run', 'tone' => 'ready'], ['label' => 'Overdue', 'tone' => 'overdue']],
            ...$this->timeline($timeline, $now),
            'parts' => self::parts($planned, $part, fn (PlannedStudioAction $item): StudioActionType => $item->type),
            'part' => $part,
            'rows' => $rows,
            'paginator' => $paginator,
            'note' => 'Preview of the plan, from the saved draft. Nothing is queued until the publication is approved; the queue then records when each action really ran.',
        ];
    }

    /**
     * An approved plan's own actions, as they stand.
     *
     * @return array<string, mixed>
     */
    public function actual(StudioPlan $plan, string $part, int $page): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $all = $this->actions->timelineOf($plan->id);
        $types = $part === 'all' ? null : self::PARTS[$part];
        $paginator = $this->actions->forPlan($plan->id, $types, $page);

        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $this->actions->forPlan($plan->id, $types, $paginator->lastPage());
        }

        /** @var list<StudioAction> $shown */
        $shown = $paginator->items();
        $labels = $this->labels($plan, array_map(fn (StudioAction $action): array => ['actorId' => $action->actor_user_id, 'payload' => $action->payload], $shown));
        $byId = $all->keyBy('id');

        $timeline = $all->map(fn (StudioAction $action): array => [
            'part' => self::partOf($action->type),
            'at' => $action->occurs_at,
            'tone' => self::tone($action, $now),
            'title' => self::at($action->occurs_at).' · '.$action->status->name,
        ])->values()->all();

        $rows = array_map(function (StudioAction $action) use ($plan, $labels, $now, $byId): array {
            $failure = StudioActionFailure::tryFrom((string) $action->failure_code);
            $parent = $action->parent_action_id !== null ? $byId->get($action->parent_action_id) : null;

            return [
                'key' => 'action:'.$action->id,
                'id' => (int) $action->id,
                'planned' => self::at($action->occurs_at),
                'overdue' => $action->status === StudioActionStatus::Pending && $action->occurs_at->lessThan($now),
                ...self::part($action->type),
                ...$this->what($action->type, $action->payload, $labels),
                'after' => $this->after($plan, $action->type === StudioActionType::CreatePost ? 'post' : 'action', $action->type, $action->payload, $labels, $action->occurs_at, $now)
                    .($parent !== null ? ' · #'.$parent->id : ''),
                'executor' => $this->executor($action->actor_user_id, $labels),
                ...self::runs($action, $now),
                'state' => self::state($action->status),
                'reason' => $failure !== null && $action->status !== StudioActionStatus::Done ? $failure->description() : null,
                'links' => self::links($action),
            ];
        }, $shown);

        $count = fn (StudioActionStatus ...$statuses): int => $all->filter(fn (StudioAction $action): bool => in_array($action->status, $statuses, true))->count();

        return [
            'preview' => false,
            'meta' => 'As it stands '.self::at($now).' · each action’s planned time, and when it really ran',
            'stats' => [
                ['label' => 'Done', 'value' => $count(StudioActionStatus::Done), 'tone' => null],
                ['label' => 'Waiting', 'value' => $count(StudioActionStatus::Pending, StudioActionStatus::Dispatched, StudioActionStatus::Running), 'tone' => null],
                ['label' => 'Need attention', 'value' => $count(StudioActionStatus::Held, StudioActionStatus::Failed), 'tone' => $count(StudioActionStatus::Held, StudioActionStatus::Failed) > 0 ? 'danger' : null],
                ['label' => 'Cancelled', 'value' => $count(StudioActionStatus::Cancelled), 'tone' => null],
            ],
            'legend' => [['label' => 'Pending', 'tone' => 'scheduled'], ['label' => 'Overdue', 'tone' => 'overdue'], ['label' => 'Running', 'tone' => 'ready'], ['label' => 'Done', 'tone' => 'done'], ['label' => 'Held or failed', 'tone' => 'error'], ['label' => 'Cancelled', 'tone' => 'cancelled']],
            ...$this->timeline($timeline, $now),
            'parts' => self::parts($all->all(), $part, fn (StudioAction $action): StudioActionType => $action->type),
            'part' => $part,
            'rows' => $rows,
            'paginator' => $paginator,
            'note' => 'Planned times are the plan’s own — a past date stays the date of what is written. When each action really ran is kept apart.',
            'active' => $count(StudioActionStatus::Pending, StudioActionStatus::Dispatched, StudioActionStatus::Running) > 0,
        ];
    }

    /** A moment as the Action plan and the Action queue show it, in the application's time zone. */
    public static function at(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone((string) config('app.timezone'))->format('d M Y, H:i');
    }

    /** The part of the plan an action belongs to: the post's — itself, its votes and its rating answers — or the discussion's. */
    public static function partOf(StudioActionType $type): string
    {
        return in_array($type, self::PARTS['post'], true) ? 'post' : 'discussion';
    }

    /**
     * An action's state as a badge shows it — the business queue's own
     * states, by their own names.
     *
     * @return array{label: string, tone: string, dot: bool}
     */
    public static function state(StudioActionStatus $status): array
    {
        return match ($status) {
            StudioActionStatus::Pending => ['label' => 'Pending', 'tone' => 'outline', 'dot' => false],
            StudioActionStatus::Held => ['label' => 'Held', 'tone' => 'warning', 'dot' => false],
            StudioActionStatus::Dispatched => ['label' => 'Dispatched', 'tone' => 'info', 'dot' => false],
            StudioActionStatus::Running => ['label' => 'Running', 'tone' => 'info', 'dot' => true],
            StudioActionStatus::Done => ['label' => 'Done', 'tone' => 'success', 'dot' => false],
            StudioActionStatus::Failed => ['label' => 'Failed', 'tone' => 'danger', 'dot' => false],
            StudioActionStatus::Cancelled => ['label' => 'Cancelled', 'tone' => 'neutral', 'dot' => false],
        };
    }

    /**
     * When an action runs, or ran, and what is worth saying about it.
     *
     * @return array{runs: string, runsNote: ?string}
     */
    public static function runs(StudioAction $action, CarbonImmutable $now): array
    {
        return match ($action->status) {
            StudioActionStatus::Pending => [
                'runs' => $action->run_after->greaterThan($now) ? self::at($action->run_after) : 'Due now',
                'runsNote' => $action->deferrals > 0 ? 'Held back by its persona’s limit '.$action->deferrals.' '.Str::plural('time', $action->deferrals) : null,
            ],
            StudioActionStatus::Held => ['runs' => 'Waits to be resumed', 'runsNote' => null],
            StudioActionStatus::Dispatched => ['runs' => 'Sent '.self::at($action->dispatched_at ?? $now), 'runsNote' => 'Attempt '.$action->attempts],
            StudioActionStatus::Running => ['runs' => 'Since '.self::at($action->claimed_at ?? $now), 'runsNote' => 'Attempt '.$action->attempts],
            StudioActionStatus::Done => ['runs' => 'Ran '.self::at($action->finished_at ?? $now), 'runsNote' => null],
            StudioActionStatus::Failed => ['runs' => 'Failed '.self::at($action->finished_at ?? $now), 'runsNote' => 'After '.$action->attempts.' '.Str::plural('attempt', $action->attempts)],
            StudioActionStatus::Cancelled => ['runs' => 'Not run', 'runsNote' => null],
        };
    }

    /**
     * Where what an action wrote can be seen on the site: the post, or the
     * comment on it — while the site shows it.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function links(StudioAction $action): array
    {
        if ($action->status !== StudioActionStatus::Done || $action->result_id === null) {
            return [];
        }

        return match ($action->type) {
            StudioActionType::CreatePost => ($post = Post::query()->find($action->result_id)) !== null && $post->canReceiveComments()
                ? [['label' => 'Open post', 'url' => route('posts.show', $post)]]
                : [],
            StudioActionType::CreateComment, StudioActionType::CreateReply => ($comment = Comment::query()->with('post')->find($action->result_id)) !== null
                && $comment->post !== null && $comment->post->canReceiveComments() && $comment->canReceiveVotes()
                ? [['label' => 'Open comment', 'url' => route('posts.show', $comment->post).'#comment-'.$comment->id]]
                : [],
            default => [],
        };
    }

    /**
     * The persona's handles, the comments' labels and the rating questions'
     * and options' names the rows need, each read once.
     *
     * @param  list<array{actorId: ?int, payload: array<string, mixed>}>  $items
     * @return array{users: array<int, User>, nodes: array<int, string>, bodies: array<int, string>, parents: array<int, int>, groups: array<int, string>, options: array<int, string>}
     */
    private function labels(StudioPlan $plan, array $items): array
    {
        $nodes = StudioDiscussionNode::query()->where('studio_plan_id', $plan->id)->get(['id', 'parent_id', 'offset_seconds', 'sort_order', 'body']);
        $ordered = StudioPlanDiscussionEditor::ordered($nodes->map(fn (StudioDiscussionNode $node): array => [
            'id' => (int) $node->id,
            'parentId' => $node->parent_id !== null ? (int) $node->parent_id : null,
            'offsetSeconds' => (int) $node->offset_seconds,
            'sortOrder' => (int) $node->sort_order,
        ])->all());
        $ids = fn (string $field): array => array_values(array_unique(array_filter(array_map(fn (array $item): mixed => $item['payload'][$field] ?? null, $items), is_int(...))));

        return [
            'users' => User::query()->whereKey(array_values(array_unique(array_filter(array_map(fn (array $item): ?int => $item['actorId'], $items), is_int(...)))))->get()->keyBy('id')->all(),
            'nodes' => StudioPlanDiscussionEditor::labels($ordered),
            'bodies' => $nodes->mapWithKeys(fn (StudioDiscussionNode $node): array => [(int) $node->id => (string) $node->body])->all(),
            'parents' => $nodes->whereNotNull('parent_id')->mapWithKeys(fn (StudioDiscussionNode $node): array => [(int) $node->id => (int) $node->parent_id])->all(),
            'groups' => RatingGroup::query()->whereKey($ids('rating_group_id'))->get()->mapWithKeys(fn (RatingGroup $group): array => [(int) $group->id => $group->translatedLabel()])->all(),
            'options' => RatingOption::query()->whereKey($ids('rating_option_id'))->get()->mapWithKeys(fn (RatingOption $option): array => [(int) $option->id => $option->translatedLabel()])->all(),
        ];
    }

    /**
     * What an action does, in words: its icon, a line and the detail under it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{users: array<int, User>, nodes: array<int, string>, bodies: array<int, string>, parents: array<int, int>, groups: array<int, string>, options: array<int, string>}  $labels
     * @return array{icon: string, action: string, detail: ?string, strong: bool}
     */
    private function what(StudioActionType $type, array $payload, array $labels): array
    {
        $node = is_int($payload['discussion_node_id'] ?? null) ? $payload['discussion_node_id'] : null;
        $label = $node !== null ? ($labels['nodes'][$node] ?? 'a removed comment') : null;
        $up = ($payload['vote_type'] ?? null) === 'up';
        $excerpt = fn (?string $body): ?string => $body !== null ? Str::limit(trim($body), self::EXCERPT) : null;

        return match ($type) {
            StudioActionType::CreatePost => ['icon' => 'newspaper', 'action' => 'Publish the post', 'detail' => null, 'strong' => true],
            StudioActionType::CreateComment => ['icon' => 'message-square', 'action' => 'Comment '.$label, 'detail' => $excerpt($payload['body'] ?? ($node !== null ? $labels['bodies'][$node] ?? null : null)), 'strong' => false],
            StudioActionType::CreateReply => ['icon' => 'reply', 'action' => 'Reply '.$label, 'detail' => $excerpt($payload['body'] ?? ($node !== null ? $labels['bodies'][$node] ?? null : null)), 'strong' => false],
            StudioActionType::VotePost => ['icon' => $up ? 'thumbs-up' : 'thumbs-down', 'action' => ($up ? 'Upvote' : 'Downvote').' the post', 'detail' => null, 'strong' => false],
            StudioActionType::VoteComment => ['icon' => $up ? 'thumbs-up' : 'thumbs-down', 'action' => ($up ? 'Upvote ' : 'Downvote ').$label, 'detail' => null, 'strong' => false],
            StudioActionType::VoteRating => [
                'icon' => 'star',
                'action' => 'Answer “'.($labels['groups'][(int) ($payload['rating_group_id'] ?? 0)] ?? 'a removed question').'”',
                'detail' => $labels['options'][(int) ($payload['rating_option_id'] ?? 0)] ?? 'A removed option',
                'strong' => false,
            ],
        };
    }

    /**
     * What an action waits for, in words.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{users: array<int, User>, nodes: array<int, string>, bodies: array<int, string>, parents: array<int, int>, groups: array<int, string>, options: array<int, string>}  $labels
     */
    private function after(StudioPlan $plan, string $key, StudioActionType $type, array $payload, array $labels, CarbonImmutable $occursAt, CarbonImmutable $now): string
    {
        if ($type === StudioActionType::CreatePost) {
            return $plan->publication_mode === StudioPlanPublicationMode::AtDate && $occursAt->lessThan($now)
                ? 'Starts the plan · keeps its past date'
                : 'Starts the plan';
        }

        if ($type === StudioActionType::CreateReply) {
            $parent = $labels['parents'][(int) ($payload['discussion_node_id'] ?? 0)] ?? null;

            return 'After '.($parent !== null ? ($labels['nodes'][$parent] ?? 'its comment') : 'its comment');
        }

        if ($type === StudioActionType::VoteComment) {
            return 'After '.($labels['nodes'][(int) ($payload['discussion_node_id'] ?? 0)] ?? 'its comment');
        }

        return 'After the post';
    }

    /** @param  array{users: array<int, User>, nodes: array<int, string>, bodies: array<int, string>, parents: array<int, int>, groups: array<int, string>, options: array<int, string>}  $labels */
    private function executor(?int $actorId, array $labels): string
    {
        $user = $actorId !== null ? ($labels['users'][$actorId] ?? null) : null;

        return match (true) {
            $actorId === null => 'No author selected',
            $user === null || $user->isTombstoned() => User::TOMBSTONE_DISPLAY_NAME,
            default => '@'.$user->username,
        };
    }

    /**
     * Whether an action's moment is past by the time the plan would be
     * approved now — a post published at once never is.
     */
    private static function overdue(StudioPlan $plan, string $key, CarbonImmutable $occursAt, CarbonImmutable $now): bool
    {
        if ($key === 'post' && $plan->publication_mode !== StudioPlanPublicationMode::AtDate) {
            return false;
        }

        return $occursAt->lessThan($now);
    }

    /** How a mark of an approved plan's timeline is drawn. */
    private static function tone(StudioAction $action, CarbonImmutable $now): string
    {
        return match ($action->status) {
            StudioActionStatus::Done => 'done',
            StudioActionStatus::Held, StudioActionStatus::Failed => 'error',
            StudioActionStatus::Cancelled => 'cancelled',
            StudioActionStatus::Dispatched, StudioActionStatus::Running => 'ready',
            StudioActionStatus::Pending => $action->occurs_at->lessThan($now) ? 'overdue' : 'scheduled',
        };
    }

    /**
     * @return array{part: string, partKey: string}
     */
    private static function part(StudioActionType $type): array
    {
        $part = self::partOf($type);

        return ['part' => $part === 'post' ? 'Post' : 'Discussion', 'partKey' => $part];
    }

    /**
     * The post's lane and the discussion's, each mark where its action falls
     * between the first and the last, and where now is when it falls between.
     *
     * @param  list<array{part: string, at: CarbonImmutable, tone: string, title: string}>  $marks
     * @return array{lanes: list<array{label: string, marks: list<array{left: string, tone: string, title: string}>}>, axis: list<string>, now: ?string}
     */
    private function timeline(array $marks, CarbonImmutable $now): array
    {
        if ($marks === []) {
            return ['lanes' => [], 'axis' => [], 'now' => null];
        }

        $times = array_map(fn (array $mark): int => $mark['at']->getTimestamp(), $marks);
        $first = min($times);
        $span = max(1, max($times) - $first);
        // Kept a step in from either end, so a mark at the first or the last moment is drawn whole.
        $position = fn (int $at): float => 0.01 + 0.98 * max(0.0, min(1.0, ($at - $first) / $span));
        $lanes = [];

        foreach (['post' => 'Post', 'discussion' => 'Discussion'] as $part => $label) {
            $drawn = [];

            foreach ($marks as $mark) {
                if ($mark['part'] !== $part) {
                    continue;
                }

                $step = (int) round($position($mark['at']->getTimestamp()) * self::MARK_STEPS);
                $drawn[$step.':'.$mark['tone']] ??= ['left' => number_format($step / self::MARK_STEPS * 100, 2, '.', '').'%', 'tone' => $mark['tone'], 'title' => $mark['title']];
            }

            $count = count(array_filter($marks, fn (array $mark): bool => $mark['part'] === $part));
            $lanes[] = ['label' => $label.' · '.$count, 'marks' => array_values($drawn)];
        }

        $nowAt = $now->getTimestamp();
        $short = fn (int $at): string => CarbonImmutable::createFromTimestamp($at, 'UTC')->setTimezone((string) config('app.timezone'))->format('d M, H:i');

        return [
            'lanes' => $lanes,
            'axis' => [$short($first), $short($first + intdiv($span, 2)), $short($first + $span)],
            'now' => $nowAt > $first && $nowAt < $first + $span ? number_format($position($nowAt) * 100, 2, '.', '').'%' : null,
        ];
    }

    /**
     * All, the post's part and the discussion's, each with how many it holds.
     *
     * @template T
     *
     * @param  array<int, T>  $items
     * @param  callable(T): StudioActionType  $typeOf
     * @return list<array{value: string, label: string}>
     */
    private static function parts(array $items, string $part, callable $typeOf): array
    {
        $count = fn (string $of): int => count(array_filter($items, fn (mixed $item): bool => $of === 'all' || self::partOf($typeOf($item)) === $of));

        return [
            ['value' => 'all', 'label' => 'All · '.$count('all')],
            ['value' => 'post', 'label' => 'Post · '.$count('post')],
            ['value' => 'discussion', 'label' => 'Discussion · '.$count('discussion')],
        ];
    }

    /**
     * One page of a preview — the last there is for a page past the end.
     *
     * @param  list<PlannedStudioAction>  $items
     * @return array{0: int, 1: LengthAwarePaginatorContract<int, PlannedStudioAction>}
     */
    private static function page(array $items, int $page): array
    {
        $last = max(1, (int) ceil(count($items) / StudioActionsQuery::PER_PAGE));
        $page = min(max(1, $page), $last);

        return [$page, new LengthAwarePaginator(
            array_slice($items, ($page - 1) * StudioActionsQuery::PER_PAGE, StudioActionsQuery::PER_PAGE),
            count($items),
            StudioActionsQuery::PER_PAGE,
            $page,
        )];
    }
}
