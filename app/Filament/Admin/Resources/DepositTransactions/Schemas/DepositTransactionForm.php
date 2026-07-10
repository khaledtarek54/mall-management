<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Schemas;

use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepositTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        // A cancelled deposit is a terminal record — read-only.
        $locked = fn (?DepositTransaction $record) => $record !== null && $record->status !== 'recorded';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.deposit_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.deposit_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->required()
                        ->searchable()
                        // Tenant + asset are derived from the lease in the model, so
                        // the picker must be scoped to the user's visible properties.
                        // In All-Properties mode currentAssetId() is null, so without
                        // the visibleAssetIds() fallback a restricted user would see
                        // (and could pick) every property's leases (property isolation).
                        ->options(function () {
                            $query = Lease::query()->with('unit', 'tenant');
                            if ($assetId = TenantScope::currentAssetId()) {
                                $query->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId));
                            } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
                                $query->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $ids));
                            }

                            return $query->get()
                                ->mapWithKeys(fn ($l) => [
                                    $l->id => ($l->reference ?? ('Lease #'.$l->id)).' — '.($l->tenant?->name ?? ''),
                                ])
                                ->all();
                        })
                        ->disabled($locked),

                    Select::make('type')
                        ->label(__('admin.filters.type'))
                        ->options(fn () => __('admin.enums.deposit_type'))
                        ->required()
                        ->native(false)
                        ->disabled($locked),

                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        ->options(fn () => __('admin.enums.expense_paid_from'))
                        ->default('bank')
                        ->native(false)
                        ->required()
                        ->disabled($locked),

                    DatePicker::make('transaction_date')
                        ->label(__('admin.fields.transaction_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->disabled($locked),

                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),
        ]);
    }
}
