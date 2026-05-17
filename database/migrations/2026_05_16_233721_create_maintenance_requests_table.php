<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g. "MR-HW-2026-0001"
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'submitted',
                'acknowledged',
                'in_progress',
                'awaiting_tenant',
                'resolved',
                'closed',
                'cancelled',
            ])->default('submitted');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('category', [
                'electrical',
                'plumbing',
                'hvac',
                'structural',
                'cleaning',
                'safety',
                'other',
            ])->default('other');
            $table->string('title');
            $table->text('description');
            $table->text('resolution_notes')->nullable();
            $table->dateTime('submitted_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('target_resolution_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['tenant_id', 'status']);
            $table->index(['unit_id', 'status']);
            $table->index('assigned_to');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
