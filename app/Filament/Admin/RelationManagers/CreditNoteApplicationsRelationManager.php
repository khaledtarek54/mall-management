<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Services\CreditNoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only view of which invoices consumed this credit note's balance, and how much each took — so
 * `applied_amount` is a verifiable breakdown, not a bare aggregate. Applications are only ever created
 * by the service (never hand-entered), so there is no create/edit; the per-row "un-apply" is the
 * granular counterpart to the note's all-or-nothing "reverse".
 */
class CreditNoteApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tables.credit_note_applications.title');
    }

    /** Un-apply gate — named once so visible() (UI) and action() (real gate) can't drift. */
    public static function canUnapply(CreditNoteApplication $record): bool
    {
        $note = $record->creditNote;

        return $note instanceof CreditNote
            && $note->status !== 'void'
            && (Auth::user()?->can('credit_notes.apply') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: CreditNoteApplication carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with(['invoice', 'creator']))
            ->columns([
                TextColumn::make('invoice.number')
                    ->label(__('admin.fields.invoice'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP', divideBy: 1)
                    ->weight('semibold'),
                TextColumn::make('applied_at')
                    ->label(__('admin.tables.credit_note_applications.applied_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(__('admin.tables.credit_note_applications.by'))
                    ->placeholder('—'),
            ])
            ->defaultSort('applied_at', 'desc')
            ->headerActions([]) // applications are service-created — no manual create/attach
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateHeading(__('admin.empty.credit_note_applications.heading'))
            ->emptyStateDescription(__('admin.empty.credit_note_applications.description'))
            ->recordActions([
                Action::make('unapply')
                    ->label(__('admin.actions.unapply_credit_note'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.unapply_credit_note_confirm'))
                    ->visible(fn (CreditNoteApplication $record) => self::canUnapply($record))
                    ->action(function (CreditNoteApplication $record): void {
                        // action() is the real gate — mountAction() never checks visible().
                        abort_unless(self::canUnapply($record), 403);
                        $reversed = app(CreditNoteService::class)->reverseApplication($record);
                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.credit_note_reversed', ['amount' => number_format($reversed, 2)]))
                            ->send();
                    }),
            ]);
    }
}
