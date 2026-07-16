<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            // The target property. An announcement is broadcast to every active
            // tenant of this one property (property-isolated).
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            // Who composed it (operator staff); kept for the audit trail.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Stamped when the broadcast fan-out completes; recipients_count is the
            // number of tenants reached, both shown read-only in the list.
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
