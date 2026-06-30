<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيود اليومية وأطرافها — Journal entries (header) and their debit/credit lines.
 * Iron rule (enforced in JournalPostingService): Σ debit = Σ credit per entry,
 * and every line references an `is_postable` ledger account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // رقم القيد, e.g. "JE-202606-0001"
            $table->date('entry_date');
            $table->foreignId('accounting_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description_en')->nullable();
            $table->string('description_ar')->nullable();
            // Polymorphic link back to the document that generated this entry
            // (Invoice / Payment / CreditNote …). Null = a manual entry.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_manual')->default(true);
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft'); // مسودة / مرحّل / ملغى
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete(); // dimension: which property's books
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'entry_date']);
            $table->index(['source_type', 'source_id']);
            $table->index('asset_id');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);  // مدين
            $table->decimal('credit', 14, 2)->default(0); // دائن
            $table->string('description')->nullable();
            // Analytical dimensions (الأبعاد التحليلية) for filtered reporting.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index('ledger_account_id');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
