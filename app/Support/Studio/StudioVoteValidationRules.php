<?php

namespace App\Support\Studio;

use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\RatingOption;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Rating\RatingConfigurationManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one set of rules the votes a Draft prepares are held to
 * (docs/architecture/studio-plans.md, "The votes"), keyed as the editor's
 * fields — `votes.2.voteType` — whoever wrote them. Nothing else checks a
 * prepared vote, and nothing that reaches SaveStudioVotesAction is trusted
 * to have been checked.
 *
 * What a Draft may hold, in two parts, so the second can be asked on rows the
 * caller has locked:
 *
 * - validated(), needing no row: at most MOST_VOTES votes, each one of the
 *   three kinds with the fields of its kind and none of another's — up or
 *   down on the post or a comment, an option and no direction for a rating
 *   question — a moment from the post's own to LATEST_OFFSET_SECONDS after
 *   it, and a place among votes of one moment, both whole numbers;
 * - validatedAgainst(), on the plan, its discussion and the users as they
 *   are now: a vote named by its id is this plan's; every Persona is still
 *   one and not deleted — a Limited, Shadowbanned or Banned one still may
 *   be, as in the discussion; nobody votes on the plan's post as its author,
 *   nor on a comment it writes itself; a comment voted on is this plan's and
 *   comes no later than the vote; an option newly chosen is an active option
 *   of an active rating question (RatingConfigurationManager), its question
 *   the option's own; a Persona votes on each target once, whichever way
 *   (StudioPlanVote::targetKeyFor()); and on a plan with a date, no vote
 *   falls past the latest date a post may carry.
 *
 * An option chosen earlier and archived, deactivated or deleted since stays
 * as it was chosen: a save that keeps the vote as it is keeps it, and nothing
 * ever puts another option in its place.
 *
 * What the votes need before they may be cast is asked apart, by
 * readinessErrors(): every Persona Active and able to vote, every option
 * still one to vote for — on top of everything a Draft is held to. The same
 * pass says each problem as a stable code too (readinessProblems()), which is
 * what an approval refuses the plan for.
 *
 * The same bounds hold the other way round, for the rest of the Draft: a new
 * date for the post is refused when a vote would then fall past the dates a
 * post may carry (validatePlanDate()), and a comment of the discussion is not
 * moved past a vote on it, nor given to a Persona voting on it
 * (validateDiscussion()). Nothing is moved, taken away or given to another
 * Persona to make them fit: the change is refused instead.
 */
final class StudioVoteValidationRules
{
    /** The most votes one plan may prepare. */
    public const MOST_VOTES = 1000;

    /** The latest a vote may come: thirty days after the post, as a comment may. */
    public const LATEST_OFFSET_SECONDS = StudioDiscussionValidationRules::LATEST_OFFSET_SECONDS;

    /** The highest place a vote may be given among votes of one moment. */
    public const LATEST_SORT_ORDER = 65_535;

    /** The longest name a new vote may be given within one save. */
    public const KEY_LIMIT = 64;

    private const DELETED_PERSONA = 'Choose a persona who is not deleted.';

    public function __construct(
        private readonly RatingConfigurationManager $ratingConfiguration,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $types = [StudioActionType::VotePost->value, StudioActionType::VoteComment->value, StudioActionType::VoteRating->value];

        return [
            'votes' => ['list', 'max:'.self::MOST_VOTES],
            'votes.*' => ['array'],
            'votes.*.id' => ['nullable', 'integer:strict', 'min:1', 'distinct:strict'],
            'votes.*.key' => ['nullable', 'string', 'max:'.self::KEY_LIMIT, 'distinct:strict'],
            'votes.*.type' => ['required', 'string', Rule::in($types)],
            'votes.*.personaUserId' => ['required', 'integer:strict', 'min:1'],
            'votes.*.voteType' => [
                'required_if:votes.*.type,'.StudioActionType::VotePost->value.','.StudioActionType::VoteComment->value,
                'prohibited_if:votes.*.type,'.StudioActionType::VoteRating->value,
                'nullable',
                'string',
                Rule::enum(VoteType::class),
            ],
            'votes.*.discussionNodeId' => [
                'required_if:votes.*.type,'.StudioActionType::VoteComment->value,
                'prohibited_unless:votes.*.type,'.StudioActionType::VoteComment->value,
                'nullable',
                'integer:strict',
                'min:1',
            ],
            'votes.*.ratingGroupId' => [
                'prohibited_unless:votes.*.type,'.StudioActionType::VoteRating->value,
                'nullable',
                'integer:strict',
                'min:1',
            ],
            'votes.*.ratingOptionId' => [
                'required_if:votes.*.type,'.StudioActionType::VoteRating->value,
                'prohibited_unless:votes.*.type,'.StudioActionType::VoteRating->value,
                'nullable',
                'integer:strict',
                'min:1',
            ],
            'votes.*.offsetSeconds' => ['required', 'integer:strict', 'min:0', 'max:'.self::LATEST_OFFSET_SECONDS],
            'votes.*.sortOrder' => ['required', 'integer:strict', 'min:0', 'max:'.self::LATEST_SORT_ORDER],
        ];
    }

