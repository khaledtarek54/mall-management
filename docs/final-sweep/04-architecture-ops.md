# Final sweep — architecture, security, performance, operations, test honesty

> Part of [the final pre-staging sweep](README.md). Four audits: an authorised security review of all
> auth surfaces; a static performance/scalability pass sized to year 5 / 5 malls; production &
> operational readiness; and a **test-honesty** audit asking not "is coverage high" but **"which green
> tests are lying?"**
>
> Every CRITICAL and HIGH was re-verified by the lead — including two claims that contradict
> documented project invariants, which I checked in the vendor source before recording.

## 0. The verdict

**Security: no CRITICAL, and a long list of things that were attacked and held.** Cross-tenant IDOR on
every API route, mass assignment, role escalation, SQL injection across ~40 raw-SQL sites, output
encoding, mPDF, command injection, media privacy, the Paymob HMAC callback, `/pay/{token}`, and the
shopper feed were all probed and are sound. The two real findings are the same shape twice: a derived
money column that is `$fillable`, submitted by the form, with a hole in its server-side re-derivation.

**Performance: the concurrency discipline is excellent and the aggregate discipline is not.** 110
`lockForUpdate` sites, essentially all `whereKey()`-scoped single rows. But the dashboard hydrates
every open invoice twice on every login, exports and importers run inline, and the reporting layer
re-runs its own aggregates 2–5× per render.

**Operations: the application is production-grade; everything around it is not.** No deploy
mechanism, no working backup, no rollback, no external eye. And the go-live blocker is **the
cut-over**, not the configuration.

**Tests: 611 files and ~3,680 cases, and the deepest finding is that nine of eighteen conformance
gates assert a convention is *declared* rather than that it *behaves*.**

---

## 1. Security

### 1.1 CRITICAL — the portal password reset resolves against the admin user table

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes, every link

Three links, all confirmed in code:

