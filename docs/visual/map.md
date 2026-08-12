# The whole system, one page

<p class="eyebrow">The map</p>

Atriom is big — 36 modules, three logins, a general ledger underneath all of it. But it is
not complicated in 36 different ways. **There is one spine and four things hanging off it.**
Learn the spine and every module has an obvious place.

This page is the map. Every box links to the drawing that explains it.

## The one sentence

<div class="rule"><span class="lbl">The spine</span><b>A lease turns space into money owed; a bill collects it; the ledger records it.</b><br><br>Everything else either <b>feeds the bill</b> (CAM, percentage rent, utilities, the marketing levy), <b>spends money</b> (payroll, vendors, custody, parts), or <b>keeps the building running</b> (requests, preventive maintenance, inventory). All of it lands in the same books.</div>

## The spine, drawn

<p class="sub">Five steps. If you only ever learn one thing about Atriom, learn this line.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Lease</span><span class="d">A tenant signs for a unit. This fixes the rent and the term.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Charges</span><span class="d">Rent + service charge + marketing levy attach to the lease.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Invoice</span><span class="d">Monthly, the charges become a bill. VAT is added where it applies.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Payment</span><span class="d">The tenant pays. The balance falls toward zero.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">The books</span><span class="d">Every step above posts itself to the ledger.</span></div></div>

<div class="plain">Step 05 is the part people miss. <b>You never "do the accounting" in Atriom.</b> Each document posts its own journal entry automatically — the ledger is a consequence of operating the mall, not a separate job someone remembers to do. <a href="/accounting/the-ledger">How that works →</a></div>

## Who logs in where

<p class="sub">Three doors into one database. Most confusion about Atriom is really confusion about which door.</p>

<div class="emap"><div class="enode"><span class="name">/admin</span><span class="role">Eltizam staff + Jawad (owners), scoped by role</span><span class="rels"><span class="rel">identity: User + roles</span><span class="rel has">sees the selected property</span></span></div><div class="enode"><span class="name">/portal</span><span class="role">retailers, on the web</span><span class="rels"><span class="rel">identity: TenantUser</span><span class="rel has">only admins may write</span></span></div><div class="enode"><span class="name">/api/v1</span><span class="role">retailers, in the mobile app</span><span class="rels"><span class="rel">identity: Tenant (Sanctum)</span><span class="rel has">cross-tenant returns 404</span></span></div></div>

<div class="rule"><span class="lbl">Rule · property isolation</span>The admin app always has <b>one property selected</b> — every table, every number is scoped to it. An <b>"All Properties"</b> pseudo-property gives the portfolio view. This isn't a filter you can forget: <b>every model is classified</b> as property-owned or shared, and a test fails the build if a new one ships unclassified.</div>

## The five subsystems

<p class="sub">Every one of the 36 modules lives in exactly one of these. This is the whole product.</p>

### 🔑 Leasing — where the money starts

<div class="emap"><div class="enode"><span class="name">01 · Properties &amp; Units</span><span class="role">the malls and the shops inside them</span></div><div class="enode"><span class="name">02 · Tenants</span><span class="role">the retailer companies</span></div><div class="enode"><span class="name">03 · Portal users</span><span class="role">the people at a retailer who log in</span></div><div class="enode"><span class="name">04 · Leases</span><span class="role">the contract — rent, term, deposit, escalation</span></div></div>

**[See it drawn →](/leasing/)**

### 💵 Money & AR — the spine itself

<div class="emap"><div class="enode"><span class="name">05 · Billing &amp; Invoices</span><span class="role">the monthly run, VAT, proration</span></div><div class="enode"><span class="name">06 · Payments</span><span class="role">cash in, allocation, late fees, Paymob</span></div><div class="enode"><span class="name">07 · Credit notes</span><span class="role">the spine, backwards</span></div><div class="enode"><span class="name">16 · ETA e-invoicing</span><span class="role">the Egyptian Tax Authority submission</span></div><div class="enode"><span class="name">17 · Reports</span><span class="role">monthly close, AR aging, statements</span></div></div>

**[See it drawn →](/money/)**

### 🔧 Operations — the engine room

<div class="emap"><div class="enode"><span class="name">08 · CAM</span><span class="role">shared costs, recovered pro-rata</span></div><div class="enode"><span class="name">09 · Tenant sales / % rent</span><span class="role">a cut of what the shop sells</span></div><div class="enode"><span class="name">10 · Utility meters</span><span class="role">consumption → a charge</span></div><div class="enode"><span class="name">11 · Tenant requests</span><span class="role">the tenant asks for something</span></div><div class="enode"><span class="name">22 · Inventory</span><span class="role">spare parts, per-mall warehouses</span></div><div class="enode"><span class="name">26 · Facility maintenance</span><span class="role">PPM plans + corrective jobs + SLA</span></div><div class="enode"><span class="name">27 · Announcements</span><span class="role">operator → tenants, broadcast</span></div></div>

