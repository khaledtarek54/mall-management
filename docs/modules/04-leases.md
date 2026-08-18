# Leases

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
>
> **And the premises field stopped being a second, lossy path.** `EditLease::afterSave()` feeds
> `additional_unit_ids` to `syncUnits()`, which is a `sync()` — so REMOVING a unit there **detached
> its `lease_unit` row**. That row carries the `effective_from`/`effective_to` that
> `totalAreaSqmForPeriod()` allocates CAM on, so the deletion did not end the tenant's occupancy: it
> erased the months they genuinely held the space, silently restating a reconciliation that may
> already be closed. Two clicks, no warning. The field is read-only on Edit now, and **Change
> premises** — which CLOSES the row — is the only path.

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
> - **Encumbrance warns, it does not block** (OP-03). `Unit::encumbrances()` / `isEncumbered()` feed
>   BOTH unit pickers — master and additional, because an expansion right is usually exercised over
>   the adjacent unit, which is what the second picker adds. `LeaseOption::encumbersUnit()` had
>   existed all along with **nothing in the codebase calling it**.

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
>   does not follow it — unlike a rent change, where both do.
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

## 1. Purpose & business context

Leases model the core revenue instrument of Egyptian mall operations. They bind tenants to units (retail spaces) for a fixed term, specify monthly rent and service charges with embedded VAT rules, enable percentage-of-sales rent triggers, and track the full lifecycle: draft negotiation → active occupancy → renewal or expiry → termination. A tenant may hold multiple single-unit leases across a mall; a single lease may span multiple units (multi-unit lease). Operators (Eltizam department) manage creation, renewal, termination, and rent escalation; owners (Jawad) and the accounting department oversee invoicing and payment via the linked Charge and Invoice modules.

## 2. Domain model

| Table | Model | Key Columns | Meaning |
|-------|-------|-----------|---------|
| `leases` | `Lease` | `reference` (string, unique) | LSE-{ASSET_CODE}-{YEAR}-{SEQ_NUM}, e.g. "LSE-HW-2026-0001". Generated by `Lease::generateReference()`. |
| | | `unit_id` (FK → units, NOT NULL) | Foreign key to the master unit; denormalized pointer to `units.id` for fast lookups and backward compatibility. Always mirrors the `is_master=true` row in `lease_unit` pivot. Scoped by `ScopesViaProperty` trait in Filament. |
| | | `tenant_id` (FK → tenants, NOT NULL, RESTRICT) | Tenant occupying the lease. Cannot be orphaned. |
| | | `previous_lease_id` (FK → leases, nullable, NULL ON DELETE) | Points to the prior lease if this is a renewal. Enables the lease chain: original → renewal → next renewal. |
| | | `status` (enum) | One of: `draft`, `pending_approval`, `active`, `expired`, `renewed`, `terminated`, `cancelled`. Default `draft`. Drives unit occupancy projection (see § 4). |
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
| | | `escalation_type` (enum) | One of: `none`, `fixed_percent` (escalation_rate %, **auto-applied**), `fixed_amount` (escalation_amount EGP, **auto-applied**), `cpi` (inflation-indexed — **skipped by the sweep until an index feed exists**; no number is invented). Default `none`. |
| | | `next_escalation_date` (date, nullable) | Next scheduled escalation. **Armed automatically on create** by `Lease::creating` = `commencement + 1yr` whenever escalation is configured (`fixed_percent`/`cpi`, rate > 0) — converged in the model so the wizard, standard form, and renewal all set it consistently (before this, NO creation path populated it, so the sweep never fired for a real lease). The daily `leases:apply-escalations` sweep (`RentEscalationService`) applies a due `fixed_percent` increase through `LeaseRentChangeService` and rolls this forward a year — idempotent + lock-safe. `none`/rate-0 leases stay null (never escalate). |
| | | `has_percentage_rent` (boolean, NOT NULL, default false) | Whether sales-based rent (pct rent) applies. |
| | | `percentage_rent_threshold` (decimal 12,2, nullable) | Sales floor triggering pct rent (artificial breakpoint). E.g., 100,000 EGP/month → charge on sales above this. |
| | | `percentage_rent_rate` (decimal 5,2, nullable) | Pct rent rate (0–100, e.g., 8 → 8% of sales above threshold). |
| | | `percentage_rent_calculation_type` (enum, nullable) | `artificial` (threshold-based) or `natural_breakpoint` (% of sales minus monthly base rent, floored at 0). Defaults to `artificial` if null when calculating. |
| | | `billing_day` (date, nullable) | Preferred day of month to invoice (reserved for future billing logic). |
| | | `payment_terms_days` (unsigned small int, default 7) | Invoice payment due window (7 days = due 1 week after issue). |
| | | `notes` (text, nullable) | Audit trail: appended with termination/rent-change stamps and reasons. |
| | | `metadata` (JSON, nullable) | Flexible key-value store for future integrations. |
| `lease_unit` | (pivot) | `lease_id`, `unit_id` | Links leases to units; supports multi-unit leases. Each lease has ≥1 pivot rows (one per unit). |
| | | `is_master` (boolean, default false) | Exactly one `is_master=true` per lease. The master is the "primary" unit and is mirrored to `leases.unit_id`. |

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