1. [PortalPanelProvider.php:38](../../app/Providers/Filament/PortalPanelProvider.php#L38) calls
   `->passwordReset()` and **never** `->authPasswordBroker(...)`. `->authGuard('portal')` at `:51`
   governs authentication, not the broker.
2. [config/auth.php:21](../../config/auth.php#L21) — `'passwords' => env('AUTH_PASSWORD_BROKER', 'users')`.
   So the portal's reset resolves against `App\Models\User`. The purpose-built `tenant_users` broker
   at `config/auth.php:129-134` is **dead code**.
3. [User.php:120-126](../../app/Models/User.php#L120-L126) — `match ($panel->getId()) { 'admin' => …,
   default => true }` returns **true for `portal`**, so Filament's own guard never fires.

**Consequence A:** a `TenantUser` can never reset their password — the lookup misses and returns "we
can't find a user with that email". **Consequence B:** an operator's email typed into the public
portal reset form mails that admin a genuine reset link built for the *portal* panel, which writes to
the `users` row.

> **Severity, stated precisely.** Completing a takeover still requires the admin's inbox, so this is
> an auth-boundary crossing and a phishing-grade primitive — **not** direct account theft. The
> *broken* half is unambiguous, and it compounds: `config/app.php:59` falls back to
> `APP_URL/reset-password`, `APP_MOBILE_RESET_URL` is absent from `.env.example`, and there is **no
> `reset-password` route in `routes/web.php`**. So at go-live **neither surface can recover a
> password.**

**Fix:** `->authPasswordBroker('tenant_users')` and `'portal' => false` in `canAccessPanel()`.

### 1.2 HIGH — an issued invoice's `balance` is client-writable

- **Remedy class:** BUGFIX + a new conformance gate · **Effort:** S · **Verified:** yes

`balance` is in `$fillable` ([Invoice.php:64-79](../../app/Models/Invoice.php#L64-L79)) and the form
renders it `->readOnly()->dehydrated()`
([InvoiceForm.php:290-296](../../app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php#L290-L296)).
`readOnly` is an HTML attribute; `dehydrated()` explicitly opts the value **into** the submitted
payload. And the corrective hook short-circuits:

```php
static::saving(function (self $invoice) {
    if (! $invoice->exists) { return; }
    if (! $invoice->isDirty(['subtotal', 'vat_amount', 'total'])) { return; }   // ← Invoice.php:335
    …
});
```

A payload that changes `balance` **alone** returns at line 335 and the tampered value persists. The
finalised-invoice guard (`:409-414`) freezes only `issue_date`, `tenant_id`, `lease_id`.

**The comment directly above that hook reads: _"`readOnly()` on the form is the UX; this is the
rule."_** The rule has a hole.

Exploit: any holder of `invoices.edit` POSTs the Livewire payload with `data.balance = 0`. The invoice
then reads settled in the portal, in AR aging (`arAgingBuckets()` filters `balance > 0`), in the
overdue scan and on every collections screen — while GL AR still carries the debit. **HIGH not
CRITICAL** because the activity log records `balance` and the next `recomputeTotals()` self-heals.

Notably, [ChangeImpact.php:114-122](../../app/Support/ChangeImpact.php#L114-L122) classifies `balance`
and `paid_amount` as **NEUTRAL** — *"the AR sub-ledger's derived state, not the invoice's GL effect."*
True of the GL, false of AR. **The registry meant to catch this actively reassures the reader.**

### 1.3 HIGH — a draft credit note's totals are derived in the browser only

`CreditNoteForm.php:233-236` dehydrates `subtotal`/`vat_amount`/`total`/`balance` while draft, and
**`app/Models/CreditNoteItem.php` has no model hooks at all** — verified: zero matches for `booted`,
`static::sav`, `static::creat`, `static::updat`, `recomputeTotals` — unlike `InvoiceItem.php:69-72`.
`CreditNoteService.php:32` then trusts the header verbatim, its own comment asserting "the totals are
already item-derived", **which is only true of the browser**. Result: `Dr Sales Returns / Cr AR` posted
at a figure the document's own lines cannot reproduce.

### 1.4 The structural recommendation

Both HIGHs are literally the same pattern: **`readOnly()->dehydrated()` or `disabled()->dehydrated()`
over a fillable derived money column.** A conformance gate over that pattern is worth more than any
per-resource sweep, and it fits the project's existing idiom. Add `number` to the list — an issued
invoice's number is rewritable via `disabled()->dehydrated()` (`InvoiceForm.php:46-49`), and
`ChangeImpact.php:123-125`'s stated reason for not guarding it ("nothing rewrites it") is factually
wrong.

### 1.5 MEDIUM

**SVG is accepted by every Filament `->image()`** (`mimetypes:image/*`), and MarketingPost hero/gallery
are on the **public** disk (`MarketingPost.php:246-247`) — a portal tenant-admin gets a permanent
same-origin `/storage/….svg` executing their JS. The mobile API path already blocks it correctly
(`SaveMarketingPostRequest.php:66-69`); only the Filament form diverged · **`/paymob/return` is an
unauthenticated, unthrottled order-id → payment-link-token oracle** (`CallbackController.php:147-164`;
the route at `web.php:49-50` sits outside the throttle group) — the 302 `Location` leaks a token that
reads another tenant's invoice, and each probe runs a leading-wildcard `LIKE` scan ·
**`owner_statements.view_own` is granted but never checked** (zero hits in `app/`), so the relation
manager lists every co-owner's share and payout to any owner on the asset · **CSV/XLSX formula
injection** — `ReportCsv.php:26-40` and `TenantRequestExporter.php:26-27` export tenant-written `title`
with no `=`/`+`/`-`/`@` neutralisation · **`SESSION_SECURE_COOKIE` is unset and undocumented** —
`null` resolves to `false`, not "auto".

### 1.6 Attacked and found sound

Cross-tenant IDOR on every `/api/v1` route (each resolves through the tenant relation and **404s, never
403s**) · tenant FK tampering (`Portal::clampLeaseId`, the `lease_unit` pivot check) · mass assignment
(no `unguard`, no `$guarded`, no `$request->all()` outside the HMAC-verified callback) · role
escalation via the user form (`enforceProtectedRolesRule`) · **SQL injection — ~40 raw-SQL sites read
individually; all literal, bound, or driver-switch constants, and nothing takes a column or sort key
from request input** · output encoding and mPDF (all `{!! !!}` escape with `e()`; no remote/file access)
· command injection and deserialization · media privacy (every sensitive collection on `local`) ·
`/health` (token-gated with `hash_equals`, fails closed) · the shopper feed · `/pay/{token}` (48-char
rotatable token, throttled, `default-src 'none'` CSP) · the Paymob S2S callback (HMAC then
`lockForUpdate` with an in-transaction re-read) · audit trail on all ten money models ·
`OpsLog::REDACT` now does list `payment_token`/`payment_key`/`access_token` · **no abandoned packages
in `composer.lock`**; the production tree is current (Laravel 13.20.0, Filament 4.11.8).

**Re-verified false claims** (from the ROADMAP's own list, not re-raised): login throttling
(`WithRateLimiting` in the Livewire component) and role-grant auditing (`App\Support\AccessControlAudit`).

---

## 2. Performance & scalability

Sized to year 5 / 5 malls: ~150k invoices, ~500k journal entries, ~1.7M journal lines, ~2,500 leases.
Two facts colour everything: `INFRASTRUCTURE.md:139,141` sizes production at **12 PHP-FPM children**,
so 30 operators contend for 12 workers and any 3-second page is a queue; and Redis is **required** by
`INFRASTRUCTURE.md:209-225` while `.env.example` ships cache, session **and** queue on MySQL with no
health check enforcing the switch.

### 2.1 CRITICAL

**Bulk "Download PDFs" renders one mPDF per selected invoice, synchronously, uncapped.**
`InvoicesTable.php:559-574` — no `chunkSelectedRecords()`, no limit, no queue. Filament's "select all"
hands the closure every filter-matching record; each build is a full Arabic-shaping mPDF render at
~150–400 ms and 10–30 MB peak. ~600 invoices (one mall-month) exceeds a 60 s FPM timeout; a year is a
guaranteed 504 plus OOM. **Reachable day one after migration by clicking the header checkbox.**

**Every CSV export and every importer runs inline, and three importers hard-code it.** Verified:
`return 'sync';` at [TenantImporter.php:86](../../app/Filament/Imports/TenantImporter.php#L86),
[UnitImporter.php:114](../../app/Filament/Imports/UnitImporter.php#L114),
[LeaseImporter.php:120](../../app/Filament/Imports/LeaseImporter.php#L120) — so **config cannot save
them**. No `->chunkSize()` or `->maxRows()` anywhere. **The importers bite at go-live, because the
migration itself is a bulk import.**

**The dashboard hydrates every open invoice twice and fires 15 uncached aggregates before the
permission filter.** `MallStats.php:88` and `ArAging.php:39` both call `arAgingBuckets()`, which
`->get()`s every open invoice with no `select()`, no aggregate and **no lower date bound**, then
buckets in PHP — 7,500–15,000 models hydrated **twice** per dashboard load, ~30–90 MB against a 256 MB
limit. It is a `SUM … GROUP BY CASE` written as a hydration. `ActionRequired` adds 13 counts plus two
unbounded `->get()->filter()`s, all computed **before** the role filter — so a coordinator who sees two
cards pays for the overdue-AR sum. Two widgets set `$isLazy = false`.

~~**The nightly late-fee job is unbounded and can be re-entered.**~~ **FIXED 2026-08-12** — the
guard, `retry_after` raised to 900, an id-snapshot sweep, and `QueueJobSafetyConformanceTest`
classifying every job. See [module 19](../modules/19-notifications-scans.md#queued-jobs-and-re-entrancy).
The original finding: Verified:
[ApplyLateFees.php:17-18](../../app/Jobs/ApplyLateFees.php#L17-L18) sets `$timeout = 600`, `$tries = 1`
and has **no `middleware()`**, while `config/queue.php:43` sets `retry_after` to **90**. A run
exceeding 90 s becomes reclaimable and a second worker starts the same unbounded sweep.
`RunMonthlyBilling` guards precisely this with `WithoutOverlapping(...)->dontRelease()` at `:32`; this
job never got it. `LateFeeService.php:32-37` also `->get()`s the entire arrears backlog — the one
dataset that never shrinks. Correctness survives (the per-invoice lock and re-check are sound); it is
doubled load and doubled memory on AR at 04:00, nightly.

**Cache, sessions and queue default to MySQL, which is off-box.** Every session read/write, every
permission-catalogue read, every queue poll and **every `Cache::lock()`** crosses the network — and
this codebase leans on locks hard (`AllocatesDocumentNumber` takes a **blocking** lock around every
numbered-document insert). `Health` has production-only checks for 2FA, demo logins and seeded roles —
**none for the drivers.**

### 2.2 HIGH — selected

**GL report pages re-run their full aggregate 2–5× per render** (`TrialBalance` 4×, `WeeklySpend` 5×
with two unbounded `->get()`s each = 10 table loads, `Reports` + `MonthlyCloseStats` 12 loads), and
**the balance sheet passes no lower date bound**, scanning `journal_lines ⨝ journal_entries ⨝
ledger_accounts` from inception. Five sibling pages already use the `??=` memo — the fix is to copy it.

**`whereDate()` on already-`DATE` columns — 126 occurrences — defeats every date index.** MySQL will
not unwrap a function on a column. Every column named is *already* `date`, so the wrapper is redundant
as well as index-defeating. **`EXPLAIN` one query before doing all 126** — this rests on
non-sargability, which was not measured.

**The GL hot path re-reads `account_mappings` and `accounting_periods` per document.** This confirms
and localises the lead recorded in CLAUDE.md: `AccountResolver` memoises correctly but only
**per instance**, and it is not container-bound, so the cache is per-job and never shared. The sweep is
already fine (one warm instance); **the cost lands entirely on the real-time queue path, which is
where production posts.** Three small safe changes fix it: scope the resolver as a singleton, cache the
~40 mappings and ~200 accounts with observer invalidation **exactly mirroring `ChargeCode::flushLookupCaches()`**,
and memoise `AccountingPeriod::forDate()` keyed `Y-m`.

**Weekly `accounting:sync-ledger --all` is O(every document ever)** and its `EAGER` map omits the four
highest-volume sources, so those journalizers `loadMissing()` **per row** despite the chunk · **the
daily sweep loads all AR and AP line history into memory to read two scalars** · **the General Ledger
page materialises a whole account-year twice, then paginates in PHP** · **CAM reconciliation wraps a
500-lease loop in ONE transaction**, accumulating up to 500 row locks held to commit — the only job
here that visibly blocks operators · **every `marketing` invoice line triggers a year-wide
doubly-nested-`whereHas` SUM**, making billing O(leases × invoice_items_in_year) · **nine uncached
COUNT aggregates on every admin page render**, with `Cache::` appearing **zero times** across all 428
files under `app/Filament` · **the tenant list issues 6 queries per row, 150 per page** · **rent roll
and expiration schedule N+1 on `unit.areas`** — absent from both eager-load lists, ~2,500–5,000 extra
queries, and **the best win-to-risk ratio in the report** · **`activity_log` has no `created_at`
index** while the monthly cleaner deletes on that column.

**Global search fans 37 unindexed `LIKE '%term%'` scans per query — and `SearchPolicy.php:131-137`
credits an "identifier fast-path … anchored `LIKE 'term%'`" that does not exist.** The provider
implements a length floor, category ordering and exact-match promotion; there is no anchored path.
**A doc crediting a mechanism that isn't there is how this got missed.**

### 2.3 Retired — verified false, do not act on

`invoices.balance` is unindexed (**false** — `2026_05_24_130536:23`) · missing single-column FK indexes
(**false** — `constrained()` emits a FK and InnoDB auto-indexes it) · `TenantScope::visibleAssetIds()`
is a query storm (**false** — it short-circuits to `[$tenant->getKey()]` with zero queries) ·
`stock_movements` needs an on-hand index (**false** — it has one) · Filament tables poll (**false** —
zero `->poll()`, deliberate) · three summarizers sum the wrong column (**false** — resolved by the lead
in the vendor source; see [02-ux §5](02-ux.md#5-retired-claims)).

### 2.4 Already fast — do NOT touch

`MonthlyBillingService`'s loop shape (`chunkById(100)`, per-lease transaction, `planInvoiceForLease()`
issues **zero** queries) · the concurrency discipline generally · `AccountResolver`'s memo *design* ·
`InvoiceItemSettlement::forMany()` (2 queries for any number of invoices) · **`ChargeCode`'s
container-scoped memo with correct invalidation — the pattern to copy** · `occupancyCost()` and
`revenueByType()` (real SQL `GROUP BY`) · `OwnerStatementPdfService` reading frozen JSON, genuinely O(1)
· `TableDefaults` · the 60 tables that already declare eager loads.

---

## 3. Production & operational readiness

**The go-live blocker is the cut-over, not the configuration.** See
[01-yardi-parity §import chain](01-yardi-parity.md) and the README's blocker list.

### 3.1 CRITICAL

**There is no rollback and no recovery.** `PRODUCTION-RUNBOOK.md:214` makes "restore from the latest
backup" the *entire* DB rollback story — correct, given 171 of 195 migrations drop columns in `down()`
plus 16 irreversible data backfills. **The backup it points at has produced nothing for 12 days**
(`mysqldump` missing). Today a bad migration is not "recovered with loss", it is **unrecoverable**.
Once fixed the honest numbers are **RPO ≈ 24 h** (nightly dump, no PITR; the Aiven tier has no
backups) and **RTO unmeasured** — nobody has timed a restore. The archive is unencrypted
(`BACKUP_ARCHIVE_PASSWORD` empty) and local-only.

**`.env.example` ships `APP_ENV=local` — the safety net has an off-switch and it is the default.**
Five of eleven health checks return `ok:true` unconditionally on `local`/`testing`. `DB_CONNECTION=sqlite`
compounds it: `missingDumpBinary()` returns null for sqlite, so backup capability reports **"able to
back up"** on a box that cannot. `cp .env.example .env` yields a box reporting green while running
debug-on, 2FA-off, with published demo super_admins. **There is no boot-time guard anywhere.**

**The provisioning list omits `mysql-client` and Node.** `INFRASTRUCTURE.md:361` installs
`php8.4-mysql` — the PDO driver, not `mysqldump` — **and that omission is the mysqldump incident.** No
`nodejs` either, while `PRODUCTION-RUNBOOK.md:53` makes `npm run build` mandatory or both panels render
unstyled. `INFRASTRUCTURE.md:374` refers to "a `deploy.sh`" that was never written.

### 3.2 HIGH

**The books can drift forever, silently.** The nightly GL↔AR tie-out reports mismatch via
`$this->warn()` and cron pipes stdout to `/dev/null`. `billing:reconcile` — the tool it tells you to
run — is **never scheduled**. The only recurring "do the books tie out?" signal in production goes to
`/dev/null` · **monthly billing: one attempt, no `failed()`, no catch-up, and no `Queue::failing`
listener anywhere** — a missed month is discovered from cash flow · **the GL sweep always exits 0**,
per-document failures go to the app log rather than `ops`, and the one real alarm is de-duped so a
persistent failure alerts **once, ever** · **the restore drill breaks when you follow the advice** —
`VerifyBackupService` hands `Storage::path()` to `ZipArchive::open()` (fails on S3) and `createScratch()`
needs `CREATE DATABASE`, which `INFRASTRUCTURE.md:100-107` explicitly withholds · **`atriom:install`
skips `ApprovalRulesSeeder` and `DepartmentSeeder`** ([01-yardi-parity §2.1](01-yardi-parity.md)) ·
**no opening-balance path, and none for two-thirds of master data** — no importer for Vendor, Employee,
FixedAsset, Invoice, Payment, DepositTransaction, Charge, Asset or meters · **`/health`'s queue-depth
check reads a table Redis never writes**, so the stopped-worker detector is dead on the intended
topology, and `checkBackups()` inspects only `disks[0]` so a failing off-site copy is invisible ·
**`integrations:check --mail` passes when mail isn't delivered** — it returns success for
`MAIL_MAILER=log`, the shipped default, so every alert with a clock on it goes into a log file and the
gate is green.

### 3.3 Production-ready as built — do NOT touch

`App\Support\Health`'s design, especially the file-based heartbeat so a DB outage can't raise a second
wrong alarm · `atriom:install` verifying through the journalizers' own resolver, so "installed" means
*proved* · `BackupArchive`'s insight that every dead backup looks healthy from outside · per-row
`OpsLog::error` containment in the money services · `OpsLog::scrub()` as one redaction policy shared
with Sentry · `UnitImporter`'s property clamp · `SubmitInvoiceToEta`'s bounded retry and `failed()` ·
fail-closed config defaults pinned by `ProductionDefaultsTest` — **the problem is `.env.example`
overriding them, not the defaults** · `GO-LIVE.md` itself, which is accurate.

---

## 4. Test honesty

611 files, ~3,680 cases. The question was which green tests are lying.

### 4.1 CRITICAL — `lockForUpdate` is a no-op in every test

- **Verified:** yes, in the framework source

`SQLiteGrammar::compileLock()` returns `''`
([vendor/laravel/framework/…/SQLiteGrammar.php:31-34](../../vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php#L31-L34)),
and `phpunit.xml:59-60` runs the suite on `sqlite` `:memory:`. **All 110 `lockForUpdate` call sites are
inert in every test run**, and only three have any lock-presence proxy.

Production is unaffected — MySQL honours the locks. What is affected is that **deleting a lock would
turn nothing red.** Concurrency is the one invariant class in CLAUDE.md that never got a registry and
a gate, and it is the class the project has already been bitten by (the double-booking race, the
Paymob double-charge race).

### 4.2 CRITICAL — the authz gate is an `OR` where the rule is an `AND`

- **Verified:** yes, in the Filament source — **and it refines a documented CLAUDE.md invariant**

`ActionAuthzConformanceTest:143-145` accepts `->authorize()` **or** an `abort_unless` closure gate.
I checked why that matters in v4.11.8:

- `CanBeHidden::isHiddenInGroup()` ends `return ! $this->isAuthorizedOrNotHiddenWhenUnauthorized();`
  ([:57](../../vendor/filament/actions/src/Concerns/CanBeHidden.php#L57)) — **authorization folds into
  hidden**.
- `CanBeDisabled::isDisabled()` returns true if `isHidden()`, and ends `return ! $this->isAuthorized();`
  ([:33](../../vendor/filament/actions/src/Concerns/CanBeDisabled.php#L33)) — **authorization folds into
  disabled**.
- `Action::call()` ([:666-675](../../vendor/filament/actions/src/Action.php#L666-L675)) performs **no
  authorization check at all**.

So `visible()` and `->authorize()` are **the same layer**, not two. The only genuinely independent
second layer is `abort_unless` **inside the action closure** — which is exactly what CLAUDE.md's
practical advice already says. What is wrong is (a) the **gate** accepts either, and (b) CLAUDE.md's
*rationale* sentence misdescribes the mechanism.

**76 write actions — including journal-entry `post`/`void`, `void_invoice` and period close — carry
`->authorize()` and no closure gate**, so they rest on a single layer. **No live exploit**: that layer
holds today. The defect is that the gate meant to guarantee defence-in-depth doesn't, which is
precisely the thing that would fail silently on a Filament upgrade.

### 4.3 CRITICAL — `billing:reconcile` counts two of four channels

Corroborated by three independent agents and verified by the lead — see
[03-money-gl §2.2](03-money-gl.md#22-billingreconcile-counts-two-of-the-four-settlement-channels).
This audit adds that `MonthEndReadinessService:177` consumes it, so **a correct book reports as
not-ready at close**, and that it has been blind since 2026-07-20. It is the fifth site CLAUDE.md's
four-channel list doesn't name — and `Invoice.php:543` literally says *"if a fifth is ever needed, grep
for this comment first."*

### 4.4 HIGH

**Pest's higher-order `->throws()` wraps the whole body, so the *setup* can satisfy the assertion** ·
the payroll deduction-cap test asserts only `net >= 0`, so zeroing both statutory withholdings passes ·
**authz tests that dispatch via `mountAction` cannot reach the closure gate they name** (per §4.2,
`mountAction` short-circuits on `isDisabled()`) · **the regression test for a known false pass is
itself never executed** · **the money-deletion gate cannot see any bulk delete** — its regex
`/\b(Force)?DeleteAction::make\(/` matches neither `DeleteBulkAction::make(` nor
`ForceDeleteBulkAction::make(`, and four ship inside money resources · **nothing binds the dashboard
registry to what the dashboard actually renders** · **the posting-date gate's only behavioural test is
a tautology** — it filters to classes using the trait, then asserts the trait's own method exists ·
**the isolation gate accepts `BypassesFilamentTenantAutoScope` as proof of scoping, though it scopes
nothing** · **one guarded page satisfies the write-guard requirement for all 32 must-guard resources**.

### 4.5 The deepest finding: gates that check declaration, not behaviour

**Only three of eighteen gates assert their discovery found anything.** Every other gate loops a
discovered collection and asserts *inside* the loop — so if discovery returned `[]`, the gate passes
having checked nothing. And nine of eighteen assert a convention is **declared** (a trait exists, a
string appears in a file) rather than that it **behaves**.

Selected blind spots, each verified by the audit:

| Gate | What slips past |
|---|---|
| **ActionAuthz** | the `OR` (§4.2) · every `*BulkAction` (vendor-supplied `->action()`) · `Action::make($var)` · a hollow `->authorize(fn () => true)` · **`EXEMPT` keyed on `basename()`**, so one entry exempts all 28 duplicate filenames across both panels |
| **DeletionPolicy** | bulk deletes (§4.4) · the portal panel · relation managers · **6 of 16 `NEVER_DELETABLE` models use a permission group that *is* seeded with `.delete`** |
| **PropertyIsolation** | admin only, one level deep · a hand-written 32-entry must-guard array · auto-tenancy resources exempted although Filament never stamps on *update* |
| **PostingDateGuard** | the tautology (§4.4) · `str_contains` over the whole file **with no comment stripping** · the tripwire pins `DatePicker::make('col')` only — missing `DateTimePicker`, double quotes, and **every non-Filament writer** (mobile API, importers, console) |
| **GlRegistry** | consistency only. **Nothing enforces CLAUDE.md's actual rule** — "at least one test per source must drive the real service + the sweep". A source can be perfectly registered and posted by nothing. |
| **VatRateSetting** | scans `app/` **only** — not migrations (which held two of the original eight literals), seeders or config |
| **ChartOfAccounts** | **no floor on chart size** — an empty or failed seed passes three `::all()->each()` loops having checked nothing |
| **AdminSmokeManifest** | tight, but the coverage it guarantees is **Playwright's, which never runs**. A resource is guaranteed *listed*, never *smoked*. |
| **MediaPrivacy** ✅ | one of the three that asserts non-emptiness. Residual: an ad-hoc `->toMediaCollection('x')` on an unregistered name would inherit the fail-open default. *Verified no live leak.* |
| **ChangeImpact** ✅ | **the exemplar — copy this shape.** It proves REFUSED by dirtying a committed fixture **and** carries a negative control. |

**The cheapest durable fix is porting the three good patterns** — `ChangeImpact`'s fixture-and-control,
`UniqueRuleScope`'s detector self-test, `SearchPolicy`'s runtime verification — **not adding more
gates.**

### 4.6 Retired suspicions

All 30 `expect(true)->toBeTrue()` hits are **legitimate paired controls** · the `DomainRefusal` 302
problem is **genuinely fixed** · the search-RBAC false-pass trap **does not apply** where it looked like
it might.

---

## 5. Cross-cutting: the conventions without gates

The project's defining strength is that a convention with a gate does not drift. This sweep found
**three conventions with no gate at all**, each already demonstrated to drift:

1. **No DB-level enums.** ✅ **Gated 2026-08-12** — `App\Support\DatabaseEnums` +
   `NoDatabaseEnumsConformanceTest`, which fails on a new one and on a stale grandfathered entry, so
   the list can only shrink.

   > **Correction to the count: it is 38, not 62.** That figure was read off a developer's local
   > MySQL, migrated incrementally for months and still carrying columns later migrations had
   > already freed. The test database is rebuilt from every migration on each run, so it is the only
   > honest answer — **24 of the 62 were ghosts.** Freeing the remaining ten operator-extensible ones
   > (`FREE_THESE`) is still open. The gate also had to read the SQLite CHECK constraint rather than
   > the column type: the first version checked type only, found zero enums in the environment it
   > runs in, and would have passed forever while gating nothing.

   The original entry, for the record: **62** enum columns survive; only four have ever been freed. It cost two
   migrations in three days: `add_written_off_to_invoices_status` (an `ALTER TABLE … MODIFY` on the
   hottest table to add one value) and `free_charges_type_from_its_db_enum`, whose own docblock records
   that the enum had **silently broken the charge-code catalogue's recurring-billing promise**.

   > **Correction to the lead's initial framing.** I first presented this as a latent-breakage risk.
   > The data-integrity audit disproved that half: Laravel renders `enum()` on SQLite as
   > `varchar check (...)`, so **tests enforce the identical set** — there is no false-green hole — and a
   > diff of every model's `STATUS_*`/`TYPE_*`/`METHOD_*` constant against all 62 DB sets found **zero
   > mismatches**. The real case is narrower and still worth acting on: **deploy cost and operator
   > autonomy.** 17 of the 62 are genuinely operator- or accountant-extensible and should be freed —
   > sharpest being `payments.method` (Egypt's rails keep moving: Fawry, Meeza, Aman, Vodafone Cash —
   > each a blocking `ALTER` on the hottest table), `units.category` (no anchor, no cinema),
   > `utility_meters.type` (no district cooling), and the four `cash|bank` pairs, which already lose to
   > the new `bank_accounts` table — with more than one bank, `paid_from = 'bank'` cannot say which.
   > 24 are genuinely fixed and should stay; 21 are fixed in principle but each widening is an `ALTER`
   > on a table that will be large.
2. **Concurrency** (§4.1) — 110 lock sites, untestable by construction.
3. **Derived money columns must not be client-writable** (§1.4).

Each is a ~half-day gate that pays for itself the first time it fires.

**PHPStan debt** belongs here too, and it stopped being abstract during this sweep. Level 5,
**492 errors across 409 baseline entries** (the entry count understates it), with
`app/Filament/*/Resources/*/Schemas/*` and `.../Pages/*` **excluded** — so much of the UI layer is
unanalysed, and both HIGH security findings in §1.2–1.3 live in `Schemas/`.

> **The baseline is hiding a live, go-live-blocking bug.** `LeaseImporter.php:41` uses `$this` inside
> a closure defined in `public static function getColumns()`, where no `$this` is bound. PHPStan
> caught it **twice** — `nullCoalesce.variable` ("Variable `$this` on left side of `??` is never
> defined") and the downstream `if.alwaysFalse` — and **both are in `phpstan-baseline.neon`**. The
> consequence is that `$record->unit_id` is never set on a NOT-NULL column, so the lease import cannot
> work at all. The correct static-context pattern is four files away: `UnitImporter` calls
> `self::resolveVisibleAsset()` from inside its closures and reserves `$this->data` for
> `resolveRecord()`, which is an instance method.

Of the whole baseline, only that pair are live bugs. Most of the largest category — "Access to an
undefined property" (~100+) — indicates missing `@property` docblocks rather than defects. **One trap
for any burn-down:** four `alwaysFalse` entries are load-bearing NOT-NULL guards on `balance` —
annotate them before touching them, or the burn-down removes a guard.

The `Pages/*` exclusion is the one that is not defensible: **152 files, 6,665 lines, containing 53
`assertAssetInScope` calls and 26 authz gates**, unanalysed — while CI is off.

---

## 6. Data-model & migration integrity

**No CRITICAL.** The governing observation is worth quoting: **every HIGH here is in an area with no
registry + conformance gate.** Where the project has one — Vat, PostingDateGuards, ChangeImpact,
PropertyIsolation, SearchPolicy, DeletionPolicy — the audit found nothing. The ungoverned areas are FK
delete behaviour, unique indexes, rate conventions, engine divergence, and what `atriom:install` seeds.

### 6.1 HIGH

**The GL's property dimension derives through a soft-deletable relation with no `withTrashed()`.**
`InvoiceJournalizer.php:83` and `PaymentJournalizer.php:46` both walk `$invoice->lease?->unit?->asset_id`,
and neither `Lease::unit()` nor `Invoice::lease()` carries `withTrashed()` — while both models
soft-delete. The project already knows the pattern (`StockMovement.php:83`, `PayrollLine.php:58`,
`Custody.php:65`, `PurchaseRequest.php:136` all use it); the four GL-critical relations do not. If a
`Unit` ever acquires `deleted_at` via a path that skips model events, the derivation yields
`asset_id = null`, `matches()` goes false because it compares asset, and **the next sweep voids the
posted entry and reposts it with no property dimension** — the money leaves every per-property P&L and
owner statement overnight, unattended.

**A GL entry with `asset_id = null` is invisible to every owner statement.** The forms deliberately
allow a blank property with a "Consolidated" placeholder (`ExpenseForm`, `VendorBillForm`,
`PayrollForm`, `JournalEntryForm` — none `required()`), but `LedgerReportService.php:91,416` filters
`whereIn('je.asset_id', $assetIds)` and **SQL `IN` never matches NULL**. A 500k vendor bill with
Property left blank appears in the consolidated P&L and the trial balance — so it looks recorded — but
never reduces any property's net, **overstating the owner's distributable**. The year-end close *does*
handle the null bucket explicitly, which proves the case was considered there and not here. No screen
lists unassigned entries.

**"One invoice per lease per period" is guaranteed by a cache lock, not a DB index** — and
`MonthlyBillingService.php:143-149` says so in its own comment. AR correctness therefore depends on
`CACHE_STORE`; set it to `array`/`file`, or run multi-node with a per-container cache, and two clicks
double-bill a tenant into an `Invoice`, which is `NEVER_DELETABLE`.

**`TenantImporter` dedupes on `tenants.email` — nullable, with no unique index.** Re-running an import
(the normal response to a partial one) creates a fresh duplicate of every email-less tenant, which then
acquire leases and invoices independently, **splitting one retailer's AR across two records that can no
longer be merged**. Both sibling importers key on something genuinely unique.

**`cascadeOnDelete` reaches four `NEVER_DELETABLE` tables**, and
`GenerateOwnerStatementRunService.php:125` performs a *builder* force-delete (`$run->statements()->forceDelete()`)
— a mass DELETE with no model events, so `RefusesDeletionOfCommittedRecords` never runs and the DB
cascade does. Nothing is destroyed today only because two unrelated workflow guards happen to align —
**a coincidence in two files, not a constraint.** Separately,
`RefusesDeletionWhenReferenced.php:38-43` early-returns and skips all blocker checks when force-deleting
an already-trashed record.

**Two engine-divergence classes make tests structurally unable to fail.** MySQL is
`utf8mb4_unicode_ci` (case- and accent-insensitive) while SQLite is binary — so ~40 unique indexes
behave differently in tests than in production, and `Charge::assertTypeIsAKnownChargeCode()` is
**genuinely a different function per engine**. And MySQL `strict` mode makes decimal and VARCHAR
overflow an error where SQLite silently accepts it. *(No live overflow instance was found — the class
is live because no test can ever reproduce a rejection.)*

### 6.2 MEDIUM — selected

**Two rate conventions on adjacent columns of the same table** — `cam_expense_pools.admin_fee_pct` is a
fraction while `variable_pct`/`controllable_pct`/`gross_up_pct` are percents · **15 migrations mix a
data backfill with the schema change**, so on MySQL a mid-flight failure leaves the schema applied and
unrecorded and the re-run fails — they replay cleanly in SQLite tests, which is why nobody has noticed
· money precision is split `decimal(12,2)`/`(14,2)`/`(15,2)` across tables holding the same quantity,
**crossing the boundary inside one settlement flow** · `timestamp` and `dateTime` are mixed with no
pinned connection timezone — **fix before production data exists** · five polymorphic relations carry no
referential integrity, including `journal_entries.source_type/source_id`, which is the auditor's link
from an entry to its document · `Charge` is hard-deletable and nulls the provenance link on three
historical documents · `AssetStaffRelationManager` still writes `asset_user.role`, dropped in July —
**the value vanishes behind a success toast** · `InvoiceExporter` exports `vat_total`, which is neither
a column nor an accessor, so **the VAT column is blank on every exported row**.

**And a test-suite-only class worth naming:** a wide band of fixtures passes column names that do not
exist — `Vendor::create(['category'])` in **15 files**, plus `InvoiceItem`, `Charge` and `VendorBill`
instances. Mass assignment discards them silently, so each test is green over a field the app does not
have. The same sweep over `app/`, `database/seeders/` and `database/factories/` came back **clean** —
this is purely a `tests/` problem, and it is the project's own "tests must use reachable inputs" rule
being broken at scale.

### 6.3 Sound by construction — do NOT touch

FK coverage is **total** (only 2 unconstrained `*_id`, both deliberate polymorphic keys) · `onDelete`
on the money core is right — restrict on every document parent, `nullOnDelete` on every
`*_by_user_id` audit stamp · **`$fillable` and `$casts` match the schema everywhere** — all 174 decimal
scales correct, all 13 app JSON columns array-cast, zero non-column fillables · **no relation points at
a renamed table and no dropped column has a live reference**, beyond the three already listed · both
enum-widening migrations re-list every prior value in both directions · model constants match all 62 DB
enum sets · form `maxLength()` matches every sized varchar · **`atriom:install`'s posting readiness is
genuinely verified** — 48 posting roles resolved through the real `AccountResolver`, exiting non-zero on
failure · an empty `charge_codes` catalogue refuses nothing, through three documented fallback layers.

---

## 7. Maintainability

**Two numbering systems.** `AllocatesDocumentNumber` (locked, spanning the insert, with the unique index
as backstop) and **ten hand-rolled `generateReference()`s** that are not. One of them is FS-05, a
deterministic 500.

**Two lease-creation paths, and the default one is the weaker.** `CreateLease` uses Filament's stock
`CreateRecord` flow — `mutateFormDataBeforeCreate()` does property-scope validation and `afterCreate()`
seeds charges — with **no unit lock and no `isActivelyLeased()` check**. Only `LeaseCreationService`
(the quick wizard) and `LeaseRenewalService` lock. Together with `LeaseSpaceChangeService::expand()`,
that makes **two of four activation paths guarded**, not the "both" the gap-analysis claims.

**God classes, judged individually rather than by size:** `LeasesTable::configure()` is a single
**~1,030-line static method** — accumulated responsibility, worth splitting · `CamReconciliationService`
is two services sharing a file · `Lease.php`, `ReportService.php` and `MonthlyBillingService.php` are
**justified cohesion** and encode hard-won rules — leave them alone.

**`docs/money/` is a stale mirror of the money rules, and a fresh index vouches for it as current** —
including a two-channel statement of the four-channel AR invariant. **`CLAUDE.md`'s scheduled-jobs list
omits 8 of the 29 scheduled commands.**
