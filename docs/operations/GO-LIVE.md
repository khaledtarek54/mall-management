> **Eight of these items are now checked in the app** (2026-08-12, extended 2026-08-21):
> `/admin/configuration-health` reads the live database for the seller's tax registration number,
> the billing contact printed on every document, unclassified charge codes, uncommissioned tax
> codes, withholding with nothing to withhold, the posting map, whether today falls in an open
> period, and whether the statutory payroll rates were actually applied to the latest approved run.
> Each row says what the gap breaks.
>
> This file is still the wider gate — it covers credentials and infrastructure no query can see —
> but the parts a query CAN see no longer depend on somebody re-verifying this document by hand.
> **The QUESTIONS moved out on 2026-08-23:** they all live in
> [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md), and §2 below lists only which IDs block a launch.

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

**✅ The dump half is fixed (2026-08-18) — and the earlier diagnosis here was wrong.**
`backup:run` exited 127 and produced no archive from **2026-07-30 to 2026-08-18**, nineteen days.

> **This section used to say "every box below is the OPERATOR's — none of it is code, and that is
> the finding." That was wrong, and it is why the row sat open for nineteen days while everyone
> who looked at it concluded there was nothing to build.** `Health::checkBackupCapability()` had
> read `database.connections.{driver}.dump.dump_binary_path` since the day the failure was found —
> but **that key existed on no connection**, so it could only ever resolve to `''` and fall through
> to a PATH lookup that kept failing. The check was correct and unanswerable: no `.env` value could
> have fixed it. The seam now exists on `mysql` + `mariadb` as `DB_DUMP_BINARY_PATH` (a DIRECTORY;
> empty means "use PATH", which is right on any image that installs the client normally).
>
> **A second bug was hiding behind the first.** `VerifyBackupService::CRITICAL_TABLES` named
> `journal_entry_lines` — a table that has never existed in this schema (it is `journal_lines`) —
> so `atriom:backup-verify` would have answered *BACKUP NOT RESTORABLE* for every healthy archive.
> Nobody saw it because the dump crash meant the drill never reached that check. A restore drill
> that always fails is worth less than none: it teaches whoever reads it to ignore the one run that
> matters. Both are pinned by `BackupCanActuallyRunTest`, which checks the seam EXISTS (the bug was
> a missing key, not a wrong value) and that every critical table is really in the schema.
>
> **Proven end to end, not asserted:** `backup:run` wrote an archive and `atriom:backup-verify`
> replayed it into a scratch database — 135 tables, 1,279 statements, every critical table with
> rows. That is the first verified-restorable backup this project has had.
>
> As of 2026-08-12 **`atriom:install` reports backup capability itself**, in an error block naming
> each problem. Reported rather than fatal: configuring backups after installing is a legitimate
> order of operations, and an installer that refused would simply be run with the check disabled.
> (`InstallReportsBackupCapabilityTest`.)

**The remaining boxes ARE the operator's:**

- [ ] Ship the MySQL client in the deploy image (`mysqldump` must resolve for the app user), **or**
      set `DB_DUMP_BINARY_PATH` to the directory holding it. `atriom:health` fails production while
      neither is true.
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

**🧊 The whole module is FROZEN as of 2026-08-22** — registered in `App\Support\Modules::FROZEN`, so `Modules::enabled('eta')` answers false before any setting is read and ETA appears nowhere in the running system. This section is therefore **not a go-live gate any more**; it is the checklist for the day the freeze lifts. `EtaSettings` was deleted with it (it was inert); the mock flag now lives only in `config/eta.php` (`ETA_MOCK`, default true), and `eta.signing.enabled` defaults false.

- [ ] Real `client_id` / `client_secret` from the operator's ETA taxpayer profile.
- [ ] A **CAdES signing certificate** — ETA production rejects unsigned B2B documents.
- [ ] Real **EGS item codes** + issuer TRN / legal name / address (placeholders ship today: issuer
      TRN `100000000`, EGS `EG-6820-001`). Wrong codes are rejected at submission.
- [ ] Flip `mock` off — one setting, gated on the three above.

