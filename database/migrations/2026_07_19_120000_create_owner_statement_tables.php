<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner statements + disbursements (module 32) — the operator-for-owner deliverable.
 * A per-property, per-period OwnerStatementRun (the accounting truth + GL source) has a
 * child OwnerStatement per owner (the deliverable). Slice 4 builds runs + statements as a
 * read/draft engine; the GL journalizer + finalise arrive in slice 5, disbursements in 6.
 *
 * Money columns are decimal(14,2); every money/percentage column defaults 0 at the DB and
 * in the model $attributes so a NOT-NULL column can never take a null (the meter_readings.cost
 * class of bug). These figures are service-computed, never form-entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statement_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();              // OSR-YYYY-NNNN
            $table->foreignId('asset_id')->constrained('assets'); // restrict: a financial record outlives nothing
            $table->foreignId('accounting_period_id')->constrained('accounting_periods');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('posting_date');                        // GL entry_date at finalise (default period_end)
            $table->string('basis')->default('accrual');         // accrual | cash (frozen at finalise)

            // The property P&L snapshot (from LedgerReportService::incomeStatement).
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('total_expense', 14, 2)->default(0);
            $table->decimal('net_operating_income', 14, 2)->default(0); // revenue − expense
            $table->decimal('net_distributable', 14, 2)->default(0);    // = Σ children.owner_share

            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('owner_statement_runs')->nullOnDelete();
            $table->string('status')->default('draft');          // draft | finalised | superseded
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('finalised_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['asset_id', 'accounting_period_id', 'version'], 'osr_asset_period_version_unique');
            $table->index(['status', 'posting_date']);
        });

        Schema::create('owner_statements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();               // OS-YYYY-NNNN
            $table->foreignId('owner_statement_run_id')->constrained('owner_statement_runs')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets'); // denormalized → uniform auto-scope + isolation
            $table->foreignId('user_id')->constrained('users');   // the owner (payee)

            $table->decimal('ownership_percentage', 5, 2)->default(0); // snapshot of the pivot at generate time
            $table->date('tenure_from')->nullable();
            $table->date('tenure_to')->nullable();
            $table->decimal('weight', 9, 6)->default(0);          // share of the property net (1.0 for a sole owner)
            $table->decimal('owner_share', 14, 2)->default(0);    // = net × weight (authoritative)
            $table->decimal('share_revenue', 14, 2)->default(0);  // breakdown for the PDF
            $table->decimal('share_expense', 14, 2)->default(0);
            $table->decimal('paid_to_date', 14, 2)->default(0);   // Σ paid disbursements (recomputed, never hand-set)

            $table->string('status')->default('draft');          // draft | finalised | sent | superseded
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_statement_run_id', 'user_id'], 'os_run_owner_unique');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_statements');
        Schema::dropIfExists('owner_statement_runs');
    }
};
