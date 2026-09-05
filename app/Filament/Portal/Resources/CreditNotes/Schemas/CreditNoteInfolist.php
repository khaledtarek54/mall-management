<?php

namespace App\Filament\Portal\Resources\CreditNotes\Schemas;

use App\Models\CreditNoteItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One credit note, as the tenant reads it.
 *
 * Deliberately narrower than the admin form: no GL entry, no posting date, no internal notes. The
 * tenant's questions are *what was credited, why, against which bill, and how much of it is still
 * mine* — and the lines are what turn "12,000" into an explanation they can check against their
 * own records.
 */
class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.credit_note_details'))
                ->columns(3)
                ->components([
                    TextEntry::make('number')
                        ->label(__('admin.tables.credit_note.number'))
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.credit_note.{$state}"))
                        ->color(fn (string $state): string => match ($state) {
                            'applied' => 'success',
                            'issued' => 'warning',
                            'void' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('invoice.number')
                        ->label(__('admin.fields.invoice'))
                        // Null is a real state: a standalone tenant-level credit belongs to the
                        // account rather than to one bill.
                        ->placeholder('—')
                        ->fontFamily('mono'),
                    TextEntry::make('reason')
                        ->label(__('admin.fields.credit_note_reason'))
                        ->formatStateUsing(fn (?string $state) => $state
                            ? __("admin.enums.credit_note_reason.{$state}")
                            : '—')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('applied_at')
                        ->label(__('admin.fields.applied_at'))
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                ]),

            Section::make(__('admin.sections.items'))
                ->components([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('description')
                                ->label(__('admin.fields.description'))
                                // Worded for whoever is signed in, exactly as the invoice twin is
                                // (UX-30). This one was missed on the first pass and was masked by
                                // the credit-note writers being unconverted — it would have gone
                                // live silently the day they were.
                                ->state(fn (CreditNoteItem $record): string => $record->narrative())
                                ->columnSpan(2),
                            TextEntry::make('vat_amount')
                                ->label(__('admin.fields.vat_amount'))
                                ->money('EGP')
                                ->alignRight(),
                            TextEntry::make('total')
                                ->label(__('admin.fields.total'))
                                ->money('EGP')
                                ->alignRight(),
                        ]),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextEntry::make('subtotal')
                        ->label(__('admin.fields.subtotal'))
                        ->money('EGP'),
                    TextEntry::make('vat_amount')
                        ->label(__('admin.fields.vat_amount'))
                        ->money('EGP'),
                    TextEntry::make('total')
                        ->label(__('admin.tables.credit_note.total'))
                        ->money('EGP')
                        ->weight('bold'),
                    // The figure the tenant came for: what is still theirs to set against a future
                    // bill, as opposed to what has already been used.
                    TextEntry::make('balance')
                        ->label(__('admin.tables.credit_note.balance'))
                        ->money('EGP')
                        ->weight('bold')
                        ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),
                ]),
        ]);
    }
}
