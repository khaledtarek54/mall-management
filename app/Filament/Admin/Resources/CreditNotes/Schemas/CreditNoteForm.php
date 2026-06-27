<?php

namespace App\Filament\Admin\Resources\CreditNotes\Schemas;

use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.credit_note_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.credit_note_number'))
                        ->disabled()
                        ->dehydrated()
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        ->options(fn () => \App\Support\TenantScope::selectableTenantOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    Select::make('invoice_id')
                        ->label(__('admin.fields.invoice'))
                        ->options(function (Get $get) {
                            $tenantId = $get('tenant_id');
                            if (! $tenantId) {
                                return [];
                            }
                            $assetId = \App\Support\TenantScope::currentAssetId();
                            return Invoice::query()
                                ->where('tenant_id', $tenantId)
                                ->when($assetId, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->where('asset_id', $assetId)))
                                ->orderByDesc('issue_date')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn ($i) => [$i->id => $i->number . ' — EGP ' . number_format((float) $i->total, 2)])
                                ->all();
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if (! $state) {
                                return;
                            }
                            $invoice = Invoice::find($state);
                            if ($invoice) {
                                $set('lease_id', $invoice->lease_id);
                            }
                        }),

                    Select::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->relationship(
                            'lease',
                            'reference',
                            modifyQueryUsing: fn ($query) => $query->when(
                                \App\Support\TenantScope::currentAssetId(),
                                fn ($q, $assetId) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)),
                            ),
                        )
                        ->searchable()
                        ->preload(),

                    Select::make('reason')
                        ->label(__('admin.fields.credit_note_reason'))
                        ->options(fn () => __('admin.enums.credit_note_reason'))
                        ->required()
                        ->default('adjustment')
                        ->native(false),

                    DatePicker::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->required()
                        ->default(now())
                        ->native(false),

                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.credit_note'))
                        ->required()
                        ->default('draft')
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
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeTotals($set, $get))
                        ->deleteAction(fn ($action) => $action->after(fn (Set $set, Get $get) => self::recomputeTotals($set, $get)))
                        ->schema([
                            TextInput::make('description')
                                ->label(__('admin.fields.description'))
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(5),
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
                                ->default(0)
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
                                ->columnSpan(3),
                        ]),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextInput::make('subtotal')->label(__('admin.fields.subtotal'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated(),
                    TextInput::make('vat_amount')->label(__('admin.fields.vat_amount'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated(),
                    TextInput::make('total')->label(__('admin.fields.total'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated(),
                    TextInput::make('balance')->label(__('admin.fields.balance'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated(),
                ]),

            Section::make(__('admin.sections.notes'))
                ->collapsible()
                ->collapsed()
                ->components([
                    Textarea::make('reason_notes')
                        ->label(__('admin.fields.reason_notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected static function recomputeItem(Set $set, Get $get): void
    {
        $amount = (float) ($get('amount') ?? 0);
        $vatRate = (float) ($get('vat_rate') ?? 0);

        $vatAmount = round($amount * $vatRate / 100, 2);
        $total = round($amount + $vatAmount, 2);

        $set('vat_amount', $vatAmount);
        $set('total', $total);

        self::recomputeTotalsFromItem($set, $get);
    }

    protected static function recomputeTotalsFromItem(Set $set, Get $get): void
    {
        $items = $get('../../items') ?? [];
        [$subtotal, $vat] = self::sumItems($items);
        $total = round($subtotal + $vat, 2);

        $applied = (float) ($get('../../applied_amount') ?? 0);

        $set('../../subtotal', $subtotal);
        $set('../../vat_amount', $vat);
        $set('../../total', $total);
        $set('../../balance', round($total - $applied, 2));
    }

    protected static function recomputeTotals(Set $set, Get $get): void
    {
        $items = $get('items') ?? [];
        [$subtotal, $vat] = self::sumItems($items);
        $total = round($subtotal + $vat, 2);

        $applied = (float) ($get('applied_amount') ?? 0);

        $set('subtotal', $subtotal);
        $set('vat_amount', $vat);
        $set('total', $total);
        $set('balance', round($total - $applied, 2));
    }

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
