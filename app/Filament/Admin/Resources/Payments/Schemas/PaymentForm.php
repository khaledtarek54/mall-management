<?php

namespace App\Filament\Admin\Resources\Payments\Schemas;

use App\Models\Invoice;
use App\Models\Payment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use App\Support\FormTab;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        // A payment records a real cash receipt — once it exists, its amount / date /
        // method / payer are frozen (a mistake is corrected by voiding + re-recording,
        // not a silent edit that would desync the GL cash/AR movement). The allocations
        // repeater and `status` stay open: re-allocating a receipt across invoices is a
        // legitimate operation, and status still needs to move (initiated→captured,
        // captured→failed chargeback). (GL integrity hardening — Phase 1.)
        $locked = fn (?Payment $record) => $record !== null;

        // One tab per concern through App\Support\FormTab, so each carries a badge counting
        // the validation errors INSIDE it (UX-13) — Filament v4 has no error indicator on Tabs,
        // and without one a blank required field on an unseen tab refuses the form with nothing
        // visible to fix.
        return $schema->columns(1)->components([
            Tabs::make('payment')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make(__('admin.sections.payment'), [


                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->placeholder(__('admin.fields.reference_auto'))
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->disabled($locked)
                        ->relationship('tenant', 'name', modifyQueryUsing: function ($query) {
                            // Scope to tenants of the current property — a
                            // property-restricted user must not allocate a
                            // payment to another property's tenant/invoices.
                            $ids = \App\Support\TenantScope::visibleAssetIds();

                            return $query->when($ids !== null, fn ($q) => $q->whereHas(
                                'leases.unit',
                                fn ($u) => $u->whereIn('asset_id', $ids),
                            ));
                        })
                        ->searchable(['name', 'legal_name', 'email', 'phone'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            self::suggestAllocations($state, $get, $set);
                        }),
                    // Surface the tenant's on-account credit right where a payment is taken — so the
                    // operator can see it and choose to apply it (via the invoice) instead of collecting
                    // cash the tenant has already prepaid. Property-scoped; reacts to the tenant select.
                    \Filament\Forms\Components\Placeholder::make('tenant_credit_balance')
                        ->label(__('admin.fields.credit_on_account'))
                        ->visible(fn (Get $get) => (bool) $get('tenant_id'))
                        ->content(function (Get $get) {
                            $tenant = \App\Models\Tenant::find($get('tenant_id'));
                            $balance = $tenant?->creditBalance(\App\Support\TenantScope::visibleAssetIds()) ?? 0.0;

                            return $balance > 0.005 ? 'EGP '.number_format($balance, 2) : __('admin.fields.no_credit');
                        }),
                    DatePicker::make('payment_date')
                        ->label(__('admin.fields.payment_date'))
                        ->required()
                        ->disabled($locked)
                        ->default(now())
                        ->native(false),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->disabled($locked)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get) {
                            self::suggestAllocations($get('tenant_id'), $get, $set);
                        }),
                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        ->options(fn () => __('admin.enums.method'))
                        ->required()
                        ->disabled($locked)
                        ->native(false),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        // Only the forward "money in" lifecycle is manually selectable. Reversals
                        // (refunded / failed / bounced) re-open AR + void the GL cash leg, so they
                        // must go through the reason-gated Void action — never a bare status edit
                        // (which would leave no reason + no 'voided' audit event). A payment that is
                        // already received can only move WITHIN the received set (captured →
                        // reconciled → settled), never back to un-received. A terminal/reversed
                        // payment keeps showing its status read-only so the record still renders.
                        ->options(function (?Payment $record) {
                            $received = ['captured', 'reconciled', 'settled'];
                            $keys = $record && $record->isReceived()
                                ? $received
                                : array_merge(['initiated'], $received);
                            $opts = collect(__('admin.statuses.payment'))->only($keys);
                            if ($record && ! $opts->has($record->status)) {
                                $opts->put($record->status, __('admin.statuses.payment.' . $record->status));
                            }

                            return $opts->all();
                        })
                        ->disabled(fn (?Payment $record): bool => $record !== null
                            && in_array($record->status, ['refunded', 'failed', 'bounced'], true))
                        ->default('captured')
                        ->required()
                        ->native(false),
                    ])->columns(3),

                    FormTab::make(__('admin.sections.allocations'), [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.allocations_helper'))
                            ->columnSpanFull(),


                    Repeater::make('allocations')
                        ->label('')
                        ->columns(12)
                        ->reorderable(false)
                        ->addActionLabel(__('admin.actions.add_allocation'))
                        // A receipt must settle at least one invoice — an unallocated on-account
                        // advance would be orphaned in the property-scoped UI (server-guarded too).
                        ->minItems(1)
                        ->live()
                        ->schema([
                            Select::make('invoice_id')
                                ->label(__('admin.resources.invoice.singular'))
                                ->required()
                                ->options(function (Get $get) {
                                    $tenantId = $get('../../tenant_id');
                                    if (! $tenantId) {
                                        return [];
                                    }

                                    return Invoice::query()
                                        ->where('tenant_id', $tenantId)
                                        ->where('balance', '>', 0)
                                        // `balance > 0` is not "still collectable". A written-off
                                        // invoice keeps its balance on purpose — the write-off is
                                        // not a settlement channel — and a cancelled one may too.
                                        // Allocating cash to either credits AR below the debt:
                                        // the GL was already relieved (Dr Bad Debt / Cr AR), so a
                                        // payment on top drives AR negative while the bad-debt
                                        // expense stays booked for a debt that was in fact
                                        // collected. The sibling picker in PostDatedChequeForm
                                        // has always filtered status; this one never did.
                                        ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
                                        ->when(\App\Support\TenantScope::visibleAssetIds(), fn ($q, $ids) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $ids)))
                                        ->orderBy('due_date')
                                        ->get()
                                        ->mapWithKeys(fn (Invoice $i) => [$i->id => self::invoiceOptionLabel($i)])
                                        ->all();
                                })
                                // The options() list is scoped to balance > 0, so an invoice this
                                // payment already fully PAID (balance now 0) is not in it. On the
                                // edit page that stored value would otherwise render as a raw id
                                // ("6"); resolve ANY invoice's label so the row shows its number.
                                ->getOptionLabelUsing(fn ($value): ?string => ($i = Invoice::find($value))
                                    ? self::invoiceOptionLabel($i)
                                    : null)
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    if (! $state) {
                                        $set('allocated_amount', null);
                                        return;
                                    }

                                    $invoice = Invoice::find($state);
                                    if (! $invoice) {
                                        return;
                                    }

                                    // Subtract what's already allocated to other rows in this payment.
                                    $paymentAmount = (float) ($get('../../amount') ?? 0);
                                    $usedElsewhere = 0.0;
                                    foreach ($get('../../allocations') ?? [] as $row) {
                                        if (($row['invoice_id'] ?? null) == $state) {
                                            continue; // skip the current row (we're updating it)
                                        }
                                        $usedElsewhere += (float) ($row['allocated_amount'] ?? 0);
                                    }
                                    $remainingPayment = max(0, round($paymentAmount - $usedElsewhere, 2));

                                    $apply = min((float) $invoice->balance, $remainingPayment);
                                    if ($apply <= 0) {
                                        // No payment amount yet (or fully allocated) — fall back to invoice balance.
                                        $apply = (float) $invoice->balance;
                                    }

                                    $set('allocated_amount', round($apply, 2));
                                })
                                ->columnSpan(8),
                            TextInput::make('allocated_amount')
                                ->label(__('admin.fields.allocated_amount'))
                                ->prefix('EGP')
                                ->numeric()
                                ->minValue(0.01)
                                ->required()
                                // Per-row cap at the picked invoice's
                                // (balance + this row's already-allocated
                                // amount). Stops over-allocation that the
                                // existing total-only guard would miss
                                // (audit M06 F-25 / D-18).
                                ->rule(function (Get $get) {
                                    return function (string $attribute, $value, $fail) use ($get) {
                                        $invoiceId = $get('invoice_id');
                                        if (! $invoiceId) {
                                            return;
                                        }
                                        $invoice = \App\Models\Invoice::find($invoiceId);
                                        if (! $invoice) {
                                            return;
                                        }
                                        // When editing, the invoice's balance
                                        // already accounts for the existing
                                        // allocation on this row — add it back
                                        // so the cap doesn't reject a legal
                                        // edit-then-resave.
                                        $existingAllocation = 0.0;
                                        if ($paymentId = $get('../../id')) {
                                            $existingAllocation = (float) \DB::table('invoice_payment')
                                                ->where('invoice_id', $invoiceId)
                                                ->where('payment_id', $paymentId)
                                                ->value('allocated_amount') ?: 0.0;
                                        }
                                        $cap = round((float) $invoice->balance + $existingAllocation, 2);
                                        if ((float) $value > $cap + 0.005) {
                                            $fail(__('admin.payment.allocation_exceeds_balance', [
                                                'invoice' => $invoice->number,
                                                'max' => 'EGP ' . number_format($cap, 2),
                                            ]));
                                        }
                                    };
                                })
                                ->columnSpan(4),
                        ]),

                    Html::make(function (Get $get): HtmlString {
                        $payment = (float) ($get('amount') ?? 0);
                        $allocated = 0.0;
                        foreach ($get('allocations') ?? [] as $row) {
                            $allocated += (float) ($row['allocated_amount'] ?? 0);
                        }
                        $remaining = round($payment - $allocated, 2);
                        $color = abs($remaining) < 0.01
                            ? 'text-green-600 dark:text-green-400'
                            : ($remaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400');

                        $paymentTxt = __('admin.fields.amount') . ': EGP ' . number_format($payment, 2);
                        $allocTxt = __('admin.sections.allocated') . ': EGP ' . number_format($allocated, 2);
                        $remainTxt = __('admin.sections.unallocated') . ': EGP ' . number_format($remaining, 2);

                        return new HtmlString(
                            "<div class='text-sm flex gap-6'>" .
                            "<span>{$paymentTxt}</span>" .
                            "<span>{$allocTxt}</span>" .
                            "<span class='font-semibold {$color}'>{$remainTxt}</span>" .
                            "</div>"
                        );
                    }),
                    ]),

                    FormTab::make(__('admin.sections.gateway_cheque'), [




                    TextInput::make('gateway')
                        ->label(__('admin.fields.gateway')),
                    TextInput::make('gateway_transaction_id')
                        ->label(__('admin.fields.gateway_transaction_id')),
                    TextInput::make('cheque_number')
                        ->label(__('admin.fields.cheque_number')),
                    DatePicker::make('cheque_clearance_date')
                        ->label(__('admin.fields.cheque_clearance_date'))
                        ->native(false),
                    ])->columns(2),
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
     * Human label for an invoice in the allocation picker. Shared by options() and
     * getOptionLabelUsing() so the list and the stored-value label can never drift.
     */
    protected static function invoiceOptionLabel(Invoice $i): string
    {
        return "{$i->number} · " . __('admin.fields.balance') . ': EGP ' . number_format((float) $i->balance, 0)
            . ' · ' . __('admin.fields.due_date') . ' ' . $i->due_date?->format('d/m/Y');
    }

    /**
     * Auto-populate the allocations repeater with the tenant's oldest open
     * invoices, distributing the payment amount across them.
     * Only runs when allocations is empty (don't clobber user edits).
     */
    protected static function suggestAllocations(?int $tenantId, Get $get, Set $set): void
    {
        if (! $tenantId) {
            return;
        }

        $current = $get('allocations') ?? [];
        $hasUserData = false;
        foreach ($current as $row) {
            if (! empty($row['invoice_id']) || (float) ($row['allocated_amount'] ?? 0) > 0) {
                $hasUserData = true;
                break;
            }
        }
        if ($hasUserData) {
            return;
        }

        $amount = (float) ($get('amount') ?? 0);
        if ($amount <= 0) {
            return;
        }

        $remaining = $amount;
        $rows = [];

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('balance', '>', 0)
            // Same status filter as the picker above — auto-suggest must not pre-fill an
            // allocation the picker would refuse to let an operator make by hand.
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            // Scope to the active property set, mirroring the invoice picker above —
            // otherwise auto-suggest would pre-fill a shared tenant's out-of-scope
            // (other-property) invoices for a restricted user in All-Properties mode.
            ->when(\App\Support\TenantScope::visibleAssetIds(), fn ($q, $ids) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $ids)))
            ->orderBy('due_date')
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }
            $apply = min((float) $invoice->balance, $remaining);
            $rows[] = [
                'invoice_id' => $invoice->id,
                'allocated_amount' => round($apply, 2),
            ];
            $remaining = round($remaining - $apply, 2);
        }

        if (! empty($rows)) {
            $set('allocations', $rows);
        }
    }

}
