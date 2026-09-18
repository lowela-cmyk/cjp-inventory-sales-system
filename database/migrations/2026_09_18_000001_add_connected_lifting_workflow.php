<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifting_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('schedule_code', 30)->unique();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('truck_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->restrictOnDelete();
            $table->string('dr_number', 100)->nullable()->index();
            $table->dateTime('scheduled_at')->index();
            $table->string('status', 40)->default('scheduled')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('hauls', function (Blueprint $table) {
            $table->foreignId('lifting_schedule_id')
                ->nullable()
                ->after('id')
                ->constrained('lifting_schedules')
                ->restrictOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('workflow_status', 40)->default('pending')->after('status')->index();
        });

        Schema::create('purchase_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->index();
            $table->timestamps();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('deduplication_key', 191)->nullable()->after('alert_code')->unique();
            $table->string('action_url')->nullable()->after('reference_id');
        });

        Schema::create('alert_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['alert_id', 'user_id']);
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_code', 30)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('haul_allocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('quantity_liters', 14, 2);
            $table->dateTime('received_at')->index();
            $table->string('status', 40)->default('stock_posted')->index();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        DB::table('purchases')->orderBy('id')->eachById(function (object $purchase): void {
            $workflowStatus = match ($purchase->status) {
                'cancelled' => 'cancelled',
                'hauled' => 'completed',
                'partially_hauled' => 'partially_lifted',
                default => 'pending',
            };

            DB::table('purchases')->where('id', $purchase->id)->update(['workflow_status' => $workflowStatus]);
        });

        DB::table('hauls')->whereNull('lifting_schedule_id')->orderBy('id')->eachById(function (object $haul): void {
            do {
                $code = 'SCH-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
            } while (DB::table('lifting_schedules')->where('schedule_code', $code)->exists());

            $scheduleId = DB::table('lifting_schedules')->insertGetId([
                'schedule_code' => $code,
                'depot_id' => $haul->depot_id,
                'truck_id' => $haul->truck_id,
                'driver_user_id' => $haul->driver_user_id,
                'dr_number' => $haul->dr_number,
                'scheduled_at' => $haul->scheduled_at,
                'status' => $haul->status,
                'created_at' => $haul->created_at,
                'updated_at' => $haul->updated_at,
            ]);

            DB::table('hauls')->where('id', $haul->id)->update(['lifting_schedule_id' => $scheduleId]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('alert_reads');

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn(['deduplication_key', 'action_url']);
        });

        Schema::dropIfExists('purchase_status_histories');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['workflow_status']);
            $table->dropColumn('workflow_status');
        });

        Schema::table('hauls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lifting_schedule_id');
        });

        Schema::dropIfExists('lifting_schedules');
    }
};
