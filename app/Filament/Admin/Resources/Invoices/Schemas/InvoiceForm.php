<?php

namespace App\Filament\Admin\Resources\Invoices\Schemas;

use App\Models\Lease;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.invoice_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.invoice_number'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->relationship(
                            'lease',
                            'reference',
                            modifyQueryUsing: fn ($query) => $query->when(
                                TenantScope::currentAssetId(),
                                fn ($q, $assetId) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)),
                            ),
                        )
                        ->searchable(['reference'])
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
                        ->relationship('tenant', 'name')
                        ->searchable(['name', 'legal_name', 'email', 'phone'])
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.invoice'))
                        ->required()
                        ->native(false),
                    DatePicker::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->required()
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
                        ->native(false),
                ]),

            Section::make(__('admin.sections.items'))
                ->components([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->columns(12)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('admin.actions.add_item'))
                        ->reorderable(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeInvoiceTotals($set, $get))
                        ->deleteAction(fn ($action) => $action->after(fn (Set $set, Get $get) => self::recomputeInvoiceTotals($set, $get)))
                        ->schema([
                            Select::make('type')
                                ->label(__('admin.fields.type'))
                                ->options(fn () => __('admin.enums.invoice_item_type'))
                                ->required()
                                ->default('base_rent')
                                ->native(false)
                                ->columnSpan(3),
                            TextInput::make('description')
                                ->label(__('admin.fields.description'))
                                ->required()
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
                                ->default(14)
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

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
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
                ]),

            Section::make(__('admin.sections.notes'))
                ->collapsible()
                ->collapsed()
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
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
