<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string
    {
        return 'RateGuru Admin';
    }

    public function getHeading(): string
    {
        return 'RateGuru Admin';
    }
}
