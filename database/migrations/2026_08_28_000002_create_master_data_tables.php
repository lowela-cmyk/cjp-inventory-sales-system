<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->string('depot_code', 30)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });

        Schema::create('fuel_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });

        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_code', 30)->unique();
            $table->string('name');
            $table->enum('type', ['garage'])->default('garage')->index();
            $table->string('address')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 30)->unique();
            $table->string('name');
            $table->string('company_name');
            $table->string('location')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->enum('payment_status', ['clear', 'pending', 'partial', 'unpaid'])->default('clear')->index();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });

        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->string('truck_code', 30)->unique();
            $table->string('plate_number', 50)->nullable()->unique();
            $table->decimal('capacity_liters', 12, 2);
            $table->enum('truck_type', ['hauling', 'delivery', 'mixed'])->index();
            $table->enum('status', ['available', 'assigned', 'maintenance', 'inactive'])->default('available')->index();
            $table->timestamps();
        });

        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('driver_code', 30)->unique();
            $table->string('license_number', 100)->nullable();
            $table->string('emergency_contact', 30)->nullable();
            $table->enum('status', ['available', 'assigned', 'inactive'])->default('available')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
        Schema::dropIfExists('trucks');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('fuel_types');
        Schema::dropIfExists('depots');
    }
};
