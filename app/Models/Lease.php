<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\HidesDraftsFromTenant;
use App\Models\Concerns\Lease\ActsAsBillableAgreement;
use App\Models\Concerns\Lease\DeterminesFitOutGrace;
use App\Models\Concerns\Lease\HasCamTerms;
use App\Models\Concerns\Lease\HasLeasePremises;
use App\Models\Concerns\Lease\HasLeaseTermState;
use App\Models\Concerns\Lease\HasRenewalLineage;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\ChargeScheduleService;
use App\Services\MarketingLevyService;
use App\Services\RentEscalationService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\DepositBasis;
use App\Support\DepositBilling;
use App\Support\DocumentNumbering;
use App\Support\LeaseActivation;
use App\Support\Translate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[DeletableWhenUnused(blockedBy: ['invoices', 'charges', 'salesDeclarations', 'camAllocations', 'tenantRequests', 'renewals', 'deposits', 'postDatedCheques', 'events'], instead: 'terminate the lease — that is the documented end of a tenancy, and it keeps the billing history')]
#[PropertyOwned(via: 'unit')]
class Lease extends Model implements BillableAgreement, HasMedia
{
    use ActsAsBillableAgreement, AllocatesDocumentNumber, DeterminesFitOutGrace, HasCamTerms, HasFactory, HasLeasePremises, HasLeaseTermState, HasRenewalLineage, HasSearchText, HidesDraftsFromTenant, InteractsWithMedia, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;
    use HasCustomFields;

    /** The signed contract + supporting paperwork. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /**
     * A holdover rate is a PERCENTAGE OF the contracted rent, so 100 is the floor.
     *
     * `$lastRent * $rate / 100` — 150 means 150%, the shipped default. Below 100 prices overstaying
     * BELOW renewing, which is the opposite of what a holdover clause is for; a genuinely reduced
     * wind-down rent is a rent change or a relief, not a holdover.
     *
     * Here rather than in the three places that stated it, because they DISAGREED. Measured at HEAD
     * on 2026-09-03: the conversion modal floored at 100 under a comment explaining why,
     * `ConvertLeaseToHoldoverService` at "greater than zero", and the PORTFOLIO DEFAULT that modal
     * prefills itself from — `billing.holdover_default_rate_pct` on /admin/settings — at 0. So an
     * operator could save 80, press Convert to holdover, and be refused on a field they had never
     * touched, quoting a minimum the settings screen had just accepted below.
     */
    public const HOLDOVER_MIN_RATE_PCT = 100.0;

