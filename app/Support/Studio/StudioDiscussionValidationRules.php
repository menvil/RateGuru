<?php

namespace App\Support\Studio;

use App\Actions\Comments\AddCommentAction;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * The one set of rules a Draft's discussion is held to
 * (docs/architecture/studio-plans.md, "The discussion"), keyed as the
 * editor's fields — `nodes.3.body` — whoever wrote it: an administrator by
 * hand, or later the AI generator. Nothing else checks a discussion, and
 * nothing that reaches SaveStudioDiscussionTreeAction is trusted to have been
 * checked.
 *
 * In two parts, so the second can be asked on rows the caller has locked:
 *
 * - validated(), needing no lock: at most MOST_NODES comments; each by a
 *   Persona that is not deleted, with a text of up to a comment's length and
 *   a place among its siblings; two levels only — a reply answers a comment
 *   on the post, never another reply nor itself, so no loop can form — and a
 *   reply that names a comment in this same discussion; no comment before
 *   the post, nor later than LATEST_OFFSET_SECONDS after it, and no reply
 *   before the comment it answers;
 * - validateAgainst(), on the plan and the users as they are now: a comment
 *   named by its id is this plan's, so a save can neither reach another
 *   plan's comment nor one deleted meanwhile; every Persona is still one and
 *   not deleted — a Limited, Shadowbanned or Banned one still may be, as a
 *   plan's author may, since whether it can write is asked before anything
 *   is published; and on a plan with a date, no comment falls past the
 *   latest date a post may carry (StudioPlanValidationRules).
 *
 * Times are kept relative to the post — seconds after it — so a new date for
 * the post moves the whole discussion with it, unchanged.
 */
final class StudioDiscussionValidationRules
{
    /** The most comments and replies one plan may prepare. */
    public const MOST_NODES = 40;

    /** The longest a prepared comment may be: as long as a comment may be. */
    public const BODY_LIMIT = AddCommentAction::MAX_BODY_LENGTH;

    /** The latest a comment may come: thirty days after the post. */
    public const LATEST_OFFSET_SECONDS = 2_592_000;

    /** The highest place a comment may be given among its siblings. */
    public const LATEST_SORT_ORDER = 65_535;

