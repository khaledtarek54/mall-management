<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المصروفات المباشرة — direct / petty-cash expenses: spend paid immediately from
 * cash or bank with no vendor-payable stage (the cash counterpart of a vendor
 * bill). Posts Dr expense (+ Dr VAT recoverable) / Cr cash|bank.
 * total is DERIVED = amount + vat (enforced in the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // e.g. "EXP-AW-202607-0001"
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40); // maintenance | utilities | cleaning_security | marketing | admin | other
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);            // net (ex-VAT)
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);             // DERIVED = amount + vat
            $table->enum('paid_from', ['cash', 'bank'])->default('cash');
            $table->date('expense_date');
            $table->enum('status', ['recorded', 'cancelled'])->default('recorded');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expense_date']);
            $table->index('asset_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
