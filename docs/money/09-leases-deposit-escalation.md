# 09 — Leases: Rent, Service Charge, Deposit & Escalation

> **Audience:** business/finance team and engineers new to Atriom.
> **Scope:** the **money terms on a lease** and the lifecycle services that act on them — `base_rent_monthly`, `service_charge_monthly`, the security **deposit** (× months), the annual **escalation** %, the **charge records** a lease seeds, **renewal** (term carry-over + % rent), **termination** (final settlement), and **multi-unit** leases.
> **Golden rule (read this first):** a lease's `base_rent_monthly` / `service_charge_monthly` are the *contractual* numbers, but they are **not** what gets billed directly. Billing reads the lease's **`Charge` rows**. The two are kept in lock-step only by going through the lease services (`LeaseCreationService`, `LeaseRenewalService`, `LeaseRentChangeService`, `LeaseTerminationService`). Editing rent on the lease form is **blocked** precisely so the two can never drift.

---

## Plain-language summary (what + why, for a non-engineer)

A **lease** is the contract between a tenant (a retailer) and the property. It records, among other things, the **money terms**:

- **Base rent** — the fixed monthly rent. **VAT-exempt** under the Egyptian model.
- **Service charge** — a monthly fee for shared services (security, cleaning, common-area HVAC). **14% VAT applies.**
- **Marketing levy** — a percentage of base rent (default **5%**), billed to the tenant as its own line and funnelled into the property's marketing budget. **VAT-exempt.** (Full detail lives in `docs/money/03-marketing-levy.md`; it is summarised here because creating/renewing/changing-rent a lease all re-derive it.)
- **Security deposit** — a one-time, refundable sum the tenant lodges up front, **defaulting to 3× monthly rent**. It is a held amount, **not** an invoiced charge.
- **Annual escalation %** — how much rent is meant to rise each year (default **7%**). This is a *recorded intention*; the system does **not** auto-raise rent. The rise is realised when an operator **renews** the lease (or uses "Change rent") and enters the new number.

When a lease is created, the system automatically seeds the lease's **recurring charges** — Base Rent, Service Charge (if any), and Marketing Levy. From then on the **monthly billing run** turns those charges into invoices. So the lease is the *contract*, the charges are the *billing instructions*, and the invoices are the *bills*.

Three lifecycle actions change the money:

- **Renew** — at the end of a term, create a brand-new linked lease that carries every term forward (deposit, escalation %, percentage-rent settings, payment terms, notes, and the full set of units), but with the **new rent / service charge** the operator types in. The old lease is marked `renewed`; its charges stop; the renewal's charges start.
- **Change rent** — mid-term rent (and optionally service charge) change that updates both the lease *and* the matching charge rows together, so the next bill is correct.
- **Terminate** — end a lease early: stop its charges, optionally cancel its **fully-unpaid** open invoices, free the unit. (Deposit refund is a deliberate manual step — see edge cases.)

A lease can also cover **multiple units** (e.g. a flagship store spanning two shopfronts). One unit is the **master**; the rest are "additional". The money terms are still single numbers on the one lease — multi-unit does not multiply rent automatically.

---

## The exact rule / formula (every input, output, rounding, config source)

### 1. The lease money columns

Schema: `database/migrations/2024_01_01_000004_create_leases_table.php`. Model casts: `app/Models/Lease.php:65-79`.

