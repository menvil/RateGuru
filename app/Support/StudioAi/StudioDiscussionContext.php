<?php

namespace App\Support\StudioAi;

use App\Data\Studio\StudioPlanData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Models\Category;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\Tag;
use App\Models\User;

/**
 * What a discussion is written from (docs/architecture/studio-plans.md,
 * "Discussion generation"): the post as the plan holds it now — its final
 * title and description, its category and tags — the stored analysis of its
 * photo, never the photo; the Personas taking part, each with what its
 * profile says of how it writes; and the plan's discussion as it is, so a
 * part written again knows what it answers. Nothing of any other plan.
 *
 * There is a context only once the photo's analysis has finished with a
 * valid description of the plan's photo, and the post has its final title —
 * never one picked from the suggestions on its behalf. A description may be
 * missing, as a post's may.
 *
 * Three hashes say what it was: the analysis's (StudioImageDescription), the
 * post's texts with the discussion — every comment's id, parent, Persona,
 * text, place and time, and the names its authors go by — and the Personas'
 * characters. Everything the model is sent is in one of them. A generation keeps
 * them, and is used only while the plan still has the same.
 */
final readonly class StudioDiscussionContext
{
    /**
     * @param  list<string>  $tags
     * @param  list<StudioDiscussionPersona>  $personas  those taking part, ascending by id, each with its alias
     * @param  list<StudioDiscussionNode>  $nodes  the plan's discussion: each comment on the post, then its replies, each level by its place, then by id
     * @param  array<int, string>  $authorNames  the display name of every Persona the discussion names, by id
     */
    private function __construct(
        public int $imageAssetId,
        public StudioImageDescription $image,
        public string $analysisHash,
        public string $title,
        public ?string $description,
        public ?string $category,
        public array $tags,
        public array $personas,
        public array $nodes,
        public array $authorNames,
        public string $discussionHash,
        public string $personasHash,
    ) {}

    /**
     * The context of the plan as it is now, with these Personas taking part;
     * or why there is none.
     *
     * @param  list<int>  $personaIds
     */
    public static function of(StudioPlan $plan, ?StudioPlanImageAnalysis $analysis, array $personaIds): self|StudioDiscussionGenerationError
    {
        $photo = StudioPostTextContext::of($plan->image_asset_id, $analysis);

        if ($photo === null || ! $photo->complete) {
            return StudioDiscussionGenerationError::AnalysisMissing;
        }

        $title = StudioPlanData::normalizedText($plan->title);

        if ($title === null) {
            return StudioDiscussionGenerationError::IncompletePost;
        }

        sort($personaIds);
        $accounts = User::query()->whereKey($personaIds)->with('personaProfile')->get()->keyBy('id');

        if ($personaIds === [] || $accounts->count() !== count(array_unique($personaIds))) {
            return StudioDiscussionGenerationError::InvalidPersona;
        }

        $personas = [];

        foreach (array_values(array_unique($personaIds)) as $index => $id) {
            $personas[] = StudioDiscussionPersona::of('persona_'.($index + 1), $accounts->get($id));
        }

        $nodes = self::ordered($plan->discussionNodes()->get()->all());
        $authorNames = User::query()
            ->whereKey(array_values(array_unique(array_map(fn (StudioDiscussionNode $node): int => $node->persona_user_id, $nodes))))
            ->get()
            ->mapWithKeys(fn (User $user): array => [(int) $user->getKey() => StudioDiscussionPersona::nameOf($user)])
            ->all();
        ksort($authorNames);
        $description = StudioPlanData::normalizedText($plan->description);
        $tagIds = $plan->tag_ids;
        sort($tagIds);

        return new self(
            imageAssetId: $photo->imageAssetId,
            image: $photo->description,
            analysisHash: $photo->analysisHash,
            title: $title,
            description: $description,
            category: $plan->category_id !== null ? Category::query()->whereKey($plan->category_id)->value('name') : null,
            tags: Tag::query()->whereKey($tagIds)->orderBy('name')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all(),
            personas: $personas,
            nodes: $nodes,
            authorNames: $authorNames,
            discussionHash: self::hash([
                $title,
                $description,
                $plan->category_id,
                $tagIds,
                array_map(fn (StudioDiscussionNode $node): array => [
                    $node->id, $node->parent_id, $node->persona_user_id, $node->body, $node->sort_order, $node->offset_seconds,
                ], self::byId($nodes)),
                // The names the model reads the discussion's authors by.
                $authorNames,
            ]),
            personasHash: self::hash(array_map(fn (StudioDiscussionPersona $persona): array => $persona->fingerprint(), $personas)),
        );
    }

    /**
     * The Personas a part of the discussion is written by: the authors of the
     * comments it writes again.
     *
     * @param  list<int>  $nodeIds
     * @return list<int> ascending
     */
    public static function authorsOf(StudioPlan $plan, array $nodeIds): array
    {
        return StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->getKey())
            ->whereKey($nodeIds)
            ->pluck('persona_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The comments of a target in the plan's discussion as it is stored now —
     * read before any lock, to know whose rows to lock; asked again of the
     * context once they are (scope()).
     *
     * @return list<int>|null
     */
    public static function scopeOf(StudioPlan $plan, StudioDiscussionGenerationMode $mode, ?int $targetId): ?array
    {
        return self::scopeIn(self::ordered($plan->discussionNodes()->get()->all()), $mode, $targetId);
    }

    /**
     * The comments of a target in the discussion as this context read it, in
     * order: a comment, a reply, or a comment then every reply it has — none
     * when it holds no such comment, or one at the wrong level.
     *
     * @return list<int>|null
     */
    public function scope(StudioDiscussionGenerationMode $mode, ?int $targetId): ?array
    {
        return self::scopeIn($this->nodes, $mode, $targetId);
    }

    /**
     * A comment, a reply, or a comment then every reply it has, among these
     * comments in their order; none when they hold no such comment, or one at
     * the wrong level.
     *
     * @param  list<StudioDiscussionNode>  $nodes  each comment on the post, then its replies
     * @return list<int>|null
     */
    private static function scopeIn(array $nodes, StudioDiscussionGenerationMode $mode, ?int $targetId): ?array
    {
        $target = null;

        foreach ($nodes as $node) {
            $target = $node->id === $targetId ? $node : $target;
        }

        if (! $mode->isTargeted() || $target === null) {
            return null;
        }

        return match (true) {
            $mode === StudioDiscussionGenerationMode::RegenerateReply => $target->isRoot() ? null : [$target->id],
            ! $target->isRoot() => null,
            $mode === StudioDiscussionGenerationMode::RegenerateComment => [$target->id],
            default => [$target->id, ...array_values(array_map(
                fn (StudioDiscussionNode $node): int => $node->id,
                array_filter($nodes, fn (StudioDiscussionNode $node): bool => $node->parent_id === $target->id),
            ))],
        };
    }

    /**
     * The name each comment of the discussion goes by in a request —
     * `existing_1`, `existing_2`, in the order the discussion lists them —
     * so the model names a comment without ever seeing an id.
     *
     * @return array<int, string> by id
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->nodes as $index => $node) {
            $labels[$node->id] = 'existing_'.($index + 1);
        }

        return $labels;
    }

    /** The alias of the Persona with this id, when it takes part. */
    public function aliasOf(int $userId): ?string
    {
        foreach ($this->personas as $persona) {
            if ($persona->userId === $userId) {
                return $persona->alias;
            }
        }

        return null;
    }

    /**
     * Every account the discussion names as an author, ascending.
     *
     * @return list<int>
     */
    public function authorIds(): array
    {
        return array_keys($this->authorNames);
    }

    /** The Persona with this alias, among those taking part. */
    public function persona(string $alias): ?StudioDiscussionPersona
    {
        foreach ($this->personas as $persona) {
            if ($persona->alias === $alias) {
                return $persona;
            }
        }

        return null;
    }

    /**
     * Each comment on the post, then its replies; each level by its place,
     * then by id.
     *
     * @param  list<StudioDiscussionNode>  $nodes
     * @return list<StudioDiscussionNode>
     */
    private static function ordered(array $nodes): array
    {
        $byPlace = fn (StudioDiscussionNode $a, StudioDiscussionNode $b): int => [$a->sort_order, $a->id] <=> [$b->sort_order, $b->id];
        $roots = array_values(array_filter($nodes, fn (StudioDiscussionNode $node): bool => $node->isRoot()));
        usort($roots, $byPlace);
        $ordered = [];

        foreach ($roots as $root) {
            $ordered[] = $root;
            $replies = array_values(array_filter($nodes, fn (StudioDiscussionNode $node): bool => $node->parent_id === $root->id));
            usort($replies, $byPlace);
            array_push($ordered, ...$replies);
        }

        return $ordered;
    }

    /**
     * @param  list<StudioDiscussionNode>  $nodes
     * @return list<StudioDiscussionNode>
     */
    private static function byId(array $nodes): array
    {
        usort($nodes, fn (StudioDiscussionNode $a, StudioDiscussionNode $b): int => $a->id <=> $b->id);

        return $nodes;
    }

    /** @param  array<mixed>  $values */
    private static function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
