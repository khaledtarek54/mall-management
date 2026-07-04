<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlements against a custody (module 25, Treasury Phase 1). Two kinds:
 *   - expense: the custodian spent on a company expense (with a receipt) →
 *     Dr Expense (by category) / Cr Custodies.
 *   - return: the custodian returned unspent cash → Dr Cash|Bank / Cr Custodies.
 * Both reduce the outstanding custody. A CHILD ledger source of the custody — its GL
 * follows the custody's lifecycle via the parent-lifecycle cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_id')->constrained('custodies')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('type');                        // expense | return
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->string('category')->nullable();        // expense: maintenance|utilities|... (→ expense account)
            $table->string('method')->nullable();          // return: cash | bank
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('custody_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_transactions');
    }
};