A lease is one kind of agreement that raises AR; a **unit ownership** ([plan 08](../plans/08-unit-owners.md))
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
| **draft** | New lease created in admin or via `LeaseCreationService`. | → `pending_approval`, `active`, `cancelled` | Discarded if not activated; reserved unit if present. |
| **pending_approval** | Operator upgrades a draft lease pending review. | → `active`, `cancelled` | Awaits approval before activation; reserved unit. |
| **active** | Lease commences (explicit status set on creation or via promotion). | → `renewed` (renewal creates new lease), `terminated`, `expired`, `cancelled` | Unit is occupied. Invoices generate. Charges are active. Only one active lease per unit. |
| **renewed** | Triggered when `LeaseRenewalService::renew()` marks original as 'renewed'. | (terminal for original) | Original lease is now closed; the renewal is a new 'active' lease linked via `previous_lease_id`. Unit is reserved (because the renewal—a new active lease—projects it to occupied). |
| **expired** | Manual mark-as-expired or automated task (future). | (terminal) | Unit becomes vacant (unless another non-terminal lease on it). Invoicing stops. |
| **terminated** | `LeaseTerminationService::terminate()` on active or pending lease. | (terminal) | Charges deactivated. Unit becomes vacant (unless another non-terminal lease). Invoices optionally cancelled. |
| **cancelled** | Operator cancels a draft or pending lease. | (terminal) | Unit reverts to vacant (if no other non-terminal leases). |

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
- `CreateLease` — full form (incl. additional_unit_ids multi-select, charges not in form).
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
   - `additional_unit_ids` (Select, multiple, dehydrated=false) — non-master units for multi-unit leases; dehydrated=false (processed in `afterCreate()` / `afterSave()`).
   - `tenant_id` (Select, required, searchable, creatable inline) — with quick-create form (name, phone, email).
   - `status` (Select) — draft, pending_approval, active, etc.
   - `show_occupied_units` (Toggle, live, dehydrated=false) — toggles unit dropdown visibility.

2. **Term** (3 cols)
   - `commencement_date` (DatePicker, required).
   - `term_months` (TextInput, numeric, 1–120, default 36).
   - `expiry_date` (DatePicker, required).

