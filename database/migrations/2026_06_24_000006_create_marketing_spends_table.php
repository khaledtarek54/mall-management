<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing spend items — offers, promotions, events, printed work (FR MKT-1).
 * Each spend decrements its budget (FR MKT-5) and may carry a receipt reference
 * issued to Accounting (FR MKT-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_budget_id')->constrained('marketing_budgets')->cascadeOnDelete();
            $table->enum('category', ['offer', 'promotion', 'event', 'printed_work', 'other'])->default('other');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->string('receipt_reference')->nullable(); // receipt issued to Accounting (MKT-4)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('marketing_budget_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_spends');
    }
};
