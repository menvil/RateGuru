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
        return view('pages.contact', $this->pageData('contact'));
    }

    private function show(string $pageKey): View
    {
        return view('pages.static', $this->pageData($pageKey));
    }

    /**
     * @return array{pageKey: string, siteName: string, title: string, content: string}
     */
    private function pageData(string $pageKey): array
    {
        $settings = $this->settings->current();

        return [
            'pageKey' => $pageKey,
            'siteName' => $settings->siteName(),
            ...$settings->staticPage($pageKey),
        ];
    }
}
