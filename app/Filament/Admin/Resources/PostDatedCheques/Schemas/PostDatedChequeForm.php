<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Schemas;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
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
                        // The options are scoped to unpaid statuses, so a cleared cheque's invoice
                        // (now 'paid') drops out; resolve any stored invoice to its number so the
                        // edit page never renders the raw id. After `->entity()`, which installs its
                        // own resolver.
                        ->getOptionLabelUsing(fn ($value): ?string => Invoice::find($value)?->number)
                        ->helperText(__('admin.post_dated_cheques.fields.invoice_hint')),
                    TextInput::make('cheque_number')
                        ->label(__('admin.post_dated_cheques.fields.cheque_number'))
                        ->required()
                        ->maxLength(100),
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
