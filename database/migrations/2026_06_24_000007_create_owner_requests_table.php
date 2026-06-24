<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner requests (FR OWN-1/2): a Jawad (owner) user raises a request to the
 * Eltizam operator team (recipient = operator) or to another owner user
 * (recipient = owner). Shares the request lifecycle + immutability rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->enum('recipient', ['operator', 'owner'])->default('operator');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed', 'cancelled'])->default('open');
            // Scheduled work window (FR REQ-1).
            $table->dateTime('scheduled_from')->nullable();
            $table->dateTime('scheduled_to')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipient', 'status']);
            $table->index('created_by_user_id');
            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_requests');
    }
};