    /**
     * A lease is found by its reference. Tenant and unit are reached through relation
     * search against THEIR blobs — never copied into this one (see the trait docblock).
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,

            // The operator's own fields (D-7). `metadata` is this row's own attribute, so this
            // honours the no-relations rule and re-folds whenever the record saves.
            ...$this->customFieldSearchValues(),
        ];
    }

    protected static function booted(): void
    {
        // ── Allocate a reference when none was supplied — under the lock, held across the INSERT ──
        //
        // Deliberately NOT Invoice's "always re-generate" rule, and the difference is the point.
        // Nothing legitimately supplies an invoice NUMBER, so overwriting one is free. A lease
        // reference is different: **importing an operator's existing leases means importing the
        // contract references they already use**, and those must survive the insert. Overwriting
        // unconditionally also silently renamed any lease created with a deliberate reference.
        //
        // A supplied duplicate is therefore refused by the UNIQUE index rather than quietly
        // renumbered, which is the correct answer for someone else's data. Generated references
        // are safe by construction: `generateUniqueReference()` is MAX-based over `withTrashed()`
        // with a collision loop, and the lock stops two concurrent creates racing to the same
        // number. If the lock times out, the loop and the index remain.
        static::creating(function (self $lease) {
            if (filled($lease->reference)) {
                return;
            }

            $assetCode = $lease->unit?->asset?->code ?: 'AW';

            $lease->reference = $lease->allocateDocumentNumber(
                static::referencePrefix($assetCode),
                fn (): string => static::generateUniqueReference($assetCode),
            );
        });

        // ── AN EXECUTED LEASE THAT HAS NOT STARTED IS `future`, NOT `active` ──────────────────
        //
        // Derived on the WRITE as well as swept nightly, and both halves are needed. The sweep
        // alone leaves a lease keyed today reading `active` until 05:15 tomorrow, which is exactly
        // long enough to overstate occupancy on the screen the person who keyed it is looking at;
        // the write alone cannot notice the morning the term actually starts, because that is a day
        // on which nothing happens. Same pairing `Unit::recomputeStatus()` and `leases:expire`
        // already form for the unit column.
        //
        // On the MODEL rather than at the four doors that execute a lease (the wizard, the renewal,
        // the form, the importer) for the reason `ValueSets::guard()` is one wildcard listener: the
        // fifth door is covered by existing rather than by its author remembering.
        //
        // Only `active` is rewritten. A draft stays a draft, and none of the four terminal statuses
        // is touched — `renewed`, in particular, is a decision about a term that HAS run, and a
        // renewal is routinely dated ahead.
        static::saving(function (self $lease) {
            if ($lease->status === 'active') {
                $lease->status = self::executedStatusFor($lease->commencement_date);
            }
        });

        // ── The escalation clause and its terms are kept consistent on EVERY write ─────────────
        // This was a `creating` hook, which covered exactly half of the problem.
        //
        //  1. **Arming.** The daily `leases:apply-escalations` sweep keys on
        //     `next_escalation_date`, and no creation path used to populate it — so escalation
        //     silently never ran for a single real lease. That was fixed on create. But adding a
        //     clause to an EXISTING lease (the ordinary way an operator records a term they missed,
        //     or switches `none` → `fixed_percent`) still left the column null, and `whereNotNull`
        //     excluded the lease for the rest of its term. The same dead feature, one edit away.
        //
        //  2. **Clearing.** The form shows a rate box only for the types that state a rate, so a
        //     lease switched to `none` keeps whatever rate, amount and collar it had — invisible in
        //     the UI, still sitting in the columns, and read again the moment somebody switches the
        //     type back. A field the operator cannot see must not hold a value that can take effect.
        //
        // Both belong on `saving` and in the MODEL rather than the form: the importer, the API and
        // `LeaseRenewalService` all write leases without ever rendering a field.
        //
        // The anniversary stays `commencement + 1 year` even when that is in the past. A backlog is
        // already how this system models a late clause — the sweep applies ONE step per run and
        // rolls forward, so a mid-term lease catches up over successive nights instead of
        // compounding several years in a single pass.
        //
        // **Clearing keys on the TYPE; arming keys on the FIGURE**, and the asymmetry is load-bearing.
        // `none` is the only value that means "there is no clause", and it is the only one whose
        // fields the form hides — so it is the only one whose terms may be discarded. A
        // `fixed_percent` stated at 0% is a different animal: it is a real clause with a zero step,
        // the rate box is on screen, and `RentEscalationService` deliberately keeps such a lease in
        // its sweep so it can roll the date forward once a year rather than reconsider it every
        // night. Clearing on `! escalatesContractually()` looked equivalent and dropped exactly
        // those leases out of the sweep for good (caught by `RentEscalationTest` and
        // `FixedAmountEscalationTest`, both of which pin the rolled date).
        static::saving(function (self $lease) {
            if ($lease->escalation_type === 'none') {
                $lease->escalation_rate = 0;
                $lease->escalation_amount = null;
                $lease->escalation_floor_rate = null;
                $lease->escalation_ceiling_rate = null;
                $lease->escalation_applies_to_service_charge = false;
                $lease->next_escalation_date = null;

                return;
            }

            // ── THE POINTER FOLLOWS THE INTERVAL (2026-09-11) ─────────────────────────────
            //
            // Armed once when null, and never re-armed: change *Steps every* from blank (12) to
            // 6 on a fresh lease and the first step stayed at +12, then walked every 6 from
            // there. Re-armed from the SWEEP'S OWN STATE: the pointer it carries is always the
            // anniversary after the last one it applied (or after commencement when none), so
            // one old interval back from it is that anniversary, and one NEW interval on from
            // there is the next. A first cut read the last projected rung that had STARTED
            // instead, and the review broke it: rungs start on the 1st and the sweep applies on
            // the anniversary day, so an edit between the two read a rung as applied while
            // `base_rent_monthly` was never bumped, armed the pointer past the sweep, and every
            // later sweep amended the projected rungs DOWN one step for the rest of the term.
            if ($lease->exists
                && $lease->escalatesContractually()
                && $lease->isDirty('escalation_interval_months')
                && $lease->commencement_date !== null
                && $lease->getOriginal('next_escalation_date') !== null) {
                //
                // And walked FORWARD on the new cadence to the first anniversary on or after
                // today: shortening 12 → 6 a year in puts "last applied + 6" in the past, and a
                // pointer in the past makes the next sweep back-date a step over months already
                // billed and then amend the rung that had already started.
                $commencement = CarbonImmutable::parse($lease->commencement_date);
                $oldInterval = (int) ($lease->getOriginal('escalation_interval_months') ?: 12);
                $lastApplied = CarbonImmutable::parse($lease->getOriginal('next_escalation_date'))
                    ->subMonthsNoOverflow($oldInterval);
                $next = $lease->escalationDateAfter($lastApplied->lessThan($commencement) ? $commencement : $lastApplied);
                $today = CarbonImmutable::today();

                while ($next->lessThan($today)) {
                    $next = $lease->escalationDateAfter($next);
                }

                $lease->next_escalation_date = $next->format('Y-m-d');
            }

            // ── THE POINTER FOLLOWS THE COMMENCEMENT TOO (2026-09-11, Trello 7IgLPLGl) ────
            //
            // The first anniversary is one interval after commencement, and it was armed once, at
            // creation. Move the commencement on an un-invoiced lease and the pointer — and the
            // ladder projected from it — stayed on the old date. Nothing has been billed (an
            // invoiced lease refuses the move below), so the first anniversary is simply re-armed
            // from the date the term now starts on.
            if ($lease->exists
                && $lease->escalatesContractually()
                && $lease->isDirty('commencement_date')
                && $lease->commencement_date !== null) {
                $lease->next_escalation_date = $lease
                    ->escalationDateAfter(CarbonImmutable::parse($lease->commencement_date))
                    ->format('Y-m-d');
            }

            if ($lease->escalatesContractually()
                && $lease->next_escalation_date === null
                && $lease->commencement_date !== null) {
                // The clause's own interval, not a literal year (EG-30 / M-6). This hook ARMS the
                // first step, and it was the sibling the interval change left behind: a biennial
                // clause got its first escalation armed twelve months out and stepped a year early,
                // once, before the sweep's own rolling took over — a rent increase the tenant never
                // agreed to, on the first anniversary, with nothing on screen to say so.
                $lease->next_escalation_date = $lease
                    ->escalationDateAfter(CarbonImmutable::parse($lease->commencement_date))
                    ->format('Y-m-d');
            }
        });

        // ── THE CLAUSE'S PROJECTED FUTURE FOLLOWS THE CLAUSE — every term of it (2026-09-11) ──
        // On the MODEL for the standing reason: the API and services write leases without
        // rendering a field. `updated`, not `saved` — on CREATE the charge rows are not seeded
        // yet, and every creation door projects itself after seeding them.
        //
        // This was three hand-written branches — clause cleared → prune; service-charge toggle
        // on → project; toggle off → prune the service charge — and every OTHER term fell through
        // them. Measured on staging (Trello RV4DrGHA + jF09XB3n, one lease): the operator typed 1
        // into *Steps every* (nothing re-projected), flipped the toggle (which re-projected with
        // whatever the lease carried — MONTHLY rungs from the first anniversary, 1,000 → 10,835
        // over two years), cleared the interval (nothing re-projected, the monthly rungs stayed),
        // and set the rate 10 → 100 the next day (nothing re-projected, the 10% rungs stayed).
        // The lease said one thing and the billing engine — which reads the ladder, not the
        // clause — billed another.
        //
        // Yardi regenerates the rent steps from the escalation setup whenever the setup changes;
        // MRI the same. Here that is `ChargeScheduleService::retrueProjectedLadder()`: deactivate
        // every not-yet-started PROJECTED rung (a stated, manual rung survives, a relief window
        // is walked through rather than over, and the levy follows exactly the rent rungs
        // pruned), then project again from the clause as it now reads. A cleared clause projects
        // nothing, which is the old first branch. The collar columns are deliberately NOT in the
        // list: the projection states the raw rate and the sweep collars it, so a collar change
        // moves no rung and re-truing on it would only churn the audit trail.
        //
        // THE LEVY PAIR IS THE OTHER HALF, and it was `EditLease::afterSave()`'s until today —
        // one door of the several that write these two columns. Re-syncing the BASE levy row
        // (`createLevyCharge()`: on, off, or re-rated from today) must run BEFORE the projection
        // writes the levy's future rungs, or the projection opens the levy from commencement at
        // the first STEP's amount and the base re-sync then overwrites it — measured through the
        // real page: a levy toggled on lost its first future step, and mid-term gained a
        // back-dated row at the stepped amount. And the levy's own edit re-trues ONLY the levy
        // rungs: `setAmount()` no-ops on an unchanged amount, so re-projecting over an intact
        // rent ladder writes nothing for rent — no churn, and no walk over a relief.
        //
        // AND THE TERM IS THE LADDER'S BOUNDS (2026-09-11, Trello 7IgLPLGl, one door over from
        // the two cards above): the seeded rows start ON the commencement, the anniversaries are
        // counted FROM it, and the walk stops AT the expiry — so a commencement or expiry edit is
        // a ladder edit. The tester moved the commencement from the 10th to the 12th on an
        // un-invoiced lease and the schedule went on starting on the 10th. A commencement move
        // re-dates the rows anchored on the old date (`redateFrom`) before the walk; an expiry
        // move prunes past the new end or projects up to it. Only an UN-INVOICED lease gets here
        // for the commencement (the `saving` guard above refuses the rest); an invoiced lease's
        // expiry still moves — through the acts that own it, which re-true through this hook.
        static::updated(function (self $lease) {
            $levyMoved = $lease->wasChanged(self::LEVY_TERMS);
            $clauseMoved = $lease->wasChanged(array_values(array_diff(self::LADDER_TERMS, self::LEVY_TERMS)));
            $boundsMoved = $lease->wasChanged(self::LADDER_BOUNDS);

            if (! $levyMoved && ! $clauseMoved && ! $boundsMoved) {
                return;
            }

            // An expiry move is a FURTHER TERM only when a LIVE term is lengthened. A shortened
            // one is an early termination and an ended term "lengthened" is a close-out or an
            // overstay terminated on a date — and in both the tenancy ends, it does not step:
            // `LeaseTerminationService` writes the termination date onto `expiry_date`, and
            // measured, closing out an expired term on 15 October let the walk mint the
            // anniversary the term never reached (the 1st of the expiry month, 1,331 over 1,210)
            // and the final bill read it. `ConvertLeaseToHoldoverService` states the rule the
            // other way round: "a projected escalation the lease never reached must not become
            // the basis". So the walk is bounded at the CONTRACTED expiry there — the earlier of
            // the two — and only an extension (its service projects the further years itself,
            // as it always has) runs unbounded.
            $projectUntil = null;

            if ($lease->wasChanged('expiry_date')
                && $lease->getOriginal('expiry_date') !== null
                && $lease->expiry_date !== null) {
                $old = CarbonImmutable::parse($lease->getOriginal('expiry_date'))->startOfDay();
                $new = CarbonImmutable::instance($lease->expiry_date)->startOfDay();
                $furtherTerm = $new->greaterThan($old)
                    && $old->greaterThanOrEqualTo(CarbonImmutable::today())
                    && ! in_array($lease->status, self::TERMINAL_STATUSES, true);

                $projectUntil = $furtherTerm ? null : $old->min($new);
            }

            DB::transaction(function () use ($lease, $levyMoved, $clauseMoved, $boundsMoved, $projectUntil) {
                if ($levyMoved) {
                    app(MarketingLevyService::class)->createLevyCharge($lease);
                }

                app(ChargeScheduleService::class)->retrueProjectedLadder(
                    $lease,
                    clause: $clauseMoved || $boundsMoved,
                    redateFrom: $lease->wasChanged('commencement_date') && $lease->getOriginal('commencement_date') !== null
                        ? CarbonImmutable::parse($lease->getOriginal('commencement_date'))
                        : null,
                    projectUntil: $projectUntil,
                );
            });
        });

        // ── A FLOOR ABOVE ITS OWN CEILING HAS NO READING, AND THIS LEASE HAS THREE SUCH PAIRS ──
        //
        // One rule, three places it applies. The first was guarded; the other two were reported
        // from the panel on 2026-09-10 (Trello kZ77DQa7 and H22OkiFa) and are the same sentence
        // about different columns: whichever bound is applied LAST silently wins, and the figure
        // the operator typed becomes the one thing that can never happen. Refused at the MODEL so
        // an import or an API write is covered too — the form's inline rules only guard the screen.
        static::saving(function (self $lease) {
            // ── ONLY ON A DIRTY WRITE OF THE CLAUSE ITSELF ────────────────────────────────
            //
            // Every one of these is refused only when the operator is TOUCHING that clause, for the
            // reason the bank-account chart rule states: a lease already carrying the contradiction
            // — and staging has one, which is where these cards came from — would otherwise become
            // unsaveable for any reason at all, including the edit that fixes it. A guard that
            // blocks its own remedy protects nothing.
            $clauseTouched = $lease->isDirty([
                'escalation_type', 'escalation_rate', 'escalation_floor_rate', 'escalation_ceiling_rate',
            ]);

            // **A fixed rate OUTSIDE its collar is not refused, and that was tried and reverted
            // (2026-09-10).** Trello kZ77DQa7 reports the collar as purposeless on a Fixed % clause;
            // it is the opposite — `RentEscalationService::collar()` CLAMPS the stated rate, which
            // is a documented, tested semantic: `EscalationCollarTest` pins "caps the increase at
            // the ceiling", "lifts the increase to the floor" and, as its own control, "equal bounds
            // are a fixed step, not a contradiction". Refusing the combination broke five
            // regression cases, the pre-staging QA harness and the renewal path.
            //
            // So the clause stays legal and the fix is VISIBILITY: the rate field shows the step
            // the lease will actually take. Same answer as the term-vs-expiry card — show the
            // truth, do not force the values.

            // 1. The collar itself. `RentEscalationService::collar()` applies the floor and then
            //    the ceiling, so an inverted pair resolves to the ceiling every time.
            if ($clauseTouched
                && $lease->escalation_floor_rate !== null
                && $lease->escalation_ceiling_rate !== null
                && (float) $lease->escalation_floor_rate > (float) $lease->escalation_ceiling_rate) {
                throw new \DomainException(__('admin.errors.escalation_collar_inverted'));
            }

            // 2. The late-fee minimum above the late-fee cap. `LateFeeService` applies
            //    `max($min, …)` and THEN `min($fee, $max)`, so the cap wins and a minimum of 1,000
            //    under a cap of 100 charges 100 — the tester's words, "no single fee value can
            //    satisfy both rules".
            //
            //    Asked of the RESOLVED clause, not the two columns, because these are three-tier
            //    settings: a lease stating only a minimum, above a cap it inherits from the
            //    property, is the same contradiction and reads identically to the operator. Only
            //    when one of them is being written, so an ordinary lease save pays nothing for it.
            //    **Zero is NO CAP at every tier** — the meaning every install had before the column
            //    existed — so it can never be the smaller bound.
            // `exists`, so a CREATE never trips it. The only create doors are the lease form —
            // which carries its own inline rule — and the services that COPY an existing clause:
            // `LeaseRenewalService` rebuilds every fillable column, so on a renewal all of them are
            // dirty, and without this a lease already carrying the contradiction could not be
            // renewed at all. The same lockout as the collar's, one door over.
            if ($lease->exists && $lease->isDirty(['late_fee_minimum', 'late_fee_maximum'])) {
                $terms = $lease->lateFeeTerms();
                $min = (float) $terms['minimum'];
                $max = (float) $terms['maximum'];

                if ($max > 0 && $min > $max) {
                    throw new \DomainException(__('admin.errors.late_fee_minimum_above_cap', [
                        'minimum' => number_format($min, 2),
                        'maximum' => number_format($max, 2),
                    ]));
                }
            }
        });

        // ── A lease's term cannot run backwards ────────────────────────────────────────────────
        // `expiry_date` has THREE writers — the standard form, `LeaseTerminationService` (which
        // stamps the termination date onto it) and `LeaseRenewalService` — and the rule lived on
        // exactly one of them, as an `->after()` on the form's DatePicker. The terminate action's
        // DatePicker carried no constraint at all, so terminating with a mis-keyed earlier date
        // produced a lease that reads expired while its status is active, that `activeInPeriod()`
        // can never match (so it bills nothing, ever again), and recurring charges stamped
        // `end_date` BEFORE their own `start_date` — the shape `atriom:audit-charge-schedules`
        // exists to catch on import, minted in-app.
        //
        // Guarded on BOTH columns: fixing only the expiry side leaves the identical broken state
        // reachable by moving commencement forward instead.
        //
        // EQUAL IS ALLOWED. A lease terminated on its own commencement date — a deal that collapses
        // at handover — is legitimate and must stay recordable. The form keeps the stricter
        // `->after()` for NEW leases, where a zero-day term is nonsense: this layer carries the
        // invariant every writer must obey, the form adds the product rule for the create path.
        // ── AN INVOICED LEASE DOES NOT MOVE ITS COMMENCEMENT (2026-09-11) ──────────────────
        // The form has locked the field once the lease is invoiced since 2026-08-12, under a
        // comment saying why: the commencement anchors the first billable month, the billing cycle
        // and every charge row's start date, so moving it re-dates a schedule that issued documents
        // were raised from. A disabled field is a rendering decision; the importer and every
        // service reach the column without one. This is the gate. Expiry is deliberately NOT
        // here — a termination, an extension and a close-out all move it, as acts of their own.
        //
        // AND A LEASE WHOSE RENT HAS ALREADY STEPPED (the review of this fix): the anniversaries
        // are counted from the commencement, so once one has been reached — a rung has started,
        // or the sweep has applied one — moving the commencement re-derives a step that already
        // happened: measured, a draft lease a year old moved one month later kept its started
        // 1,100 rung AND projected 1,210 from the new anniversary, one extra step for the rest of
        // the term. `commencementLockedBecause()` is the ONE predicate the form's lock and this
        // refusal read, so the two cannot drift.
        static::saving(function (self $lease) {
            if (! $lease->exists || ! $lease->isDirty('commencement_date')) {
                return;
            }

            $because = $lease->commencementLockedBecause();

            if ($because === null) {
                return;
            }

            // A literal key per reason, never a composed one — a composed key is invisible to the
            // translation gate and falls to the raw string for a reason nobody registered.
            $key = match ($because) {
                'invoiced' => 'admin.refusals.lease_commencement_locked_after_invoicing',
                'stepped' => 'admin.refusals.lease_commencement_locked_after_stepping',
            };

            throw new \DomainException(__($key, [
                'reference' => $lease->reference,
                'from' => Carbon::parse($lease->getOriginal('commencement_date'))->toDateString(),
                'stepped' => $lease->firstSteppedOn()?->toDateString() ?? '—',
            ]));
        });

        static::saving(function (self $lease) {
            if ($lease->commencement_date === null || $lease->expiry_date === null) {
                return;
            }

            $commencement = Carbon::parse($lease->commencement_date)->startOfDay();
            $expiry = Carbon::parse($lease->expiry_date)->startOfDay();

            if ($expiry->lt($commencement)) {
                throw new \DomainException(__('admin.errors.lease_expiry_before_commencement', [
                    'commencement' => $commencement->toDateString(),
                    'expiry' => $expiry->toDateString(),
                ]));
            }
        });

        // ── A RENEWAL REMINDER IS ABOUT ONE EXPIRY DATE, SO MOVING THE DATE RE-ARMS IT ────────
        //
        // `leases.expiry_reminder_notified_at` is the idempotency stamp `leases:remind-expiring`
        // keys on (`whereNull(...)`), and nothing has ever cleared it. Traced on HEAD by reading
        // every writer of the column, not by running one — the regression test measures it: a
        // lease reminded 90 days before its 2026-10-31 expiry and then EXTENDED to 2029-10-31 carries
        // that stamp for the rest of the tenancy, so the renewal conversation is never started
        // again — an absence, which is the failure class nobody reports.
        //
        // The stamp says *"the tenant has been told about the expiry date this lease carries"*. A
        // LATER expiry makes that statement false, so it is withdrawn. In the MODEL rather than in
        // `LeaseExtensionService` because `expiry_date` is still an editable field on `LeaseForm`
        // for an un-invoiced lease and `LeaseImporter` writes it too — the same reasoning that put
        // the rate derivation and the deposit multiple here.
        //
        // **FORWARD ONLY.** `LeaseTerminationService` stamps the termination date onto
        // `expiry_date` and, under notice, leaves the lease ACTIVE — so clearing on a backwards
        // move would send *"your lease is approaching expiry, start the renewal conversation"* to a
        // tenant who has already served notice. That message is outbound and cannot be recalled.
        static::updating(function (self $lease) {
            if (! $lease->isDirty('expiry_date')
                || $lease->expiry_reminder_notified_at === null
                // A caller that states the stamp in the same save has ruled on it itself.
                || $lease->isDirty('expiry_reminder_notified_at')) {
                return;
            }

            $previous = $lease->getOriginal('expiry_date');

            if (blank($previous) || blank($lease->expiry_date)) {
                return;
            }

            if (Carbon::parse($lease->expiry_date)->startOfDay()
                ->greaterThan(Carbon::parse($previous)->startOfDay())) {
                $lease->expiry_reminder_notified_at = null;
            }
        });

        // ── A deposit cannot be negative ───────────────────────────────────────────────────────
        // `minValue(0)` on the form and nothing behind it. Low severity, and worth saying so: this
        // is the CONTRACTUAL figure — the money that actually moves comes from `deposit_transactions`
        // — so a negative one cannot mis-pay anyone. What it can do is print a nonsense
        // "contractual deposit" line on the move-out statement the operator hands the tenant
        // (`MoveOutStatementService::for()`). Refused rather than clamped: silently turning -5,000
        // into 0 hides the typo instead of reporting it.
        static::saving(function (self $lease) {
            if ($lease->security_deposit !== null && (float) $lease->security_deposit < 0) {
                throw new \DomainException(__('admin.errors.negative_security_deposit'));
            }
        });

        // ── Rent and service charge cannot be negative ─────────────────────────────────────────
        // The same situation as the deposit above and a worse consequence, so the same answer.
        // `minValue(0)` on the form and `min:0` on the importer, with nothing behind either — and
        // `LeaseCreationService` writes its schedule rows under `if ($rent > 0)` / `if ($service >
        // 0)`, so a negative figure produces a lease with NO base-rent row, no marketing levy and
        // nothing to bill, for the whole of its term, while its own screen shows the figure that
        // was typed. That is the shape this codebase has now been bitten by three times: a lease
        // that looks configured and bills nothing.
        //
        // ZERO stays legal — a rent-free fit-out period, a kiosk let on percentage rent alone, a
        // service charge folded into the rent are all real. Only below zero is refused, and
        // refused rather than clamped, for the reason the deposit guard gives: turning -5,000 into
        // 0 hides the typo instead of reporting it.
        static::saving(function (self $lease) {
            foreach (['base_rent_monthly', 'service_charge_monthly'] as $column) {
                if ($lease->{$column} !== null && (float) $lease->{$column} < 0) {
                    throw new \DomainException(__('admin.errors.negative_lease_amount', [
                        'field' => __('admin.fields.'.$column),
                    ]));
                }
            }
        });

        // ── Rate-priced rent is DERIVED, from every writer ─────────────────────────────────────
        // A lease priced per m² must never carry a monthly figure that disagrees with its own rate
        // and area. Enforced here rather than in the form so an import, a service or a future
        // screen cannot drift — the same reason the NOT-NULL coercions live at this layer.
        //
        // Only on CREATE and when the rate or basis actually changed: re-deriving on every save
        // would silently overwrite an amount a later expansion legitimately set for a period, and
        // the schedule — not this column — is the record of what billed.
        static::saving(function (self $lease) {
            if ($lease->rent_pricing_basis !== self::RENT_RATE) {
                return;
            }

            // On CREATE always — a typed monthly figure cannot outrank the rate the deal was
            // struck at. On UPDATE only when the rate moved and the caller did NOT state a rent in
            // the same save: `LeaseRentChangeService` re-rates and re-prices together, on an
            // effective date this hook knows nothing about, and must not be second-guessed.
            $stated = $lease->exists && $lease->isDirty('base_rent_monthly');

            if (! $lease->exists
                || ($lease->isDirty(['base_rent_rate_per_sqm_year', 'rent_pricing_basis']) && ! $stated)) {
                $derived = $lease->deriveBaseRentFromRate();

                if ($derived !== null) {
                    $lease->base_rent_monthly = $derived;
                }
            }
        });

        // ── A deposit agreed as "three months' rent" stays three months' rent ──────────────────
        // `security_deposit` is a flat figure and rent escalates, so on a 7% clause a 3× deposit
        // covers 2.62 months by year three and 2.29 by year five: the landlord's security erodes by
        // nearly a quarter over a term, silently, and precisely as a tenant becomes more likely to
        // default. Yardi tracks the requirement against rent for this reason.
        //
        // In the MODEL, beside the rate derivation above and for the same reason: the escalation
        // sweep, the Change Rent action, a renewal (which copies `security_deposit` forward while
        // setting a NEW rent — the same erosion, one renewal at a time), the importer and the API
        // all write leases, and only one of them is a form. One seam covers them all.
        //
        // **Null means flat, and nothing moves.** A deposit agreed as a sum unrelated to rent is a
        // real deal; inferring a multiple by dividing the deposit by the rent would invent a term
        // nobody agreed to.
        //
        // **Since 2026-09-11 the deposit has a BASIS** (`security_deposit_basis`, meeting 2026-09-02
        // point 3): `months` is what the multiple above always meant, `percent_of_annual_rent` is
        // the Egyptian / GCC clause, `fixed` is what a null multiple always meant. One arithmetic —
        // `DepositBasis::derive()` — read here, by the wizard and by the form's preview, so a
        // renewal, an escalation and an import cannot price one clause three ways. A rent-linked
        // basis re-derives; a fixed one is the operator's figure and nothing touches it.
        static::saving(function (self $lease) {
            // A writer that states a multiple and no basis (every writer before the basis existed;
            // the importer still) means `months` — the backfill's own rule, applied on the way in.
            if ($lease->security_deposit_basis === null) {
                $lease->security_deposit_basis = $lease->security_deposit_months === null
                    ? DepositBasis::FIXED
                    : DepositBasis::MONTHS;
            }

            $required = DepositBasis::for($lease);

            if ($required !== null && (float) $lease->security_deposit !== $required) {
                $lease->security_deposit = $required;
            }
        });

        // ── A reservation has a window (meeting 2026-09-02, point 1) ──────────────────────────
        // A draft or pending lease holds its shop off the market (`Unit::recomputeStatus()` reads
        // it as `reserved`), so it opens with the property's hold window stamped on it — Yardi's
        // unit-hold expiry. On the MODEL, so the form, the wizard and the importer all stamp it;
        // a window of 0 days (the shipped default) stamps nothing, and `leases:expire` lapses a
        // reservation whose day has passed with the money still not in.
        //
        // Stamped when a lease ENTERS the awaiting state — created there, or a draft promoted into
        // it — and CLEARED on every exit from it, whichever door took it out (the Activate act, the
        // dropdown on an ungated property, a cancellation, the importer): a date on an active lease
        // is a hold that no longer exists, and a renewal must not inherit it either
        // (`RENEWAL_RESETS`). Found by the review of this change, which activated a draft through
        // the dropdown and read `reserved_until` still standing on the active lease.
        static::saving(function (self $lease) {
            $awaiting = LeaseActivation::isAwaiting($lease);

            if (! $awaiting) {
                if ($lease->reserved_until !== null) {
                    $lease->reserved_until = null;
                }

                return;
            }

            $enteredNow = ! $lease->exists || $lease->isDirty('status');

            if ($lease->reserved_until !== null || ! $enteredNow) {
                return;
            }

            $days = LeaseActivation::reservationDaysFor($lease->unit?->asset_id);

            if ($days > 0) {
                $lease->reserved_until = CarbonImmutable::today()->addDays($days);
            }
        });

        // ── Terminal leases are immutable ──────────────────────────────────────────────────────
        // Once a lease is terminated/expired/cancelled/renewed its fields can't change — only
        // soft-delete/restore (deleted_at). The transition INTO a terminal state is allowed (checked
        // against the ORIGINAL status: termination + renewal both move from 'active'). Closes the
        // hole where the standard Edit form could re-open + mutate a terminated lease.
        static::updating(function (self $lease) {
            $original = $lease->getOriginal('status');
            if (in_array($original, self::TERMINAL_STATUSES, true)) {
                // Block commercial/state changes; still permit benign annotations (notes/metadata),
                // timestamps, and soft-delete/restore. This stops the exploit (re-opening a
                // terminated lease and changing its rent/status/dates) without freezing housekeeping.
                $allowed = ['notes', 'metadata', 'updated_at', 'deleted_at'];
                $blocked = collect($lease->getDirty())->keys()->reject(fn ($k) => in_array($k, $allowed, true));
                if ($blocked->isNotEmpty()
                    && ! $lease->isResumingFromExpiry()
                    && ! $lease->isClosingOutAnExpiredTerm()
                    && ! $lease->isRenewingAnExpiredTerm()) {
                    throw new \DomainException(__('admin.refusals.immutable_lease', ['status' => Translate::orHumanized("admin.statuses.lease.{$original}", $original)]));
                }
            }
        });
    }

    /**
     * Is this write CLOSING OUT a lease whose term already ran out?
     *
     * The sibling of {@see isResumingFromExpiry()}, and it exists for the same reason. At the end of
     * a term an operator has two answers — hold the tenancy over, or close it out — and `expired` is
     * in {@see TERMINAL_STATUSES}, so this hook refused BOTH. Holding over was given its carve-out
     * when LE-04 was found unreachable; closing out was not, and stayed refused: after 05:15 a
     * tenant who had actually left could not be recorded as having left.
     *
     * Recognised by the SHAPE of the write, never by trusting a caller — the same discipline its
     * sibling follows. The shape is unambiguous and no form can produce it: `expired` → `terminated`,
     * which `LeaseForm` does not offer (it withholds `terminated` unless the record is already in
     * it), touching none of the commercial terms.
     */
    public function isClosingOutAnExpiredTerm(): bool
    {
        if ($this->getOriginal('status') !== 'expired' || $this->status !== 'terminated') {
            return false;
        }

        return collect($this->getDirty())
            ->keys()
            ->intersect(self::CLOSE_OUT_FORBIDS)
            ->isEmpty();
    }

