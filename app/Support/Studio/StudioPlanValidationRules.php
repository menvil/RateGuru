<?php

namespace App\Support\Studio;

use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Support\Posts\PostContentRules;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one set of rules a Draft is held to, keyed as the editor's fields: the
 * editor checks a draft with them to put each message by its field, and the
 * Content Studio actions check whatever reaches them with them again, so no
 * request is trusted to have been checked.
 *
 * Nothing is required — a Draft is saved as incomplete as it is — but
 * whatever is there keeps the bounds the post it becomes keeps
 * (PostContentRules, the public upload form's), and whatever it names exists:
 * the author is a Persona that is not deleted, and the category, the tags and
 * the rating options are there, one answer per rating group at most. Whether
 * they are still active, and the plan complete, is asked when it is approved.
 */
final class StudioPlanValidationRules
{
    /**
     * The span a publication date may fall in: what a post's timestamps hold
     * on every database the site runs on (MariaDB's TIMESTAMP range), since
     * the post takes the plan's date as its own.
     */
    public const EARLIEST_PUBLISH_AT = '1970-01-02 00:00:00';

    public const LATEST_PUBLISH_AT = '2038-01-18 23:59:59';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'authorUserId' => [
                'nullable',
                'integer:strict',
                Rule::exists(User::class, 'id')
                    ->where('role', UserRole::Persona->value)
                    ->whereNot('status', UserStatus::Deleted->value),
            ],
            'title' => ['nullable', 'string', 'min:'.PostContentRules::TITLE_MIN_LENGTH, 'max:'.PostContentRules::TITLE_LIMIT],
            'description' => ['nullable', 'string', 'max:'.PostContentRules::DESCRIPTION_LIMIT],
            'sourceUrl' => ['nullable', 'url', 'max:'.PostContentRules::SOURCE_URL_LIMIT],
            'categoryId' => ['nullable', 'integer:strict', Rule::exists(Category::class, 'id')],
            'tagIds' => ['list', 'max:'.PostContentRules::MOST_TAGS],
            'tagIds.*' => ['integer:strict', 'min:1', Rule::exists(Tag::class, 'id')],
            'authorAnswerOptionIds' => ['list', $this->oneAnswerPerGroup(...)],
            'authorAnswerOptionIds.*' => ['integer:strict', 'min:1', Rule::exists(RatingOption::class, 'id')],
            'publicationMode' => ['required', Rule::enum(StudioPlanPublicationMode::class)],
            'publishAt' => ['nullable', 'date', 'after_or_equal:'.self::EARLIEST_PUBLISH_AT, 'before_or_equal:'.self::LATEST_PUBLISH_AT],
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
            'authorUserId.*' => 'Choose a persona as the author.',
            'title.min' => 'Make the title at least '.PostContentRules::TITLE_MIN_LENGTH.' characters long.',
            'title.max' => 'Keep the title to '.PostContentRules::TITLE_LIMIT.' characters or fewer.',
            'description.max' => 'Keep the description to '.number_format(PostContentRules::DESCRIPTION_LIMIT).' characters or fewer.',
            'sourceUrl.url' => 'Enter a full web address, starting with https://.',
            'sourceUrl.max' => 'Keep the source address to '.number_format(PostContentRules::SOURCE_URL_LIMIT).' characters or fewer.',
            'categoryId.*' => 'Choose a category from the list.',
            'tagIds.max' => 'Choose up to '.PostContentRules::MOST_TAGS.' tags.',
            'tagIds.*' => 'Choose tags from the list.',
            'authorAnswerOptionIds.*' => 'Choose the author’s answers from the rating options.',
            'publicationMode.*' => 'Choose when to publish.',
            'publishAt.*' => 'Choose a date from 1970 to 2038.',
        ];
    }

    /**
     * The draft as it will be stored, once it passes every rule.
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validated(StudioPlanData $data): StudioPlanData
    {
        $data = $data->normalized();

        Validator::make($data->toArray(), $this->rules(), $this->messages())->validate();

        return $data;
    }

    /** At most one answer for each rating group, as a post's author answers are. */
    private function oneAnswerPerGroup(string $attribute, mixed $value, Closure $fail): void
    {
        // Anything but a list of ids is refused by the other rules already.
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $id): bool => ! is_int($id)) !== []) {
            return;
        }

        $groups = RatingOption::query()->whereKey($value)->pluck('rating_group_id');

        if ($groups->count() !== $groups->unique()->count()) {
            $fail('Choose one answer for each rating group at most.');
        }
    }
}
