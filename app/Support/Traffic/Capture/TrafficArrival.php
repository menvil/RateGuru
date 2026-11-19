<?php

namespace App\Support\Traffic\Capture;

/**
 * The arrival token of the page being drawn, kept for the request: the game
 * player takes it to send back on its first call; on any other page the
 * layout prints it for the small script that does the same. Bound scoped.
 */
final class TrafficArrival
{
    private ?string $token = null;

    private bool $taken = false;

    public function set(string $token): void
    {
        $this->token = $token;
        $this->taken = false;
    }

    /** The token, for the player; the layout then prints nothing. */
    public function take(): ?string
    {
        $this->taken = true;

        return $this->token;
    }

    /** The token the layout should print, if nobody took it. */
    public function pending(): ?string
    {
        return $this->taken ? null : $this->token;
    }
}
