<?php

namespace App\Support\StudioAi;

use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Support\Studio\StudioDiscussionValidationRules;
use Illuminate\Validation\ValidationException;

/**
 * A discussion generation's proposal, as it is kept and as it is taken into
 * the plan (docs/architecture/studio-plans.md, "Discussion generation"):
 *
 * - a whole discussion — new comments and replies, each with a key of its
 *   own, the key of the comment a reply answers, its Persona, its text, its
 *   place among its siblings and its time — which takes the place of the
 *   plan's discussion when it is taken in;
 * - or the new texts of the comments of one part of the plan's discussion,
 *   by id, which change nothing else of them, nor any other comment.
 *
 * A proposal is made only from an answer that passes every check: exactly
 * the fields the mode's schema() declares, of their types; a whole
 * discussion of exactly the comments and replies asked for, by the Personas
 * taking part only, no later than the latest moment; a part written again of
 * exactly its own comments, named as the request named them; no two texts
 * the same; and the discussion it makes — a whole new one, or the plan's
 * with the new texts in it — held to the one set of rules every discussion is
 * (StudioDiscussionValidationRules). The model names comments and Personas
 * only by the request's own aliases; ids are the server's to map, never
 * taken from an answer. An answer that fails a check is refused whole:
 * nothing is trimmed into shape, dropped or made up to fill a gap.
 */