| Column | Type / cast | Default (DB) | Meaning |
| --- | --- | --- | --- |
| `base_rent_monthly` | `decimal(12,2)` → `decimal:2` | (required) | Fixed monthly rent. **VAT-exempt.** |
| `service_charge_monthly` | `decimal(12,2)` → `decimal:2` | `0` | Monthly shared-services fee. **14% VAT.** |
| `currency` | `string(3)` | `EGP` | Always `EGP` in practice. |
| `security_deposit` | `decimal(12,2)` → `decimal:2` | `0` | Held deposit (see §3). |
| `security_deposit_received` | `boolean` | `false` | Has the deposit actually been lodged? |
| `escalation_rate` | `decimal(5,2)` → `decimal:2` | `0` | Annual rent-increase %. |
| `escalation_type` | `enum('none','fixed_percent','cpi')` | `none` | How the increase is computed (informational — see §4). |
| `next_escalation_date` | `date` (nullable) | `null` | The anniversary the next rise is *due*. **Informational only.** |
| `term_months` | `unsignedSmallInteger` | (required) | Length of term in months. |
| `commencement_date` / `expiry_date` | `date` | (required) | Start / end. |
| `payment_terms_days` | `unsignedSmallInteger` | `7` | Days from issue to invoice due date. |
| `has_percentage_rent` / `percentage_rent_threshold` / `percentage_rent_rate` / `percentage_rent_calculation_type` | bool / decimals / enum | `false` / null… | Turnover-rent terms (carried on renewal; not auto-billed). |

> **NOT-NULL safety:** `has_percentage_rent` and `security_deposit_received` are NOT-NULL booleans. The model sets in-memory defaults (`$attributes`, `Lease.php:60-63`) so a service-created lease that omits them can never propagate `null` into those columns (this class of bug is called out in the project invariants).

### 2. Base rent + service charge → the charge records a lease generates

A lease does **not** get billed from its own columns. On creation it **seeds `Charge` rows** (`app/Services/LeaseCreationService.php:82-129`, idempotent — skips if the lease already has any charge):

| Charge `type` | `name` | `amount` source | `frequency` | `vat_applicable` | `vat_rate` | created when |
| --- | --- | --- | --- | --- | --- | --- |
| `base_rent` | "Base Rent" | `base_rent_monthly` | `monthly` | `false` | `0` | `rent > 0` |
| `service_charge` | "Service Charge" | `service_charge_monthly` | `monthly` | `true` | `14.00` | `service > 0` |
| `marketing` | "Marketing Levy" | 5% of base rent (see §5) | `monthly` | `false` | `0` | `rent > 0` |

`start_date` on each = the lease commencement (`LeaseCreationService.php:92`). `is_active = true`.

The **monthly billing run** (`app/Services/MonthlyBillingService.php`) then converts active charges into invoice line items:

- **Per line:** `amount = round(charge.amount × factor, 2)`; `vat_amount = round(amount × vat_rate/100, 2)`; `total = round(amount + vat_amount, 2)` (`MonthlyBillingService.php:221-239`). `factor` is `1.0` unless the first month is pro-rated (§ edge cases).
- **Per invoice header:** `subtotal = Σ amount`, `vat_amount = Σ vat_amount`, `total = subtotal + vat_amount` (`:241-243`), each `round(…, 2)`. `currency` defaults to `EGP`. `due_date = issue_date + payment_terms_days` (default 7).
- A lease with **no applicable charge** for the period yields **no invoice** (`:195-197`).

So for a lease with rent **R** and service charge **S**, the monthly invoice (no escalation event, full month) is:

```
Base Rent line       : R               (VAT 0)
Service Charge line  : S + round(S×0.14,2) VAT
Marketing Levy line  : round(R×0.05,2)  (VAT 0)
─────────────────────────────────────────────
subtotal = R + S + round(R×0.05,2)
vat      = round(S×0.14,2)
total    = subtotal + vat
```

### 3. Security DEPOSIT (× months)

