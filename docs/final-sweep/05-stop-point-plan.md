# The stop point — what "done" means, and how to get there

> Part of [the final pre-staging sweep](README.md). This is the answer to *"recommend me everything
> that can be done to make the stop point for this project."*

## 0. First: name the stop point, because there are three

The most useful thing this sweep can tell you is that **"done" is not one line, and the project has
been implicitly aiming at the wrong one.**

| Stop point | What it means | Where you are |
|---|---|---|
| **A · Feature-complete** | Every module built, every gap analysed | **Reached.** The Yardi cycle closed all 43 stories; modules 01–36 exist; the generic-ERP layer is deliberately frozen |
| **B · Trustworthy** | The money is right, the credentials are safe, and a mistake has a correction path | **Not reached — and it is close.** ~16 findings stand between you and it, most of them S-sized |
| **C · Operable** | A named human can install it, load a real mall, run a month, close it, and recover from a bad day | **Not reached, and this is the real gap.** The cut-over path is broken, the backup writes nothing, and there is no deploy mechanism at all |

**The project has been optimising for A and reporting against A.** `ROADMAP.md` says "the code-side of
go-live is done, and what remains cannot be written — only configured." **That is no longer true**, and
this sweep is the evidence: the lease importer fails on every row, the VAT return has no page, the
approval ladder is never installed, and `pay-demo` ships live.

> **Recommendation: declare the stop point to be C, and stop there.** Do not aim at feature
> parity with Yardi's collections module, Angus's H&S domain, or Mallcomm's loyalty layer. Those are
> real gaps and they are in [01-yardi-parity.md](01-yardi-parity.md) with triggers. None of them is
> what a first Egyptian mall client needs in month one.

---

## 1. The route, in five phases

Effort is engineering time, not calendar. **S** ≲ half a day · **M** ≲ 3 days · **L** > 3 days.

### Phase 1 — Stop the bleeding — ✅ **DONE 2026-08-11**

All nine items shipped and pushed (`50166c3`…`df637af`). Full suite green: **4,218 tests, 4,214
passed, 4 skipped, 0 failures.** Every guard was mutation-checked — removed, watched go red,
restored — and each fix carries a regression test in `tests/Feature/Regression/`.

Two things worth carrying forward from doing the work:

- **One fix was wrong the first time and the suite caught it.** Copying `Invoice`'s "always
  re-generate the number" rule onto `Lease` renamed leases created with deliberate references —
  which would have broken importing an operator's real contract references at cut-over. Corrected
  to allocate only when blank (`df637af`). The failing test was right.
- **Two audit findings were narrower than reported, and one was wider.** The lease-reference crash
  needs a lease nothing references (which is exactly what the broken importer produces); the
  approval-ladder gap loses value *tiering*, not all gating. Against that, `mall_admin` turned out
  to sit above the protected-role guard — a live privilege escalation nobody had listed.

Everything here was small, and every item was either money-destroying or credential-grade.

| # | Item | ID | Effort |
|---|---|---|---|
| 1 | Delete the demo-payment path entirely — route, controller, action, both Filament `payDemo` actions | FS-01 | S |
| 2 | Remove the `?? 'password'` default; require a generated password, and rotate any tenant already created with it | FS-02 | S |
| 3 | `->authPasswordBroker('tenant_users')` + `'portal' => false` in `User::canAccessPanel()`; add the missing mobile reset route | FS-03 | S |
| 4 | `Lease::generateReference()` → adopt `AllocatesDocumentNumber` (lock + `withTrashed()`) | FS-05 | S |
| 5 | Status filter on the payment picker; cap and net partial write-offs; one `written_off` predicate | FS-06 | M |
| 6 | Remove or build "Send WhatsApp" — do not ship an action that reports a false result | FS-16 | S |
| 7 | Add `ApprovalRulesSeeder` + `DepartmentSeeder` to `atriom:install`, with a health check | FS-13 | S |
| 8 | `.env.example`: `APP_ENV=production`, `DB_CONNECTION=mysql`, and a health check that fails when cache/session/queue resolve to `database` in production | FS-11, FS-37 | S |

**Phase-1 exit test:** a tenant with a known email cannot alter their own AR; no account is reachable
with the password `password`; a tenant can reset their password; deleting a draft lease does not break
lease creation; and `atriom:health` on a production-shaped box tells the truth.

### Phase 2 — Make the cut-over possible — ✅ **code done 2026-08-11**

