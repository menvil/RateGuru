<?php

namespace App\Data\Studio;

use App\Models\StudioPlan;

/**
 * The whole discussion of a Draft as it is to be saved: every comment and
 * reply it is to have, and nothing else. A comment saved before and left out
 * is deleted, with its replies; none at all is a discussion emptied.
 */
final readonly class StudioDiscussionData
{
    /** @param  list<StudioDiscussionNodeData>  $nodes  in the order they are listed */
    public function __construct(
        public array $nodes = [],
    ) {}

    /** A plan's discussion as it is saved. */
    public static function of(StudioPlan $plan): self
    {
        return new self($plan->discussionNodes()->get()
            ->map(fn ($node): StudioDiscussionNodeData => StudioDiscussionNodeData::of($node))
            ->values()
            ->all());
    }

    public function normalized(): self
    {
        return new self(array_map(fn (StudioDiscussionNodeData $node): StudioDiscussionNodeData => $node->normalized(), $this->nodes));
    }

    /**
     * The Personas it names, each once, in ascending order.
     *
     * @return list<int>
     */
    public function personaIds(): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (StudioDiscussionNodeData $node): ?int => $node->personaUserId, $this->nodes),
            fn (?int $id): bool => $id !== null,
        )));
        sort($ids);

        return $ids;
    }

    /**
     * Keyed as the editor's fields and its validation are: `nodes.3.body`.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['nodes' => array_map(fn (StudioDiscussionNodeData $node): array => $node->toArray(), $this->nodes)];
    }
}
