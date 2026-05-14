<?php

use Illuminate\Support\Facades\Schema;

it('creates moderation_logs table with required columns', function () {
    expect(Schema::hasTable('moderation_logs'))->toBeTrue();
    expect(Schema::hasColumns('moderation_logs', [
        'id',
        'moderator_id',
        'action',
        'target_type',
        'target_id',
        'reason',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