Shipped: the lease importer's four faults (`4851b78`), the audit command's zero-charge blind spot,
ten tests that actually execute an importer, tenant-import identity (`0716e53`), all three importers
off hard-coded `sync`, the `asset_owner` UI (`65b5d51`), and the **opening-balance import**
(`cd3606c`). Full suite green throughout: **4,246 tests, 0 failures.**

**Plus a fourth importer, 2026-08-12: `VendorImporter`** — the supplier register, so the operator's
existing vendor list arrives with the rest of the cut-over rather than being keyed by hand. Follows
the three that exist: identity on `tax_id` then `email` (never `name` — "Cairo HVAC Co." and "Cairo
HVAC Co" would fork into two suppliers that can never be merged once either has a bill), matching a
TRN with or without dashes, enum columns validated against the DB's own set, and `slug` withheld
because the model owns it. The one importer-specific trap: **a blank withholding-tax cell must stay
NULL, not become 0** — null means "use the portfolio default" and 0 means "this supplier is exempt",
so coercing would silently exempt the entire register. Mutation-checked both ways.

Still hand-keyed: Employee, FixedAsset, Charge and meter readings.

**Design decision recorded:** opening AR arrives as **open items — real invoices** — not a lump-sum
balance, because aging, dunning, statements and per-invoice allocation all work on documents. They
deliberately post nothing, since the revenue is already in the accountant's opening entry; the
AR tie-out going square is what proves the migration loaded everything.

**Still open in this phase, and both are 🔑/⚙️ rather than code:** importers for the master data
that still has none (Vendor, Employee, FixedAsset, Charge, meters — item 14), and a rehearsal of
the whole cut-over against real data, which is the exit test below.


The original plan follows, with the shipped rows marked — kept rather than deleted, because a
retired row is what stops the next person rebuilding a thing that works.

| # | Item | ID | Effort |
|---|---|---|---|
| 9 ✅ | Fix `LeaseImporter`'s **four** faults: the `$this`-in-a-static-closure that leaves `unit_id` null, the non-existent `asset_code` column, the missing charge-seeding call, and the non-idempotent `resolveRecord()` — plus the property clamp. Copy `UnitImporter` throughout; it is correct on every one of these | FS-04 | M |
| 9b ✅ | **Un-baseline the two PHPStan entries on `LeaseImporter`** — they are the analyser's report of fault 1, suppressed. Then re-check the other `alwaysFalse` entries, four of which are load-bearing `balance` guards and must be annotated, not removed | — | S |
| 10 ✅ | Make `atriom:audit-charge-schedules` **fail on a lease with zero charges** — today that is the one shape it calls clean | FS-04 | S |
| 11 ✅ | `TenantImporter`: stop deduping on a nullable, non-unique `email` | — | S |
| 12 ✅ | Importers off `sync`; add `getMaxRows()` and a chunk size | FS-36 | S |
| 13 ✅ | **Opening balances** — AR, deposits, cash, AP. At minimum an invoice importer with a dry-run and an error report | FS-15 | M |
| 14 | Importers for the master data that has none — Vendor, Employee, FixedAsset, Charge, meters | — | M |
| 15 ✅ | **A test that actually executes each importer.** None exists today; the sole importer test inspects validation rules | — | S |
| 16 ✅ | `asset_owner` UI — a relation manager on `AssetResource`, plus `ownership_percentage` | FS-14 | S–M |

**Phase-2 exit test:** load a full mall — properties, floors, units, tenants, leases with charge
schedules, opening AR, deposits, owners — from files, twice, and get the same result both times.
Then run `atriom:audit-charge-schedules` and have it be meaningful.

### Phase 3 — Make the books defensible (≈ 1 week)

| # | Item | ID | Effort |
|---|---|---|---|
| 17 | Shared `REPORTABLE` constant + the one test asserting bank-rec ≡ ledger for the same account | FS-07 | M |
| 18 | Per-pool CAM estimate charge codes; default non-`cam` pools to `stated` | FS-08 | S |
| 19 | Credit notes into the VAT return's documents side and both base buckets; give it a page | FS-09 | S |
| 20 | `billing:reconcile`: all four channels; then **schedule it** and alert on drift | FS-18, FS-33 | S |
| 21 | `alreadyBilledForMonth`: status filter + `nsf_fee` | FS-19 | S |
| 22 | Month-range on the four statement pages | FS-20 | S–M |
| 23 | Deposit posting-date guard; correct the false `system:` registry entry | FS-25 | S |
| 24 | `VendorBill` → penalties cascade | FS-26 | S |
| 25 | Late fee onto its own dated invoice | FS-27 | S |
| 26 | Annual %-rent through `netOfDeductions()` | FS-28 | S |
| 27 | WHT base excludes VAT | FS-29 | S |
| 28 | Seller TRN on the tax invoice | FS-30 | S |
| 29 | `withTrashed()` on the four GL-critical relations that derive the property dimension | — | S |
| 30 | Surface or refuse `asset_id = null` GL entries — today they are invisible to every owner statement | — | S |

**Phase-3 exit test:** run a full synthetic month — bill, collect, credit, write off, reconcile CAM,
close — and have `billing:reconcile`, `glTieOut()` and the VAT return all tie out, with a printable
trial balance for that month.

### Phase 4 — Make it operable (≈ 1 week, mostly ops)

| # | Item | ID | Owner |
|---|---|---|---|
| 31 | Install `mysql-client` and Node; fix the provisioning list | FS-12 | ⚙️ |
| 32 | **Write `deploy.sh`.** `INFRASTRUCTURE.md:374` refers to one that was never written | — | ⚙️ |
| 33 | Get the backup actually producing an archive; add off-site, encryption and an archive-password escrow | FS-10 | ⚙️ |
| 34 | **Run a restore drill and time it.** RTO is currently unmeasured. Fix `VerifyBackupService`'s S3 and `CREATE DATABASE` assumptions first | FS-10 | ⚙️ |
| 35 | Point an external monitor at `/health`; set `SENTRY_LARAVEL_DSN`, `OPS_LOG_STACK`, `LOG_SLACK_WEBHOOK_URL` | — | ⚙️ |
| 36 | Fix `/health`'s queue check to read the real queue backend, and make `checkBackups()` inspect every disk | — | 🧑‍💻 S |
| 37 | Make `integrations:check --mail` fail on `MAIL_MAILER=log` | — | 🧑‍💻 S |
| 38 | Runbooks for the five missing procedures: failed billing run, user locked out, secret rotation, **cut-over**, schema rollback | — | 🧑‍💻 |
| 39 | Rewrite `docs/gap-analysis/999-production-checklist.md` — it asks the operator to verify "295/295 tests" (actual ~3,680), "40+ migrations" (actual 195) and "3 scheduled entries" (actual 29) | — | S |

**Phase-4 exit test:** a person who is not you deploys a release, breaks it deliberately, and restores
— with the runbook and no help.

### Phase 5 — The gates that stop the recurrence (≈ 3–4 days)

The project's own evidence is that **a convention with a conformance gate has not drifted once**, and
every finding in this sweep is in an area without one. This phase is what makes the stop point hold.

| # | Gate | Closes |
|---|---|---|
| 40 | **Derived money columns may not be `dehydrated()` while `$fillable`** | FS-24, and the class it belongs to |
| 41 | **Concurrency**: a registry of lock-bearing services + a MySQL-backed test path, since SQLite compiles `lockForUpdate` to `''` | FS-22 |
| 42 | **`ActionAuthz` must require `abort_unless` in the closure**, not accept `->authorize()` as an alternative | FS-23 |
| 43 | **Navigation**: every `getNavigationGroup()` returns a declared key | FS-46 |
| 44 | **Clone-completeness**: every fillable column of a cloned model is either carried or in a stated `NOT_CARRIED` list | FS-17, and the class |
| 45 | **Importers** are inside the isolation and posting-date gates — today **no gate covers them at all** | FS-04 |
| 46 | Port `ChangeImpact`'s fixture-and-control shape to the nine gates that assert *declaration* rather than *behaviour* | [04 §4.5](04-architecture-ops.md) |

---

## 2. What to explicitly NOT do

Scope discipline is most of what gets you to a stop point. Each of these is a real gap that should be
recorded with a trigger and then left alone.

- **Do not build a collections module.** Add a dunning ladder and invoice delivery tracking; skip
  payment plans, credit limits and collections status until a client asks.
- **Do not build the H&S domain** (permit-to-work, incident log, statutory certificates). The FRD asks
  for none of it. *Trigger: the operator's first contractor incident.*
- **Do not build a technician field app.** Take the two S-sized pieces — a completion-photo collection
  on work orders, and staff device tokens — and stop.
- **Do not finish Egyptian statutory payroll unless you mean it.** Decide: finish brackets, insured-wage
  cap and end-of-service, or **descope payroll for go-live and say so in the FRD**. Shipping payroll
  that computes zero tax is worse than not shipping payroll, because it looks operational.
- **Do not rebuild the money core.** Two adversarial passes could not break it.
- **Do not "fix" the lease `ViewAction` into a bespoke abstract.** The disabled-form schema is a
  documented anti-drift decision.
- **Do not renumber the chart of accounts** while the 8-vs-10-digit question is parked.
- **Do not re-enable CI.** Standing owner decision; this sweep does not revisit it. Note only that with
  CI off, the gates in Phase 5 are worth *more*, not less — they fail the local `pest --parallel` run
  that is now the only gate.
- **Do not chase the 409 PHPStan baseline errors.** Do raise the two excluded directories
  (`Resources/*/Schemas/*`, `Resources/*/Pages/*`) into analysis, because both HIGH security findings
  live there.

---

## 3. The decisions only you or the accountant can make

These block work but are not work. **Get them answered while Phase 1–2 runs.**

| # | Question | Blocks | Ask |
|---|---|---|---|
| 1 | **Is base rent VAT-exempt for commercial letting?** Law 67/2016 exempts *non-commercial* rental | Every invoice in history, and #2 | Accountant |
| 2 | **Partial-exemption apportionment** — input VAT is recovered 100% while most output is exempt. **A "taxable" answer to #1 dissolves this entirely** | The VAT return's correctness | Accountant |
| 3 | Marketing fund: revenue, or a reconciled fund with true-up and tenant reporting? | Module 13's shape | Operator + the lease contracts |
| 4 | Mid-year termination and CAM: does a departed tenant get a partial-year true-up? | `participants()` | Operator |
| 5 | Payroll: finish or descope? | Phase scope | You |
| 6 | Jawad's real Egyptian chart of accounts, and the 8-vs-10-digit code width | Nothing technical — the system is width-agnostic and proven so | Accountant |
| 7 | The All-Properties plumbing (12 files) — keep for mall #2, or delete now? | Nothing; 5-minute call | You |
| 8 | WHT: switch on at go-live, or after the first filing period? | FS-29's urgency | Accountant |

---

## 4. Documentation: what to fix, and one structural change

This sweep found stale documentation in **every** area it looked, and in several cases the stale doc
would actively cause a rebuild or shield a bug. Full lists are in each aspect file. The pattern:

- **`docs/money/` is a stale mirror of the money rules** — including a bolded claim that there is no
  global VAT-rate setting (the opposite of the shipped invariant) and a two-channel statement of the
  four-channel AR rule. It is the set a finance person signs off from.
- **`docs/benchmarks/yardi/06-atriom-gap-analysis.md` verdicts double-booking "KEEP — do not touch"**,
  which now shields a real gap: only **two of four** lease-activation paths take the unit lock. The
  standard New Lease page (`CreateLease`, Filament's default `CreateRecord` flow) and
  `LeaseSpaceChangeService::expand()` both lack it.
- **`docs/modules/04-leases.md` lists three shipped features as open** — reading it would send someone
  to rebuild options, trailing proration and holdover billing.
- **`SearchPolicy.php:131-137` credits an anchored-`LIKE` fast path that does not exist**, which is how
  37 unindexed scans per query stayed unnoticed.
- **`CLAUDE.md`'s scheduled-command list omits 8 of 29.**

**The structural change:** the project already has the right instinct — `GeneratedDocsConformanceTest`
fails the build when a `<!-- GENERATED -->` block drifts from its registry. **Extend that to the
scheduled-command list, the resource census, and the module status matrix**, and delete the
hand-maintained copies. Everything in a doc that is a *fact about the code* should be generated; what
remains should be *reasoning*, which does not go stale the same way.

---

## 5. If you only do one week

Phase 1 (2–3 days) plus items 9, 10, 15, 19, 20 and 21 from Phases 2–3.

That gets you: no fabricated payments, no default credentials, working password recovery, a lease
import that works and is verified by a test, a VAT return an operator can open and trust, a
reconciler that counts all four channels and runs on a schedule, and a billing run you can safely
re-run after voiding an invoice.

It does **not** get you a tested restore, and I would not put a real client's money on the system
without one.

---

## 6. How to keep this document honest

Move a row to a "Done" section when it ships rather than deleting it — the retired list is what stops
the next person rebuilding a thing that works, and this sweep retired six claims that had already been
fixed or were never true.

And when a finding here is fixed, **ask the question this codebase does not currently ask: where
else?** Six of this sweep's findings were the un-propagated half of a fix that already existed
somewhere in the same repository.
