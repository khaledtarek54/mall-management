<?php

namespace App\Filament\Admin\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        // A cancelled expense is a terminal record — read-only.
        $locked = fn (?Expense $record) => $record !== null && $record->status !== 'recorded';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.expense_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.expense_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->disabled($locked),

                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.vendor_bill_category'))
                        ->required()
                        ->native(false)
                        ->disabled($locked),

                    Select::make('paid_from')
                        ->label(__('admin.fields.paid_from'))
                        ->options(fn () => __('admin.enums.expense_paid_from'))
                        ->default('cash')
                        ->native(false)
                        ->required()
                        ->disabled($locked),

                    DatePicker::make('expense_date')
                        ->label(__('admin.fields.expense_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->maxLength(255)
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(3)
                ->components([
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        ->disabled($locked),

                    TextInput::make('vat_amount')
                        ->label(__('admin.fields.vat_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        ->disabled($locked),

                    // Total is derived (amount + VAT) so it can never drift — the model
                    // re-enforces it on every write; this is a live UX preview only.
                    TextInput::make('total')
                        ->label(__('admin.fields.total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated()
                        ->default(0),
                ]),
        ]);
    }

    protected static function syncTotal(Set $set, Get $get): void
    {
        $set('total', round((float) ($get('amount') ?? 0) + (float) ($get('vat_amount') ?? 0), 2));
    }
}
