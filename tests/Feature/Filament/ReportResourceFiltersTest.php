<?php

use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

it('filters open reports in report resource', function () {
    $admin = User::factory()->admin()->create();

    $open = Report::factory()->create(['status' => ReportStatus::Open]);
    $resolved = Report::factory()->create(['status' => ReportStatus::Resolved]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->filterTable('open')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$resolved]);
});
