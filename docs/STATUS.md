# Atriom — status, and what happens next

> **The one document.** Where the build is, what stands between it and real money, and whose desk
> each remaining item is on. It replaced `STATUS.md` and `STATUS.md` on
> **2026-08-24** — two lists of the same launch is how a stale one survives, and that had already
> happened twice: on 2026-08-23 a re-check found fourteen rows describing as missing something that
> had shipped, and the go-live gate still called Egyptian tax depreciation *"deliberately not built"*
> four days after it shipped with its own screen.
>
> **Every claim here was re-checked against the running code on 2026-08-24**, not copied forward.
> Where a number is quoted it was measured; where a default is described it was read out of the
> settings or the schema.

**Sections:** [0 · Where the build is](#0--where-the-build-is) ·
[1 · Blocks go-live — yours to configure](#1--blocks-go-live--infrastructure-and-credentials) ·
[2 · Blocks go-live — yours to decide](#2--blocks-go-live--decisions-only-you-can-make) ·
[3 · Before the first real month](#3--before-the-first-real-month) ·
[4 · Confirm a default](#4--confirm-a-default-silence-ships-it) ·
[5 · Do you need this?](#5--do-you-need-this--yes-means-code) ·
[6 · Wording we cannot build from](#6--requirement-wording-we-cannot-build-from) ·
[7 · Deliberately not blocking](#7--deliberately-not-blocking) ·
[8 · Closed — do not re-ask](#8--closed--the-code-answers-these) ·
[9 · The next step](#9--the-next-step)

---

## 0 · Where the build is

**The code is not the blocker, and has not been for some time.** Everything in §1 and §2 is a
credential, a piece of infrastructure, or a decision only you can make.

| Check | Result | When |
|---|---|---|
| Test suite | **6,597 tests, 6,577 passed, 20 skipped, 0 failed** (28,907 assertions) | 2026-08-26 |
| Pre-staging QA harness (real services, real MySQL) | **1,084 assertions, 0 failed** | 2026-08-24 |
| MySQL-only tier (locks, enums, column widths, search, **every filter on the real driver**) | **9/9, 2,594 assertions** | 2026-08-26 |
| Concurrency races (two processes, two connections) | **4/4 refused correctly** | 2026-08-23 |
| Browser suite (Playwright) | **442 passed** | 2026-08-23 |
| Conformance-gate audit | **69 of 72 gates mutation-proven**; 5 holes found and fixed | 2026-08-24 |
| Pre-staging findings (F-01 … F-13) | **all closed** | 2026-08-24 |
| **Final verification** — 8 lenses, 16 agents, every finding adversarially verified | **82 raised · 80 confirmed · 14 fixed · rest backlogged**; nothing blocking | 2026-08-24 |

**Since that round — an authorization pass on 2026-08-26.** Four defects, two of them holes rather
than gaps, all found by sweeping rather than by report:

- **The occupancy map had no `canAccess()` at all.** A Filament page without one is open to every
  authenticated user, and that page prints the NAME OF THE TENANT trading in each unit plus the
  mall's vacancy rate. An external maintenance contractor could read it, along with `technician`,
  `coordinator`, `customer_service`, `marketing` and `hr`. Its two neighbours in the same navigation
  group both gate on `reports.view`.
- **A `viewer` or `manager` pinned to ONE mall could read every mall's activity log.** The screen
  trusted that the grant "stops at the full-portfolio roles"; both of those roles hold the key and
  both can be pinned through the ordinary user form.
- Every filter in the panel 500'd the moment it was used on eleven tables (`order by tenants.tenant`),
  and a parking bay whose lease had expired still read *assigned* on the register for ever.

**What now stops them recurring** is the more useful half: all 14 roles are swept against all 99
screens through the real route (200 or 403, never a 500); every admin list is checked for
cross-property rows for a restricted operator, and every screen is requested under a mall the
operator does not hold; and a screen that NO role is refused now fails the build unless it is
registered as universal with a reason. The panel's filters are swept on both database drivers and
as a property-scoped operator as well as a super admin.

**The final verification round is done** — [qa/STAGING-FINAL-VERIFICATION.md](qa/STAGING-FINAL-VERIFICATION.md)
is the evidence (and §0 answers *"where do we stand against Yardi and the market?"*), and
[qa/POST-STAGING-BACKLOG.md](qa/POST-STAGING-BACKLOG.md) is what it deliberately left for later.
It closed two HIGH money findings before they could ever bill anyone — a cleared series cheque that
minted credit no invoice could draw (so a tenant could be late-fee'd while the mall held their cash),
and the deposit sub-ledger having no cutover path — plus seven armed-but-latent ones, each waiting on
something routine: a renewal, a resale, the C-TAX answer, a non-January fiscal year, a schedule
catch-up. **Three rows in this document changed as a result** and are marked below: C3.1 (bins are
built), C3.2 (stock transfers work; cross-property is a reasoned decline) and **A3.8 — consolidated
statements are NOT reachable**, which this document had been promising as shipped.

Two things worth knowing about that table:

- **The browser suite had run ZERO tests for over a month** before 2026-08-23 — global setup signed
  in as a user a rename had deleted, and a suite that cannot start produces no red, it produces
  nothing. It is guarded now (`E2eHarnessUsersExistTest`).
- **Five conformance gates were passing while checking nothing of substance.** Two had live money
  defects underneath: an invoice could be marked part-settled with no credit note behind it, and a
  spent credit note read unspent so the same credit could be given away twice. Both fixed. The audit
  is repeatable — `docs/qa/scripts/gate-audit.py`.

**All 72 gates now run.** `FixtureColumnsExist` was the last one switched off, pending pre-existing
ghost fixture keys; the 72 of them were cleared and the gate turned back on the same afternoon
(`7335552f`). This paragraph said otherwise for a few hours, which is how a re-verified document goes
stale — corrected here rather than left as a footnote.

---

## 1 · Blocks go-live — infrastructure and credentials

⚙️ = DevOps · 🔑 = a credential only you hold. **None of this needs an engineer from us.**

### 1.1 Backups ⚙️ — the highest-consequence row on the page

The dump itself was broken for nineteen days (2026-07-30 → 2026-08-18) and is **fixed and proven end
to end**: `backup:run` wrote an archive and `atriom:backup-verify` replayed it into a scratch
database — 135 tables, 1,279 statements. That is the first verified-restorable backup this project
has had.

- [ ] Ship the MySQL client in the deploy image (`mysqldump` must resolve for the app user), **or**
      set `DB_DUMP_BINARY_PATH` to the directory holding it. *(Verified: the seam exists on `mysql`
      and `mariadb` in `config/database.php`; empty means "use PATH".)* `atriom:health` fails
      production while neither is true.
- [ ] `BACKUP_DISKS="backups,s3"` — **verified: the default is `backups` only, a LOCAL disk.** A copy
      on the same machine as the database dies with the machine.
- [ ] `BACKUP_ARCHIVE_PASSWORD` — archives hold signed leases, tenant tax cards, vendor documents.
- [ ] `BACKUP_ALERT_EMAIL` — without it a failure is logged and pages nobody.

**If skipped:** the first hardware failure loses every invoice, payment and ledger entry.

### 1.2 Nothing off-box can see a failure ⚙️

- [ ] `SENTRY_LARAVEL_DSN` — **verified blank in `.env.example`.** Wired and inert until set.
- [ ] `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`.
- [ ] Point an uptime monitor at **`/health`** (not `/up`), and set `HEALTH_TOKEN` (**verified
      unset**). Without the token the endpoint returns status alone — the un-tokened command prints
      `null` on a perfectly healthy box, which reads as "no answer".

### 1.3 The demo password still ships 🔑

**Verified: `.env.example:17` ships `DEMO_USER_PASSWORD=password`.**

- [ ] Rotate it, or delete the demo accounts, **before the URL is shareable**. Every seeded user —
      including `admin@mall.test` (super_admin) — uses it.

### 1.4 PHP extensions must be present in FPM, not just the CLI ⚙️

`composer install` refuses on a box missing `intl`, `gd` or `zip` — but composer runs under
`php-cli` and the panel renders under `php-fpm`. One missing `.ini` symlink passes every install-time
check and throws on every money column in the panel.

- [ ] `curl -s -H "X-Health-Token: $HEALTH_TOKEN" https://<host>/health | jq '.checks.php_extensions'`
      — **over HTTP, not from the console.**

### 1.5 Paymob is sandbox-only 🔑

- [ ] Complete KYC, re-issue all four live credentials, re-register callbacks on the production
      domain, then run **one small real charge** ([integrations/PAYMOB-SETUP.md §6](integrations/PAYMOB-SETUP.md)).
- [ ] Run `php artisan integrations:check` after the live `.env` swap.

### 1.6 Secrets and network 🔑

- [ ] **Where do live Paymob credentials live, and who rotates them?** Four live keys in plaintext
      `.env`, no vault, no rotation procedure. A leaked HMAC secret lets someone forge a *paid*
      callback — invoices marked settled with no money arriving. *(The verification itself is sound:
      SHA-512, `hash_equals`, fails closed.)*
- [ ] **Is the app reachable ONLY through the reverse proxy?** `TRUSTED_PROXIES=*` is what makes
      login throttling and the audit trail see the real client IP — and is safe only while nothing
      can reach the app directly. If there is a direct address, give us the proxy IPs to pin.

### 1.7 ETA e-invoicing — 🧊 frozen, not a gate

The whole module is **frozen in code** (`Modules::FROZEN`), so `Modules::enabled('eta')` answers
false before any settings row is read and ETA appears nowhere in the running system. **This is not a
go-live gate any more** — it is the checklist for the day the freeze lifts: real `client_id` /
`client_secret`, a CAdES signing certificate, real EGS item codes and issuer TRN, then flip the mock
off. *(ETA work is on hold by the owner's own instruction.)*

### 1.8 The deploy path ⚙️ — closed

`./deploy.sh` is the production runbook in one command, and **refuses rather than continues** on a
dirty tree, a missing `npm`, an empty `public/build`, or pending migrations. It runs `atriom:install`
and `atriom:rebuild-search` — the two steps the runbook's prose calls REQUIRED and the script used
to skip, both of which fail silently. Production is behind a manual confirm; maintenance mode lifts
on an `EXIT` trap so a failed deploy cannot strand the box on a 503.

---

## 2 · Blocks go-live — decisions only you can make

🔴 **Answer before the first real invoice.** Some of these cannot be corrected afterwards without
leaving a visible break in the books. **Silence is a decision** — each row states what ships.

**Eight of these are checked live at `/admin/configuration-health`** (verified: 8 checks — seller tax
identity, billing contact, unclassified charge codes, uncommissioned tax codes, withholding, the
posting map, an open period, and whether the payroll rates were applied to the latest approved run).
On this workstation two are red right now: **seller tax identity** and **billing contact** — which is
A1.1 below, showing up exactly where it should.

**And since 2026-08-25 they are on the GATE, not only on a screen.** `atriom:config-health` runs the
same eight from the CLI and `atriom:preflight` runs it second, after `atriom:health` — so a release
can no longer report clean on an install with no seller TRN, an incomplete posting map or no open
period. Only BLOCKING rows change the exit code; `--strict` fails on the advisories too, which is
the cutover posture.

| # | Question | What ships if you say nothing | Who |
|---|---|---|---|
| **A1.1** | **The operator's tax registration number, registered legal name, and billing-enquiries email.** Settings → Tax. | **Blank.** The tenant cannot reclaim the VAT. The PDFs omit the line rather than print a placeholder, because a plausible-looking TRN gets filed by the tenant and fails on audit — and **since 2026-08-25 the invoice does not call itself a *Tax Invoice* either** until this is set; it is titled plainly *Invoice / فاتورة*, so an unconfigured install issues an incomplete document rather than a false one. The name falls back to *"Atriom"*, the software's name, which no tenant has seen on a lease. `atriom:config-health` reports this as BLOCKING, and since the same date it runs inside `atriom:preflight` rather than only on a screen. | Accountant |
| **A1.x** | **Sign off the tax treatment: which supplies are taxable, at what rate, from when** — including the one Law 157/2025 forces: **is base rent now taxed, and from what date?** | Rent exempt, services at 14%. **All configuration**: `charge_codes.tax_code` is the ruling as a row, the rate is a dated rung at `/admin/tax-codes`, so a rise can be entered in advance and a back-dated invoice keeps the rate in force. No release needed. | Accountant |
| **C-TAX** | **Which supplies carry stamp tax (ضريبة الدمغة) or schedule tax (ضريبة الجدول)?** | Both families are in the catalogue with their own accounts and posting treatment (output = liability, input = **expense**), and **no charge code points at one**. If a supply IS subject and nothing says so, it is under-taxed on the return. | Accountant |
| **A4.1** | **The real Egyptian chart of accounts.** *(The file supplied earlier was a Saudi contracting template — zakat, no VAT — and was rejected.)* | A starter Egyptian chart. **Importable now** (EG-28), keyed on `code`, order-independent. Also parked on you: **account code width, 8 vs 10 digits** — the system is width-agnostic, so it is your convention, not our constraint. | Accountant |
| **A9.1 / A9.2** | **Sign off the posting map** — do all 52 roles point at the right account in your chart? And **the 5% marketing levy: revenue, or a restricted fund (a liability)?** Shown on the tenant invoice? | Seeded mapping; levy as revenue, billed as a line. Re-pointable per role **and per property** from the screen. **→ code (XS)** only if you want a dedicated *marketing fund* role. | Accountant |
| **A2.7** | **Are invoices issued under Eltizam's TRN, or each owner's?** | One seller identity for the whole install. Two owners with two VAT registrations cannot both be billed correctly. **→ code (M)** for the per-asset issuer override. | Accountant + owner |
| **A3.7** | **Opening balances** — AR, AP, bank, deposits held, fixed assets — **and the cut-over date.** | Nothing loaded, so the first trial balance is wrong by exactly the history preceding it. **The machinery is built** (`/admin/opening-balances`, plus importers). Missing: your numbers and your date. | Accountant |
| **A8.3** | **What history migrates, how many years, and sample files?** | Scope undefined. Importers exist for tenants, units, leases, charges, opening invoices, fixed assets and the chart; they cannot guess which history matters. | Accountant + IT |
| **B.1 / B.3 / B.4 / B.5** | **How is Eltizam paid, and whose bank account does tenant money land in?** Fixed fee, % of collected, % of gross? Rent only or all charges? Before or after VAT? Is the fee VATable? Are *"owner"* and *"Jawad"* one party or two? | No management-fee engine, no operator↔owner money flow. **Owner statements and disbursements are built** and show net **before** fee. **→ code (M)** for the fee line once the basis is known; the wider revenue split is a finance workshop, not an email. | Owner (Jawad) |
| **C-NUM** | **The document number prefixes.** | `INV · CN · JE · BILL · EXP · DEP · RCT · PAY · PR · LSE · PDC`, **continuous** (Yardi's scheme; `annual` and `monthly` offered). **Hard deadline:** after the first issued invoice the prefix is printed on documents that cannot be renumbered, and changing it starts a *second* series. | Accountant |
| **C-FY** | **The month the fiscal year starts.** | January. **Refused once anything is posted** — moving it re-dates the periods. Free on an empty install, expensive after. | Accountant |
| **C-PAY** | **The statutory payroll rates** — salary tax and both social-insurance shares. | **0 · 0 · 0**, on a dated 1 Jan 2026 rung that already carries the insurable-wage band (2,700 / 16,700). Zero deliberately: a guessed rate looks authoritative and is wrong. **But all three at nil on an approved run means net = gross on every payslip and no liability in the books.** | Accountant / HR |
| **C4.2** | **Target go-live date, parallel-run period, and who validates the migrated data on your side.** | Undefined. Nothing can be scheduled around it. | Eltizam |

---

## 3 · Before the first real month

🟠 These change what the system does day to day.

| # | Question | What ships if you say nothing | Who |
|---|---|---|---|
| **C1.9** | **The final month of an expiring lease — is the tenant entitled to a credit for days they did not occupy?** | Full month billed, unearned part **credited at move-out**, on the lease's own proration rule (EG-29). On a 30k lease ending on the 10th: ~20,300 credited under `actual`, 20,000 under `thirty_day`, **nothing** under `whole_month`. The clause matters more than the arithmetic. | Operations |
| **C1.10** | **A tenant who stays past the lease end — automatic holdover, or always an operator's act?** | **An act.** Converting stamps `holdover_from` and `holdover_rate_pct` (default **150%**). An unconverted overstay is alerted and unbilled — billing past a lease nobody agreed to extend is a commercial claim, not a calculation. | Owner + operations |
| **C-SLA** | **Which SLA priorities are measured in WORKING time, and which on the calendar?** | **Verified empty — every priority runs on bare hours.** The calendar exists (Fri–Sat weekend, holidays register, Ramadan short days, per property) and ships off. Left unset, an urgent job raised Thursday 17:00 is due Friday 17:00 with nobody on site — **and a vendor SLA penalty is charged off that clock.** Each job freezes the clock it was promised on, so changing this never re-prices work in flight. | Operations |
| **C-NSF** | **Price the returned-cheque fee** (per property). | **0**, and the action stays hidden until a figure is set. Normally the bank's own charge plus an administrative component. | Operations + accountant |
| **C2.4** | **A vendor SLA penalty is booked as a cost reduction, so the saving flows into the CAM pool tenants reimburse — intended, or should the mall keep it?** | The benefit reaches tenants. | Operations + accountant |
| **A5.1 / A5.4** | **Is this workforce entitled to end-of-service gratuity, and who is covered by social insurance?** | Computed and reported under Labour Law 12/2003 Art. 122, and **posts nothing** — Art. 122 covers workers *not* under the social-insurance law, and most Egyptian employees are. **→ code (S)** to post the accrual once you rule. | Accountant / HR |
| **A5.3** | **Should the system compute statutory payroll at all, or is each run keyed by hand?** | Keyed per run. The numbers are a **dated ladder** resolved for the run's own month. **→ code (L)** for the seven-band progressive engine. | Accountant / HR |
| **A2.9** | **Confirm withholding rates by supply type** — published summaries disagree (1 / 2 / 3 / 5%). | Per-supplier rate → portfolio default → 0, on the VAT-exclusive share, with quarterly Form 41 and per-supplier certificates. Only the rates are missing. | Accountant |
| **A2.8** | **Do you charge a trade-name or brand component?** | Not charged, so not taxed. If you do, it is a charge code pointed at the schedule-tax code — configuration. | Owner + accountant |
| **C4.11** | **Which roles must have two-factor authentication, and from what date?** | **Nobody is forced.** `manager`, `accounting`, `leasing`, `operations`, `hr` handle payments and tenant data with no second factor. The recommended list is `SecurityDefaults::FORCE_2FA_ROLES`; paste it into `SECURITY_FORCE_2FA_ROLES` when ready. Switching it on marches every listed role through TOTP at next login — schedule it, and note it would block the people doing pre-go-live validation. | Eltizam IT |
| **C4.12** | **A user with no property assigned — see nothing, or everything?** | **The two layers disagree.** Query scoping treats no-assignment as unrestricted (single-mall back-compat); the panel refuses entry to every property. The result was an account that could open no page. **→ code (XS)** either way, once stated. | Eltizam IT |
| **—** | **Auto-apply open credit is ON** (Voyager's behaviour). Confirm. | On. A credit raised while a charge is in dispute will otherwise be consumed by the next invoice. | Operations |

---

| **A3.10** | **Writing off an unpaid SECURITY-DEPOSIT invoice line — what should it post?** A deposit line credits `deposits_held`, a **liability**, when the invoice is issued. The write-off journalizer books `Dr bad_debt_expense / Cr accounts_receivable` whatever the line was — so it charges an expense against revenue that was never recognised, and leaves the obligation standing on the balance sheet. | Posts as bad debt, as above. It bites only the day somebody writes off a deposit invoice, which is why it is here and not in §2 — but the entry is wrong on both sides until you rule. Recorded as SW-201. | Accountant |

## 4 · Confirm a default (silence ships it)

🟡 Built, working, reasonable. Each is one word from you — *"yes"*, or a different number.

| # | Confirm | Ships as |
|---|---|---|
| A1.2–A1.6 | Percentage rent, CAM true-up, late fees and the marketing levy are **VAT-exempt**; levy **5% of base rent only**, accrued and never shown to the tenant; CAM allocated **pro-rata by leased m²** | Every one is a row on `/admin/charge-codes` — a different ruling is a row, not a release |
| A1.7 | Late fee **2%** of outstanding, **minimum 50 EGP**, **7-day grace**, charged **once**, **no cap** | Five settings on three tiers (lease → property → portfolio); 0 = no cap, 0 = charge once |
| A1.8 | **Security deposit 3 months**, **escalation 7% fixed** | Deposit is a per-property setting; escalation is per lease, with a CPI-indexed option |
| A1.8b | **Is 7% the house escalation default?** | **Verified still a literal** in `LeaseCreationService` — a different house policy is keyed on every lease until changed (**XS**) |
| A1.9 | The **artificial breakpoint** for percentage rent — `(sales − threshold) × rate` | Per lease, with a natural-breakpoint option and monthly-vs-annual cumulation |
| A1.10 | **Payment terms 7 days** from issue | A per-property setting applied at origination; the lease then carries its own number |
| A3.2 | **Accrual, revenue at issue.** Straight-line rent (EAS 49) built and **off** | Flip it in Billing settings when your accountant decides |
| **A3.9** | **A tenant hands you a year of post-dated cheques — is that an ASSET the day you take them, or nothing until each one clears?** | **Nothing until it clears** (module 33's recorded v1 scope: register-only, settle-on-clear). The register tracks maturity, lodgement bank and the bounce lifecycle; the invoice stays open and the receipt is minted on clearing. The alternative is the Notes-Receivable accrual — `Dr 11205001 / Cr AR` on lodging — which is built as a documented refinement, not as code. Flipping it changes when revenue leaves AR, so it is yours |
| A3.4 | Period close blocks back-dated posting | As described |
| **A3.8** | **Reporting per property — and CONSOLIDATED is not reachable today.** The books support it (the year-end close already rolls a consolidated bucket) but no screen offers it: the six statements pin their property picker to the mall in the switcher, and All-Properties mode was removed by an earlier decision. A combined P&L for an owner holding both malls is currently two PDFs and a spreadsheet. | Per property, as described. **Consolidated needs a decision** — reopen the All-Properties question (**M**), or accept the per-property split |
| A5.2 | Payroll withholdings split into their own payable accounts | As described |
| A6.1 | **Egyptian tax depreciation rates per class** (5 / 10 / 25 / 50%, Law 91/2005 art. 25) | Built and computed; confirm the rates you file at |
| A6.2 / A9.6 | Monthly depreciation run, bilingual payslips, per-asset useful life and salvage | As described |
| A7.2 / A7.5 | Deposit is a refundable liability with no VAT; discounts through credit notes with approval | As described |
| A9.3 / A9.4 | CAM presented **gross**; inventory at per-movement unit cost (FIFO on receipts) | As described |
| A8.1 / A8.2 | The report set at go-live and the export format | 23 report pages, CSV + XLSX, saved views, scheduled email |
| B.2 / B.9 | Co-owners with % and dates; owner is oversight + requests, approves nothing before Eltizam acts | As described |
| B2.3 / B2.4 / B2.5 | Unit-owner صيانة is property revenue; no operator approval on a resale; a purchase-value owner's denominator is the **sold cohort** | As described |
| C1.1 | Unit types `retail · food_beverage · wellness · service · kiosk · office · storage`, statuses `vacant · reserved · occupied · maintenance` | **A code-side value set, not an operator catalogue** — a different list is a one-line change (**XS**) |
| C1.2–C1.6 | Renewal, escalation, early termination, fit-out grace (**full**: rent, service, CAM and levy all suppressed), manual sales declarations | As described |
| C2.1 / C2.2 | CAM pool contents and the annual true-up; utilities at cost, no markup, no cap | As described |
| C2.3 | The SLA hour targets the breach scan alerts on | Settings — the working-calendar half is C-SLA |
| C2.6 / C3.5 / C3.9 | Approval bands **1,000 / 10,000 EGP**; delete is super-admin only, money records never deletable | As described |
| C3.3 / C3.4 | Warehouse categories (free text) and one reorder level + quantity per item | As described |
| C4.3 | Training format and which roles | Undefined |
| D.2 | **Paymob card payments** — activate now or later? | Built, off |
| D.4 | Hosting, backup/DR expectations, and what happens when someone leaves | Cloud-ready; daily backups, weekly restore drill; deactivate rather than delete |
| E.3 | *"Admin (per mall) — full access"* does **not** include deleting records | Deletion is super-admin only; money records refuse outright |

---

## 5 · "Do you need this?" — yes means code

Each row is a capability the system does **not** have. The size is ours; the answer is yours. **None
is blocking** — if the answer is no, the row disappears.

| # | Question | Today | Size |
|---|---|---|---|
| **A2.6** | **Tax-exempt tenants** (free zone, government, NGO, embassy)? | Taxability resolves *charge code → tax code → dated rate*, one answer for the portfolio; no tenant or lease input. This is **EG-02** — the fix is that third input, expressed as a tax CODE, not a rate. | L |
| **A2.1** | **Do tenants withhold tax from rent**, and issue certificates you must track? | The **vendor** side is built. A tenant who withholds reconciles as an underpayment for ever. | M |
| **A3.3 / A7.3** | **Should rent billed in advance be deferred and recognised over the period?** | Recognised at issue. *(Straight-line rent IS built and switchable.)* | M |
| **A7.1** | **Should security cheques be their own class**, distinct from payment cheques? | The PDC register has no purpose column. | XS |
| **A9.5** | **Accrue a leave provision monthly?** | Not computed. Gratuity is A5.1. | S |
| **A9.8** | **A salary-tax return**, beside the VAT return and Form 41? | Not built. | S |
| **C1.8** | **Generate the lease contract as a PDF**, signature tracked in-system? | Uploading a signed lease works; nothing generates one. | M |
| **C2.5** | **Recharge a tenant-caused repair to that tenant?** VATable or cost recovery? Parts only, or parts + labour + the vendor's invoice? | Responsibility is recorded; there is no path from a work order to a tenant invoice. | M |
| **C2.7** | **Must a vendor bill back an externally-bought part before the job closes?** | Recorded; nothing requires a bill. | XS |
| **C3.1** | ~~**Bins or shelves inside a warehouse?**~~ **BUILT — no longer a question.** | `Bin` shipped 2026-08-18: master data, unique code per warehouse, a write-validated `resolveBinId()`, a bins relation manager and a bin picker on the movement forms. Answering *yes* would commission work that already exists. | — |
| **C3.2** | **Inter-mall stock transfers?** | **Same-property transfers WORK** — `StockMovementService::transfer()`, reachable from the stock-movements list. **Cross-property is refused by design**, with the reason in the code: value would cross the property boundary with no journal entry, so the documented path is adjust-out + receive-in and each mall's books record the movement. What is open is only whether you want that as ONE atomic action. | M |
| **C3.6** | **The approval chain for inter-department requests and payments routed through Accounting.** More than one approver for a large spend? | `approval_rules` is a single-level band lookup per module. | M |
| **C3.8** | **Per service: billed out or absorbed as a unit expense** — plus an annual report either way. | Not distinguished. | M |
| **C4.1** | **WhatsApp or SMS** to tenants? | Email, in-app bell and push (built, not live). | M |
| **C4.10** | **Should a role's authority differ per property?** | A role is portfolio-wide; property assignment is a separate list. Deliberately not built — **the trigger to revisit is the first person assigned to both malls.** | L |
| **C4.13** | **Should a technician be emailed when work is assigned?** | Bell only. Mailing everything trains people to ignore the alerts that matter. | XS |
| **E.4** | **Must completing a work order require a photo?** | Tenant-request evidence shipped; work-order photos can be attached, not required. | XS |
| **E.5** | **Is who/when/from→to enough for status history**, or do you need per-step comments and attachments? | Who/when/from→to is recorded. | M |
| **B2.1 / B2.2** | **Which GL account does the unit-owner letting FEE post to**, and **is there a sinking fund (صندوق صيانة)**? | Both block module 37's phase 5. The fee % and basis are configurable; only the account is missing. | XS |
| **B.7 / B.5 / B.8** | **A float per property? Tenant money in trust/escrow per property? Is each mall a separate legal entity with inter-company entries?** | None modelled; single-company GL with a property dimension. | M / L / XL |
| **A7.4** | **Is anything really billed in USD or EUR?** | **EGP only, and enforced.** If a lease is USD-*linked*, index the escalation and denominate in EGP (EG-31) rather than full multi-currency. | M |
| **C3.7** | **"Personal accounts" (محسوبات شخصية)** — who exactly, and what for? | **Custody (عهدة) and employee advances are built** and post to the GL. A per-person sub-ledger beyond those does not exist, and cannot be sized until we know who it is for. | ? |

---

## 6 · Requirement wording we cannot build from

Each needs **one clarifying sentence**.

| # | Question | Why we stopped |
|---|---|---|
| **E.1** | **FR-REQ-01 "delegation (from/to)"** — what does it mean? | No such concept exists anywhere in the system or the rest of the FRD |
| **E.2** | **FR-PPM-01 "Fixed maintenance"** — one-time, or periodic per asset? | The FRD says **both**, in different sentences. We support periodic |

---

## 7 · Deliberately not blocking

Measured or decided, not forgotten. Re-opening one should require new evidence.

- **CI auto-runs stay off** — the owner's standing call. Keep `pest --parallel` green locally,
  because a red push is silent rather than a red check.
- **Dating `Asset.leasable_area_sqm`** — declined: no pool uses the GLA basis and the denominator is
  already frozen per pool.
- **Notifying on every ledger re-derive** — declined: it would fire on each late fee and CAM run.
- **Bank-reconciliation suggested matches** — the manual path works, and a suggester that is usually
  right is exactly what stops being read. Worth building after someone reconciles a real month.
- **Deposit batches, bank feeds, multiple books, multi-currency, POS feeds, IoT/predictive
  maintenance** — declined breadth, per the Yardi and Odoo benchmarks.
- **The straight-line rent engine ships OFF**, awaiting the accountant's ruling.
- **No technician mobile app** — technicians use the admin panel, so that role's UX in the panel is
  the requirement. *(Open UX question: on a phone the work-order list shows cost variance but hides
  `equipment.code` and `scheduled_for`, and `visibleFrom('md')` is not a toggle the operator can
  override.)*

---

## 8 · Closed — the code answers these

Do not re-ask. One line apiece so a link from another document still resolves.

| # | Why it is closed |
|---|---|
| A2.2 | **Stamp tax is built** — in the dated catalogue with its own posting roles; bills the moment a charge code points at it. *(Which supplies is C-TAX.)* |
| A2.3 | **Real-estate tax: the cost side is built** (EG-33), including **semiannual**, Egypt's two instalments. The **assessment** is deliberately not modelled — a computed guess would go on a statutory filing |
| A2.4 · D.1 · D.3 | **e-invoicing is FROZEN in code.** Not a question and not work to schedule |
| A2.5 | **The VAT return is built** — `/admin/vat-return`, by document, with the ledger tie-out |
| A3.1 | Full double-entry GL with a property dimension |
| A3.5 | **Bank reconciliation is built**; since EG-12 each document carries its own bank account, so two banks no longer share one chart account |
| A3.6 | **Write-off is built** — and the tenant still sees the `written_off` status |
| A3.7 *(mechanism)* | **The opening-balance screen and importers are built.** Only your figures and date are missing |
| A4.1 *(mechanism)* | **The chart is importable** (EG-28). Only the file is missing |
| A5.1 *(half)* | The **employer's** social-insurance contribution is recorded and posts |
| A5.3 *(mechanism)* | **Payroll numbers are a dated ladder** (EG-03) resolved for the run's own month |
| A6.1 | **Egyptian tax depreciation is built** — statutory pools and the temporary difference. A schedule, not a second ledger, because Egypt files single-book |
| A7.1 *(half)* | **The PDC register is built** — lifecycle, bulk lodging, maturity dashboard, GL posting |
| A9.7 | **A bank account per mall** (EG-12) and **configurable numbering** including the reset rule (EG-10) |
| A9.8 *(half)* | **Form 41 is built** (EG-21) — quarterly, per registration, with certificates and a tie-out |
| B.6 | **Owner statements and disbursements are built.** Only the fee line waits, on B.4 |
| C1.1 *(half)* | **The occupancy map is built** — a per-floor grid with each unit's status and tenant |
| C1.5 | **Fit-out grace is built** — a full grace for whole months from commencement |
| C1.7 | **Both halves built** — multi-user portal accounts, and tenant documents with COI type, expiry and a scheduled scan |
| C1.9 *(arithmetic)* | **Proration is the lease's to state** (EG-29). Only the entitlement is open |
| C1.10 *(billing)* | **A holdover IS billable** once converted. Only the automatic-conversion policy is open |
| C2.3 *(calendar)* | **The working calendar is built** (EG-08/EG-38). Ships off; see C-SLA |
| C3.3 *(mechanism)* | `warehouses.category` is free text |
| C3.7 *(two thirds)* | **Custody (عهدة) and employee advances are built**, both posting to the GL |
| F-01 … F-13 | **Every pre-staging finding is closed** — see [qa/PRE-STAGING-FINDINGS.md](qa/PRE-STAGING-FINDINGS.md) for the evidence per finding |

---

## 9 · The next step

In order, and nothing here is blocked on us:

1. **Answer A1.1** — the TRN, legal name and billing email. It is one screen, it is the single
   highest-consequence answer on this page, and `/admin/configuration-health` is red on it today.
2. **Do §1.1** — the backup boxes. One hardware failure without them loses everything.
3. **Send the chart of accounts (A4.1) and the opening balances + cut-over date (A3.7).** Both
   importers are built and waiting; these are the long-lead items on your side.
4. **Decide C-NUM and C-FY** before the first invoice — both have hard deadlines and neither can be
   undone cleanly.
5. **Set a go-live date and a parallel-run period (C4.2)**, so the rest can be scheduled against it.

Everything else in §2 and §3 can follow, and §4 needs nothing but a nod.

### 9.1 · The accountant's sitting

**Twenty of the rows above are the accountant's, and they are not twenty conversations.** Taken in
this order they are one meeting and two follow-ups. Row IDs only — the rows themselves are above, and
each already states what the system does *today* while it waits.

**Sitting one — nothing correct can be issued without these.** *(§2, plus §3's A3.7.)*

| Order | Rows | The stake in one line |
|---|---|---|
| 1 | **A1.1** | Until the TRN is set, no document may call itself a *Tax Invoice* — so **the tenant cannot reclaim the VAT you charged them** |
| 2 | **A1.x · C-TAX** | Which supplies are taxable, at what rate, from when — including whether **Law 157/2025 now taxes base rent** |
| 3 | **A4.1** | The real Egyptian chart, and your 8-vs-10-digit code convention. The longest lead time on your side |
| 4 | **A9.1 / A9.2** | Sign off the 52 posting roles, and rule on the **5% marketing levy: revenue, or a fund you hold** |
| 5 | **A3.7 · A8.3** | Opening balances and the cut-over date. Without them your first trial balance is wrong by exactly the history before it |
| 6 | **C-NUM · C-FY** | Both have **hard deadlines**: after the first issued invoice neither can be undone cleanly |

**Sitting two — tax detail.** **A2.9** (withholding rates: the published summaries disagree) ·
**A2.7** (one seller identity, or one per owner) · **A2.8** (is a trade-name component charged).
Nothing breaks while these wait; the engines are built and inert.

**Sitting three — payroll.** **C-PAY** is the sharp one: all three statutory rates are **0**, so an
approved run gives **net = gross on every payslip and no liability in the books**. Then **A5.1 /
A5.4** (gratuity entitlement, social-insurance coverage) and **A5.3** (compute statutory payroll at
all, or key each run by hand).

**A nod, not a meeting — five switches that are built and OFF.** Each ships a defensible default and
changes nothing until ruled on: **A3.2** straight-line rent (EAS 49) · **A3.9** post-dated cheques as
notes receivable · **A3.10** what a written-off deposit line posts *(the one place the current entry
is arguably wrong on both sides)* · **C2.4** whether an SLA penalty's benefit reaches tenants through
the CAM pool · **C-NSF** the returned-cheque fee, hidden until priced.

**Do not send this as twenty questions.** Sitting one is the whole of what stops you issuing a
correct invoice and opening the books; everything else can follow, because each remaining row either
ships off or reports rather than guesses.

---

**Where the detail lives:** [BUSINESS-RULES.md](BUSINESS-RULES.md) (every rule + risk level) ·
[EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md) (what an operator can change without a developer) ·
[ROADMAP.md](ROADMAP.md) (the prioritised backlog) ·
[gap-analysis/README.md](gap-analysis/README.md) (what the benchmarks do that Atriom does not) ·
[operations/STAGING.md](operations/STAGING.md) (a staging box is gated on none of this) ·
[operations/PRODUCTION-RUNBOOK.md](operations/PRODUCTION-RUNBOOK.md) (how to deploy) ·
[requirements/CLIENT-DISCOVERY-ANSWERS.md](requirements/CLIENT-DISCOVERY-ANSWERS.md) (answers already collected).

**Sign-off**

| Section | Owner | Date |
|---|---|---|
| Tax, GL, payroll, assets, opening balances (A·) | *accountant* | |
| Owner money-flow (B·) | *owner (Jawad) + Eltizam finance* | |
| Operations (C·) | *Eltizam operations lead* | |
| IT, hosting, secrets (D·) | *Eltizam IT* | |
| Requirement wording (E·) | *Eltizam operations lead* | |
