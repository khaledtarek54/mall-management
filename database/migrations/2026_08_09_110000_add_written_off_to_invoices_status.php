<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `written_off` to the invoice status enum.
 *
 * A debt the operator has accepted is uncollectible had only two homes before this, and both were
 * wrong. **Cancel** reverses the revenue — in the CURRENT period, including revenue earned and
 * recognised in a prior year — so last year is understated, this year is overstated, and the bad
 * debt never appears as bad debt. **Leave it** and AR aging carries fiction forever, so every
 * collections figure lies.
 *
 * The correct treatment keeps the revenue in the period it was earned, credits AR, and debits
 * bad-debt expense. That needs a status of its own: `cancelled` means "this never should have been
 * billed", `written_off` means "it was rightly billed and will not be paid". Different facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'issued', 'partially_paid', 'paid', 'overdue',
                'disputed', 'cancelled', 'credited', 'written_off',
            ])->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'issued', 'partially_paid', 'paid', 'overdue',
                'disputed', 'cancelled', 'credited',
            ])->default('draft')->change();
        });
    }
};
