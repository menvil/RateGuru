<?php

namespace App\Livewire\Concerns;

use App\Actions\Games\CreditGameSiteActivityAction;
use Closure;
use Throwable;

/**
 * Lets a voting component credit a vote to the game that sent the visitor
 * there. A failure is reported and never spoils the vote, which is already in.
 */
trait CreditsGameSiteActivity
{
    /** @param  Closure(CreditGameSiteActivityAction): void  $credit */
    private function creditGame(Closure $credit): void
    {
        try {
            $credit(app(CreditGameSiteActivityAction::class));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