    /**
     * What a close-out may NOT touch — the resumption denylist minus `expiry_date`.
     *
     * A termination MOVES the expiry date: that is when the tenancy actually ended, and it is what
     * every projection reads. Everything else is the deal, and closing a tenancy out does not
     * re-negotiate it.
     */
    public const CLOSE_OUT_FORBIDS = [
        'tenant_id',
        'unit_id',
        'start_date',
        'commencement_date',
        'base_rent_rate_per_sqm_year',
        'rent_pricing_basis',
        'security_deposit_months',
        'security_deposit_basis',
        'security_deposit_percent',
        'previous_lease_id',
        'deleted_at',
    ];

    /**
     * Is this write RENEWING a lease whose term already ran out?
     *
     * The third sibling of {@see isResumingFromExpiry()} and {@see isClosingOutAnExpiredTerm()},
     * and the last of the three answers an operator can give at the end of a term. `expired` is a
     * PROJECTION written by `leases:expire` at 05:15 AND a member of {@see TERMINAL_STATUSES}, so
     * this hook refused all three; hold-over got its carve-out when LE-04 was found unreachable,
     * closing out got one when the same hole was reported against termination, and renewing — the
     * commonest of the three, because a renewal is routinely signed weeks after the old term ran
     * out — stayed refused.
     *
     * Recognised by the SHAPE of the write, never by trusting a caller, on the discipline both
     * siblings follow. `expired` → `renewed` is unambiguous and no form can produce it: `LeaseForm`
     * never offers `renewed`, and `LeaseRenewalService` writes that status and nothing else.
     *
     * The bound is {@see HOLDOVER_RESUMPTION_FORBIDS} rather than {@see CLOSE_OUT_FORBIDS}, and the
     * difference is `expiry_date`. A close-out MOVES the expiry — that is the day the tenancy
     * actually ended. A renewal does not: the original\'s term is exactly what the successor
     * continues from, so moving it here would silently re-date the join between the two documents.
     */
    public function isRenewingAnExpiredTerm(): bool
    {
        if ($this->getOriginal('status') !== 'expired' || $this->status !== 'renewed') {
            return false;
        }

        // ── A SUCCESSOR MUST ALREADY EXIST, AND THAT IS WHAT MAKES THE SHAPE TIGHT ───────────
        //
        // The holdover sibling is safe because it additionally requires `holdover_from` to move
        // null → set, a column no writer but its own service touches. The status pair alone is NOT
        // that tight, and the panel is the wrong place to look for the hole: `LeaseForm` never
        // offers `renewed` and `EditLease` halts on `isTerminal()` before saving — but the
        // IMPORTER is not a form. `LeaseImporter` accepts `status: renewed`, and `resolveRecord()`
        // does `firstOrNew(['reference' => …])`, so one CSV row against an `expired` lease would
        // otherwise rewrite `base_rent_monthly`, `service_charge_monthly`, `term_months` and
        // `security_deposit` — none of them in the denylist below, because none is a column the
        // renewal service writes — on a lease the system calls immutable, and mark it `renewed`
        // with no successor at all.
        //
        // `LeaseRenewalService` creates the renewal BEFORE it closes the original, so this costs
        // that path nothing; an import row cannot fabricate a lease pointing back at this one.
        if (! static::query()->where('previous_lease_id', $this->getKey())->exists()) {
            return false;
        }

        return collect($this->getDirty())
            ->keys()
            ->intersect(self::HOLDOVER_RESUMPTION_FORBIDS)
            ->isEmpty();
    }

