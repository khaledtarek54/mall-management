# Leases

> **⚠️ A LEASE IS ACTIVATED BY AN ACT, ONCE THE MONEY IS IN — AND A RESERVATION LAPSES WHEN IT IS
> NOT (client meeting 2026-09-02, points 1·2·3; shipped 2026-09-11).** Until then activation was a
> DROPDOWN: anyone holding `leases.edit` picked `active` on the form, nothing asked whether the
> deposit or the cheques had arrived, the wizard created every lease `active` unconditionally, and a
> draft held its shop off the market (`reserved`) for ever. **The standard.** Voyager, MRI and
> Entrata all put an approval between entering a lease and its going live, so activation is an ACT
> with its own right — `leases.activate`, granted to **accounting** (with `leases.view`, and
> deliberately NOT `leases.edit`): leasing enters, accounting executes, Yardi's entering-vs-posting
> split, the same shape as `invoices.issue`. A MONEY gate is Yardi's residential *"no move-in with a
> balance"* — a per-property control, not Voyager Commercial's default — so it ships as a per-property
> setting whose default is Yardi's, and the client's rule is what they SET. A hold that lapses is
> Yardi's unit-hold expiry. **Configurable the way the market is (`/safe-change` §3b), four settings,
> all per property (`PropertySettings::OVERRIDABLE`), every default the market's so nothing moves
> on deploy:**
>
> | Setting | Values | Ships | The client sets |
> |---|---|---|---|
> | `billing.lease_activation_requires` | `none` · `deposit_received` · `deposit_or_cheques` | `none` | `deposit_or_cheques` |
> | `billing.reservation_valid_days` | days, 0 = never | `0` | their X |
> | `billing.default_security_deposit_basis` | `months` · `percent_of_annual_rent` · `fixed` | `months` | their clause |
> | `billing.default_security_deposit_percent` | % of annual rent | `0` | — |
>
> **The one predicate is [`App\Support\LeaseActivation`](../../app/Support/LeaseActivation.php)**
> — `isAwaiting()` (**`pending_approval` only** — a `draft` is terms still being written, so the act
> never executes one and the sweep never cancels one; leasing promotes a draft to awaiting when the
> deal is signed, which is what the status has always been for) and `shortfall()` (what is still
> missing: the agreed
> `security_deposit` against `Lease::depositHeld()`, or lodged `held`/`deposited` PDCs on the lease
> where the property counts cheques) — read by the button, the service and the sweep. With the
> setting at `none`, ENTRY EXECUTES exactly as before: the form offers `active`, the wizard creates
> `active`. With money required, the form withholds `active` on a new lease, the wizard enters it
> `pending_approval` (label now *"Awaiting activation"*), and **the Activate act is the only door**:
> [`ActivateLeaseService`](../../app/Services/ActivateLeaseService.php) locks the lease, refuses on
> the shortfall in the reader's words with the figures, locks every unit of the lease and refuses if
> one was let meanwhile (a pending lease does NOT hold its premises — `HOLDS_PREMISES` — so
> activation is the moment it starts to, and the double-let guard belongs here as it does in
> creation; lease→units order, SW-009c), writes `executedStatusFor()` (active, or `future` if the
> commencement is ahead), clears `reserved_until`, records `TYPE_ACTIVATION`. **The act lives on the
> ROW of the leases list** (registered in `RowActionPolicy::IN_ROW_EXCEPTIONS`): the record page is
> reached through `canEdit()`, which accounting does not hold, so a header act would be unreachable
> by exactly the role whose job it is — the *Awaiting activation* tab + the row button IS the
> accountant's worklist (point 2), with the shortfall on the button before the click and the button
> disabled until it is met. **The reservation**: `Lease::creating` stamps `reserved_until` = today +
> the property's days on every awaiting lease, whichever door entered it; `leases:expire` cancels
> one past its day — `TYPE_CANCELLATION`, narrative `reservation_lapsed`, unit freed by the observer,
> `ReservationLapsedNotification` to manager + leasing after commit — **unless** the property gates
> nothing or has since set its window to 0 (the sweep reads the CURRENT policy, so "0 = never" means
> never for a date stamped earlier too), the money HAS arrived (the accountant's to activate), or an
> ISSUED invoice stands on it (a person's call under a live document); those stay on the tab with
> the date in red. The window is cleared on EVERY exit from awaiting — the act, the dropdown on an
> ungated property, a cancellation — and `RENEWAL_RESETS` names it, so an active lease never carries
> a hold and a renewal never inherits one. **The deposit basis** (point 3): the market standard is a fixed sum or a multiple
> of the monthly rent, which the lease has expressed since EG-35; *"% of the annual rent"* is the
> Egyptian / GCC clause convention and arithmetically a multiple by another name, so it is a third
> BASIS on the same field (`security_deposit_basis`, `security_deposit_percent`), and
> [`DepositBasis::derive()`](../../app/Support/DepositBasis.php) is the ONE arithmetic the model's
> `saving` hook, the wizard and the form's preview read. Backfilled from the months column (a
> multiple = months, none = fixed) so no lease changed meaning; the wizard now writes the basis and
> multiple rather than the derived sum, so a wizard lease's deposit tracks its rent as a form
> lease's always did. The importer and exporter carry basis and percent. **Deliberately not built:**
> the API does not expose the basis (the amount is the term the tenant is billed for); a renewal or
> holdover conversion is an executed lease already and does not re-enter the gate.
> **The review of this change found two blockers and eight real faults in it, every one closed
> and pinned.** The per-property override page cast EVERY value to a float on save, so choosing
> `deposit_received` for a mall stored `0`, read back as `none`, and the gate stayed off on the mall
> everybody believed it was on — and `billing.proration_method` had been dead the same way since its
> dropdown was added on 2026-08-23; `PropertyOverrides::normalise()` now stores a choice as the word,
> a switch as a bool, a figure as a float. And `post_dated_cheques.lease_id` had existed since the
> register shipped with NO door writing it, so `deposit_or_cheques` — the client's own option — could
> never be satisfied from the panel; the cheque form and the series modal carry a lease picker now,
> the model fills it from the invoice a cheque is lodged against and refuses another tenant's or
> another mall's lease. The rest: a rent-linked basis whose figure was blank stored whatever the
> disabled amount last read (required under its basis now); the create form never proposed the
> property's percent (it does); a percent policy carrying 0% proposed a 0.00 deposit that walked
> through the gate (`DepositBasis::defaultsFor()` falls back to months); the tab relabel had hit the
> procurement board's shared key (its own key now); the window survived every exit from awaiting
> but the act's and rode onto renewals; the sweep ignored a window switched back to 0 while the
> column that shows the date hid; the importer's `active` door is now named in its own comment.
> Recorded rather than fixed: activation holds the lease then asks for the unit while creation
> holds the unit and scans `leases` FOR UPDATE — a cycle InnoDB resolves by rolling one side back
> (a retry, never a double let), the same shape renewal and holdover carry; and a lease activated
> after the property's billing day waits for the next run or the weekly unbilled-periods scan for
> its first invoice. `ALeaseIsActivatedByAnActOnceTheMoneyIsInTest` — twenty-five cases,
> twenty-three mutations; three stay green for a stated reason (the model re-derives the executed
> status the service also writes; `->authorize()` hides what `visible()` would have shown —
> Filament's own contract; and the cheque rule is defended in two clauses, red only when both go).

> **⚠️ Nothing ever moved a lease from `active` to `expired` (fixed 2026-08-19).** There was a
> `vendors:expire-contracts` sweep for vendor contracts and no equivalent for leases, so a lease
> whose term had run out stayed `active` indefinitely unless a person renewed, terminated or held it
> over. Measured on a lease that expired 2026-01-31, with today at 2026-08-19: it still read
> `active`, its unit still read `occupied` (so every occupancy figure and the rent roll overstated),
> **the unit could not be re-let** — creation refused with *"this unit already has an active lease"*
> on a shop that was physically empty — and `RentEscalationService` kept stepping its rent, writing
> schedule rows for years the tenancy did not cover. Invoices were never at risk: billing refuses an
> ended lease with `lease_ended`. What was wrong was the STATE, and everything that reads it.
>
> [`leases:expire`](../../app/Console/Commands/ExpireLeasesCommand.php), daily at 05:15. A converted
> **holdover is excluded** — its expiry is in the past by design and `holdover_from` is what keeps it
> billing. The escalation sweep carries **its own** term guard as well, because the two protect
> different things: the sweep fixes the state, and the query refuses to act on a lease the sweep has
> not reached yet. Pinned by `LeaseExpirySweepTest`.

> **⚠️ The double-booking guard did not fire under real concurrency (fixed 2026-08-19).**
> `LeaseCreationService` takes `Unit::lockForUpdate()`, and this doc called the `isActivelyLeased()`
> check behind it *authoritative*. It was not. Under MySQL REPEATABLE READ the transaction's
> consistent-read snapshot is fixed at its FIRST plain read — the tenant lookup, one line above the
> lock — so the guard was answered from before the wait. Proven with two processes on two
> connections: the second transaction's guard returned **false** with the first transaction's lease
> committed on that unit, while a **locking** read of the same query at the same instant returned 1.
>
> What actually prevented the double-booking was the UNIQUE index on `leases.reference` — and only
> because both writers computed the SAME number from the same stale snapshot, so the loser got a
> duplicate-key **500** instead of the intended refusal. `Unit::isActivelyLeasedForUpdate()` is the
> locking read; the plain method stays for form validation and table columns, where taking row locks
> on every render would cost with no reader waiting.
>
> Separately, the numbering lock was being bypassed: `Lease::creating` allocates the reference under
> `AllocatesDocumentNumber` and returns early when one is already filled, so `LeaseCreationService`
> and `LeaseRenewalService` pre-computing it skipped the lock entirely. Both now let the model
> allocate. After the fix the race ends in *"This unit already has an active lease"*.
> Pinned by `ConcurrencyGuardsReadUnderLockTest` (structure) and `docs/qa/scripts/race.sh` (the real
> two-process proof, which the SQLite suite cannot give).


> **New to the business rather than to the code?** Read
> [`docs/training/LEASING-WALKTHROUGH.md`](../training/LEASING-WALKTHROUGH.md) first — the same module
> explained for someone with no property or accounting background, field by field and button by
> button, with 16 hands-on exercises against `LearningSeeder`. This file is the developer's account:
> invariants, past bugs and extension points.

> A lease is a binding occupancy contract between a tenant and a unit (or units) with linked charges (rent + service fees), escalation terms, optional percentage rent, and a multi-state lifecycle from draft through expiry/renewal/termination.

> **⚠️ The lease hub is complete — the Summary landed (2026-08-17, UX-01).** Every tab already made
> a fact *reachable*; none made the important ones *visible together*, and the story that asked for
> this page said why in one line: **"so that I stop hunting across five resources."**
>
> [`LeaseSummary`](../../app/Filament/Admin/Widgets/LeaseSummary.php) is a header widget above the
> tabs, carrying six stats: **rent today** (+ the next step) · **premises** as at today · **term**
> (+ days to expiry, or holdover) · **outstanding** (+ how many overdue) · **deposit held vs
> contractual, naming the shortfall** · **next critical date** from the open options.
>
> **It computes nothing of its own** — `ChargeScheduleService::pickInForce()` for the rent (the same
> selection billing uses), `MoveOutStatementService::depositHeld()` for the deposit, the invoices
> for the AR. A summary with its own arithmetic is a second opinion, and the first thing anyone
> notices is that it disagrees with the tab underneath it.
>
> **One deliberate departure from `pickInForce()`**: it falls back to the latest active row when
> nothing covers the date, which is right for a rent roll (a pre-schedule lease with one open-ended
> row must not read as "no rent") and wrong here — this card answers *what is billing TODAY*, and a
> lease that has not commenced is billing nothing. The lease's own commencement is the gate, which
> is the same fact `isBillableForPeriod()` refuses on.
>
> A **header widget, not a separate View page**, though the story specified one: the lease page
> already IS the record hub, and a second surface showing the same facts is one that drifts from it —
> the same reasoning that put the actions in a single registry. Page-scoped and registered in
> `DashboardLayout::NOT_ON_DASHBOARD`, because a widget nobody classified once published a
> property's whole receivables ledger to every role on the panel. Pinned by `LeaseSummaryTest`.

> **⚠️ Extending a term is an ACT now, not a typed date (2026-08-17).** `expiry_date` and
> `term_months` were free text on the form, so a further term happened by typing a date: no reason,
> no actor, no event, and nothing downstream able to tell an extension from a correction.
> `LeaseEvent::TYPE_EXTENSION` had been declared and **never written by anything** — the same shape
> `relocation` still has. This was the last commercially-significant field with no act behind it.
>
> [`LeaseExtensionService`](../../app/Services/LeaseExtensionService.php) + the **Extend term**
> action; both date fields lock once the lease has been invoiced.
>
> **An extension is not a renewal, and keeping them apart is the point.** A renewal ENDS this
> tenancy and starts a new lease with its own reference, its own negotiated terms and its own
> document (`previous_lease_id` is the chain). An extension leaves the SAME contract running longer
> on the same terms — a "further term", or an exercised extension option. Modelling one as the other
> loses which happened, and Yardi keeps them separate for the same reason.
>
> Three behaviours worth knowing: it **refuses to pull an expiry backwards**, because ending a
> tenancy early is a *termination* — which settles the deposit, credits unearned billing and closes
> the schedule, none of which this does; it **does not re-date the charge rows**, because they are
> open-ended and a longer term simply keeps billing them (re-dating would be the bug); and it **does
> re-project the escalation ladder**, because anniversaries that fell past the old expiry now fall
> inside the term, and a lease must not run two more years with its future rent recorded nowhere.
> `term_months` is re-derived from the new date via `LeaseTerm::monthsSpanning` — a further term is
> negotiated to a DATE, so the date is the fact and the month count describes it. Pinned by
> `LeaseTermExtensionTest`.
>
> **And an unrecorded option is now a portfolio question.** `leases:scan-option-windows` reads
> `lease_options` and nothing else, so a clause nobody abstracted is a right nothing will ever alert
> on — inherent, and true of Yardi too. The lease's own panel already said so when empty; the
> leases list now carries a **"No options recorded"** filter, which turns "which contracts have not
> been abstracted yet" from a question nobody could put to the system into one click.

> **⚠️ The list FINDS, the record ACTS (2026-08-17).** Nine commercial actions hung off every row of
> the leases list while the lease's own page carried one — so an operator who opened a lease had to
> go back to the list to do anything to it. That is backwards from the record-hub information
> architecture this project took from Yardi ([benchmark 08](../benchmarks/yardi/08-yardi-ui-ux.md)),
> and a row of nine equally-weighted verbs reads as noise rather than as choices.
>
> Worse, with the definitions living in one surface only the two could never be kept in step: an
> action added in one place silently left the other behind, which is what had happened.
>
> **[`App\Filament\Admin\Actions\LeaseActions`](../../app/Filament/Admin/Actions/LeaseActions.php)
> is now the single definition**, and both surfaces compose from it by name — possible without a
> wrapper because `Filament\Actions\Action` is one class for a table row and a page header in v4.
> The table keeps **View + Edit**; the lease page carries three grouped dropdowns — **Money**
> (change rent, grant relief) · **Premises** (change premises, let/give back a bay) · **Lease**
> (renew, holdover, terminate, final account) — beside Generate invoice.
> `LeaseActionTopologyTest` fails the build if a bespoke `Action::make()` reappears on a row, or if a
> group smuggles in an action the registry does not own.

> **⚠️ The nightly sweep used to make the holdover decision unreachable (fixed 2026-09-01).**
> `leases:expire` runs at 05:15 and projects every active lease past its term to `expired` — and that
> candidate set is *exactly* the holdover-conversion candidate set. Four doors shut at once: the
> service refused anything but `active`, the button's `visible()` asked `isHoldover()` (which
> requires `active`), the ActionRequired card's scope did the same, and `expired` is in
> `TERMINAL_STATUSES` so the immutability hook refused every commercial write. The whole LE-04
> workflow was reachable between midnight and 05:15 on the single morning after a term ended, and
> never again.
>
> The root cause is that **`expired` is a PROJECTION and a TERMINAL STATUS at the same time** — a
> machine's guess about today, written into a column whose other values are decisions that closed the
> record. `ProjectedState`'s two other projections each carve out a human's statement
> (`units.status = 'maintenance'`, `rentable_items.status = 'out_of_service'`); this one had none.
> Whether the tenant is still trading is the one fact only a person holds, and the sweep was
> asserting the opposite on their behalf before anyone was at their desk.
>
> `Lease::awaitsHoldoverDecision()` (and its SQL twin `scopeHoldoverNeedingAction()`) is now the one
> predicate the service, the button and the card all read; it spans `active` **and** `expired`, and
> excludes a tenancy somebody really closed — derived from the immutable termination event, never a
> new column. Conversion writes `status => 'active'` back, or it would succeed and still bill
> nothing (`isBillableForPeriod()` requires it). `Lease::isResumingFromExpiry()` lifts the
> immutability hook for that one write, recognised by its **shape** rather than by trusting a caller:
> `expired` → `active`, `holdover_from` null → set, and nothing dirty among the commercial terms in
> `HOLDOVER_RESUMPTION_FORBIDS`. `terminated`, `cancelled` and `renewed` stay absolutely immutable.
>
> **It locks the UNIT**, because resuming makes a lease active on a shop again and this is the third
> writer that can do that. The hole it closes is sequential rather than a race: the sweep vacates the
> unit, leasing legitimately re-lets it, and the old lease is converted weeks later — two active
> leases on one shop, both billing, with `Unit::recomputeStatus()` reporting `occupied` either way.
> A re-let shop is excluded from the predicate too, so the card cannot offer work the service will
> decline. (`AnExpiredLeaseCanStillBeHeldOverTest`, mutation-proved.)
>
> **⚠️ THE SAME SWEEP MADE THE RENEWAL UNREACHABLE, AND THAT WAS THE COMMONEST OF THE THREE
> (2026-09-10).** At the end of a term an operator has exactly three answers — hold the tenancy
> over, close it out, or **renew** — and `expired` shut all three at once for the reason above. Two
> were fixed as they were reported: holding over on 2026-09-01, closing out on 2026-09-10. Renewing
> was not, and it is the one that happens most: a renewal is routinely signed **weeks after the old
> term ran out**, because the parties negotiate while the tenant keeps trading.
>
> The only route left was to **convert to holdover first and renew the resumed lease** — which
> prices those months at the holdover uplift (150% by default) that the parties never agreed to. A
> workaround that bills the tenant a penalty is not a workaround.
>
> **The market standard is to renew from the lease whatever its status.** Yardi renews a Current or
> Past lease alike, and so do MRI and Entrata; the renewal is dated back to the day after the old
> term so the **tenancy has no gap**, and the months already elapsed bill on the next run.
> `Lease::canBeRenewed()` is the ONE predicate the Renew button and `LeaseRenewalService` both read,
> so the panel can no longer hide work the service would accept. `terminated`, `cancelled` and
> `renewed` stay refused — each is a person's act with its own successor document — and
> `Lease::isRenewingAnExpiredTerm()` lifts the immutability hook for the single `expired` → `renewed`
> write, recognised by SHAPE exactly as its two siblings are, bounded by
> `HOLDOVER_RESUMPTION_FORBIDS` (not `CLOSE_OUT_FORBIDS`: a close-out MOVES the expiry date, a
> renewal must not, because the original's term is precisely what the successor continues from).
>
> **`isOpenPastItsTerm()` is the predicate extracted on its second real call site** — *the term ran
> out and the tenancy is still open* — with `awaitsHoldoverDecision()` now composing it. Still open
> is DERIVED: a tenancy somebody closed carries an immutable termination event, a shop leasing
> re-let carries a second active lease.
>
> **THE GUARD THAT `active` USED TO GIVE FOR FREE.** An active lease holds its own shops, so a
> renewal could not collide with anything. A lease past its term holds nothing — the sweep vacated
> every unit that morning — so leasing may legitimately have re-let one while the renewal was being
> negotiated, and renewing then puts **two active leases on one shop, both billing**, with
> `Unit::recomputeStatus()` reporting `occupied` either way. Refused on **two deliberately redundant
> layers**: the predicate's plain read (the sequential case — re-let days ago) and a LOCKING read
> inside the transaction (the race — a re-let committed while this renewal was in flight, which a
> plain read there is answered from before). Neither can be mutation-killed alone because each
> covers for the other; the regression test asserts BOTH, and the race layer is gated by
> `ConcurrencyPolicy::AUTHORITATIVE_GUARDS`, which already registers
> `Unit::isActivelyLeasedForUpdate`. The refusal **names the unit**
> (`Lease::unitLetToSomebodyElse()` returns the shop rather than a flag, so the predicate that
> refuses and the sentence that explains it cannot disagree), because the fact the operator needs is
> on the shop and not on the lease.
>
> **AND IT IS EVERY UNIT, NEVER THE MASTER POINTER — the review's first finding.** `syncUnits()`
> re-attaches the original's WHOLE unit set, so guarding `leases.unit_id` guarded one of N. A lease
> over A-01 + A-02 is vacated on both, and leasing can then sign a new lease with **A-02 as its own
> master** — legitimately, because the creation guard asks whether A-02 has an ACTIVE lease and this
> one is `expired`. The renewal then double-booked A-02, and `Lease::totalAreaSqmForPeriod()` counted
> that shop **twice in the CAM denominator**, mis-apportioning every other tenant in the pool while
> Σ allocated = actual expense stayed green. The SQL twin `scopeHoldoverNeedingAction()` was widened
> the same way — through this lease's own `lease_unit` pivot on both sides — or the dashboard card
> would offer work the service refuses.
>
> **THE BAY CARRY BYPASSED THE ONLY DOUBLE-LET GUARD BAYS HAVE — the review's second.** The loop
> `attach()`es directly, so none of `AssignRentableItemService`'s guards run, `isHeldOn()` included.
> That was safe while only an `active` lease could be renewed, because such a lease still held its
> bays. An `expired` one opens a real window: the sweep frees the bay the morning the term ends, an
> operator lets it to another tenant a fortnight later, and the renewal re-attaches it open-endedly
> from the day after the old expiry — **two live holdings on one bay**, and the pivot is keyed on
> `(holder, item, effective_from)` so nothing in the database catches it. Refused now, naming the
> bay; asked only when the original is `expired`, because for an `active` one its OWN holding makes
> `isHeldOn()` true and refusing there would drop every bay on every ordinary renewal.
>
> **THE CARVE-OUT'S SHAPE NEEDED A SUCCESSOR — the review's third.** The docblock defended
> `expired` → `renewed` on the panel being closed, which it is (`LeaseForm` never offers `renewed`,
> `EditLease` halts on `isTerminal()`). **The IMPORTER is not a panel.** `LeaseImporter` accepts
> `status: renewed` and `resolveRecord()` does `firstOrNew(['reference' => …])`, so one CSV row
> against an `expired` lease would have rewritten `base_rent_monthly`, `service_charge_monthly`,
> `term_months` and `security_deposit` — none of them in the denylist, because none is a column the
> renewal service writes — and marked it `renewed` **with no successor at all**. The shape now also
> requires a lease pointing back at this one, which the service creates first and an import row
> cannot fabricate. (The holdover sibling was always tight for the same reason: it additionally
> requires `holdover_from` to move null → set, a column no other writer touches.)
>
> **AND THE MODAL WAS PROMISING A CATCH-UP THAT NEVER COMES.** It first read *"the months since will
> be billed on the next run"*. They will not: `RunMonthlyBilling` bills exactly
> `now()->startOfMonth()` and no caller loops over missed periods, so a renewal keyed in September
> and dated to 1 July invoices September alone and nothing reports the two missing months — a silent
> revenue leak the operator had been told to expect. It now says they are **not** billed
> automatically and names the Billing forecast tab, which raises a past period one at a time. A
> CONVERTED HOLDOVER is excluded from that sentence altogether: its expiry is always past, and those
> months have already been invoiced at the uplift, so telling the operator they are unbilled invites
> a second invoice for the same period.
>
> **A PARKING BAY IS DELIBERATELY NOT PART OF THIS, and the reverted attempt is the finding.**
> Widening `AssignRentableItemService` to an `expired` lease was tried and measured: a bay attached
> to one reads **AVAILABLE the instant it is attached** (`rentable_items.status` is a projection
> whose stated meaning is that a term ending RELEASES the space, exactly as the same sweep vacates
> the unit), can be let to somebody else with **no clash raised** (`isHeldOn()`, the double-let
> guard, reads the same `active|pending_approval` list), and **bills nothing**
> (`isBillableHoldoverFor()` needs `holdover_from`). Three defects, not a feature. It is also what
> Yardi does: a Voyager *Past* lease acquires no rentable items, and Voyager's month-to-month
> resident — Atriom's CONVERTED holdover, which is `active` — is the one who can. Continuing a
> tenancy stays an explicit act, and the bay follows the tenancy rather than outliving it.
>
> **And the carry filter behind it was wrong in the other direction.** The renewal copies
> `rentable_item_holdings` and deliberately does not copy `effective_to`, but it read the WHOLE
> holding history — so **every bay the tenant had already given back was re-attached to the renewal
> open-endedly** and `rebuildCharge()` billed it again, on a document nobody re-reads item by item.
> Bounded at the **EARLIER of the old expiry and the renewal's commencement**. Expiry is the right
> answer for the ordinary case — a holding scoped to the old term ends ON the last day, so a
> commencement-based bound would drop exactly the bays this loop exists to carry — but
> `commencement_date` is an unbounded picker and an EARLY re-gear (a new term starting before the
> old one ended) is ordinary retail practice, where a bay live on the day the new term began was
> being dropped. (`ARenewalCanBeSignedAfterTheTermHasEndedTest`, twelve teeth mutation-proved.)

> **And the premises field stopped being a second, lossy path.** `EditLease::afterSave()` feeds
> `additional_unit_ids` to `syncUnits()`, which is a `sync()` — so REMOVING a unit there **detached
> its `lease_unit` row**. That row carries the `effective_from`/`effective_to` that
> `totalAreaSqmForPeriod()` allocates CAM on, so the deletion did not end the tenant's occupancy: it
> erased the months they genuinely held the space, silently restating a reconciliation that may
> already be closed. Two clicks, no warning. The field is read-only on Edit now, and **Change
> premises** — which CLOSES the row — is the only path.

> **The DEFAULT multiple is a setting, not a literal (EG-35, 2026-08-22).**
> `BillingSettings::default_security_deposit_months` (3.0), per-property overridable. It had been
> the `3` in `LeaseCreationService`'s `$rent * 3`, so *"three months from Q1"* was a deploy and
> *"two months at the outlet mall"* was unsayable. It PROPOSES the figure; `security_deposit_months`
> on the lease still records what was negotiated, and the derivation below is unchanged.

> **⚠️ A lease in HOLDOVER is priced at its uplift, and every derivation must honour that
> (EG-40, 2026-08-22).** `base_rent_rate_per_sqm_year` stays CONTRACTUAL — that is what a rate means
> — and `holdover_rate_pct` is the premium recorded on top of it. Re-rating on conversion would bake
> a temporary penalty into the contracted rate and lose what the parties agreed.
>
> But `deriveBaseRentFromRate()` ignored the premium, and `LeaseSpaceChangeService` re-derives from
> the rate when a rate-priced lease takes more space — so taking an extra unit mid-holdover silently
> dropped the rent to 100% of contracted (120,000 where 180,000 was owed). The derivation now
> applies the premium from `holdover_from` onward, **the same way the conversion applies it**
> (premium on the contracted figure, each step rounded), so the two cannot disagree about the same
> lease. A date before the conversion is still contracted.

> **The SAME defect had a third door, and the two directions were wrong in opposite ways (SW-049,
> 2026-09-03).** `LeaseSpaceChangeService` was taught the premium in August; `LeaseRentChangeService`
> was not — it did the arithmetic inline and the word `holdover` appeared nowhere in the file. The
> model hook cannot compensate: `Lease::saving` deliberately skips re-derivation when this service
> states a rent, under a comment saying it *"must not be second-guessed"*.
>
> **Rate → rent UNDER-CHARGED.** The Change Rent modal PREFILLS the contractual rate and `required()`s
> it on a rate-priced lease, so even a save that only meant to change the service charge re-states
> 4,800 and writes the contracted rent back. Measured on 250 m² at 4,800/m²/yr under a 150% holdover:
> **150,000 → 100,000**, a silent 50,000/month drop, on an edit nobody intended as a rent change.
>
> **Rent → rate INFLATED THE CONTRACT.** `RentEscalationService` keeps holdovers in scope on purpose
> and passes the UPLIFTED rent, so the inverse wrote the premium into the contractual rate — 157,500
> gave **7,560/m²/yr where 5,040 is contracted**, ×1.5 exactly, and every later rate→rent derivation
> compounds it.
>
> Both now go through the model's helpers, which is the only way the two directions cannot disagree.
> **The inverse is a SECOND method, not an edit to the shared one** — `deriveRateFromBaseRent()`'s
> other caller is `LeaseRenewalService`, which passes a rent the parties NEGOTIATED for the new term
> (EG-39: the deal wins and the rate follows it), and that figure is already contractual even when
> the lease it renews is holding over. Teaching the shared helper about the premium would divide a
> freely agreed number by 1.5 and understate every renewal rate struck off a holdover — EG-39's own
> defect re-created one column along. The two callers pass semantically different rents; that is the
> whole reason for `deriveContractedRateFromEffectiveRent()`. It honours `holdover_from` in both
> directions, so a back-dated change into the contracted period carries no premium.


> **⚠️ A LEASE THAT OPENS ON TWO SHOPS IS PRICED ON BOTH OF THEM (2026-09-02).**
> Its sibling above, one step earlier in the lease's life. `Lease::saving` derives
> `base_rent_monthly` on CREATE through `deriveBaseRentFromRate()`, which reads the `lease_unit`
> pivot — and **on a create that pivot is empty**: `LeaseObserver` writes the master row in
> `created`, and `CreateLease::afterCreate()` attached the ADDITIONAL units last of all, *after*
> seeding the charges and projecting the whole term's ladder. So the derivation fell through to
> its own master-unit fallback and nothing re-asked once the second shop was attached.
>
> Measured on Val Plaza: A-03 (90 m²) + A-04 (120 m²) at 1,000/m²/yr saved **7,500** a month where
> 17,500 was due — ladder 7,500 → 8,025 → 8,586.75, marketing levy 375, deposit 22,500. A lease
> under-billed by 10,000 a month for a three-year term, and **wrong in five places from one column**
> because everything downstream is derived from `base_rent_monthly`.
>
> `afterCreate()` now runs **premises → rent → charges**, and the re-derivation is
> `Lease::repriceFromPremises()` — beside its two twins, refusing the moment an invoice exists.
> That guard is not decorative: re-deriving a lease that has BILLED restates months already
> invoiced, and that act needs an EFFECTIVE DATE, which is exactly what `LeaseSpaceChangeService`
> takes. The master-unit fallback in `deriveBaseRentFromRate()` **stays** — an importer or a test
> that never touches the pivot has to price on something; what was missing was a caller re-asking.
>
> **The FORM told the same lie and that is why it survived.** `additional_unit_ids` carried
> `->live()` under a comment promising the rent re-derives *"the moment the let area changes"* —
> and `->live()` only makes the round trip. The helper text under the rate updated to 210.00 m²
> while the rent beside it still read 7,500, so the screen agreed with the database and both were
> wrong. Both pickers now call `deriveRentInto()`; the master one is the tooth a naive test cannot
> see, because filling the rate and the unit together lets the RATE field's hook do the work.
> (`AnExtraUnitIsPricedIntoTheRentAtCreationTest`, four teeth mutation-proved.)

> **⚠️ A RENEWAL is a re-negotiation: the deal wins and the rate follows it (EG-39, 2026-08-22).**
> `Lease::saving()` re-derives `base_rent_monthly` from rate × area on CREATE — and a renewal is a
> create — so renewing a 250 m² unit let at 4,800/m²/yr for a negotiated 110,000 used to save
> **100,000**, silently. `LeaseRenewalService` now derives the new `base_rent_rate_per_sqm_year`
> from the agreed rent (`deriveRateFromBaseRent()`, the inverse of its twin and living beside it),
> off the ORIGINAL's area because the renewal holds no units until `syncUnits()`.
>
> **Origination is unchanged** — on a new lease the rate still outranks a typed figure. Fixed in the
> service rather than the model because a disabled form field still posts a value, so "the caller
> stated a rent" cannot be told apart from "the form echoed one" on create. And the agreed figure is
> kept exact: a 2dp rate does not always round back to it (97,531.11 → 97,531.19), and the operator
> must see the number they negotiated.

> **⚠️ A deposit agreed as "three months' rent" now STAYS three months' rent (2026-08-17).**
> `security_deposit` is a flat figure and rent escalates. On a 7% clause a deposit agreed at 3×
> covers **2.62 months by year three and 2.29 by year five** — the landlord's security against a
> defaulting tenant erodes by nearly a quarter over a term, silently, and precisely as the tenant
> becomes more likely to default. Yardi tracks the requirement against rent; the Yardi gap analysis
> carried this as a 🟡 *"note only"*.
>
> `leases.security_deposit_months` records the negotiated MULTIPLE, and the deposit is derived from
> it in **`Lease::saving`** — beside the rate-priced rent derivation and for the same reason: the
> escalation sweep, the Change Rent action, a **renewal** (which copies `security_deposit` forward
> while setting a NEW rent — the same erosion, one renewal at a time), the importer and the API all
> write leases, and only one of them is a form. The form's amount field goes read-only once a
> multiple is stated, exactly as a rate-priced rent does.
>
> **Null means FLAT, and nothing moves.** A deposit agreed as a sum unrelated to rent is a real deal;
> inferring a multiple by dividing the deposit by the rent would invent a term nobody agreed to.
> Existing leases are all null, so nothing moved. Pinned by `DepositTracksRentTest`.
>
> **Not billed automatically, deliberately.** The top-up changes the CONTRACTUAL requirement; the
> money still moves through `deposit_transactions`, because a deposit is a liability (Dr Cash / Cr
> Deposits Held) and invoicing it as an ordinary charge would post it as revenue. The shortfall is
> already surfaced where it settles — `MoveOutStatementService` reports `deposit_shortfall`.

> **⚠️ NEW 2026-08-16 — "Billing forecast", the per-lease forward view.** A **tab beside the Charge
> schedule**: what this tenancy will be invoiced, period by period, for the next 24 months.
>
> **It is a relation manager with no relation, and that is supported rather than a hack.** Filament
> requires `$relationship` named to mount one, but `Table::records()` installs a data source and
> `Table::hasQuery()` is `! $dataSource` — so the named relation is never queried. Two defaults do
> have to be cleared: the base table wires `recordAction` and `recordUrl` closures typed
> `Model $record`, which exist to open the related record and fatal on the first render against
> computed rows. (Built as a modal action first, on the belief that a tab had to be a real relation.
> It doesn't, on v4.11.8 — and beside the schedule is where it belongs, because those two are exactly
> the pair people confuse.)
>
> **Why it had to exist.** Four screens described a lease's money and none answered this question.
> The **Charge schedule** holds the *rules* — one dated row per amount, because storing the months as
> well as the rule would store the same fact twice — and was therefore repeatedly read as a payment
> plan and found wanting ("why doesn't it show what's paid each month?"). **Rent Roll** is a snapshot
> of today. **Billing Run Preview** is one period across every lease. The **Invoices** tab is history.
> So *"what does this tenancy bill next year?"* — the question a negotiator, an operator and an owner
> all ask — had no screen.
>
> **It computes nothing of its own.** Every row is `MonthlyBillingService::planInvoiceForLease()`,
> the method the real run persists verbatim and the preview renders. A forecast with its own
> arithmetic diverges first on exactly the cases that matter — a proration edge, a cycle boundary, an
> escalation step — and does it silently. `LeaseBillingForecastService` only walks the calendar and
> asks.
>
> **Two actions on it, both scoped.** The invoice number on an already-raised period is a **link**
> to that invoice (drill-down on every number, the panel's standard), and the row that is genuinely
> due carries **Bill this period** — routed through `MonthlyBillingService::generateForLease()`, so
> it inherits the period lock and the already-billed probe rather than becoming a second billing
> path. It appears **only** where `App\Support\BillingWindow` allows: a button on every future row
> would let someone raise a receivable two years early from the one screen whose whole job is to
> look ahead. Gated in `visible()` and again with `abort_unless` in the closure, on
> `leases.generate_invoice`.
>
> Three readings worth knowing: a **quarterly** lease is grouped into cycles rather than listing the
> mid-cycle months as gaps in the tenant's obligations; a period **already invoiced** shows what it
> ACTUALLY billed and names the invoice (re-planning the past would report it at today's rent and
> read as a discrepancy that isn't one); and **truncated** means *the schedule continues past what is
> shown*, not *we hit the row cap* — a 60-month lease whose 24 rows exactly fill the 24-month horizon
> hits no cap and is still cut short by three years. Pinned by `LeaseBillingForecastTest`.

> **⚠️ A lease's future rent is now visible wherever it is claimed (2026-08-16).** `fixed_amount`
> was excluded from projection in **three places at once**, and the standard create form projected
> nothing at all — so an amount-escalating lease had its rent moved every anniversary by
> `RentEscalationService` with **nothing anywhere saying it would**. Pinned by
> `FutureRentIsVisibleTest`.
>
> - **`CreateLease` never projected.** `LeaseCreationService::create()` has always written the whole
>   ladder, but that service is reached only from the *Quick new lease* wizard on the list header;
>   the ordinary **New lease** page runs Eloquent directly and stopped at the three seeded rows. The
>   same deal produced a different lease depending on which button was pressed. It now projects.
> - **`projectTermEscalations()` refused `fixed_amount`.** "+EGP 4,000 a month each year" is an
>   ordinary anchor-tenant term and is exactly as knowable at signing as a percentage — unlike CPI,
>   which stays unprojected because there is no index feed. The step is sized as the sweep sizes it
>   (`rent + amount`, **no collar** — a bound in percent cannot clamp a step in pounds), which is what
>   keeps "a projected lease and a swept one converge on identical rows" true for amount leases too.
> - **`atriom:project-lease-schedules` filtered the same way**, so the backfill answered *"No active
>   leases with a contracted escalation"* about a portfolio of them — and printed an amount lease's
>   step as `0.00%`, which reads as *no increase* beside four projected steps.
> - **The Charge-schedule heading had four wrong readings**, all fixed by answering from the rent
>   schedule instead of the lease column: *"Billing now"* announced a rent on a lease that had not
>   commenced; *"next step"* called the lease's own **opening** rent row a step; the query carried
>   **no `type` filter**, so the row reported as a *rent* step could be the service charge or the
>   levy (whichever the tie broke to); and the unprojected-clause warning knew only `fixed_percent`,
>   so an amount lease was told **"no further steps scheduled"** — not a hedge but a false statement
>   about the contract.

> **⚠️ The lease form now says what it means (2026-08-16).** Five ways this screen could be filled
> in wrongly and report success. All pinned by `LeaseFormTightnessTest`.
>
> - **The double-booking guard had stopped guarding.** The `unit_id` rule that refuses a second
>   active lease was sitting on `unit_ownership_id`, where `$value` is an ownership id — so
>   `! $value` returned early on every ordinary lease and the closure could never fire. What kept it
>   looking present is the option query, which hides occupied units; **`show_occupied_units` widens
>   exactly that query**, and behind it there was nothing left. `CreateLease` does not run
>   `LeaseCreationService`, so the unit row-lock never saw it either — the standard form would mint a
>   SECOND active lease on a let unit. Reproduced before the fix ("Component has no errors"). The
>   lock in the service is the real guard for the *raced* case; this is the guard for the ordinary
>   one, and neither substitutes for the other.
> - **Escalation asks the TYPE first, and shows only that type's fields.** Visibility used to read
>   "not `fixed_amount`", so a lease declaring **`none`** still offered a rate box and a collar —
>   inputs an operator would fill in and nothing would ever read. `none` now shows nothing at all;
>   `fixed_percent`/`cpi` show the rate + collar; `fixed_amount` shows the amount alone (a bound
>   written in percent cannot clamp a step written in pounds, which is why `collar()` skips it).
> - **A hidden field must not keep a live value.** Filament does not dehydrate a hidden field, so
>   switching an existing lease to `none` LEFT its rate, amount and collar in the columns — invisible,
>   and read again the moment anyone switched the type back. `Lease::saving` clears them, in the
>   model rather than the form because the importer, the API and `LeaseRenewalService` never render a
>   field. **Clearing keys on the type; arming keys on the figure** — a `fixed_percent` stated at 0%
>   is a real clause with a zero step that the sweep deliberately keeps, so that it can roll the date
>   once a year instead of reconsidering it nightly. Clearing on "no figure" instead looked
>   equivalent and dropped those leases out of the sweep permanently.
> - **A clause recorded after signing now actually runs.** `next_escalation_date` was armed in
>   `creating` only, so adding an escalation to an EXISTING lease left it null and
>   `RentEscalationService`'s `whereNotNull` excluded that lease for the rest of its term — the same
>   dead feature the create-side fix was written for, one edit away.
> - **Percentage rent can no longer be configured to charge nothing, or everything.** The rate was
>   optional (toggle on, rate blank → an overage of 0.00 every month, reading as configured on every
>   screen); it is now required. And a **natural breakpoint with no base rent** is refused — the
>   breakpoint IS the base rent, so at zero the clause silently becomes "a percentage of every pound
>   of sales from the first one", which looks perfectly ordinary on the resulting invoice.
>
> **And what identifies a lease is chosen once (the Yardi rule).** `unit_id`, `tenant_id` and
> `unit_ownership_id` are locked on Edit: the master unit is the lease's identity, re-pointing the
> tenant would hand one retailer's billing history and deposit to another under the same contract
> reference, and both are separate commercial acts (a relocation, an assignment) rather than edits.
> Additional units stay editable — expanding and contracting the premises is ordinary.
> `commencement_date`, `rent_commencement_date` and `fit_out_scope` are free while the lease is still
> an agreement and **lock the moment it has been invoiced**, because from then on they are what those
> invoices were derived from. And `terminated` / `renewed` are no longer offered in the status select:
> they are outcomes of a service (deactivating the schedule, crediting unearned billing, cancelling
> open invoices, settling the deposit, writing the next lease), and typing one recorded the word while
> skipping every one of those acts.

> **⚠️ Exercising an option now writes the deal (2026-08-09, OP-04/OP-03).**
> `ExerciseLeaseOptionService` is the one path that resolves an option. It marks it, records a
> **lease event typed by what the option DOES** (a renewal EXTENDS, an expansion EXPANDS, a
> termination option TERMINATES — the timeline reads in deal terms, not option terms), and the
> renewal form pre-fills the contracted term, rent and commencement from it.
>
> - **The gap was never the data.** `LeaseOption::projectedRent()` had computed the contracted rent
>   since options shipped; the renewal form asked the operator to type one from scratch. A five-year
>   renewal at a contracted +10% typed as the old rent is a mis-priced tenancy nobody sees until the
>   next reconciliation.
> - **`market` and `cpi` pre-fill nothing** and the event records `rent_to_be_agreed`. A valuation
>   and an index feed are not numbers this system may invent — the same rule the escalation sweep
>   follows for CPI.
> - **The notice date is when notice was SERVED**, not when it was recorded. Refusing a late-recorded
>   notice would push the operator to falsify the date.
> - **Waive/lapse write no lease event**: nothing about the lease changed, and a timeline padded with
>   non-events is one people stop reading.
> - **An option is a RIGHT; an event is what happened — and `extension` belongs only to the second**
>   (2026-08-22). `EVENT_FOR` mapped an option type `extension` and `pendingRenewalTerms()` queried
>   for it, while `LeaseOption::TYPES` has never allowed the value and no
>   `admin.lease_options.types.extension` label exists — dead code a direct write was the only way to
>   reach. Removed rather than legalised: `renewal` already IS the right to extend, so a second code
>   for it would split option reporting across two values meaning one thing, and the picker would
>   have rendered a raw translation key. The trap is that `admin.leasing.lease_events.types` sits
>   directly above `admin.leasing.lease_options.types` and DOES contain `extension` — reading the
>   first while looking for the second is the same mistake that once gave `expense.category` the
>   retail list.
> - **Encumbrance warns, it does not block** (OP-03). `Unit::encumbrances()` / `isEncumbered()` feed
>   BOTH unit pickers — master and additional, because an expansion right is usually exercised over
>   the adjacent unit, which is what the second picker adds. `LeaseOption::encumbersUnit()` had
>   existed all along with **nothing in the codebase calling it**.

> **⚠️ A clause number outlived the type that owned it (2026-08-31).**
> Found by driving the edit modal. Change a co-tenancy clause to *Signage* and its 70% occupancy
> floor stayed on the row — the form had just HIDDEN that field, so it was never submitted, and the
> model kept what was already there. The result is a number no screen can show and no operator can
> correct, printed as `70.00%` beside the word *Signage* in the clause register. **A form hiding a
> field stops it being SUBMITTED; only the model can stop it being KEPT.**
>
> `LeaseClause::NUMBERS_BY_TYPE` is the one definition of which number each type may carry — the
> form's four `visible()` rules and the model's `saving()` hook both read it, so they cannot drift.
> Any save now corrects a stale row, so no backfill was needed. Mutation-proved in both directions:
> removing the guard and nulling everything each turn the tests red, and the second is the one that
> matters — a hook that emptied every number would satisfy the obvious test and quietly blank the
> register.
>
> **The same screen had a second copy of "which number is this".** The tab's Trigger column read
> `threshold_pct`, `threshold_amount` and `radius_km` and **omitted `notice_days`**, so an
> assignment clause — whose only number IS the notice period — showed a dash on the very screen it
> was entered on, while the register (written later) read all four. Both now call
> `LeaseClause::triggerLabel()`. **No demo clause exercised it**: every seeded row with a notice
> period also carried a percentage or an amount, so the dash looked correct. The seeder now carries
> one, because a fixture set that only shows the easy cases is how a column stays wrong.

> **⚠️ The clause abstract had no reader (2026-08-31).**
> `LeaseClause` was built so one question could be asked — its own docblock quotes it: *"nothing can
> even answer 'how many of our leases have a co-tenancy trigger tied to the anchor we are about to
> lose?'"* — and `contingentMoney()`, `inForceOn()` and `liveExposure()` were written to answer it.
> **`liveExposure()` had no caller anywhere in `app/`, only tests.** Fully built, fully tested and
> unreachable: the shape this repo names for the four orphaned services found in August, where the
> green test file is exactly what makes it look maintained. Ninety-nine clauses sat on the demo
> books readable one lease at a time; no report, no search index, no alert.
>
> `App\Filament\Admin\Pages\ClauseRegister` is that caller — the leasing counterpart of the rent
> roll, in the same navigation group and the same report category.
>
> - **The exposure count is `liveExposure()` verbatim**, never a count reassembled from the table's
>   filters. That scope bundles three conditions on purpose, and a second assembly of them here
>   would be free to drift back into the bug its docblock records: an open-ended co-tenancy clause
>   reads as in force for ever, so a TERMINATED tenancy was once reported as exposed.
> - **Query-backed, not a collection.** The filters narrow real SQL, so they page correctly, are
>   remembered by `TableDefaults` and render as clearable chips. Property isolation is the model's
>   own `#[PropertyOwned(via: 'lease.unit')]` — two hops, and `currentAssetId()` alone would return
>   the portfolio to a restricted operator in All-Properties mode.
> - **The trigger column reads whichever of four columns the type uses** — a percentage for
>   co-tenancy, a sales figure for kick-out, kilometres for radius, notice days for assignment.
>   Showing `threshold_pct` alone prints a blank cell for three types out of four.
> - **The shared report shell now renders its filter strip only if the page declares one**, by the
>   same `method_exists` idiom its stats and unallocated-notice blocks already used. A report whose
>   filters belong on the table declared none, and the unconditional render was a 500 on the page.
> - Counted through `trans_choice`, not `lease(s)`: Arabic 11–99 takes a singular accusative noun,
>   so a bare `:count عقد` is wrong for every number this line realistically shows.

> **⚠️ A refusal and a success cannot both be true of one click (2026-08-31).**
> Reported from the panel: exercising an option on a notice date outside its window produced BOTH
> *"Notice was served on 01/06/2026, outside this option's window…"* and *"Option marked exercised."*
> Only the first was true.
>
> - **A `catch` that shows a message and returns normally is a `catch` that reports success.** The
>   action caught the service's refusal, sent a danger notification and let the closure return, so
>   Filament sent the chain's `successNotificationTitle()` straight afterwards. A success toast is
>   what an operator files the day's work by; one that fires over a refusal is worse than silence.
>   A sweep of every `->action()` in the panel carrying `successNotification` found exactly one
>   such swallow — this one.
> - **The window is refused ON THE FIELD**, not in the action, so the modal stays open with the date
>   still in it and the operator fixes the day rather than re-typing the reason and the document
>   reference. The service guard stays as the backstop: a field's value still arrives in the
>   Livewire payload whatever the form did.
> - **`DomainException`, not `InvalidArgumentException`.** An operator exercising outside the window,
>   or re-clicking Exercise on an option somebody already resolved, is doing something the lease does
>   not permit — not tripping a developer error. `bootstrap/app.php` renders the first as a readable
>   message and the second as a 500, and `RefusalsAreTranslatedConformanceTest` deliberately sweeps
>   only the first. The already-resolved refusal was also raw English printing a raw column value
>   (*"This option is already 'lapsed'"*), so it now reads through `admin.lease_options.statuses.*`.
> - Mutation-proved both ways: neutering the field rule, and restoring the swallowing catch, each
>   turn `AnOptionIsExercisedInsideItsNoticeWindowTest` red. Verified in a browser afterwards —
>   refusal alone with the modal open, success alone on a valid date.

> **⚠️ A lease event's REASON is a key, resolved at read time (2026-08-30).**
> `App\Support\LeaseEventNarrative` is the lease timeline's twin of `JournalNarrative`, under the
> rule both obey: **a row stores DATA, never PROSE.** Ten services composed their sentence through
> `__()` at WRITE time and stored the result, so a timeline row was frozen in whichever language that
> particular run happened to be in — measured on the demo books, **8 of 9 stored reasons were English**
> and the ninth was Arabic only because that one run was. An operator reading the lease history in
> Arabic saw English, and no lang edit could ever reach a row already written.
>
> - **The `reason` COLUMN stays and an operator's own words WIN.** It is now nullable, so an event
>   nobody explained stores no prose at all. But a service stamps a key on EVERY event it writes,
>   including the ones a human typed a reason for — so a resolver that tests the key first throws
>   away the only part of the row carrying the WHY, which is what it did until it was measured: a
>   relief explained as *"Trading concession while the north entrance is closed for works"* rendered
>   as the generic *"Rent relief granted — 54,000.00 reduced to 40,500.00"*, which the figures in
>   the same table already said. The composed sentence is the fallback for a row with NO words,
>   never a replacement for one that has them. Every pre-existing row keeps its frozen sentence, because `LeaseEvent` refuses updates
>   by design; a blank timeline cell would be worse than a stale one.
> - **`RecordLeaseEventService` accepts EITHER**, and refuses neither-nor: the *"a lease event needs a
>   reason — that is the point of recording it"* guard now passes when a narrative key is stamped, so
>   the check still bites on an event that explains nothing.
> - **The same three traps this codebase has hit before.** `__()` reads dots as NESTING (the
>   narratives are nested under `admin.leasing.lease_events.narratives`, not keyed by a literal
>   `option.exercised`); a missing placeholder must render an em dash rather than a leftover
>   `:notice_given_at` on a lease document; and `Lang::has()` FALLS BACK to English, so the parity
>   check passes `fallback: false` or it only ever catches keys missing from both.
> - **The gate reads the reason ARGUMENT, not the file.** `SettleMoveOutService` legitimately calls
>   `__()` several times for refusals and transaction notes — those are correct at write time — so the
>   sweep is windowed to the argument that becomes `lease_events.reason`.
> - **Its first version was too weak in two ways, and both were found from the DATA, not the gate.**
>   It matched `__(` only, so `RentEscalationService`'s **raw English** — `Automatic rent escalation
>   +10%`, never translatable at all, the worse half of the defect — walked straight past it; and it
>   swept only the files that NAME `RecordLeaseEventService`, which the escalation sweep does not (it
>   goes through `LeaseRentChangeService`). The call graph is now DERIVED and followed **one hop**.
>   The same pass found the LAST survivor of the superseded notes-append: `LeaseTerminationService`
>   still wrote `Terminated on 2026-08-30: …` into `leases.notes` — frozen English, read by nothing,
>   duplicating the event beside it — which is exactly what LE-01 recorded as replaced.
> - **A CLASSIFICATION token inside the sentence resolves in the READER's language too.** Found on
>   the screen, not by a test: with the panel in Arabic, an English read of the same row came back
>   `خيار التجديد exercised — notice served 30/07/2026` — one sentence in two languages, because
>   `tokens()` resolved `option_type` through a bare `trans()` that answers in whatever session is
>   running. The same half-translated shape `DocumentLocale::in()` exists to prevent on the PDFs:
>   wrap the DATA, not just the template, or you get an Arabic body under English headings.
> - **A narrative nothing writes is a sentence nobody reads.** `rent_escalated` was catalogued in
>   both languages while the sweep stored English beside it, so the vocabulary looked complete and
>   the timeline was not; the gate now requires a writer for every key. Mutation-proved four ways:
>   dropping an Arabic narrative, re-composing prose, reverting the escalation to raw English, and
>   adding an unwritten key each turn it red. (`LeaseEventNarrativeIsAKeyNotProseTest`.)

> **⚠️ A field offering values its column refuses reads as a button that does nothing (2026-08-18).**
> The lease's "Record deposit movement" modal took its METHOD options from `admin.enums.method` — the
> PAYMENT methods (card, bank_transfer, instapay, wallet, cheque) — while
> `deposit_transactions.method` accepts exactly `cash` and `bank`, and the field defaulted to
> `bank_transfer`. Every submission threw at the `ValueSets` listener **after** validation passed, so
> the operator pressed Save and nothing happened; the reason was in the log and nowhere else.
>
> The fix is structural, not a corrected literal: `DepositTransaction::methodOptions()` DERIVES the
> labelled set from `ValueSets::allowed()`, so no surface can offer a value the column refuses and a
> new method reaches every screen at once. Both surfaces read it — the deposit resource's own form
> had the right two values by hand, which is the drift: two surfaces choosing their own list for one
> column, and only one of them wrong.
>
> `LeaseDepositActionActuallySavesTest` DRIVES the action rather than inspecting it, and its first
> version was green over the bug because it passed `method` explicitly and never exercised the
> default — a gate checking a weaker property than its name. It now asserts the derivation and sweeps
> both surfaces, and is proven by reinstating the original literal.

> **⚠️ Straight-line rent had no screen at all (fixed 2026-08-18).** `StraightLineRentAdjustment` is
> a registered GL posting source with its own journalizer and a scheduled command — and it appeared
> on **no screen in the panel**. A lease's straight-line position, the first thing an owner's
> accountant asks about, was reachable only by running a CLI. Found by sweeping the lease page for
> unreachable functionality, not by a failing test: nothing was red, because nothing was wrong —
> only invisible.
>
> `LeaseStraightLineRelationManager` shows the schedule above the rows (recognised per month, total,
> term) and then billed / recognised / adjustment per period, with the cumulative sum — which must
> unwind to **zero** by expiry, the property an accountant checks. Read-only: an adjustment is POSTED
> by `accounting:post-straight-line-rent` from the schedule and the month's billing, so a create
> button would be a second way to state a number the engine already computes.
>
> Shown only when `BillingSettings::straight_line_rent_enabled` is ON **and** the lease can actually
> be averaged — it needs a term and a `base_rent` ladder, and averaging a term whose end is unknown
> is worse than recognising nothing. The setting ships **off**.

> **⚠️ A security deposit is now a CHARGE on the tenant ledger — Voyager's model (2026-08-18).**
> Previously a deposit existed only as a `DepositTransaction` an operator recorded AFTER money
> arrived, so **no document ever asked the tenant for it** and the portal had to tell them to make a
> bank transfer quoting a reference. That was the root cause behind "the client doesn't know how he
> should pay": there was nothing to pay.
>
> `BillSecurityDepositService` raises it as an ordinary invoice line (`security_deposit`), so it
> ages, reaches the statement and the collections screen, and can be paid by card on the same rail
> as rent. **The GL is what makes it a deposit rather than income** — that charge code's posting role
> is `deposits_held`, a LIABILITY, and it is the only non-revenue entry in
> `InvoiceJournalizer::REVENUE_ROLE`:
>
> ```
> billing   Dr Tenant Receivables   Cr Tenant Deposits Held
> payment   Dr Bank                 Cr Tenant Receivables
> ─────────────────────────────────────────────────────────
> net       Dr Bank                 Cr Tenant Deposits Held   ← what a direct receipt posts
> ```
>
> So there is no double count and no second billing path: the invoice journalizer already credits
> whatever role a line's charge code names, which is why this needed one map entry rather than a new
> posting route.
>
> Three rules that are easy to get wrong and are pinned by `DepositIsABillableChargeTest`:
> **it bills the SHORTFALL, never the contractual figure** (billing 144,000 to a tenant who paid
> 100,000 is how a landlord holds — and owes back — twice the deposit); **an UNPAID deposit invoice
> is not held** (it is a receivable, and counting it would refund at move-out what was never
> received, so `Lease::depositHeld()` reads the line's SETTLEMENT via `InvoiceItemSettlement`); and
> it carries **no VAT** — a deposit is a security, not a supply, so taxing it would charge VAT on the
> landlord's own liability. The period is dated to the lease TERM, which keeps it out of any month's
> revenue reading and stops the trailing-proration and unearned-credit rules — both keyed on the
> period — treating it as time-apportioned rent to claw back on termination.
>
> The direct-receipt path is unchanged and still posts its own entry: both rails feed one
> `depositHeld()`.

> **⚠️ The pot had no locking definition, so a move-out could disburse it twice (fixed 2026-09-01).**
> `SettleMoveOutService::settle()` took **no lock of any kind**, and unlike the unit double-booking
> no UNIQUE index turns the race into a duplicate-key error: `deposit_transactions.number` is unique,
> but the two writers are handed different numbers, so nothing constrains THE POT. Two settlements on
> one move-out each read the whole pot and each wrote a refund for it: the deposit disbursed twice,
> `depositHeld()` negative by its full value, and `deposits_held` in the GL saying a tenant who has
> left owes the landlord money.
>
> **`Lease::depositHeldForUpdate()` is the locking twin** — the pattern `Unit::isActivelyLeased()` /
> `isActivelyLeasedForUpdate()` established for exactly this. The plain twin stays: the leases list,
> the lease page and the portal infolist read it on render, and taking row locks per row per page is
> a cost with no writer waiting on it. A lock serialises writers; it does not make the guard behind
> it SEE them — under REPEATABLE READ a plain read is answered from the snapshot taken before the
> wait, which is why a locked settlement still needs a locking READ.
>
> **The LEASE is the contended row**, because the pot is one per lease and spans three tables
> (recorded movements, deposit applications, and the settled part of any billed deposit). Locking any
> one of them leaves the other two free to move — which is why `ApplyDepositToInvoiceService` locking
> the INVOICE was never a guard for this: two applications against two *different* invoices of one
> lease locked two different rows and were not serialised at all. It now takes the lease first.
>
> **Lock order is load-bearing: leases → invoices → deposit_transactions → deposit_applications.**
> `Payment`'s two over-allocation guards lock invoices and then the deposit applications for those
> invoices, so taking them the other way round here would deadlock an ordinary receipt against a
> move-out. Nothing in `app/` locks an invoice and then a lease, so putting the lease at the head
> introduces no cycle — verified across every file in `ConcurrencyPolicy::expected()` that takes
> more than one lock (28 of the 81 registered).
>
> The twin re-derives the pot with its own queries rather than reusing the display path, because the
> locks must be **literal in the method**: `ConcurrencyPolicyConformanceTest`'s `AUTHORITATIVE_GUARDS`
> check reads that method's own body, so a twin that delegated its locking would pass review and fail
> the gate. That makes "do the two agree" a real question, so
> `DepositHeldIsTheSameFigureLoadedOrNotTest` now asks it of all three paths across all three terms.
> (`AMoveOutCannotDisburseTheDepositTwiceTest` proves which table was locked via `LockSpy`;
> `tests/Mysql/DepositPotSerialisesTest` proves two connections actually serialise, which SQLite
> cannot show because it compiles `lockForUpdate()` to nothing.)
>
> **The pot has FIVE doors and the sweep that found this one was looking for services that locked
> NOTHING.** The other four: `ApplyDepositToInvoiceService` (above), the **Record deposit movement**
> action — a cap read from the display twin outside any transaction — `BillSecurityDepositService`,
> which locked the lease and then asked `depositUnbilledShortfall()` beneath a comment stating that
> the lock made it a check-then-act guard. It does not, and a comment asserting a safety property
> that does not hold is worse than none: two operators each read the same unbilled shortfall and each
> raise an invoice, so the tenant is asked for **twice the security they agreed** and `deposits_held`
> is credited twice the day they pay. `depositUnbilledShortfallForUpdate()`,
> `depositShortfallForUpdate()` and `depositBilledOutstandingForUpdate()` mirror the display trio
> one-for-one, so a refusal MESSAGE quotes the figures its DECISION was made from — *"you have
> already billed 0.00"* is a worse refusal than none.
>
> …and **the deposit register's own Create and Edit pages, which had no cap at all** — the fifth door,
> and the only one that never had even the check-then-act version. `DepositTransactionForm` caps
> `amount` at `minValue(0.01)` and nothing else, `ListDepositTransactions` mounts a plain
> `CreateAction`, and both are gated on `deposit_transactions.create` / `.edit` — the SAME permissions
> the lease action gates on. So the operator refused a 500,000 refund against a 100,000 pot on the
> lease page could open the register and save it, and edit it freely afterwards, because the receipt
> freeze fires only for `type === 'receipt'`. **The cap now lives on the MODEL** (`DepositTransaction::saving`),
> for the reason `ValueSets::guard()` is one wildcard listener rather than a trait on 39 models: a
> sixth door is covered by existing rather than by being remembered.
>
> It measures against the pot **less this row's own persisted contribution**
> (`potContributionAsPersisted()`, read from `getOriginal()`). Without that subtraction, correcting a
> recorded 30,000 refund up to 90,000 is measured against the 70,000 left *after itself* and a
> legitimate restatement is refused — the worse direction, because the row is lost and it reads as a
> bug rather than a rule. A draft contributes nothing, so a draft becoming recorded correctly gets no
> add-back. The read is deliberately PLAIN: the hook also fires inside `SettleMoveOutService`, which
> has already pinned all three tables under the lease lock, and a row lock taken from a model hook
> that often runs with no transaction around it is released on the next statement — worse than none,
> because it reads as protection. The AUTHORITATIVE guard is the caller's; this is the backstop that
> makes the invariant true of the ROW rather than of one screen.
>
> **Enumerate the doors onto a pot by grepping the pot, never from the diff that fixed one of them.**

> **⚠️ …but the REGISTER only ever read one rail (fixed 2026-08-18).** Reported from the field:
> *"I paid the security deposit invoice and no security deposit record is done."* Correct, and the
> money was never the problem — the cycle ties out exactly (`Dr AR / Cr Deposits Held` on issue,
> `Dr Bank / Cr AR` on payment, `depositHeld()` derives the settlement, shortfall goes to zero, and
> both refund and move-out netting read the derived figure). What was wrong is that
> `deposit_transactions` is the only thing the deposit register and the lease's Deposits tab read,
> and **the billing rail writes no row there.** On the reporter's data the register showed
> **390,000** against a `deposits_held` liability of **534,000** — the operator's one screen for
> "what do we owe back?" understating the obligation by exactly the deposit just collected, on what
> is now the recommended rail. Nothing reconciled the two, so nothing would ever have said so.
>
> **The fix DERIVES; it does not write the missing row.** Writing a `DepositTransaction` on
> settlement is the intuitive answer and wrong twice: the invoice has already credited
> `deposits_held`, so a receipt row posts the liability a second time; and settlement is not a
> one-shot event — a part payment, a credit note, a void or a write-off all move it — so the row
> would be a stored copy of a moving number, needing permanent reconciliation against the thing it
> was copied from. That is the second-truth-about-the-same-money the AR invariants forbid, and the
> same reason `InvoiceItemSettlement` never stores a per-line balance.
>
> `App\Support\DepositHoldings` is the one aggregate definition (`Lease::depositHeld()` remains the
> per-lease one) and three surfaces read it: the **register header** states both rails and checks
> itself against the ledger; the **lease Deposits tab** carries an agreed/held/billed/shortfall
> summary and a distinct empty state, because an empty table on a lease holding 144,000 read as
> "they never paid"; and **`billing:reconcile` gained a `deposits_tie_out` check**, so the two can
> never drift apart silently again. `glBalance()` sums `JournalEntry::REPORTABLE_STATUSES`, not
> `posted` — voiding posts a sign-flipped reversal and marks the original `void`, and
> `LedgerPoster::sync()` voids on every re-derive, so a `posted`-only filter would make every
> re-derived deposit invoice read as a NEGATIVE liability.
>
> Tests: `DepositsCollectedByBillingAreVisibleTest` — pins that no movement row is written (so a
> later "fix" that inserts one fails here), that the aggregate counts both rails and is
> property-scoped, that the tie-out holds through the REAL `accounting:sync-ledger` sweep, and that
> the reconcile check FAILS on a deliberately unposted receipt. An unmapped chart reports no
> discrepancy rather than failing every fresh install.

> **⚠️ A DEPOSIT IS HELD FOR MONEY RECEIVED — NOT FOR AN INVOICE STATUS (fixed 2026-09-02).**
> The sentence above — *"the two can never drift apart silently again"* — was not yet true, and the
> reason is worth the space.
>
> Both halves of the billed-deposit question (*what is held?* / *what is still asked for?*) were
> answered from `invoices.status`, through a list of three strings written out **four times**: as a
> constant on `Lease`, and as two bare literals inside `DepositHoldings`. That is the drift the
> constant's own docblock warned about, naming only two of the four places it was written — and it
> drifted: with a deposit invoice written off after part payment, the lease page read *"held
> 60,000"* while the register header beside it read **0.00** under a red GL-gap stat.
>
> Worse, a status is a coarse proxy for what is an **amount** question. Every figure underneath comes
> from `InvoiceItemSettlement`, which derives per-line numbers from `paid_amount`, so the status list
> caught only the terminal cases and missed every partial one — three defects, all money:
>
> | | before | now |
> |---|---|---|
> | Deposit 100,000, paid 60,000, **remaining 40,000 written off** | invoice became `written_off`, dropped out entirely, pot read **0.00** — the move-out refunded nothing and the mall kept 60,000 | held 60,000 |
> | Deposit 100,000, paid 60,000, **40,000 credited by note** | invoice stayed `paid` (never `credited`), pot read the full **100,000** — the move-out refunded 40,000 that never arrived, outbound, with no recovery path | held 60,000, shortfall 40,000 |
> | Deposit 100,000, paid 60,000, **10,000 written off**, collectable 30,000 paid | the forgiven 10,000 went on counting as *already asked for*; the **Bill deposit** button stayed hidden and the service refused with *"already billed 10,000.00"* — quoting money the operator had forgiven, with no path to ask again | claimed 0, unbilled shortfall 10,000 — re-billable |
>
> **`App\Support\DepositBilling` is now the one seam** and both questions are answered from amounts.
> `heldOn()` is the deposit lines' settlement less credit-note relief; `claimedOn()` is their
> outstanding less any write-off that reaches them. The status list shrinks to the two that record
> neither a receipt nor a claim — `cancelled` (load-bearing: `recomputeTotals()` zeroes a cancelled
> `paid_amount`, so its deposit line would read fully OUTSTANDING and count as a live claim) and
> `credited` (belt-and-braces for imported rows); the claim question adds `draft`. `written_off` is
> deliberately in neither.
>
> **Attribution leans the safe way, and that is not the same way for both.** Credit relief comes off
> the deposit line **first** — understating the holding shows as a shortfall an operator can see and
> chase, overstating it refunds cash that never arrived, which is the choice
> `InvoiceItemSettlement::TYPE_PRIORITY` already states for the deposit's position in the queue. A
> write-off comes off the deposit line **last**, reaching it only once every other outstanding line
> is exhausted, because understating the claim would let the deposit be billed a second time.
>
> The precedent was already in the repo and had not been followed here:
> `BooksReconciliationService::arDiscrepancies()` added an explicit `InvoiceWriteOff` term for the
> identical reason — *"the exclusion above only rescues invoices written off in FULL; every partial
> one showed as an AR delta from the day it was booked, permanently, with no way to clear it."*
>
> Tests: `ADepositIsHeldOnlyForMoneyReceivedTest` — all three defects, both the loaded and unloaded
> read paths, the locking twin, **and** the aggregate against the lease (the tooth whose absence let
> the drift through). Mutation-proved four ways.
>
> **Still open (SW-201): `deposits_tie_out` is red for any written-off deposit invoice.**
> `InvoiceWriteOffJournalizer` posts `Dr bad_debt_expense / Cr accounts_receivable` whatever the line
> was — but a `security_deposit` line credited `deposits_held`, a LIABILITY, at issue, not revenue.
> So writing one off books a bad-debt expense against revenue never recognised and leaves the refund
> obligation standing at its full billed figure. That is an accounting decision for the operator's
> accountant (relieve `deposits_held` for the deposit portion, or keep counting a written-off deposit
> line as in flight), not a code choice, and it predates this fix.

> **⚠️ The deposit was invisible on both sides (fixed 2026-08-18).** Raised by an operator: *"the
> client doesn't know how he should pay, and the admin doesn't know how much the lease wants or the
> shortfall."* Three separate causes:
>
> - **`leases.security_deposit_received` was a SECOND TRUTH** — a form toggle, defaulted false at
>   creation, that **nothing ever synced** from the deposit register. A lease with 240,000 recorded
>   still read "not received", and an operator could tick it on a lease where nothing arrived. A
>   boolean cannot express a PARTLY collected deposit at all, which is the ordinary case: 150,000
>   held against a contractual 180,000 is neither true nor false. **Column dropped**
>   (`2026_08_18_090000`); the register is the answer.
> - **The lease LIST showed no deposit at all.** "Who still owes me a deposit?" meant opening every
>   lease in turn. There is now a **Deposit due** column (agreed − held, with the subtraction shown
>   underneath so it is never a figure to take on trust) and a **Deposit outstanding** filter. On the
>   seeded portfolio it immediately found an active lease trading since January with 144,000 agreed
>   and nothing ever collected.
> - **The tenant PORTAL showed the contracted figure alone** — not what they had paid, not what was
>   outstanding, no instruction. It now shows agreed / paid / outstanding, and — only when something
>   IS outstanding — how to pay it. That line matters because **a deposit is never invoiced**, so
>   nothing else in the portal will ever ask them for it.
>
> `Lease::depositHeld()` / `depositShortfall()` are the ONE definition (receipts − refunds − forfeits
> − `DepositApplication`s, recorded only); `MoveOutStatementService::depositHeld()` delegates to it
> rather than keeping its own copy, so the final account and the list cannot disagree about the same
> money. `DepositExposureIsVisibleTest`.
>
> **Still open, and it is the root cause:** there is no deposit CHARGE CODE, so a deposit can never
> appear on an invoice. Yardi posts a deposit as a charge on the tenant ledger (Dr AR / Cr Deposits
> Held) and the tenant pays it like any bill. Here it exists only as a `DepositTransaction` an
> operator records after the money arrives — which is why nothing ever asks the tenant to pay.

> **⚠️ Termination now settles money, not just status (2026-08-09, phase 4).** Terminating a lease
> **credits back the unearned part of any invoice already billed past the termination date**
> (`CreditUnearnedBillingService`, story MF-02) — rent bills in advance, so a tenant leaving on the
> 18th has already been invoiced for the whole month. It is opt-OUT (`credit_unearned`, default
> true); the flag exists because the note posts on the termination date and a CLOSED period refuses
> it. One-off lines are never clawed back — a utility recharge or a fine is earned for something
> that already happened.
>
> **Two defects made that unreachable in practice, both fixed 2026-08-17.** The toggle described
> above **had no screen**: `terminate()` read `credit_unearned`, and the modal offered only date,
> reason and "cancel open invoices" — so the documented opt-out could not be exercised, and the
> default was invisible rather than merely on. Worse, `cancel_open_invoices` filtered on **balance
> alone**, cancelling every fully-unpaid invoice on the lease regardless of the period it covered.
> On a system that bills in advance that is a money defect twice over: it wipes revenue already
> earned (a quarterly lease terminating mid-quarter lost the whole quarter, and the two percentage-
> rent invoices for months entirely in the past — 463,260 in the reproduction), and it deletes the
> very document step 5 was going to credit, so **no credit note is produced and none is missing
> from anywhere an operator would look**. Cancellation is now scoped to invoices whose period
> starts *after* the termination; a straddling invoice is left for the credit.
>
> **The move-out final account** (`MoveOutStatementService` + `SettleMoveOutService`, story MF-03)
> is the document that settles the tenancy: deposit held vs contractual, open AR, credit owed back,
> itemised deductions, and the true-ups that are **not knowable yet** (an unreconciled CAM year,
> missing sales declarations). Settling disposes of the deposit in one act and **freezes the
> statement as the termination event's payload** — re-deriving it a year later would show today's
> numbers, not the ones that were signed. Settlement follows Yardi's order (S8): **arrears are netted
> off the deposit first** (`ApplyDepositToInvoiceService`, Dr Deposits Held / Cr AR — the FOURTH
> channel into `Invoice::recomputeTotals()`), then the operator's deductions are forfeited, then the
> remainder is refunded. Arrears go first because an unpaid rent invoice is a real document that may
> already have reached the tax authority, while a deduction is an assessment made at settlement.
>
> **A FORECAST HAS TO AGREE WITH THE ACT IT FORECASTS (SW-031, 2026-09-03).** The statement's own
> docblock promises *"the net position it reports is the one the settlement carries out"* — and its
> open-invoice query was a hand-kept `issued|partially_paid|overdue` while the settlement narrows
> with `->acceptingSettlement()`, the `InvoiceSettlement` register, in which **`disputed` is
> classified LIVE**. So on a 540,000 deposit with a 50,000 disputed invoice the statement said the
> tenant was owed 540,000 and the Settle button beside it deducted the 50,000 and refunded 490,000 —
> and those figures are FROZEN onto the immutable termination event, so the disagreement is signed.
>
> Note the direction, because the finding that opened this had it backwards: the statement
> **understated** the deduction. It was never refunding a deposit in full over the operator's claim,
> and a first fix that printed *"under dispute (claimed, not deducted)"* beside an amount the next
> button deducts was worse than the bug — a false statement on a document the tenant signs. Both
> sides read the one register now.
>
> **What is being ARGUED about is a separate figure, from the ITEM flag, shown BESIDE the total** —
> the position MF-07 shipped for AR aging on 2026-08-09 (*"the disputed figure sits BESIDE the aged
> one rather than being netted out of it: deducting it would understate what the mall is owed"*, and
> *"an invoice is rarely disputed in full … the flag belongs on the line"*). Reading
> `invoices.status` instead labels the whole document: measured, a 50,000 invoice with only its
> 20,000 service line flagged reported all 50,000 as under dispute. `chargeableBalance()` is
> `collectableBalance()` less exactly this figure, so the two are one definition read from both ends.
>
> **ON-ACCOUNT CREDIT IS STATED, NEVER NETTED (SW-032).** `Tenant::creditBalance()` — money the
> tenant paid and never used — was omitted entirely, which is one of the pots this document promises.
> It is scoped to the lease's property (`$lease->unit?->asset_id`; a lease carries no `asset_id`) and
> it is **not a term in `net_to_tenant`**, because `SettleMoveOutService` never calls
> `ApplyTenantCreditService`: netting it forecasts an act the settlement does not perform, and the
> frozen event then fails to add up from its own keys. Measured with 100,000 held, 250,000 of arrears
> and 60,000 on account, the signed document said the tenant owed **90,000** where the ledger said
> **150,000**. A receipt naming no property at all is honestly 0 here — `payments` has no `asset_id`
> column, so an unallocated bank transfer has neither an allocation nor a cheque to take one from —
> and it stays visible in the tenant's whole-company balance.
>
> **The modal body lives in `LeaseActions::finalAccountSummary()` so it can be tested.** There is no
> final-account PDF, so that modal is the entire operator-facing surface of the statement — and a
> `Placeholder`'s `content()` closure is evaluated nowhere a test can reach (Filament renders a
> mounted action's schema in a later pass, so the component's own HTML does not contain it). A
> mutation deleting both new rows from it left the whole suite green.
>
> **The whole final account is ONE transaction (fixed 2026-08-11).** Arrears settlement used to run
> before and outside it, so a settlement that then failed — most reachably on "the deductions exceed
> the deposit held" — left the deposit already spent against the tenant's invoices while the operator
> saw an error and reasonably concluded that nothing had happened. A final account commits whole or
> not at all.
>
> **And `settlement_date` is a posting date.** `PostingDateGuards` used to exempt `DepositApplication`
> as `system:` — *"stamped at application time, not operator-typable"* — which was simply untrue:
> `ApplyDepositToInvoiceService` stamps a parameter, and this service passes the operator's
> `settlement_date` off an unconstrained DatePicker. Back-dating a settlement into a closed March
> netted 120,000 off the deposit, closed the AR, reported success — and the post was refused inside
> the best-effort sync job, leaving a tie-out gap of exactly that much. The guard now lives in
> `ApplyDepositToInvoiceService` (the service that stamps the date), and the registry names it.
> **A `system:` exemption asserting a property that does not hold is worse than a missing entry:
> the gate reports coverage.**
>
> **Late-fee terms are per-lease** (`Lease::lateFeeTerms()`, story MF-08), falling back to
> `BillingSettings` — **not** `config('billing.*')`, which the service used to read while the admin
> Settings screen wrote the settings record, making every saved late-fee value inert.

> **A RENEWAL CARRIES EVERY NEGOTIATED TERM — and it is derived, not enumerated (2026-08-12).**
> `LeaseRenewalService` built its payload from a literal array written when `leases` had ~24
> columns. The table now has 43, and **14 were silently dropped on every renewal.** None errored.
>
> The worst was invisible rather than wrong: `escalation_type` carried and `escalation_amount` did
> not, so `Lease::creating` computed `configured = false`, `next_escalation_date` stayed null, and
> `RentEscalationService`'s `whereNotNull` excluded the lease **for its entire term** — a
> compounding revenue leak that looks exactly like a lease with no escalation clause. Also lost: the
> escalation collar, `rent_pricing_basis` (so a rate-priced lease renewed flat and a later expansion
> changed no rent at all), the per-lease late-fee terms, the %-rent deduction clause, the holdover
> uplift.
>
> **And three child collections were never copied at all** — the service contained no mention of
> them. The CAM cap (`camTermFor()` queries the NEW lease id, finds nothing, and the tenant gets an
> **uncapped year-end true-up on a capped lease** — with the renewal's CAM panel simply empty, so
> nobody can see the cap was lost). The percentage-rent ladder (`has_percentage_rent` and the
> `tiered` type DO carry, so the lease reads as configured while the overage is **0.00 every
> month**). And the `lease_rentable_item` pivot — parking, storage and signage, unbilled.
>
> The payload is now **`$fillable` minus `Lease::RENEWAL_RESETS`**, so a new lease column is carried
> by default and dropping one is a decision written down with its reason. That is the fix: the
> enumeration was the bug, not any particular missing line. `LeaseRenewalCarriesTermsTest` proves
> each dropped term and fails on a stale reset entry; reverting to the old array reproduces all five
> header losses and all three child losses.
>
> One distinction the reset list exists to make: **`holdover_rate_pct` carries** (a negotiated
> uplift) while **`holdover_from` does not** (a state the ORIGINAL entered by running past expiry).
> Likewise the rentable-item pivot carries its rate but not its `effective_to` — a renewal
> inheriting a window that has already closed would silently stop billing the bay.

> **⚠️ Every commercial change is an EVENT now (2026-08-09, phase 2).** Phase 1 gave the rent a
> schedule, so the system could answer *what* it was and *when* it changed. It still could not
> answer **why** — a negotiated reduction, an expansion and a typo were all just rows with dates,
> and the only trace of intent was a sentence appended to `leases.notes`.
>
> [`LeaseEvent`](../../app/Models/LeaseEvent.php) is the append-only record: *type · effective date ·
> reason · actor · document reference · payload*. [`RecordLeaseEventService`](../../app/Services/RecordLeaseEventService.php)
> is the one writer, and it is called **inside** each change's transaction so a change and its
> history commit or fail together. What you must know:
>
> - **Events are immutable, both ways.** `updating` and `deleting` are refused at the model. An
>   editable audit record is not an audit record; correct a mistake by recording the correcting
>   event, the same discipline as void / credit-note on the money records.
> - **The actor comes from the session, never from a caller.** A sweep under `artisan` has no
>   authenticated user, so the timeline says "System" — which is true. Letting callers pass an actor
>   would eventually put a human's name against an automated escalation.
> - **The `leases.notes` append is gone.** `notes` is the operator's own field again.
> - **Four services record events**: `LeaseRentChangeService` (rent_modification),
>   `LeaseReliefService` (abatement), `ConvertLeaseToHoldoverService` (holdover),
>   `LeaseSpaceChangeService` (expansion / contraction). A new commercial change should record one
>   too — that is what makes the timeline complete rather than decorative.
> - **Relief is bounded and reverts by itself.** `ChargeScheduleService::overlayWindow()` trims the
>   underlying rows around the window instead of replacing them, so a relief spanning a contracted
>   step produces one relief row per segment and resumes at the **post-step** amount. Contracted
>   `base_rent_monthly` does NOT move (a concession is not a renegotiation) and the marketing levy
>   does not follow it — unlike a rent change, where both do. **The window's own rows carry
>   `Charge::ORIGIN_RELIEF` (2026-09-11)** — they were `manual`, which read as a STATED step to the
>   projection and as a chain link to the prune, and a clause edit over a relief halved the rent
>   for the rest of the term; the row that resumes after the window stays `manual`, because it is
>   the contract continuing. See *every edit to the clause re-trues the ladder* below.
> - **Holdover bills, but only when an operator says so.** `holdover_from` is what lets
>   `isBillableForPeriod()` past expiry; `holdover_rate_pct` (default 150%, `BillingSettings`) is
>   applied to the row in force **at expiry**, not to a projected step the term never reached.
>   Nothing auto-converts — "the tenant is still in the unit" is a fact only a human knows.
> - **The premises are date-ranged too.** `lease_unit` carries `effective_from`/`effective_to`, both
>   NULL on every row written before this (= "held for the whole lease"). A contraction CLOSES the
>   row, never deletes it, or the months the tenant actually held the space vanish from the next CAM
>   reconciliation. `Unit::allLeases()` stays UNFILTERED because DeletionPolicy uses it to mean "was
>   this unit ever leased".
> - **"Occupied" and "leased" are now DIFFERENT questions, and mixing them double-books space.**
>   `Lease::constrainToCurrentlyHeld()` (rows in force today) drives occupancy; a unit released by a
>   contraction is vacant even while the lease stays active on its other units.
>   `Lease::constrainToNotYetReleased()` (rows that have not ENDED) drives the double-booking guard,
>   because an expansion agreed in September for 1 November has already claimed the unit — reading
>   the occupancy question there let a second lease take it through October and collide on the day
>   the expansion landed. Such a unit reports **`reserved`**: not occupied, not free.
> - **The master unit cannot be given back.** It is the lease's identity (`leases.unit_id`). Moving
>   out of it is a *relocation* — an event type with no service yet.

> **⚠️ The rent is a SCHEDULE now (2026-08-08).** A benchmark against Yardi Voyager Commercial
> found one structural defect here: this module stored the lease's *current state* and mutated it.
> `LeaseRentChangeService` overwrote `Charge.amount` and `RentEscalationService` overwrote it again
> every year, so the system knew what the rent *is* and had no structured memory of what it *was*.
>
> **Phase 1 inverted the write path.** A rent change now **closes the row in force the day before
> the new one starts and opens the next** — [`ChargeScheduleService`](../../app/Services/ChargeScheduleService.php)
> is the one place that happens, and `charges.origin` records whether a row was seeded, typed,
> escalated or carried on renewal. Consequences you must know before touching this module:
>
> - **A charge type can have MANY rows.** Anything that assumed one row per `(lease, type)` is
>   wrong. `LeaseRenewalService` carried *every* active row onto the renewal — with a schedule that
>   is three overlapping rent rows billing the tenant three times a month; it now carries only the
>   row in force. `MarketingLevyService` had the same assumption baked into an `updateOrCreate`.
> - **Exactly one recurring row per type may cover a billing period.** Guarded twice:
>   `Charge::saving()` refuses an overlapping row **at write time** (any writer — a form, an import,
>   a direct `Charge::create()`), and `MonthlyBillingService::assertScheduleUnambiguous()` is the
>   backstop for rows that arrived by raw SQL. Without the write-time half the operator only learns
>   on the 1st, when the whole lease's invoice fails. One-off charges are exempt from both: a CAM
>   true-up, a percentage-rent overage and a utility recharge genuinely share a month, and they are
>   not a schedule. **Adjacent rows are fine** — one ending the day before the next begins *is* the
>   schedule; `ChargeScheduleService` cannot produce an overlap by construction.
> - **Effective dates snap to the billing month.** The engine bills one amount per type per month,
>   so a mid-month change starts on the 1st — which also reproduces the old overwrite behaviour
>   exactly. Mid-month proration of a rent change is deliberately future work.
> - **Billing a past month now bills what was in force THEN**, not today's amount. That is a
>   behaviour change, and it is the point.
> - `Lease::base_rent_monthly` still tracks the rent in force; nothing downstream moved.
> - **It takes a `BillableAgreement`, not a `Lease` (2026-08-19).** The service keys off
>   `invoiceLinkAttributes()`, so the same close-and-open discipline governs a unit owner's
>   assessment schedule ([module 37](37-unit-owners.md)) without a second implementation — one place
>   where an overlap is impossible by construction, for every agreement that bills. `overlayWindow()`
>   is the one method that still takes a `Lease`, deliberately: rent relief is a lease concession and
>   an ownership has no rent to relieve.
>
> **Fit-out grace is per-charge now (LS-05).** `fit_out_scope` decides what the grace abates:
> `rent_only` (**the new default** — base rent free, service charge and every other reimbursement
> still payable; the industry standard, "net abatement") or `gross` (the whole invoice, the
> 2026-07-19 operator decision). **The column default is `gross` and the MODEL default is
> `rent_only`** — that split is the migration: existing leases keep the grace they were actually
> billed under, new leases get the standard. `Lease::firstBillableMonth()` derives from the scope,
> so `periodInFitOut()`, the quarterly cycle anchor and the "unbilled leases" card all follow
> without their own copy of the rule. Use `inFitOutWindow()` for "is the rent free" and
> `periodInFitOut()` for "does nothing bill" — they are different questions under net abatement.
>
> **The whole term is written at signing (LS-01).** A lease created with a `fixed_percent`
> escalation gets its entire rent ladder up front — a five-year 7% lease is five rent rows the day
> it is signed, so the mall's future revenue is a recorded fact and an operator can review an
> increase before it bills. Renewals project their own ladder. **CPI is not projected** (no index
> feed; inventing the number would be inventing data — the same reason the sweep skips it), and
> `leases:apply-escalations` still runs each anniversary: it recomputes the same amount, finds it
> already in force, adds no row, and advances `base_rent_monthly` + `next_escalation_date`. A
> projected lease and a swept one converge on identical rows.
>
> **Where you SEE it:** the **Charge schedule** panel on the lease
> ([`ChargeScheduleRelationManager`](../../app/Filament/Admin/RelationManagers/ChargeScheduleRelationManager.php))
> — every row, its date range, whether it is billing now / scheduled / ended, and why it exists.
> The heading says what is billing today and when it next changes. **No row is edited in place, on
> purpose:** rent changes go through the Change Rent action, and the panel's own **Add charge** /
> **Stop charge** actions route through `ChargeScheduleService`, so the schedule has exactly one
> writer. Add charge is how any other catalogue code — key money, a chiller charge — gets onto a
> lease; `base_rent`, `marketing` and `parking` are excluded there because their own services derive
> them.
>
> **EVERY CHARGE STEPS BY ITS OWN RULE — the annual increase is a term of the charge row, Yardi's
> per-charge grain (2026-09-12, meeting 2026-09-02 point 24).** The client's ask — *"the annual
> increase should be on all expenses not only on rent — better to be an option to be a percentage
> or a fixed number"* — is not a custom request: Voyager holds the escalation schedule PER CHARGE
> CODE (method · floor and ceiling · frequency) and generates the future rows from it (benchmark
> [01 §4](../benchmarks/yardi/01-yardi-lease-administration.md#4-escalations)), MRI's recurring
> charge carries its own step, and the 2026-09-05 service-charge toggle's own docblock had named
> per-charge escalation as the shape it stood in for. Until this, parking, signage, storage and
> every other row never escalated at all, so a bay contracted at +500 a year held its signing
> figure until somebody remembered. **The rule is on the row**: `charges.escalation_mode`
> (`follows_lease` · `percent` · `fixed_amount` · `none`, null read as none) with the row's own
> `escalation_rate` / `escalation_amount`, carried onto every successor rung and every copy of the
> row through **`Charge::CARRIED_TERMS`** — the ONE list `setAmount()`, `overlayWindow()` (relief
> rows and the resumed row) and `LeaseRenewalService` spread, replacing four hand-written column
> lists that had each dropped a term at some point. `App\Support\ChargeEscalation` is the one
> reading the sweep, the projection, the schedule tab and the lease form take: a follows-lease row
> takes the rent's COLLARED percentage on the same anniversary (what the toggle meant, and what
> *"the rent and service charge shall increase by 7%"* says), its own percent or amount step by
> that, and the rent's own rows carry no rule because the lease's clause IS theirs; the marketing
> levy follows the rent by derivation. **Deliberately not copied from Voyager: a per-row frequency
> and effective date** — every clause these malls sign steps on the contract's anniversary, so the
> lease's interval is the one calendar (skill §3b); a `follows_lease` row under an AMOUNT clause
> steps nothing (a step in pounds is a statement about the rent — the 2026-09-05 rule, kept); and
> a CAM re-estimate is never stepped on either side of the boundary, exactly as before.
>
> **The sweep steps each charge by its rule; the projection writes each charge's ladder at
> signing.** `RentEscalationService::applyOne()` sizes every step from the SCHEDULE (the rung
> billing INTO the anniversary, never a lease column — the tab can end or restate a service charge
> without touching `service_charge_monthly`, and a charge with no rung live on the anniversary is
> skipped rather than resurrected); a service charge that FOLLOWS rides in the rent's own
> `LeaseRentChangeService::apply()` call (one transaction, the `_with_service` event naming both
> figures), and every other stepped charge — including a service charge on its OWN percentage,
> whose figure the `_with_service` sentence cannot carry — gets its own rung and its own
> `charge_escalated` / `charge_escalated_amount` event, naming the charge through the catalogue in
> the reader's language. **A lease whose rent never steps is still swept** for the bay that does:
> the sweep selects on the clause OR a row carrying a rule, `Lease::escalates()` is the clause OR
> `escalatesAnyCharge()`, and `Lease::saving` clears the pointer under `none` only when nothing on
> the schedule steps either. **Ruling on a charge is `ChargeScheduleService::setEscalation()`**,
> the one writer: it stamps the mode onto every active rung of the type that has not yet ended (the
> sweep reads the rung billing into each anniversary, so every rung must agree), arms
> `next_escalation_date` at the first anniversary ON OR AFTER today where the lease had none
> (never in the past, where the sweep would back-date a step over months already billed — the
> interval-change rule), and re-trues ONLY that type's ladder (`retrueProjectedLadder(clause:
> false, chargeTypes: [$type])`), so the rent's rungs keep their ids and a relief window on the
> rent is not walked over for a change to the parking. **Under a `none` clause the walk does not
> touch the rent or the levy at all** — the first cut wrote the unchanged figure and let
> `sameMoney` no-op, and the sibling test broke it: the base is read off the EVE, a STARTED rung
> the prune kept covers the anniversary itself, and writing the eve's figure there amended history
> down a step. **Where the operator rules**: the lease form's new **Annual increase** tab (below)
> holds the clause and a "Which charges step" TABLE — one row per recurring charge type on the
> schedule, read from the rung in force today, written back through `setEscalation()` only for the
> rows that changed; the schedule tab's *Add charge* asks the rule where the charge is born and
> projects its ladder at once; the charge importer takes the three columns. **The default is the
> PROPERTY's proposal**: `billing.new_charges_follow_escalation` (per property, off = Yardi's
> answer, a charge carries no escalation until one is stated) makes the client's *"on all
> expenses"* a configuration act — on, the service charge the form seeds, every *Add charge* and
> every blank importer cell is PROPOSED as following the clause, and the operator still rules per
> row. The 2026-09-05 column is gone: the migration writes `follows_lease` onto every active
> service-charge row of a flagged lease and drops it, so nothing an install bills moved on deploy.
> (`EveryChargeStepsByItsOwnRuleTest`, twenty-two cases, nineteen mutations each killing their own
> tooth — including the `clauseIsFollowable()` guard inside `stepFor()`, which the driven cases
> could not see because both callers pass null under an amount clause, so it is asked directly.)
>
> **The adversarial review found three blockers and four should-fixes, every one verified by
> driving the code, and each is a tooth now.** A PARKING BAY IS DERIVED: it is priced in the
> rentable-items register and `AssignRentableItemService::rebuildCharge()` re-derives the parking
> row from the sum on every assignment, so a rule on the row was undone by the next bay (+500
> vanished on the third) — `parking` joins `ChargeEscalation::DERIVED_TYPES`, and stepping a bay
> belongs to that register, PER ITEM — **built the same week, see the next block**. THE POINTER
> WAS ARMED IN ONE DOOR:
> `Lease::saving` cannot see a charge row at creation, so a `none`-clause lease ruled at birth
> projected a ladder and was never swept (`service_charge_monthly` 250 while the schedule billed
> 260) — the PROJECTION arms `next_escalation_date` now, the seam every door reaches. RESTATING A
> RULED CHARGE SWITCHED ITS RULE OFF: the Add-charge modal and the importer defaulted every row to
> the property's proposal, so re-pricing a +500 charge stored `none` on the successor while the
> stale rungs went on stepping (and with the property set to follow, +500 became 7 %) — the modal
> proposes the rule the type already carries, a blank importer cell INHERITS (null on every
> `setAmount()` branch), and the type's ladder is re-walked whether or not the new row states a
> rule. A RELIEF ROW WAS A BASE AND A TARGET for the sweep's charge step (a flat 5,000 concession
> came out as 6,000 for the window): `contractedRowBefore()` walks back over the window, the
> sweep never writes into one, and the carry the projection seeds is the contract's, not the
> concession's. A STATED TERM NEVER REACHED THE ROW IN FORCE — `setAmount()` returned on the
> same-money branch before reading its attributes, so an importer row restating the seeded
> service charge *with percent 5* reported success and stored nothing (pre-existing for
> `billing_timing`/`prorate`, fixed for all three). The form's diff compares rules normalised by
> mode, or a figure lingering in a hidden box re-minted the ladder on every save. **Recorded, not
> built**: a `manual` resumption after a relief is not re-priced by the walk (shared with the
> rent's ladder, pre-existing), the RENT's own sweep step inside a relief window has the same
> shape on the rent path (pre-existing; its own `/safe-change`), and a charge on its own rule under
> a CPI lease waits for the index with the rent — one anniversary, deliberate.)
>
> **A PARKING BAY STEPS BY ITS OWN RULE TOO, ON ITS HOLDING — and a lease is created WITH its
> bays, and the rule is set from the TABS (2026-09-12).** Three asks in one change, the operator's:
> *"the lease from the beginning to be able to add parking and rentable items while creation, also
> handle the annual escalation of it … handle the escalation on the tabs not in the form only …
> make sure the relation managers are synced with forms."* The standard is the same benchmark: a
> rentable item in Voyager is *a recurring lease charge on its own code* assignable to *"new and
> existing residents"* (benchmark 09 §2), and the escalation schedule sits on that charge (01 §4).
> Atriom folds every item into ONE `parking` row, so the per-item half of that shape is the
> HOLDING: `rentable_item_holdings` carries the same three terms a charge row does
> (`escalation_mode` · `escalation_rate` · `escalation_amount`, `ChargeEscalation::MODES`, read
> by the same class — its methods take any row carrying the terms). **`App\Support\RentableItemPricing`
> is the one arithmetic**: `rateOn()` is the holding's `monthly_rate` stepped once per lease
> anniversary from the sweep's own pointer to the date, only where the anniversary's billing month
> is after the holding's (a bay taken in the anniversary month is priced for the year at the rate
> agreed that day); `sumOn()` is what the parking row carries on a date. Three readers of it: the
> assignment-day rebuild, the projection's `projectParkingRung()` (one rung per anniversary, laid
> beside the charges' and pruned with them on a clause edit), and the sweep, which steps each held
> item, STORES the new rate on the holding — the rent's own discipline, and why a follows-lease bay
> under an index clause can be re-summed a year later — re-sums the row and records
> `charge_escalated_items` (*"…1,500.00 to 1,700.00 (P-A, P-B, each by its own rule)"*, the codes
> being data and the sentence resolved in the reader's language). A lease whose rent never steps
> is swept, armed and projected for the bay that does (`Lease::escalatesAnyCharge()` reads the
> register; the sweep's selection carries the same predicate). An OWNERSHIP's bay carries no rule —
> an assessment has no anniversary. **The corollary of storing the rate in force, stated**: a date
> before an anniversary already applied reads the stepped rate, so a change back-dated across one
> prices the months before it at today's rates — the same limit a back-dated rent change has
> against a started rung. **The parking rows are a FUNCTION of the register and are RE-LAID, never
> amended** (`ChargeScheduleService::relayDerivedRows()`): moving the one row in force and leaving
> the later ones standing — `setAmount()`'s discipline, right for a charge stated rung by rung —
> left a bay back-dated across a started anniversary unbilled for a year and a bay released
> back-dated billing for the rest of the term (found by review). Every row from the change date is
> derived again from the dates the held set changes, a row already starting on a segment's date is
> amended in place (one bay let after another the same month is one row, one id), the row covering
> the months before stays ACTIVE and bounded, and nothing held from a date is a GAP through
> `close()` — whose rule fixed a pre-existing money defect on the way: the old close branch set
> `is_active => false` on a stop still ahead, and the planner drops an inactive row before it reads
> the end date, so a bay released at the year end and recorded in June billed nothing from June
> (`RentableItemAssignmentTest` had pinned it). **Items at creation**: the create form's *Parking &
> rentable items* table (Lease details tab — create only, hidden for a DRAFT, which holds nothing;
> a blank date means the commencement, a date ahead of it is refused in words) and the quick
> wizard's third step share ONE builder (`LeaseForm::rentableItemsAtCreation()`), and every row
> goes through `AssignRentableItemService::assign()` — the one door the header action and the tab
> take. The wizard's table DEHYDRATES where the form's must not: an action's `$data` is the
> dehydrated state, and with the form's setting the wizard's step accepted the rows, created the
> lease and let nothing (found by review — a service-level test could not see it; the test drives
> the modal). **The rule on the tabs**: the schedule tab's *Annual increase* row action writes
> through `ChargeScheduleService::setEscalation()`, the items tab's through
> `AssignRentableItemService::setEscalation()` (the live holding only, by its own id — never
> `updateExistingPivot()`, which reaches every holding of the item, and the one that goes ON when a
> future-dated release overlaps a re-let), the assign modal asks the rule where the bay is let, and
> `App\Support\Filament\EscalationRuleFields` is the one trio of fields all seven askers build.
> **Synced both ways**: a tab action announces `RecordChanged` and `EditLease::refreshFormData()`
> refills the form's "Which charges step" table by hand — `fillPartially()` flattens the record
> with `dot()->only()` and an ARRAY path matches nothing in a flattened map, so listing it in
> `derivedStatePaths()` refilled nothing (measured); and it is `refreshFormData` that is aliased,
> NOT the `#[On]` listener, because Livewire keys attribute listeners by EVENT and an aliased trait
> method keeps its attribute, so overriding the listener registered two handlers and the alias won
> (found by review; the test dispatches the event, never calls the method). The form's table shows
> the register's rules in words against a read-only parking row (`RentableItemPricing::
> describeHoldings()`, the same sentence the items tab's column shows), pointing at the tab that
> rules per item. **Doors left alone, and why**: the lease importer cannot state items (a CSV row
> is one lease, not a list); the charge importer and `UnitOwnershipChargesRelationManager` never
> write holdings; the ownership tab's assign modal offers no rule. **Recorded, not built**: a
> relief window on the parking row (unreachable — relief is offered for the rent and the service
> charge only; the walk stays out of one if that widens); a follows-lease bay under a `none` clause
> keeps the pointer armed for a sweep that no-ops, as a follows-lease charge does.
> (`ARentableItemStepsByItsOwnRuleTest`, twenty-one cases, twenty-seven mutations each killing
> their own tooth; `release()` also pinned — it too wrote every holding of the item, pre-existing.)
>
> **Clearing a clause takes its projected future with it (2026-09-05).** The `saving` hook clears
> the clause's COLUMNS; `ChargeScheduleService::pruneProjectedLadder()` now clears its SCHEDULE on
> the two events where the sweep's rung-by-rung self-correction dies — `escalation_type` → `none`
> (rent + every ruled charge's rungs + the levy's lock-step rungs, matched to the rent rungs
> actually pruned) and a charge ruled to stand still (`setEscalation(…, none)` since 2026-09-12;
> the service-charge toggle → off before that). Only **not-yet-started** rungs carrying the projection's own origin
> go: a rung already billing is history, and a future rung the operator amended through Change
> Rent carries `manual` and is a stated term — it survives, and **the chain re-links around it**
> (every surviving row whose end abuts a pruned rung extends to the next survivor's eve or the
> chain's outer bound, because a deactivated future without a re-opened survivor stops the charge
> billing entirely at the next anniversary — worse than the escalated amount).
> (`AClearedEscalationClauseTakesItsProjectedFutureWithItTest`, mutation-proved three ways.)
>
> **A CHANGED RENT REACHES THE END OF THE LEASE — the ladder follows the change (2026-09-05,
> reported from the panel).** On a lease with a projected ladder — every fixed-percent lease —
> Change Rent's new row inherits its end from the row it closes (the eve of the next anniversary)
> and every rung beyond was computed from the OLD rent at signing, so the operator's change
> visibly died after one year: the schedule, the billing forecast and the rent roll all reverted
> to old-rent figures, with only the sweep's night-of-the-anniversary amend to quietly correct
> each rung as it arrived. `LeaseRentChangeService::apply()` and `LeaseSpaceChangeService` now
> re-true the ladder through the same `projectTermEscalations()` walk, whose base is **the rent in
> force on each step's own EVE, read from the schedule the walk is writing** (a carried
> accumulator was identical while the projection was the ladder's only writer, and stops being the
> moment a rung mid-ladder is stated). **A rung the operator STATED outranks the derivation**: a
> future-dated Change Rent amends its anniversary's rung in place and marks it `manual`, so the
> re-true adopts its figure instead of overwriting it, and the step after it compounds from the
> stated amount — the contract's own reading. **The SWEEP deliberately does not re-true**
> (`origin === ORIGIN_ESCALATION` skips it): its contract is one step per run — on an unprojected
> lease it appends one rung a year, pinned behaviour — and it is the projection's job, not the
> night's. *(Its original second reason — that a re-projection would write the tail at the raw
> rate for exactly the lease whose collar just bit — lapsed on 2026-09-11, when the projection
> started writing the collared rate.)* (`AChangedRentReachesTheEndOfTheLeaseTest`, eight cases, three
> mutations proved — the re-true, the stated-rung adoption, and the space-change wiring.)
>
> **EVERY EDIT TO THE CLAUSE RE-TRUES THE LADDER — the ladder is a function of the clause, and it
> was being kept for one term of it (2026-09-11, Trello RV4DrGHA + jF09XB3n, both Critical).**
> Billing reads the LADDER and never the clause, so a ladder projected from a clause the operator
> then corrected goes on billing the correction's predecessor. `Lease::updated` re-projected on
> exactly three hand-written events — clause cleared, service-charge toggle on, toggle off — and
> on nothing else, so the tester's ordinary editing session on staging lease #21 (set the rate,
> save, change the interval, save, change the rate again, save) left the ladder written at the
> FIRST save: rungs at 10% where the clause read 100%, stepping every month where it read every
> year — two cards, one cause, reproduced rung for rung. **`Lease::LADDER_TERMS`** names the six
> columns the projection is a function of (type · rate · amount · interval · the levy toggle and
> rate — the service-charge toggle was the seventh until 2026-09-12, when the rule moved onto the
> charge row and `setEscalation()` became its own re-true), the hook asks `wasChanged()` of the list, and
> **`ChargeScheduleService::retrueProjectedLadder()`** does the whole of it: prune every
> not-yet-started projected rent and service rung and the levy rungs riding on exactly those rent
> rungs, then project again from the clause as it NOW reads — a cleared clause projects nothing,
> so the three branches are gone and a change to the eighth term is covered by being registered.
> **The collar is IN the schedule (2026-09-11, the same day's follow-up)**: the projection wrote
> the raw rate and left the sweep to clamp each rung the night it landed, so a ceiling of 5 % over
> a 10 % clause showed a 10 % ladder for the whole term and a tester read it as not applied.
> Yardi's rent-step schedule IS the amounts that will bill and a fixed-percent collar is
> deterministic, so `projectTermEscalations()` writes `RentEscalationService::collar()`'s answer —
> the same clamp the sweep applies, so the anniversary is a no-op and the two cannot disagree (a
> fixed AMOUNT is not collared, as in the sweep). The collar columns are not in `LADDER_TERMS`
> but DERIVED into the trigger: `Lease::collaredRateMoved()` re-trues exactly when the clamp's
> answer changes (tightening a ceiling from 12 to 5 over 10 % does; lifting a floor from 2 to 3
> under it does not), so a bound that never bites churns nothing. A **started rung is history and a stated (`manual`) rung is a
> term** — the prune touches neither, exactly as the cleared-clause prune above. **The interval is
> the one term the sweep's own pointer reads**, so `saving` re-arms `next_escalation_date` from the
> SWEEP'S OWN STATE — the pointer it carries is one old interval past the last step it applied, so
> that step plus one NEW interval is the next, walked forward to the first anniversary on or after
> today. The first cut read the last projected rung that had STARTED instead, and the review broke
> it: rungs start on the 1st and the sweep applies on the anniversary day, so an interval edit in
> between read a rung as applied while `base_rent_monthly` was never bumped, armed the pointer past
> the sweep, and every later sweep amended the projected rungs DOWN a step for the rest of the
> term. And a shortened interval a year in puts "last applied + new interval" in the PAST, where the
> sweep would back-date a step over months already billed — hence the forward walk. **It is also
> the REPAIR**: a ladder that has already drifted — staging lease #21 — is re-trued by the same
> method on demand, which is why it is a public method rather than the hook's body.
>
> **A RELIEF WINDOW IS WALKED THROUGH, NOT OVER, and a relief row now says it is one.** The review
> drove a rate edit on a lease carrying a six-month 50 % relief over the first step and got
> `550@2027-09..2028-08 | 660@2028-09 | 792@2029-09` — rent halved for the rest of the term, ladder
> looking ordinary. Three things compounded, all from one collapse: a relief's rows were written
> `manual`, indistinguishable from a STATED step (the same collapse `ORIGIN_CAM_ESTIMATE` was
> introduced to end), so the projection ADOPTED the relief segment standing on the anniversary as
> the contracted figure and derived the levy from it; the prune took the rung that RESUMES the
> contract after the window (`overlayWindow()` pushes it past the window, still `escalation`); and
> the chain re-link then extended the relief row over the gap it left. `Charge::ORIGIN_RELIEF` is
> the window's own rows' origin now (the resumed copy stays `manual` — it IS the contract
> continuing), backfilled from each relief event's `rows_opened[].id` by
> `2026_09_11_120000_a_relief_row_says_it_is_one`. The prune keeps a rung starting the day after a
> relief row ends; the walk treats a relief-covered anniversary as neither adopted nor written and
> a relief eve as no base (the carried figure is the contracted rent the relief was granted
> against); and the resumption rung is RE-PRICED to the step the clause now says, so the levy —
> derived from that same figure — and the resumed rent agree. The window's own rows stay exactly
> as granted, to the day.
>
> **Two more came out of the same lease, both in the levy's tail.** `pickInForce()`'s fallback
> for a date NOTHING covers answered with the LAST active row, which is right for a schedule that
> has run out and wrong for one that has not begun: every write snaps to the billing boundary, so
> a lease commencing on the 10th has no row covering the 1st of its own first month, and the
> answer to "what is in force before anything is" is the FIRST row. Handed the last projected
> rung instead, the levy re-sync on an ordinary save in the commencement month overwrote the
> final year's levy with the base levy — 400 → 50 on the box. It reads the first row now. **And
> the levy's re-sync moved OUT of `EditLease::afterSave()` into the hook** — it ran on every save
> of the lease there (a write for nothing on most, and the door for this one), and once the levy
> pair became ladder terms the ORDER was wrong: the hook projected the levy's rungs with no base
> row in force, so `setAmount` opened the levy from commencement at the first STEP's amount, and
> the page's re-sync then overwrote it with the base — measured through the real page, a levy
> toggled on lost its first future step. `Lease::updated` re-syncs the base row FIRST
> (`createLevyCharge()`), then re-trues; and a levy-only edit (`Lease::LEVY_TERMS`) re-trues ONLY
> the levy rungs (`retrueProjectedLadder($lease, clause: false)`) — `setAmount()` no-ops on an
> unchanged amount, so the walk over an intact rent ladder writes nothing for rent and no rent
> rung changes id. (`AnEscalationClauseEditRetruesItsLadderTest` — fifteen cases: the tester's
> exact session, the carried levy, the surviving stated rung, the collar-only no-churn control,
> the pointer on a fresh, a mid-term and a second interval change, an interval edit inside the
> 1st-to-anniversary window followed by the sweep, a step the sweep had applied, the relief walk,
> the levy toggled on through the page, and the repair of staging's exact drifted state; nine
> mutations each kill their own tooth, and the re-link's relief clause is recorded as
> belt-and-braces — unreachable while the resumption is kept.)
>
> **AND THE TERM IS THE LADDER'S BOUNDS — one door over, the next day (2026-09-11, Trello
> 7IgLPLGl, Critical).** The seeded rows start ON the commencement, the anniversaries are counted
> FROM it and the walk stops AT the expiry, so a commencement or expiry edit is a ladder edit —
> and the form had said so since 2026-08-12, in the comment that LOCKS both dates once the lease
> is invoiced (*"the commencement anchors … every charge row's start date"*), while nothing
> re-derived any of it on an edit: the tester moved the commencement from the 10th to the 12th on
> an un-invoiced lease and the schedule went on starting on the 10th. `Lease::LADDER_BOUNDS` joins
> the re-true trigger; a commencement move re-dates the rows CREATION anchored on the old date —
> `seed` · `levy` · `renewal` (`retrueProjectedLadder(…, redateFrom:)` → `redateRowsAnchoredOn()`,
> after the prune so a projection-closed base row is open again, before the walk so the walk
> closes it at the moved first anniversary — through the model, row by row, so `Charge::saving`'s
> guards stand; a moved row that would now end before it starts covers nothing under the new term
> and is retired rather than refused in a charge's vocabulary) and re-arms the first anniversary
> from the new commencement. **Only creation's rows, by ORIGIN, because on a lease commencing on
> the 1st every writer snaps to the 1st** — a bay assigned that month, a CAM estimate, a relief
> segment or a manual charge shares the date without being about it, and a bay's register row
> would not move with it (the review's finding; the first cut moved anything on the date). An
> expiry move prunes past the new end and projects up to a lengthened LIVE term; **an ended term
> "lengthened" is a close-out and a shortened one an early termination, and in both the walk is
> bounded at the CONTRACTED expiry** — `LeaseTerminationService` writes the termination date onto
> `expiry_date`, and unbounded, closing out an expired term on 15 October minted the anniversary
> on the 1st of the expiry month (1,331 over 1,210) for the final bill to read, the exact rule
> `ConvertLeaseToHoldoverService` states the other way round (the review's second finding).
> `LeaseExtensionService`'s own projection stays: it is the service's statement of intent, and it
> covers the extension of a term that has already run out, which the hook reads as a close-out.
> **And the form's lock is a GATE now, with TWO reasons behind ONE predicate**:
> `Lease::commencementLockedBecause()` answers `invoiced` (the older lock) or `stepped` — a
> contracted step already reached, since the anniversaries are counted from the commencement and
> moving it re-derives a step that happened (measured: a year-old draft moved a month later kept
> its started 1,100 rung AND projected 1,210 from the new anniversary) — and the form's disabled
> field, its helper and the model's refusal (`admin.refusals.lease_commencement_locked_after_{reason}`,
> both languages, naming the way out) all read it, so a service that renders no field is refused
> for exactly the reason the form shows. Under the importer the row FAILS without the sentence —
> Filament's `ImportCsv` logs a failed row and not the message, a pre-existing shape for every
> model refusal under every importer, and an open item. The expiry is deliberately not guarded,
> because three acts move it. Voyager treats a start-date change once charges have posted as an
> amendment rather than an edit — the same line in the same place. The field says what a move
> does before it is made (`admin.helpers.commencement_redates_schedule`).
> (`ALeaseTermEditRedatesItsScheduleTest` — eleven cases: the tester's exact steps through the real
> page, a move to another month, a row with its own date and one that merely shares the date, a
> shortened and a lengthened expiry, the invoiced refusal with its expiry control, the stepped
> refusal with its fresh-lease control, a close-out past the expiry, an early termination keeping
> its coming step, a levy-closed base row moved past its end, and the wording in both languages;
> nine mutations each kill their own tooth.)
>
> **TWO MORE DOORS OF THE SAME SHAPE, closed the same day.** *A DRAFT's rent follows its units*:
> `EditLease::afterSave()` refuses a unit change on a live lease and routes it to the space-change
> act; a draft is outside that list, and on a rate-priced draft the rent is rate × area — so a
> draft whose space changed kept the rent of the old space in the column and in the seeded row.
> **The review then found the door did not exist in a browser**: the units picker carried TWO
> `disabled()` calls and the later, page-wide `$operation === 'edit'` (from the day removing a
> unit still detached its occupancy row) overwrote the status one, so every Edit page was locked,
> drafts included, and only the Livewire harness — which fills a disabled field regardless — had
> ever reached the new code. **`Lease::premisesLockedBecause()` is the ONE predicate now** —
> `live` (the act owns the space) · `stepped` (a re-priced seed row would disagree with a started
> rung) · `schedule` (a row an act or an import wrote would be re-priced and relabelled) — read by
> the field's single `disabled()`, its helper (two sentences, one for a live lease and one for a
> draft) and `afterSave()`'s refusal, and the test asserts the field is ENABLED on a draft through
> the real page, the assertion nothing had made. When it is free, the wizard's own post-attach
> sequence runs again: `repriceFromPremises()` and then **`ChargeScheduleService::repriceSeededRent()`**,
> which amends the seeded rows in place at the commencement, REBUILDS the levy (every levy row is
> derived from the rent, and an earlier re-rate leaves two rows of which `createLevyCharge()`
> amends only the first — the review's finding), re-projects the steps — and NO-OPS when the
> seeded rows already carry the lease's figures (the form derives a rate-priced rent live from the
> units picked, so the column usually arrives already right and the ROW is what is a save behind;
> the row is the signal, or a flat-priced draft would re-mint its rungs for nothing). **A figure of
> zero RETIRES the row rather than amending it to 0.00** — `seedStandardCharges()` seeds none, the
> billing run has no zero skip, and the review found the rent branch had no zero twin at all: a
> rent corrected to 0 left the seed rent row billing 10,000.
> *The lease IMPORTER re-importing a lease already on the books*: `resolveRecord()` re-imports by
> reference and `afterCreate()` seeds charges for a NEW lease only, so a corrected rent in a
> re-run file moved the lease's column and left the seeded row billing the old figure —
> `LeaseImportExecutesTest` had pinned exactly that as "idempotent". Yardi imports lease charges
> as their own records. While NOTHING has happened to the lease — `commencementLockedBecause()`
> null and **`scheduleIsStillAsCreated()`** (no active row outside seed · levy · escalation) —
> the importer is the door that made the schedule and may re-derive it through the same
> `repriceSeededRent()` (the correction a migrating operator makes before the first billing night)
> — run whenever the gates pass and not only when a column moved, so a lease the OLD behaviour
> had already drifted is repaired by re-running the file with the figures it carries; otherwise
> `beforeUpdate()` throws a `RowImportFailedException` in the reader's words naming the doors that
> own the rows (`admin.refusals.lease_import_amounts_behind_schedule`) — the one refusal Filament's
> `ImportCsv` writes into the failed-rows file WITH its sentence. **And the console has the repair
> too**: `atriom:project-lease-schedules --retrue` takes already-laddered leases through the hook's
> own `retrueProjectedLadder()` (dry-run; `--commit` writes), for the ladders projected before the
> collar was written into the schedule and the ones that drifted before the hook existed — the
> deploy step for this change, since nothing re-trues an existing ladder on its own.
> (`ADraftLeasesRentFollowsItsUnitsTest` · `LeaseImportExecutesTest`; fourteen mutations.)
>
> **Leases signed before projection existed** carry a single open-ended rent row and no ladder.
> `php artisan atriom:project-lease-schedules` backfills them (dry-run by default, `--commit` to
> write); it anchors on each lease's own `next_escalation_date`, so a mid-term lease gets its steps
> on the contract's dates and an already-billed month is never re-dated. Until a lease is
> backfilled its Charge schedule says so explicitly rather than claiming no increase is coming.
>
> **Former wart, FIXED 2026-08-11 — `charges.type` was a DB-level ENUM**, which the project
> convention forbids (string + validation, so a new type needs no migration) and which capped the
> charge-code catalogue: a code an accountant added could be billed as a one-off invoice line and
> not set up as a recurring charge, because the database rejected it. It is now a `string(32)`
> validated by `Charge::assertTypeIsAKnownChargeCode()` against the catalogue (with
> `InvoiceItemType` as the floor for an unseeded database). Its side effect went with it: MySQL
> ordered an ENUM by DECLARED index, so `ORDER BY type` read as arbitrary on screen. The
> charge-schedule table still sorts by date, because a schedule reads as one timeline.
>
> Full analysis and the remaining phases: [`docs/benchmarks/yardi/`](../benchmarks/yardi/README.md).
> **Still open here:** no lease options / notice-window alerts, no trailing proration, holdover is
> alerted but never billed. Note `LeaseCreationService` hard-codes `escalation_type =
> 'fixed_percent'` and ignores the caller's value — a CPI lease can only be made by editing one
> after creation.

> **⚠️ Fixed 2026-09-05 — every lease created through the PANEL carried the wrong mall's initials.**
> `LeaseForm` defaulted the reference field to `Lease::generateReference('AW')` — Atriom Walk's
> initials, a hardcoded literal — so a lease on any other mall was numbered `LSE-AW-…`. Found on the
> Val Plaza demo box, and it was **not** the documented "renamed after seeding" hazard: that asset was
> created once and never updated, and the leases were created days later. `Lease::creating()` already
> resolves the code from the lease's own UNIT and allocates under the document-number lock — but it
> returns early when a reference is already filled, so the form computed a wrong answer and silently
> overrode the right one. That is why a direct model create looked correct and only the panel was
> wrong. The form no longer pre-fills; the field shows *"Assigned when you save"*. Pre-allocating at
> RENDER time was a second fault: two operators opening the form both received the same number, and
> the second save met the unique index instead of taking the next one.
> (`ADocumentCarriesTheMallItBelongsToTest`, both teeth mutation-proved.)
>
> **⚠️ Fixed 2026-08-11 — `Lease::generateReference()` was a deterministic duplicate-key 500.**
> It was `count() + 1` against a UNIQUE column on a soft-deleting model. The soft-delete scope
> hides trashed rows from `count()`, so the counter falls behind the numbers actually issued:
> create five leases, delete one, and the next create computes `…-0005`, which already exists.
> The insert throws — **and throws on every subsequent attempt, because the count never recovers,
> so lease creation stays broken for the rest of the calendar year.**
>
> Reachable by design rather than by misuse: `DeletionPolicy` puts Lease in the WHEN_UNUSED tier
> and `EditLease` offers Delete/ForceDelete, so removing a lease that nothing references is a
> supported action — and a lease that nothing references is exactly what `LeaseImporter` produces
> today, since it never seeds a charge schedule.
>
> It now uses the shape `Invoice` has had all along, four files away: **MAX-of-prefix over
> `withTrashed()`**, a **collision loop**, and `AllocatesDocumentNumber`'s **lock held across the
> insert**, with the UNIQUE index as the final backstop. `creating` always re-allocates, so a
> reference the form or the importer pre-filled minutes earlier can never be persisted stale — and
> the property code now comes from the lease's own unit rather than the hardcoded `'AW'` those
> callers passed. Pinned by `LeaseReferenceAllocationTest`, which was mutation-checked against the
> original implementation and reproduces the exact `UNIQUE constraint failed: leases.reference`.
>
> *Worth noting which half did the work:* the collision loop alone defeats the crash — a probe with
> `count()` restored but the loop in place still passed. MAX is the better primitive (monotonic, no
> loop iterations), but the loop is the guard.


> **⚠️ Fixed 2026-08-11 — the lease importer did not work, in four stacked ways.**
> This is the cut-over path, and **no test in the repository had ever executed an importer** (the
> one importer test inspects validation *rules*), which is how four faults sat on it with a green
> suite. Each hid the next:
>
> 1. **`$this` inside a closure built in `static getColumns()`** — the `unit_code` column read
>    `$this->data['asset_code']`, where no `$this` is bound. So `unit_id` was never set, against a
>    NOT NULL column. **PHPStan reported this twice and both entries were in the baseline**; they
>    are now removed rather than suppressed.
> 2. **A column that does not exist** — `asset_code` had no `fillRecordUsing()`, so Filament wrote
>    `$record->asset_code`, and `leases` has no such column → `SQLSTATE[42S22]` on every row.
> 3. **No charge schedule** — the importer never called `seedStandardCharges()`, so an imported
>    lease billed **nothing** (`MonthlyBillingService` reads the schedule, not the columns).
> 4. **Not idempotent, not property-clamped** — a missing `reference` minted a fresh one per run,
>    duplicating every lease on a re-run; and `withoutGlobalScopes()` was called with no visibility
>    check, copying `UnitImporter`'s lookup while dropping the clamp that makes it safe.
>
> Cross-field lookups now happen in `resolveRecord()`, which is an instance method where
> `$this->data` genuinely exists; an unresolvable unit or tenant returns null, which SKIPS the row
> rather than reaching an insert that dies on an integrity constraint. The property clamp is
> extracted to `ResolvesVisibleAssetByCode` so the next importer inherits it. **Existing contract
> references are preserved** — importing an operator's leases means importing the references they
> already use. Pinned by `LeaseImportExecutesTest`, which drives `Importer::__invoke()` directly.
>
> **And the safety net was blind:** `atriom:audit-charge-schedules` iterated a lease's charges, so
> a lease with ZERO charges — exactly what the broken importer produced — yielded no findings and
> the command printed "Every charge schedule is unambiguous." It now reports that shape explicitly.




> **A charge cannot start before its lease does (2026-08-28).** The Add-charge modal defaulted to the
> current month and accepted it on a lease commencing the month AFTER. **No money was at risk** —
> `planInvoiceForLease()` already clamps the billable window to the commencement date, measured: a
> lease commencing 1 September with a charge from 1 August billed **0.00 in August** and 11,000 in
> September. That is exactly what made it worth guarding: the form accepted a date it would silently
> ignore, so the operator sets August, reads the August run, finds nothing, and goes looking for a
> fault in the billing.
>
> The floor is **commencement (possession)**, not rent commencement — a tenant fitting out before
> rent starts is still consuming security and power, so a service charge from the day they took the
> keys is real. **No ceiling at expiry, deliberately**: a lease in HOLDOVER has an expiry date in the
> past on purpose and is still billing, so an upper bound would block adding a charge to exactly the
> leases that most often need one. Both pickers in the schedule tab also gained `->native(false)` —
> they were the only two date fields in the admin panel rendering the browser's own control against
> 352 that do not. (`AChargeCannotStartBeforeItsLeaseTest`, proven by removal.)



> **⚠️ A one-off could REPLACE the recurring charge it was meant to top up (fixed 2026-08-28).**
> Found by an operator following a correction through the panel: a service charge invoiced at 11,000
> should have been 14,000, so the 3,000 shortfall was added as a **one-time charge of the same type**
> — and October went from 14,000 to **3,000**. The month under-billed by 14,000, silently.
>
> `ChargeScheduleService::setAmount()` **restates**: it closes the row in force and opens a new one.
> Right for a rent change or an escalation step, catastrophic for a one-off, because the schedule
> holds **one row per type per month** by design (`Charge`'s overlap guard refuses two) — so a
> one-time row of a live type cannot sit beside the recurring one, only in its place. Where a later
> row happens to exist the damage is one month; where none does — the ordinary case — the recurring
> charge simply **ends for the rest of the term**.
>
> Refused in the Add-charge action, naming the remedy: bill the top-up under its **own charge code**
> (`other`), which is what Yardi does and what the code exists for — the tenant then reads a line
> that says what it is, instead of a service charge that changed size for one month. The refusal is
> scoped to `one_time` only: restating a recurring charge is the ordinary act this screen exists for
> and is pinned by its own control. (`AOneOffMustNotEatTheRecurringChargeTest`, proven by removal.)



> **⚠️ Adding a unit through the form left the rent behind (fixed 2026-08-28).**
> `EditLease::afterSave()` calls `syncUnits()`, which attaches the units and nothing else: measured,
> a 110 m² lease at 4,800/m² went to 200 m² and kept billing **44,000 where 80,000 was due**, with
> the charge schedule and the forecast both still showing the old figure.
>
> **Re-deriving there is not the fix, and that is the point.** Re-rating needs an EFFECTIVE DATE and
> a form save has nowhere to put one, so it could only restate the rent from the start of the lease —
> rewriting months already billed. `LeaseSpaceChangeService` (Change premises) takes that date,
> re-derives at it, and closes and reopens the charge row; Yardi treats a premises change as a dated
> amendment for the same reason. Refused on the WRITE as well as the field, since a disabled input's
> value still arrives in the Livewire payload. A DRAFT lease stays freely editable.
> (`SpaceMovesThroughItsOwnActionTest`, proven by removal.)



> **⚠️ `headerActions()` declared twice renders only the second (fixed 2026-08-28).**
> `ChargeScheduleRelationManager` called it once with `changeRent` and again with `addCharge`, and
> the second call REPLACES the first — it is a setter, not an append. So `changeRent` was written on
> that tab and rendered **nowhere**, from the day it was added, and the duplication is invisible in
> review because both calls read correctly on their own. Exactly the failure `LeaseActions`'s own
> docblock records — *"an action missing from a group is defined and rendered nowhere"* — arriving
> through a different door. Found while adding a second action beside it, when neither appeared.
>
> **An action now lives where its RESULT is shown**, so the operator can work from the tab or from
> the header: `changeRent` and `grantRelief` on the **charge schedule** (both write rows into it),
> and the premises and lifecycle actions on **lease history** (every one writes an event, which is
> the row that tab exists to show). Nothing MOVED — the header keeps all twelve, because a tab is a
> second door and not a replacement. `EveryLeaseActionIsReachableFromItsTabTest` builds each table
> and asks what it will actually render, since reading the source is what let a duplicate
> `headerActions()` look correct for months.



> **The two hardest tabs explained nothing (2026-08-28).** Measured across the lease's tab forms:
> **24 of 51 fields carried help**, and the two worst were the two whose every field is a legal or
> arithmetic concept — **Options** (12 of 15 bare) and **CAM cap terms** (7 of 11). An operator was
> asked for `uplift_percent`, `base_year_amount` and `compounding` with nothing but the label. Now
> **42 of 51**; the remaining nine are table filters and self-evident fields, which is the bar
> `FieldHelp` sets deliberately — *"every required field needs help" is the wrong bar*.
>
> **How the measurement was nearly faked.** The first pass built the page and asked each field for
> `getHelperText()`, reporting 27 of 48 bare. Every one of the 48 had actually THROWN — the trap
> CLAUDE.md already records for `FieldHelpConformanceTest`, whose own first version *"reported 11%
> of 673 fields carry help while measuring nothing at all"*. The number was manufactured by a
> swallowed exception. Read from source, the way the gate does, it was 41 of 50 on the main form —
> the lease form was never the stale one.


## The lease abstract — clauses (2026-08-19)

`lease_clauses` holds the legal terms that do not reduce to money, taken from the benchmark's own
list *(cited, [benchmarks/yardi/01](../benchmarks/yardi/01-yardi-lease-administration.md) §7)*: use ·
**exclusivity** · **radius restriction** · **co-tenancy** · **kick-out** · assignment and subletting ·
insurance · operating hours · signage · parking allocation · repairs · guarantor. Not extended with
invented types — a clause Voyager does not name goes in `other` with its wording until it is common
enough to earn a row.

**The reason it exists is a question, not a feature**, and the benchmark states it:

> *"co-tenancy and kick-out clauses are contingent money. … In Atriom these clauses live only in the
> uploaded PDF, so nothing can act on them and nothing can even report 'how many of our leases have
> a co-tenancy trigger tied to the anchor we are about to lose'."*

**`LeaseClause::scopeLiveExposure()` answers exactly that**, and the tab badges those two types
apart from the rest.

It bundles three conditions rather than leaving them to be composed — contingent-money type, clause
in force, **and the lease still live**. The bundling is not tidiness: the first version composed
only the first two and reported a **terminated** lease as exposed, because its co-tenancy clause was
open-ended and so read as in force for ever while the tenancy it protected had ended. An operator
asking *"who can claim an abatement if the anchor leaves?"* would have been handed a tenant who had
already left. Found by running the query on real data, fixed the same day, pinned by
`LeaseClausesAreAbstractedTest`.

`scopeContingentMoney()` survives as a pure type filter, because *"every kick-out clause we have
ever agreed"* is a legitimate different question that deliberately includes dead leases.

### What it deliberately does NOT do

The benchmark notes a well-run system **abates rent automatically** when a co-tenancy trigger
fires. Atriom records and surfaces the trigger; raising the abatement stays a deliberate act through
`LeaseReliefService`. That is a decision:

- An abatement is money off a tenant's bill, and the condition is a legal reading ("has the anchor
  ceased trading?") that an occupancy percentage only approximates. A system abating on its own
  reading would be wrong in exactly the cases that matter — a temporary closure, a replacement
  anchor mid-fit-out — and each error is a credit the operator has to claw back from a tenant who
  has already banked it.
- It is the shape every other contingent charge here already has: a violation is recorded and
  billed by a separate act; a percentage-rent overage is locked and then billed.

### Shape

Four typed numbers rather than a JSON blob, because four clauses carry a figure the business
reasons about — the occupancy floor (`threshold_pct`), the sales threshold (`threshold_amount`), the
kilometres (`radius_km`) and the notice (`notice_days`). A number in JSON cannot be filtered or
reported on, which puts it back in prose nobody can query. The form shows only the number the
selected clause type actually carries.

**Dated**, because a clause can lapse — a co-tenancy protection commonly runs for the first years
only. Null on either bound is open-ended, the same convention the charge schedule and the premises
pivot use, and `isInForceOn()` / `scopeInForceOn()` share one definition so a screen and a report
cannot disagree.

`source_reference` is free text ("cl. 14.3", "Schedule 2 §4") because contracts do not agree on a
numbering scheme, and its job is only to let somebody find the wording without reading sixty pages.
**The signed PDF remains the source of truth**; this is an abstract, and an abstract is allowed to
be shorter than what it summarises.

## CPI escalation, and the index register behind it (2026-08-19)

`escalation_type = 'cpi'` existed from 2024 and the sweep **deliberately skipped it** — there is no
machine-readable Egyptian CPI feed, and inventing an index number is inventing data that a tenant
pays for. That refusal was right. What was missing was somewhere for the real figure to live.

**The benchmark specifies this exactly** *(cited,
[benchmarks/yardi/01](../benchmarks/yardi/01-yardi-lease-administration.md) §4)*: an index-method
escalation carries an **index source**, a **publication lag** and a **base index value**, on top of
the floor and ceiling Atriom already had. Scenario **S4** adds the Egyptian ruling, and it is why
the collar is load-bearing rather than decorative:

> *"In Egypt, where CPI has run 20–35%, a collar is not optional — an uncollared CPI clause is a
> clause no tenant signs. Any CPI work must ship the collar with it, or it is worse than nothing."*

### How it resolves

1. `rent_indices` records what was published: index code, the month it **describes**, the value, and
   the date it became knowable. One value per index per month; a revision is an **edit**, not a
   second row, so the figure a step used stays answerable.
2. On the anniversary the sweep reads the index for `anniversary − escalation_index_lag_months`. A
   clause reading *"the September index, effective 1 January"* is a **four**-month lag.
3. Rate = (that figure ÷ `escalation_index_base_value` − 1) × 100.
4. `RentEscalationService::collar()` clamps it — the same clamp a stated-percentage lease uses.
5. The step walks the identical path from there: anniversary dating, the schedule row, the
   marketing-levy resync. **CPI cannot drift from a stated clause**, because after the rate is
   resolved there is only one path.

### The refusals, which are the point

- **No figure published → the lease is left alone and the anniversary does NOT roll.** The sweep
  runs daily, so the step lands the day the statistic does — Voyager's *"it generates the row when
  the index publishes"*. Rolling the date past an unpublished month would be a year the tenant
  never pays for.
- **No index named, or no base value → skip.** An incomplete clause is not a licence to guess, and
  a zero base is not divided into: an infinite step is not a better answer than none.

### ⚠️ THE COLLAR IS NOT PURPOSELESS ON A FIXED CLAUSE — IT IS INVISIBLE (2026-09-10)

Trello kZ77DQa7 (High) reports Minimum/Maximum increase as having *"no functional purpose"* when
Escalation Type is Fixed %. **The opposite is true, and the truth is worse:**
`RentEscalationService::collar()` deliberately applies to whatever rate is about to be used, so a
stated **10%** under a floor of **30%** steps the rent thirty percent a year, unattended, while
Annual Escalation goes on reading 10.

**Refusing that combination was tried and REVERTED, and the reasons are the useful part.** The clamp
is the documented semantic: `EscalationCollarTest` pins *"caps the increase at the ceiling"*, *"lifts
the increase to the floor"*, *"states the rate it actually applied, not the one on the lease"*, and —
as the control inside its own inversion test — *"equal bounds are a fixed step, not a contradiction"*.
`ServiceChargeEscalatesWithRentTest` pins the collared rate reaching the service charge, and
`docs/qa/scripts/11_leasing_lifecycle.php` has a whole section headed *"the collar clamps a mistyped
rate"*. A refusal broke five regression cases, fatalled the pre-staging QA harness before its summary
ever printed, and — because `LeaseRenewalService` rebuilds every fillable column — made such a lease
impossible to renew.

So the clause stays legal and **the fix is visibility**: the rate field carries a live warning naming
the step the lease will actually take, and the collar's own helper says it OVERRIDES the stated rate
rather than *"the increase never falls below this"*, which is vacuous when the increase is constant
and is precisely how the field came to be read as pointless. Same answer as the term-vs-expiry card
on the same board: show the truth, do not force the values.

### ⚠️ A MINIMUM LATE FEE ABOVE ITS CAP IS REFUSED (Trello H22OkiFa, High, 2026-09-10)

The sibling card, and the one place the pair really is unsatisfiable. `LateFeeService` applies
`max($min, …)` and then `min($fee, $max)`, so a minimum of 1,000 under a cap of 100 charges 100 —
the tester's own words, *"no single fee value can satisfy both rules"*. Unlike the collar, nothing
documents a meaning for it: a minimum that can never be reached is not a term.

Refused on the MODEL, asked of the **resolved** clause rather than the two columns, because these are
three-tier settings and a lease stating only a minimum, above a cap it inherits from the property, is
the same contradiction. **Zero is NO CAP at every tier** — the meaning every install had before the
column existed — so it can never be the smaller bound, which is why the form's inline rule is a
closure rather than a plain `gte()`.

**On UPDATE only, and that is deliberate.** The create doors are the lease form, which carries the
inline rule, and the services that COPY an existing clause — `LeaseRenewalService` rebuilds every
fillable column, so without this a lease already carrying the contradiction could not be renewed at
all, refused with a message about late fees. `LeaseImporter` carries no late-fee columns, so an
import reaches neither guard.

**The clamp ORDER is untouched and still correct.** `LateFeeCapAndDepositDefaultTest` pins that the
cap wins, and it must: data written before this guard still has to resolve to something. That
fixture now builds the contradictory clause past the entry guard and says why — the refusal and the
resolution are complements, not alternatives.

**Still open, found and not fixed:** `billing.late_fee_minimum` and `late_fee_maximum` are also
portfolio and per-property settings, and neither the Settings screen nor Property overrides carries
a cross-field rule — so the state can be recreated one tier up, silently, for every lease that
inherits it. The refusal even tells the operator to lower a minimum that may live there. It wants
its own change. (`ALeaseClauseCannotContradictItselfTest`, six teeth mutation-proved.)

### ⚠️ TWO TERMS THAT ARE LEGAL, SURPRISING AND WERE SILENT (Trello M6scQfGu + pqvmFQa9, Medium, 2026-09-10)

Both arrived from the tester as QUESTIONS rather than defects — *should possession have to come
before rent commencement?*, *is a 24-month deposit on a 12-month lease valid?* — and the answer to
both is the same as the escalation collar and the term/expiry pair on the same board: **legal, so
warn rather than refuse.**

**Rent starting before possession.** The asymmetry decides it. The date rent starts DRIVES billing —
`firstBillableMonth()` opens there and `graceAbates()` decides what the fit-out grace covers — while
`possession_date` is computed on by NOTHING: a full sweep of `app/`, `database/`, `routes/` and
`resources/` finds it only in `Lease::$fillable`, `$casts` and `RENEWAL_RESETS`. So the wrong order
costs no money and breaks no rule, and it is a real thing an operator must be able to record: a
landlord who handed over LATE has rent running from a day the tenant could not trade, which is the
basis of the relief claim that follows. Yardi does not enforce it either — its lease-administration
model calls rent commencement *usually* after possession, describing the ordinary deal rather than
constraining the record.

**The note reads the date rent ACTUALLY starts, and that is the whole check.**
`rent_commencement_date` is nullable and **blank is the ordinary state** — it means no grace, so
billing opens at commencement (`DemoSeeder` leaves it null on three leases in four and says in
writing that this *"is the normal case"*). A note reading only the grace field was therefore silent
on most of the book while firing on the one spelling where an operator had set that field equal to
commencement: **the same situation, written two ways, warned in the rarer one.** It is
`rent_commencement_date ?: commencement_date`.

**A deposit longer than its term.** Twenty-four months' security on a twelve-month lease is unusual
and entirely real — a weak covenant, a new foreign brand, a first-time operator — and neither Yardi
nor MRI constrains the deposit against the term. Nothing downstream misbehaves: the months are a
multiplier on the rent and `security_deposit` is the sum actually held.

**Three clauses, and two of them exist to keep it QUIET.** The SUM has to be known — the deposit is
derived from the rent, so on a create form where no rent has been typed it is still 0, and this
repo has already recorded what a warning naming zero money reads as (the unallocated-entries
notice): not a caution, a broken field. And **exceeding the term is not on its own remarkable**: a
kiosk or seasonal pop-up let for one month against the house default of three months' security
exceeds its term threefold, and the operator typed nothing. What is startling is more than a YEAR's
rent held (`LeaseForm::DEPOSIT_MONTHS_WORTH_SAYING`), which no house default reaches and which is
what a term keyed into the months box, or 24 for 2.4, produces. The tester's own case is the
boundary and fires. The note quotes the **SUM**, because that is the figure the tenant is asked to
hand over.

**`hintColor` also paints the field's question-mark icon** — `HasHint::setUpHint()` builds that icon
with `->color(fn () => $parentComponent->getHintColor())` — so an unconditional amber marks the
informational hint on every ordinary lease as though something were wrong. Both fields carry such an
icon; the three older warning hints in this form carry none, which is why the file's own precedent
never showed it. Colour and text read ONE predicate each.

**`possession_date` had to become `->live()`**, or the note is invisible in exactly the direction it
exists for. Filament binds a field DEFERRED unless told otherwise, so typing possession onto a lease
that already carries its rent dates never round-trips and never re-renders — and a `hint()` is not
validation, so Save says nothing either. **No Livewire test can see that**: `fillForm()` fires the
update hook for every key it sets, so under the harness every field behaves as though it were live.
It is asserted on the component (`isLive()`), which is the only layer that can.

**The note deliberately still fires on an INVOICED lease**, where the term/expiry note beside it
bails: both dates it reads are locked there, but `possession_date` stays editable, so the correction
it asks for is still available.

(`ALeaseSaysWhenItsTermsLookWrongTest` — seven teeth mutation-proved, and half the file is controls,
because a note on a correct form is read as an error and then ignored on the form where it matters.)

### ⚠️ A LEASE SIGNED BEFORE IT STARTS IS `future` — Yardi's sixth status (2026-09-10)

Voyager's lease status runs **Prospect → Applicant → Future → Current → Notice → Past**. Atriom had
five of the six: `draft`/`pending_approval` cover the first two, and `expired`/`renewed`/
`terminated`/`cancelled` split *Past* more finely than Voyager does — which is the better model and
stays. **Future was missing**, and its absence cost money.

**The money defect.** A renewal is normally negotiated months before the term ends.
`LeaseRenewalService` stamped the original `renewed` — TERMINAL, and outside `BILLABLE_STATUSES` —
the instant the renewal was signed, while the successor sat at `active` with a commencement still
ahead. Neither billed the gap. Measured: renewing in September a lease expiring 31 December left
**October, November and December uninvoiced** on a shop still trading. `billing:scan-unbilled-periods`
— the weekly safety net built for exactly this — was structurally unable to report it, because it
only reports months a BILLABLE lease missed.

**The occupancy defect, same cause.** A lease keyed today to commence in 60 days read `active` from
the day it was typed, so its unit read `occupied` and the mall's occupancy went 0% → 10.4% for a shop
nobody was trading from and nobody was paying for.

**`Lease::executedStatusFor()` is the ONE definition.** An operator declares a deal EXECUTED and the
calendar answers whether that means `active` or `future`, so nobody DECLARES a lease future — it is a
{@see ProjectedState} projection, like `expired` at the other end of the same term. Derived on the
WRITE (on the model, so the wizard, the renewal, the form and the importer all inherit it rather than
each remembering a date comparison) **and** swept by `leases:expire`, because the morning a term
starts is a day on which nothing is written. Both halves are needed: the sweep alone leaves a lease
keyed today reading `active` until 05:15 tomorrow.

**`future` IS billable, for an ordering reason that is load-bearing.** The billing job runs at 02:00
and the sweep at 05:15, so on the morning a tenancy opens the lease is still `future` when billing
asks — excluding it would lose the **first month of every lease keyed in advance**. Admitting it says
no more than the date clauses already say, exactly as the comment admitting `expired` argues.

**The renewal now happens once.** `LeaseRenewalService` stamps `renewed` only on a term that has
ALREADY run (LE-04); otherwise `leases:expire` writes it on the day the term ends, derived from the
successor, where `expired` and `terminated` are already decided. And `canBeRenewed()` gained an
explicit uniqueness guard — **nothing ever enforced that a tenancy is renewed once**, because the
`renewed` stamp was doing it implicitly; the moment it moved, a double-clicked *Renew* produced two
successors on one tenancy. A CANCELLED successor does not count, or a renewal that fell through would
leave the lease permanently un-renewable.

**THE REVIEW IS THE STORY OF THIS CHANGE.** `active` was an allow-list value read by ~50 sites and
the first pass updated **two**. The adversarial pass found the rest, and the worst was the invariant
this module exists to protect: `Unit::isActivelyLeased()` — one of the three
`ConcurrencyPolicy::AUTHORITATIVE_GUARDS` — queries `where('status','active')`, so it stopped seeing
a future holding entirely. Measured: **two leases on one shop, thirteen months of overlap, both
billing**, the unit reading `reserved` throughout, and `LeaseDoubleBookingTest` red in the working
tree. Its own docblock had argued the case in writing — *"a **future**-dated expansion has already
spoken for the unit… letting a second lease take it in the gap is exactly the double-booking this
guard exists to stop"* — using the word for a state the query then excluded.

**Two named lists exist so this cannot drift again**, and they answer different questions:
`Lease::HOLDS_PREMISES` (`active` + `future`) is *does a signed lease hold this shop* — the
double-booking guards, the re-let check, `Tenant::activeLeases()`, `Unit::activeLease()`;
`Lease::OPEN_TO_COMMERCIAL_ACTS` (+ `pending_approval`) is *may this lease be acted on* — the fifteen
sites that carried the literal `['active','pending_approval']`, every one of which already admits a
lease merely AWAITING APPROVAL, so refusing one that is signed and dated to open was incoherent.
Billing the security deposit is the clearest case: a PRE-HANDOVER act that had become unreachable
until the day the tenancy started.

Also corrected by the same sweep: straight-line rent accrued on a population cash rent billed (a GL
divergence in month one), the manual *Generate Invoice* button was hidden on a lease the batch run
bills, the rent roll's own *"not yet commenced"* footnote became structurally always 0, the 24–60
month revenue forecast dropped every signed deal, option windows on a future lease were never scanned
(an unrecoverable deadline), and the leasing-pipeline widget would have shown signed deals **nowhere**.

(`ALeaseSignedBeforeItStartsIsNotYetRunningTest` — 16 cases, 8 mutations. Note the honest one:
removing `future` from EITHER of `Unit::recomputeStatus()`'s two lists leaves it green, because both
answer true for a lease whose pivot rows carry no dates; they separate only on a future-DATED pivot,
which `LeaseSpaceChangeService` alone produces. Removing it from both goes red.)

### A falling index does not cut the rent, and does not move the base

The clause says the rent increases by the index movement; nothing in it says it decreases. So a
negative movement is skipped — and the base **stays where it was**. That second half is the part
worth stating: if the base rolled down to the trough, the following year would charge the tenant
for the index merely recovering ground the landlord never gave up. A real over-charge, arriving a
year late, out of a year in which nothing appeared to happen.

The anniversary DOES roll on a fall, unlike an unpublished month — a flat year is a year that
happened, whereas a missing statistic is a year not yet answerable.

### Two decisions worth knowing

**The base ROLLS FORWARD** on each application, so year two measures year-on-year rather than
cumulative-since-commencement. Voyager offers both readings; this codebase already resolves
compounding one way ("a percentage step multiplies the current rent"), and two opposite conventions
under one word is how an escalation type comes to mean something nobody agreed.

**`escalatesContractually()` changed.** A CPI lease used to count as configured only if someone had
typed an `escalation_rate` — the sole way an index clause was expressible when none could be
applied. It now counts when it names an index and a base. **The rate-only shape still counts**,
deliberately: those leases could never escalate anyway, and the `saving` hook CLEARS the escalation
terms of an unconfigured lease, so treating them as unconfigured would wipe an anniversary an
operator had recorded. Left armed and still inert, exactly as they were, until someone names the
index.

The register is at **Leasing → Rent indices**, maintained by `leasing` (the person reading the
CAPMAS release administers the leases that follow it); `accounting` sees it read-only, because an
escalation shows up in their books as an ordinary rent change and they need the figure behind it.
**Nothing is seeded on a real install** — an empty register is the correct starting state for a
system that refuses to invent figures; `DemoSeeder` carries a plainly-demo series.

## 1. Purpose & business context

Leases model the core revenue instrument of Egyptian mall operations. They bind tenants to units (retail spaces) for a fixed term, specify monthly rent and service charges with embedded VAT rules, enable percentage-of-sales rent triggers, and track the full lifecycle: draft negotiation → active occupancy → renewal or expiry → termination. A tenant may hold multiple single-unit leases across a mall; a single lease may span multiple units (multi-unit lease). Operators (Eltizam department) manage creation, renewal, termination, and rent escalation; owners (Jawad) and the accounting department oversee invoicing and payment via the linked Charge and Invoice modules.

## 2. Domain model

| Table | Model | Key Columns | Meaning |
|-------|-------|-----------|---------|
| `leases` | `Lease` | `reference` (string, unique) | LSE-{ASSET_CODE}-{YEAR}-{SEQ_NUM}, e.g. "LSE-HW-2026-0001". Generated by `Lease::generateReference()`. |
| | | `unit_id` (FK → units, NOT NULL) | Foreign key to the master unit; denormalized pointer to `units.id` for fast lookups and backward compatibility. Always mirrors the `is_master=true` row in `lease_unit` pivot. Scoped by `ScopesViaProperty` trait in Filament. |
| | | `tenant_id` (FK → tenants, NOT NULL, RESTRICT) | Tenant occupying the lease. Cannot be orphaned. |
| | | `previous_lease_id` (FK → leases, nullable, NULL ON DELETE) | Points to the prior lease if this is a renewal. Enables the lease chain: original → renewal → next renewal. |
| | | `status` (enum) | One of: `draft`, `pending_approval`, `future`, `active`, `expired`, `renewed`, `terminated`, `cancelled`. Default `draft`. Drives unit occupancy projection (see § 4). `future` = signed, term not started — DERIVED from the commencement date, never declared. |
| | | `commencement_date` (date) | Start of lease term. |
| | | `expiry_date` (date) | End of lease term (inclusive). Calculated on creation: `commencement + term_months - 1 day`. |
| | | `expiry_reminder_notified_at` (timestamp, nullable) | Idempotency stamp for the tenant lease-expiry reminder (`leases:remind-expiring`); NULL until the tenant has been reminded once for this lease's expiry. |
| | | `term_months` (unsigned small int) | Contract duration in months (1–120). |
| | | `base_rent_monthly` (decimal 12,2) | Monthly rent amount (EGP), before VAT. Core revenue stream. Read-only on edit; changed via `LeaseRentChangeService::apply()` to keep `Charge.amount` synchronized. |
| | | `rent_pricing_basis` (string, NOT NULL, default `flat`) | How the rent was priced: `flat` (a typed monthly amount) or `rate` (EGP/m²/year). `flat` is the column default, so no lease written before LS-04 re-prices. |
| | | `base_rent_rate_per_sqm_year` (decimal 12,2, nullable) | The contracted rate, where the lease is priced per m². `Lease::deriveBaseRentFromRate()` turns it into the monthly figure; the model enforces it on save so no writer can drift. |
| | | `service_charge_monthly` (decimal 12,2) | Monthly service charge (EGP), VAT-applicable (14% in Egypt). Default 0. |
| | | `has_marketing_levy` (boolean, NOT NULL, default **true**) | Whether the tenant pays the marketing-fund contribution (a `marketing` charge = % of base rent, billed monthly). Default true preserves today's behaviour; turn off for tenants who negotiated out. Carried forward on renewal. |
| | | `marketing_levy_rate` (decimal 5,2, nullable) | Per-lease override of the marketing levy %. Blank = the mall default (`MarketingSettings`, 5%). Carried forward on renewal. |
| | | `possession_date` (date, nullable) | When the tenant took the keys and fit-out began — routinely BEFORE the term commences, and the date a handover dispute turns on. Recorded and displayed; deliberately drives no billing, since nothing bills before commencement anyway. |
| | | `rent_commencement_date` (date, nullable) | When rent starts. **Replaced `fit_out_months`** (dropped 2026-08-10): a lease says "rent commences 1 April", not "three months of fit-out", and a whole-month integer could not express a mid-month start. Null = no grace (bills from the commencement month). What the grace abates is `fit_out_scope`. Does **not** carry forward on renewal. A date on or before commencement is treated as no grace, so a mis-key cannot pull the first billable month backwards. |
| | | `billing_frequency` (enum `monthly`\|`quarterly`\|`semiannual`\|`annual`, NOT NULL, default `monthly`) | How often the lease is invoiced. Quarterly/annual leases pay **in advance**: one invoice per cycle covering the whole cycle (each monthly charge × months-in-cycle; rent + service + levy together), on cycle-start months only. Cycles are anchored to the **first billable month** (commencement + fit-out); every cycle is a full N months. **Carries forward** on renewal. |
| | | `currency` (string 3, default 'EGP') | ISO 4217 code (currently always EGP in Egypt context). |
| | | `security_deposit` (decimal 12,2, default 0) | One-time security amount (typically 3× monthly rent). |
| | | `security_deposit_received` (boolean, NOT NULL, default false) | Whether the deposit has been collected. |
| | | `escalation_rate` (decimal 5,2) | Annual rent-increase percentage (0–100, e.g., 7 → 7%). |
| | | `escalation_floor_rate` / `escalation_ceiling_rate` (decimal 5,2, nullable) | **The collar** (الحد الأدنى/الأقصى للزيادة) — the *"greater of CPI or 3%, capped at 10%"* clause. `RentEscalationService::collar()` clamps whatever rate is about to be applied, whatever produced it, so the bounds bite **before** CPI exists: on a `fixed_percent` lease the ceiling is a rail against a mistyped rate (a `70` entered for `7` would otherwise step the rent seventy percent on the anniversary, unattended). Each bound applies only when set — a floor with no ceiling is not a cap at zero. A floor above the ceiling is **refused at the model** (`Lease::saving`), because the ceiling would silently win and the minimum typed would be the one increase that could never happen. |
| | | `escalation_amount` (decimal 14,2, nullable) | The flat monthly increase for a `fixed_amount` lease — *"rent rises by EGP 5,000 a month each year"*, an ordinary anchor-tenant term. Used **instead of** `escalation_rate`, never alongside it; the percentage collar is not applied to it, because a bound stated in percent has no meaning against a step stated in pounds. |
| | | `escalation_type` (string 32, NOT NULL, default `none`) | **Not a DB enum** — it stopped being one on 2026-08-10 when `fixed_amount` was added, and the value set now lives in `App\Support\ValueSets` (`leases.escalation_type`), which refuses an out-of-set value on every model save. The lease form derives its options from that registry, so the picker cannot offer what the model would reject. One of: `none`, `fixed_percent` (escalation_rate %, **auto-applied**), `fixed_amount` (escalation_amount EGP, **auto-applied**), `cpi` (inflation-indexed — **skipped by the sweep until an index feed exists**; no number is invented). Default `none`. |
| | | `next_escalation_date` (date, nullable) | Next scheduled escalation. **Armed automatically on create** by `Lease::creating` = `commencement + 1yr` whenever escalation is configured (`fixed_percent`/`cpi`, rate > 0) — converged in the model so the wizard, standard form, and renewal all set it consistently (before this, NO creation path populated it, so the sweep never fired for a real lease). The daily `leases:apply-escalations` sweep (`RentEscalationService`) applies a due `fixed_percent` increase through `LeaseRentChangeService` and rolls this forward a year — idempotent + lock-safe. `none`/rate-0 leases stay null (never escalate). |
| | | `has_percentage_rent` (boolean, NOT NULL, default false) | Whether sales-based rent (pct rent) applies. |
| | | `percentage_rent_threshold` (decimal 12,2, nullable) | Sales floor triggering pct rent (artificial breakpoint). E.g., 100,000 EGP/month → charge on sales above this. |
| | | `percentage_rent_rate` (decimal 5,2, nullable) | Pct rent rate (0–100, e.g., 8 → 8% of sales above threshold). |
| | | `percentage_rent_calculation_type` (enum, nullable) | `artificial` (threshold-based) or `natural_breakpoint` (% of sales minus monthly base rent, floored at 0). Defaults to `artificial` if null when calculating. |
| | | `payment_terms_days` (unsigned small int, default 7) | Invoice payment due window (7 days = due 1 week after issue). |
| | | `notes` (text, nullable) | Audit trail: appended with termination/rent-change stamps and reasons. |
| | | `metadata` (JSON, nullable) | Flexible key-value store for future integrations. |
| `lease_unit` | (pivot) | `lease_id`, `unit_id` | Links leases to units; supports multi-unit leases. Each lease has ≥1 pivot rows (one per unit). |
| | | `is_master` (boolean, default false) | Exactly one `is_master=true` per lease. The master is the "primary" unit and is mirrored to `leases.unit_id`. |

> **There is no per-lease billing day.** `leases.billing_day` was dropped 2026-08-20 (EG-20) — it
> shipped in the 2024 schema promising "day of month to issue invoice" and was read by nothing for
> the whole life of the system, while being cast as a `date` (so `1` stored *1 January 1970*, not
> *the 1st*). The **one** definition is `BillingSettings::monthly_billing_day`, which
> is now a PER-PROPERTY override — see the note below.
>
> **Per property since 2026-08-21 (M-5).** `billing.monthly_billing_day` is a `PropertySettings::OVERRIDABLE` key, so one mall bills on the 1st and another on the 25th. Both money runs fire DAILY and ask `App\Support\BillingDay` whose day it is — a global `->monthlyOn()` would have made the override a setting the operator saves and nothing can honour. A day past the end of a short month bills on that month's last day.
> > `routes/console.php` turns into the cron expression for the single monthly sweep. What a lease
> *does* carry is `billing_frequency` (monthly · quarterly · semiannual · annual) — **when the cycle
> repeats**, not which day of the month it lands on.
>
> Honouring a per-lease day would not have been a column read: the run is one scheduled sweep over
> every lease, so it would mean per-day cohorts and a reworked idempotency stamp. The question worth
> answering first is per-**property**, which is what a multi-mall operator actually asks for — see
> EG-18 in [EGYPT-MARKET-FIT](../EGYPT-MARKET-FIT.md).

**Relationships:**
- `Lease::unit()` → `belongsTo(Unit::class)` (the master via `unit_id`)
- `Lease::masterUnit()` → alias to `unit()` (semantic clarity)
- `Lease::units()` → `belongsToMany(Unit::class, 'lease_unit')` with pivot `is_master` (all units including master)
- `Lease::tenant()` → `belongsTo(Tenant::class)`
- `Lease::previousLease()` → `belongsTo(Lease::class, 'previous_lease_id')` (points backward)
- `Lease::renewals()` → `hasMany(Lease::class, 'previous_lease_id')` (points forward to all renewals)
- `Lease::charges()` → `hasMany(Charge::class)` (rent, service charge, plus any custom charges)
- `Lease::invoices()` → `hasMany(Invoice::class)` (generated monthly bills)
- `Lease::camAllocations()` → `hasMany(CamAllocation::class)` (CAM expense allocations)
- `Lease::salesDeclarations()` → `hasMany(TenantSalesDeclaration::class)` (sales-based rent triggers)

### `Lease implements BillableAgreement`

A lease is one kind of agreement that raises AR; a **unit ownership** ([plan 08](37-unit-owners.md))
is the other. `App\Contracts\BillableAgreement` is the narrow part that is true of both — who owes
(`billingTenantId()`), in what currency (`billingCurrency()`), on what terms (`paymentTermsDays()`,
`billingCycleMonths()`), for which property (`assetId()`), over what schedule (`charges()`,
`isBillableForPeriod()`), and which column records that this agreement raised the invoice
(`invoiceLinkAttributes()` → `['lease_id' => …]`).

**Lease law is deliberately NOT in that interface** — fit-out abatement, holdover, escalation ladders,
percentage rent, straight-line rent, CAM ceilings. Those stay here, and the services that need them keep
asking a `Lease`. Widening `Lease` to also mean "an ownership" would make every one of those rules answer
*not applicable* at runtime instead of at the type level, which is how a nullable column becomes a bug
report. Five of the eight interface methods already existed on this model with identical signatures,
which is why the seam sits where it does.

## 3. Business rules & invariants

| Rule | Enforcement | Test(s) |
|------|-------------|---------|
| **Unit occupancy is a lease-status projection.** Active lease → occupied. Draft/pending/renewed → reserved. Expired/terminated/cancelled → vacant. Maintenance overrides auto-projection. | `Unit::recomputeStatus()` (called by `LeaseObserver` on Lease create/update). | `LeaseObserverTest::*`, `MultiUnitLeaseDataScenarioTest::projects_*` |
| **Master unit is authoritative & mirrored.** `leases.unit_id` always = the `is_master=true` unit in the `lease_unit` pivot. Single-unit code paths rely on this. | `LeaseObserver::ensureMasterPivot()` syncs the pivot; `Lease::syncUnits()` updates both pivot and `unit_id`. | `MultiUnitLeaseTest::mirrors_single_unit`, `demotes_the_old_master_and_mirrors_*` |
| **Only one active lease per unit at a time.** Prevents double-booking. | Filament form validation + guard in `LeaseCreationService::create()`. | `LeaseForm::unit_id` rule checks uniqueness on status='active'. |
| **Rent charges are VAT-exempt; service charges carry 14% VAT.** Egyptian tax rule. | `LeaseCreationService::seedStandardCharges()` creates: base_rent with `vat_applicable=false`, service with `vat_applicable=true, vat_rate=Vat::standardRate()` (settings-driven). | `LeaseLifecycleScenarioTest::creation_seeds_VAT_exempt_rent_*` |
| **A rate-priced lease re-derives its own rent when the let area moves.** Commercial rent is negotiated per m² almost everywhere; recomputing `area × rate ÷ 12` by hand is how the wrong rent gets billed for the rest of a term. | `Lease::deriveBaseRentFromRate()` is the single authority, enforced in the model's `saving` hook so a form, an import or a future screen cannot disagree with it. `LeaseSpaceChangeService::applyRentChange()` falls back to it when the caller states no rent. A stated `new_total_rent` still wins — a blended rate for enlarged premises is a real negotiation. | `RateBasedRentTest` |
| **Lease.base_rent_monthly & Charge.amount stay synchronized.** Prevents billing-amount drift between UI display and actual invoice generation. | `LeaseRentChangeService::apply()` updates both Lease field AND the matching Charge row(s). Form edit disables rent fields; only the service method changes them. | `LeaseRentChangeService` tests; `LeaseLifecycleScenarioTest::escalation_raises_base_rent_*` |
| **Terminal leases are immutable.** Once `terminated`/`expired`/`cancelled`/`renewed`, a lease's commercial + state fields can't change (only notes/metadata + soft-delete/restore). Stops a terminated lease being re-opened and re-priced via the Edit form. | `Lease::updating` blocks any dirty field outside the allow-list once the ORIGINAL status is terminal (the transition INTO terminal is allowed); `EditLease` halts with a notice. | `Module04LeaseIntegrityTest` |
| **Renewal carries forward the full unit set.** Multi-unit lease renewal does NOT drop additional units. | `LeaseRenewalService::renew()` calls `syncUnits()` with the original's full unit set. | `MultiUnitLeaseRenewalTest::renews_a_multi_unit_lease_carrying_*` |
| **Percentage rent threshold variants:** <br> - **Artificial:** max(0, sales - threshold) × rate. <br> - **Natural breakpoint:** max(0, sales × rate - base_rent). | Calculated at invoice time by `PercentageRentCalculationService`. | `BillingMathTest::test_percentage_rent_artificial_breakpoint`, `test_percentage_rent_natural_breakpoint` |
| **Termination deactivates charges & optionally cancels unpaid invoices.** Prevents recurring billing post-termination. | `LeaseTerminationService::terminate()` sets `Charge.is_active=false` and optionally cancels fully-unpaid invoices (status → 'cancelled', balance → 0). Partially-paid invoices require explicit credit-note reversal. | `LeaseTerminationService` tests |
| **Security deposit is non-binding for invoicing.** It is a field on Lease, NOT automatically deducted from tenant balances; operators issue credit notes if collected. | Manually tracked in notes; `security_deposit_received` flag aids reporting. | Domain rule; design choice for audit clarity. |
| **A lease cannot end before it starts.** `expiry_date >= commencement_date`, on every writer. EQUAL is allowed — a deal that collapses at handover terminates on its commencement date. | `Lease::saving` guards **both** columns (fixing only expiry leaves the same broken state reachable by moving commencement forward). The lease form keeps the stricter `->after()` for NEW leases, where a zero-day term is nonsense; the terminate action carries a matching `minDate`. | `LeaseExpiryNeverPrecedesCommencementTest` |
| **A security deposit cannot be negative.** It is the CONTRACTUAL figure only — the money that moves comes from `deposit_transactions` — so this protects the move-out statement, not a payment. Refused rather than clamped, so a typo is reported rather than hidden. | `Lease::saving`. | `LeaseDepositNonNegativeTest` |
| **An option's notice window must be a window.** `latest_notice_date >= earliest_notice_date` (a null bound is unbounded; a one-day window is a real contract term). An inverted pair is simultaneously never-open and already-closed, so `leases:scan-option-windows` announces the option lapsed having never announced it open. | `LeaseOption::saving` — the model had no `booted()` at all until 2026-08-11; the rule was one `->afterOrEqual()` on the relation manager. | `LeaseOptionWindowTest` |
| **Percentage-rent bands stay inside their bounds.** Breakpoint ≥ 0; rate within 0–100%. A negative rate raises a "charge" that is really a credit, through the same immediate-invoice path as a real overage. | `LeasePercentageRentTier::assertNoOverlap()` (which also carries the overlap + inversion rules). | `PercentageRentTiersAndDeductionsTest` |

## 4. Lifecycle / state machine

| Status | Entry point | Allowed transitions | Exit rule / immutability |
|--------|-------------|-------------------|--------------------------|
| **draft** | New lease created in admin or via `LeaseCreationService`. | → `pending_approval`, `active` (the Activate ACT, or entry where the property gates nothing), `cancelled` | Discarded if not activated; reserved unit if present; `reserved_until` stamped from the property's window and lapsed by `leases:expire`. |
| **pending_approval** (*"Awaiting activation"*) | Entered where the property requires the deposit or cheques before going live (the wizard, or the form) — or a draft the operator promoted. | → `active` / `future` (the Activate act, `leases.activate`, refused until `LeaseActivation::shortfall()` is nil), `cancelled` (a reservation that lapsed) | Reserved unit; does NOT hold the premises against another signer. |
| **active** | The Activate act, or entry on a property whose `lease_activation_requires` is `none` (the shipped default). | → `renewed` (renewal creates new lease), `terminated`, `expired`, `cancelled` | Unit is occupied. Invoices generate. Charges are active. Only one active lease per unit. |
| **renewed** | Triggered when `LeaseRenewalService::renew()` marks original as 'renewed'. | (terminal for original) | Original lease is now closed; the renewal is a new 'active' lease linked via `previous_lease_id`. Unit is reserved (because the renewal—a new active lease—projects it to occupied). |
| **expired** | Manual mark-as-expired or automated task (future). | (terminal) | Unit becomes vacant (unless another non-terminal lease on it). Invoicing stops. |
| **terminated** | `LeaseTerminationService::terminate()` on active or pending lease. | (terminal) | Charges deactivated. Unit becomes vacant (unless another non-terminal lease). Invoices optionally cancelled. |
| **cancelled** | Operator cancels a draft or pending lease, or `leases:expire` lapses a reservation past `reserved_until` with the money still not in. | (terminal) | Unit reverts to vacant (if no other non-terminal leases). |

**Projection rules (Unit status):**
```
foreach lease in unit.allLeases():
  if lease.status == 'active':
    → occupied (STOP; active takes precedence)
  elif lease.status in ['draft', 'pending_approval', 'renewed']:
    → reserved (CONTINUE; check if any active)
  else:
    → vacant (CONTINUE; ignore expired/terminated/cancelled)
```

**Notes:**
- Only `active` status produces occupied units; renewal status is reserved (the new lease is active, not the old one).
- `maintenance` override on Unit prevents any auto-recomputation until manually cleared.
- Lease observers fire on create/update to recompute all attached units (via pivot).

## 5. Services, jobs & scheduled commands

### LeaseCreationService

**Signature:** `LeaseCreationService::create(array $payload): Lease`

**Idempotency:** Not idempotent — creates a new Lease row and seeded Charges on each call.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guard on active-lease uniqueness.

**When it runs:** Called by Filament `CreateLease` page or programmatically.

**Behavior:**
1. Validates tenant mode (existing or create new).
2. Checks for existing active lease on the unit (throws ValidationException if found).
3. Generates unique lease reference (asset code + year + sequence).
4. Computes `expiry_date` as `commencement + term_months - 1 day`.
5. Creates Lease row with status='active' (or as supplied).
> **Term ⇄ expiry derive both ways (2026-08-12).** `commencement_date`, `term_months` and
> `expiry_date` were three independent form inputs, so a lease could be saved as "36 months"
> spanning twelve — and `term_months` is not decoration: it is logged, copied by renewal, and read
> by the option-exercise service, so the disagreement propagated into the next contract. Changing
> the commencement or the term now recomputes the expiry; typing an expiry recomputes the TERM.
> All three stay editable.
>
> The rule lives once, in **`App\Support\LeaseTerm`**: `expiry = commencement + term − 1 day`,
> with month ends **clamped**. Centralising it found a live defect — `addMonths()` overflows, so a
> lease commencing 31 August for six months expired 2 March rather than 27 February, three days
> outside the agreed term. Existing leases keep their stored expiry; only new derivations change.
>
> `LeaseTerm::monthsBetween()` returns null unless the range is a whole number of months, so a
> negotiated end date (aligned to a financial year, or to another tenant's fit-out) is never
> rounded into a tidy term — and an expiry at or before the commencement derives nothing, which
> leaves the `after()` validation rule free to refuse it. See `DerivedDateFieldsTest`.

> **⚠️ …AND THE PAIR THAT CANNOT AGREE NOW SAYS SO (2026-09-10).** Leaving the term alone is right,
> and for two years it was also SILENT: commencement 10 Sep 2026, term 1 month, expiry overridden
> to 1 Oct 2028 saved without a word, under a helper text reading *"Derived from the commencement
> date and the term."* `term_months` is logged on the lease, copied by every renewal and read by
> the option-exercise service, so the contradiction travels into the next contract. The expiry
> field now carries a live **warning hint** naming the date the typed term would have produced, so
> the operator can see WHICH field is wrong rather than only that something is.
>
> **A warning, not a refusal, and not a forced value.** Flooring the term to the whole months the
> range covers (`monthsSpanning()`) was built, measured and reverted: a range SHORTER than a month
> still returns null so the reported defect survived verbatim for a ten-day pop-up let; a
> fifteen-year lease derived 173 against the field's own `maxValue(120)`, turning a wrong save into
> a dead end refused on a number nobody typed; and `LeaseImporter::afterValidate()` defines
> agreement as strict equality, so the form would have written pairs its own importer rejects on
> re-import. The importer refuses the identical pair only because a CSV cannot be asked which of
> the two is wrong — the operator can, which is the whole difference.
>
> **And blurring the term no longer destroys a negotiated expiry.** Livewire's blur modifier commits
> unconditionally and Filament calls `afterStateUpdated` whether or not the value moved, so merely
> clicking into the term field to READ it and tabbing out re-derived the expiry over the date just
> negotiated. It now fires only when the term actually changed — a latent hazard the new warning
> would otherwise have made likely, because the warning points straight at that field.
> (`ALeasesTermNeverContradictsItsDatesTest`, five teeth mutation-proved.)
>
> **The IMPORT obeys the same rule.** It took a commencement, an optional expiry and an optional
> term with no relationship between them, so the bulk path could create the disagreement the form
> prevents — a hundred rows at a time. It now derives whichever is missing and **refuses a row where
> both are present and disagree**: neither can be preferred, because the expiry is a contract date
> and the term describes it, so a failed row names the problem while the CSV is still open. Where a
> bespoke end date is not a whole number of months, `term_months` (a NOT NULL column) takes the
> whole months the range covers via `LeaseTerm::monthsSpanning()` — never null.
>
> `App\Support\DerivedFields` + `DerivedFieldsConformanceTest` keep this from decaying: a new
> screen exposing all three fields as inputs must be classified, or the build fails.

6. Seeds two standard Charges: base_rent (VAT-exempt) and service_charge (VAT at the standard rate — the `VAT_14` tax code's current rung, 14% today).

**Related:** `LeaseCreationService::seedStandardCharges()` (static) — idempotent seed of rent + service-charge pair; skips if Charges already exist; used by CreateLease page afterCreate.

---

### LeaseRenewalService

**Signature:** `LeaseRenewalService::renew(Lease $original, array $data): Lease`

**Idempotency:** Not idempotent — creates new Lease, marks original as 'renewed'.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards original must be status='active'.

**When it runs:** Called by Filament bulk action or programmatically.

**Behavior:**
1. Validates original lease status is 'active'; throws InvalidArgumentException if not.
2. Parses new term months, rent, service charge (defaults to original if omitted).
3. Computes commencement (defaults to day after original expiry) and new expiry.
4. Creates new Lease row linked via `previous_lease_id → original.id`, with status='active'.
5. Syncs all units from original (including additional units): `syncUnits()` with master preserved.
6. Duplicates all Charges from original, updating base_rent and service_charge amounts to new values.
7. Marks original as status='renewed'.

**Critical fix:** Carries full unit set (not just master); regression test in `MultiUnitLeaseRenewalTest`.

---

### LeaseTerminationService

**Signature:** `LeaseTerminationService::terminate(Lease $lease, array $data): Lease`

**Idempotency:** Not idempotent — updates lease and deactivates charges.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards lease must be status='active' or 'pending_approval'.

**When it runs:** Called by Filament edit page action or programmatically.

**Behavior:**
1. Validates lease is active or pending; throws InvalidArgumentException if not.
2. Parses termination_date (defaults to today), reason, and cancel_open_invoices flag.
   - **The date cannot precede the lease's commencement** (validation sweep, 2026-08-11). This
     service writes the operator's date straight onto `expiry_date`, and until the model guard
     landed neither it nor its DatePicker constrained it at all — a mis-keyed year produced a lease
     that reads expired while active, that `activeInPeriod()` can never match (so it bills nothing
     ever again), and charges stamped `end_date` before their own `start_date`. The refusal is
     `Lease::saving`, so it also covers a programmatic call; the action carries a matching
     `minDate`. Terminating ON the commencement date is allowed.
3. Updates Lease: status='terminated', expiry_date=termination_date, appends reason to notes.
4. Deactivates all Charges: is_active=false, end_date=termination_date (stops monthly billing).
5. Optionally cancels unpaid invoices (status in [draft, issued, partially_paid, overdue], balance > 0, paid_amount = 0). Sets status='cancelled', balance=0.
   - **Important:** Partially-paid invoices are NOT cancelled (would orphan paid_amount); operator must issue credit notes.
   - **…and only for a period that never happened (2026-08-17).** The filter used to be balance-only,
     so it cancelled every fully-unpaid open invoice on the lease *whatever period it covered* — and
     on a system that bills IN ADVANCE that destroys revenue the landlord already earned. Found by
     running the Chapter 8 exercise on real data: a quarterly lease terminated on 15 November lost
     the Oct–Dec invoice (253,260, of which 126,630 was earned), October's percentage rent (70,000, a
     month entirely in the past) and November's — 463,260 of receivables gone, with the tenant having
     occupied and traded from the space. Step 6 exists precisely to credit the straddling case, so
     cancelling the whole document first left it nothing to credit: the two steps were not merely
     ordered wrongly, **the first made the second unreachable** (which is why no credit note appeared).
     The rule is now the PERIOD, not the balance — starts after the termination → cancel; straddles
     it → leave it, step 6 credits the unearned share; ends before it → leave it owing.
     `TerminationKeepsEarnedRevenueTest` pins all three, and the two older tests that asserted
     cancellation now state their termination date explicitly, so the period rule cannot decide an
     outcome their claim (ETA filing / partial payment) is really about.
6. Optionally credits the unearned share of a straddling invoice (`credit_unearned`, default **true**).
   - The toggle is now **on the terminate modal**. `terminate()` had read `credit_unearned` since
     phase 4, but no screen ever sent it — the opt-out the docs described was unreachable, and an
     operator who had to terminate into a closed period had no way through but to reopen the books.

---

### LeaseRentChangeService

**Signature:** `LeaseRentChangeService::apply(Lease $lease, array $data): Lease`

**Idempotency:** Not idempotent — updates Lease and Charge rows.

**Transaction:** Yes, atomic.

**Locking:** No explicit locking; guards lease must be status='active' or 'pending_approval'.

**When it runs:** Called by Filament edit page custom action (not the standard edit form, which disables rent fields).

**Behavior:**
1. Validates lease is active or pending; throws InvalidArgumentException if not.
2. Parses new base_rent_monthly and optionally new service_charge_monthly; validates ≥ 0.
3. Updates Lease fields and appends reason stamp to notes.
4. Syncs the most-recent active Charge of type 'base_rent': updates amount or creates if missing.
5. If service_charge provided: syncs matching Charge (creates only if amount > 0).

**Why a dedicated service:** Form edit disables rent fields to prevent silent Charge drift. This service keeps Lease and Charge.amount in sync for monthly billing consistency (audit M04 F-20 / D-13).

---

### LeaseObserver

**Fires on:** `created()`, `updated()`.

**Behavior:**
- **created():** Calls `ensureMasterPivot()` (mirrors unit_id into lease_unit with is_master=true) and `recomputeUnits()` (re-project all attached units).
- **updated():** If status or unit_id changed, calls `ensureMasterPivot()` and `recomputeUnits()`. No-op if only other fields changed.

**Idempotent:** Yes; re-applying the same projection is safe.

### Scheduled: lease-expiry reminder (`leases:remind-expiring`)

Daily command (07:00) that reminds the tenant when an **active** lease's `expiry_date` falls within `billing.lease_expiry_reminder_days` (default 90) — email + in-app bell + mobile push, nudging renewal. Idempotent via `leases.expiry_reminder_notified_at` (one reminder per lease; a renewal is a new lease row, so it reminds for its own expiry). Same lock+re-check pattern as the overdue scans. See [19-notifications-scans.md](19-notifications-scans.md) for the notification + `LeaseExpiryApproachingNotification`.

---

## 6. Filament resources & key fields

### LeaseResource

**Location:** `/app/Filament/Admin/Resources/Leases/LeaseResource.php`

**Permission scope:** `leases.*` (view, create, edit, delete, terminate, renew, generate_invoice).

**Tenant scoping:** Via `ScopesViaProperty::tenantScopeRelation()` → `unit` (filters leases by asset of current property).

**Navigation:** Leasing group, sort=4, icon=DocumentText.

**Key pages:**
- `ListLeases` — table with status filters, tenant/unit dropdowns, import/export.
- `CreateLease` — full form (incl. additional_unit_ids multi-select, the *Parking & rentable items* table since 2026-09-12; charges not in form — the seeded rows, the items' holdings and the ladder are written in `afterCreate()` through the services).
- `EditLease` — rent fields read-only; additional_unit_ids prefilled; custom "Generate Invoice" and "Change Rent" actions.

---

### LeaseForm (Schemas/LeaseForm.php)

**Tabbed, not scrolled (2026-08-08).** Thirty fields across six concerns is a scroll, not a form, so
the sections below are now **tabs** — one concern per screen (operator directive; standard recorded
as UX-13 in [the UI/UX benchmark](../benchmarks/yardi/08-yardi-ui-ux.md)). Notes and Documents are
merged into one tab; `persistTabInQueryString()` lets a link point at a tab.

Tabs are built with **`App\Support\FormTab::make(label, [...])`, never a bare `Tab::make()`** —
`FormTab` adds a danger badge counting the validation errors *inside that tab*, because Filament
v4.11.8 has no error indicator on `Tabs` and a required field left blank on a tab you are not
looking at would otherwise refuse the form with nothing visible to fix. The count is derived from
the tab's own fields at render time, so it cannot drift from what the tab contains. Tests:
`tests/Feature/Regression/FormTabErrorBadgeTest.php`.

**Tabs:**

1. **Lease Details** (3 cols)
   - `reference` (TextInput, disabled, dehydrated) — auto-generated, read-only.
   - `unit_id` (Select, live, required) — master unit; filters to non-occupied/non-reserved unless `show_occupied_units` toggle. Validation rule prevents active-lease conflicts.
   - `additional_unit_ids` (Select, multiple, dehydrated=false) — non-master units for multi-unit leases; dehydrated=false (processed in `afterCreate()` / `afterSave()`). Disabled by `Lease::premisesLockedBecause()` — live (use *Change premises*), or a draft that has stepped / carries an act's row — and free on a plain draft, where a change re-prices a rate-priced rent and its seeded rows (2026-09-11).
   - **Parking & rentable items** (Section, create only, hidden while `status` is `draft` — a draft holds nothing, Voyager's own rule): a `Repeater::table()` of items let WITH the lease — the item (the property's free, in-service list, `RentableItemOptions::lettableIn()`, `distinct()`), the negotiated rate (prefilled from the register's asking rate on pick), a from-date (blank = the commencement; not before it) and the annual-increase trio. `dehydrated(false)` — `CreateLease::afterCreate()` lets each row through `AssignRentableItemService::assign()` and NAMES any it could not (a refusal is a warning, never a failed create). The quick wizard's third step is the same builder, dehydrating — an action's `$data` is the dehydrated state — and `LeaseCreationService::create()` assigns them (2026-09-12).
   - `tenant_id` (Select, required, searchable, creatable inline) — with quick-create form (name, phone, email).
   - `status` (Select) — draft, pending_approval, active, etc.
   - `show_occupied_units` (Toggle, live, dehydrated=false) — toggles unit dropdown visibility.

2. **Term** (3 cols)
   - `commencement_date` (DatePicker, required). Disabled once the lease is invoiced (the model refuses the move too, 2026-09-11); on a saved un-invoiced lease its helper says a move re-dates the charge rows anchored on the old date and re-projects the steps — which `Lease::updated` then does.
   - `term_months` (TextInput, numeric, 1–120, default 36).
   - `expiry_date` (DatePicker, required).

3. **Financial Terms** — FOUR SECTIONS since 2026-09-12 (meeting point 24, and the operator's own
   words: *"the lease form looks so bad"*): the tab had grown to thirty-five fields in one
   five-column grid with the escalation's thirteen inputs appearing and vanishing between the
   deposit's and the late fee's as the clause type changed. Now **Rent & service charge** ·
   **Billing** (possession, rent commencement, fit-out scope, frequency, payment terms, proration)
   · **Security deposit** · **Late fees** (collapsed — overrides of the property's terms, blank
   on almost every lease). Yardi's lease screen groups the same facts under headings, and UX-13's
   own rule is one concern at a time. The annual increase moved to its own tab (4 below).
   - `rent_pricing_basis` (Radio: flat | rate; disabled on edit) — choosing `rate` reveals the rate field and makes the monthly figure derived.
   - `base_rent_rate_per_sqm_year` (TextInput, EGP/m²/yr; required + visible only when the basis is `rate`) — the helper text shows the let area the derivation is using, updated live as units are picked.
   - `base_rent_monthly` (TextInput, numeric, ≥0; disabled on edit **and** on a rate-priced lease, dehydrated) — read-only on edit to enforce use of LeaseRentChangeService, read-only on `rate` because it is derived.
   - `service_charge_monthly` (TextInput, numeric, ≥0; disabled on edit, dehydrated) — helper text on edit warns "use Change Rent action".
   - `has_marketing_levy` (Toggle, live, default true) — whether the marketing levy is billed to this tenant. `EditLease::afterSave()` re-syncs the `marketing` charge via `MarketingLevyService::createLevyCharge()` — from `Lease::updated` since 2026-09-11 (the page no longer re-syncs it; the hook does, base row first, then the levy rungs, for every door) — so a toggle change takes effect on the next run.
   - `marketing_levy_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_marketing_levy) — per-lease rate override; placeholder shows the mall default; blank = default.
   - `possession_date` + `rent_commencement_date` (DatePickers) — the handover date and the start of rent. Blank rent-commencement = no grace. The billing gate lives on the model: `Lease::periodInFitOut()` / `firstBillableMonth()` / `rentCommencesOn()`, shared by `MonthlyBillingService` and the ActionRequired "unbilled leases" card (so a lease in grace is neither billed nor flagged).
   - `billing_frequency` (Select: monthly / quarterly / semiannual / annual, default monthly) — the invoicing cadence. The cadence rule lives on the model: `Lease::billingCycleMonths()` (1/3/6/12) and `isBillingCycleStart()` (commencement-anchored, post-fit-out), used by `MonthlyBillingService` (bill the whole cycle on cycle-start months) and the "unbilled leases" card (don't nag off-cycle months). A manual "Generate Invoice" for an off-cycle month returns reason `off_cycle` with a clear notice.
   - `security_deposit` (TextInput, numeric, ≥0).
   - `payment_terms_days` (TextInput, numeric, default 7, suffix ' days').
   - `security_deposit_received` (Toggle, column full).

4. **Annual increase** (since 2026-09-12 — meeting point 24) — two sections.
   - **The rent's clause**: `escalation_type` (Select — none, fixed_percent, fixed_amount, cpi;
     options derived from `ValueSets`; default fixed_percent), `escalation_rate` (0–100, default
     7, with the collar's live "what will actually step" hint), `escalation_amount`, the index
     code / base value / lag for CPI, `escalation_interval_months`, the floor and ceiling. The
     type is asked first and every other field shows only for its own type.
   - **Which charges step**: a TABLE (`Repeater::table()`, not stored on the lease —
     `dehydrated(false)`, read from form state by the page) with one row per recurring charge
     type on the schedule: the charge · its rule (`ChargeEscalation::options()`, whose
     follows-lease option NAMES the percentage it would inherit, read live off the clause fields
     above) · its own % or EGP figure, or a sentence saying what it inherits. On CREATE the only
     row is the service charge the form seeds, proposed from the property
     (`billing.new_charges_follow_escalation`); on EDIT every type the schedule holds, filled by
     `EditLease::chargeEscalationRows()` from the rung in force today and written back in
     `afterSave()` through `ChargeScheduleService::setEscalation()` — only for rows that changed,
     because ruling re-walks that type's ladder. The rent and the levy are never rows: the clause
     and the rent answer for them, and the section says so. **The parking row is a READ-ONLY row
     (2026-09-12)** carrying the register's rules in words (`RentableItemPricing::describeHoldings()`
     — *"P-12 — +EGP 500.00 a year · P-13 — Follows the rent — +7% a year"*), because a bay is
     ruled on PER ITEM on the Parking & rentable items tab; the same sentence that tab's column
     shows. The table REFILLS when a tab announces a change (`EditLease::refreshFormData()`, by
     hand — an array path is invisible to `fillPartially()`), so it never shows a rule a tab
     already changed. The same three fields are also on the schedule tab's own *Annual increase*
     row action and *Add charge* modal, the items tab's *Annual increase* row action and the
     *Let a bay or store* modal — one builder, `App\Support\Filament\EscalationRuleFields`.

5. **Percentage Rent** (3 cols, collapsed, collapsible)
   - `has_percentage_rent` (Toggle, live).
   - `percentage_rent_calculation_type` (Select, visible if has_percentage_rent) — artificial, natural_breakpoint; default artificial.
   - `percentage_rent_threshold` (TextInput, numeric, ≥0, prefix 'EGP', visible if has_percentage_rent).
   - `percentage_rent_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_percentage_rent).

6. **Notes** (collapsed)
   - `notes` (Textarea, 3 rows).

7. **Documents** (collapsible)
   - `documents` (SpatieMediaLibraryFileUpload, multiple, PDF/image/Word, max 10 MB, collection='documents').

---

### CreateLease page

**Behavior:**
- Standard Filament form creates Lease via Eloquent.
- `afterCreate()` hook — **the order is load-bearing** (2026-09-02), because every step is priced
  from the one before it:
  1. Syncs additional units via `Lease::syncUnits()` if `additional_unit_ids` is non-empty, then
     `Lease::repriceFromPremises()` — a rate-priced rent is a function of the let area, and the
     pivot does not exist until this point.
  2. Seeds standard charges via `LeaseCreationService::seedStandardCharges()`.
  3. Projects the whole term's ladder via `ChargeScheduleService::projectTermEscalations()`.

  Running the units last, as it did until 2026-09-02, built the ladder, the marketing levy and the
  deposit from the master unit's area alone.

---

### EditLease page

**Behavior:**
- `mutateFormDataBeforeFill()` prefills `additional_unit_ids` from the pivot (non-master units).
- `afterSave()` syncs the full unit set (master + additional) via `syncUnits()`.

**Custom actions:**
- **Generate Invoice** (action, visible if status='active'): Modal schema collects period (month-picker) and prorate flag, calls `MonthlyBillingService::generateForLease()`.
- **Change Rent** (action, visible if status='active'): Modal collects new base_rent, optional new service_charge, an effective date and a reason; calls `LeaseRentChangeService::apply()`. On a **rate-priced** lease (LS-04) the modal asks for the new **rate** instead of the amount — two editable fields deriving from each other is how they end up disagreeing — and the service re-derives the monthly figure. Where only a rent is stated (the escalation sweep), the rate is re-derived from it, so a 7% step raises both by 7% and the lease never advertises a rate it no longer bills.

---

### LeasesTable (Tables/LeasesTable.php)

**Columns:**
- `reference` (copyable, mono, xs font).
- `unit.code` (badge, gray; description lists additional units: "+ A-02, A-03").
- `tenant.name` (bold).
- `base_rent_monthly` (money EGP, right-aligned, sortable).
- `commencement_date`, `expiry_date` (d/m/Y, sortable; expiry color-coded: red <30d, orange <90d).
- `status` (badge, colored: green=active, warning=pending, info=renewed, danger=terminated/cancelled, gray=other).

**Filters:**
- Status, tenant, unit (relationship dropdowns).
- Commencement/expiry date ranges.
- Trash (soft-delete filter).

**Bulk actions:**
- Export (LeaseExporter).
- Delete, Force Delete, Restore (soft-delete actions; only super_admin).

**Inline actions:**
- Edit, Delete (standard Filament).

---

## 7. Notifications & integrations

**Invoice notifications:** When an invoice is issued from a lease's charges, `InvoiceIssuedNotification` is sent to the tenant (email + WhatsApp).

**Sales declaration notifications:** When a tenant submits a sales declaration, `SalesDeclarationSubmittedNotification` alerts accounting.

**ETA integration:** Invoices linked to leases are submitted to the Egyptian Tax Authority (ETA) via `EtaJsonBuilder` / `EtaIntegrationService`.

**Monthly billing:** `MonthlyBillingService::generateForLease()` creates invoices for active leases' charges on a scheduled run (RunMonthlyBillingCommand) or manually via the EditLease action.

**CAM allocations:** `CamAllocation::allocateTenant()` computes service-charge allocations per lease-unit for CAM reconciliation.

---

## 8. Extension points — how to change/extend SAFELY

### Adding a new lease-level field (e.g., tenant_contact_override)

1. **Schema:** Add column to `create_leases_table` migration or new migration.
2. **Model:** Add to `Lease::$fillable` and `$casts` (if date/decimal/boolean).
3. **Form:** Add input to `LeaseForm::configure()` in the appropriate section.
4. **Validation:** Add rules in the field definition or in a custom Request class if complex.
5. **Tests:** Write a scenario test in `tests/Feature/Scenarios/LeaseLifecycleScenarioTest.php` or a unit test in `tests/Feature/Models/LeaseTest.php`.
6. **Do NOT:** Manually edit rent fields on the Lease record via a standard form — use `LeaseRentChangeService::apply()` to keep Charges in sync.

### Adding a new lease state (e.g., 'on_hold')

1. **Migration:** Update the status enum in `create_leases_table` migration.
2. **Model:** No code change needed (enum is auto-recognized).
3. **Form:** Update the status Select options in `LeaseForm`.
4. **Unit projection:** Update `Unit::recomputeStatus()` match logic if the new status should map to reserved/occupied/vacant differently.
5. **Observers:** Check `LeaseObserver::updated()` to ensure status transitions fire recomputation as needed.
6. **Tests:** Add scenario in `LeaseObserverTest` + `LeaseLifecycleScenarioTest`.
7. **Permissions:** Add new permission entry in `RolesPermissionsSeeder::PERMISSIONS['leases']` if needed (e.g., 'leases.hold').

### Changing escalation logic

The rent's clause is on the lease (`escalation_type` · rate · amount · CPI index/base/lag · interval
· collar) and is applied by `RentEscalationService` (the nightly `leases:apply-escalations` sweep)
and projected up front by `ChargeScheduleService::projectTermEscalations()`; every OTHER charge's
rule is on its own rows (`charges.escalation_mode` + figure, `App\Support\ChargeEscalation`).

1. **A new MODE** (say, "follows the rent by half"): add it to `ChargeEscalation::MODES`, teach
   `stepFor()` what it resolves to, add its label to `admin.charge_escalation.modes` in BOTH lang
   files, and `describe()` for the schedule tab. The sweep, the projection, the form and the
   importer read the registry and need no change. `ValueSets` refuses an unregistered mode.
2. **A new TERM every successor rung must inherit** (a cap per charge, say): a column on
   `charges`, added to `Charge::CARRIED_TERMS` — that ONE list is what `setAmount()`,
   `overlayWindow()` and the renewal spread, so a term added there reaches every writer.
3. **A new lease-level CLAUSE term the ladder depends on**: add it to `Lease::LADDER_TERMS` so an
   edit re-trues the ladder (the hook asks `wasChanged()` of the list).
4. **Invoices:** every step takes effect on the next invoice — billing reads the ladder's
   `Charge.amount`, never the clause.
5. **Do NOT:** edit `Lease.base_rent_monthly` or `service_charge_monthly` without the matching
   schedule row — `LeaseRentChangeService` and `setAmount()` are the writers; and do NOT step a
   charge from a lease column — the sweep sizes every step from the SCHEDULE, because the tab can
   end or restate a charge without touching the column.

### Supporting multi-unit lease rent differentiation (per-unit rents)

**Current design:** Single base_rent_monthly applies to all units in the lease. Since LS-04 a lease may
instead be priced at EGP/m²/year, which covers the common case — the money follows the summed area,
so adding or giving back a unit re-prices the lease without anyone allocating anything per unit.
Genuinely *different* rates for different units in one lease still need the work below:

1. Add `lease_unit.rent_allocation_factor` (decimal) column — proportional share of base rent per unit.
2. Modify `LeaseCreationService::seedStandardCharges()` or create a new `allocateChargesPerUnit()` method to split the base-rent Charge across units proportionally.
3. Modify `MonthlyBillingService` to read per-unit charges when invoicing.
4. Update `LeaseRentChangeService` to re-allocate Charges per unit on rent change.
5. Update tests to assert per-unit charge splits.

**Caveat:** This breaks the single Charge.amount model; the current design assumes one rent Charge per lease that applies to all units equally.

### Adding a percentage-rent variant (e.g., tiered thresholds)

1. Extend the `percentage_rent_calculation_type` enum (or add a new field like `percentage_rent_tier_type`).
2. Implement new logic in `PercentageRentCalculationService::calculate()`.
3. Extend `LeaseForm` to expose new tier fields conditionally.
4. Write test scenarios in `PercentageRentScenarioTest.php`.

### Handling lease conflicts during multi-unit sync

`Lease::syncUnits()` is idempotent: it diffs the supplied unit IDs against the current pivot and recomputes occupancy for all affected units. If a unit is already occupied by another active lease:

- The pivot uniqueness constraint (`UNIQUE (lease_id, unit_id)`) prevents duplicate attachments.
- But there is no guard against attaching a unit that is already occupied by another lease's active status.
- **Do NOT** call `syncUnits()` without first checking that all units are available (not occupied by another active lease).

## 9. Gotchas, edge cases & recently-fixed bugs

### The 2026-08-29/30 lifecycle sweep — ten defects, all found by driving the panel

A full tenancy was run end to end on the demo books — let, re-priced, abated, deposit billed and
received, terminated, settled — and every screen, action and tab was driven in a browser
(`tools/leasing-sweep.mjs`). The suite was green throughout; none of these was visible to it.

They share a shape worth naming: **not one is a broken component.** Each sits at the seam between
two pieces that are individually correct, and each fails SILENTLY — a lease that bills nothing
looks exactly like a lease with nothing due, which is why they survived.

| What broke | The seam |
|---|---|
| Terminate opened with "credit back unearned rent" OFF | `fillForm()` sets the WHOLE state, so a field it omits loses its own `->default()` |
| A deposit invoice suppressed every month of rent for the term | `security_deposit` missing from `STANDALONE_ITEM_TYPES`, and the deposit invoice is dated to the whole term |
| A renewal's service charge reached the lease and not the schedule | `renew()` CARRIES rows; a stated figure with no row to carry produced none |
| The holdover rate was labelled as its own inverse | Code computes `rent × pct / 100`; `admin.fields.*` called it an "uplift %" — threefold, and undercharging |
| A negative rent produced a lease that bills nothing | `if ($rent > 0)` writes the schedule rows; the model had no guard |
| A deposit could be billed twice | `depositShortfall()` answers "are we short", which is not "should we ask again" |
| The lease history said nothing about the day it ended | Only the final account and a break option ever wrote a `termination` event |
| A tenancy owing more than its deposit could not be settled | "Is there anything to settle" was asked AFTER the arrears consumed the deposit |
| A lease under notice stopped billing immediately | `status = 'terminated'` and every charge deactivated on the day notice was given |
| The termination re-opened closed rungs of the rent ladder | A blanket `update()` of `end_date`, invisible while the same statement deactivated everything |

**Two of these were introduced by the fix before them**, and both surfaced only on real data — the
ladder one because `atriom:audit-charge-schedules` reported every schedule unambiguous at the moment
the billing run refused one, and that disagreement pointed at the new code rather than at the books.

**What the sweep also proved correct**, by hand against Yardi's rules: all four proration methods
(and a full month = exactly 1.0 under each), all four escalation types including a percentage collar
that correctly does NOT apply to an amount-stated step, five CPI scenarios including "the index has
not published, so wait rather than invent", both percentage-rent bases at and around the breakeven,
straight-line rent netting to exactly zero over the term, and all four billing frequencies.

---

### Bug: Renewal silently drops multi-unit leases' additional units (FIXED)

**Issue:** `LeaseRenewalService::renew()` previously carried only leases.unit_id, dropping additional units from multi-unit leases.

**Fix:** Now calls `syncUnits()` with the original's full unit set (from pivot), preserving the master.

**Test:** `MultiUnitLeaseRenewalTest::renews_a_multi_unit_lease_carrying_the_full_unit_set_*`

**Lesson:** Always copy the full unit set on renewal, not just the master.

---

### Bug: In-memory has_percentage_rent null on renewal without fresh()

**Issue:** `LeaseCreationService::create()` omits `has_percentage_rent` in the payload, so the returned Lease instance has null in memory (even though the column NOT NULL defaults to false in the DB). If renewal is called on that instance without `fresh()`, null propagates into the renewal's non-nullable column.

**Fix:** Model defaults `has_percentage_rent => false` in `$attributes`, so the in-memory value is never null. `LeaseLifecycleScenarioTest` now calls `fresh()` on service-created leases to mirror production behavior (admin panel re-fetches before follow-up actions).

**Test:** `LeaseLifecycleScenarioTest::renews_a_service_created_lease_without_the_has_percentage_rent_NOT_NULL_crash`

**Lesson:** Always re-read (fresh()) service-created models before cascading operations, or ensure model defaults cover all NOT NULL columns.

---

### Legacy charge rows: the schedule rollout's blind spot

**Issue (LS-06):** phase 1 made the charge schedule authoritative, and
`MonthlyBillingService::assertScheduleUnambiguous()` now refuses two active rows of the same type
covering one period. Under the old model that shape billed **both** rows — a quiet over-bill. Under
the new one the refusal is caught and reported, so the lease produces **no invoice at all**: quieter,
and worse. `Charge` also gained a model-level overlap guard, so the shape can no longer be created —
which confines the hazard to rows already in the database when phase 1 shipped, and means nothing in
the code path will ever surface them.

**Run `php artisan atriom:audit-charge-schedules` before a deploy and after every data import.** It
is read-only and exits non-zero on any finding, so it can gate a pipeline rather than be a report
someone remembers to read. It reports overlaps (bills nothing), gaps (a month with no rent line) and
undated rows (harmless to billing; inconsistent to sort).

**A null `start_date` bills identically to a commencement-dated one** — `chargeAppliesToPeriod()`
skips the comparison entirely when the column is null. The LS-06 migration stamps it anyway, because
null sorts first on MySQL and last on SQLite, so "the row in force" could answer differently on the
two databases we run. `end_date` is deliberately left open: Atriom bills holdover from the same
rows, so stamping the expiry would stop the rent the day the term ended.

### Rent change must stay atomic with Charge sync

**Issue:** Audit M04 F-20 / D-13 identified drift between Lease.base_rent_monthly and Charge.amount when rent was changed via the standard edit form (which disabled the fields but allowed the form to still write them in the background).

**Fix:** Rent fields are now read-only on edit. The dedicated `LeaseRentChangeService::apply()` updates both Lease and Charge(s) in a single transaction. The form disable + dehydrated flags enforce this.

**Test:** `LeaseRentChangeService` tests; `LeaseLifecycleScenarioTest::escalation_raises_base_rent_*`

**Lesson:** Any time Lease affects a derived Charge, use a dedicated service with explicit transaction guards.

---

### Termination of partially-paid invoices requires explicit credit-note handling

**Issue:** If a lease is terminated and the operator chooses to cancel open invoices, partially-paid invoices (paid_amount > 0, balance > 0) are NOT cancelled. Cancelling them would orphan the paid_amount.

**Design:** Only fully-unpaid invoices (paid_amount = 0, balance > 0) are auto-cancelled. Operators who want to void a partially-paid invoice must:
1. Issue a credit note for the balance.
2. Manually mark the invoice as cancelled.

**Test:** `LeaseTerminationService` tests verify this guard.

**Lesson:** Termination actions must respect the AR ledger; never orphan payments.

---

### Bug: two concurrent requests could double-book a unit (FIXED 2026-07-30)

Two active leases on one shop is the single thing this module's invariants exist to prevent — it
bills the shop twice a month, gives two tenants a claim on it, and corrupts every occupancy figure
the owner sees.

**`LeaseRenewalService` was the hole.** Its `status === 'active'` guard sat *outside* the
transaction with no lock, so two requests that each loaded the lease before either committed —
a double-clicked "Renew", two admins, a retried POST — both passed it and both created an `active`
renewal. Reproduced: the unit was left carrying **two active leases** with the original in
`renewed`.

**`LeaseCreationService` had the same shape, weaker.** Its `isActivelyLeased()` guard is inside the
transaction, but it read the unit with a plain `find()`. Under MySQL's REPEATABLE READ a snapshot
read cannot see another transaction's uncommitted lease, so two concurrent creates on one unit both
find it free — and there is no unique constraint to catch the loser.

**The fix:** both services now `lockForUpdate()` **the unit row** before checking. Occupancy is the
contended resource, so every path that can put an active lease on a unit contends on the same row
and they serialise against each other, not merely against themselves. Renewal additionally
re-reads and re-checks the original lease under its own lock.

Adding a third activation path? Take the unit lock. `LeaseDoubleBookingTest` asserts every one of
these services still calls `lockForUpdate` — a sequential test cannot reproduce a race, but it can
hold the line that the lock protecting against it is still there.

The `LeaseForm` `unit_id` rule (pivot-aware, excludes self) remains the UI-level guard; it is not a
substitute for the service lock, because it validates before the write and outside the transaction.

---

### Multi-unit occupancy: unit is occupied if ANY attached lease is active

**Concurrency:** If a unit is part of multiple leases (which should not happen in normal flow due to the active-lease guard, but is theoretically possible if the guard is bypassed), the occupancy projection queries all leases on the unit and sees if any are active. One active lease is enough to mark occupied.

**Idempotence:** `Unit::recomputeStatus()` is idempotent: applying it multiple times is safe. Observers ensure it fires on every lease change.

---

### Percentage rent calculation type defaults to 'artificial' if null

**Migration:** An older lease might have `percentage_rent_calculation_type = null`. When `PercentageRentCalculationService::calculate()` runs, it treats null as 'artificial'.

**Future-proof:** New leases always set a non-null type via the form default (artificial).

---

### Escalation DOES auto-apply (this section used to say the opposite)

`RentEscalationService`, driven by the scheduled `leases:apply-escalations`, sweeps active leases
with `next_escalation_date <= today` and applies the increase through `LeaseRentChangeService`
(so the base-rent Charge and the marketing levy stay in lock-step), then rolls
`next_escalation_date` forward **by the clause's own interval**.

- **The interval is the clause's, not a literal year (EG-30 / M-6, 2026-08-22).**
  `leases.escalation_interval_months` is nullable and **null means twelve**, so every lease that
  existed before this keeps escalating annually and the sweep is behaviour-identical on deploy. A
  biennial clause, an 18-month step or the six-monthly review that goes into a short fit-out lease
  is now a number on the lease rather than something an operator has to remember — which is what
  actually happened, and is how a step comes to be missed for a year.
  `Lease::escalationIntervalMonths()` is the single definition and floors a 0 at one month, because
  rolling the date nowhere would make the sweep reconsider the same lease every day for ever with
  nothing on screen to say so.
  - **`Lease::escalationDateAfter()` is the ONE roll, and THREE callers need it** — the sweep, the
    `Lease::creating` hook that ARMS the first date, and `ChargeScheduleService::projectTermEscalations()`,
    which writes the contracted ladder an operator reads. The first cut changed only the sweep, so a
    biennial lease was armed twelve months out and stepped a year early once, and the projected
    ladder promised increases in years the sweep would never apply one. Found by the adversarial
    review of the commit that introduced it.
  - It uses `addMonthsNoOverflow()` **and restores the anchor day**. Carbon's default overflows a
    month-end date into the next month (31 Aug + 18 months → 2 March, not the last day of
    February); `NoOverflow` alone then rolls off the CLAMPED date, so 31 Aug → 28 Feb → 28 Aug and
    the anniversary creeps backwards a few days at every step. The anchor is the commencement day,
    clamped to a day the target month has — the same reading `BillingDay` takes of a month-end
    billing day.
- **Idempotent + concurrency-safe:** each lease is row-locked and its due-ness re-checked *inside*
  the transaction; applying advances the date past today, so a re-run is a no-op.
- **One step per run:** a multi-year backlog (from a mis-set date) catches up over successive runs
  instead of compounding several years in one pass.
- **CPI is deliberately skipped** — there is no index feed, and inventing a CPI number would be
  inventing data. Only `fixed_percent` applies. Wiring CPI = §8 "Changing escalation logic".
- A rate of `0` still rolls the date forward, so it is not reconsidered every single day.

*(Until 2026-07-30 this section told you escalation was manual and invited you to build
`ApplyLeaseEscalationsCommand`. It had already been built. Check `routes/console.php` before
building anything this file calls "future".)*

---

### Security deposit is a metadata field, not enforced in invoicing

**Design:** The `security_deposit` and `security_deposit_received` fields are informational. They do NOT automatically reduce tenant invoices or create offset credits. If a security deposit is held, operators must:
1. Manual track its collection (toggle `security_deposit_received`).
2. Issue a credit note or payment offset when returning or applying the deposit to final invoices.

---

## 10. Tests & related modules

### Test files

- **Models & unit logic:**
  - `tests/Feature/Models/LeaseTest.php` — helpers, derived methods (totalMonthlyAmount, annualValue, isActive, isExpiringSoon, generateReference).

- **Observer (unit-status projection):**
  - `tests/Feature/Observers/LeaseObserverTest.php` — status transitions, master mirroring, maintenance override.

- **Services:**
  - `tests/Feature/Services/LeaseCreationServiceTest.php`
  - `tests/Feature/Services/LeaseRenewalServiceTest.php`
  - `tests/Feature/Services/LeaseTerminationServiceTest.php`
  - `tests/Feature/Services/LeaseRentChangeServiceTest.php`

- **Scenarios (end-to-end):**
  - `tests/Feature/Scenarios/LeaseLifecycleScenarioTest.php` — creation → escalation → renewal → termination, charges + VAT + invoicing integration.
  - `tests/Feature/Scenarios/MultiUnitLeaseFormScenarioTest.php` — Filament form for multi-unit leases.
  - `tests/Feature/Scenarios/MultiUnitLeaseDataScenarioTest.php` — occupancy projection for master + additional units, syncUnits edge cases.
  - `tests/Feature/Scenarios/PercentageRentScenarioTest.php` — artificial & natural-breakpoint percentage rent.

- **Filament:**
  - `tests/Feature/MultiUnitLeaseTest.php` — form & table interaction (edit, additional units).

- **Regression:**
  - `tests/Feature/Regression/MultiUnitLeaseRenewalTest.php` — multi-unit renewal carrying full unit set.
  - `tests/Feature/Regression/LeaseDoubleBookingTest.php` — a unit can never carry two active leases: the raced double-renewal, the occupied-unit create, and the standing assertion that both activation paths still lock the unit row.
  - `tests/Feature/Regression/Module04HoldoverAlertTest.php` — an active lease past its end date is surfaced (see C1.10: it is alerted, not billed).
  - `tests/Feature/Regression/Module04LeaseIntegrityTest.php` — cross-cutting lease integrity.

### Related modules

- **Properties & Units** (`docs/modules/01-properties-units.md`) — occupancy status projection; lease drives unit state.
- **Tenants** (`docs/modules/...`) — one tenant per lease.
- **Charges** (`docs/modules/...`) — rent + service charges linked to lease; VAT rules.
- **Invoices** (`docs/modules/...`) — monthly billing reads lease charges.
- **CAM** (`docs/modules/...`) — allocates service charges to CAM pools.
- **Percentage Rent / Sales Declarations** (`docs/modules/...`) — triggered by TenantSalesDeclaration on a lease.
- **ETA Integration** (`docs/modules/...`) — invoices from leases submitted to tax authority.
- **Marketing Levy** (`docs/modules/...`) — derived from lease data for budget allocation.

---

**CRUD Permissions (Spatie):**
- `leases.view` → see list/detail
- `leases.create` → create leases
- `leases.edit` → edit lease fields
- `leases.delete` → hard/soft delete (only super_admin)
- `leases.terminate` → call LeaseTerminationService
- `leases.renew` → call LeaseRenewalService
- `leases.generate_invoice` → ManuallyGenerate invoices from lease

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Lease` | **Only while unreferenced** — blocked by `invoices`, `charges`, `salesDeclarations`, `camAllocations`, `maintenanceRequests`, `renewals`, `deposits`, `postDatedCheques` | terminate the lease — that is the documented end of a tenancy, and it keeps the billing history |

---

## Options & critical dates (2026-08-09)

A commercial lease is a bundle of options, and options are money. Atriom recorded none of them: a
renewal right at a contracted uplift existed only inside the uploaded PDF, so nothing could alert
on it, report it, or stop the space being promised to somebody else.

**The gap that made this urgent.** The only lease-date alert was `leases:remind-expiring`, firing
90 days before **expiry**. A typical clause reads *"notice no earlier than 12 and no later than 9
months before expiry"* — so that reminder arrived three to six months **after** the right had
already been lost. The system reliably spoke too late to act, which is worse than not speaking: it
feels like coverage.

| Piece | What it does |
|---|---|
| [`LeaseOption`](../../app/Models/LeaseOption.php) | renewal · termination · expansion · contraction · ROFR · ROFO · purchase, each with **both ends** of its notice window, the rent basis it would produce, a termination penalty, and the unit it encumbers |
| [`LeaseOptionsRelationManager`](../../app/Filament/Admin/RelationManagers/LeaseOptionsRelationManager.php) | the panel on the lease, sorted **soonest deadline first**, with a live days-left badge and Exercise / Waive actions |
| `leases:scan-option-windows` (daily 06:45) | alerts **before the window opens**, **before it closes**, and records a missed one as **lapsed** |

**Three moments, not one**, because each needs a different action: *opening* → start the
conversation; *closing* → decide; *lapsed* → stop planning around a right that is gone.

### Rules worth knowing before you change this

- **An option not recorded here is an option nothing will ever remind anyone about.** The scan reads
  these rows and nothing else.
- **Encumbrance only applies while an option is OPEN.** An exercised, waived or lapsed option ties
  up nothing — treating it as if it did would block space the mall is free to let.
- **`projectedRent()` refuses to invent a number.** A `market` review needs a valuation and `cpi`
  needs an index feed, so both return null — the same rule the escalation sweep follows.
- Idempotent + lock-safe like every other scheduled scan: row-locked, stamp re-checked **inside**
  the transaction. Delivery failures warn but still stamp, because the panel reads the window live
  and independently of the stamps — a dropped email cannot make a deadline invisible.
- Write access rides on `leases.edit`, gated in **both** `visible()` and the action closure
  (mutation-verified).

**Still open:** the encumbrance is recorded but not yet surfaced in the unit picker when letting a
space (OP-03), and there is no portfolio-wide critical-dates work-list (UX-09) — today the alerts
are the delivery mechanism.

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-103

beside the holdover section: **A holdover rate is floored at 100% in ONE place — `Lease::HOLDOVER_MIN_RATE_PCT`.** It is a percentage OF the contracted rent (`$lastRent * $rate / 100`, 150 = 150%), so below 100 prices overstaying below renewing, which is the opposite of what the clause is for; a genuinely reduced wind-down rent is a rent change or a relief. The number was stated three times and the three disagreed — measured 2026-09-03: the conversion modal at 100 under a comment explaining why, `ConvertLeaseToHoldoverService` at "greater than zero", and the PORTFOLIO DEFAULT the modal prefills itself from (`billing.holdover_default_rate_pct`, the only tier — `PropertySettings` has no holdover key) at **0**. So an operator could save 80 on /admin/settings and then be refused on a field they had never touched, quoting a minimum the settings screen had just accepted below. The service keeps the floor as well as the modal, because `convert()` may be called with no `rate_pct` at all, in which case the rate IS the portfolio default and the modal's `minValue` never runs. The refusal is `admin.refusals.holdover_rate_below_floor` in both languages and names both escapes (type at least 100%, or raise the portfolio default), because `LeaseActions` catches `\InvalidArgumentException` and renders the message to the operator.


### SW-042

, under `## 9. Gotchas, edge cases & recently-fixed bugs`:

### The quick-lease wizard opened on literals, not on the mall's conventions (SW-042, 2026-09-03)

**Two doors create a lease and they disagreed about the defaults.** The full form has read the
configured conventions since EG-35 — the lease TERM from `AccountingSettings::default_lease_term_months`,
the PAYMENT TERMS from `PropertySettings::paymentTermsDays()` (property → portfolio). The leases
list's *Quick new lease* wizard prefilled the literals `36` and `7`. Measured with the portfolio set
to 45 days and a 30-day override on one mall: the wizard opened on 7.

**`LeaseCreationService`'s own fallback could never rescue it.** Its
`?? PropertySettings::paymentTermsDays($unit->asset_id)` fires only when the caller states no terms,
and the wizard's `lease.payment_terms_days` input is always dehydrated — so the payload always
states a value and that branch is dead for this door. A test of the service would have passed
throughout; only driving the wizard sees it.

**The term default is now one seam, `App\Support\LeaseTerm::defaultMonths()`**, read by both screens.
It lives on `LeaseTerm` rather than as a static on `AccountingSettings` because
`SettingsReachConformanceTest` proves a setting is read by something *outside* `app/Settings` —
moving the only read into the settings class would blind the gate that exists to catch a setting
nothing consults. `escalation_rate` is deliberately still the literal `7` in both places: there is no
configured escalation convention, and routing it through one would invent a setting nothing reads.
`fillForm()` is now a CLOSURE, so the property is resolved when the modal is opened rather than when
the table was assembled. (`TheQuickLeaseWizardOpensOnTheMallsConventionsTest`, 3 mutations.)


### SW-055

, under `## 9. Gotchas, edge cases & recently-fixed bugs`:

### The deposit filter and the deposit column disagreed on the same page (SW-055, 2026-09-03)

The leases list's **Deposit outstanding** filter re-expressed the pot as one `whereRaw`: receipts
less refunds and forfeits from `deposit_transactions`, less `deposit_applications`. That is **three
of the four terms** `Lease::depositHeld()` sums. The missing one is `settledDepositBillings()` — a
deposit billed on an invoice and since paid — and it is not an edge case:
`BillSecurityDepositService` raises the deposit on its own invoice and writes no
`deposit_transactions` row at all, so it is how a deposit is normally collected. Measured: a lease
agreeing 60,000, billed and paid in full, read **0.00** in the `deposit_shortfall` column and was
returned by the filter beside it as owing the whole 60,000.

**`Lease::scopeDepositOutstanding()` is the one definition.** It cannot be pure SQL and that is the
point: the missing term is `DepositBilling::heldOn()` over `InvoiceItemSettlement`, which splits
`invoices.paid_amount` across the lines in `TYPE_PRIORITY` order and nets credit relief — arithmetic
no correlated subquery can carry, and re-expressing it beside the original is how the two came to
disagree. So the CANDIDATES are narrowed in SQL and the POT is asked of the model, once per
candidate, off the three relations the list already eager-loads. **Chain it last**, after the status
clause and the property scope, so the candidate query inherits both. Every other consumer — the
portal infolist, the mobile API, the lease actions, the deposits relation manager,
`BillSecurityDepositService` — already called the model; this filter was the only re-implementation
left. (`ADepositAlreadyCollectedIsNotStillOutstandingTest`, 2 mutations.)


### SW-048

**Extending a lease re-arms its renewal reminder (SW-048, 2026-09-03).** `leases.expiry_reminder_notified_at` is the idempotency stamp `leases:remind-expiring` keys on (`whereNull(...)`), it had exactly one writer, and nothing ever cleared it. The stamp means *"the tenant has been told about the expiry date this lease carries"* — so the moment the term is EXTENDED it describes a date in the middle of the tenancy, and the renewal conversation is never started again for the rest of it. A silent absence, the failure class nobody reports; `Lease::RENEWAL_RESET` already described the column correctly for the other direction (*"a notification stamp about the original's expiry"*) and the lease that STAYS had no counterpart. **The rule is a `Lease::updating` hook, not a line in `LeaseExtensionService`**, because `expiry_date` is still a plain DatePicker on `LeaseForm` for an un-invoiced lease and `LeaseImporter` writes it too — the same one-seam reasoning that put the rate derivation and the deposit multiple on the model. **FORWARD ONLY, and that asymmetry is the load-bearing part**: `LeaseTerminationService` stamps the termination date onto `expiry_date` and, under notice, leaves the lease ACTIVE — precisely the row the sweep still selects — so clearing on a backwards move would send *"your lease is approaching expiry, start the renewal conversation"* to a tenant who has already served notice. That message is outbound and cannot be recalled. A forward move inside the current 90-day window does re-remind, quoting the new date; that is the intended reading, and the alternative is silence for the rest of the tenancy. Clearing writes no audit noise — `_notified_at` is already a denylisted suffix in `ActivityLogging`.


### SW-125

**A refund or forfeit is fixed once the final account has been settled (2026-09-04).** The deposit freeze had one half only: `DepositTransaction`'s `saving` guard asks `$wasOrIsReceipt`, so it never covered the two rows a move-out WRITES, and `ChangeImpact` recorded the module as "already had the freeze, on a BETTER predicate" — true of receipts alone. Measured: 100,000 received, lease terminated, `SettleMoveOutService::settle()` writes a 100,000 refund and `depositHeld()` reads 0; retyping that refund to 10,000 was accepted, the pot climbed back to **90,000**, and a second 90,000 refund against that phantom pot was accepted too — while the first 100,000 had already left the bank. `DepositTransaction::finalAccountIsSettled()` is the second predicate: a termination `LeaseEvent` carrying the settlement payload, which is the document the tenant signed and which quotes `refunded` and `deducted_total`. It deliberately is NOT `hasBeenDrawnOn()` — a recorded refund is itself a draw, so that query finds the row itself and would freeze every refund at birth, killing the correction `potContributionAsPersisted()` exists to support. Unlike its sibling it asks BOTH ends of a re-point, because moving a row out of a settled lease and into one are the same restatement from two directions. **`status` and `notes` stay editable so `cancel_deposit` remains the escape** — it records why, reverses the ledger entry and returns the money to the pot, and the corrected movement is then recorded; correcting a committed movement through a named act rather than by retyping is the discipline every money document here follows. `ASettledFinalAccountIsNotRetypedTest` pins both refusals, both directions of the re-point, the escape, and the two controls (an un-depended-on refund is still correctable; a late receipt can still be recorded).

### SW-053

` (the clause register section), with a pointer from the SLA settings notes in `docs/modules/26-facility.md`:

**A field's unit AFFIX is chrome too, and the Arabic gate cannot see one (SW-053, 2026-09-04).** `ArabicPanelHasNoEnglishChromeConformanceTest` sweeps `getLabel()` on columns, filters, actions, tabs and empty states — nine call sites across its 548 lines — and calls `getSuffixLabel()`/`getPrefixLabel()` nowhere. So the lease-clause form's `->suffix('days')` and all eight of Settings → SLA's `->suffix('hrs')` sat as English words inside Arabic forms with nothing able to go red. Measured by building the clause schema and reading the affixes back: `threshold_pct => '%'`, `radius_km => 'km'`, `notice_days => 'days'`. **The rule is: an ISO code, an SI unit symbol or a punctuation mark is verbatim; a natural-language word goes through `__()`.** So `days` becomes `admin.fields.days` — the key the lease form six fields away has used since EG-35 — `hrs` becomes a new `admin.fields.hrs` («ساعة»), and **`km` is deliberately NOT translated**, which is the half of the row that is refused: it is the SI symbol, and this app already prints `m²` verbatim in eight places, so translating one and not the other would leave the panel inconsistent with itself. A sweep of every literal affix in `app/` found **184**, of exactly nine distinct values, of which **9 sites** were English words — that is the whole class and it is now closed by a gate that requires each literal to be a registered symbol WITH a reason, and fails equally on a stale registration. Two traps the gate records: a loose regex crosses the closing quote and reports the translated tail of `->suffix('/m²/'.__(...))` as hardcoded (a gate firing on correct code), and the premise is asserted (184 → 175 affixes seen) so a sweep that silently stops matching cannot report a clean panel it never read.


### SW-043

under the rent-index / CPI escalation section: "**A revised index figure is an EDIT to the reading, not a second one (SW-043, 2026-09-04).** `rent_indices` is keyed `(code, period)` and the form asked nothing about it, so retyping a month that already has a reading — which is exactly what a revision looks like from the operator's chair — met the database index as an untranslated 500. It is refused as a field error now, worded to name the escape the migration's own docblock states: open the existing row and correct its value, so a lease that escalated on the old figure can still show which figure it used and when it changed. Both sides of the check are normalised the way storage normalises them — `RentIndex::normaliseCode()` is the one home for the upper-casing the dehydrator does, because a rule keyed on the typed value compares `egy_cpi` against a stored `EGY_CPI` and matches nothing under SQLite's case-sensitive `=`, i.e. green in the suite and different on MySQL; and the month is snapped to the 1st, because the period state is legitimately a mid-month date. The self-exclusion on edit is load-bearing: without it the revision the refusal points at is itself refused. The form is the only door (`DemoSeeder` uses `updateOrCreate`), so there is deliberately no model guard beside it and the index stays the backstop."

### SW-027

**The lease register's status filter dropped `cancelled`** via `->except('cancelled')`, which arrived
in `bcca5b17` (May 2026) with no comment on the line and no reason in the commit message — incidental
rather than a decision. `LeaseForm:295` rejects only `renewed`/`terminated`, and only for a record not
already in them, so a lease **can** be saved `cancelled`. Unlike its invoice and payment siblings it
was never UNFINDABLE — `ListLeases::getTabs()` carries `cancelled` in the **Ended** tab — but the
filter beside the column that renders the word could not select it, and a status only one of the two
can reach is the drift.
Now `StatusOptions::for('leases')`. Full reasoning in
[modules/05 → SW-027](05-billing-invoices.md); regression test
`ARegisterFilterFindsEveryStatusItHoldsTest`.

### SW-238

**The deposit form and the deposit model disagreed about when a receipt freezes.**
`DepositTransactionForm` locked on `status !== 'recorded'` — so it froze a CANCELLED deposit and left
a live one wide open — while `DepositTransaction::saving` refuses on `hasBeenDrawnOn()` (asked of the
LEASE, because the deposit is one pot per lease) and on `finalAccountIsSettled()`. A 100,000 receipt
already netted 80,000 against arrears therefore rendered every field enabled: the operator retyped
the amount and the model answered with a refusal toast on submit. The form now mirrors both freezes
on exactly the columns each one names — `bank_account_id`, `method`, `is_opening_balance` and `notes`
are in neither, so they stay on the cancelled-only lock — memoised per record, because both
predicates are queries and Filament evaluates a `disabled()` closure on every render pass. The house
rule is the one `ExpenseForm` states beside its own `$moneyLocked`: **the same predicate on both
layers**, so the operator sees a disabled field and the reason rather than a refusal after
submitting. Full reasoning in
[CHANGE-IMPACT-PLAN §16](../accounting/CHANGE-IMPACT-PLAN.md#16-the-ui-sweep-2026-09-05--a-status-is-the-outcome-of-an-act-and-an-act-is-on-the-record);
regression test `AnActOnAPostedDocumentIsWhereItCanBeSeenTest`.

### SW-050

**A TERMINATED LEASE STILL BILLS THE PERIOD IT CONSUMED (SW-050, fixed 2026-09-05) — this one moved
money OUTBOUND.** A charge billed IN ARREARS is invoiced one cycle behind: September's service
charge appears on October's invoice, because September's service is not knowable until October. When
the lease ends no October invoice is ever raised, so the days the tenant genuinely occupied are
billed by **nothing** — and `MoveOutStatementService` computes `net = depositHeld + tenantCredit −
openAr` **from existing invoices only**, so an invoice nobody raised is not open AR. Measured on rent
100,000 in advance + service charge 20,000 in arrears, deposit 300,000, terminated 20 September with
the tenant paid up: the tenant owed **13,333.33** and was refunded **300,000.00** where 286,666.67
was right. The tenant has gone, there is no recovery path, and `pendingTrueUps()` has no
unbilled-period term so nothing on the statement says anything is missing.

**The row's stated cause was half wrong, and the half that was right is not where it pointed.**
`is_active = false` is not what stops the SCHEDULED run — `isBillableForPeriod()` refuses on the
STATUS first, and forcing the flag back on changes nothing there. But it is exactly what stopped the
new act, which has already got past the status: the planner answered `no_applicable_charges` because
the schedule had been deactivated three steps earlier. Setting `end_date` is what bounds the billing;
the flag only says a row is finished, so it is now written once nothing else needs to read it.

**`App\Services\BillFinalPeriodService` writes no arithmetic** — `planInvoiceForLease()` already
gives the right answer once `expiry_date` has moved, and a second copy would let the final bill
disagree with the credit note beside it on the same statement. **`invoice_items.covered_end` is the
idempotency stamp**, so terminating twice, re-terminating on a different date and a catch-up run are
one mechanism, with no new column.

**TWO DOORS.** A termination dated in the future is NOTICE: the lease stays active and the period has
not happened yet, so `LeaseTerminationService` bills only an immediate termination and `leases:expire`
bills the lease that ends by notice or by running out — the shape `Unit::recomputeStatus()` already
has. The sweep wraps its call per lease, because one throw there would abort every remaining expiry
and leave the unit and rentable-item re-projections unrun.

**Four decisions the review and the mutations changed, each worth more than the original fix:**

- **Not restricted to ARREARS rows.** That was the obvious guard and it UNDER-bills: the run fires on
  the 1st, so any mid-month termination has an unbilled advance month, and measured, 66,666.67 of
  prorated rent was billed by nothing. `covered_end` is right for both timings — and it is Yardi's
  rule, that charges prorate to the move-out date, all of them.
- **The legacy probe is scoped by `charge_id`.** Unscoped it made the whole service INERT: `covered_end`
  is written by the recurring run alone, and every one-off raiser (late fee, deposit bill, violation
  fine, utility recharge, CAM recovery, % rent overage, NSF fee) issues against the lease with no
  `covered_*`, so one late fee anywhere in the lease's life refused it for ever — hardest on exactly
  the leases this exists for.
- **The CYCLE, not the calendar month.** A quarterly lease is billed only on a cycle start, so the
  planner answered `off_cycle` for two months in three and raised nothing at all.
- **`forceFinalCycle` for the HOLDOVER.** Its expiry is deliberately in the past and `holdover_from`
  keeps it billing, so `$isFinalCycle` answered false and an arrears row covered the previous month
  only. `leases:expire` excludes holdovers, so termination is their one door and the omission would
  have been permanent.

**A stated deviation on the posting date.** `PostingDate` says refuse rather than re-date, and
`CreditUnearnedBillingService` — this bill's own mirror — does refuse. This posts FORWARD instead,
because a credit note is optional relief the operator can re-take while this is the last chance to
ask for money that otherwise leaves as a larger refund; and if today is closed too it SKIPS loudly
rather than throwing, since `closeFiscalYear()` closes the current month as well and taking a
termination down with it is the worse outcome. Every skip is an `OpsLog` warning and the final
invoice's number goes into the `lease_terminated` event payload beside the credit notes — a lease
silently un-billed at move-out has no second chance, because a terminated lease never reaches the
expiry sweep.

**It bills ONE period, not the unbilled backlog** — stated because the document looks like a complete
final bill. A lease nobody ever ran billing for still has those months uninvoiced; that is a
different problem with a different answer. `ATerminatedLeaseStillBillsTheMonthItConsumedTest`,
9 cases, 6 mutations.

### SW-240

**`is_opening_balance` joined both deposit freezes.** The receipt freeze's dirty-list named every
column that changes what the pot is MADE OF (amount, parties, date, type, status) and missed the one
that changes whether the pot was ever BOOKED: the opening flag suppresses the receipt's
`Dr Cash / Cr Deposits Held`, so flipping it on a drawn-on receipt voided the posted credit while
the applications' debits stood — the pot negative by the receipt's full value, the amount-edit hole
worn as a checkbox. In the receipt freeze AND the settled-account freeze now, and on the form's
`$frozen`; an UNDRAWN receipt's flag stays correctable, which is the model's own
fixable-until-drawn-on design and the over-lock control in the test. See
[CHANGE-IMPACT-PLAN §17](../accounting/CHANGE-IMPACT-PLAN.md); `AMoneyStateMovesThroughAnActTest`,
mutation-proved.

### A lease is not ENTERED past its own term, and a lease that ended can still be closed out

Two halves of one report ("status still shows Active once the Ends date has passed").

**Entering it.** `leases:expire` is a NIGHTLY sweep, so a lease keyed today for a term that ended
last year read *Active* until 05:15 — the register, the occupancy figures and the rent roll all
showing a state the system itself already disagreed with (`hasExpiredTerm()` was true the moment it
was written). Yardi derives a lease's status from its dates plus explicit acts, so entering a
historical lease in Voyager shows it as Past immediately. `LeaseForm` therefore stops OFFERING
`active` once the expiry typed has already passed, reactively, with a helper text saying why — the
same rule the unit's occupancy follows: do not accept a state you will silently correct later.

**Deliberately at the FORM, not on the model.** A `saving` hook rewriting the column was tried and
reverted. It makes "active with a past term" impossible — and THREE services still refuse to act on
a lease that is not `active`: `LeaseRenewalService`, `AssignRentableItemService`, and (until this
change) `LeaseTerminationService`. Making that state impossible a day earlier than the sweep already
does turns three latent gaps into immediate ones without fixing any of them. **The other two remain
open and are recorded here rather than implied: a lease cannot be RENEWED or take a PARKING BAY once
its term has run out**, which for renewals is the common case, since they are routinely signed late.

**Closing it out.** At the end of a term an operator has three answers — renew, hold over, close out
— and the sweep projects the whole candidate set to `expired`. Holding over was given its carve-out
when LE-04 was found unreachable (`awaitsHoldoverDecision()` accepts `expired` for exactly that
reason); closing out was not, so after 05:15 a tenant who had actually left could not be recorded as
having left. `LeaseTerminationService` accepts `expired` now, and `Lease::isClosingOutAnExpiredTerm()`
is the immutability carve-out — the sibling of `isResumingFromExpiry()`, recognised by the SHAPE of
the write (`expired` → `terminated`, touching no commercial term, which `LeaseForm` cannot produce
because it withholds `terminated` unless the record is already in it). A tenancy somebody already
closed stays refused, and an expired lease stays immutable for everything else.

A past-term lease is also never "under notice": notice is a statement about a tenancy that is still
running, so terminating one that has already expired records a MOVE-OUT and moves it straight to
`terminated`. (`ALeaseIsNeverEnteredPastItsOwnTermTest`.)
