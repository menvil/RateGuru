<?php

namespace App\Actions\Studio;

use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Studio\StudioDispatchWake;
use App\Support\Studio\StudioExecutionSwitch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An administrator switching Content Studio's executor on or off from the
 * Action queue: the project's `content_studio_execution_enabled` flag
 * (StudioExecutionSwitch), written under the settings row's lock with every
 * other setting and flag kept as stored. Only an administrator who may both
 * manage plans and the project's settings switches it.
 *
 * Off by default on every installation, and never switched by a deploy:
 * only this, asked for on screen and confirmed there, turns it on.
 *
 * On: the dispatcher is woken once the switch is committed, so what became due
 * while it was off — a historical plan's actions among them — is sent at once.
 * Off: nothing more is sent or claimed from the next send and the next claim
 * on; a run a worker already claimed finishes, and nothing written is taken
 * back.
 */
final class SwitchStudioExecutionAction
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
        private readonly StudioDispatchWake $wake,
        private readonly DomainLogger $logger,
    ) {}

    /** @throws CannotManageStudioActionException with nothing written */
    public function handle(User $admin, bool $on): void
    {
        $current = User::query()->find($admin->getKey());

        if ($current === null || ! $current->can('manage-studio-plans') || ! $current->can('manage-project-settings')) {
            throw CannotManageStudioActionException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($on): void {
            $row = $this->settings->lockedRow();
            $flags = is_array($row->feature_flags) ? $row->feature_flags : [];

            if (! $row->forceFill(['feature_flags' => [...$flags, StudioExecutionSwitch::FLAG => $on]])->save()) {
                throw new RuntimeException('The executor switch was not saved.');
            }
        });

        $this->settings->flush();

        $this->logger->warning('studio.execution.switched', [
            'admin_id' => $admin->getKey(),
            'enabled' => $on,
        ]);

        if ($on) {
            $this->wake->now();
        }
    }
}