final readonly class StudioDiscussionProposal
{
    /**
     * @param  list<array{key: string, parent_key: string|null, persona_user_id: int, body: string, sort_order: int, offset_seconds: int}>  $nodes  a whole discussion
     * @param  array<int, string>  $bodies  the new text of each comment written again, by id, in order
     */
    private function __construct(
        public StudioDiscussionGenerationMode $mode,
        public array $nodes,
        public array $bodies,
    ) {}

    /**
     * The JSON schema the model's answer must follow, in strict mode: exactly
     * these fields, all required, nothing more. How many comments there are,
     * how long they are and when they come is asked for in the instructions
     * and checked by from().
     *
     * @return array<string, mixed>
     */
    public static function schema(StudioDiscussionGenerationMode $mode): array
    {
        $text = ['type' => 'string'];

        return match ($mode) {
            StudioDiscussionGenerationMode::FullDiscussion => self::object([
                'comments' => [
                    'type' => 'array',
                    'items' => self::object([
                        'key' => $text,
                        'parent' => ['type' => ['string', 'null']],
                        'persona' => $text,
                        'offset_seconds' => ['type' => 'integer'],
                        'text' => $text,
                    ]),
                ],
            ]),
            StudioDiscussionGenerationMode::RegenerateComment, StudioDiscussionGenerationMode::RegenerateReply => self::object(['text' => $text]),
            StudioDiscussionGenerationMode::RegenerateBranch => self::object([
                'comment' => $text,
                'replies' => ['type' => 'array', 'items' => self::object(['reply' => $text, 'text' => $text])],
            ]),
        };
    }

    /**
     * The proposal this answer makes for this request, or why it makes none.
     *
     * @param  array<mixed>  $answer  as decoded from the provider's structured output
     */
    public static function from(StudioDiscussionPrompt $prompt, array $answer, StudioDiscussionValidationRules $rules): self|StudioDiscussionGenerationError
    {
        $proposal = $prompt->mode === StudioDiscussionGenerationMode::FullDiscussion
            ? self::wholeDiscussion($prompt, $answer)
            : self::part($prompt, $answer);

        if ($proposal === null) {
            return StudioDiscussionGenerationError::InvalidProviderResponse;
        }

        $texts = $proposal->mode === StudioDiscussionGenerationMode::FullDiscussion
            ? array_column($proposal->nodes, 'body')
            : array_values($proposal->bodies);
        $normalized = array_map(fn (string $text): string => mb_strtolower(trim($text)), $texts);

        if (count($normalized) !== count(array_unique($normalized))) {
            return StudioDiscussionGenerationError::InvalidProviderResponse;
        }

        try {
            $rules->validated($proposal->applyTo(self::current($prompt->context)));
        } catch (ValidationException $invalid) {
            $personas = array_filter(array_keys($invalid->errors()), fn (string $field): bool => str_ends_with($field, '.personaUserId'));

            return count($personas) === count($invalid->errors())
                ? StudioDiscussionGenerationError::InvalidPersona
                : StudioDiscussionGenerationError::InvalidProviderResponse;
        }

        return $proposal;
    }

    /**
     * A proposal as a generation keeps it; none when what is kept is not one.
     */
    public static function fromStored(StudioDiscussionGenerationMode $mode, mixed $result): ?self
    {
        if (! is_array($result)) {
            return null;
        }

        if ($mode === StudioDiscussionGenerationMode::FullDiscussion) {
            $nodes = $result['nodes'] ?? null;

            if (! is_array($nodes) || ! array_is_list($nodes)) {
                return null;
            }

            foreach ($nodes as $node) {
                if (! is_array($node) || ! is_string($node['key'] ?? null)
                    || ! array_key_exists('parent_key', $node) || ! ($node['parent_key'] === null || is_string($node['parent_key']))
                    || ! is_int($node['persona_user_id'] ?? null) || ! is_string($node['body'] ?? null)
                    || ! is_int($node['sort_order'] ?? null) || ! is_int($node['offset_seconds'] ?? null)) {
                    return null;
                }
            }

            return new self($mode, $nodes, []);
        }

        $bodies = [];

        foreach (is_array($result['bodies'] ?? null) ? $result['bodies'] : [] as $entry) {
            if (! is_array($entry) || ! is_int($entry['node_id'] ?? null) || ! is_string($entry['body'] ?? null)) {
                return null;
            }

            $bodies[$entry['node_id']] = $entry['body'];
        }

        return $bodies === [] ? null : new self($mode, [], $bodies);
    }

    /** @return array{nodes: list<array<string, mixed>>}|array{bodies: list<array{node_id: int, body: string}>} as a generation keeps it */
    public function toArray(): array
    {
        if ($this->mode === StudioDiscussionGenerationMode::FullDiscussion) {
            return ['nodes' => $this->nodes];
        }

        $bodies = [];

        foreach ($this->bodies as $id => $body) {
            $bodies[] = ['node_id' => $id, 'body' => $body];
        }

        return ['bodies' => $bodies];
    }

    /** How many comments it writes. */
    public function count(): int
    {
        return $this->mode === StudioDiscussionGenerationMode::FullDiscussion ? count($this->nodes) : count($this->bodies);
    }

    /**
     * The discussion the plan would have with it: a whole new one in place of
     * the plan's; or the plan's own, every comment as it is but for the new
     * texts of those written again.
     */
    public function applyTo(StudioDiscussionData $current): StudioDiscussionData
    {
        if ($this->mode === StudioDiscussionGenerationMode::FullDiscussion) {
            return new StudioDiscussionData(array_map(fn (array $node): StudioDiscussionNodeData => new StudioDiscussionNodeData(
                key: $node['key'],
                parentKey: $node['parent_key'],
                personaUserId: $node['persona_user_id'],
                body: $node['body'],
                sortOrder: $node['sort_order'],
                offsetSeconds: $node['offset_seconds'],
            ), $this->nodes));
        }

        return new StudioDiscussionData(array_map(fn (StudioDiscussionNodeData $node): StudioDiscussionNodeData => $node->id !== null && array_key_exists($node->id, $this->bodies)
            ? new StudioDiscussionNodeData(
                id: $node->id,
                parentId: $node->parentId,
                personaUserId: $node->personaUserId,
                body: $this->bodies[$node->id],
                sortOrder: $node->sortOrder,
                offsetSeconds: $node->offsetSeconds,
            )
            : $node, $current->nodes));
    }

    /** The plan's discussion as the context read it. */
    public static function current(StudioDiscussionContext $context): StudioDiscussionData
    {
        return new StudioDiscussionData(array_map(fn ($node): StudioDiscussionNodeData => StudioDiscussionNodeData::of($node), $context->nodes));
    }

    /** @param  array<mixed>  $answer */
    private static function wholeDiscussion(StudioDiscussionPrompt $prompt, array $answer): ?self
    {
        if (! self::hasExactly($answer, ['comments']) || ! is_array($answer['comments']) || ! array_is_list($answer['comments'])) {
            return null;
        }

        $nodes = [];
        $places = [];
        $roots = 0;

        foreach ($answer['comments'] as $comment) {
            if (! is_array($comment) || ! self::hasExactly($comment, ['key', 'offset_seconds', 'parent', 'persona', 'text'])
                || ! is_string($comment['key']) || ! (is_string($comment['parent']) || $comment['parent'] === null)
                || ! is_string($comment['persona']) || ! is_int($comment['offset_seconds']) || ! self::isText($comment['text'])) {
                return null;
            }

            $persona = $prompt->context->persona($comment['persona']);

            // Only the Personas taking part, and none of their comments later than the latest moment.
            if ($persona === null || $comment['offset_seconds'] > $prompt->latestOffsetSeconds) {
                return null;
            }

            $parent = $comment['parent'] !== null ? trim($comment['parent']) : null;
            $parent = $parent === '' ? null : $parent;
            $roots += $parent === null ? 1 : 0;
            $place = $places[$parent ?? ''] ?? 0;
            $places[$parent ?? ''] = $place + 1;

            $nodes[] = [
                'key' => trim($comment['key']),
                'parent_key' => $parent,
                'persona_user_id' => $persona->userId,
                'body' => trim($comment['text']),
                'sort_order' => $place,
                'offset_seconds' => $comment['offset_seconds'],
            ];
        }

        // Exactly as many comments, and as many replies, as were asked for.
        if ($roots !== $prompt->rootCount || count($nodes) - $roots !== $prompt->replyCount) {
            return null;
        }

        return new self(StudioDiscussionGenerationMode::FullDiscussion, $nodes, []);
    }

    /** @param  array<mixed>  $answer */
    private static function part(StudioDiscussionPrompt $prompt, array $answer): ?self
    {
        $labels = $prompt->context->labels();
        $scope = $prompt->scope;

        if ($scope === [] || array_diff($scope, array_keys($labels)) !== []) {
            return null;
        }

        if ($prompt->mode !== StudioDiscussionGenerationMode::RegenerateBranch) {
            return self::hasExactly($answer, ['text']) && self::isText($answer['text']) && count($scope) === 1
                ? new self($prompt->mode, [], [$scope[0] => trim($answer['text'])])
                : null;
        }

        if (! self::hasExactly($answer, ['comment', 'replies']) || ! self::isText($answer['comment'])
            || ! is_array($answer['replies']) || ! array_is_list($answer['replies'])) {
            return null;
        }

        $bodies = [$scope[0] => trim($answer['comment'])];
        $replies = array_slice($scope, 1);

        // Exactly the branch's own replies, each once, in the order they were named.
        if (count($answer['replies']) !== count($replies)) {
            return null;
        }

        foreach ($answer['replies'] as $index => $reply) {
            if (! is_array($reply) || ! self::hasExactly($reply, ['reply', 'text']) || ! is_string($reply['reply'])
                || trim($reply['reply']) !== $labels[$replies[$index]] || ! self::isText($reply['text'])) {
                return null;
            }

            $bodies[$replies[$index]] = trim($reply['text']);
        }

        return new self(StudioDiscussionGenerationMode::RegenerateBranch, [], $bodies);
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $keys  sorted
     */
    private static function hasExactly(array $values, array $keys): bool
    {
        $present = array_keys($values);
        sort($present);

        return $present === $keys;
    }

    /** Valid UTF-8 with no control character but a line feed; its length is the rules' to check. */
    private static function isText(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u', $value) === 0;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
