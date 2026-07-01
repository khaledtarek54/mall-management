<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حركات التأمينات — security-deposit transactions. Each row is one GL event:
 *   receipt → Dr Bank|Cash / Cr Deposits Held (liability)
 *   refund  → Dr Deposits Held / Cr Bank|Cash
 *   forfeit → Dr Deposits Held / Cr Misc Income
 * The GL "Deposits Held" balance = Σ receipts − Σ refunds − Σ forfeits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // e.g. "DEP-AW-202607-0001"
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['receipt', 'refund', 'forfeit']);
            $table->decimal('amount', 14, 2);
            $table->date('transaction_date');
            $table->enum('method', ['cash', 'bank'])->default('bank');
            $table->enum('status', ['recorded', 'cancelled'])->default('recorded');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'transaction_date']);
            $table->index('lease_id');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_transactions');
    }
};
