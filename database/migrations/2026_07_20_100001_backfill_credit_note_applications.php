<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill an application row for every credit note that was applied BEFORE the
 * credit_note_applications table existed. The old applyToInvoice() bumped the aggregate
 * `credit_notes.applied_amount` / `invoices.credit_applied_amount` with no row, so on an environment
 * migrated (not `migrate:fresh`) the reverse paths — which derive from the rows — would either strand
 * the credit (cancel) or make it re-appliable (guided reverse). This reconstructs the link.
 *
 * The invoice is taken from the note's own `invoice_id` (the invoice it was created against, which is
 * the one it was applied to in the overwhelming common case). Notes with applied_amount > 0 but a null
 * invoice_id can't be reconstructed here; they degrade to a safe no-op on reverse (derive-from-rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('credit_notes')
            ->whereNull('deleted_at')
            ->where('applied_amount', '>', 0)
            ->whereNotNull('invoice_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('credit_note_applications')
                    ->whereColumn('credit_note_applications.credit_note_id', 'credit_notes.id')
                    ->whereNull('credit_note_applications.deleted_at');
            })
            ->get(['id', 'invoice_id', 'applied_amount', 'applied_at', 'issued_by_user_id', 'updated_at']);

        foreach ($rows as $note) {
            DB::table('credit_note_applications')->insert([
                'credit_note_id' => $note->id,
                'invoice_id' => $note->invoice_id,
                'amount' => $note->applied_amount,
                'applied_at' => $note->applied_at ?? $note->updated_at,
                'created_by' => $note->issued_by_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible backfill of historical data — the create-table migration's down() drops it.
    }
};
