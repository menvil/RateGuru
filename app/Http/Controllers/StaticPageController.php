<?php

namespace App\Http\Controllers;

use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;

final class StaticPageController extends Controller
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function about(): View
    {
        return $this->show('about');
    }

    public function privacy(): View
    {
        return $this->show('privacy');
    }

    public function terms(): View
    {
        return $this->show('terms');
    }

    public function contact(): View
    {
        return $this->show('contact');
    }

    private function show(string $pageKey): View
    {
        $settings = $this->settings->current();
        $page = $settings->staticPage($pageKey);

        abort_unless(filled($page['title']) && filled($page['content']), 404);

        return view('pages.static', [
            'pageKey' => $pageKey,
            'siteName' => $settings->siteName(),
            ...$page,
        ]);
    }
}
