<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff ↔ Department membership (DEPT-4). Mirrors the asset_user staff pivot:
 * a free-form role label at the department plus tenure dates. Distinct from
 * departments.head_user_id (the single department head).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('role', 100)->nullable(); // e.g. "Lead", "Member", "Coordinator"
            $table->date('assigned_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'department_id'], 'department_user_unique');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
    }
};
