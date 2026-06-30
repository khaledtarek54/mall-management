<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دليل الحسابات — Chart of Accounts.
 * A tree of accounts (parent_id). Only `is_postable` leaves accept journal lines;
 * the parents above them are summary/rollup accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // رقم الحساب, e.g. "11201001"
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']); // طبيعة الحساب
            $table->enum('normal_balance', ['debit', 'credit']); // الرصيد الطبيعي (derived from type)
            $table->boolean('is_postable')->default(false); // حساب ترحيل (leaf) vs تجميعي
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('type');
            $table->index(['type', 'is_postable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
