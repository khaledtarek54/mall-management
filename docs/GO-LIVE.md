> **Six of these items are now checked in the app** (2026-08-12): `/admin/configuration-health`
> reads the live database for the seller's tax registration number, unclassified charge codes,
> uncommissioned tax codes, withholding with nothing to withhold, the posting map, and whether today
> falls in an open period. Each row says what the gap breaks.
>
> This file is still the wider gate — it covers credentials, infrastructure and unanswered questions
> that no query can see — but the parts a query CAN see no longer depend on somebody re-verifying
> this document by hand.

# Atriom — the go-live gate

**One document. Everything that must be CONFIGURED or ANSWERED before this system carries real
money.** Compiled and verified against the running code 2026-08-11.

> **Why this exists.** The remaining work was spread across five documents — the roadmap's P0 rows,
> `OPEN-QUESTIONS.md`, the ETA/Paymob certification notes, the production runbook and three
> gap analyses — so nobody could answer "what is actually left?" without reading all of them and
> knowing which claims had gone stale. This is that answer, in one place, with each row re-checked
> against the code rather than copied forward.
>
> **The headline: the code is not the blocker and has not been for some time.** Every row below is
> either a credential, a piece of infrastructure, or a decision only the client can make. The build
> is 4,156 tests green.
>
> **How to read a row.** ⚙️ = infrastructure/DevOps · 🔑 = a credential or account only the client
> holds · 🧑‍💼 = a business decision. "Verified" is what the code does *today*, checked while writing
> this — not what a doc said last month.
>
> **Going to staging, not live?** None of this page blocks a staging box. That gate is
> [STAGING.md](STAGING.md) — what staging is for, its `.env` deltas, and which of its red health
> rows are supposed to be red.

---

## 1. 🔴 Blocks go-live — money is wrong or invisible without these

### 1.1 Backups are running and writing nothing ⚙️

**Verified: `mysqldump` is not on PATH.** `backup:run` exits 127 and has produced no archive since
this was first recorded on **2026-07-30**. `atriom:health` reports it under `backup_capability` and
has been reporting it correctly the whole time.

> **Every box below is the OPERATOR's — none of it is code, and that is the finding.** The check was
> never missing; nothing forced anyone to look at it. So as of 2026-08-12 **`atriom:install` reports
> backup capability itself**, in an error block naming each problem, instead of closing with
> *"run `atriom:health`, it also checks backups"* — which is what it did for those twelve days.
> Reported rather than fatal: configuring backups after installing is a legitimate order of
> operations, and an installer that refused would simply be run with the check disabled.
> (`InstallReportsBackupCapabilityTest`.)

- [ ] Ship the MySQL client in the deploy image (`mysqldump` must resolve for the app user).
- [ ] `BACKUP_DISKS="backups,s3"` — **verified default is `backups` only, a LOCAL disk.** A copy on
      the same machine as the database dies with the machine, which is not a backup.
- [ ] `BACKUP_ARCHIVE_PASSWORD` — archives hold signed leases, tenant tax cards and vendor documents.
- [ ] `BACKUP_ALERT_EMAIL` — without it a failure is recorded (`OpsLog`) but pages nobody.

**If skipped:** the first hardware failure loses every invoice, payment and ledger entry. This is
the single highest-consequence row on the page.

### 1.2 Nothing off-box can see a failure ⚙️

- [ ] `SENTRY_LARAVEL_DSN` — wired and inert until set. PII is withheld by config.
- [ ] `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`.
- [ ] Point an uptime monitor at **`/health`** (not `/up`). It already fails on a dead scheduler, a
      stopped queue worker, a stale backup or a DB outage — and is worth nothing until something
      external polls it. Set `HEALTH_TOKEN` (**verified unset**) to expose detail to the monitor.

**If skipped:** every money-path failure — a refused GL posting, a failed backup, a stopped worker —
is visible only to someone who happens to open `/admin`.

### 1.3 ~~There is no deploy workflow at all~~ — CLOSED 2026-08-13 ⚙️

