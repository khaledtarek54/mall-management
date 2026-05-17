<?php

namespace App\Filament\Admin\Resources\Payments\Schemas;

use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.payment'))
                ->columns(3)
                ->components([
                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->placeholder(__('admin.fields.reference_auto'))
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->relationship('tenant', 'name')
                        ->searchable(['name', 'legal_name', 'email', 'phone'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            self::suggestAllocations($state, $get, $set);
                        }),
                    DatePicker::make('payment_date')
                        ->label(__('admin.fields.payment_date'))
                        ->required()
                        ->default(now())
                        ->native(false),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get) {
                            self::suggestAllocations($get('tenant_id'), $get, $set);
                        }),
                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        ->options(fn () => __('admin.enums.method'))
                        ->required()
                        ->native(false),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.payment'))
                        ->default('captured')
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('admin.sections.allocations'))
                ->description(__('admin.sections.allocations_helper'))
                ->components([
                    Repeater::make('allocations')
                        ->label('')
                        ->columns(12)
                        ->reorderable(false)
                        ->addActionLabel(__('admin.actions.add_allocation'))
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
                                        ->orderBy('due_date')
                                        ->get()
                                        ->mapWithKeys(fn (Invoice $i) => [
                                            $i->id => "{$i->number} · " . __('admin.fields.balance') . ': EGP ' . number_format((float) $i->balance, 0) . ' · ' . __('admin.fields.due_date') . ' ' . $i->due_date?->format('d/m/Y'),
                                        ])
                                        ->all();
                                })
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

            Section::make(__('admin.sections.gateway_cheque'))
                ->columns(2)
                ->collapsible()
                ->collapsed()
                ->components([
                    TextInput::make('gateway')
                        ->label(__('admin.fields.gateway')),
                    TextInput::make('gateway_transaction_id')
                        ->label(__('admin.fields.gateway_transaction_id')),
                    TextInput::make('cheque_number')
                        ->label(__('admin.fields.cheque_number')),
                    DatePicker::make('cheque_clearance_date')
                        ->label(__('admin.fields.cheque_clearance_date'))
                        ->native(false),
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
