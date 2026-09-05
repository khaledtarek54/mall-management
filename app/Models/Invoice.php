<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\GuardsPostingDate;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\HidesDraftsFromTenant;
use App\Models\Concerns\Invoice\AllocatesInvoiceNumber;
use App\Models\Concerns\Invoice\HasPaymentLink;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\ApplyTenantCreditService;
use App\Services\CreditNoteService;
use App\Services\DisputeInvoiceItemService;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\InvoiceSettlement;
use App\Support\OpsLog;
use App\Support\PropertySettings;
use App\Support\Translate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[NeverDeletable(correction: 'cancel the invoice, or issue a credit note')]
// Denormalized asset_id (like Disbursement / OwnerStatement). It USED to walk
// `lease.unit`, which was only safe while `lease_id` was NOT NULL — a unit owner has no
// lease, and an invoice that cannot name its property is invisible to every scoped query.
#[PropertyOwned]
// The finalisation guard already froze issue_date once an invoice is ISSUED; what
// remained was a DRAFT back-dated and then issued, posting AR into a sealed month.
#[PostingDateGuardedBy(guard: Invoice::class)]
class Invoice extends Model
{
    use AllocatesDocumentNumber, AllocatesInvoiceNumber, HasPaymentLink, RefusesDeletionOfCommittedRecords;
    use GuardsPostingDate, HasFactory, HasSearchText, HidesDraftsFromTenant, LogsActivity, SoftDeletes;