    /**
     * The commercial terms a resumption may NOT touch — a denylist, deliberately.
     *
     * The first version listed what the service writes and refused anything else, which looked
     * tighter and was wrong: `getDirty()` is read AFTER every `saving` hook has run, and
     * `Lease::saving` recomputes `security_deposit` whenever `base_rent_monthly` moves. The uplift
     * moves it, so the hook dirtied a column the allowlist did not mention and the conversion threw
     * — for every lease carrying `security_deposit_months`, which the lease form DEFAULTS from the
     * property setting. Measured: identical leases, only that column differing, one converted and
     * one refused. `HasSearchText` folds `search_text` in `saving` too, so a row with a stale blob
     * would have failed the same way.
     *
     * An allowlist over derived columns is a list of "what the service writes" being used as "what
     * may be dirty", and those are different questions. Naming the terms instead states the actual
     * rule: a resumption may not re-negotiate the deal — not the term, not the premises, not the
     * price basis, not the counterparty.
     */
    public const HOLDOVER_RESUMPTION_FORBIDS = [
        'tenant_id',
        'unit_id',
        'start_date',
        'commencement_date',
        'expiry_date',
        'base_rent_rate_per_sqm_year',
        'rent_pricing_basis',
        'security_deposit_months',
        'security_deposit_basis',
        'security_deposit_percent',
        'previous_lease_id',
        'deleted_at',
    ];

    /**
     * The one write that may lift `expired` — recognised BY SHAPE, never by trusting the caller.
     *
     * `expired` is unlike its three siblings in `TERMINAL_STATUSES`. `terminated`, `cancelled` and
     * `renewed` are each a person's act with a successor document; `expired` is a PROJECTION written
     * by the nightly `leases:expire` sweep — a machine's guess that nobody continued the tenancy.
     * Converting to holdover is the operator asserting the opposite, and it is the one fact only a
     * person holds.
     *
     * So the carve-out is not "the holdover service may write here" — a service cannot be trusted
     * by a model, and a crafted Livewire payload does not announce which service it came from. It is
     * the SHAPE of the write: `expired` → `active`, `holdover_from` moving from null to set, and
     * nothing else dirty outside the resumption columns. No other operation in the system has that
     * shape, and `terminated`, `cancelled` and `renewed` stay absolutely immutable because each fails
     * the first clause.
     *
     * The bound is {@see HOLDOVER_RESUMPTION_FORBIDS} — the commercial terms — rather than a list of
     * the columns the service happens to write. The realistic risk here is not an attacker borrowing
     * the shape (`holdover_from` is on no form, and Filament drops undeclared keys); it is a
     * LEGITIMATE write growing a derived column and being refused, which is exactly what happened.
     */
    public function isResumingFromExpiry(): bool
    {
        if ($this->getOriginal('status') !== 'expired' || $this->status !== 'active') {
            return false;
        }

        if ($this->getOriginal('holdover_from') !== null || $this->holdover_from === null) {
            return false;
        }

        return collect($this->getDirty())
            ->keys()
            ->intersect(self::HOLDOVER_RESUMPTION_FORBIDS)
            ->isEmpty();
    }

    /**
     * Lease documents live on a PRIVATE disk (not web-accessible). A signed contract is
     * the most confidential artifact in the system — it carries both parties' identities
     * and the commercial terms — and must never be reachable via a guessable public URL.
     * They're served only through the authenticated admin panel.
     *
     * **This was a live exposure until 2026-07-16.** The model implemented HasMedia but
     * registered no collection, so `documents` silently inherited medialibrary's default
     * disk — `env('MEDIA_DISK', 'public')`, and neither the env var nor a config override
     * existed. Every uploaded contract landed in the webroot. Never rely on the default:
     * declare the disk explicitly (MediaPrivacyConformanceTest enforces it).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS_COLLECTION)->useDisk('local');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'lease');
    }

    /**
     * Columns a RENEWAL deliberately does not inherit, each with the reason.
     *
     * `LeaseRenewalService` derives its payload from `$fillable` minus this list, so a new lease
     * column is carried by default and dropping one is a decision somebody has to write down.
     *
     * It is that way round because the opposite failed: the service enumerated what to copy, the
     * table grew from ~24 columns to 43, and **14 negotiated terms were silently lost on every
     * renewal** — the escalation amount and its collar, the rate-pricing basis, the per-lease
     * late-fee terms, the percentage-rent deduction clause, the holdover uplift. None of them
     * errored. `LeaseRenewalCarriesTermsTest` fails the build on a column that is neither carried
     * nor named here.
     *
     * @var array<string, string>
     */
    public const RENEWAL_RESETS = [
        // ── Identity and term: the renewal's own, set explicitly by the service ────────────────
        'reference' => 'the renewal gets its own document number.',
        'previous_lease_id' => 'points AT the original — set by the service, not copied.',
        'status' => 'a renewal starts active regardless of how the original ended.',
        'commencement_date' => 'the renewal term, supplied by the operator.',
        'expiry_date' => 'same.',
        'term_months' => 'same.',
        'base_rent_monthly' => 'the renegotiated rent — the whole point of renewing.',
        'service_charge_monthly' => 'same.',

        // ── State that belonged to the ORIGINAL tenancy ───────────────────────────────────────
        'possession_date' => 'the tenant took possession once, at the start of the original lease.',
        'reserved_until' => 'a hold on the ORIGINAL while it awaited activation; a renewal is executed and holds nothing.',
        'rent_commencement_date' => 'fit-out grace was for the original build-out; a renewal has no new one.',
        'fit_out_scope' => 'same — there is no fit-out to scope on a renewal.',
        'next_escalation_date' => 'recomputed by the escalation hook in `Lease::booted` from the renewal\'s own dates; copying the original\'s would escalate against a term that has ended.',
        'holdover_from' => 'holdover is a state the ORIGINAL entered by running past expiry. A renewal starts inside its term. (`holdover_rate_pct` — the negotiated uplift — DOES carry.)',
        'expiry_reminder_notified_at' => 'a notification stamp about the original\'s expiry.',
    ];

