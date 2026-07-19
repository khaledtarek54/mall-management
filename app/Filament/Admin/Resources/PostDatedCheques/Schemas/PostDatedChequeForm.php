<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Schemas;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                    Select::make('asset_id')
                        ->label(__('admin.post_dated_cheques.fields.property'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        ->required()
                        ->native(false),
                    Select::make('tenant_id')
                        ->label(__('admin.post_dated_cheques.fields.tenant'))
                        ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->required()
                        ->native(false),
                    Select::make('invoice_id')
                        ->label(__('admin.post_dated_cheques.fields.invoice'))
                        ->options(fn (Get $get) => $get('tenant_id')
                            ? Invoice::query()
                                ->where('tenant_id', $get('tenant_id'))
                                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                ->orderByDesc('issue_date')
                                ->pluck('reference', 'id')->all()
                            : [])
                        ->searchable()
                        ->native(false)
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