    /** The longest name a new comment may be given within one save. */
    public const KEY_LIMIT = 64;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nodes' => ['list', 'max:'.self::MOST_NODES],
            'nodes.*' => ['array'],
            'nodes.*.id' => ['nullable', 'integer:strict', 'min:1', 'distinct:strict'],
            'nodes.*.key' => ['nullable', 'string', 'max:'.self::KEY_LIMIT, 'distinct:strict'],
            'nodes.*.parentId' => ['nullable', 'integer:strict', 'min:1'],
            'nodes.*.parentKey' => ['nullable', 'string', 'max:'.self::KEY_LIMIT],
            'nodes.*.personaUserId' => [
                'required',
                'integer:strict',
                Rule::exists(User::class, 'id')
                    ->where('role', UserRole::Persona->value)
                    ->whereNot('status', UserStatus::Deleted->value),
            ],
            'nodes.*.body' => ['required', 'string', 'max:'.self::BODY_LIMIT],
            'nodes.*.sortOrder' => ['required', 'integer:strict', 'min:0', 'max:'.self::LATEST_SORT_ORDER],
            'nodes.*.offsetSeconds' => ['required', 'integer:strict', 'min:0', 'max:'.self::LATEST_OFFSET_SECONDS],
        ];
    }

    /**
     * What each message says to do.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nodes.list' => 'List the comments one after another.',
            'nodes.max' => 'Keep the discussion to '.self::MOST_NODES.' comments and replies or fewer.',
            'nodes.*.id.*' => 'This comment is not part of this plan’s discussion. Reload the plan.',
            'nodes.*.key.*' => 'Give each new comment a name of its own, of up to '.self::KEY_LIMIT.' characters.',
            'nodes.*.parentId.*' => 'The comment it replies to is not in this discussion.',
            'nodes.*.parentKey.*' => 'The comment it replies to is not in this discussion.',
            'nodes.*.personaUserId.*' => 'Choose a persona who is not deleted.',
            'nodes.*.body.required' => 'Write the comment.',
            'nodes.*.body.*' => 'Keep the comment to '.number_format(self::BODY_LIMIT).' characters or fewer.',
            'nodes.*.sortOrder.*' => 'Place the comment from 0 to '.number_format(self::LATEST_SORT_ORDER).'.',
            'nodes.*.offsetSeconds.*' => 'Set when it comes: from the post’s own moment to 30 days after it.',
        ];
    }

    /**
     * The discussion as it will be stored, once it passes every rule that
     * needs no row: its fields, its shape and its times.
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validated(StudioDiscussionData $data): StudioDiscussionData
    {
        $data = $data->normalized();

        $validator = Validator::make($data->toArray(), $this->rules(), $this->messages());

        // The shape is asked only of a discussion whose every field is sound.
        $validator->after(function (ValidatorInstance $validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (self::shapeErrors($data) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        $validator->validate();

        return $data;
    }

    /**
     * The rules a validated discussion is held to on the rows as they are
     * now: the plan's own comments, the users named — locked by the caller,
     * keyed by id — and the plan's date.
     *
     * @param  Collection<int, User>  $users  every Persona the discussion names, keyed by id
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validateAgainst(StudioDiscussionData $data, StudioPlan $plan, Collection $users): void
    {
        $saved = StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->getKey())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        // Published at once, the post's moment is its approval's, decades
        // inside the range; on a date, it is that date.
        $postAt = $plan->publication_mode === StudioPlanPublicationMode::AtDate ? $plan->publish_at : null;
        $latest = CarbonImmutable::parse(StudioPlanValidationRules::LATEST_PUBLISH_AT);
        $errors = [];

        foreach ($data->nodes as $index => $node) {
            if ($node->id !== null && ! in_array($node->id, $saved, true)) {
                $errors["nodes.{$index}.id"] = 'This comment is not part of this plan’s discussion. Reload the plan.';
            }

            // The role and the status that count are the ones the row has now.
            $persona = $node->personaUserId !== null ? $users->get($node->personaUserId) : null;

            if ($persona === null || ! $persona->isPersona() || $persona->isTombstoned()) {
                $errors["nodes.{$index}.personaUserId"] = 'Choose a persona who is not deleted.';
            }

            if ($postAt !== null && $postAt->addSeconds($node->offsetSeconds)->greaterThan($latest)) {
                $errors["nodes.{$index}.offsetSeconds"] = 'It would come after 2038, past the latest date a post may carry. Bring it closer to the post.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The comment each reply answers, by its index in the discussion; none
     * for a comment on the post, nor for one naming a comment the discussion
     * does not hold. For a discussion validated() passed, every reply has one.
     *
     * @return array<int, int|null>
     */
    public static function parentIndexes(StudioDiscussionData $data): array
    {
        [$byId, $byKey] = self::indexes($data);

        return array_map(
            fn (StudioDiscussionNodeData $node): ?int => match (true) {
                $node->parentId !== null => $byId[$node->parentId] ?? null,
                $node->parentKey !== null => $byKey[$node->parentKey] ?? null,
                default => null,
            },
            $data->nodes,
        );
    }

    /**
     * Two levels and no loop: each reply names one comment of this same
     * discussion, a comment on the post, and comes no earlier than it.
     *
     * @return array<string, string>
     */
    private static function shapeErrors(StudioDiscussionData $data): array
    {
        $parents = self::parentIndexes($data);
        $errors = [];

        foreach ($data->nodes as $index => $node) {
            if (! $node->isReply()) {
                continue;
            }

            $field = $node->parentId !== null ? "nodes.{$index}.parentId" : "nodes.{$index}.parentKey";
            $parent = $parents[$index];

            if ($node->parentId !== null && $node->parentKey !== null) {
                $errors[$field] = 'Name the comment it replies to once, by its id or by its name.';
            } elseif ($parent === null) {
                // A comment saved before and left out of the save is deleted
                // by it: nothing may answer it, nor a comment of another plan.
                $errors[$field] = 'The comment it replies to is not in this discussion.';
            } elseif ($parent === $index) {
                $errors[$field] = 'A comment cannot reply to itself.';
            } elseif ($data->nodes[$parent]->isReply()) {
                // Two levels only, as on the site: a reply answers a comment on the post.
                $errors[$field] = 'Reply to a comment on the post, not to another reply.';
            } elseif ($node->offsetSeconds < $data->nodes[$parent]->offsetSeconds) {
                $errors["nodes.{$index}.offsetSeconds"] = 'A reply cannot come before the comment it answers.';
            }
        }

        return $errors;
    }

    /**
     * Where each id and each key of the discussion is.
     *
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private static function indexes(StudioDiscussionData $data): array
    {
        $byId = [];
        $byKey = [];

        foreach ($data->nodes as $index => $node) {
            if ($node->id !== null) {
                $byId[$node->id] = $index;
            }

            if ($node->key !== null) {
                $byKey[$node->key] = $index;
            }
        }

        return [$byId, $byKey];
    }
}