*Row corrected 2026-08-16: this was verified 2026-08-11 and went stale two days later.*
**`./deploy.sh` exists at the repo root.** It is [PRODUCTION-RUNBOOK.md §2](PRODUCTION-RUNBOOK.md)
in one command — `git pull` → `composer install --no-dev` → `npm ci && npm run build` →
`migrate --force` → cache rebuild → `queue:restart` → `atriom:health` — and **refuses rather than
continues**: a dirty working tree, a missing `npm`, an empty `public/build`, or `--skip-migrate`
with migrations pending all stop it. Production is gated behind a manual confirm; staging deploys
freely. Maintenance mode is lifted by an `EXIT` trap, so a failed deploy cannot strand the box on a
503 with nobody knowing why.

What remains is not a deploy workflow but the two things it cannot do for you: **ship the MySQL
client in the image** (§1.1) and hold the secrets it deploys with.

### 1.4 ETA e-invoicing cannot legally submit 🔑

**Verified: `EtaSettings::$mock = true`**, and `eta.signing.enabled` defaults false.

- [ ] Real `client_id` / `client_secret` from the operator's ETA taxpayer profile.
- [ ] A **CAdES signing certificate** — ETA production rejects unsigned B2B documents.
- [ ] Real **EGS item codes** + issuer TRN / legal name / address (placeholders ship today: issuer
      TRN `100000000`, EGS `EG-6820-001`). Wrong codes are rejected at submission.
- [ ] Flip `mock` off — one setting, gated on the three above.