3. **Financial Terms** (3 cols)
   - `rent_pricing_basis` (Radio: flat | rate; disabled on edit) — choosing `rate` reveals the rate field and makes the monthly figure derived.
   - `base_rent_rate_per_sqm_year` (TextInput, EGP/m²/yr; required + visible only when the basis is `rate`) — the helper text shows the let area the derivation is using, updated live as units are picked.
   - `base_rent_monthly` (TextInput, numeric, ≥0; disabled on edit **and** on a rate-priced lease, dehydrated) — read-only on edit to enforce use of LeaseRentChangeService, read-only on `rate` because it is derived.
   - `service_charge_monthly` (TextInput, numeric, ≥0; disabled on edit, dehydrated) — helper text on edit warns "use Change Rent action".
   - `has_marketing_levy` (Toggle, live, default true) — whether the marketing levy is billed to this tenant. `EditLease::afterSave()` re-syncs the `marketing` charge via `MarketingLevyService::createLevyCharge()` so a toggle change takes effect on the next run.
   - `marketing_levy_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_marketing_levy) — per-lease rate override; placeholder shows the mall default; blank = default.
   - `possession_date` + `rent_commencement_date` (DatePickers) — the handover date and the start of rent. Blank rent-commencement = no grace. The billing gate lives on the model: `Lease::periodInFitOut()` / `firstBillableMonth()` / `rentCommencesOn()`, shared by `MonthlyBillingService` and the ActionRequired "unbilled leases" card (so a lease in grace is neither billed nor flagged).
   - `billing_frequency` (Select: monthly / quarterly / semiannual / annual, default monthly) — the invoicing cadence. The cadence rule lives on the model: `Lease::billingCycleMonths()` (1/3/6/12) and `isBillingCycleStart()` (commencement-anchored, post-fit-out), used by `MonthlyBillingService` (bill the whole cycle on cycle-start months) and the "unbilled leases" card (don't nag off-cycle months). A manual "Generate Invoice" for an off-cycle month returns reason `off_cycle` with a clear notice.
   - `security_deposit` (TextInput, numeric, ≥0).
   - `escalation_rate` (TextInput, numeric, 0–100, default 7, suffix '%').
   - `escalation_type` (Select) — none, fixed_percent, cpi; default fixed_percent.
   - `payment_terms_days` (TextInput, numeric, default 7, suffix ' days').
   - `security_deposit_received` (Toggle, column full).

4. **Percentage Rent** (3 cols, collapsed, collapsible)
   - `has_percentage_rent` (Toggle, live).
   - `percentage_rent_calculation_type` (Select, visible if has_percentage_rent) — artificial, natural_breakpoint; default artificial.
   - `percentage_rent_threshold` (TextInput, numeric, ≥0, prefix 'EGP', visible if has_percentage_rent).
   - `percentage_rent_rate` (TextInput, numeric, 0–100, suffix '%', visible if has_percentage_rent).

5. **Notes** (collapsed)
   - `notes` (Textarea, 3 rows).

6. **Documents** (collapsible)
   - `documents` (SpatieMediaLibraryFileUpload, multiple, PDF/image/Word, max 10 MB, collection='documents').

---

### CreateLease page

**Behavior:**
- Standard Filament form creates Lease via Eloquent.
- `afterCreate()` hook:
  - Seeds standard charges via `LeaseCreationService::seedStandardCharges()`.
  - Syncs additional units via `Lease::syncUnits()` if `additional_unit_ids` is non-empty.

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

### Changing escalation logic (e.g., adding CPI indexing)

1. **Lease model:** escalation_type already supports 'cpi' enum value.
2. **Escalation job/command:** Create a new command (e.g., `ApplyLeaseEscalationsCommand`) that queries leases with escalation_type='cpi' and next_escalation_date ≤ now, fetches CPI index, calculates new rent, calls `LeaseRentChangeService::apply()` for each.
3. **Invoices:** Escalation changes take effect on the next invoice (monthly billing reads Charge.amount).
4. **Do NOT:** Directly edit Lease.base_rent_monthly without updating the matching Charge; use the service.

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
`next_escalation_date` forward a year.

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

- **Units** (`docs/modules/03-units.md`) — occupancy status projection; lease drives unit state.
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