- **Default value:** **3× monthly base rent.** Set in `LeaseCreationService.php:60` — `'security_deposit' => (float) ($payload['lease']['security_deposit'] ?? $rent * 3)` — and mirrored in the demo data (`DemoSeeder.php:235`, `security_deposit => $rent * 3`).
- **Where the "3×" is configured:** it is a **code constant**, not a settings row. The "× months" lives in two places only: the service fallback above, and the form helper text (`lang/en/admin/help.php` (`helpers.*`) "Defaults to 3× rent if blank.", `lang/en/admin/help.php` "typically 3× monthly rent. Refundable at lease termination if no damages."). The **wizard / form do not impute it** — they offer a plain `security_deposit` input defaulting to `0` (`LeaseForm.php:203-210`, wizard `LeasesTable.php:300-305`); the `$rent * 3` fallback only fires through `LeaseCreationService::create()` when the field is left blank/null.
- **It is NOT an invoiced charge.** No `deposit`-type `Charge` is ever seeded, and the billing engine has no deposit handling. The deposit is a *held balance* recorded on the lease, tracked by the boolean `security_deposit_received` (default `false`; the Quick-Lease wizard always leaves it `false`, the demo seeder sets it `true`).
- **At termination it is NOT auto-refunded** (see edge cases) — refund is a manual operator step, by design.

### 4. Annual ESCALATION %

- **Default rate:** **7%** (`escalation_rate`), set in `LeaseCreationService.php:62` (`?? 7`), the form default (`LeaseForm.php:217`), the wizard fill (`LeasesTable.php:190`), and the demo seeder (`DemoSeeder.php:237` `7.00`).
- **Default type:** `fixed_percent` — same % every year (`LeaseCreationService.php:63`, `LeaseForm.php:223`). Enum options: `none`, `fixed_percent`, `cpi` (migration `:34`). The form exposes Fixed/CPI/Step labels (`lang/en/admin/leasing.php`).
- **`next_escalation_date`** is the recorded anniversary the rise is *due* (demo: `commencement + 1 year`, `DemoSeeder.php:239`).
- **CRITICAL behavioural fact — there is NO automatic escalation.** There is **no scheduled command, job, or observer** that reads `escalation_rate` / `next_escalation_date` and raises rent. (Confirmed: no escalation logic anywhere in `app/`, `routes/console.php`, or `app/Console/Commands`. The only "escalation" references outside the column names are an unrelated security-probe comment and a renewal-discussion seed string.) These fields are **stored intentions / reporting metadata**. The rise becomes real only when an operator **renews** the lease (typing the escalated rent) or uses **"Change rent."** On renewal, `next_escalation_date` is deliberately reset to `null` (`LeaseRenewalService.php:56`).
- **The arithmetic an operator performs by hand** (the system does not): escalated rent = `round(current_rent × (1 + escalation_rate/100), 2)`. The renewal modal pre-fills `new_rent` with the **current** rent (`LeasesTable.php:343`), so the operator must apply the escalation themselves before submitting.

### 5. Marketing levy (5% of base rent) — re-derived everywhere rent changes

- **Rate source:** `MarketingSettings::$levy_rate_percent` (default **5.0**), operator-tunable at `/admin/settings`, group `marketing` (`app/Settings/MarketingSettings.php`). If the settings row is missing the service falls back to **5.0** (`MarketingLevyService.php:21-29`).
- **Amount:** `round(base_rent_monthly × rate/100, 2)` (`MarketingLevyService.php:31-34`).
- **One levy charge per lease**, kept in sync via `Charge::updateOrCreate(['lease_id', 'type' => 'marketing'], …)` (`MarketingLevyService.php:41-56`). VAT-exempt.
- **Re-derived on every money path:** creation (`LeaseCreationService.php:126-128`), renewal against the *new* rent (`LeaseRenewalService.php:98-100`), and rent-change (`LeaseRentChangeService.php:89-91`). This guarantees the levy is always 5% of the **current** base rent, never a stale copy.

### 6. Renewal (carries terms, % rent type)

`app/Services/LeaseRenewalService.php`. Input: `{ new_term_months, new_rent, new_service_charge?, commencement_date? }`.