**If skipped:** invoices are not legally valid tax documents. *(Note: ETA work is on hold by the
owner's own instruction; this row records the gate, it does not ask for work.)*

### 1.5 Paymob is sandbox-only 🔑

- [ ] Complete KYC, re-issue all four live credentials, re-register callbacks on the production
      domain, then run **one small real charge** (`PAYMOB-SETUP.md §6`).
- [ ] Run `php artisan integrations:check` after the live `.env` swap — it validates Paymob and ETA
      credentials and exits non-zero on failure.

### 1.6 The seeded demo password still ships 🔑

**Verified: `.env.example` ships `DEMO_USER_PASSWORD=password`.**

- [ ] Rotate it, or delete the demo accounts, **before the URL is shareable**. Every seeded user —
      including `admin@mall.test` (super_admin) — uses it.

---

## 2. 🔴 Questions only the ACCOUNTANT can answer

These block correct books, not merely features. Tracked bilingually in
[ACCOUNTANT-BRIEFING.md](accounting/ACCOUNTANT-BRIEFING.md); full detail in
[OPEN-QUESTIONS.md §A](OPEN-QUESTIONS.md#a--accountant--finance).

| # | Question | Why it blocks | Today |
|---|---|---|---|
| **A4** | **The real Egyptian chart of accounts.** The file supplied was a Saudi contracting template and was rejected. | Every posting resolves through `account_mappings` to accounts that must be the accountant's, not ours. | A sample chart ships; **code width is 8-vs-10 digits, parked on the accountant** (the system is width-agnostic and proven so) |
| **A1** | **The tax rates the billing engine computes from** — and confirmation that **base rent is VAT-exempt while service charge is standard-rated**. | **Corrected 2026-08-12:** the rate is no longer a setting. It lives in the **dated tax catalogue** at `/admin/tax-codes` — `TaxSettings::vat_standard_rate` is gone — so a rate is a rung with an effective date and a rise can be entered before it applies. Which supplies are taxable is `charge_codes.tax_code`, the accountant's ruling as a row. Both are configuration answers, not builds. | 14% standard, base rent exempt |
| **A1.1** | **The operator's own tax registration number** (and registered legal name, if it differs from the mall's trading name). One field, one answer — but nothing else unblocks it. | **Every invoice is titled "Tax Invoice" and is not a valid one without it: the tenant cannot reclaim the VAT.** Since 2026-08-17 the **credit note** carries the same particulars and has the same dependency — it is what the tenant uses to *reverse* input tax already claimed, so an unidentifiable one is the same defect pointing the other way. Entered at Settings → Tax → Seller identity. Deliberately **blank** on a fresh install, and the PDFs omit the line rather than printing a placeholder — a plausible-looking TRN reads as valid, gets filed by the tenant, and fails on audit with Atriom's name on the document. | **Blank — must be set before the first real invoice is issued** |
| **A1.2** | **`seller_legal_name`** specifically. Not blocking for VAT, but not cosmetic. | It is the name **every generated document leads with** (`App\Support\IssuingEntity`) — the twelve PDFs, the invoice email, and the hosted **payment page a cardholder reads before entering their card details**. Left blank, all of them fall back to `"Atriom"`, which is the software's name and one no tenant has seen on a lease. An unrecognised merchant on a card page is what a chargeback starts as. | **Blank — set it with A1.1** |
| **A9.1/A9.2** | **Sign off the posting map**, and the treatment of the **5% marketing levy**. | The map is editable on screen; what it should SAY is an accounting decision. | Defaults seeded |
| **A3.7** | **Opening balances** — AR, AP, bank, deposits held, fixed assets at cut-over. | Without them the first trial balance is wrong by exactly the history that preceded it. | Not loaded |
| **A5** | **End-of-service gratuity** — accrual basis and rate. | Employer social insurance **is** already recorded; gratuity is the remaining half. | Not accrued |
| **A6** | **Egyptian tax depreciation** (Law 91/2005 pool/diminishing-value) + whether a second tax book is required. | Depreciation is straight-line only, so there is no tax-basis figure. **I deliberately did not build this — it would be inventing tax policy.** | Straight-line only |
| **A2.1** | **Tenant-side withholding tax** — do tenants withhold on rent, and at what rate? | The vendor side shipped; this half is unmodelled. | Not modelled |

---

## 3. 🔴 Questions only the OWNER (Jawad) can answer

| # | Question | What is waiting on it |
|---|---|---|
| **B.1 / B.3 / B.4 / B.5** | **How is Eltizam paid, and whose bank account does tenant money land in?** | The **owner-statement management fee** is built-but-omitted pending B.4. More seriously, the **Jawad/Eltizam revenue split** (FR-FIN-06..09) needs legal entities, issuer-vs-payer separation, effective-dated split rules and per-entity VAT — it touches every journalizer and **ETA's single hardcoded issuer TRN, which cannot express two entities**. It therefore *constrains ETA go-live*. Needs a finance workshop, not an email. |

---

## 4. 🟠 Decisions the OPERATOR should make before the first real month

| # | Decision | Today | Consequence of not deciding |
|---|---|---|---|
| **C1.9** | **The final month of an expiring lease — full month or by occupied days?** *(Row corrected 2026-08-11: this was recorded as unremedied over-billing. It is not — the full month is billed and `CreditUnearnedBillingService` credits the unearned portion at move-out, using `monthsCovered()`, the same rule the invoice used.)* The remaining question is narrower and still real: **is the tenant entitled to that credit at all**, or does the lease make rent due for any month the term runs into? | Bill full, credit the unearned part on move-out | ~20,300 EGP per departing tenant on a 30k lease, in the wrong direction if the lease says otherwise |
| **C1.10** | **A tenant who stays past lease end — do they keep paying, and at what rate?** Commercial leases usually charge 125–150% of the last rent. *(Row corrected 2026-08-12: "not billed" was stale.)* | **Alerted, and billable once an operator CONVERTS the lease to holdover** (`ConvertLeaseToHoldoverService`, story LE-04). Conversion stamps `holdover_from` — which is what makes the lease billable past its own expiry — and `holdover_rate_pct`, defaulting to `BillingSettings::holdover_default_rate_pct` (150%). An UNCONVERTED overstay is still alerted and unbilled. | The remaining decision is narrower: **should conversion be automatic on expiry, or always an operator's act?** It is deliberately an act today, because billing a tenant past a lease nobody has agreed to extend is a commercial claim rather than a calculation |
| **C4.10–C4.13** | Access and alerting decisions (who is forced into 2FA, who receives which alert). | **Verified: `SECURITY_FORCE_2FA_ROLES` is empty — nobody is forced into 2FA.** `App\Support\SecurityDefaults::FORCE_2FA_ROLES` is the recommended list to paste in. | Admin accounts are password-only |
| **C-NUM** | **Document number prefixes** — the letters in front of every invoice, credit note, journal entry, bill, expense, deposit, payroll run, purchase request, lease and cheque. *(Configurable since 2026-08-12; Settings → Accounting.)* | Defaults `INV` · `CN` · `JE` · `BILL` · `EXP` · `DEP` · `PAY` · `PR` · `LSE` · `PDC` | **This one has a hard deadline.** After the first issued invoice the prefix is printed on documents that cannot be renumbered, and changing it later starts a SECOND series rather than renumbering the first — a jump an auditor will query on a tax-invoice sequence. Ask the accountant (Q-COA-5) before the first real month, not after |
| **C-FY** | **The month the fiscal year starts.** *(Configurable since 2026-08-12; Settings → Accounting.)* | January (calendar year) | **Refused once anything is posted**, because moving it re-dates the periods: a document posted into an open period lands inside a closed one, or an entry the accountant closed and reported becomes editable again. Decide on the empty install — which is exactly when it is free |
| **D.5 / D.6** | **Paymob secret storage** (vault vs plaintext `.env`) and **whether the app is reachable outside the proxy** — `TRUSTED_PROXIES` defaults to `*`, which is safe *only* while it is not. | Plaintext `.env`; verification itself is sound (SHA-512 + `hash_equals`, fails closed) | A leaked HMAC forges "paid" callbacks |
| **—** | **Auto-apply open credit** is now **ON by default** (Voyager's behaviour, decided 2026-08-11) with a global switch in Billing settings. Confirm that suits — a credit raised in dispute will otherwise be consumed by the next invoice. | On | A support call, occasionally a legal one |

---

## 5. 🟢 Deliberately NOT blocking — recorded so nobody re-opens them

Each of these was measured or decided, not forgotten. Re-opening one should require new evidence.

- **CI auto-runs stay off** — the owner's standing call. The suite is ~1.5 min on a quiet machine
  (the "20+ min" figure in the roadmap was stale and is corrected); keep `pest --parallel` green
  locally, because a red push is silent rather than a red check.
- **Dating `Asset.leasable_area_sqm`** — measured and declined: no pool uses the GLA basis, the
  denominator is already frozen and recorded per pool, and a property's GLA changes every few years.
- **Notifying on every ledger re-derive** — declined: it would fire on each late fee and each CAM
  run, and an alert arriving dozens of times a month is one nobody reads.
- **Bank-reconciliation suggested matches** — built last on purpose and still unbuilt: the manual
  path works, and a suggester that is usually right is exactly what stops being read. Worth building
  only after someone has reconciled a real month by hand.
- **Deposit batches, bank feeds, multiple books, multi-currency, POS feeds, IoT/predictive
  maintenance** — declined breadth, per the Yardi and Odoo benchmarks.
- **The straight-line rent engine ships OFF**, awaiting the accountant's ruling. Turning it on is a
  settings change, not a build.

---

## 6. Requirements that cannot be built as written

Five clarifications in [OPEN-QUESTIONS.md §E](OPEN-QUESTIONS.md#e--requirements-we-cannot-build-as-written).
The sharpest is **FR-REQ-01 "delegation (from/to)"** — no such concept exists anywhere in the system,
and it cannot be inferred from anything the client has described.

---

## 7. What happens if nothing is answered

The system runs, bills correctly against the rules it has, and posts a complete double-entry ledger.
What it **cannot** do is file a legally valid tax invoice, take a real card payment, survive the loss
of its machine, tell anyone when something breaks, or produce a trial balance that includes the
history preceding cut-over.

**The shortest path to a defensible go-live is §1 — every row is infrastructure or a credential, and
none of it needs an engineer.**

---

**Everything that is NOT on this page** — the improvements worth making once the system is live,
what is blocked behind which answer, and what is deliberately declined — is in
[BACKLOG.md](BACKLOG.md).

---

*Sources consolidated here: [ROADMAP §2–§3](ROADMAP.md), [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md),
[ETA-PAYMOB-CERTIFICATION.md](ETA-PAYMOB-CERTIFICATION.md),
[PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md), [accounting/GAP-ANALYSIS.md](accounting/GAP-ANALYSIS.md).
Those stay the detail; this stays the gate. **When a row here is done, tick it here and update its
source** — a checklist that disagrees with its sources is worse than neither.*
