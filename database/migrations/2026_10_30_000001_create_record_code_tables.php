<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 15 #6 (October 30, 2026): IDs are PREFIX-FUEL-NUMBER (PUR-PREM-001, SLS-PREM-001, LFT-PREM-001).
 *
 * - code_sequences: the last number given per prefix and fuel. The row is locked while the next number is taken, so two
 *   users saving at once never get the same ID; numbers are never reused (the codes' unique indexes stay as a guard).
 * - code_renumbering: every old code and the code that replaced it (the renumbering of existing records, or a new ID
 *   after a fuel change). Search boxes and old alert links find a record by its old code through it, and the
 *   renumbering migration's down() restores the old codes from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 10);
            $table->string('fuel_code', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'fuel_code']);
        });

        Schema::create('code_renumbering', function (Blueprint $table): void {
            $table->id();
            // purchase, sale, lift (lifting_schedules) or haul.
            $table->string('entity', 20);
            $table->unsignedBigInteger('record_id');
            $table->string('old_code', 60)->index();
            $table->string('new_code', 60)->index();
            // renumber (the October 30 migration) or fuel_change (a Pending record's fuel was edited).
            $table->string('reason', 20);
            $table->timestamp('created_at')->nullable();

            $table->index(['entity', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_renumbering');
        Schema::dropIfExists('code_sequences');
    }
};
