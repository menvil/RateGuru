<?php

use Illuminate\Support\Facades\Schema;

it('creates reports table with required columns', function () {
    expect(Schema::hasTable('reports'))->toBeTrue();
    expect(Schema::hasColumns('reports', [
        'id',
        'reporter_id',
        'target_type',
        'target_id',
        'reason',
        'message',
        'status',
        'resolved_by',
        'resolved_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
