<?php

namespace App\Filament\Admin\Resources\Invoices\Schemas;

use App\Models\Invoice;
use App\Models\Lease;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use App\Support\FormTab;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use App\Support\Vat;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once an invoice is issued it is a live AR + GL document (and, once filed,
        // an ETA tax invoice) — its money-affecting fields are immutable. Corrections
        // go through a void / re-issue or a credit note, never a silent edit. Only a
        // (vestigial) draft is freely editable. Mirrors the VendorBill/Expense $locked
        // convention. The derived amounts (subtotal/vat/total/balance) are already
        // read-only, so locking the ITEMS repeater + the GL-identity selects (lease /
        // tenant / issue_date) is what freezes the numbers. Status stays open so
        // dispute / cancel transitions still work. (GL integrity hardening — Phase 1.)
        $locked = fn (?Invoice $record) => $record !== null && $record->status !== 'draft';

        // Tabs, one per concern, through App\Support\FormTab so each carries a badge counting the
        // validation errors INSIDE it (UX-13). Filament v4 ships no error indicator on Tabs, so a
        // required field left blank on a tab you are not looking at would refuse the form with
        // nothing visible to fix — strictly worse than the scroll it replaces.
        return $schema->columns(1)->components([
            Tabs::make('invoice')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make(__('admin.sections.invoice_details'), [


                    TextInput::make('number')
                        ->label(__('admin.fields.invoice_number'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->disabled($locked)
                        ->relationship(
                            'lease',
                            'reference',
                            modifyQueryUsing: fn ($query) => $query
                                ->with(['tenant:id,name', 'unit:id,code'])
                                ->when(
                                    TenantScope::visibleAssetIds(),
                                    fn ($q, $assetIds) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $assetIds)),
                                ),
                        )
                        // Search columns are kept non-empty so Filament treats this as having
                        // dynamic (server-side) search results — see Select::hasDynamicSearchResults().
                        // The actual matching is done by getSearchResultsUsing() below, which spans
                        // the lease reference, tenant name, and unit code.
                        ->searchable(['reference'])
                        ->getSearchResultsUsing(function (string $search): array {
                            $term = '%'.$search.'%';

                            return Lease::query()
                                ->with(['tenant:id,name', 'unit:id,code'])
                                ->when(
                                    TenantScope::visibleAssetIds(),
                                    fn ($q, $assetIds) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $assetIds)),
                                )
                                ->where(fn ($q) => $q
                                    ->where('reference', 'like', $term)
                                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', $term))
                                    ->orWhereHas('unit', fn ($u) => $u->where('code', 'like', $term)))
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Lease $lease) => [
                                    $lease->id => trim(
                                        ($lease->reference ?? '—').' · '.($lease->tenant?->name ?? '—').' · '.($lease->unit?->code ?? '—')
                                    ),
                                ])
                                ->all();
                        })
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Lease $record) => trim(
                            ($record->reference ?? '—').' · '.($record->tenant?->name ?? '—').' · '.($record->unit?->code ?? '—')
                        ))
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if (! $state) {
                                return;
                            }

                            $lease = Lease::with('charges')->find($state);
                            if (! $lease) {
                                return;
                            }

                            if ($lease->tenant_id) {
                                $set('tenant_id', $lease->tenant_id);
                            }

                            self::prefillItemsFromLease($lease, $set, $get);
                        })
                        ->required(),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->disabled($locked)
                        ->relationship('tenant', 'name', modifyQueryUsing: function ($query) {
                            // Scope to tenants of the current property — a
                            // property-restricted user must not invoice another
                            // property's tenant. Mirrors PaymentForm.
                            $ids = \App\Support\TenantScope::visibleAssetIds();

                            return $query->when($ids !== null, fn ($q) => $q->whereHas(
                                'leases.unit',
                                fn ($u) => $u->whereIn('asset_id', $ids),
                            ));
                        })
                        ->searchable(['name', 'legal_name', 'email', 'phone'])
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        // 'draft' is not a selectable target once the invoice is finalized —
                        // reverting would re-open the locked money fields (see the model
                        // guard in Invoice::booted). Forward transitions stay available.
                        ->options(function (?Invoice $record) {
                            $options = __('admin.statuses.invoice');
                            if ($record && $record->status !== 'draft') {
                                unset($options['draft']);
                            }

                            // 'cancelled' is NOT a status you pick — it is the outcome of the
                            // "Void invoice" action, which refuses when captured cash is still
                            // allocated, returns any applied credit, reverses the GL entry and
                            // records WHY in the audit trail. Offering it here let an operator
                            // cancel a paid invoice with none of that: the cash stayed captured and
                            // allocated while the AR simply disappeared. The model refuses it too
                            // (Invoice::booted) — this only stops the UI inviting it.
                            unset($options['cancelled']);

                            return $options;
                        })
                        ->required()
                        ->native(false),
                    DatePicker::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->required()
                        ->disabled($locked)
                        ->live(onBlur: true)
                        ->native(false),
                    DatePicker::make('due_date')
                        ->label(__('admin.fields.due_date'))
                        ->required()
                        // A due date on/before the issue date is nonsensical for
                        // AR ageing (it would be "overdue" the moment it's
                        // issued). Enforce strictly-after here so manual invoice
                        // entry can't break the billing timeline.
                        ->after('issue_date')
                        ->validationMessages([
                            'after' => __('admin.validation.invoice_due_after_issue'),
                        ])
                        ->native(false),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->native(false),
                    DatePicker::make('period_end')
                        ->label(__('admin.fields.period_end'))
                        ->required()
                        // The billing period must move forward in time; a period
                        // ending on/before its start is meaningless for proration.
                        ->after('period_start')
                        ->native(false),
                    ])->columns(3),

                    FormTab::make(__('admin.sections.items'), [

                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->columns(12)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('admin.actions.add_item'))
                        ->reorderable(false)
                        // Freeze the line items once issued — they are the invoice's
                        // revenue breakdown in the GL. A disabled relationship repeater
                        // still shows the items read-only. (Phase 1.)
                        ->disabled($locked)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeInvoiceTotals($set, $get))
                        ->deleteAction(fn ($action) => $action->after(fn (Set $set, Get $get) => self::recomputeInvoiceTotals($set, $get)))
                        ->schema([
                            Select::make('type')
                                ->label(__('admin.fields.type'))
                                // The CATALOGUE, not the enum — that is the point of
                                // `charge_codes`: a code an accountant added is billable the
                                // moment they save it, with no deploy. Falls back to the enum
                                // if the table has not been seeded, so an un-migrated
                                // environment still renders a usable form.
                                ->options(fn () => \App\Models\ChargeCode::options()
                                    ?: \App\Enums\InvoiceItemType::options())
                                ->required()
                                ->default('base_rent')
                                ->native(false)
                                ->live()
                                // An out-of-scope supply defaults to 0% VAT, not the standard rate.
                                // The SET lives in Vat::EXEMPT_TYPES, not here: this switch used to
                                // carry its own list of two while the services originated six, so a
                                // hand-added late fee / marketing levy / fine / NSF fee picked up
                                // 14% that no service would ever have charged.
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    $set('vat_rate', Vat::rateForType(is_string($state) ? $state : null));
                                    self::recomputeItem($set, $get);
                                })
                                ->columnSpan(3),
                            TextInput::make('description')
                                ->label(__('admin.fields.description'))
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(3),
                            TextInput::make('amount')
                                ->label(__('admin.fields.amount'))
                                ->prefix('EGP')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeItem($set, $get))
                                ->columnSpan(2),
                            TextInput::make('vat_rate')
                                ->label(__('admin.fields.vat_rate'))
                                ->suffix('%')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                // The operator can still type a different rate on the line — this is
                                // only the starting point. Derived from the row's type default
                                // (`base_rent`, which is exempt): a fresh row used to render
                                // "Base Rent · 14%", contradicting itself before anything was typed.
                                ->default(fn (Get $get) => Vat::rateForType($get('type') ?: 'base_rent'))
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeItem($set, $get))
                                ->columnSpan(2),
                            TextInput::make('total')
                                ->label(__('admin.fields.total'))
                                ->prefix('EGP')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(2),
                        ]),
                    ]),

                    FormTab::make(__('admin.sections.amounts'), [


                    TextInput::make('subtotal')
                        ->label(__('admin.fields.subtotal'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0)
                        ->readOnly()
                        ->dehydrated(),
                    TextInput::make('vat_amount')
                        ->label(__('admin.fields.vat_amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0)
                        ->readOnly()
                        ->dehydrated(),
                    TextInput::make('total')
                        ->label(__('admin.fields.total'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0)
                        ->readOnly()
                        ->dehydrated(),
                    TextInput::make('balance')
                        ->label(__('admin.fields.balance'))
                        ->prefix('EGP')
                        ->numeric()
                        ->default(0)
                        ->readOnly()
                        ->dehydrated(),
                    // Show credit settled distinctly from cash paid (both fold into paid_amount otherwise),
                    // so the operator/tenant can tell how this invoice was settled. Only when it applies.
                    \Filament\Forms\Components\Placeholder::make('credit_applied_display')
                        ->label(__('admin.fields.credit_applied'))
                        ->content(fn ($record) => $record ? 'EGP '.number_format((float) $record->credit_applied_amount, 2) : '—')
                        ->visible(fn ($record) => $record !== null && (float) $record->credit_applied_amount > 0),
                    ])->columns(4),

                    FormTab::make(__('admin.sections.notes'), [



                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    /**
     * Recompute a single repeater item's vat_amount + total from amount + vat_rate,
     * then bubble up to the parent invoice totals.
     */
    protected static function recomputeItem(Set $set, Get $get): void
    {
        $amount = (float) ($get('amount') ?? 0);
        $vatRate = (float) ($get('vat_rate') ?? 0);

        $vatAmount = round($amount * $vatRate / 100, 2);
        $total = round($amount + $vatAmount, 2);

        $set('vat_amount', $vatAmount);
        $set('total', $total);

        // Walk up to the invoice level using ../../ to recalc parent totals.
        $set('../../subtotal', null); // touch parent, real recalc happens below
        self::recomputeInvoiceTotalsFromItem($set, $get);
    }

    /**
     * Triggered from within a repeater item: read all sibling items and update parent invoice totals.
     */
    protected static function recomputeInvoiceTotalsFromItem(Set $set, Get $get): void
    {
        $items = $get('../../items') ?? [];

        [$subtotal, $vat] = self::sumItems($items);
        $total = round($subtotal + $vat, 2);

        $paid = (float) ($get('../../paid_amount') ?? 0);

        $set('../../subtotal', $subtotal);
        $set('../../vat_amount', $vat);
        $set('../../total', $total);
        $set('../../balance', round($total - $paid, 2));
    }

    /**
     * Triggered at the repeater level (add/remove/reorder): recalc parent totals.
     */
    protected static function recomputeInvoiceTotals(Set $set, Get $get): void
    {
        $items = $get('items') ?? [];

        [$subtotal, $vat] = self::sumItems($items);
        $total = round($subtotal + $vat, 2);

        $paid = (float) ($get('paid_amount') ?? 0);

        $set('subtotal', $subtotal);
        $set('vat_amount', $vat);
        $set('total', $total);
        $set('balance', round($total - $paid, 2));
    }

    /**
     * Prefill the items repeater from the lease's active monthly charges,
     * but only if the form is still in its default empty state (don't clobber
     * user edits or an existing invoice's items).
     */
    protected static function prefillItemsFromLease(Lease $lease, Set $set, Get $get): void
    {
        $current = $get('items') ?? [];
        $hasUserData = false;
        foreach ($current as $row) {
            if (! empty($row['description']) || (float) ($row['amount'] ?? 0) > 0) {
                $hasUserData = true;
                break;
            }
        }
        if ($hasUserData) {
            return;
        }

        $charges = $lease->charges
            ->where('is_active', true)
            ->whereIn('frequency', ['monthly', 'one_time']);

        if ($charges->isEmpty()) {
            return;
        }

        $items = $charges->map(function ($charge) {
            $rate = $charge->vat_applicable ? (float) $charge->vat_rate : 0;
            $amount = (float) $charge->amount;
            $vatAmount = round($amount * $rate / 100, 2);

            return [
                'type' => $charge->type,
                'description' => $charge->name,
                'amount' => $amount,
                'vat_rate' => $rate,
                'total' => round($amount + $vatAmount, 2),
            ];
        })->values()->all();

        $set('items', $items);
        self::recomputeInvoiceTotals($set, $get);
    }

    /**
     * @return array{0:float,1:float} [subtotal, vat]
     */
    protected static function sumItems(array $items): array
    {
        $subtotal = 0.0;
        $vat = 0.0;

        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $rate = (float) ($item['vat_rate'] ?? 0);
            $itemVat = round($amount * $rate / 100, 2);

            $subtotal += $amount;
            $vat += $itemVat;
        }

        return [round($subtotal, 2), round($vat, 2)];
    }
}
