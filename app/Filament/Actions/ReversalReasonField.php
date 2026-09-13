<?php

namespace App\Filament\Actions;

use App\Support\FieldHelp;
use Filament\Forms\Components\Textarea;

/**
 * "Why are you reversing this?" — the one field every reversal action asks for.
 *
 * **A factory, not a Textarea copied thirteen times**, for the reason `LedgerEntryAction` and
 * `PostMonthAction` are factories: written out per action it drifts, and the way it drifts is
 * silent. `->required()` missing on one action gives an optional reason nobody notices is optional
 * until an auditor asks why a 400,000 payout was cancelled and the field is empty.
 *
 * On 2026-08-28 **eight of the thirteen reversal acts asked for no reason at all** — `cancel_bill`,
 * `cancel_expense`, `cancel_payroll`, `cancel_deposit`, the disbursement cancel, both credit-note
 * acts and the two invoice application-reversals. Yardi, MRI and Entrata all require a reason code
 * on every reversal; it is the first thing an auditor asks for and the one question the reversing
 * journal entry cannot answer on its own.
 *
 * Required and length-capped: 500 characters is a sentence or two, which is what a reason is. The
 * text is recorded by `App\Support\ReversalReason` into the activity trail, which — unlike a
 * document's `notes` — cannot afterwards be edited by whoever caused the reversal.
 */
class ReversalReasonField
{
    public static function make(string $name = 'reason'): Textarea
    {
        return Textarea::make($name)
            // "Reason", not "Reason for Void": the same field sits on a CANCEL, a REOPEN and a
            // void, and the refusal it produces is read as a sentence — *"The reason field is
            // required"* is true of all of them where *"The reason for Void field…"* on a cancelled
            // bill is not (2026-09-13 audit, EN and AR alike).
            ->label(__('admin.fields.reason'))
            // Visible helper text rather than a hint icon: this is a CONSEQUENCE of what you type —
            // it becomes part of the permanent audit record — which FieldHelp puts in `helperText`,
            // and it is the one thing that changes how carefully somebody writes the sentence.
            ->helperText(__('admin.helpers.reversal_reason'))
            ->required()
            ->maxLength(FieldHelp::REVERSAL_REASON_MAX_LENGTH);
    }
}
