<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Livewire\Games\PlayGame;
use App\Livewire\Games\PreviewGame;
use App\Queries\Games\TestLinkVersionQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A game's public page: the game played (PlayGame) — or, for a valid test
 * link to one of its active versions (VersionTestLink), that version played
 * the way the admin preview plays it (PreviewGame), recording nothing. Both
 * are drawn as full pages, as a Livewire component named by the route would
 * be.
 */
final class PlayGamePageController extends Controller
{
    public function __invoke(Request $request, string $slug, TestLinkVersionQuery $links): Response
    {
        $component = $links->find($request, $slug) === null ? PlayGame::class : PreviewGame::class;

        return app()->call([app('livewire')->new($component), '__invoke']);
    }
}
