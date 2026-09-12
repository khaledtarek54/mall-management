<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting 2026-09-02, point 15 — *"funded from: make it a payment method, and who is the supplier —
 * one in the system, or a new name."*
 *
 * `funded_from` held the literal `cash|bank` and the acquisition entry's credit leg resolved by
 * POSTING ROLE alone — so a bank-funded asset landed in the generic `bank` role, the unattributed
 * state SW-228 closed for receipts, and no supplier was recorded at all. The column now reads the
 * outbound RAIL catalogue (`payment_methods`, the floor still `cash|bank` so every row already
 * written stays valid), the asset joins `RecordsBankAccount` as the ninth document on the concern
 * (`bank_account_id`, asked · defaulted · required the way the other seven do it), and it names its
 * VENDOR — a real row, never free text, because the market acquires an asset THROUGH the supplier
 * (SAP F-90, Odoo's asset-from-bill, Yardi's capital GL on the payable); the bill itself is the next
 * slice.
 *
 * Both nullable, both null on every existing row: nothing an install already holds moves, and the
 * credit leg of an asset naming no bank account still resolves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('funded_from')
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('bank_account_id')
                ->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
