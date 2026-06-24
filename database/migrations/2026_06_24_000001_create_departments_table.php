<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug')->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->text('description')->nullable();
            // null asset_id = operator-wide (global) department; a set value
            // scopes the department to a single property (FR DEPT-1 / O-9).
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            // Department head / lead — an operator staff user.
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