- **Guard:** only an **`active`** lease can be renewed (`:21-23`).
- **Defaults:** `new_service_charge` falls back to the original's service charge (`:27-29`); `commencement_date` falls back to **`original.expiry_date + 1 day`** (`:31-33`); `expiry = commencement + new_term_months − 1 day` (`:35`).
- **Creates a new linked lease** (`previous_lease_id = original.id`, `status = active`) that **carries forward**: `unit_id`, `tenant_id`, `currency`, **`security_deposit`**, **`security_deposit_received`**, **`escalation_rate`**, **`escalation_type`**, all **percentage-rent** fields (`has_percentage_rent`, `_threshold`, `_rate`, `_calculation_type`), `billing_day`, `payment_terms_days`, `notes`, `metadata` (`:40-65`). `next_escalation_date` is reset to `null` (`:56`).
- **New money:** `base_rent_monthly = new_rent`, `service_charge_monthly = new_service_charge` (`:49-50`).
- **Multi-unit carry-over:** if the original spans >1 unit, the renewal `syncUnits()` the full set with the same master (`:69-72`).
- **Charges copied per-row** (`:74-94`): every original charge is recreated on the renewal. `base_rent` → `new_rent`, `service_charge` → `new_service_charge`, everything else keeps its amount. `start_date = commencement`, `end_date = null`, `is_active = true`.
- **Marketing levy resynced** to 5% of the *new* rent (so it is not the copied original amount) (`:96-100`).
- **Original lease → `renewed`** (`:102`). The `LeaseObserver` then recomputes the unit (still `occupied` because the renewal is `active`).

### 7. Change rent (mid-term)

`app/Services/LeaseRentChangeService.php` — this exists so rent edits don't silently leave `Charge.amount` stale (audit M04 F-20 / D-13). The lease form's rent fields are **disabled on Edit** (`LeaseForm.php:186,198`) to force operators through this action.