    protected $fillable = [
        // How a PART month is priced (EG-29). Null = whatever the property says.
        'proration_method',
        // The operator's own fields (D-7). A VIRTUAL attribute — `HasCustomFields` routes it
        // through `fillCustomFields()`, which discards keys the catalogue does not define. The
        // `metadata` column itself is deliberately NOT fillable: nothing fills it wholesale.
        'custom_fields',
        'reference',
        'unit_id',
        'unit_ownership_id',
        'tenant_id',
        'previous_lease_id',
        'status',
        'commencement_date',
        'expiry_date',
        'holdover_rate_pct',
        'holdover_from',
        'expiry_reminder_notified_at',
        'term_months',
        'base_rent_monthly',
        'rent_pricing_basis',
        'base_rent_rate_per_sqm_year',
        'service_charge_monthly',
        'has_marketing_levy',
        'marketing_levy_rate',
        'possession_date',
        'rent_commencement_date',
        'fit_out_scope',
        'billing_frequency',
        'currency',
        'security_deposit',
        'security_deposit_months',
        'security_deposit_basis',
        'security_deposit_percent',
        'reserved_until',
        'escalation_rate',
        'escalation_amount',
        'escalation_floor_rate',
        'escalation_ceiling_rate',
        'escalation_index_code',
        'escalation_index_base_value',
        'escalation_index_lag_months',
        'escalation_type',
        'escalation_interval_months',
        'escalation_applies_to_service_charge',
        'next_escalation_date',
        'has_percentage_rent',
        'requires_sales_reporting',
        'percentage_rent_threshold',
        'percentage_rent_rate',
        'percentage_rent_calculation_type',
        'percentage_rent_frequency',
        'percentage_rent_billing_frequency',
        'percentage_rent_deductible_types',
        'percentage_rent_sales_exclusions',
        'payment_terms_days',
        'late_fee_percent',
        'late_fee_grace_days',
        'late_fee_minimum',
        'late_fee_maximum',
        'late_fee_recurrence_days',
        'notes',
    ];

    // Non-nullable boolean columns: default the in-memory model so a
    // service-created lease (which may omit them) never propagates null into
    // the NOT NULL columns (e.g. on renewal before a DB re-read).
    protected $attributes = [
        'has_percentage_rent' => false,
        'has_marketing_levy' => true, // preserve today's behaviour: every lease gets the levy by default
        // NEW leases default to the STANDARD (net) abatement — base rent free, service charge
        // still payable. The COLUMN default is `gross`, so every lease that already existed keeps
        // the grace it was actually billed under; retroactively re-billing a live tenancy is not a
        // migration. See the migration and docs/gap-analysis/README.md Q2.
        'fit_out_scope' => self::FIT_OUT_RENT_ONLY,
        'billing_frequency' => 'monthly', // bill monthly unless set to quarterly/semiannual/annual
        'escalation_applies_to_service_charge' => false, // the clause steps the rent alone unless it says otherwise
        'percentage_rent_frequency' => 'monthly', // fresh monthly breakpoint unless set to annual (cumulative)
        'percentage_rent_billing_frequency' => 'monthly', // WHEN the overage is charged — a separate term from the basis above
    ];

    protected $casts = [
        'percentage_rent_deductible_types' => 'array',
        'percentage_rent_sales_exclusions' => 'array',
        'commencement_date' => 'date',
        'expiry_date' => 'date',
        'holdover_rate_pct' => 'decimal:2',
        'base_rent_rate_per_sqm_year' => 'decimal:2',
        'late_fee_percent' => 'decimal:2',
        'late_fee_grace_days' => 'integer',
        'late_fee_minimum' => 'decimal:2',
        'late_fee_maximum' => 'decimal:2',
        'holdover_from' => 'date',
        'expiry_reminder_notified_at' => 'datetime',
        'next_escalation_date' => 'date',
        'base_rent_monthly' => 'decimal:2',
        'service_charge_monthly' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'security_deposit_months' => 'decimal:2',
        'security_deposit_percent' => 'decimal:2',
        'reserved_until' => 'date',
        // Cast declared purely so static analysis reads the column as a string. It was created as a
        // DB-level `enum('none','fixed_percent','cpi')` in 2024, and larastan derives the attribute
        // type from that migration while ignoring the `->change()` that converted it to a varchar —
        // so without this, every comparison against `fixed_amount` reads as "always false". A no-op
        // at runtime; the truth about allowed values lives in the model + form validation, per the
        // project's no-DB-enums convention.
        'escalation_type' => 'string',
        'escalation_rate' => 'decimal:2',
        'escalation_amount' => 'decimal:2',
        'escalation_floor_rate' => 'decimal:2',
        'escalation_ceiling_rate' => 'decimal:2',
        'escalation_index_base_value' => 'decimal:4',
        'escalation_index_lag_months' => 'integer',
        'escalation_interval_months' => 'integer',
        'escalation_applies_to_service_charge' => 'boolean',
        'percentage_rent_threshold' => 'decimal:2',
        'percentage_rent_rate' => 'decimal:2',
        'has_percentage_rent' => 'boolean',
        'requires_sales_reporting' => 'boolean',
        'has_marketing_levy' => 'boolean',
        'marketing_levy_rate' => 'decimal:2',
        'possession_date' => 'date',
        'rent_commencement_date' => 'date',
        'billing_frequency' => 'string',
        'metadata' => 'array',
    ];

    /**
     * The unit ownership this tenancy sits under — the OWNER's own tenant.
     *
     * Yardi's construct: when an owner lets his unit out, the lessee is a sub-record under the
     * owner's unit. The lessee is a real occupant for access, violations, SLA, fit-out and every
     * mall rule — and **the owner stays liable for the assessments**. Owner of record is not
     * occupant of record.
     *
     * Null for an ordinary lease of space the mall still owns, which is almost all of them.
     *
     * Deliberately NOT accompanied by a "do we collect this rent" flag: that is a term of the
     * management agreement, held on the ownership (`management_mode`), and a lease we do not bill
     * rent on simply carries no rent charge row — which the billing engine already handles by
     * raising nothing.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    /** Is this tenancy the OWNER's, rather than one the mall signed itself? */
    public function isUnderOwnership(): bool
    {
        return $this->unit_ownership_id !== null;
    }

    /**
     * Does this lease state a rent increase the system will actually apply?
     *
     * A TYPE alone is not a clause — `fixed_percent` at 0% and `fixed_amount` at nothing per year
     * are both "no increase", stated in two words instead of one. This is the single reading of
     * that question: the `saving` hook arms or clears the escalation terms from it, and both halves
     * of the old `creating` hook derived it inline.
     *
     * **`cpi` changed on 2026-08-19, when the index register arrived.** It used to count as
     * configured only if somebody had typed an `escalation_rate` — the sole way an index clause was
     * expressible when the sweep could not apply one at all. A CPI lease is now configured when it
     * names an INDEX and a base value to measure from, because that is what actually produces a
     * step (`RentIndex` + `RentEscalationService::indexRateFor()`).
     *
     * The rate-only shape still counts, deliberately. Those leases could never escalate (the sweep
     * skipped every CPI lease), so treating them as unconfigured would be truthful about the future
     * and destructive about the past: the `saving` hook CLEARS the escalation terms of a lease that
     * is not configured, so a migration-day re-save would wipe the anniversary an operator had
     * recorded. Left armed and still inert, which is exactly what it was yesterday, until someone
     * names the index.
     */
    /**
     * Months in one percentage-rent BILLING period: monthly=1, quarterly=3, annual=12.
     *
     * Separate from `billingCycleMonths()` (base rent) and from `percentage_rent_frequency` (the
     * calculation basis) on purpose — all three are independent lease terms, and a lease routinely
     * states a different cadence for each: rent quarterly in advance, sales declared monthly,
     * overage settled annually in arrears.
     */
    public function percentageRentBillingMonths(): int
    {
        return match ((string) $this->percentage_rent_billing_frequency) {
            'quarterly' => 3,
            'annual' => 12,
            default => 1,
        };
    }

    /**
     * The columns the projected charge ladder is a FUNCTION of — an edit to any of them re-trues
     * the ladder (`Lease::updated`). The escalation collar is deliberately absent: the projection
     * states the raw rate and the sweep applies the collar each anniversary, so a collar edit
     * moves no rung and re-truing on it would only churn the audit trail.
     *
     * @var list<string>
     */
    public const LADDER_TERMS = [
        'escalation_type',
        'escalation_rate',
        'escalation_amount',
        'escalation_interval_months',
        'escalation_applies_to_service_charge',
        'has_marketing_levy',
        'marketing_levy_rate',
    ];

    /**
     * The subset of {@see LADDER_TERMS} that moves only the LEVY's rungs — the base levy row is
     * re-synced and the future levy rungs re-derived, and the rent and service ladders are left
     * exactly as they stand (same rows, same ids).
     *
     * @var list<string>
     */
    public const LEVY_TERMS = [
        'has_marketing_levy',
        'marketing_levy_rate',
    ];

    /**
     * The TERM columns the projected ladder is bounded by — its anchor and its end. Not in
     * {@see LADDER_TERMS} because they are not the clause, and they ask one thing more of the
     * re-true: a moved commencement re-dates the rows that start on the old one.
     *
     * @var list<string>
     */
    public const LADDER_BOUNDS = [
        'commencement_date',
        'expiry_date',
    ];

    /**
     * Why the commencement may not move — `invoiced`, `stepped`, or null when it may.
     *
     * The ONE predicate behind the form's disabled field, its helper text and the model's refusal
     * (`admin.refusals.lease_commencement_locked_after_{reason}`), so a door that renders no field
     * is refused for exactly the reason the form shows. Invoiced first: it is the older lock
     * (2026-08-12) and the stronger fact.
     */
    public function commencementLockedBecause(): ?string
    {
        if (! $this->exists) {
            return null;
        }

        if ($this->invoices()->exists()) {
            return 'invoiced';
        }

        return $this->firstSteppedOn() !== null ? 'stepped' : null;
    }

