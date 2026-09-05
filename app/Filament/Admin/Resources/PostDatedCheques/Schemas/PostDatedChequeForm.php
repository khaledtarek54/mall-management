<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Schemas;

use App\Models\Invoice;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PostDatedChequeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.post_dated_cheques.sections.instrument'))
                ->columns(2)
                ->components([
                    PropertyField::make()
                        ->label(__('admin.post_dated_cheques.fields.property')),
                    EntitySelect::make('tenant_id')
                        ->label(__('admin.post_dated_cheques.fields.tenant'))
                        // Property-scoped — never offer a tenant with no lease in a visible property.
                        ->entity(Tenant::class)
                        ->searchable()
                        ->live()
                        ->required()
                        ->native(false),
                    // The balance-in-the-label trick this form invented is now every invoice
                    // picker's, from OptionDisplay — so is the visible-properties backstop.
                    EntitySelect::make('invoice_id')
                        ->label(__('admin.post_dated_cheques.fields.invoice'))
                        ->entity(Invoice::class)
                        ->modifyOptionsQuery(fn ($query, Get $get) => $get('tenant_id')
                            ? $query
                                ->where('tenant_id', $get('tenant_id'))
                                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                // Pinned to the property this cheque belongs to — a cheque for Mall A
                                // must NOT clear against Mall B's invoice (cross-property AR/GL leak).
                                ->when($get('asset_id'), fn ($q, $assetId) => $q->where('asset_id', $assetId))
                            // No tenant chosen yet: offer nothing rather than every invoice in scope.
                            : $query->whereRaw('1 = 0'))
                        // Browse, don't guess — the query above narrows to ONE tenant's open invoices,
                        // which is bounded by the shape of the business. `Invoice` is rightly absent from
                        // `OptionDisplay::PRELOAD` (a portfolio holds thousands) and this is the
                        // per-call-site opt-in CLAUDE.md describes. Without it the dropdown opens EMPTY,
                        // which reads as "no such record" rather than "type to search" — so it is never
                        // reported as a bug. Found in the panel on the credit-note twin (2026-08-25);
                        // `CreditNoteForm` had already reached this conclusion and the other three had not.
                        ->preload()
                        // The options are scoped to unpaid statuses, so a cleared cheque's invoice
                        // (now 'paid') drops out; resolve any stored invoice to its number so the
                        // edit page never renders the raw id. After `->entity()`, which installs its
                        // own resolver.
                        //
                        // SCOPED BY PROPERTY, never a bare `find()` (SW-014). Filament validates a
                        // Select by asking it to LABEL the submitted value and refuses what it
                        // cannot — so the label resolver IS the write guard, and an unscoped one
                        // silently accepts a crafted payload naming another mall's invoice. The
                        // payment form's identical picker states the same rule; this was the door
                        // that kept the old shape. Scoped by property ONLY, not by status or
                        // balance: a cleared cheque's invoice is legitimately absent from the
                        // OPTIONS and must still label on the edit page.
                        ->getOptionLabelUsing(function ($value): ?string {
                            $visible = TenantScope::visibleAssetIds();

                            return Invoice::query()
                                ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
                                ->find($value)?->number;
                        })
                        ->helperText(__('admin.post_dated_cheques.fields.invoice_hint')),
                    TextInput::make('cheque_number')
                        ->label(__('admin.post_dated_cheques.fields.cheque_number'))
                        ->required()
                        ->maxLength(PostDatedCheque::MAX_NUMBER_LENGTH),
                    TextInput::make('bank_name')
                        ->label(__('admin.post_dated_cheques.fields.bank_name'))
                        ->maxLength(200),
                    TextInput::make('amount')
                        ->label(__('admin.post_dated_cheques.fields.amount'))
                        ->numeric()->minValue(0.01)->required()
                        ->prefix('EGP'),
                    DatePicker::make('cheque_date')
                        ->label(__('admin.post_dated_cheques.fields.cheque_date'))
                        ->required()
                        ->native(false),
                    DatePicker::make('received_date')
                        ->label(__('admin.post_dated_cheques.fields.received_date'))
                        ->default(fn () => now()->toDateString())
                        ->required()
                        ->native(false),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
