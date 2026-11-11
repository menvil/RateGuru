<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The screens a visitor moves through around the game itself. A
        // version publishes one flow; editing it afterwards never changes
        // that version.
        Schema::create('game_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['game_id', 'position'], 'game_flows_game_position_index');
        });

        // One screen of a flow. Its texts are fixed columns, each with its
        // translations beside it, so Translation Center edits them like any
        // other project content; what each column means depends on the type
        // (GameFlowStepType).
        Schema::create('game_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('game_flows')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // A growing set (GameFlowStepType).
            $table->string('type', 40);
            $table->enum('audience', ['all', 'anonymous', 'authenticated'])->default('all');
            // The type's behaviour, checked by GameFlowStepSettings.
            $table->json('settings');
            foreach (['title' => 160, 'body' => 600, 'button_label' => 60, 'secondary_label' => 80, 'field_label' => 60, 'notice' => 300, 'consent_label' => 300] as $column => $length) {
                $table->string($column, $length)->nullable();
                $table->json($column.'_translations')->nullable();
            }
            $table->timestamps();

            $table->index(['flow_id', 'position'], 'game_flow_steps_flow_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_flow_steps');
        Schema::dropIfExists('game_flows');
    }
};
