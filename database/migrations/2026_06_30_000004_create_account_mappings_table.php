<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط الحسابات — maps a semantic posting role (e.g. "accounts_receivable",
 * "vat_payable", "rent_revenue") to a real chart account, so code never
 * hard-codes an account number. Optional per-property override (asset_id);
 * a null asset_id is the global default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key'); // semantic role
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // MySQL treats NULLs as distinct, so this guards per-asset overrides;
            // the global (null asset_id) default is kept unique via the seeder /
            // resolver using firstOrCreate.
            $table->unique(['key', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
