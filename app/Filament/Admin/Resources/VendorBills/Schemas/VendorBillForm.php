<?php

namespace App\Filament\Admin\Resources\VendorBills\Schemas;

use App\Models\ExpenseCategory;
use App\Models\FacilityWorkOrder;
use App\Models\PurchaseRequest;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Support\CatalogueTaxRate;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\Modules;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

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

                    EntitySelect::make('vendor_id')
                        ->label(__('admin.fields.vendor'))
                        ->entity(Vendor::class)
                        ->required()
                        ->live() // the purchase picker below narrows to this vendor
                        ->disabled($locked),

                    PropertyField::make(alsoDisabledWhen: $locked)
                        ->searchable()
                        ->preload()
                        ->live(),

                    // What the contract's `value` was always missing: the link that turns it from a
                    // decorative number into a commitment. Optional — an ad-hoc call-out has none.
                    // Scoped to the chosen vendor AND to properties this user can see, so the picker
                    // can't enumerate another mall's contracts (property-isolation read rule).
                    EntitySelect::make('vendor_contract_id')
                        ->label(__('admin.fields.vendor_contract'))
                        ->entity(VendorContract::class)
                        // The remaining-commitment figure in the label is the presenter's now; what
                        // stays is the vendor narrowing and the portfolio-wide contract exception
                        // (`asset_id IS NULL` — a master agreement covering every mall), which the
                        // derived property scope cannot know about.
                        ->modifyOptionsQuery(function ($query, Get $get) {
                            $vendorId = $get('vendor_id');

                            if (blank($vendorId)) {
                                return $query->whereRaw('1 = 0');
                            }

                            $visible = TenantScope::visibleAssetIds();

                            return $query
                                ->where('vendor_id', $vendorId)
                                ->when($visible !== null, fn ($q) => $q->where(
                                    fn ($w) => $w->whereIn('asset_id', $visible)->orWhereNull('asset_id'),
                                ));
                        })
                        ->helperText(function (Get $get) {
                            $contract = VendorContract::find($get('vendor_contract_id'));

                            if (! $contract instanceof VendorContract) {
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
                    // **Which job this invoice paid for** — the service bucket of the work-order
                    // cost object (2026-08-20). Without it a contractor's invoice was correctly in
                    // accounts payable and attributable to nothing, so a chiller repaired five
                    // times had an empty cost history.
                    //
                    // Suggested from the same property; NOT restricted to the same vendor, because
                    // a job legitimately draws on more than one contractor.
                    EntitySelect::make('facility_work_order_id')
                        ->label(__('admin.facility.order.singular'))
                        ->helperText(__('admin.facility.help.bill_work_order'))
                        ->entity(FacilityWorkOrder::class)
                        ->modifyOptionsQuery(fn ($query, Get $get) => filled($get('asset_id'))
                            ? $query->where('asset_id', $get('asset_id'))
                            : $query)
                        ->searchable()
                        ->visible(fn (): bool => Modules::enabled('facility')),

                    // unreachable: nothing in the application could set `purchase_request_id`, so
                    // every stock purchase with a supplier bill double-counted its cost —
                    // Inventory +500 AND Expense +500, with GRNI stuck at −500 forever. The only
                    // writer of the column was a test (gap-analysis F-100).
                    //
                    // Scoped to the same vendor AND the same property, and to purchases that have
                    // actually been RECEIVED — an unreceived purchase has credited nothing to
                    // GRNI, so there is nothing for a bill to clear.
                    EntitySelect::make('purchase_request_id')
                        ->label(__('admin.fields.purchase_request'))
                        ->helperText(__('admin.helpers.bill_purchase_request'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.bill_purchase_request'))
                        ->entity(PurchaseRequest::class)
                        ->modifyOptionsQuery(function ($query, Get $get) {
                            $vendorId = $get('vendor_id');
                            $assetId = $get('asset_id');

                            if (! $vendorId || ! $assetId) {
                                return $query->whereRaw('1 = 0');
                            }

                            return $query
                                ->where('vendor_id', $vendorId)
                                ->where('asset_id', $assetId)
                                ->where('status', PurchaseRequest::STATUS_RECEIVED);
                        })
                        ->placeholder(__('admin.fields.purchase_request_none'))
                        ->live()
                        ->disabled($locked),

                    // **The three-way match, shown where the clerk can act on it.** A bill for
                    // 10,000 against a purchase of 5,000 posted cleanly and looked like every other
                    // bill: the journalizer cleared GRNI up to the received value and expensed the
                    // rest, which is CORRECT accounting for a bill that also covers labour or
                    // delivery — and indistinguishable from a supplier billing twice. Nobody was
                    // told (2026-08-19).
                    //
                    // Stated, not refused. Blocking would be wrong for exactly the legitimate case
                    // the journalizer is built for; the operator needs the number, not a wall.
                    Placeholder::make('purchase_match')
                        ->label(__('admin.procurement.match'))
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => filled($get('purchase_request_id')))
                        ->content(function (Get $get, ?VendorBill $record): HtmlString {
                            $pr = PurchaseRequest::find($get('purchase_request_id'));

                            if ($pr === null) {
                                return new HtmlString('—');
                            }

                            $thisNet = (float) ($get('subtotal') ?? 0);
                            $variance = $pr->billingVariance($record?->getKey(), $thisNet);
                            $money = fn (float $v): string => 'EGP '.number_format($v, 2);

                            $line = __('admin.procurement.match_summary', [
                                'ordered' => $money((float) $pr->total_value),
                                'received' => $money($pr->receivedValue()),
                                'billed' => $money($pr->billedNet($record?->getKey()) + $thisNet),
                            ]);

                            if ($variance <= 0.005) {
                                return new HtmlString(e($line));
                            }

                            return new HtmlString(e($line).'<br><span style="color:#B85C38;font-weight:600;">'
                                .e(__('admin.procurement.match_over', ['amount' => $money($variance)])).'</span>');
                        }),

                    Select::make('category')
                        ->label(__('admin.fields.category'))
                        ->options(fn () => ExpenseCategory::options())
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

                    // WHICH input tax the supplier charged. Both this document and Expense post
                    // their VAT to `vat_recoverable` — the account the VAT return reads for input
                    // VAT — so before this the whole input side of a filed return rested on a
                    // number with nothing saying what it was.
                    Select::make('tax_code')
                        ->label(__('admin.fields.tax_code'))
                        ->options(fn () => TaxCode::options(TaxCode::PURCHASES))
                        ->native(false)
                        ->live()
                        ->placeholder(__('admin.charge_codes.tax_unclassified'))
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $derived = CatalogueTaxRate::deriveOnNet(
                                is_string($state) ? $state : null,
                                (float) ($get('subtotal') ?? 0),
                                is_string($get('bill_date')) ? $get('bill_date') : null,
                            );

                            if ($derived !== null) {
                                $set('vat_amount', $derived);
                                $set('tax_override_reason', null);
                                self::syncTotal($set, $get);
                            }
                        })
                        ->disabled($locked),

                    TextInput::make('vat_amount')
                        ->label(__('admin.fields.tax_total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncTotal($set, $get))
                        // Stays EDITABLE, unlike an invoice line's rate. The tax on a supplier's
                        // bill is their number on their document; a system that refused to record
                        // what a supplier actually charged would push the difference somewhere
                        // worse. A real departure asks for a reason instead — Odoo and SAP both
                        // work this way.
                        ->disabled($locked),

                    TextInput::make('tax_override_reason')
                        ->label(__('admin.fields.tax_override_reason'))
                        ->maxLength(255)
                        ->columnSpan(2)
                        ->required(fn (Get $get) => self::taxDeparts($get))
                        ->visible(fn (Get $get) => self::taxDeparts($get))
                        ->helperText(__('admin.helpers.purchase_tax_override_reason'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.purchase_tax_override_reason'))
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

    /**
     * Is the tax on this bill further from its code's figure than rounding explains?
     *
     * `required()` is real server-side validation (unlike `readOnly`, which is only an input
     * attribute), so this is the gate and not merely the hint.
     */
    protected static function taxDeparts(Get $get): bool
    {
        return CatalogueTaxRate::purchaseTaxDeparts(
            is_string($get('tax_code')) ? $get('tax_code') : null,
            (float) ($get('subtotal') ?? 0),
            (float) ($get('vat_amount') ?? 0),
            is_string($get('bill_date')) ? $get('bill_date') : null,
        );
    }
}
