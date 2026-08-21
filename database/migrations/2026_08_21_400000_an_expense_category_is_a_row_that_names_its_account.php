<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expense category is **the only thing deciding which P&L account a supplier bill hits**, and it
 * lived in a `private const` inside a journalizer trait with six values.
 *
 * Everything outside those six collapsed into `admin_expense` with a `Log::warning` nobody reads —
 * insurance, government fees and licences, bank charges, legal and professional fees, fuel for the
 * generator. Those are not exotic costs in an Egyptian mall; they are most of the overhead, and they
 * were all landing in one bucket. The category also drives `CostNature` (fixed vs variable) and
 * which the expense register and the weekly-spend report read — NOT the CAM apportionment, which is
 * `cam_pool_accounts.cost_nature`, a per-account pivot on a different table with the opposite
 * default. What DOES reach a tenant is the ACCOUNT: `SyncCamPoolFromLedgerService` builds a pool
 * from the GL by account, so pointing a category at an account inside a pool starts recovering
 * those costs through it.
 *
 * Same shape as `payment_methods`, deliberately, and for the same reason a rail names its account
 * directly rather than a `PostingRoles` key: a new category would otherwise need a new role, and
 * `Health::accountingReadiness()` requires every role to be mapped — so adding "Insurance" would
 * turn a BLOCKING health row red on every install until the accountant mapped it.
 *
 * NULL `ledger_account_id` is the normal state and means "take the floor" — the same six-entry map
 * the trait held, `admin_expense` for anything else. So this ships behaviour-identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            // The value the documents already store, so no data migration is needed.
            $table->string('code', 40)->unique();

            $table->string('name_en', 64);
            $table->string('name_ar', 64);

            // The P&L account this category books to. Null takes the floor.
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            // Fixed or variable — read by `App\Support\CostNature`, which decides how a cost is
            // decided silently by the six-value const. Internal reporting only — see the note above.
            $table->string('cost_nature', 16)->default('variable');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