    /**
     * What each message says to do. The particular ones come first: the
     * first that matches is the one given.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'votes.list' => 'List the votes one after another.',
            'votes.max' => 'Keep the votes to '.number_format(self::MOST_VOTES).' or fewer.',
            'votes.*.id.*' => 'This vote is not one of this plan’s. Reload the plan.',
            'votes.*.key.*' => 'Give each new vote a name of its own, of up to '.self::KEY_LIMIT.' characters.',
            'votes.*.type.*' => 'Choose a vote on the post, on a comment, or for a rating option.',
            'votes.*.personaUserId.*' => self::DELETED_PERSONA,
            'votes.*.voteType.prohibited_if' => 'A rating vote goes neither up nor down: choose its option only.',
            'votes.*.voteType.*' => 'Choose up or down.',
            'votes.*.discussionNodeId.prohibited_unless' => 'Only a vote on a comment names a comment.',
            'votes.*.discussionNodeId.*' => 'Choose the comment it votes on.',
            'votes.*.ratingGroupId.prohibited_unless' => 'Only a rating vote names a rating question.',
            'votes.*.ratingGroupId.*' => 'Choose a rating question.',
            'votes.*.ratingOptionId.prohibited_unless' => 'Only a rating vote names a rating option.',
            'votes.*.ratingOptionId.*' => 'Choose a rating option.',
            'votes.*.offsetSeconds.*' => 'Set when it comes, in whole seconds: from the post’s own moment to 30 days after it.',
            'votes.*.sortOrder.*' => 'Place the vote from 0 to '.number_format(self::LATEST_SORT_ORDER).'.',
        ];
    }

    /**
     * The votes as they will be stored, once they pass every rule that needs
     * no row: their fields, their kinds and their times.
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validated(StudioVotesData $data): StudioVotesData
    {
        $data = $data->normalized();

        Validator::make($data->toArray(), $this->rules(), $this->messages())->validate();

        return $data;
    }

    /**
     * The rules validated votes are held to on the rows as they are now: the
     * plan, its votes and its discussion — read under the plan's lock, which
     * every write of them takes — the users named, locked by the caller and
     * keyed by id, and the rating configuration.
     *
     * @param  Collection<int, User>  $users  every Persona the votes name, keyed by id
     * @return StudioVotesData the votes, each rating vote naming its option's own rating question
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validatedAgainst(StudioVotesData $data, StudioPlan $plan, Collection $users): StudioVotesData
    {
        [$problems, $resolved] = $this->problemsAgainst($data, $plan, $users, ready: false);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(fn (array $problem): string => $problem[1], $problems));
        }

        return $resolved;
    }

    /**
     * Everything that stands in the way of casting a plan's saved votes as
     * they are, keyed as the editor's fields, by the place of each vote in
     * the plan's order (StudioVotesData::of()): whatever a Draft is held to,
     * and besides every Persona Active and able to vote, and every option
     * still an active option of an active rating question — however long
     * ago it was chosen. Only reads.
     *
     * @return array<string, string> the first problem of each field; none when the votes may be cast
     */
    public function readinessErrors(StudioPlan $plan): array
    {
        return array_map(fn (array $problem): string => $problem[1], $this->readiness($plan));
    }

    /**
     * The same as readinessErrors(), each problem said as its stable code —
     * what an approval refuses the plan for (StudioPlanApprovalPreflight).
     * Only reads.
     *
     * @return array<string, StudioPlanApprovalProblem> the first problem of each field; none when the votes may be cast
     */
    public function readinessProblems(StudioPlan $plan): array
    {
        return array_map(fn (array $problem): StudioPlanApprovalProblem => $problem[0], $this->readiness($plan));
    }

