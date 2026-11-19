<?php

namespace App\Enums;

/** Where a flow sent the visitor on from an attempt: what site activity within 30 minutes is credited by. */
enum GameOnwardKind: string
{
    case Post = 'post';
    case Feed = 'feed';
    case Page = 'page';
    case NextGame = 'next_game';
}