    /**
     * The date the first contracted step was REACHED — a projected rung that has started, or the
     * one the sweep wrote when it applied a step (both carry `ORIGIN_ESCALATION`). Null while
     * every step is still ahead.
     */
    public function firstSteppedOn(): ?CarbonImmutable
    {
        $rung = $this->charges()
            ->where('origin', Charge::ORIGIN_ESCALATION)
            ->where('is_active', true)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', today()->toDateString())
            ->orderBy('start_date')
            ->first();

        return $rung ? CarbonImmutable::instance($rung->start_date) : null;
    }


    public function escalatesContractually(): bool
    {
        return match ($this->escalation_type) {
            'fixed_percent' => (float) $this->escalation_rate > 0,
            // Either the new index shape or the legacy rate-only one — see the note above.
            'cpi' => (filled($this->escalation_index_code) && (float) $this->escalation_index_base_value > 0)
                || (float) $this->escalation_rate > 0,
            'fixed_amount' => (float) $this->escalation_amount > 0,
            default => false,
        };
    }

    /**
     * Does this lease's escalation clause step the SERVICE CHARGE alongside the rent?
     *
     * The one predicate the sweep and the ladder projection both read, so they cannot disagree
     * about what a lease's clause covers. Percent-derived clause types only: a step stated in
     * POUNDS is a statement about the rent, and adding the same figure to a service charge a
     * fraction of its size charges nobody what they agreed — the same reasoning that keeps the
     * collar off `fixed_amount`. The type test is here rather than only on the form, because the
     * flag survives a `fixed_percent` → `fixed_amount` switch on purpose (like the collar, it is
     * inert rather than cleared, so switching back does not silently drop a recorded term).
     */
    public function escalatesServiceCharge(): bool
    {
        return (bool) $this->escalation_applies_to_service_charge
            && in_array((string) $this->escalation_type, ['fixed_percent', 'cpi'], true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * The percentage-rent breakpoint ladder, when this lease is billed on a tiered basis.
     *
     * @return HasMany<LeasePercentageRentTier, $this>
     */
    public function percentageRentTiers(): HasMany
    {
        return $this->hasMany(LeasePercentageRentTier::class)->orderBy('from_amount');
    }

    /**
     * Options recorded on this lease — renewal, termination, expansion, first refusal.
     *
     * @return HasMany<LeaseOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(LeaseOption::class);
    }

    /**
     * The lease's commercial history, newest first — every dated, reasoned change (story LE-01).
     *
     * Newest first because that is the question the timeline answers: "what happened to this lease
     * recently". The reconstruct-a-past-date question sorts the other way and is served by
     * {@see eventsAsOf()}.
     *
     * @return HasMany<LeaseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LeaseEvent::class)
            ->orderByDesc('effective_date')
            ->orderByDesc('id');
    }

    /**
     * Parking bays, stores and signage let alongside the premises (space model).
     *
     * Deliberately NOT `units()` — a rentable item is not lettable area, and keeping the two
     * relations apart is what stops one being summed into the other. See
     * [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../../docs/benchmarks/yardi/09-yardi-space-and-parking.md).
     *
     * @return BelongsToMany<RentableItem, $this>
     */
    /**
     * Parking bays, storage and signage this lease holds.
     *
     * A MORPH since 2026-08-19: the holder of a rentable item is the agreement with the ledger, not
     * specifically a lease. That is Voyager's own model — rentable items are assigned to the
     * customer RECORD (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2, "assign Rentable
     * Items … to both new and existing residents"), and in its condo product the unit owner simply
     * is that record. A `UnitOwnership` therefore holds bays through the identical relation.
     */
    /**
     * The lease abstract — the legal terms that are not money.
     *
     * Voyager's clause register (`docs/benchmarks/yardi/01-yardi-lease-administration.md` §7). The
     * two that matter most are co-tenancy and kick-out, which the benchmark calls **contingent
     * money**: while they lived only in the uploaded PDF, nothing could report which leases carried
     * one.
     *
     * @return HasMany<LeaseClause, $this>
     */
    public function clauses(): HasMany
    {
        return $this->hasMany(LeaseClause::class);
    }

    public function rentableItems(): MorphToMany
    {
        return $this->morphToMany(RentableItem::class, 'holder', 'rentable_item_holdings')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * The history as it stood on a past date — the auditor's view.
     *
     * @return Collection<int, LeaseEvent>
     */
    public function eventsAsOf(CarbonImmutable $on): Collection
    {
        return $this->events
            ->filter(fn (LeaseEvent $e) => $e->effectiveOn()->lte($on))
            ->sortBy([
                fn (LeaseEvent $a, LeaseEvent $b) => $a->effective_date <=> $b->effective_date,
                fn (LeaseEvent $a, LeaseEvent $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * Statuses whose invoice records neither a receipt nor a claim, so a deposit billed on one is
     * neither held nor still being asked for.
     *
     * **Both questions moved to {@see DepositBilling} and are answered from AMOUNTS
     * now, not from a status.** They were one list read by both, and a status is a coarse proxy for
     * an amount-level question: it caught only the terminal cases and missed every partial one, in
     * both directions and both of them money. A full write-off erased what the tenant had actually
     * paid; a partial credit note inflated the pot and refunded cash that never arrived; a partial
     * write-off left the forgiven part counting as *already asked for*, so the shortfall could never
     * be re-billed. The same three strings also lived as literals in `DepositHoldings`, which is why
     * the register above the deposit list and the lease page could disagree by a whole deposit.
     *
     * Aliased rather than deleted because this model's five deposit reads compose their own queries
     * (the locking twins have to, so `ConcurrencyPolicyConformanceTest` can see their locks) — but
     * there is exactly ONE definition, and it is not here.
     *
     * @var string[]
     */
    public const DEPOSIT_RECEIPT_EXCLUDED_STATUSES = DepositBilling::EXCLUDED_STATUSES;

    /** @var string[] */
    public const DEPOSIT_CLAIM_EXCLUDED_STATUSES = DepositBilling::CLAIM_EXCLUDED_STATUSES;

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // NEVER-deletable money records that reference the lease directly and can exist BEFORE any
    // invoice — a security deposit is recorded at signing, a year of post-dated cheques is lodged up
    // front. Listed so DeletionPolicy blocks deleting a lease that carries them (pre-go-live review).
    public function deposits(): HasMany
    {
        return $this->hasMany(DepositTransaction::class);
    }

    /**
     * Deposit money netted against this lease's invoices — one of the four settlement channels.
     *
     * Exists so {@see depositHeld()} can be answered from an EAGER LOAD on a list page instead of
     * a query per row. It summed the same table through `DepositApplication::where('lease_id', …)`
     * before, which no `with()` can reach.
     */
    public function depositApplications(): HasMany
    {
        return $this->hasMany(DepositApplication::class);
    }

    /**
     * Invoices on this lease that BILLED a security deposit and still claim money.
     *
     * The eager-loadable twin of the query inside {@see settledDepositBillings()}, carrying the
     * identical filter — a deposit is held only to the extent the tenant settled the line, so a
     * cancelled or credited invoice claims nothing.
     */
    public function depositBillings(): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->whereNotIn('status', self::DEPOSIT_RECEIPT_EXCLUDED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
            // `writeOffs` too: `DepositBilling::claimedOn()` nets them, and a leases LIST asks this
            // once per row — an unloaded relation is a query per invoice per row.
            ->with(['items', 'writeOffs']);
    }

    /**
     * The month-by-month difference between what this lease BILLED and what the books RECOGNISE.
     *
     * Straight-line rent (EAS 49 / IFRS 16): an escalating lease is recognised at its average rent
     * from day one, so the early months recognise MORE than they bill and the late months less. The
     * engine, its journalizer and its scheduled command all existed; nothing in the panel ever showed
     * the result, so a lease's straight-line position was reachable only from a CLI (fixed 2026-08-18).
     */
    public function straightLineAdjustments(): HasMany
    {
        return $this->hasMany(StraightLineRentAdjustment::class);
    }

    /**
     * The security deposit ACTUALLY held against this lease — receipts, less refunds, forfeits and
     * anything already netted against the tenant's invoices.
     *
     * **The one definition.** It lived in `MoveOutStatementService`, which meant the answer to "has
     * this tenant paid their deposit?" was only reachable from a move-out. The lease list, the lease
     * page and the tenant's own portal each needed it, and the alternative to putting it here was
     * three re-implementations of a subtraction that must never disagree.
     *
     * The `DepositApplication` term is the one people leave out: omitting it lets the same deposit
     * settle the arrears AND be refunded in full.
     *
     * Uses the loaded relation when it is there, so a list can eager-load and not issue a query per
     * row. Only RECORDED movements count — a draft is an intention, and settling against intentions
     * is how a landlord refunds money it never received.
     */
    public function depositHeld(): float
    {
        $rows = $this->relationLoaded('deposits')
            ? $this->deposits
            : $this->deposits()->get();

        $recorded = $rows->where('status', 'recorded');

        $held = $recorded->where('type', 'receipt')->sum('amount')
            - $recorded->where('type', 'refund')->sum('amount')
            - $recorded->where('type', 'forfeit')->sum('amount')
            // Same rule as `deposits` above: use the relation when it is loaded, query when it is
            // not. On the leases LIST this turns one query per row into one for the page; on a
            // freshly re-read instance — which is what every money service works from, and what the
            // refund guard reads — nothing is loaded, so it still asks the database.
            - (float) ($this->relationLoaded('depositApplications')
                ? $this->depositApplications->sum('amount')
                : DepositApplication::where('lease_id', $this->id)->sum('amount'))
            // …plus deposits BILLED and since paid (Voyager's model, 2026-08-18). A deposit charged
            // on an invoice is held only to the extent the tenant has settled that line, which is
            // why this reads the settlement and not the line total: an unpaid deposit invoice is a
            // receivable, not money in the bank, and treating it as held would refund at move-out
            // what was never received.
            + $this->settledDepositBillings();

        return round((float) $held, 2);
    }

    /**
     * The deposit pot, read under a LOCK — the authoritative figure a disbursement is written from.
     *
     * `depositHeld()` is the display twin and stays exactly as it is: the leases list, the lease
     * page, the portal infolist and the form helpers all read it on render, and taking row locks per
     * row per page is a cost with no writer waiting on it. Same split, and the same reason, as
     * `Unit::isActivelyLeased()` / `isActivelyLeasedForUpdate()`.
     *
     * **Why a locking twin at all.** A lock serialises writers; it does not make the guard behind it
     * SEE them. Under MySQL REPEATABLE READ a transaction's consistent-read snapshot is fixed at its
     * first plain read, so a settlement that locked something and then asked `depositHeld()` would
     * be answered from before it waited — and `SettleMoveOutService` locked nothing at all. Two
     * move-outs on one lease each read the whole pot and each wrote a refund for it: the deposit
     * disbursed twice, `depositHeld()` negative by its full value, and `deposits_held` in the GL
     * saying a departed tenant owes the landlord money. Unlike the unit double-booking there is no
     * UNIQUE index underneath to turn the race into a duplicate-key error, so nothing catches it.
     *
     * **The locks are written out here rather than delegated**, deliberately. All three terms of the
     * pot must be pinned, and `ConcurrencyPolicyConformanceTest`'s `AUTHORITATIVE_GUARDS` check reads
     * THIS method's own body and requires a locking read in it — a twin that pushed the locking into
     * a private helper would pass review and fail the gate, which is the right outcome: a guard whose
     * lock is not visible where the decision is made is how one gets deleted by a tidy-up.
     *
     * **LOCK ORDER IS LOAD-BEARING: leases → invoices → deposit_transactions → deposit_applications.**
     * `Payment::assertInvoicesNotOverAllocated()` locks invoices and then the deposit applications
     * for those invoices, so taking them in the other order here would deadlock an ordinary receipt
     * against a move-out. Nothing in `app/` locks an invoice and then a lease, so putting the lease
     * at the head of the chain introduces no cycle.
     */
    public function depositHeldForUpdate(): float
    {
        // The billed-and-settled term first, because it reads INVOICES — ahead of the two deposit
        // tables in the global order.
        $invoiceIds = Invoice::query()
            ->where('lease_id', $this->id)
            ->whereNotIn('status', self::DEPOSIT_RECEIPT_EXCLUDED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
            ->lockForUpdate()
            ->pluck('id');

        $settledBillings = round((float) Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['items', 'writeOffs'])
            ->get()
            ->sum(fn (Invoice $invoice): float => DepositBilling::heldOn($invoice)), 2);

        $recorded = $this->deposits()->where('status', 'recorded')->lockForUpdate()->get();

        $applied = (float) DepositApplication::where('lease_id', $this->id)->lockForUpdate()->sum('amount');

        return round(
            $recorded->where('type', 'receipt')->sum('amount')
            - $recorded->where('type', 'refund')->sum('amount')
            - $recorded->where('type', 'forfeit')->sum('amount')
            - $applied
            + $settledBillings,
            2,
        );
    }

    /**
     * The part of any BILLED security deposit the tenant has actually settled.
     *
     * Derived through {@see DepositBilling}, over `InvoiceItemSettlement` — the one
     * place that answers "how much of this line has been paid". Per the money invariants a per-item
     * balance is never stored, because that would be a second truth about the same settlement.
     *
     * **A WRITTEN-OFF invoice still counts what the tenant paid**, which this docblock used to deny:
     * a write-off forgives what was not paid, it does not un-pay what was, and reading it as a
     * terminal exclusion erased 60,000 of somebody's security from the pot. What IS netted out is
     * credit-note relief, because that is not money received.
     */
    public function settledDepositBillings(): float
    {
        // The SAME filter either way — an eager-loaded `depositBillings` relation applies it in
        // the database for the whole page, and an unloaded instance applies it here for one lease.
        // One constant, in one place (`DepositBilling`), so the two paths cannot answer differently
        // — and neither can the portfolio aggregate, which reads the same seam.
        $invoices = $this->relationLoaded('depositBillings')
            ? $this->depositBillings
            : Invoice::query()
                ->where('lease_id', $this->id)
                ->whereNotIn('status', self::DEPOSIT_RECEIPT_EXCLUDED_STATUSES)
                ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
                ->with(['items', 'writeOffs'])
                ->get();

        $settled = 0.0;

        foreach ($invoices as $invoice) {
            // Not the raw per-line settlement: `DepositBilling` nets credit-note relief, which is
            // not money received and would otherwise refund at move-out what never arrived.
            $settled += DepositBilling::heldOn($invoice);
        }

        return round($settled, 2);
    }

    /**
     * The part of a BILLED security deposit the tenant has been asked for and not yet paid.
     *
     * The sibling question to {@see settledDepositBillings()}, and the one nothing answered.
     * `depositShortfall()` is `agreed − held`, which is the right answer to *"are we short?"* — an
     * unpaid deposit invoice is a receivable, not money in the bank — and the WRONG answer to
     * *"should we ask again?"*, which is what the billing action gates on.
     *
     * Measured on the demo books: lease #3 carried an open 164,999.91 deposit invoice, the modal
     * reported "held 0.00 of 164,999.91", and billing again produced a SECOND invoice for the same
     * deposit. The tenant then owes 329,999.82 of security and the GL credits `deposits_held`
     * twice — precisely the outcome `BillSecurityDepositService` says it exists to prevent
     * (*"no second billing path"*), one step earlier in the flow than the guard it wrote.
     *
     * Same two paths as its twin, so an eager-loaded page and a re-read instance cannot answer
     * differently. The status filter is one wider here — a DRAFT deposit invoice has asked for
     * nothing — and everything else is an AMOUNT question, answered in `DepositBilling`: a
     * write-off that reaches the deposit line comes off what is still claimed, because a forgiven
     * amount will never arrive and counting it as already-asked-for is what left the shortfall
     * permanently un-re-billable. `WriteOffInvoiceService` retires an invoice only on a FULL
     * write-off, so a status filter could never have seen a partial one.
     */
    public function depositBilledOutstanding(): float
    {
        $invoices = $this->relationLoaded('depositBillings')
            ? $this->depositBillings->whereNotIn('status', self::DEPOSIT_CLAIM_EXCLUDED_STATUSES)
            : Invoice::query()
                ->where('lease_id', $this->id)
                ->whereNotIn('status', self::DEPOSIT_CLAIM_EXCLUDED_STATUSES)
                ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
                ->with(['items', 'writeOffs'])
                ->get();

        $outstanding = 0.0;

        foreach ($invoices as $invoice) {
            // `DepositBilling` reads the presenter's own per-line figures and then nets any
            // write-off that reaches the deposit line — a forgiven amount will never arrive, so
            // counting it as already-asked-for is what left the shortfall un-re-billable.
            $outstanding += DepositBilling::claimedOn($invoice);
        }

        return round(max($outstanding, 0), 2);
    }

    /**
     * What still has to be ASKED for — the shortfall less what is already on an open invoice.
     *
     * Two questions, two methods, deliberately: the leases list shows `depositShortfall()` because
     * a deposit that has been billed and not paid is still a deposit we do not hold, and the
     * billing action reads this one because raising a second invoice for it is a double ask.
     */
    public function depositUnbilledShortfall(): float
    {
        return round(max($this->depositShortfall() - $this->depositBilledOutstanding(), 0), 2);
    }

    /**
     * What still has to be ASKED for, read under a LOCK — the figure a second deposit invoice is
     * refused from.
     *
     * The display twin of this trio is fine where it is used: the leases list, the modal's helper
     * text and the lease page all render it, and taking row locks per row per page buys nothing.
     * What is NOT fine is a guard: `BillSecurityDepositService` locks the lease and then asked the
     * PLAIN one, which is the shape this codebase has already been bitten by twice — a lock
     * serialises writers, it does not make the read behind it see them, so under MySQL's
     * REPEATABLE READ the second operator is answered from before it waited. Both operators then
     * read the same outstanding deposit and each raise an invoice for it: the tenant is asked for
     * twice the security they agreed and `deposits_held` is credited twice when they pay.
     *
     * Split into three the same way the display trio is, so a refusal MESSAGE quotes the same
     * figures the refusal DECISION was made from — a "you have already billed 0.00" is a worse
     * refusal than none.
     *
     * **Lock order** is the one this model's pot already states: invoices, then the deposit tables.
     */
    public function depositUnbilledShortfallForUpdate(): float
    {
        return round(max($this->depositShortfallForUpdate() - $this->depositBilledOutstandingForUpdate(), 0), 2);
    }

    /** {@see depositShortfall()}, read under a lock. */
    public function depositShortfallForUpdate(): float
    {
        return round(max((float) ($this->security_deposit ?? 0) - $this->depositHeldForUpdate(), 0), 2);
    }

    /**
     * {@see depositBilledOutstanding()}, read under a lock.
     *
     * The lock is written out here rather than delegated for the reason {@see depositHeldForUpdate()}
     * gives: `ConcurrencyPolicyConformanceTest` reads this method's own body, so a guard whose lock
     * has moved out of sight cannot be silently deleted by a tidy-up.
     */
    public function depositBilledOutstandingForUpdate(): float
    {
        $invoiceIds = Invoice::query()
            ->where('lease_id', $this->id)
            ->whereNotIn('status', self::DEPOSIT_CLAIM_EXCLUDED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
            ->lockForUpdate()
            ->pluck('id');

        $outstanding = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['items', 'writeOffs'])
            ->get()
            ->sum(fn (Invoice $invoice): float => DepositBilling::claimedOn($invoice));

        return round(max((float) $outstanding, 0), 2);
    }

    /**
     * Agreed, less held — never negative.
     *
     * This is the number that was missing everywhere: a lease says 180,000, the bank has 150,000,
     * and nothing on any list said so. An operator asking "who still owes me a deposit?" had to open
     * every lease in turn.
     */
    public function depositShortfall(): float
    {
        return round(max((float) ($this->security_deposit ?? 0) - $this->depositHeld(), 0), 2);
    }

    /**
     * Leases still short of the security deposit they agreed — the QUERY twin of
     * {@see depositShortfall()}, and the only way that question may be asked of a LIST.
     *
     * **It cannot be one `whereRaw`, and pretending it could is what this replaces.** The leases
     * list's "Deposit outstanding" filter re-expressed the pot in SQL: receipts less refunds and
     * forfeits from `deposit_transactions`, less `deposit_applications`. That is three of the FOUR
     * terms {@see depositHeld()} sums. The missing one is {@see settledDepositBillings()} — the
     * deposit BILLED on an invoice and since paid — and that is not an exotic case:
     * `BillSecurityDepositService` raises the deposit on its own invoice and writes no
     * `deposit_transactions` row at all, so it is how a deposit is normally collected.
     *
     * Measured (2026-09-03, SW-055): a lease agreeing 60,000, billed and paid in full, read
     * `depositShortfall() = 0.00` in the list's own `deposit_shortfall` COLUMN and was returned by
     * the FILTER beside it as owing the whole 60,000 — two answers to one question on one page,
     * with nothing to say which to believe.
     *
     * The missing term is `DepositBilling::heldOn()` over `InvoiceItemSettlement`, which splits
     * `invoices.paid_amount` across the lines in priority order and then nets credit relief —
     * arithmetic no correlated subquery can carry, and re-expressing it beside the original is how
     * these two came to disagree. So the CANDIDATES are narrowed in SQL and the POT is asked of the
     * model, once per candidate, off the three relations the list already eager-loads. One
     * definition; the column and the filter cannot answer differently again.
     *
     * Cost is bounded by the leases carrying a positive agreed deposit within whatever the caller
     * has ALREADY narrowed to, so chain this LAST — after the status clause and the property scope
     * — and the candidate query inherits both.
     */
    public function scopeDepositOutstanding($query)
    {
        $short = (clone $query)
            ->where('security_deposit', '>', 0)
            ->with(['deposits', 'depositApplications', 'depositBillings'])
            ->get()
            ->filter(fn (self $lease): bool => $lease->depositShortfall() > 0)
            ->modelKeys();

        // An empty candidate set compiles to `id in ()`, which matches nothing — the right answer
        // when no lease is short, and never "no narrowing".
        return $query->whereKey($short);
    }

    public function postDatedCheques(): HasMany
    {
        return $this->hasMany(PostDatedCheque::class);
    }

    public function tenantRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function salesDeclarations(): HasMany
    {
        return $this->hasMany(TenantSalesDeclaration::class);
    }

    public function camAllocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    // ============ Derived ============

    // ============ BillableAgreement ============
    //
    // The part of a lease that is true of ANY agreement raising AR — who owes, in what currency,
    // and which column records that this agreement raised the invoice. A unit ownership answers the
    // same three (plan 08) and the billing machinery downstream never learns the difference.
    // Everything else the interface asks for (assetId, paymentTermsDays, billingCycleMonths,
    // isBillableForPeriod, charges) this model already had, which is why the seam sits here.

    /**
     * Must this tenant DECLARE their turnover?
     *
     * A separate lease term from whether they PAY percentage rent on it. `has_percentage_rent` was
     * answering both, and they are different clauses: a mall collects turnover from tenants who owe
     * no percentage rent — for sales per m², for the occupancy-cost ratio that says which tenant is
     * in trouble, and to price a renewal at all — and many leases oblige the disclosure without
     * charging on it. Yardi keeps "Sales Reporting Required" as its own field for exactly this.
     *
     * **NULL IS THE NORMAL STATE and means "follow the percentage-rent clause".** Compared with
     * `=== null`, never cast: `(bool) null` is false, which would silently exempt every lease that
     * has not been ruled on — the cast that froze `charges.vat_applicable` and is written up in
     * CLAUDE.md as the worse half of that bug. An explicit `false` is a real answer and keeps its
     * meaning: a percentage-rent lease the operator has excused from monthly filing.
     *
     * Kept beside {@see scopeOwingSalesDeclaration()}, which expresses the same rule in SQL, so the
     * predicate and the query cannot drift.
     */
    public function requiresSalesReporting(): bool
    {
        if ($this->requires_sales_reporting !== null) {
            return (bool) $this->requires_sales_reporting;
        }

        return (bool) $this->has_percentage_rent;
    }

    /**
     * May this lease carry a sales declaration at all — the DUTY or the CHARGE?
     *
     * `requiresSalesReporting()` says who MUST file each month; this says whose filing MEANS
     * something, which is wider by exactly one case: a percentage-rent tenant the operator has
     * excused from monthly filing is not chased, but a declaration they (or staff) enter is still
     * the only thing their percentage rent can be computed from. Read by every door a declaration
     * arrives through or is shown on — the portal and API create (SW-254's review found both
     * still gated on the charge, so a disclosure-only tenant was chased on the 10th and REFUSED
     * when they came to file), the app's `canDeclareSales`, the two declarations tabs and the two
     * sales reports. None of those asks "must"; they ask "can".
     *
     * Kept beside {@see scopeDeclaringSales()}, its SQL twin, for the reason the pair above is —
     * and named apart from it because a scope sharing an instance method's name cannot be called
     * off the class (`Lease::declaresSales()` is the instance method, called statically).
     */
    public function declaresSales(): bool
    {
        return $this->requiresSalesReporting() || (bool) $this->has_percentage_rent;
    }

    public function scopeDeclaringSales($query)
    {
        return $query->where(fn ($q) => $q
            ->where('requires_sales_reporting', true)
            ->orWhere('has_percentage_rent', true));
    }

    /**
     * The SQL half of "owes a sales declaration for this period and hasn't filed one".
     *
     * `missingSalesDeclarationsFor()` below is the authoritative answer and is BUILT ON this scope;
     * it returns a Collection because the fit-out exemption is model logic rather than a column. A
     * table filter needs a Builder, so the Leases table's "owing" filter applies the scope alone
     * and is therefore a SUPERSET of everything the helper answers: clicking the dashboard's count
     * of 3 can land on 4 rows if one is still in fit-out, but it can never land on a list MISSING a
     * lease the card counted. That is the safe direction for a "go and chase these" link; the
     * reverse would send someone to a page that appears to contradict the number they clicked.
     */
    public function scopeOwingSalesDeclaration($query, CarbonImmutable $periodStart)
    {
        return $query->where('status', 'active')
            // The DUTY to declare, not the charge — see `requiresSalesReporting()`. Null follows
            // the percentage-rent clause, so this reads exactly as it always did until an operator
            // states otherwise on a lease.
            ->where(fn ($q) => $q
                ->where('requires_sales_reporting', true)
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('requires_sales_reporting')
                    ->where('has_percentage_rent', true)))
            ->whereNotNull('commencement_date')
            ->whereDate('commencement_date', '<=', $periodStart->endOfMonth())
            ->whereDoesntHave('salesDeclarations', fn ($q) => $q->whereDate('period_start', $periodStart));
    }

    /**
     * Active leases that OWE a sales declaration for the period and have not filed one — past
     * their fit-out grace, so a lease that isn't billable yet isn't chased either.
     *
     * ONE definition, four callers: `sales:scan-missing-declarations` (which chases the tenant),
     * `sales:estimate-missing` (which estimates a percentage-rent tenant's turnover once chased),
     * the month-end close checklist and the dashboard's "missing declarations" card. The same rule
     * lived only inside the command until 2026-08-08; a second copy in the checklist would have
     * been the third place "which leases owe a declaration" was written down, and the first place
     * it silently disagreed. Same reasoning as `isBillableForPeriod()`/`scopeBillableForPeriod()`
     * above.
     *
     * **Composed from `owingSalesDeclaration()`, never restated (SW-254, 2026-09-11).** This method
     * carried its own `where('has_percentage_rent', true)` from before the duty had a column of its
     * own, and the dashboard card carried a third copy — so when `requires_sales_reporting` landed
     * (2026-08-30) it reached the scope and the lease list's filter and NOT the two commands or the
     * card: a percentage-rent tenant the operator had EXCUSED was still chased on the 10th and still
     * estimated on the 17th, and a disclosure-only tenant was never chased at all, while the filter
     * beside them showed the set the operator had actually ruled on. The scope is the one place the
     * duty is written in SQL; everything that answers "who owes one" reads it.
     *
     * `$assetIds` takes the shape the caller holds — the checklist's one property, the dashboard's
     * `visibleAssetIds()` — and an EMPTY list matches nothing rather than everything, because the
     * wrong direction for a scope that has been handed no properties is the whole portfolio. The
     * period is ONE month named by its first day: the end used to be a second parameter, which the
     * scope now derives for itself, and a caller could hand the fit-out test a window the scope
     * never saw.
     *
     * @param  int|list<int>|null  $assetIds
     * @return Collection<int, static>
     */
    public static function missingSalesDeclarationsFor(CarbonImmutable $periodStart, int|array|null $assetIds = null): Collection
    {
        $periodEnd = $periodStart->endOfMonth();

        return static::query()
            ->owingSalesDeclaration($periodStart)
            ->when($assetIds !== null, fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', (array) $assetIds)))
            ->with('tenant')
            ->get()
            // Fit-out is a model-level rule, not SQL — a lease still inside its grace is not yet
            // billable, so it is not yet chaseable either.
            ->reject(fn (self $lease) => $lease->periodInFitOut($periodEnd))
            ->values();
    }

    /**
     * When this tenant was CHASED for the period's sales declaration — or null, never.
     *
     * The ONE definition of "was a reminder recorded", read by two commands that used to hold
     * different halves of it: `sales:scan-missing-declarations` asked it for idempotency (do not
     * nag twice) and `sales:estimate-missing` never asked it at all (SW-253) — the "week after the
     * chase" was a SCHEDULE DAY, so a chase lost to any cause (SW-252's dead transport, a scan that
     * did not run) still ended in an estimate on a tenant who was never asked. The record is the
     * tenant's own bell row: `notifyPortal()` writes one for the company and one per portal login,
     * and the company's is the one to read (a login can be deleted; the company cannot). It is
     * NOT durable beyond `HousekeepingSettings::notification_retention_days` (default 90) —
     * `atriom:prune-transient-data` deletes bell rows by age — which is why the estimate's
     * lookback is bounded at three months and its test pins the default against it.
     *
     * The benchmark documents that Voyager bills an estimate when a declaration is missing and
     * documents no notice as a prerequisite; requiring one is Atriom's stricter reading, stated in
     * `docs/benchmarks/yardi/03` B5. Making the notice a stamp rather than a calendar assumption
     * is the whole change.
     */
    public function salesDeclarationRemindedAt(string $periodKey): ?CarbonImmutable
    {
        $at = $this->tenant?->notifications()
            ->where('data->type', 'sales_declaration_reminder')
            ->where('data->lease_id', $this->id)
            ->where('data->period_key', $periodKey)
            ->min('created_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    // ============ Generation helpers ============

    /** `LSE-AW-2026-` — the sequence the numbers below run inside. */
    public static function referencePrefix(string $assetCode = 'AW'): string
    {
        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('lease'), $assetCode, now()->format('Y'));
    }

    /**
     * The next lease reference in this property-year.
     *
     * **This was `count() + 1`, and that was a deterministic 500.** `leases.reference` is UNIQUE
     * and the model soft-deletes, so `static::count()` — which the soft-delete scope excludes
     * trashed rows from — falls behind the numbers actually issued. Delete one lease of five and
     * the next create computes `…-0005`, which already exists. The insert throws a duplicate-key
     * error, and it throws again on every subsequent attempt, because the count never recovers:
     * **lease creation stays broken for the rest of the calendar year.** Deleting an unused lease
     * is a supported action (`DeletionPolicy` puts Lease in WHEN_UNUSED, and EditLease offers it),
     * so this was reachable by design, not by misuse.
     *
     * Now MAX-of-prefix over `withTrashed()`, which is monotonic and cannot go backwards — the
     * same shape `Invoice::generateNumber()` has used all along, four files away.
     */
    public static function generateReference(string $assetCode = 'AW'): string
    {
        $prefix = static::referencePrefix($assetCode);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a
            // series passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * MAX+1 with a collision loop — the belt to the lock's braces.
     *
     * A gap-free MAX is still only correct if nothing has taken the number since we read it. The
     * lock in `AllocatesDocumentNumber` is the primary guard; this loop is what happens when the
     * lock times out and degrades to unlocked allocation.
     */
    protected static function generateUniqueReference(string $assetCode = 'AW'): string
    {
        $candidate = static::generateReference($assetCode);
        $prefix = static::referencePrefix($assetCode);
        $attempts = 0;

        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            if (++$attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique lease reference after 100 attempts.');
            }

            $candidate = sprintf('%s%04d', $prefix, (int) substr($candidate, strlen($prefix)) + 1);
        }

        return $candidate;
    }
}
