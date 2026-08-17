<?php

namespace App\Filament\Admin\Resources\Invoices\Schemas;

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Support\CatalogueTaxRate;
use App\Support\Filament\EntitySelect;
use App\Support\FormTab;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                    FormTab::make('admin.sections.invoice_details', [

                        TextInput::make('number')
                            ->label(__('admin.fields.invoice_number'))
                            ->disabled()
                            ->dehydrated(),
                        // Was forty lines of hand-rolled search + label here — the one picker in the
                        // system anyone had bothered to make readable, which is exactly why it stayed
                        // the only one. All of it now comes from App\Support\Search\OptionDisplay,
                        // including the property scope and the fold the hand-rolled `LIKE` never had.
                        EntitySelect::make('lease_id')
                            ->label(__('admin.fields.lease'))
                            ->disabled($locked)
                            ->entity(Lease::class)
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
                                self::deriveDueDate($get, $set);
                            })
                            ->required(),
                        // SHOWN, never chosen. An invoice is raised against a lease (or an
                        // ownership), and each has exactly one counterparty — so the debtor is
                        // derived, not decided. It was a free picker beside the lease picker, which
                        // meant billing Zara against Cilantro's lease was two clicks and no warning;
                        // `Invoice::assertTenantMatchesAgreement()` is what actually closes that,
                        // since a crafted request never touches this form.
                        //
                        // Displayed rather than removed: the party being billed is the one fact on
                        // an invoice nobody should have to infer, and Yardi shows it on the header
                        // for the same reason. `dehydrated()` keeps the derived value saving.
                        EntitySelect::make('tenant_id')
                            ->label(__('admin.fields.billed_to'))
                            ->entity(Tenant::class)
                            ->disabled()
                            ->dehydrated()
                            ->helperText(__('admin.helpers.invoice_tenant_derived'))
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
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveDueDate($get, $set))
                            ->native(false),
                        DatePicker::make('due_date')
                            ->label(__('admin.fields.due_date'))
                            ->required()
                            // Derived from the issue date and the LEASE's payment terms, and editable.
                            // Every service that raises an invoice already does this — the monthly run,
                            // the meter recharge, late fees, NSF fees, CAM recovery, percentage rent —
                            // so a hand-typed invoice was ageing on a different rule from a generated
                            // one, and AR ageing is the report the owner reads.
                            ->helperText(__('admin.helpers.due_date_derived'))
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

                    FormTab::make('admin.sections.items', [

                        Repeater::make('items')
                            ->relationship()
                            // THE server-side gate on the rate. The repeater is relationship-backed, so
                            // these two hooks are the only place a line is seen before it is written —
                            // the page's mutateFormDataBeforeCreate never receives the rows at all.
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => CatalogueTaxRate::enforce($data, $get('issue_date')))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get) => CatalogueTaxRate::enforce($data, $get('issue_date')))
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
                                    ->options(fn () => ChargeCode::options()
                                        ?: InvoiceItemType::options())
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
                                        self::applyTaxCodeForType(is_string($state) ? $state : null, $set, $get);
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
                                // The tax this line is billed under — picked, not typed. Until
                                // 2026-08-12 the rate below was a free 0–100 box: `Vat::rateForType()`
                                // governed the DEFAULT and nothing governed the value, so any operator
                                // could put any rate on a tax document. No reference system allows that
                                // un-gated (Yardi gates the override on rights, Odoo and SAP resolve
                                // from a tax record), and the line now records WHICH tax it carried,
                                // which is what lets the VAT return tell exempt from zero-rated.
                                Select::make('tax_code')
                                    ->label(__('admin.fields.tax_code'))
                                    ->options(fn () => TaxCode::options(TaxCode::SALES))
                                    ->default(fn (Get $get) => ChargeCode::taxCodeFor($get('type') ?: 'base_rent'))
                                    ->native(false)
                                    ->live()
                                    ->placeholder(__('admin.charge_codes.tax_unclassified'))
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        self::applyTaxCode(is_string($state) ? $state : null, $set, $get);
                                        self::recomputeItem($set, $get);
                                    })
                                    ->columnSpan(2),

                                TextInput::make('vat_rate')
                                    ->label(__('admin.fields.tax_percent'))
                                    ->suffix('%')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    // Derived from the row's type default (`base_rent`, which is
                                    // exempt): a fresh row used to render "Base Rent · 14%",
                                    // contradicting itself before anything was typed.
                                    ->default(fn (Get $get) => Vat::rateForType($get('type') ?: 'base_rent'))
                                    ->required()
                                    // READ-ONLY unless the operator holds `tax_codes.override`. Not
                                    // hidden and not removed: an override is a permission rather than a
                                    // prohibition everywhere this was benchmarked, because a contract
                                    // that fixed a rate is real — and forbidding it outright is worse
                                    // than gating it, since operators then enter the difference as an
                                    // invented line item, which is the same money made unclassifiable.
                                    ->readOnly(fn () => ! self::canOverrideTax())
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recomputeItem($set, $get))
                                    ->columnSpan(2),

                                // Only when the rate on the line has actually departed from the
                                // catalogue, and only for someone who MAY depart. For anyone else the
                                // rate is re-derived on save, so asking them to justify a departure
                                // that is about to be undone is a question with no answer. Its presence
                                // IS the override flag — there is no second boolean to fall out of step
                                // with it.
                                TextInput::make('tax_override_reason')
                                    ->label(__('admin.fields.tax_override_reason'))
                                    ->maxLength(255)
                                    ->required(fn (Get $get) => self::canOverrideTax() && self::rateDepartsFromCatalogue($get))
                                    ->visible(fn (Get $get) => self::canOverrideTax() && self::rateDepartsFromCatalogue($get))
                                    ->helperText(__('admin.helpers.tax_override_reason'))
                                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_override_reason'))
                                    ->columnSpan(4),
                                TextInput::make('total')
                                    ->label(__('admin.fields.total'))
                                    ->prefix('EGP')
                                    ->numeric()
                                    ->default(0)
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(2),
                            ]),

                        // The totals sit at the FOOT OF THE LINES, not on a tab of their own.
                        //
                        // They are derived from the repeater directly above them — `recomputeInvoiceTotals()`
                        // fires on every line change — so the number an operator is checking and the lines
                        // that produce it must be readable at the same time. On a separate tab, verifying a
                        // total meant switching away from the evidence for it and trusting memory, which is
                        // exactly where a mis-keyed line goes unnoticed. Every invoice an accountant has ever
                        // read puts the totals under the lines for this reason.
                        Section::make(__('admin.sections.amounts'))
                            ->columns(4)
                            ->schema([
                                TextInput::make('subtotal')
                                    ->label(__('admin.fields.subtotal'))
                                    ->prefix('EGP')
                                    ->numeric()
                                    ->default(0)
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('vat_amount')
                                    ->label(__('admin.fields.tax_total'))
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
                                // Show credit settled distinctly from cash paid (both fold into paid_amount
                                // otherwise), so the operator/tenant can tell how this invoice was settled.
                                // Only when it applies.
                                Placeholder::make('credit_applied_display')
                                    ->label(__('admin.fields.credit_applied'))
                                    ->content(fn ($record) => $record ? 'EGP '.number_format((float) $record->credit_applied_amount, 2) : '—')
                                    ->visible(fn ($record) => $record !== null && (float) $record->credit_applied_amount > 0),
                            ]),
                    ]),

                    FormTab::make('admin.sections.notes', [

                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    /**
     * Recompute the due date from the issue date and the lease's agreed payment terms.
     *
     * Silent when there is no lease or no issue date yet — a form mid-edit has nothing to derive
     * from, and blanking a required date the operator is about to fill would be worse than nothing.
     * The 7-day fallback is the same one every billing service applies (`?? 7`).
     */
    protected static function deriveDueDate(Get $get, Set $set): void
    {
        $issued = $get('issue_date');
        $leaseId = $get('lease_id');

        if (! is_string($issued) || $issued === '' || ! $leaseId) {
            return;
        }

        $days = Lease::whereKey($leaseId)->value('payment_terms_days');

        $set('due_date', CarbonImmutable::parse($issued)->addDays((int) ($days ?? 7))->toDateString());
    }

    /** May this operator depart from the catalogue's rate on a line? */
    protected static function canOverrideTax(): bool
    {
        return CatalogueTaxRate::mayOverride();
    }

    /**
     * The invoice's own date, which is what the rate must be resolved for.
     *
     * A line inside the repeater reaches it with `../../`. Falls back to today for a form that has
     * not filled it yet — the same answer the services give when they originate something now.
     */
    protected static function documentDate(Get $get): ?string
    {
        $date = $get('../../issue_date');

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * Point the line at the tax its charge code names, and take that tax's rate.
     *
     * Both halves matter: switching the type to a penalty must move the CODE, not merely the
     * number, or the line would keep claiming to be billed under a tax it is not.
     */
    protected static function applyTaxCodeForType(?string $type, Set $set, Get $get): void
    {
        $set('tax_code', $type === null ? null : ChargeCode::taxCodeFor($type));
        $set('vat_rate', Vat::rateForType($type, self::documentDate($get)));
        $set('tax_override_reason', null);
    }

    /** Take the rate the chosen tax carried on the invoice's date. */
    protected static function applyTaxCode(?string $taxCode, Set $set, Get $get): void
    {
        if ($taxCode === null) {
            return;
        }

        $rate = TaxCode::rateOn($taxCode, self::documentDate($get));

        if ($rate !== null) {
            $set('vat_rate', max(0.0, $rate));
            $set('tax_override_reason', null);
        }
    }

    /**
     * Does the rate on this line differ from what its tax code says for the invoice's date?
     *
     * Derived rather than stored, so the two can never disagree. A line with no tax code cannot
     * depart from anything — it is unclassified, which the floor in `Vat` already covers.
     */
    protected static function rateDepartsFromCatalogue(Get $get): bool
    {
        $taxCode = $get('tax_code');

        if (! is_string($taxCode) || $taxCode === '') {
            return false;
        }

        $resolved = TaxCode::rateOn($taxCode, self::documentDate($get));

        return $resolved !== null
            && abs(max(0.0, $resolved) - (float) ($get('vat_rate') ?? 0)) >= 0.005;
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

        // `use ($get)` — a closure does NOT inherit the enclosing scope in PHP. Without it,
        // `$get('issue_date')` inside here was an undefined variable, which under PHP 8 is an
        // ErrorException: picking a lease on the invoice form 500'd, on the primary path for
        // raising a manual invoice. It shipped in 72c2c007 and no test caught it because every
        // test that prefills items calls the service, not the form callback.
        $items = $charges->map(function ($charge) use ($get) {
            // The charge's OWN stored rate, not the catalogue's: a charge schedule was rated when
            // it was opened and re-rating it here would quietly change what a recurring line bills.
            // The tax code comes from the charge's type, so the line is still classified — and if
            // the two disagree, the form shows that as an override, which is exactly what it is.
            $rate = $charge->resolvedVatRate($get('issue_date') ? CarbonImmutable::parse($get('issue_date')) : null);
            $amount = (float) $charge->amount;
            $vatAmount = round($amount * $rate / 100, 2);

            return [
                'type' => $charge->type,
                'tax_code' => ChargeCode::taxCodeFor($charge->type),
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
