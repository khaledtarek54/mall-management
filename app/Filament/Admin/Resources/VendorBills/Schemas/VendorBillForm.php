<?php

namespace App\Filament\Admin\Resources\VendorBills\Schemas;

use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorBill;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class VendorBillForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once past draft, the bill's terms are settled — lock the descriptive
        // fields (only payments/approval change it after that).
        $locked = fn (?VendorBill $record) => $record !== null && $record->status !== 'draft';

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.vendor_bill_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.bill_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('vendor_id')
                        ->label(__('admin.fields.vendor'))
                        ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live() // the purchase picker below narrows to this vendor
                        ->disabled($locked),

                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->disabled($locked),

                    // What the contract's `value` was always missing: the link that turns it from a
                    // decorative number into a commitment. Optional — an ad-hoc call-out has none.
                    // Scoped to the chosen vendor AND to properties this user can see, so the picker
                    // can't enumerate another mall's contracts (property-isolation read rule).
                    Select::make('vendor_contract_id')
                        ->label(__('admin.fields.vendor_contract'))
                        ->options(function (Get $get) {
                            $vendorId = $get('vendor_id');

                            if (blank($vendorId)) {
                                return [];
                            }

                            $visible = \App\Support\TenantScope::visibleAssetIds();

                            return \App\Models\VendorContract::query()
                                ->where('vendor_id', $vendorId)
                                ->when($visible !== null, fn ($q) => $q->where(
                                    fn ($w) => $w->whereIn('asset_id', $visible)->orWhereNull('asset_id'),
                                ))
                                ->orderByDesc('start_date')
                                ->get()
                                ->mapWithKeys(fn (\App\Models\VendorContract $c) => [
                                    $c->id => sprintf(
                                        '%s · %s',
                                        $c->reference ?: $c->name,
                                        __('admin.vendors.commitment.remaining_short', [
                                            'amount' => number_format($c->remainingValue(), 0),
                                        ]),
                                    ),
                                ])
                                ->all();
                        })
                        ->helperText(function (Get $get) {
                            $contract = \App\Models\VendorContract::find($get('vendor_contract_id'));

                            if (! $contract instanceof \App\Models\VendorContract) {
                                return __('admin.fields.vendor_contract_hint');
                            }

                            // Spell out the arithmetic — an operator should never have to trust a
                            // bare "remaining" figure they can't reconcile.
                            return __('admin.vendors.commitment.helper', [
                                'value' => number_format((float) $contract->value, 2),
                                'billed' => number_format($contract->billedToDate(), 2),
                                'remaining' => number_format($contract->remainingValue(), 2),
                            ]);
                        })
                        ->searchable()
                        ->live()
                        ->disabled($locked),

                    // FR-PROC-04's other half — and until now, the missing half.
                    //
                    // VendorBillJournalizer clears GRNI instead of charging the expense when a
                    // bill names the purchase it pays for. That code was correct and completely
                    // unreachable: nothing in the application could set `purchase_request_id`, so
                    // every stock purchase with a supplier bill double-counted its cost —
                    // Inventory +500 AND Expense +500, with GRNI stuck at −500 forever. The only
                    // writer of the column was a test (gap-analysis F-100).
                    //
                    // Scoped to the same vendor AND the same property, and to purchases that have
                    // actually been RECEIVED — an unreceived purchase has credited nothing to
                    // GRNI, so there is nothing for a bill to clear.
                    Select::make('purchase_request_id')
                        ->label(__('admin.fields.purchase_request'))
                        ->helperText(__('admin.helpers.bill_purchase_request'))
                        ->options(function (Get $get) {
                            $vendorId = $get('vendor_id');
                            $assetId = $get('asset_id');

                            if (! $vendorId || ! $assetId) {
                                return [];
                            }

                            return PurchaseRequest::query()
                                ->where('vendor_id', $vendorId)
                                ->where('asset_id', $assetId)
                                ->where('status', PurchaseRequest::STATUS_RECEIVED)
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (PurchaseRequest $r) => [
                                    $r->id => $r->reference.' — '.number_format((float) $r->total_value, 2).' EGP',
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->placeholder(__('admin.fields.purchase_request_none'))
                        ->disabled($locked),

                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => __('admin.enums.vendor_bill_category'))
                        ->required()
                        ->native(false)
                        ->disabled($locked),

                    DatePicker::make('bill_date')
                        ->label(__('admin.fields.bill_date'))
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->disabled($locked),

                    DatePicker::make('due_date')
                        ->label(__('admin.fields.due_date'))
                        ->native(false)
                        ->disabled($locked),

                    TextInput::make('reference')
                        ->label(__('admin.fields.vendor_reference'))
                        ->maxLength(255)
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.bill_description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextInput::make('subtotal')
                        ->label(__('admin.fields.subtotal'))
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

                    // Total is derived (subtotal + VAT) so it can never drift, and is
                    // always ≥ VAT — the journalizer books AP = total and expense = total − VAT.
                    TextInput::make('total')
                        ->label(__('admin.fields.total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated()
                        ->default(0),

                    TextInput::make('paid_amount')
                        ->label(__('admin.fields.paid_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('balance')
                        ->label(__('admin.fields.balance'))
                        ->prefix('EGP')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }

    protected static function syncTotal(Set $set, Get $get): void
    {
        $set('total', round((float) ($get('subtotal') ?? 0) + (float) ($get('vat_amount') ?? 0), 2));
    }
}
