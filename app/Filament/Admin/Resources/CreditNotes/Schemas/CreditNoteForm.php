<?php

namespace App\Filament\Admin\Resources\CreditNotes\Schemas;

use App\Models\CreditNote;
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
        // A credit note is freely editable only while draft. Once issued it is a live
        // AR/GL document (a sales-return posting) — its target, date and line items are
        // frozen; a mistake is corrected by voiding it, not by a silent edit. Mirrors
        // the invoice lock. Status stays open so the void transition still works.
        // (GL integrity hardening — Phase 1.)
        $locked = fn (?CreditNote $record) => $record !== null && $record->status !== 'draft';
        // Derived money fields (subtotal/vat/total/balance) persist only while draft —
        // after finalization the persisted values are authoritative (see Amounts section).
        $persistDerived = fn (?CreditNote $record) => $record === null || $record->status === 'draft';

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
                        ->disabled($locked)
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),

                    Select::make('invoice_id')
                        ->label(__('admin.fields.invoice'))
                        ->disabled($locked)
                        ->options(function (Get $get) {
                            $tenantId = $get('tenant_id');
                            if (! $tenantId) {
                                return [];
                            }
                            // Scope to the user's visible properties — in All-Properties
                            // mode currentAssetId() is null, so fall back to the visible
                            // set (a shared tenant may have invoices across properties).
                            $visibleAssetIds = \App\Support\TenantScope::visibleAssetIds();
                            return Invoice::query()
                                ->where('tenant_id', $tenantId)
                                ->when($visibleAssetIds !== null, fn ($q) => $q->whereHas('lease.unit', fn ($u) => $u->whereIn('asset_id', $visibleAssetIds)))
                                ->orderByDesc('issue_date')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn ($i) => [$i->id => $i->number . ' — EGP ' . number_format((float) $i->total, 2)])
                                ->all();
                        })
                        // The options are capped at the 50 most-recent invoices, so a stored invoice
                        // that scrolled out of that window (or a disabled Select on a locked note)
                        // would render its raw id; resolve any stored value to its number.
                        ->getOptionLabelUsing(fn ($value): ?string => Invoice::find($value)?->number)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if (! $state) {
                                return;
                            }
                            $invoice = Invoice::with('items')->find($state);
                            if (! $invoice) {
                                return;
                            }
                            $set('lease_id', $invoice->lease_id);

                            // Inherit the invoice's lines (and their VAT) so a 14%-service line
                            // reverses 14% — defaulting vat_rate to 0 would silently under-reverse
                            // output VAT. Only prefill when the operator hasn't entered lines yet,
                            // so we never clobber their edits; they can then trim to a partial credit.
                            $hasLines = collect($get('items') ?? [])
                                ->contains(fn ($i) => (float) ($i['amount'] ?? 0) > 0);
                            if (! $hasLines && $invoice->items->isNotEmpty()) {
                                $rows = $invoice->items->map(fn ($it) => [
                                    'description' => $it->description,
                                    'amount' => (float) $it->amount,
                                    'vat_rate' => (float) $it->vat_rate,
                                    'vat_amount' => (float) $it->vat_amount,
                                    'total' => (float) $it->total,
                                ])->values()->all();
                                $set('items', $rows);
                                [$subtotal, $vat] = self::sumItems($rows);
                                $set('subtotal', $subtotal);
                                $set('vat_amount', $vat);
                                $set('total', round($subtotal + $vat, 2));
                                $set('balance', round($subtotal + $vat, 2));
                            }
                        }),

                    Select::make('lease_id')
                        ->label(__('admin.fields.lease'))
                        ->disabled($locked)
                        ->relationship(
                            'lease',
                            'reference',
                            // Scope to the user's visible properties — in All-Properties
                            // mode currentAssetId() is null, so without the visibleAssetIds()
                            // fallback a restricted user could pick any property's lease and
                            // credit another property's books (property isolation).
                            modifyQueryUsing: function ($query) {
                                $ids = \App\Support\TenantScope::visibleAssetIds();
                                if ($ids !== null) {
                                    $query->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $ids));
                                }
                            },
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
                        ->disabled($locked)
                        ->default(now())
                        ->native(false),

                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        // 'draft' is not a selectable target once the note is finalized —
                        // reverting would re-open the locked money fields (see the model
                        // guard in CreditNote::booted).
                        ->options(function (?CreditNote $record) {
                            $options = __('admin.statuses.credit_note');
                            if ($record && $record->status !== 'draft') {
                                unset($options['draft']);
                            }

                            return $options;
                        })
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
                        // Freeze the credit-note lines once issued (its sales-return
                        // breakdown in the GL). A disabled repeater shows them read-only.
                        ->disabled($locked)
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
                    // Persist the derived amounts only while the note is a draft. Once
                    // finalized these are readOnly (not disabled), so without this a plain
                    // Edit-save would dehydrate the STALE fill-time balance back over a
                    // value that applyToInvoice() has since changed — breaking the
                    // note's balance = total - applied_amount invariant (lost update).
                    TextInput::make('subtotal')->label(__('admin.fields.subtotal'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated($persistDerived),
                    TextInput::make('vat_amount')->label(__('admin.fields.vat_amount'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated($persistDerived),
                    TextInput::make('total')->label(__('admin.fields.total'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated($persistDerived),
                    TextInput::make('balance')->label(__('admin.fields.balance'))->prefix('EGP')->numeric()->default(0)->readOnly()->dehydrated($persistDerived),
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
