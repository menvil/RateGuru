<?php

namespace App\Data\Studio;

use App\Models\StudioDiscussionNode;
use Illuminate\Support\Collection;

/**
 * A discussion as a save left it: every comment with the id it keeps from
 * now on, the plan's new version, and the id each new comment's key was
 * given — so whatever comes next names a comment by its id, never by its
 * text.
 */
final readonly class SavedStudioDiscussion
{
    /**
     * @param  Collection<int, StudioDiscussionNode>  $nodes  each level by its place, then by id
     * @param  array<string, int>  $ids  the id each key of the save was given
     */
    public function __construct(
        public int $lockVersion,
        public Collection $nodes,
        public array $ids,
    ) {}

    /**
     * The comments on the post, in their order.
     *
     * @return Collection<int, StudioDiscussionNode>
     */
    public function roots(): Collection
    {
        return $this->nodes->filter(fn (StudioDiscussionNode $node): bool => $node->isRoot())->values();
    }

    /**
     * A comment's replies, in their order.
     *
     * @return Collection<int, StudioDiscussionNode>
     */
    public function repliesTo(int $id): Collection
    {
        return $this->nodes->filter(fn (StudioDiscussionNode $node): bool => $node->parent_id === $id)->values();
    }
}