- **Guard:** lease must be `active` or `pending_approval` (`:29-33`). `new_rent ≥ 0`, optional `new_service ≥ 0`, each `round(…, 2)` (`:35-44`).
- **Updates the lease** `base_rent_monthly` (and `service_charge_monthly` if supplied), appends a dated note (`:46-61`).
- **Syncs the charges** (`syncCharge`, `:102-139`): updates the most-recent **active** `base_rent` charge (creates one if none), and the `service_charge` charge (won't *create* a zero service charge — `createIfZero: false` — so you can leave it off). A created charge starts `now()->startOfMonth()`.
- **Marketing levy resynced** to 5% of the new rent (`:89-91`).

### 8. Termination (final settlement)

`app/Services/LeaseTerminationService.php`. Input: `{ termination_date?, reason?, cancel_open_invoices? }`.

- **Guard:** lease must be `active` or `pending_approval` (`:27-29`). `termination_date` defaults to today (`:31-33`).
- **Lease:** `status = terminated`, `expiry_date = termination_date`, a dated "Terminated on …" note appended (`:43-47`).
- **Unit:** recomputed by `LeaseObserver` from the status change → falls to `vacant`, or `reserved` if another draft/pending lease exists on the unit (`LeaseObserver.php`, comment `:49`).
- **Charges deactivated:** all charges set `is_active = false`, `end_date = termination_date` — so monthly billing generates no further invoices (`:52-55`).
- **Open invoices (optional):** if `cancel_open_invoices` is true, cancel **only fully-unpaid** open invoices — `status ∈ {draft, issued, partially_paid, overdue}` **AND `balance > 0` AND `paid_amount = 0`** → `status = cancelled`, `balance = 0` (`:63-74`). A **partially-paid** invoice is intentionally **left untouched** so the tenant's `paid_amount` is never orphaned; to void one, an operator must issue a credit note for the paid portion (see `docs/money/07-credit-notes.md`).
- **The deposit is not refunded here** — there is no deposit logic in the service. Final settlement of the deposit (refund minus damages) is a manual operator step.

---

## Worked examples (real numbers)

### A. New lease — full first month

Operator creates a 36-month lease via Quick-Lease: rent **EGP 50,000**, service charge **EGP 7,500**, deposit left blank, escalation left at 7%.

- Deposit auto-fills to `50,000 × 3 = `**`150,000`** (`security_deposit_received = false`).
- Charges seeded: Base Rent 50,000 (VAT 0); Service Charge 7,500 (VAT 14%); Marketing Levy `round(50,000 × 0.05, 2) = `**`2,500`** (VAT 0).
- First full-month invoice:
  - Base Rent: 50,000.00
  - Service Charge: 7,500.00 + VAT `round(7,500 × 0.14, 2) = 1,050.00`
  - Marketing Levy: 2,500.00
  - **subtotal** = 50,000 + 7,500 + 2,500 = **60,000.00**
  - **vat** = **1,050.00**
  - **total** = **61,050.00**
  - **due_date** = issue_date + 7 days.

### B. Renewal with a 7% escalation

Lease A above ends; operator renews for another 36 months. They apply the 7% escalation by hand: new rent = `round(50,000 × 1.07, 2) = `**`53,500`**, and bump the service charge to **8,000**.

- New lease (`previous_lease_id = A`) commences the day after A's expiry, expires `commencement + 36 months − 1 day`.
- Carried forward unchanged: deposit **150,000**, `escalation_rate` 7%, `escalation_type` fixed_percent, percentage-rent settings, payment terms, units.
- Charges recreated: Base Rent **53,500**, Service Charge **8,000**, plus **Marketing Levy resynced to `round(53,500 × 0.05, 2) = 2,675`** (not the old 2,500).
- A → `renewed`; the unit stays `occupied`.

### C. Mid-term rent change

Active lease, rent 50,000 → operator runs "Change rent" to **55,000**, service charge unchanged.

- Lease `base_rent_monthly` = 55,000; the active `base_rent` charge → 55,000; the marketing levy charge → `round(55,000 × 0.05, 2) = 2,750`. The very next monthly bill uses these. Service-charge charge untouched.

### D. Termination with a partially-paid invoice

Lease terminated today, `cancel_open_invoices = true`. The tenant has two open invoices: INV-1 (`issued`, balance 61,050, paid 0) and INV-2 (`partially_paid`, total 61,050, paid 30,000, balance 31,050).

- All charges → `is_active = false`, `end_date = today`.
- INV-1 → `cancelled`, balance 0 (fully unpaid).
- INV-2 → **untouched** (paid_amount > 0). Operator must credit-note the 30,000 paid portion to void it cleanly.
- Unit → `vacant` (assuming no other draft/pending lease). Deposit 150,000 is **not** auto-returned.

### E. Mid-month move-in (proration)

Lease commences **16 March** in a 31-day month, rent 50,000, billed with `prorate = true` (single-lease action).

- `daysInPeriod = 31`, `daysBilled = |31 Mar − 16 Mar| + 1 = 16`, `factor = 16/31` (`MonthlyBillingService.php:212-218`).
- Base Rent line = `round(50,000 × 16/31, 2) = 25,806.45`; labelled "… (52% pro-rated)". The **amount** is rounded, not the factor (so clean fractions bill exactly). `period_start` is stored as the **commencement** (16 March), which is why the idempotency guard is a period-**overlap** check, not a month-start match (`:75-83`, `:206-219`) — fixed in commit `3057520` so a prorated first month can't be double-billed.

---

## Every edge case + how the system handles it

- **Unit already has an active lease.** Creation throws a `ValidationException` on `lease.unit_id` (`LeaseCreationService.php:31-39`); the form re-validates the same rule on save (`LeaseForm.php:68-83`).
- **Deposit field left blank.** Through `LeaseCreationService` it imputes `rent × 3`. Through the plain form/wizard a blank coerces to `0` (`->dehydrateStateUsing(fn ($state) => $state ?? 0)`, `LeaseForm.php:209`) — the form does **not** apply the 3× rule.
- **Zero rent / zero service charge.** No Base Rent / Service Charge charge is seeded when its amount is `0` (`LeaseCreationService.php:94,109`); no marketing levy when rent is `0` (`:126`). A lease with no applicable charge produces **no invoice** that period (`MonthlyBillingService.php:195-197`).
- **Renewing a non-active lease.** Throws `InvalidArgumentException` (`LeaseRenewalService.php:21-23`).
- **Changing rent / terminating a non-active (and non-pending) lease.** Throws `InvalidArgumentException` (`LeaseRentChangeService.php:29-33`, `LeaseTerminationService.php:27-29`).
- **Negative rent or service charge on change.** Rejected with `InvalidArgumentException` (`LeaseRentChangeService.php:36-44`).
- **Service charge toggled off via Change rent.** `syncCharge(..., createIfZero: false)` updates an existing service charge to 0 but won't create a zero one (`LeaseRentChangeService.php:75-84,123-125`).
- **Escalation never auto-applies.** `escalation_rate` / `next_escalation_date` are recorded but inert — there is no job. Rent only rises when an operator renews or changes rent (see §4). Treat any expectation of "rent went up automatically on the anniversary" as **false**.
- **Mid-month commencement, prorate off (default bulk run).** Billed as a **full** month (`factor = 1.0`); proration is opt-in via the single-lease action's `$prorate` flag (`MonthlyBillingService.php:127,199-219`).
- **Re-running billing for an already-billed month.** Skipped by the period-overlap guard (`MonthlyBillingService.php:75-83,136-143`), so neither the bulk run nor the single-lease action double-bills — even for a prorated first month.
- **Terminating with partially-paid invoices.** Left intact (only fully-unpaid open invoices are cancelled) to keep AR honest (`LeaseTerminationService.php:63-74`).
- **Deposit refund at termination.** Not automated — no deposit charge, no refund record is generated; this is a deliberate manual step.
- **Multi-unit lease.** Money terms remain single numbers on the one lease; rent is **not** multiplied per unit. The extra units only affect occupancy. `unit_id` always points at the **master**; the full set lives in the `lease_unit` pivot (`Lease::units()`, `:90-95`). `syncUnits()` keeps `unit_id` = master and recomputes every affected unit's status (`:110-134`). Renewal carries the whole set (`LeaseRenewalService.php:69-72`); the create form attaches `additional_unit_ids` after create (`CreateLease.php:31-36`).
- **Concurrent billing runs.** Serialised by a 900s cache lock `billing:run:{Y-m}` (`MonthlyBillingService.php:34-41`); the queued job also carries `WithoutOverlapping`. Added in commit `bb1ceed`.

---

## Invariants + gotchas

- **Lease columns are contractual; `Charge` rows are what bill.** Never edit `base_rent_monthly` / `service_charge_monthly` directly on the lease in code and expect billing to follow — go through `LeaseRentChangeService` (or renewal), which sync the charges. The Edit form disables these fields for exactly this reason (`LeaseForm.php:186,198`).
- **VAT rule:** base rent VAT-exempt (`vat_rate 0`), service charge **14%**, marketing levy VAT-exempt. Hard-coded at charge seed time (`LeaseCreationService.php:104,117-118,127`).
- **Marketing levy is always re-derived to 5% of *current* base rent** on create/renew/change-rent; it is never copied stale.
- **Deposit default is `rent × 3`, but only via `LeaseCreationService`.** A code constant, not a setting. `security_deposit_received` defaults `false`.
- **Escalation is metadata, not automation.** No scheduled escalation exists. `next_escalation_date` resets to `null` on renewal.
- **Renewal carries deposit, escalation, percentage-rent, payment terms, notes, metadata, and the full unit set** — only rent/service charge/term/dates change.
- **Termination cancels only fully-unpaid open invoices** and **deactivates charges**; partially-paid invoices and the deposit are left for manual handling.
- **NOT-NULL booleans** (`has_percentage_rent`, `security_deposit_received`) have in-memory defaults so a service-created/renewed lease can't write `null` (`Lease.php:60-63`).
- **All money rounds to 2dp; amounts (not factors) are rounded** during proration so clean fractions bill exactly (`MonthlyBillingService.php:216-217`).
- **`Invoice::recomputeTotals()` remains the AR source of truth** — leases/charges feed line items; they never set `paid_amount` / `balance`. See `docs/money/00-money-model.md`.

---

## Where it lives in the code (file:line index)

| What | Where |
| --- | --- |
| Leases table schema (all money columns, enums, defaults) | `database/migrations/2024_01_01_000004_create_leases_table.php:11-49` |
| `percentage_rent_calculation_type` column | `database/migrations/2026_05_23_160331_add_percentage_rent_calculation_type_to_leases.php` |
| `lease_unit` pivot (multi-unit) + backfill | `database/migrations/2026_06_25_000001_create_lease_unit_table.php` |
| Lease model — casts, NOT-NULL defaults, derived helpers | `app/Models/Lease.php:60-79,178-204` |
| `Lease::units()` / `masterUnit()` / `syncUnits()` | `app/Models/Lease.php:81-134` |
| `LeaseObserver` — unit-status projection, master pivot | `app/Observers/LeaseObserver.php` |
| Create lease + seed standard charges (deposit `×3`, escalation `?? 7`) | `app/Services/LeaseCreationService.php:22-129` |
| `seedStandardCharges()` — Base Rent / Service Charge / Marketing | `app/Services/LeaseCreationService.php:82-129` |
| Renewal — carry terms, copy charges, resync levy | `app/Services/LeaseRenewalService.php:19-106` |
| Change rent — lease+charge sync, levy resync | `app/Services/LeaseRentChangeService.php:27-139` |
| Termination — status, charge deactivation, invoice cancel rule | `app/Services/LeaseTerminationService.php:25-78` |
| Marketing levy rate + amount + upsert | `app/Services/MarketingLevyService.php:21-56` |
| Marketing rate setting (`levy_rate_percent` default 5.0) | `app/Settings/MarketingSettings.php` |
| Charge model — VAT helpers, casts | `app/Models/Charge.php:24-63` |
| Monthly billing — proration, line/header math, idempotency guard | `app/Services/MonthlyBillingService.php:189-299` |
| Lease form — financial-terms section, deposit/escalation defaults, edit-lock | `app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php:172-270` |
| Quick-Lease wizard + Renew / Change rent / Terminate actions | `app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php:174-462` |
| Create page — seed charges + attach additional units | `app/Filament/Admin/Resources/Leases/Pages/CreateLease.php:20-37` |
| Demo lease data (deposit `×3`, escalation 7%, % rent) | `database/seeders/DemoSeeder.php:223-244` |
| Helper strings (deposit "3× rent", escalation labels) | `lang/en/admin/help.php` + `lang/en/admin/leasing.php` |

---

## Related

- `docs/money/00-money-model.md` — the AR single source of truth (`recomputeTotals()`); how line items become `total` / `paid_amount` / `balance`.
- `docs/money/01-billing-monthly.md` — the monthly billing run, proration, the run-lock, charge→invoice mechanics.
- `docs/money/03-marketing-levy.md` — the 5%-of-rent levy as a tenant line + the marketing-budget accrual.
- `docs/money/04-cam-reconciliation.md` — CAM annual reconciliation and positive/negative true-ups (separate from lease terms).
- `docs/money/07-credit-notes.md` — credit-note locking / reversal / auto-apply (how to void the *paid* portion of an invoice at termination).
- `docs/modules/` — the per-module references (leasing, billing/invoices) with business rules and extension points.
