<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioPlanValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator starts a publication plan: a Draft, as complete as the
 * editor makes it — with no author, photo, title, category or date at all if
 * that is all there is yet.
 *
 * The server sets what no editor may: the status (Draft), the creator (the
 * administrator, as their locked row reads), the first version, and no photo
 * — a photo comes through SetStudioPlanImageAction. A plan is not a post:
 * nothing is published, queued or announced, and no post exists for it.
 *
 * Only an active administrator may (`manage-studio-plans`), asked again on
 * their locked row inside the transaction, with the chosen author's row
 * locked beside it in ascending id order and asked again too.
 */
final class CreateStudioPlanAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioPlanValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws CannotManageStudioPlanException when the administrator may not, or the author stopped being a Persona meanwhile
     * @throws ValidationException keyed as the editor's fields
     */
    public function handle(User $admin, StudioPlanData $data): StudioPlan
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $data = $this->rules->validated($data);

        $plan = DB::transaction(function () use ($admin, $data): StudioPlan {
            [$lockedAdmin] = $this->lockAdministratorAndAuthor($admin, $data->authorUserId);

            $plan = new StudioPlan;
            $plan->fill($data->attributes());
            $plan->forceFill([
                'status' => StudioPlanStatus::Draft,
                'created_by_user_id' => $lockedAdmin->id,
                'image_asset_id' => null,
                'lock_version' => 1,
            ])->save();

            return $plan;
        });

        $this->logger->info('studio_plan.created', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
        ]);

        return $plan;
    }
}