    /**
     * Everything that stands in the way of casting the plan's saved votes,
     * the first problem of each field as its code and its message.
     *
     * @return array<string, array{0: StudioPlanApprovalProblem, 1: string}>
     */
    private function readiness(StudioPlan $plan): array
    {
        $data = StudioVotesData::of($plan);

        if ($data->votes === []) {
            return [];
        }

        $validator = Validator::make($data->toArray(), $this->rules(), $this->messages());
        $validator->passes();
        $failed = $validator->failed();
        $problems = [];

        foreach ($validator->errors()->toArray() as $field => $messages) {
            $problems[$field] = [self::ruleProblem($field, $failed[$field] ?? []), (string) $messages[0]];
        }

        $users = User::query()->whereKey($data->personaIds())->get()->keyBy('id');

        return $problems + $this->problemsAgainst($data, $plan, $users, ready: true)[0];
    }

    /**
     * What a rule a vote's field fails says of it, as a stable code.
     *
     * @param  array<string, mixed>  $rules  the rules the field fails, keyed by their names
     */
    private static function ruleProblem(string $field, array $rules): StudioPlanApprovalProblem
    {
        $prohibited = isset($rules['Prohibited']) || isset($rules['ProhibitedIf']) || isset($rules['ProhibitedUnless']);

        return match (true) {
            $field === 'votes' => isset($rules['Max']) ? StudioPlanApprovalProblem::VotesTooMany : StudioPlanApprovalProblem::VoteShapeInvalid,
            str_ends_with($field, '.personaUserId') => StudioPlanApprovalProblem::VotePersonaDeleted,
            str_ends_with($field, '.voteType') && ! $prohibited => StudioPlanApprovalProblem::VoteDirectionInvalid,
            str_ends_with($field, '.offsetSeconds') => StudioPlanApprovalProblem::VoteTimingInvalid,
            default => StudioPlanApprovalProblem::VoteShapeInvalid,
        };
    }

    /**
     * The rule a plan's date is held to by the votes it has saved: no vote
     * falls past the latest date a post may carry. Asked whenever the plan is
     * saved (UpdateStudioPlanAction), with the mode and the date it is about
     * to take, on the plan the caller has locked. The offsets are never moved
     * to make it fit: the date is refused instead.
     *
     * @throws ValidationException keyed `publishAt`, as the editor's field
     */
    public function validatePlanDate(StudioPlan $plan, StudioPlanPublicationMode $mode, ?CarbonImmutable $publishAt): void
    {
        $latestOffset = StudioPlanVote::query()->where('studio_plan_id', $plan->getKey())->max('offset_seconds');

        if ($latestOffset !== null && ! StudioDiscussionValidationRules::fitsPostDates($mode, $publishAt, (int) $latestOffset)) {
            throw ValidationException::withMessages([
                'publishAt' => 'Its votes would run past 2038, the latest date a post may carry. Choose an earlier date, or bring the votes closer to the post.',
            ]);
        }
    }

