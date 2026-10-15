<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each user last opened each sidebar module, so the sidebar can flag modules with unseen changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            $table->timestamp('last_viewed_at');
            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_views');
    }
};
