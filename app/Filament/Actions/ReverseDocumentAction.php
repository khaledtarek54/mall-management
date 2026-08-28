<?php

namespace App\Filament\Actions;

use App\Services\ReverseMoneyDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * "Reverse" — the named undo for a money document whose mechanism is a soft-delete.
 *
 * **A factory, so the four call sites cannot each invent their own vocabulary.** Before this, the
 * marketing spend had a `DeleteAction` and the other three had nothing at all: four posting sources,
 * four different answers to the same question. The button now says the same word, asks the same
 * question and writes the same audit row wherever it appears.
 *
 * **Why it is not a `DeleteAction` with a nicer label.** Filament's delete action is authorised
 * through the deletion policy and reads, to an operator, as "make this go away". A reversal is the
 * opposite claim: the document stays, its journal entry is reversed by a balanced counter-entry, and
 * both remain visible to an auditor. Calling it Delete taught the wrong model of what the system
 * does with money — and `DeletionPolicy` says so itself, in the `#[DeletionAllowed]` reason on every
 * one of these models ("operational: reversed rather than removed").
 *
 * Gated in BOTH `visible()` and `action()` per the house rule: `visible()` is the UI, `abort_unless`
 * in the action is the gate. The predicate is passed once and used for both so they cannot drift.
 */
class ReverseDocumentAction
{
    /**
     * @param  \Closure(Model): bool  $can  may the current user reverse this record
     * @param  \Closure(Model): bool|null  $when  an extra condition on the RECORD (e.g. not already disposed)
     */
    public static function make(
        \Closure $can,
        string $label = 'admin.actions.reverse',
        string $confirm = 'admin.actions.reverse_confirm',
        string $done = 'admin.notifications.document_reversed',
        string $event = 'reversed',
        ?\Closure $when = null,
    ): Action {
        return Action::make('reverse_document')
            ->label(__($label))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Model $record): bool => $can($record) && ($when === null || $when($record)))
            ->authorize(fn (Model $record): bool => $can($record))
            ->requiresConfirmation()
            ->modalDescription(__($confirm))
            ->schema([ReversalReasonField::make()])
            ->action(function (Model $record, array $data) use ($can, $when, $done, $event): void {
                // The real gate. `visible()` above is the UI; this is what a crafted dispatch meets.
                abort_unless($can($record) && ($when === null || $when($record)), 403);

                try {
                    app(ReverseMoneyDocumentService::class)->reverse($record, $data['reason'] ?? null, $event);
                } catch (\DomainException $e) {
                    // A refusal is a message, not a 500 — same shape as every reversal on the money
                    // pages. The likeliest one here is the sealed-period guard.
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__($done))->send();
            });
    }
}