    /**
     * The rules a discussion about to be saved is held to by the votes on its
     * comments, on the plan the caller has locked: a comment kept keeps its
     * votes, each at its time after the comment — moved, it takes them with
     * it (SaveStudioDiscussionTreeAction), so they must still come within
     * thirty days of the post and the dates it may carry — and is not given to
     * a Persona voting on it. A comment left out takes its votes with it.
     *
     * @throws ValidationException keyed as the discussion editor's fields
     */
    public function validateDiscussion(StudioDiscussionData $discussion, StudioPlan $plan): void
    {
        $votes = StudioPlanVote::query()
            ->where('studio_plan_id', $plan->getKey())
            ->whereNotNull('discussion_node_id')
            ->get(['discussion_node_id', 'persona_user_id', 'offset_seconds'])
            ->groupBy('discussion_node_id');
        $moments = StudioDiscussionNode::query()->where('studio_plan_id', $plan->getKey())->pluck('offset_seconds', 'id');
        $errors = [];

        foreach ($discussion->nodes as $index => $node) {
            $on = $node->id !== null ? $votes->get($node->id) : null;

            if ($on === null) {
                continue;
            }

            // Its last vote, moved as far as the comment is.
            $last = (int) $on->max('offset_seconds') + $node->offsetSeconds - (int) ($moments[$node->id] ?? $node->offsetSeconds);

            if ($last > self::LATEST_OFFSET_SECONDS) {
                $errors["nodes.{$index}.offsetSeconds"] = 'Its votes, moved with it, would end more than 30 days after the post. Keep the comment earlier.';
            } elseif (! StudioDiscussionValidationRules::fitsPostDates($plan->publication_mode, $plan->publish_at, $last)) {
                $errors["nodes.{$index}.offsetSeconds"] = 'Its votes, moved with it, would end after 2038, past the latest date a post may carry. Keep the comment earlier.';
            }

            if ($on->contains('persona_user_id', $node->personaUserId)) {
                $errors["nodes.{$index}.personaUserId"] = 'This persona votes on this comment, so it cannot write it. Take its vote away first.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Each vote held to the plan, its discussion, its Personas and the rating
     * configuration, the first problem of each field kept — as its code and
     * its message. For a Draft (`$ready` false) a Persona need only be one and
     * not deleted, and an option already chosen is kept as it is; to be cast,
     * neither is enough.
     *
     * @param  Collection<int, User>  $users
     * @return array{0: array<string, array{0: StudioPlanApprovalProblem, 1: string}>, 1: StudioVotesData} the problems, and the votes with each rating vote's own question
     */
    private function problemsAgainst(StudioVotesData $data, StudioPlan $plan, Collection $users, bool $ready): array
    {
        $saved = StudioPlanVote::query()->where('studio_plan_id', $plan->getKey())->get()->keyBy('id');
        $nodes = StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->getKey())
            ->get(['id', 'persona_user_id', 'offset_seconds'])
            ->keyBy('id');
        $optionIds = array_values(array_filter(array_map(
            fn (StudioVoteData $vote): ?int => $vote->actionType() === StudioActionType::VoteRating ? $vote->ratingOptionId : null,
            $data->votes,
        )));
        $options = $optionIds !== [] ? RatingOption::query()->whereKey($optionIds)->get()->keyBy('id') : new Collection;
        $active = $optionIds !== [] ? $this->ratingConfiguration->allActiveOptionIds() : [];
        $problems = [];
        $targets = [];
        $resolved = [];

        // The first problem of a field is the one it keeps.
        $fail = function (string $field, StudioPlanApprovalProblem $problem, string $message) use (&$problems): void {
            $problems[$field] ??= [$problem, $message];
        };

        foreach ($data->votes as $index => $vote) {
            $field = fn (string $name): string => "votes.{$index}.{$name}";
            $type = $vote->actionType();
            $groupId = null;

            if ($vote->id !== null && ! $saved->has($vote->id)) {
                $fail($field('id'), StudioPlanApprovalProblem::VoteShapeInvalid, 'This vote is not one of this plan’s. Reload the plan.');
            }

            // The role and the status that count are the ones the row has now.
            $persona = $vote->personaUserId !== null ? $users->get($vote->personaUserId) : null;

            if ($persona === null || $persona->isTombstoned()) {
                $fail($field('personaUserId'), StudioPlanApprovalProblem::VotePersonaDeleted, self::DELETED_PERSONA);
            } elseif (! $persona->isPersona()) {
                $fail($field('personaUserId'), StudioPlanApprovalProblem::VotePersonaNotPersona, self::DELETED_PERSONA);
            } elseif ($ready && ($persona->status !== UserStatus::Active || ! $persona->canVote())) {
                $fail($field('personaUserId'), StudioPlanApprovalProblem::VotePersonaRestricted, 'This persona is limited, banned or shadowbanned and cannot vote. Choose another one.');
            }

            if ($type === StudioActionType::VotePost || $type === StudioActionType::VoteRating) {
                if ($plan->author_user_id !== null && $vote->personaUserId === $plan->author_user_id) {
                    $fail($field('personaUserId'), StudioPlanApprovalProblem::VoteOwnContent, 'The post’s own author cannot vote on it. Choose another persona.');
                }
            }

            if ($type === StudioActionType::VoteComment) {
                $node = $vote->discussionNodeId !== null ? $nodes->get($vote->discussionNodeId) : null;

                if ($node === null) {
                    $fail($field('discussionNodeId'), StudioPlanApprovalProblem::VoteCommentMissing, 'The comment it votes on is not in this plan’s discussion. Reload the plan.');
                } else {
                    if ($node->persona_user_id === $vote->personaUserId) {
                        $fail($field('personaUserId'), StudioPlanApprovalProblem::VoteOwnContent, 'A persona cannot vote on its own comment. Choose another one.');
                    }

                    if ((int) $vote->offsetSeconds < $node->offset_seconds) {
                        $fail($field('offsetSeconds'), StudioPlanApprovalProblem::VoteTimingInvalid, 'A vote cannot come before the comment it is on.');
                    }
                }
            }

            if ($type === StudioActionType::VoteRating) {
                [$groupId, $problem] = self::ratingTarget($vote, $saved->get($vote->id ?? 0), $options, $active, $ready);

                if ($problem !== null) {
                    $fail($field($problem[0]), StudioPlanApprovalProblem::VoteOptionUnavailable, $problem[1]);
                }
            }

            if (! StudioDiscussionValidationRules::fitsPostDates($plan->publication_mode, $plan->publish_at, (int) $vote->offsetSeconds)) {
                $fail($field('offsetSeconds'), StudioPlanApprovalProblem::VoteDateOutOfRange, 'It would come after 2038, past the latest date a post may carry. Bring it closer to the post.');
            }

            // Once per target, whichever way: a second vote on it is a second vote.
            $target = match (true) {
                $type === StudioActionType::VotePost => StudioPlanVote::targetKeyFor($type, null, null),
                $type === StudioActionType::VoteComment && $vote->discussionNodeId !== null => StudioPlanVote::targetKeyFor($type, $vote->discussionNodeId, null),
                $type === StudioActionType::VoteRating && $groupId !== null => StudioPlanVote::targetKeyFor($type, null, $groupId),
                default => null,
            };

            if ($target !== null && $vote->personaUserId !== null) {
                if (isset($targets[$vote->personaUserId][$target])) {
                    $fail($field('personaUserId'), StudioPlanApprovalProblem::VoteRepeated, match ($type) {
                        StudioActionType::VoteComment => 'This persona already votes on this comment. Keep one of its votes.',
                        StudioActionType::VoteRating => 'This persona already chose an option of this rating question. Keep one.',
                        default => 'This persona already votes on the post. Keep one of its votes.',
                    });
                }

                $targets[$vote->personaUserId][$target] = true;
            }

            $resolved[] = $type === StudioActionType::VoteRating ? self::withGroup($vote, $groupId) : $vote;
        }

        return [$problems, new StudioVotesData($resolved)];
    }

    /**
     * The rating question a rating vote is for — its option's own — and the
     * first problem of its option, as a field and a message.
     *
     * @param  Collection<int, RatingOption>  $options  the options the votes name, keyed by id
     * @param  list<int>  $active  the active options of the active rating questions
     * @return array{0: int|null, 1: array{0: string, 1: string}|null}
     */
    private static function ratingTarget(StudioVoteData $vote, ?StudioPlanVote $kept, Collection $options, array $active, bool $ready): array
    {
        if ($vote->ratingOptionId === null) {
            return [null, null];
        }

        $keptOption = $kept !== null && $kept->type === StudioActionType::VoteRating && $kept->rating_option_id === $vote->ratingOptionId;

        // Chosen earlier and kept as it was: whatever became of the option
        // since, it stays the one chosen, for its own question, until the
        // votes are to be cast.
        if ($keptOption && ! $ready) {
            return [$kept->rating_group_id, self::groupProblem($vote, (int) $kept->rating_group_id)];
        }

        $option = $options->get($vote->ratingOptionId);

        if ($option === null) {
            return [$keptOption ? $kept->rating_group_id : null, ['ratingOptionId', 'This rating option no longer exists. Choose another one.']];
        }

        $groupId = (int) $option->rating_group_id;

        if (! in_array((int) $option->id, $active, true)) {
            return [$groupId, ['ratingOptionId', 'This option is archived, or its rating question is not active. Choose an option of an active rating question.']];
        }

        return [$groupId, self::groupProblem($vote, $groupId)];
    }

    /** @return array{0: string, 1: string}|null */
    private static function groupProblem(StudioVoteData $vote, int $groupId): ?array
    {
        return $vote->ratingGroupId !== null && $vote->ratingGroupId !== $groupId
            ? ['ratingOptionId', 'This option is not one of that rating question’s. Choose one of its own.']
            : null;
    }

    private static function withGroup(StudioVoteData $vote, ?int $groupId): StudioVoteData
    {
        return new StudioVoteData(
            id: $vote->id,
            key: $vote->key,
            type: $vote->type,
            personaUserId: $vote->personaUserId,
            voteType: $vote->voteType,
            discussionNodeId: $vote->discussionNodeId,
            ratingGroupId: $groupId,
            ratingOptionId: $vote->ratingOptionId,
            offsetSeconds: $vote->offsetSeconds,
            sortOrder: $vote->sortOrder,
        );
    }
}
