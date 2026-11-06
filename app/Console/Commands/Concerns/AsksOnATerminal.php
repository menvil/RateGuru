<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Console\Input\ArgvInput;

/**
 * For a command that asks before it writes: whether anyone is there to
 * answer. A command that cannot ask is told to say so and stop, never to take
 * the question's default for an answer.
 */
trait AsksOnATerminal
{
    /**
     * Can a question actually be answered on the other end of this run?
     *
     * Laravel answers the same question for Prompts as "a terminal, OR
     * runningUnitTests()" (ConfiguresPrompts::configurePrompts), and
     * `rateguru:setup` once copied that — which is wrong here. runningUnitTests() reads APP_ENV, and
     * APP_ENV is not evidence about stdin: `APP_ENV=testing php artisan
     * rateguru:setup nature < /dev/null` has no terminal and no mocked question
     * helper, and that spelling passed it.
     *
     * The input's own TYPE is the honest signal. A real command line arrives as
     * ArgvInput and can only be answered by a stream, so it needs a terminal. The
     * test harness invokes commands through Illuminate\Console\Application::call,
     * which builds an ArrayInput, and there the answer comes from the harness
     * rather than from any stream. Nothing here consults the environment.
     *
     * This decides the MESSAGE, not the safety: a declined or unanswerable
     * confirmation returns FAILURE either way, so a guard that guessed wrong could
     * never report an unapplied preset as applied.
     */
    protected function canAskForConfirmation(): bool
    {
        // --no-interaction is the caller's instruction and outranks everything:
        // it means do not ask, including under the test harness.
        if (! $this->input->isInteractive()) {
            return false;
        }

        if ($this->input instanceof ArgvInput) {
            return defined('STDIN') && stream_isatty(STDIN);
        }

        return true;
    }
}