**[See it drawn →](/operations/)**

### 👥 People & money-out

<div class="emap"><div class="enode"><span class="name">12 · Vendors</span><span class="role">contractors + their bills and contracts</span></div><div class="enode"><span class="name">13 · Marketing</span><span class="role">the 5% levy, budgets, spend</span></div><div class="enode"><span class="name">14 · Departments</span><span class="role">the org chart + routing</span></div><div class="enode"><span class="name">24 · HR / employees</span><span class="role">payroll, advances (سلف), payslips</span></div><div class="enode"><span class="name">25 · Treasury / custody</span><span class="role">cash in a custodian's hands (عهدة)</span></div><div class="enode"><span class="name">28 · Approvals</span><span class="role">who may approve what, by amount</span></div></div>

**[See it drawn →](/people/)**

### 📚 Accounting & close — where it all converges

<div class="emap"><div class="enode"><span class="name">21 · General Ledger</span><span class="role">chart of accounts, journals, periods, statements</span></div><div class="enode"><span class="name">23 · Fixed assets</span><span class="role">the register + monthly depreciation (الإهلاك)</span></div><div class="enode"><span class="name">15 · Owner requests</span><span class="role">Jawad asks, Eltizam answers</span></div><div class="enode"><span class="name">18 · RBAC &amp; scoping</span><span class="role">who sees what, where</span></div><div class="enode"><span class="name">19 · Notifications &amp; scans</span><span class="role">the things that happen on their own</span></div><div class="enode"><span class="name">20 · Mobile API</span><span class="role">the app's surface</span></div></div>

**[See it drawn →](/accounting/)**

## What actually reaches the books

<p class="sub">24 kinds of document post to the ledger. Every one of them posts the same way, through one registry.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">A document changes</span><span class="d">An invoice is issued, a payment captured, a penalty applied.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Its journalizer</span><span class="d">One small class turns that document into balanced debits and credits.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">The registry</span><span class="d">LedgerPoster knows which journalizer belongs to which document.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Four ways in</span><span class="d">Real-time on save · the nightly sweep · the close gate · the reconcile check — all derived from that one registry.</span></div></div>

The sources, by subsystem — the **[live list is generated from the registry itself](/modules/)**, so it cannot drift from this table:

| Subsystem | What posts |
|---|---|
| **Money & AR** | Invoice · Payment · Credit note |
| **People & money-out** | Vendor bill · Vendor bill payment · **SLA penalty** · Expense · Payroll · Employee advance · Advance repayment · Marketing spend |
| **Leasing** | Deposit transaction |
| **Operations** | Stock movement |
| **Accounting** | Fixed asset · Depreciation entry · Asset disposal · Custody · Custody transaction |

<div class="rule"><span class="lbl">Rule · one registry, learned the hard way</span>That list used to be <b>hand-copied into five places</b>, held together by a comment saying "keep in sync". They drifted. The <b>SLA penalty</b> had a perfectly correct journalizer while being absent from every list that <em>dispatches</em> one — so applying a penalty <b>cut the vendor's bill but posted nothing</b>, and the books quietly overstated what the mall owed. The tests passed, because they called the poster directly instead of running the real sweep.<br><br>There is now <b>one</b> registry, everything derives from it, and a conformance test fails the build if a journalizer has no way in. <em>Fixed 2026-07-16.</em></div>

## What happens without anyone clicking

<p class="sub">The operating calendar. If cron and the queue worker aren't running, none of this happens — and the failure is silent.</p>

<div class="track"><span class="pill p-teal">Daily<small>overdue scan · late fees · SLA breaches · auto-close · PPM raise · ledger sync</small></span><span class="conn">→</span><span class="pill p-amber">Monthly<small>the billing run · depreciation</small></span><span class="conn">→</span><span class="pill p-green">Annual<small>CAM reconciliation · year-end close</small></span></div>

<div class="plain">The monthly billing run and late fees are scheduled as <b>queued jobs</b>, not commands — which is why searching the scheduler for their command names finds nothing. A common and expensive confusion.</div>

## Where to go deeper

- **[Money & AR →](/money/)** — start here. The spine, drawn properly.
- **[Life of an invoice →](/money/invoice-lifecycle)** — every stage a bill moves through.
- **[The ledger & the rules →](/accounting/the-ledger)** — what each event debits and credits.
- **[Close & reconcile →](/accounting/close-and-reconcile)** — how a month gets shut.

_Written detail lives in `docs/modules/NN-*.md` (one per module — business rules, extension
points, gotchas). The live counts and coverage are generated into `docs/PROJECT-MAP.md` by
`php artisan atriom:dump-system-census`._
