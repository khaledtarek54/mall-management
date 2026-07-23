<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An owner statement showed only three totals — revenue, expense, net (module 32).
 *
 * That is not a statement an owner would accept: they cannot see WHAT the revenue was (rent, CAM,
 * parking) or WHERE the expenses went (maintenance, utilities, staff, vendor bills). Every property
 * management platform itemizes the owner's P&L; Atriom already computes the per-account breakdown in
 * `LedgerReportService::incomeStatement()` and was discarding it after summing the totals.
 *
 * This stores the breakdown as a FROZEN snapshot alongside the totals it already freezes
 * (recompute-then-freeze) — so the detail can never drift from the net, and a superseded version
 * keeps the numbers it was issued with. JSON, not a child table: it is a read-only snapshot of a
 * report, never queried or mutated row-by-row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_statement_runs', function (Blueprint $table) {
            $table->json('income_breakdown')->nullable()->after('net_operating_income');
        });
    }

    public function down(): void
    {
        Schema::table('owner_statement_runs', function (Blueprint $table) {
            $table->dropColumn('income_breakdown');
        });
    }
};