    /**
     * The invoice number, and nothing else. Everything an operator might otherwise search
     * (tenant, unit, lease) belongs to another record and is reached by relation search.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
        ];
    }

    /**
     * The column this invoice's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS).
     *
     * The finalisation guard below already freezes issue_date once an invoice is ISSUED, which
     * covered the obvious hole — but not the one that remained: a DRAFT could be created with a
     * back-dated issue_date and then issued, posting AR into a sealed month.
     *
     * **`GuardsPostingDate` closes back-DATING, not delayed ISSUING**, and the distinction is the
     * whole of the second hole: it is `isDirty($column)`-only by design, and issuing a draft moves
     * no date. That door is `SealedPeriod`'s — it now asks the poster when a document has no entry
     * yet and its `status` is dirty, which is when one is about to appear.
     */
    public static function postingDateColumn(): string
    {
        return 'issue_date';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'invoice');
    }

    protected $fillable = [
        'number',
        'asset_id',
        'lease_id',
        'unit_ownership_id',
        'tenant_id',
        'status',
        'is_opening_balance',
        'late_fee_invoice_id',
        'late_fee_for_invoice_id',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'subtotal',
        'vat_amount',
        'total',
        'paid_amount',
        'credit_applied_amount',
        'balance',
        'currency',
        'eta_submission_id',
        'eta_submitted_at',
        'eta_response',
        'eta_status',
        'eta_long_id',
        'notes',
        'owner_overdue_notified_at',
        'tenant_overdue_notified_at',
        'dunning_level',
        'tenant_notified_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'eta_submitted_at' => 'datetime',
        'owner_overdue_notified_at' => 'datetime',
        'tenant_overdue_notified_at' => 'datetime',
        'dunning_level' => 'integer',
        'tenant_notified_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credit_applied_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_opening_balance' => 'boolean',
        'eta_response' => 'array',
    ];

    /**
     * A lease OR a unit ownership raised this — never both, never neither.
     *
     * At the model rather than as a CHECK constraint, because SQLite drops CHECKs on any later
     * `->change()` to `invoices` and the guard would disappear without a word.
     *
     * @throws \DomainException
     */
    public function assertBelongsToExactlyOneAgreement(): void
    {
        if (($this->lease_id !== null) === ($this->unit_ownership_id !== null)) {
            throw new \DomainException(__('admin.errors.invoice_needs_one_agreement'));
        }
    }

    /**
     * The unit ownership this assessment was raised against — null for a lease invoice.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    /**
     * Invoices that may RECEIVE a settlement — the query half of {@see InvoiceSettlement}.
     *
     * Four channels settle an invoice and five call sites had five different opinions about which
     * invoices are eligible. Two of them ask this as a QUERY — the payment picker and its
     * auto-suggest, the PDC series sweep — and the rest ask it as a row cap; both come from the one
     * registry so they cannot drift again.
     */
    public function scopeAcceptingSettlement(Builder $query): Builder
    {
        return $query->whereNotIn('status', InvoiceSettlement::relievedStatuses());
    }

    /**
     * The agreement that raised this invoice — a lease, or a unit ownership.
     *
     * **Ask for this, never for `->lease`.** `invoices.lease_id` became nullable when module 37
     * introduced a party who holds no lease, and every read that still assumes it is set is a fatal
     * on exactly the rows that module added. `LateFeeService` was one: it resolved the lease's
     * payment terms with `$invoice->lease->paymentTermsDays()` under a comment stating the column
     * was NOT NULL — so `billing:apply-late-fees` threw on the first overdue owner assessment it
     * met, and a scheduled command that throws is the quietest failure there is. Nobody sees an
     * error; the late fees for every lease behind it in the run simply never happen. Measured on
     * the demo books: **48 invoices carry no lease, and all 48 are in an overdue-eligible status**.
     *
     * Both kinds answer the whole `BillableAgreement` contract — `paymentTermsDays()`,
     * `prorationMethod()`, the property, the debtor — so a caller that takes the agreement rather
     * than the lease needs no branch of its own.
     *
     * **`withTrashed()`, for the reason {@see deriveAssetId()} states beside it:** both agreements
     * soft-delete, `belongsTo` applies the default scope, and an invoice raised against an agreement
     * that was later trashed is still a real receivable. Reading it through the plain relation would
     * hand back null on a row whose invariant holds perfectly — and a caller that treats null as
     * "nothing to do here" would then skip exactly the debts nobody is watching.
     *
     * The loaded relation is preferred when there is one, so the nightly sweep does not pay for a
     * query per invoice on a path that already knows the answer.
     *
     * Null means the row names no agreement at all, which
     * {@see assertBelongsToExactlyOneAgreement()} refuses on save — so it is reachable only for an
     * unsaved instance, and it is returned rather than thrown so a caller can ask before the row
     * exists. It is NOT the "trashed agreement" case; that is what the paragraph above prevents.
     *
     * Call it — `$invoice->agreement()`. There is no `->agreement` property: Eloquent routes an
     * unknown attribute to a same-named method and demands a relation back, so property access
     * raises a `LogicException`.
     */
    public function agreement(): ?BillableAgreement
    {
        if ($this->lease_id !== null) {
            return $this->relationLoaded('lease') && $this->lease !== null
                ? $this->lease
                : Lease::withTrashed()->whereKey($this->lease_id)->first();
        }

        if ($this->unit_ownership_id !== null) {
            return $this->relationLoaded('unitOwnership') && $this->unitOwnership !== null
                ? $this->unitOwnership
                : UnitOwnership::withTrashed()->whereKey($this->unit_ownership_id)->first();
        }

        return null;
    }

    /**
     * The unit this invoice is about, whichever agreement raised it.
     *
     * An invoice is raised against a lease OR an ownership — never both, never neither (enforced on
     * save) — and each holds the unit differently. Answered once, here, because two surfaces ask:
     * the admin invoice table and the portal one. Before this they each reached `lease.unit.code`
     * directly, so every owner assessment rendered a blank unit to the operator AND to the owner
     * himself in the portal.
     *
     * Null is still possible and still correct — a multi-unit lease has no single unit.
     */
    public function unitCode(): ?string
    {
        return $this->lease?->unit?->code ?? $this->unitOwnership?->unit?->code;
    }

    /**
     * {@see unitCode()} under an ATTRIBUTE name — a second road to the rule, never a second answer.
     *
     * It exists because the 2026-08-18 fix (61bb1dc6) reached the two invoice TABLES, which could
     * take a `->state()` closure, and its own commit message said "two surfaces ask it". Five did
     * not, and each of them names a column rather than running a closure — an infolist entry, a
     * global-search detail, a report column, a `data_get()` cell and an export column — so all five
     * went on walking `lease.unit.code` and rendered a BLANK unit for every owner assessment: the
     * portal View page the owner himself opens to read his own bill, the top-bar search result, the
     * AR-ageing worklist, that worklist's CSV and the invoice register export.
     *
     * Measured on `mall_management_qa` 2026-09-04: `select count(*) from invoices where lease_id is
     * null` → **42** of 290, so one invoice in seven on those books printed a dash where its shop
     * belongs. A blank cell reads as "no data", not as a bug, which is why it survived a year.
     *
     * **NOT in `$appends`, deliberately.** `docs/api/openapi.json` is generated from `toArray()`,
     * and {@see InvoiceResource} already publishes `unit_code`
     * explicitly — appending it here would rewrite the generated mobile contract as a side effect
     * of a panel fix.
     */
    public function getUnitCodeAttribute(): ?string
    {
        return $this->unitCode();
    }

    /**
     * Invoices concerning one unit, through either agreement.
     *
     * The counterpart of {@see unitCode()} for the "filter by unit" controls. A lease-only clause
     * silently excluded every owner assessment, so filtering a mall by the unit an owner occupies
     * returned nothing and read as "no invoices" rather than "this filter cannot see him".
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeForUnit(Builder $query, int $unitId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('lease', fn (Builder $l) => $l->where('unit_id', $unitId))
            ->orWhereHas('unitOwnership', fn (Builder $o) => $o->where('unit_id', $unitId)));
    }

    /**
     * The property whose books this receivable belongs to — denormalized, never inferred.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Work out this invoice's property from whatever raised it.
     *
     * `withTrashed()`, deliberately: a terminated lease's unit may be soft-deleted, and its invoices
     * are still real receivables in a real mall. The Eloquent relation would scope exactly those
     * rows out and hand back null — which is the invisibility this column exists to end.
     */
    protected function deriveAssetId(): ?int
    {
        if ($this->lease_id === null) {
            // An ownership carries asset_id directly — no chain to walk, which is the whole point.
            return $this->unit_ownership_id === null
                ? null
                : UnitOwnership::withTrashed()->whereKey($this->unit_ownership_id)->value('asset_id');
        }

        // Use the loaded relation when there is one, so the billing run does not pay for a query per
        // invoice on a path that already knows the answer.
        $lease = $this->relationLoaded('lease') && $this->lease !== null
            ? $this->lease
            : Lease::withTrashed()->whereKey($this->lease_id)->first();

        return $lease?->assetId();
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<InvoiceWriteOff, $this> */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(InvoiceWriteOff::class);
    }

    /**
     * The late-fee invoice raised because THIS invoice went unpaid (story MF-08).
     *
     * A late fee used to be a line appended to the overdue invoice, which restated an issued
     * document and — since the entry is dated from `issue_date` — booked April's penalty as January
     * revenue. It is now its own dated invoice, and this is the link that makes charging it
     * idempotent under a concurrent sweep.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function lateFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'late_fee_invoice_id');
    }

    /**
     * Has a late fee already been charged for this invoice, and does it still stand?
     *
     * A CANCELLED fee invoice does not count: its GL entry is voided and the tenant owes nothing on
     * it, so the operator may charge again. Same rule as `BillViolationFineService`.
     */
    /**
     * Every late fee raised FOR this invoice, newest first — the audit trail (EG-35).
     *
     * `late_fee_invoice_id` names only the most recent, so before this relation existed the only
     * record that an earlier fee came from this invoice was a sentence inside its line description.
     */
    public function lateFeesRaised(): HasMany
    {
        return $this->hasMany(self::class, 'late_fee_for_invoice_id')->latest('issue_date');
    }

    /**
     * The most recent late fee on this invoice that has not been cancelled.
     *
     * Read from `lateFeesRaised()` rather than from `late_fee_invoice_id`, so the recurrence
     * decision and the audit trail cannot disagree about which fee is the latest. A CANCELLED fee
     * does not count — that is what lets a fee raised in error be voided and re-charged, which was
     * true before recurrence existed and stays true.
     */
    public function latestLiveLateFee(): ?self
    {
        $fromTrail = $this->lateFeesRaised()->where('status', '!=', 'cancelled')->first();

        if ($fromTrail !== null) {
            return $fromTrail;
        }

        // Fallback for a row whose back-pointer predates the backfill — and for an in-memory
        // instance whose relation has not been refreshed.
        $fee = $this->lateFeeInvoice;

        return $fee instanceof self && $fee->status !== 'cancelled' ? $fee : null;
    }

    /**
     * Bad debt accepted against this invoice so far.
     *
     * **NOT a settlement channel, and deliberately not folded into `paid_amount`.** A write-off is
     * not money arriving; `recomputeTotals()` stays the single source of truth for the four
     * channels, and `balance` keeps recording what was owed. But two consumers do need this
     * number: the write-off cap (so repeated partials cannot exceed the debt) and the AR tie-out
     * (so a partial write-off's GL relief has a matching sub-ledger expectation).
     *
     * Reversed write-offs are soft-deleted, so the default scope drops them — a recovered debt
     * correctly stops counting.
     */
    public function writtenOffAmount(): float
    {
        return round((float) $this->writeOffs()->sum('amount'), 2);
    }

    /**
     * What may still be COLLECTED — `balance` net of anything forgiven.
     *
     * **The missing third term.** `balance` answers *what was owed* and `status` answers *has this
     * left the books*; a PARTIAL write-off is neither — still on the books, still live, but smaller.
     * With nothing to express it in, every collections surface fell back to `balance` and chased the
     * forgiven slice: the overdue scan, the dunning ladder, the late-fee base, the tenant's own
     * outstanding figure and AR ageing all asked the tenant for money the operator had written off,
     * and the bad-debt entry had already relieved.
     *
     * It became sharper, not milder, once the settlement side learnt to net write-offs: the invoice
     * then could not be paid down to zero either, because the cap refused the forgiven part while
     * these reads went on demanding it.
     *
     * Derived, never stored. The sum already lives in `invoice_write_offs`, and a
     * `written_off_amount` column would be a second truth about the same money — the rule that keeps
     * per-item balances out of `invoice_item_payment`. A REVERSED write-off soft-deletes, so a
     * recovered debt becomes collectable again with no second rule to keep in step.
     */
    public function collectableBalance(): float
    {
        // Prefer an eager-loaded relation over a fresh aggregate: five consumers walk a COLLECTION
        // of open invoices, and arrears is the one dataset that never shrinks — AR ageing alone
        // would otherwise issue a query per row on every view. `with('writeOffs')` at the call site
        // makes this free; without it the behaviour is identical, just a query.
        $writtenOff = $this->relationLoaded('writeOffs')
            ? round((float) $this->writeOffs->sum('amount'), 2)
            : $this->writtenOffAmount();

        return round(max(0.0, round((float) $this->balance, 2) - $writtenOff), 2);
    }

    /**
     * What may still be PENALISED — collectable, less what is under dispute.
     *
     * Two reductions, and they are deliberately different questions. A DISPUTED amount is still
     * claimed and still chased — it is only not chargeable, which is why `disputedOutstanding()` is
     * read by the late-fee base alone and by no other collections surface. A FORGIVEN amount is not
     * claimed at all. Naming them as a pair here is what stops the next reduction (a hardship
     * deferral, a settlement in progress) becoming a fourth inline subtraction somebody has to
     * remember to add at each site — which is exactly how the forgiven slice came to be penalised.
     *
     * The FINAL floor is what matters — the order of the two subtractions does not, since both
     * orderings reduce to `max(0, balance − forgiven − disputed)`. Flooring is what stops a fully
     * forgiven, partly disputed invoice producing a negative base.
     */
    public function chargeableBalance(): float
    {
        return round(max(0.0, $this->collectableBalance() - DisputeInvoiceItemService::disputedOutstanding($this)), 2);
    }

    /**
     * The SQL twin of {@see collectableBalance()} — for a `where`, an `orderBy` or a `sum`.
     *
     * Hand-kept beside its PHP half, the discipline `HasLeaseTermState` states for its own four
     * pairs: a predicate answered one way by a query and another way by a row read is how a list and
     * a record page come to disagree about the same debt.
     *
     * `deleted_at is null` is what makes a recovered debt collectable again through the query side
     * too, matching the relation's default scope.
     */
    /**
     * {@see collectableBalance()}, read under a LOCK — for a guard that decides whether money may
     * land, rather than for a screen that renders a figure.
     *
     * A lock serialises writers; it does not make the read behind it SEE them. Under MySQL's
     * REPEATABLE READ a plain `select` inside a transaction answers from the snapshot taken before
     * it waited, so a capture that locked the invoice and then asked the plain twin decides from
     * before a concurrent write-off committed. Both of `Payment`'s over-allocation guards already
     * take this lock and say so in writing; `RecordDemoPaymentAction` did not.
     *
     * It deliberately does NOT consult a loaded relation. An eager-loaded `writeOffs` is exactly
     * what a locking read must not trust — it was fetched before the transaction, which is the
     * stale answer this method exists to avoid.
     */
    public function collectableBalanceForUpdate(): float
    {
        $writtenOff = round((float) $this->writeOffs()->lockForUpdate()->sum('amount'), 2);

        return round(max(0.0, round((float) $this->balance, 2) - $writtenOff), 2);
    }

    public static function collectableBalanceSql(string $table = 'invoices'): string
    {
        // A CASE rather than `GREATEST(…, 0)`: **GREATEST does not exist in SQLite**, and the suite
        // runs on SQLite while production runs MySQL — so a MySQL-only expression is green on the
        // real database and fatal in every test, which is this project's driver-divergence trap
        // taken in the rarer direction. `CASE WHEN … THEN … ELSE 0 END` is valid on both.
        //
        // The floor is there because the PHP half floors at zero and a twin that does not is not a
        // twin. A
        // write-off can exceed the balance on a row whose balance was later forced down —
        // `recomputeTotals()` zeroes a cancelled invoice while its write-offs stand — and an
        // unfloored expression then contributes a NEGATIVE figure to any `sum()`, netting off real
        // debt elsewhere in the same total. The five call sites all filter status today; the method
        // documents itself as safe for a bare `sum()`, so it has to actually be.
        //
        // `$table` because the string names its own columns: Laravel aliases a self-relation's inner
        // table (`whereHas('lateFeeInvoice', …)` becomes `laravel_reserved_0`), and a hardcoded
        // `invoices.` then silently binds to the OUTER row — valid SQL, no error, wrong answer. The
        // scope below passes the query's own table, which is why it takes the ALIAS off it: Laravel
        // stores the whole `invoices as laravel_reserved_0` expression in `from`, so passing it raw
        // built `invoices as laravel_reserved_0.balance - …` — a syntax error on both drivers, and
        // one the claim that used to sit here (*"the common path cannot get it wrong"*) denied.
        $net = $table.'.balance - COALESCE((select sum(amount) from invoice_write_offs '
            .'where invoice_write_offs.invoice_id = '.$table.'.id and invoice_write_offs.deleted_at is null), 0)';

        return '(case when '.$net.' > 0 then '.$net.' else 0 end)';
    }

    /** Invoices with something still collectable on them — the query half. */
    public function scopeWhereCollectable(Builder $query): Builder
    {
        return $query->whereRaw(self::collectableBalanceSql(self::qualifierFor($query)).' > 0');
    }

    /**
     * What a raw expression on this query must prefix its columns with.
     *
     * `$query->getQuery()->from` is the whole FROM expression, alias and all — for a self-relation
     * Laravel writes `invoices as laravel_reserved_0`, and concatenating that in front of
     * `.balance` yields SQL neither driver will parse. The ALIAS is the correct qualifier there,
     * and the table name everywhere else.
     */
    private static function qualifierFor(Builder $query): string
    {
        $from = $query->getQuery()->from;

        if (! is_string($from)) {
            return (new static)->getTable();
        }

        return preg_split('/\s+as\s+/i', trim($from))[1] ?? $from;
    }

    /**
     * The statuses that are OWED but must never be chased or penalised.
     *
     * `disputed` because a contested amount is still claimed and only not chargeable. `paid`
     * because the old allowlist refused it outright and that refusal is load-bearing:
     * `InvoiceSettlement::LIVE['paid']` says in writing that an invoice **can carry `status = paid`
     * with a standing balance**, and `Invoice::saving` recomputes `balance` on a header-dirty write
     * while leaving the status to the next `recomputeTotals()`. Measured through that path — a
     * receipted invoice whose `total` is later raised — a late fee applied to an invoice the
     * operator sees as PAID. No shipped screen produces the state (every UI path self-corrects, and
     * `EditInvoice` does not offer `paid` at all), but a console or data-fix write does.
     */
    private const NOT_CHASEABLE = ['disputed', 'paid'];

    /**
     * **Money still owed** — the selection twin of `collectableBalance()`.
     *
     * `acceptingSettlement()` says which invoices may RECEIVE money; asked of a whole ledger it is
     * very nearly the same partition read from the other side, and `whereCollectable()` supplies the
     * rest: an invoice that is live and has something left on it is one that is still owed. The
     * exception worth naming is `paid`, which is LIVE for a reason about receipts inside a reversal
     * window rather than about being owed — it contributes nothing while its balance agrees with
     * its status, and when it does not, the money really is outstanding (see `NOT_CHASEABLE`).
     *
     * It exists because that question was answered by a hand-kept `['issued','partially_paid',
     * 'overdue']` — **23 occurrences in `app/` at the time, of which this change routes 15** — and
     * every copy omitted **`disputed`**, which `InvoiceSettlement::LIVE` has classified as owed
     * since its first commit and which `InvoiceForm` still offers as one of the two statuses an
     * operator may set. Measured: a tenant whose only open invoice was disputed read **0.00
     * outstanding and NOT delinquent**, and their arrears were invisible to AR aging, the
     * collections worklist, the CSV and their own mobile balance.
     */
    public function scopeStillOwed(Builder $query): Builder
    {
        return $query->acceptingSettlement()->whereCollectable();
    }

    /**
     * **Past due and still owed — the ONE definition of overdue.**
     *
     * `status = 'overdue'` is a STAMP the nightly `billing:scan-overdue-invoices` writes, not the
     * question. It lags by up to a day, and `partially_paid` can never carry it at all, so reading
     * the column answers a narrower question than the one every collections surface is asking.
     * Measured on the QA baseline: 4 invoices carry the status where **11** are genuinely past due
     * and still owed, and 108 merely have something left on them.
     *
     * Both halves are load-bearing and neither is enough alone. `stillOwed()` carries LIVE (which a
     * hand-kept status list got wrong by omitting `disputed`) and COLLECTABLE (a partial write-off
     * leaves the balance standing, so a bare `balance > 0` chases money the operator forgave and the
     * bad-debt entry already relieved). The date decides whether it is late.
     *
     * It exists because that pair was written out SIX times — the invoice list's "Overdue only"
     * filter, the sidebar badge, both figures on the dashboard's Action Required card,
     * `Tenant::isDelinquent()` and `TenantBalances`' batched twin — under comments asking each other
     * to stay identical, which is a promise a comment cannot keep. The portal was the copy that
     * finally disagreed: its "Overdue Only" filter ran `whereCollectable()` alone and its dashboard
     * counted the raw status, so one screen offered three different answers to one question
     * (SW-016).
     *
     * `where`, not `whereDate`: an invoice due TODAY is overdue from midnight, which is what the
     * six call sites this replaces have always done. The two console sweeps use `whereDate` and are
     * deliberately left alone — that is a separate question about the day a fee starts accruing.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->stillOwed()->where('due_date', '<', now());
    }

    /**
     * **Money we may CHASE or PENALISE** — still owed, less `NOT_CHASEABLE`.
     *
     * The SELECTION counterpart to `chargeableBalance()`, and deliberately not its twin: this
     * filters the HEADER status, while `chargeableBalance()` subtracts LINE-level disputed
     * outstanding. They answer the same question at different grains, and both are needed —
     * `DisputeInvoiceItemService` states outright that the header status is the wrong tool because
     * an invoice is rarely disputed in full, so an invoice whose every line is disputed still
     * passes this scope and is stopped by `chargeableBalance() <= 0` at the amount instead.
     *
     * A disputed amount is still CLAIMED (so it belongs in every measurement of what the mall is
     * owed) and only not CHARGEABLE (so it belongs in no dunning letter and no late fee). The
     * overdue scan and the dunning sweep excluded disputed only as a side effect of the hand-kept
     * list they happened to copy; routing them here makes it a decision.
     */
    public function scopeChaseable(Builder $query): Builder
    {
        return $query->stillOwed()->whereNotIn('status', self::NOT_CHASEABLE);
    }

    /** The row twin of `chaseable()`, for a re-check after a locking read. */
    public function isChaseable(): bool
    {
        return ! in_array($this->status, InvoiceSettlement::relievedStatuses(), true)
            && ! in_array($this->status, self::NOT_CHASEABLE, true)
            && $this->collectableBalance() > 0;
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class)
            ->withPivot('allocated_amount')
            ->withTimestamps();
    }

    /**
     * Only the payments whose money is actually on the books.
     *
     * The same `RECEIVED_STATUSES` filter `recomputeTotals()` applies — anything that offers to
     * settle an invoice LINE (story MF-06) must not offer a payment this invoice does not count.
     */
    public function receivedPayments(): BelongsToMany
    {
        return $this->payments()->whereIn('payments.status', Payment::RECEIVED_STATUSES);
    }

    // ============ Online payment link ============

    // ============ Status helpers ============

    /**
     * The row twin of {@see scopeOverdue()} — past due AND still owed, decided the way the scope
     * decides it, because this is what the MOBILE CONTRACT sends as `is_overdue`/`days_overdue`.
     *
     * Until 2026-09-05 this was the SEVENTH spelling of the pair the scope was built to end, and
     * the one on the retailer's own phone: a hand allowlist of `issued|partially_paid` plus the raw
     * status stamp, reading no balance at all. Three provable disagreements with the scope — a
     * past-due DISPUTED invoice answered false (still claimed, so it is overdue everywhere else),
     * a `paid`-with-standing-balance one answered false, and a partially WRITTEN-OFF one whose
     * `collectableBalance()` is 0 answered `is_overdue: true, days_overdue: 47` — the app chasing
     * the operator's own forgiveness, which is exactly what `collectableBalance()` was built to
     * stop. Same construction as `isChaseable()` above, minus the NOT_CHASEABLE narrowing: a
     * disputed invoice IS overdue (the money is claimed and late), it is only not dunnable.
     */
    public function isOverdue(): bool
    {
        return ! in_array($this->status, InvoiceSettlement::relievedStatuses(), true)
            && $this->collectableBalance() > 0
            && $this->due_date->isPast();
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now());
    }

    /**
     * Re-entrancy guard for the auto-apply hook below. Applying a credit SAVES the invoice (its
     * balance drops), which would fire the same hook again.
     */
    protected static bool $applyingCredit = false;

    protected static function booted(): void
    {
        // ── Apply an on-account credit automatically (Voyager behaviour) ───────────────────────
        // Yardi applies open credit to the next charge without being asked. Hooked on the MODEL
        // rather than in the billing service because an invoice is raised from six different paths
        // (monthly run, CAM recovery, percentage-rent overage, violation fine, NSF fee, manual) and
        // a hook per path is the arrangement where one gets forgotten — the same reasoning as the
        // marketing-feed cache bust.
        //
        // The accounting is unchanged: `ApplyTenantCreditService` still posts its own dated
        // Dr Unearned / Cr AR document, still row-locks the tenant, still caps at the lesser of the
        // credit and the balance. Only the trigger is new.
        static::saved(function (self $invoice) {
            if (static::$applyingCredit) {
                return;
            }
            if ($invoice->status !== 'issued' || round((float) $invoice->balance, 2) <= 0) {
                return;
            }
            // Per PROPERTY (M-5). Reading the portfolio setting directly would have made the
            // override a value the operator saves and nothing consults — which
            // `PropertySettings`' own docblock calls worse than no override at all. The invoice
            // carries its property, so there is no ambiguity about which mall's policy applies.
            if (! PropertySettings::get('billing.auto_apply_tenant_credit', $invoice->asset_id)) {
                return;
            }

            static::$applyingCredit = true;

            try {
                app(ApplyTenantCreditService::class)->applyToInvoice($invoice);
            } catch (\DomainException $e) {
                // "This tenant has no credit to apply" is the ORDINARY case — most invoices have
                // none — and a DomainException is a refusal, not a fault (bootstrap/app.php treats
                // it as one everywhere else). Logging it would put an error line under every
                // invoice the system raises.
            } catch (\Throwable $e) {
                // Anything else IS worth hearing about, but must never cost the operator the
                // invoice: the credit stays on account and can be applied by hand.
                OpsLog::error('invoice.auto_apply_credit_failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                static::$applyingCredit = false;
            }
        });

        // The header can never be moved away from the line items — see syncTotalsFromItems().
        // Gated on "an existing invoice whose header is being written" so an ordinary save (a
        // status flip, a note) costs nothing: on CREATE there are no items yet (the repeater and
        // every billing service write them after the header), and the item hook re-derives the
        // moment they land. `readOnly()` on the form is the UX; this is the rule.
        static::saving(function (self $invoice) {
            if (! $invoice->exists) {
                return;
            }

            $headerTouched = $invoice->isDirty(['subtotal', 'vat_amount', 'total']);
            $settlementTouched = $invoice->isDirty(['balance', 'paid_amount', 'credit_applied_amount']);

            if (! $headerTouched && ! $settlementTouched) {
                return;
            }

            if ($headerTouched) {
                $invoice->syncTotalsFromItems(persist: false);
            }

            // ── `paid_amount` is not writable from here, by anyone ─────────────────────────────
            // It is derived from FOUR settlement channels and `recomputeTotals()` is the single
            // source of truth for it — which persists through `saveQuietly()`, so the legitimate
            // write never reaches this hook at all. Anything arriving here dirtying it is therefore
            // a client payload by construction, and the honest response is to discard it.
            //
            // Reverted rather than refused: the form submits this field on every save (it is
            // `readOnly()->dehydrated()`), so throwing would break ordinary edits that changed
            // something else entirely.
            if ($invoice->isDirty('paid_amount')) {
                $invoice->paid_amount = $invoice->getOriginal('paid_amount');
            }

            // ── …and `credit_applied_amount`, for exactly the same reason ──────────────────────
            // It is the SECOND of the four settlement channels, and it was missing from both lists
            // above until 2026-08-23 — so a payload dirtying it ALONE returned at the early exit
            // and persisted, which is the identical short-circuit this hook already carries a
            // paragraph about fixing for `balance`. Fixed one column, left its sibling.
            //
            // Measured before the fix: `update(['credit_applied_amount' => 5000])` stuck on a
            // committed invoice, and the next `recomputeTotals()` — any payment, any credit note,
            // the nightly sweep — folded it in as settlement: paid 0.00 → 5,000.00, balance
            // 11,400.00 → 6,400.00. An invoice reading part-settled with no credit note, no
            // payment and no deposit behind it, and the GL still carrying the full AR debit.
            //
            // Reverted rather than refused, and safe to revert, because every legitimate write
            // (`CreditNoteService`, all four of them) sets the column and then persists through
            // `recomputeTotals()` → `saveQuietly()`, which never fires this hook. Anything that
            // reaches here dirtying it is a client payload by construction.
            if ($invoice->isDirty('credit_applied_amount')) {
                // `?? 0`, because an invoice created without this attribute has no ORIGINAL for it
                // and the column is NOT NULL — reverting to a bare `getOriginal()` writes null and
                // the save dies on the constraint. Zero IS the truthful prior state of a numeric
                // column nobody has written, and it is the coercion rule this codebase already
                // keeps for NOT NULL columns.
                $invoice->credit_applied_amount = $invoice->getOriginal('credit_applied_amount') ?? 0;
            }

            // Balance follows the (possibly corrected) total in the same write — mirrors the
            // `creating` branch below. Status is left to the next recomputeTotals(), as today.
            //
            // **Recomputed whenever EITHER group is dirty, which is the fix.** This used to
            // short-circuit unless the header moved, so a payload changing `balance` alone returned
            // before reaching it and the tampered value persisted: the invoice read settled in the
            // portal, in AR aging (which filters `balance > 0`), in the overdue scan and on every
            // collections screen, while the GL still carried the AR debit.
            $invoice->balance = $invoice->status === 'cancelled'
                ? 0
                : round(max(0, (float) $invoice->total - (float) $invoice->paid_amount), 2);
        });

        static::creating(function (self $invoice) {
            // ── The invoice's own property, resolved once ─────────────────────────────────────
            // Four things used to walk lease → unit → asset for this: isolation, the GL's asset
            // dimension, the number prefix below, and the marketing-levy accrual. `lease_id` is
            // becoming nullable (a unit OWNER has no lease), so the property is settled here, on the
            // row, and every one of them reads the column instead. See the migration for why the
            // column is nullable in the schema and never null in practice.
            //
            // `IssueInvoiceService` already passes it — the agreement knows its own property, so the
            // billing path costs no query. This fills it for every other path: the Filament form,
            // the factory, a fixture.
            if ($invoice->asset_id === null) {
                $invoice->asset_id = $invoice->deriveAssetId();
            }

            // Exactly one agreement raised this document — a lease OR a unit ownership. "Neither"
            // is the silent one: an invoice attached to nothing still ages and still duns, but no
            // screen that starts from an agreement will ever show it.
            $invoice->assertBelongsToExactlyOneAgreement();

            if ($invoice->asset_id === null) {
                // Refused rather than defaulted. An invoice with no property is invisible to every
                // property-scoped screen and posts to the GL with no dimension — it would read as
                // "saved" and behave as though it did not exist.
                throw new \DomainException(__('admin.errors.invoice_without_property'));
            }

            // Always (re)generate at save time so we never persist a stale
            // form-cached number that could collide with another record. The
            // prefix is the property's code (INV-AW-…), now read from the invoice's
            // own asset rather than inferred through the lease.
            $assetCode = $invoice->asset?->code ?: 'AW';

            // A migrated OPENING ITEM keeps the operator's own number.
            //
            // The always-regenerate rule above is right for an invoice this system issues —
            // nothing legitimately supplies one, so a stale form-cached number can only be wrong.
            // An opening item is the exception that proves it: its number is the one printed on
            // the paperwork the retailer already holds, and the reason to load open items rather
            // than a lump-sum balance is precisely so an operator can quote it on a collections
            // call. Renumbering it would make that call unanswerable.
            $keepsItsOwnNumber = $invoice->is_opening_balance && filled($invoice->number);

            if (! $keepsItsOwnNumber) {
                $invoice->number = $invoice->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $invoice->issue_date),
                    fn (): string => static::generateUniqueNumber($assetCode, $invoice->issue_date),
                );
            }

            if (empty($invoice->currency)) {
                $invoice->currency = 'EGP';
            }
            if ($invoice->balance === null) {
                $invoice->balance = (float) ($invoice->total ?? 0) - (float) ($invoice->paid_amount ?? 0);
            }
            // Pre-generate the public pay-link token so the API/admin/portal never
            // write during a read. Existing invoices get one lazily (paymentLinkToken).
            if (blank($invoice->payment_link_token)) {
                $invoice->payment_link_token = Str::random(48);
            }
        });

        // Finalized (issued+) invoice immutability guard (GL integrity — Phase 1).
        // A draft is freely editable; once issued the invoice is a live AR/GL document:
        //   1. it cannot be reverted to draft (that would re-open the form-locked fields);
        //   2. its GL-identity fields (issue_date = period, tenant/lease = AR dimension)
        //      are immutable — no system path rewrites them (LateFeeService/CAM touch only
        //      subtotal/total/items, which stay writable, and via saveQuietly so they skip
        //      this event anyway).
        // Defense-in-depth behind the form lock — closes the JS-tamper / API / tinker path.
        static::updating(function (self $invoice) {
            // The property can never be CLEARED, on a draft or otherwise. The column is nullable at
            // the schema level only because tightening it would mean `->change()` on `invoices`,
            // which on SQLite silently drops the CHECK constraints guarding `status`/`eta_status`
            // (see the migration). This is where that nullability is taken back.
            if ($invoice->isDirty('asset_id') && $invoice->asset_id === null) {
                throw new \DomainException(__('admin.errors.invoice_without_property'));
            }

            // An edit must not be able to leave the document belonging to both agreements or to
            // neither — the create-time invariant, applied again to the path that could undo it.
            if ($invoice->isDirty(['lease_id', 'unit_ownership_id'])) {
                $invoice->assertBelongsToExactlyOneAgreement();
            }

            // Captured CASH blocks a cancel — on EVERY path, not just VoidInvoiceService, and
            // in `updating` so the write is refused rather than merely reported.
            //
            // The service has always refused this; the status Select on the invoice form offered
            // `cancelled` as a plain option and walked straight past it. Measured: an invoice with
            // a captured 10,000 payment cancelled cleanly from the form — status=cancelled, balance
            // forced to 0, payment still captured and still allocated 10,000, tenant credit 0. The
            // money was neither receivable nor owed back; it had vanished from every
            // operator-visible surface while the cash sat in the GL.
            //
            // (First written in the `updated` hook, which fires AFTER the row is persisted: the
            // exception surfaced but the cancel still happened. The regression test caught it.)
            //
            // capturedCashPaid() is the same predicate VoidInvoiceService uses — paid, net of
            // credit notes and applied tenant credit — named once so the two cannot drift.
            // Reversible non-cash settlement nets to zero here and is allowed; only real cash
            // refuses, and the remedy is to void/refund the payment first.
            if ($invoice->status === 'cancelled'
                && $invoice->getOriginal('status') !== 'cancelled'
                && $invoice->capturedCashPaid() > 0) {
                throw new \DomainException(__('admin.actions.cancel_blocked_captured_cash', [
                    'number' => $invoice->number,
                ]));
            }

            // **A standing WRITE-OFF blocks a cancel on every path too, and this is where it has to
            // live** (SW-023). `VoidInvoiceService` refuses it — and `LeaseTerminationService`
            // cancels open invoices with a direct `update(['status' => 'cancelled', …])` that never
            // goes near the service. Its filter is `status in (draft, issued, partially_paid,
            // overdue) AND balance > 0 AND paid_amount = 0`, and a partially written-off invoice
            // matches every clause precisely BECAUSE a write-off leaves `balance` standing and is
            // not a settlement channel. So the most common cancel in the system — it is the
            // `cancel_open_invoices` tick, and it DEFAULTS to on — walked straight past the guard,
            // leaving the write-off's `Cr AR` with no document behind it.
            //
            // Exactly the reasoning the captured-cash guard above states for itself: on EVERY path,
            // and in `updating` so the write is refused rather than merely reported.
            if ($invoice->status === 'cancelled'
                && $invoice->getOriginal('status') !== 'cancelled'
                && $invoice->writeOffs()->exists()) {
                throw new \DomainException(__('admin.refusals.invoice_void_has_write_off', [
                    'number' => $invoice->number,
                ]));
            }

            // A write-off is an ACCOUNTING ACT, not a status. `WriteOffInvoiceService` posts
            // Dr Bad Debt / Cr AR against an `InvoiceWriteOff` row, records the reason, refuses a
            // closed period and enforces the outstanding cap — and it is gated on `invoices.void`.
            // The status Select on this form needs only `invoices.edit` and offered `written_off`
            // as a plain option, so a role deliberately denied the write-off could produce its
            // whole effect without any of it: no bad-debt entry, no reason, no row, and live AR
            // gone from the overdue sweep, the late-fee sweep, the dunning ladder and both payment
            // pickers. It was also a one-way door — "Write off" hides once the status is set and
            // "Reverse write-off" hides while no `InvoiceWriteOff` row exists — so nothing on any
            // screen could put it back.
            //
            // The real path is unaffected: the service assigns the status with `saveQuietly()`,
            // which fires no model events, exactly as `recomputeTotals()` does. This refuses the
            // ordinary save — the form, an importer, a crafted Livewire payload — and the presence
            // of the row is what tells the two apart, so a legitimate write-off can never be
            // blocked by its own guard.
            if ($invoice->status === 'written_off'
                && $invoice->getOriginal('status') !== 'written_off'
                && ! InvoiceWriteOff::where('invoice_id', $invoice->id)->exists()) {
                throw new \DomainException(__('admin.refusals.invoice_write_off_is_an_act'));
            }

            if ($invoice->getOriginal('status') === 'draft') {
                return; // draft is freely editable (and draft→issued must be allowed)
            }
            if ($invoice->status === 'draft') {
                throw new \DomainException(__('admin.refusals.invoice_no_return_to_draft'));
            }
            // `number` joined this list 2026-08-12. It identifies the tax invoice the tenant is
            // holding — and that the ETA may have seen — so rewriting it silently re-labels a
            // document that exists outside this system. `ChangeImpact` classified it DESCRIPTIVE on
            // the stated grounds that "AllocatesDocumentNumber assigns it in `creating` and nothing
            // rewrites it", which was true of the code and not of the form: `InvoiceForm` renders it
            // `disabled()->dehydrated()`, and `disabled` is an HTML attribute while `dehydrated()`
            // is an explicit opt-IN to the submitted payload.
            // `asset_id` joins the list: it IS the GL's property dimension now, so moving it on an
            // issued invoice books the revenue into another mall's P&L and another owner's
            // statement — the very thing `lease_id` was refused for before it stopped carrying it.
            foreach (['issue_date', 'asset_id', 'tenant_id', 'lease_id', 'unit_ownership_id', 'number'] as $field) {
                if ($invoice->isDirty($field)) {
                    throw new \DomainException(__('admin.refusals.immutable_invoice', ['field' => Translate::orHumanized("admin.fields.{$field}", $field)]));
                }
            }
        });

        // Cancelling/un-cancelling an invoice changes whether its marketing levy
        // counts toward the fund (recomputeAccrued excludes cancelled). The item
        // hook doesn't fire on a status-only change, so re-derive here.
        static::updated(function (self $invoice) {
            if (! $invoice->wasChanged('status')) {
                return;
            }

            // CANCELLING an invoice that consumed credit would lose that credit
            // against a row that leaves the books — return it to the tenant as an
            // offsetting credit note. NOT 'credited': that is the terminal
            // paid-BY-credit-note state (it STAYS on the books, revenue recognised),
            // so its credit is the intended settlement and must stay consumed —
            // reversing it there would double-refund + drive net AR negative.
            // Read the PERSISTED credit_applied_amount (the in-memory instance may
            // be stale — credit was applied to a separately-locked copy) so the
            // reversal can't be silently skipped. (saveQuietly inside → no recursion.)
            if ($invoice->status === 'cancelled') {
                $appliedCredit = (float) static::whereKey($invoice->id)->value('credit_applied_amount');
                if ($appliedCredit > 0) {
                    app(CreditNoteService::class)->reverseAppliedCredit($invoice->fresh());
                }
                // Applied tenant CREDIT (on-account draw-downs) likewise returns to the tenant — soft-delete
                // the applications so their Dr Unearned / Cr AR entries void and the credit frees up again.
                // Fires on ANY cancel path, not just VoidInvoiceService, so a credit can never strand on a
                // voided invoice (else AR/Unearned would be left holding a reversed-but-still-applied credit).
                foreach (TenantCreditApplication::where('invoice_id', $invoice->id)->get() as $app) {
                    $app->delete();
                }

                // A netted SECURITY DEPOSIT returns the same way (MF-03). Without this the deposit
                // would stay spent on an invoice that no longer claims any AR — the tenant's
                // refund permanently short by the amount, and Deposits Held holding a balance
                // against a receivable that left the books.
                foreach (DepositApplication::where('invoice_id', $invoice->id)->get() as $app) {
                    $app->delete();
                }
            }

            if ($invoice->status !== 'cancelled' && $invoice->getOriginal('status') !== 'cancelled') {
                return; // neither old nor new status is cancelled — accrual unaffected
            }
            // The invoice's own column: the lease chain is null for a unit-owner assessment.
            $assetId = $invoice->asset_id;
            $year = optional($invoice->issue_date)->year;
            if ($assetId && $year && $invoice->items()->where('type', 'marketing')->exists()) {
                MarketingBudget::forPeriod($assetId, (int) $year)->recomputeAccrued();
            }
        });
    }

    /**
     * Re-derive the header (subtotal / vat_amount / total) from the line items. **The single
     * implementation of "an invoice's items sum to its header"** — every writer goes through it.
     *
     * The rule is load-bearing, not cosmetic: `InvoiceJournalizer` debits AR with the HEADER
     * total and credits revenue from the ITEM amounts (split by charge type) plus item VAT, so a
     * divergence computes the two sides of one journal entry from two different numbers. And
     * `recomputeTotals()` derives `balance` from the same header, so it is also the amount the
     * tenant is chased for.
     *
     * It used to live at the FORM layer only (`InvoiceForm` recomputes in `afterStateUpdated` and
     * renders the three fields `readOnly()`) — but `readOnly` is an HTML attribute, the fields are
     * `dehydrated()`, and nothing re-derived server-side: a tampered Livewire payload persisted a
     * header of 1 against 12,280 of items, and a direct item write (API / import / console / a
     * service that forgot) moved the items without moving the header at all. Promoted to the model
     * by the 2026-08-11 validation sweep; the form keeps its live recompute purely for the inline UX.
     *
     * An invoice with NO items is deliberately left alone — a legacy / opening-balance row carries
     * a header and has nothing to derive it from, so zeroing it would erase real AR.
     *
     * @param  bool  $persist  false = mutate the attributes in flight only (for the `saving` hook,
     *                         which must not save inside a save).
     */
    public function syncTotalsFromItems(bool $persist = true): void
    {
        if (! $this->exists) {
            return;
        }

        $items = $this->items()->get(['amount', 'vat_amount']);
        if ($items->isEmpty()) {
            return;
        }

        $subtotal = round((float) $items->sum('amount'), 2);
        $vat = round((float) $items->sum('vat_amount'), 2);

        $this->subtotal = $subtotal;
        $this->vat_amount = $vat;
        $this->total = round($subtotal + $vat, 2);

        if ($persist) {
            // recomputeTotals() saveQuietly()s, so it re-derives balance/status off the new
            // total and cannot re-enter the `saving` hook below.
            $this->recomputeTotals();
        }
    }

    /**
     * What period this invoice covers, in words — the SPAN, never just the month it opens in.
     *
     * A quarterly lease raises one invoice for three months, and a statement that printed
     * `period_start` alone showed "Apr 2026" against 240,300 of April–June rent. The tenant reads
     * that as one month's rent at three times the rate and disputes it; the operator has to open the
     * invoice to explain a document that should have explained itself.
     *
     * Kept on the model rather than in the Blade because the portal, the mobile API and the invoice
     * PDF all answer the same question, and three copies of a date rule drift into three answers.
     */
    public function periodLabel(): string
    {
        if (! $this->period_start) {
            return '—';
        }

        $locale = app()->getLocale();
        $start = $this->period_start->locale($locale);

        if (! $this->period_end) {
            return $start->isoFormat('MMM YYYY');
        }

        $end = $this->period_end->locale($locale);

        return match (true) {
            // One month — "Apr 2026", the only case the old label was right about.
            $start->isSameMonth($end) => $start->isoFormat('MMM YYYY'),
            // Within one year the year is stated once: "Apr – Jun 2026".
            $start->year === $end->year => $start->isoFormat('MMM').' – '.$end->isoFormat('MMM YYYY'),
            // An annual cycle straddling December needs both: "Dec 2026 – Feb 2027".
            default => $start->isoFormat('MMM YYYY').' – '.$end->isoFormat('MMM YYYY'),
        };
    }

    /**
     * Recompute paid_amount / balance / status. **The single source of truth for AR balances.**
     *
     * `paid_amount` is the sum of FOUR channels — captured payments, applied credit notes, applied
     * tenant credit, and a security deposit netted at move-out. Nothing else may write it.
     */
    public function recomputeTotals(): void
    {
        $paid = (float) $this->payments()
            ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
            ->sum('invoice_payment.allocated_amount');

        // Applied credit notes settle AR too (they bump credit_applied_amount,
        // not the payments pivot) — include them so a later payment recompute
        // doesn't erase the credit.
        $paid += (float) $this->credit_applied_amount;

        // Applied tenant CREDIT (an on-account advance drawn onto this invoice) also settles AR.
        // It is its own document (Dr Unearned / Cr AR); soft-deleted (reversed) rows are excluded,
        // so reversing an application re-opens the AR here on the next recompute.
        $paid += (float) TenantCreditApplication::where('invoice_id', $this->id)->sum('amount');

        // A SECURITY DEPOSIT netted against this invoice at move-out (story MF-03) — the fourth
        // and last channel. Its own document too (Dr Deposits Held / Cr AR), soft-deleted on
        // reversal, so the AR re-opens and the deposit balance returns on the next recompute.
        //
        // Every calculation that decides "how much of this invoice is settled" must count all four.
        // Three of them were added one at a time and each time something downstream had to learn
        // about it; if a fifth is ever needed, grep for this comment first.
        $paid += (float) DepositApplication::where('invoice_id', $this->id)->sum('amount');

        $this->paid_amount = round($paid, 2);
        $this->balance = round(max(0, (float) $this->total - $this->paid_amount), 2);

        // A cancelled invoice claims no AR — force balance to 0 (it left the books).
        // Also prevents a phantom 'total' balance after a cancel-reversal zeroes the
        // applied credit (paid→0 would otherwise re-derive balance = total).
        if ($this->status === 'cancelled') {
            $this->balance = 0;
        }

        // Auto-status: don't override manual overrides like 'cancelled' / 'credited' / 'disputed'.
        // 'written_off' joins the manual overrides: a debt accepted as uncollectible must not be
        // dragged back to 'overdue' by the next recompute. Reversing a write-off is what re-opens
        // it (WriteOffInvoiceService::reverse), not a side effect of recalculating a balance.
        //
        // **And 'draft', which was the one being promoted (SW-215).** `InvoiceItem::saved` calls
        // `syncTotalsFromItems()` → here, so writing a LINE onto a draft issued it — measured
        // through the real create page: the operator picks Draft, the invoice is stored `issued`.
        // That put an unissued document in front of the tenant, on the books and in the GL, and the
        // form drops `draft` from its options once the status has moved, so there was no way back.
        // A draft with no lines is not a document anybody wants; a draft is precisely an invoice
        // WITH lines that has not been raised yet, so the promotion fired on the only case that
        // matters. `InvoiceSettlement` already recorded this in writing as a reason to refuse cash
        // against a draft — *"an unissued document becomes a live one without ever passing through
        // IssueInvoiceService"* — as a hazard to route around rather than a thing to fix.
        //
        // Only the STATUS is frozen: `paid_amount` and `balance` above still recompute, so a draft
        // that somehow carries a settlement still reports the right figures. Issuing stays an ACT —
        // `IssueInvoiceService` states the status at create, and the panel's Select is the other
        // door — never a side effect of saving a line.
        if (! in_array($this->status, ['draft', 'cancelled', 'credited', 'disputed', 'written_off'])) {
            if ($this->balance <= 0 && $this->paid_amount > 0) {
                $this->status = 'paid';
            } elseif ($this->paid_amount > 0) {
                $this->status = 'partially_paid';
            } elseif ($this->due_date && Carbon::parse($this->due_date)->isPast()) {
                $this->status = 'overdue';
            } else {
                $this->status = 'issued';
            }
        }

        $this->saveQuietly();
    }

    /**
     * The portion of paid_amount that is real captured CASH — i.e. excluding the reversible,
     * non-cash settlements (applied credit notes, applied tenant credit, netted security deposit).
     * This is what must be refunded / reversed before an invoice can be voided; a voidable invoice
     * has captured cash ≤ 0. Named here once so the void guard (service + Filament visible()) can't
     * drift on how they are treated.
     *
     * A netted deposit belongs on the exclusion list for the same reason an applied credit does: no
     * cash arrived, and the settlement reverses by soft-deleting its own document. Counting it as
     * cash would refuse to void an invoice that has nothing to refund.
     */
    public function capturedCashPaid(): float
    {
        return round(
            (float) $this->paid_amount
            - (float) $this->credit_applied_amount
            - (float) TenantCreditApplication::where('invoice_id', $this->id)->sum('amount')
            - (float) DepositApplication::where('invoice_id', $this->id)->sum('amount'),
            2,
        );
    }

    /** A draft invoice has not been issued to anyone; anything past draft is on the books. */
    public function isCommittedForDeletionPurposes(): bool
    {
        return $this->status !== 'draft';
    }
}
