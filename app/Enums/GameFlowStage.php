<?php

namespace App\Enums;

/**
 * Where a flow step belongs relative to the game: before it, the game itself,
 * after it, or the step that ends the flow.
 */
enum GameFlowStage: string
{
    case BeforeGame = 'before_game';
    case Game = 'game';
    case AfterGame = 'after_game';
    case Exit = 'exit';

    public function order(): int
    {
        return match ($this) {
            self::BeforeGame => 0,
            self::Game => 1,
            self::AfterGame => 2,
            self::Exit => 3,
        };
    }
}