**If skipped:** invoices are not legally valid tax documents. *(Note: ETA work is on hold by the
owner's own instruction; this row records the gate, it does not ask for work.)*

### 1.5 Paymob is sandbox-only 🔑

- [ ] Complete KYC, re-issue all four live credentials, re-register callbacks on the production
      domain, then run **one small real charge** ([`integrations/PAYMOB-SETUP.md` §6](../integrations/PAYMOB-SETUP.md)).
- [ ] Run `php artisan integrations:check` after the live `.env` swap — it validates Paymob and ETA
      credentials and exits non-zero on failure.

### 1.6 The seeded demo password still ships 🔑

**Verified: `.env.example` ships `DEMO_USER_PASSWORD=password`.**

- [ ] Rotate it, or delete the demo accounts, **before the URL is shareable**. Every seeded user —
      including `admin@mall.test` (super_admin) — uses it.

### 1.7 PHP extensions must be present in FPM, not just in the CLI ⚙️

`composer install --no-dev` already refuses on a box missing `intl`, `gd` or `zip` — the dependency
tree requires them. **But composer runs under `php-cli` and the panel renders under `php-fpm`,** and
a box with an extension in one and not the other passes every install-time and console-time check
while throwing on every money column in the panel. One missing `.ini` symlink does it.

- [ ] `curl -s -H "X-Health-Token: $HEALTH_TOKEN" https://<host>/health | jq '.checks.php_extensions'`
      — **over HTTP, not from the console.** It names any missing extension and what it costs. The
      nine and their consequences are in `App\Support\PhpExtensions`; `composer check-platform-reqs`
      covers only the CLI half. **The token is required**: without it the endpoint returns `status`
      alone (detail names internal subsystems and can carry a DB error), so the un-tokened form of
      this command prints `null` on a perfectly healthy box — which reads as "no answer" and was
      wrong in both runbooks until 2026-08-21.

---
## 2. ❓ The questions — all of them, in one place

**Every unanswered question now lives in [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md)** and is not
restated here (2026-08-23). Two lists of the same questions is how a stale one survives: on
2026-08-23 a re-check against the code found fourteen rows describing as missing something that had
since shipped, and this page carried two of them — it still said Egyptian tax depreciation was
*"deliberately not built"* four days after it shipped with its own screen.

**What blocks launch, by ID.** Follow each into [§1 of OPEN-QUESTIONS](../OPEN-QUESTIONS.md#1---before-the-first-real-invoice)
for what ships if nobody answers.

| # | The question | Why it blocks launch |
|---|---|---|
| **A1.1** | The operator's TRN, legal name and billing-enquiries email | Every invoice is titled *Tax Invoice* and is not one — the tenant cannot reclaim the VAT. Ships blank on purpose: a plausible-looking TRN gets filed and fails on audit |
| **A1.x · C-TAX** | Which supplies are taxable, at what rate, and which carry stamp or schedule tax | The billing engine computes from it. **All configuration** — a charge-code row and a dated rate, no release |
| **A4.1** | The real Egyptian chart of accounts | Every posting resolves through `account_mappings` to accounts that must be the accountant's. **Importable since EG-28** — only the file is missing |
| **A9.1 / A9.2** | Sign off the posting map, and the marketing levy's treatment | The map is editable on screen; what it should SAY is an accounting decision |
| **A3.7** | Opening balances and the cut-over date | Without them the first trial balance is wrong by exactly the history preceding cut-over. The screen and importers exist |
| **A8.3** | What history migrates, and sample files | Migration cannot be scoped without it |
| **A2.7** | One TRN for the install, or one per owner | Two owners with two VAT registrations cannot both be billed correctly today (→ code) |
| **B.1 / B.3 / B.4 / B.5** | How Eltizam is paid, and whose account tenant money lands in | The owner-statement fee line is built-but-omitted behind it; the wider revenue split needs a finance workshop, not an email |
| **C-NUM · C-FY** | Document prefixes and the fiscal-year start | **Both have hard deadlines.** A prefix cannot be changed after the first issued invoice without starting a second series; the fiscal year is refused once anything is posted |
| **C-PAY** | The statutory payroll rates | All three at nil on an approved run means net = gross on every payslip and no liability in the books |
| **C4.2** | Go-live date, parallel run, and who validates the data on the client side | Nothing can be scheduled around it |

**Decisions for the first real month** — C1.9 (is a departing tenant entitled to the unearned
credit), C1.10 (should holdover conversion be automatic), C-SLA (which priorities run on the working
calendar — a vendor SLA penalty is charged off that clock), C-NSF (price the returned-cheque fee),
C4.11–C4.12 (2FA rollout, and what an unassigned account may see) — are
[§2 of OPEN-QUESTIONS](../OPEN-QUESTIONS.md#2---before-the-first-real-month).

---

## 3. 🟢 Deliberately NOT blocking — recorded so nobody re-opens them

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

## 4. Requirements that cannot be built as written

Two clarifications remain in [OPEN-QUESTIONS.md §5](../OPEN-QUESTIONS.md#5--requirement-wording-we-cannot-build-from)
— it was five, and three turned out to be confirmations of what already ships. The sharpest is
**FR-REQ-01 "delegation (from/to)"**: no such concept exists anywhere in the system, and it cannot be
inferred from anything the client has described.

---

## 5. What happens if nothing is answered

The system runs, bills correctly against the rules it has, and posts a complete double-entry ledger.
What it **cannot** do is file a legally valid tax invoice, take a real card payment, survive the loss
of its machine, tell anyone when something breaks, or produce a trial balance that includes the
history preceding cut-over.

**The shortest path to a defensible go-live is §1 — every row is infrastructure or a credential, and
none of it needs an engineer.**

---

**Everything that is NOT on this page** — the improvements worth making once the system is live,
what is blocked behind which answer, and what is deliberately declined — is in
[BACKLOG.md](../ROADMAP.md).

---

*Sources consolidated here: [ROADMAP §2–§3](../ROADMAP.md), [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md),
[ETA-PAYMOB-CERTIFICATION.md](../integrations/ETA-PAYMOB-CERTIFICATION.md),
[PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md), [accounting/GAP-ANALYSIS.md](../gap-analysis/README.md).
Those stay the detail; this stays the gate. **When a row here is done, tick it here and update its
source** — a checklist that disagrees with its sources is worse than neither.*
