<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cam_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cam_expense_pool_id')->constrained('cam_expense_pools')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->decimal('pro_rata_share_pct', 7, 4)->default(0);
            $table->decimal('allocated_amount', 14, 2)->default(0);
            $table->decimal('estimated_paid', 14, 2)->default(0);
            $table->decimal('true_up_amount', 14, 2)->default(0);
            $table->decimal('cap_amount', 14, 2)->nullable();
            $table->json('exclusions')->nullable();
            $table->enum('status', ['pending', 'billed', 'disputed', 'closed'])->default('pending');
            $table->foreignId('billed_charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cam_expense_pool_id', 'lease_id'], 'cam_alloc_pool_lease_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_allocations');
    }
};
