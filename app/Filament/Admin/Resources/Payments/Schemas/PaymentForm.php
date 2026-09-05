<?php

namespace App\Filament\Admin\Resources\Payments\Schemas;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Support\Filament\BankAccountField;
use App\Support\Filament\EntitySelect;
use App\Support\FormTab;
use App\Support\InvoiceSettlement;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
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
                    FormTab::make('admin.sections.payment', [

                        TextInput::make('reference')
                            ->label(__('admin.fields.reference'))
                            ->placeholder(__('admin.fields.reference_auto'))
                            ->disabled()
                            ->dehydrated(false),
                        // The lease-OR-ownership scope this form used to spell out by hand is now
                        // OptionDisplay's, so the invoice form (which had the narrower, wrong version)
                        // and this one cannot drift apart again. The searchable set widened with it:
                        // name/legal_name/email/phone became the whole folded blob, i.e. also the
                        // tenant code, WhatsApp, tax ID, commercial register and Arabic trade name.
                        EntitySelect::make('tenant_id')
                            ->label(__('admin.resources.tenant.singular'))
                            ->disabled($locked)
                            ->entity(Tenant::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                self::suggestAllocations($state, $get, $set);
                            }),
                        // Surface the tenant's on-account credit right where a payment is taken — so the
                        // operator can see it and choose to apply it (via the invoice) instead of collecting
                        // cash the tenant has already prepaid. Property-scoped; reacts to the tenant select.
                        Placeholder::make('tenant_credit_balance')
                            ->label(__('admin.fields.credit_on_account'))
                            ->visible(fn (Get $get) => (bool) $get('tenant_id'))
                            ->content(function (Get $get) {
                                $tenant = Tenant::find($get('tenant_id'));
                                $balance = $tenant?->creditBalance(TenantScope::visibleAssetIds()) ?? 0.0;

                                return $balance > 0.005 ? 'EGP '.number_format($balance, 2) : __('admin.fields.no_credit');
                            }),
                        DatePicker::make('payment_date')
                            ->label(__('admin.fields.payment_date'))
                            ->required()
                            ->disabled($locked)
                            ->default(now())
                            ->native(false),

                        // Which bank account this money moved through. `for()` takes the document
                        // class because the document declares BOTH the purpose its money belongs to
                        // and the column naming its rail — so the picker defaults to the same
                        // account `RecordsBankAccount` would have filled in, and requires one on
                        // exactly the rails the catalogue says carry bank money.
                        BankAccountField::for(Payment::class)
                            ->disabled($locked),
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
                            ->options(fn () => PaymentMethod::optionsFor('payments.method'))
                            ->required()
                            ->disabled($locked)
                            ->native(false)
                            // `->live()` so the bank-account field beside it picks up its requirement as soon as the
                            // rail changes. The refusal itself does not depend on this — `required()` is evaluated at
                            // validation with the submitted rail in state — this only decides how soon the asterisk
                            // and the helper sentence catch up.
                            ->live(),
                        Select::make('status')
                            ->label(__('admin.tables.common.status'))
                            // Only the forward "money in" lifecycle is manually selectable. Reversals
                            // (refunded / failed / bounced) re-open AR + void the GL cash leg, so they
                            // must go through the reason-gated Void action — never a bare status edit
                            // (which would leave no reason + no 'voided' audit event). A terminal or
                            // reversed payment keeps showing its status read-only so the record still
                            // renders.
                            //
                            // **`reconciled` and `settled` are NOT offered, because nothing produces
                            // them.** `payments.status` documents them as "matched in accounting" and
                            // "final", and bank reconciliation — `MatchBankStatementLineService`, the
                            // thing that decides whether a receipt appeared on a statement — writes a
                            // `BankMatch` row and never touches this column. Nothing in `app/` writes
                            // either value, and nothing reads them apart from membership in
                            // `RECEIVED_STATUSES`, which `captured` already satisfies. So the operator
                            // was choosing between three words that mean the same thing to every
                            // consumer, one of which claims a reconciliation that did not happen —
                            // a status offered as a decision with no act behind it, which is what the
                            // reversal statuses above were taken out for.
                            //
                            // The vocabulary STAYS in `ValueSets` and in both lang files: legacy and
                            // imported rows carry these values, `RECEIVED_STATUSES` must go on
                            // counting them as money in, and the fallback below keeps such a row
                            // rendering and saveable. When the reconciliation screen is given a
                            // status to write, this is the list it re-joins.
                            ->options(function (?Payment $record) {
                                $keys = $record && $record->isReceived()
                                    ? ['captured']
                                    : ['initiated', 'captured'];
                                $opts = collect(__('admin.statuses.payment'))->only($keys);
                                if ($record && ! $opts->has($record->status)) {
                                    $opts->put($record->status, __('admin.statuses.payment.'.$record->status));
                                }

                                return $opts->all();
                            })
                            // **A display everywhere except the create form (SW-240).** The narrowed
                            // options above had left exactly one manual transition — `initiated` →
                            // `captured` — and that one POSTS CASH TO THE GL (`PaymentJournalizer`
                            // posts on the received set), so it was the last place in the panel
                            // where a bare dropdown moved the books: no confirmation, no act, no
                            // reason to pause. It is the **Capture** header action now, with a
                            // confirmation that states the consequence, through
                            // `CapturePaymentService`. The create-time initiated/captured choice is
                            // the born state and stays.
                            ->disabled(fn (?Payment $record): bool => $record !== null)
                            ->default('captured')
                            ->required()
                            ->native(false),
                    ])->columns(3),

                    FormTab::make('admin.sections.allocations', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.allocations_helper'))
                            ->columnSpanFull(),

                        Repeater::make('allocations')
                            // `hiddenLabel()`, not `label('')`. A blank label makes Filament DERIVE
                            // one from the field name, so the Arabic panel read "Allocations" above
                            // the repeater; the tab it sits in already names it.
                            ->hiddenLabel()
                            ->columns(12)
                            ->reorderable(false)
                            ->addActionLabel(__('admin.actions.add_allocation'))
                            // A receipt must settle at least one invoice — an unallocated on-account
                            // advance would be orphaned in the property-scoped UI (server-guarded too).
                            ->minItems(1)
                            ->live()
                            ->schema([
                                EntitySelect::make('invoice_id')
                                    ->label(__('admin.resources.invoice.singular'))
                                    ->required()
                                    ->entity(Invoice::class)
                                    ->modifyOptionsQuery(function ($query, Get $get) {
                                        $tenantId = $get('../../tenant_id');
                                        if (! $tenantId) {
                                            return $query->whereRaw('1 = 0');
                                        }

                                        return $query
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
                                            //
                                            // Now the shared predicate rather than a literal list.
                                            // The list was a denylist that answered "has something
                                            // already relieved this?" and grew by whichever status
                                            // prompted each incident — so it never mentioned DRAFT,
                                            // which fails a different test: nothing was relieved
                                            // because nothing was ever posted. Allocating cash to a
                                            // draft credits AR the journalizer never debited, and
                                            // then flips the draft to paid, so an unissued document
                                            // becomes a live one without passing through
                                            // IssueInvoiceService at all.
                                            ->acceptingSettlement()
                                            // Oldest due first: an allocation screen works the
                                            // arrears, so the invoice that has been outstanding
                                            // longest belongs at the top.
                                            ->reorder('due_date', 'asc');
                                    })
                                    // Browse, don't guess — the query above narrows to ONE tenant's open invoices,
                                    // which is bounded by the shape of the business. `Invoice` is rightly absent from
                                    // `OptionDisplay::PRELOAD` (a portfolio holds thousands) and this is the
                                    // per-call-site opt-in CLAUDE.md describes. Without it the dropdown opens EMPTY,
                                    // which reads as "no such record" rather than "type to search" — so it is never
                                    // reported as a bug. Found in the panel on the credit-note twin (2026-08-25);
                                    // `CreditNoteForm` had already reached this conclusion and the other three had not.
                                    ->preload()
                                    // The options are scoped to balance > 0, so an invoice this
                                    // payment already fully PAID (balance now 0) is not in them. On the
                                    // edit page that stored value would otherwise render as a raw id
                                    // ("6"); resolve ANY invoice so the row shows its number. After
                                    // `->entity()`, which installs its own narrowed resolver.
                                    // …but SCOPED. `Invoice::find()` resolves anything, and Filament
                                    // derives a Select's `in:` rule from whether the label resolves —
                                    // so a bare find here overrides `EntitySelect`'s scoped resolver
                                    // and makes "the label lookup IS the write guard" untrue on this
                                    // one field. The server-side guards still bite
                                    // (`assertInvoiceAssetInScope`, `assertInvoicesShareTenant`), but
                                    // a layer that reads as a gate should be one.
                                    //
                                    // Scoped by PROPERTY only, never by balance or status: the whole
                                    // reason for resolving outside the options is that a fully-paid
                                    // invoice is legitimately absent from them on the edit page.
                                    ->getOptionLabelUsing(function ($value) {
                                        $visible = TenantScope::visibleAssetIds();

                                        $invoice = Invoice::query()
                                            ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
                                            ->find($value);

                                        return $invoice ? self::invoiceOptionLabel($invoice) : null;
                                    })
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

                                        // `settleableAmount()`, not the raw balance: a PARTIAL
                                        // write-off leaves the invoice live with its whole balance
                                        // standing, and prefilling that figure would offer a number
                                        // the model-level guard then refuses — which presents as the
                                        // page being broken rather than as a refusal.
                                        $settleable = InvoiceSettlement::settleableAmount($invoice);
                                        $apply = min($settleable, $remainingPayment);
                                        if ($apply <= 0) {
                                            // No payment amount yet (or fully allocated) — fall back
                                            // to what may still be settled.
                                            $apply = $settleable;
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
                                            $invoice = Invoice::find($invoiceId);
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
                                            // **SIBLING ROWS COUNT.** `CreatePayment::afterCreate()`
                                            // SUMS every row naming the same invoice (the pivot is
                                            // keyed by invoice id), and the repeater has no
                                            // `->distinct()`, so two legitimate-looking rows —
                                            // 700 and 600 against a 1,000 invoice — each passed this
                                            // cap independently and the receipt was refused only at
                                            // the model, after submit. Duplicate rows are supported
                                            // input, not a mistake (`PaymentFormGuardsTest` covers
                                            // 400 + 600 on one invoice), so the fix is to cap the
                                            // ROW against what its siblings have already claimed
                                            // rather than to forbid the second row.
                                            $claimedBySiblings = 0.0;
                                            $currentPath = $get('.');
                                            foreach ($get('../../allocations') ?? [] as $key => $row) {
                                                if ($get('../../allocations.'.$key) === $currentPath) {
                                                    continue;   // this row
                                                }
                                                if ((int) ($row['invoice_id'] ?? 0) === (int) $invoiceId) {
                                                    $claimedBySiblings += (float) ($row['allocated_amount'] ?? 0);
                                                }
                                            }

                                            // Same cap the model-level backstop applies, so the form
                                            // refuses with a figure rather than letting the save
                                            // throw.
                                            $cap = round(
                                                InvoiceSettlement::settleableAmount($invoice)
                                                    + $existingAllocation
                                                    - $claimedBySiblings,
                                                2,
                                            );
                                            if ((float) $value > $cap + 0.005) {
                                                $fail(__('admin.payment.allocation_exceeds_balance', [
                                                    'invoice' => $invoice->number,
                                                    'max' => 'EGP '.number_format($cap, 2),
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

                            $paymentTxt = __('admin.fields.amount').': EGP '.number_format($payment, 2);
                            $allocTxt = __('admin.sections.allocated').': EGP '.number_format($allocated, 2);
                            $remainTxt = __('admin.sections.unallocated').': EGP '.number_format($remaining, 2);

                            return new HtmlString(
                                "<div class='text-sm flex gap-6'>".
                                "<span>{$paymentTxt}</span>".
                                "<span>{$allocTxt}</span>".
                                "<span class='font-semibold {$color}'>{$remainTxt}</span>".
                                '</div>'
                            );
                        }),
                    ]),

                    FormTab::make('admin.sections.gateway_cheque', [

                        // `maxLength(255)` on all three, against `varchar(255)` — measured on
                        // `mall_management_qa`, 2026-09-03. These are the only free-text string
                        // inputs on this form and none of them was bounded; Laravel ships MySQL
                        // `strict => true`, so the database answers `SQLSTATE[22001] Data too long
                        // for column` (reproduced on that server with 300 characters) — a 500 on
                        // Create that loses the whole receipt, from pasting a long bank reference
                        // into a box that invites free text.
                        //
                        // **The suite structurally cannot see this**: SQLite does not enforce a
                        // varchar width, so the bound has to be on the FORM to exist in a test at
                        // all. The sibling `PostDatedChequeForm` had it right the whole time (100 and
                        // 200, matching its own narrower columns).
                        // **All four lock the moment the payment exists (SW-240), and the gateway
                        // pair is the one that matters.** `ChangeImpact` classifies them NEUTRAL,
                        // which is true of the LEDGER and not of the money: the Paymob callback
                        // FINDS its payment by `gateway_transaction_id` and DEDUPES a replayed
                        // callback on the `:txn:{id}:` marker inside it (`CallbackController`) —
                        // so retyping it on a live gateway payment can orphan the capture (money
                        // taken at the gateway, never recorded here) or strip the marker so the
                        // same callback captures TWICE, the double-charge class `ConcurrencyPolicy`
                        // exists to prevent. The cheque pair is the paper instrument's identity;
                        // an uncleared cheque's home is the PDC register, so a captured cheque
                        // payment is cleared money and its particulars are evidence, not a work in
                        // progress. No PANEL path fills the gateway pair after creation — the
                        // Paymob callback rewrites `gateway_transaction_id` on capture and retry,
                        // and a model write does not pass through a form lock, which is exactly
                        // where those rewrites belong. (The review corrected this sentence: its
                        // first version claimed nothing in `app/` writes them at all.)
                        TextInput::make('gateway')
                            ->label(__('admin.fields.gateway'))
                            ->disabled($locked)
                            ->maxLength(255),
                        TextInput::make('gateway_transaction_id')
                            ->label(__('admin.fields.gateway_transaction_id'))
                            ->disabled($locked)
                            ->maxLength(255),
                        TextInput::make('cheque_number')
                            ->label(__('admin.fields.cheque_number'))
                            ->disabled($locked)
                            ->maxLength(255),
                        // The one of the four that is NOT identity but a fact that ARRIVES: an
                        // INITIATED cheque payment — reachable from this same form's create-time
                        // status choice — clears later, and locking this at exists left no way to
                        // ever record when (the review's dead-end finding). So it stays open until
                        // the money is received or reversed: type the clearance date, then Capture.
                        DatePicker::make('cheque_clearance_date')
                            ->label(__('admin.fields.cheque_clearance_date'))
                            ->disabled(fn (?Payment $record): bool => $record !== null
                                && ($record->isReceived() || $record->isReversed()))
                            ->native(false),
                    ])->columns(2),
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
     * Human label for an invoice in the allocation picker. Shared by options() and
     * getOptionLabelUsing() so the list and the stored-value label can never drift.
     */
    protected static function invoiceOptionLabel(Invoice $i): string
    {
        return "{$i->number} · ".__('admin.fields.balance').': EGP '.number_format((float) $i->balance, 0)
            .' · '.__('admin.fields.due_date').' '.$i->due_date?->format('d/m/Y');
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
            // Same predicate as the picker above — auto-suggest must not pre-fill an allocation the
            // picker would refuse to let an operator make by hand. One registry, so the two cannot
            // drift again; they had already drifted apart from the PDC picker and from three
            // services.
            ->acceptingSettlement()
            // Scope to the active property set, mirroring the invoice picker above —
            // otherwise auto-suggest would pre-fill a shared tenant's out-of-scope
            // (other-property) invoices for a restricted user in All-Properties mode.
            ->when(TenantScope::visibleAssetIds(), fn ($q, $ids) => $q->whereIn('asset_id', $ids))
            ->orderBy('due_date')
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }
            $apply = min(InvoiceSettlement::settleableAmount($invoice), $remaining);
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
