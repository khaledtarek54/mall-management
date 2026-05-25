<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff ↔ Asset pivot.
 *
 * Distinct from `asset_owner` (which captures the *legal owner* relationship
 * with ownership_percentage). This table assigns *staff* — e.g. a leasing
 * manager handles only Haya Walk, a maintenance manager covers two malls.
 *
 * The `role` column on the pivot is a free-form label of the staff member's
 * role *at this property* (Property Manager, Leasing Lead, Site Engineer).
 * Spatie RBAC roles stay separate and global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('role', 100)->nullable(); // e.g. "Property Manager", "Site Engineer"
            $table->date('assigned_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'asset_id'], 'asset_user_unique');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_user');
    }
};
