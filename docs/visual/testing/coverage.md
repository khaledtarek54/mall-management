# Every part of the system

<p class="eyebrow">Module by module</p>

Do **[the money cycle](/testing/cycle)** first — it gives you a working lease, invoices and books
to test everything else against.

Each entry below says **what it is for in business terms**, what to check, and what would be wrong.
That order is deliberate: you cannot judge whether a screen is correct until you know what job it
is doing. Where a section links to a handbook page, read it — it explains the thing properly.

<div class="plain">You do not have to finish this in one pass. Work down it; the groups are roughly
in the order a real mall uses them.</div>

---

## Setting up the mall

### Properties, floors & units
*What it is:* the physical building. A **property** is a mall; it has **floors**; floors hold
**units** (shops). Everything else hangs off a unit — you cannot let space that does not exist.

**Check:** create a floor and a unit; change a unit's area and confirm anything derived from it
(rent per m², occupancy) follows; retire a unit that has never been let.
**Wrong if:** you can delete a unit that has a lease on it, or a unit's status contradicts the
leases holding it.

### Areas
*What it is:* zones within a mall (food court, parking, back-of-house) used to **route maintenance
work** to the right team.

**Check:** assign an area to a unit, raise a maintenance request against it, confirm the work order
lands with that area on it.
**Wrong if:** a work order comes out with no area when its unit has one.

### Departments
*What it is:* the operator's own internal teams — who work orders and purchases are assigned to.

**Check:** a department is offered on the work-order and employee forms.
**Wrong if:** the department picker is **empty**. Departments are shared across the whole portfolio,
so an empty list on a mall-specific screen is a real bug that has happened before.

### Tenants
*What it is:* the retailers. A tenant is a **company**, not a person — it has a commercial
registration, a tax ID, and possibly several shops.

**Check:** create one with Arabic and English names; search for it by the Arabic name, by phone, by
tax ID; confirm the code is allocated automatically.
**Wrong if:** searching an Arabic name with a different but equivalent spelling («شركه» vs «شركة»)
finds nothing. Search is supposed to be insensitive to that.

### Portal users
*What it is:* the retailer's own logins to `/portal`. One company can have several staff; only
some may act.

**Check:** create a second portal user without admin rights; confirm they can **see** but not
**pay** or **raise** anything.
**Wrong if:** a read-only portal user can perform an action, or one tenant's user sees another
tenant's data. The second is the most serious bug class in the portal.

### Custom fields
*What it is:* lets the operator add their own fields to tenants, leases, units, vendors and
properties without a developer.

**Check:** add a field, fill it on a record, then find that record by filtering and by sorting on
it; export the list and confirm the column is there.
**Wrong if:** a record that never answered the field is shown as blank rather than excluded when
you filter, or the value disappears when you edit the record.

---

## Letting space

### Leases
*What it is:* the contract. It fixes the rent, the service charge, the term, the deposit and any
escalation. **Everything about money starts here.**
<a href="/leasing/lease-lifecycle">Life of a lease →</a>

**Check:** renew a lease (the renewal is a *new* lease); amend one mid-term; terminate one early;
add a second unit to an existing lease.
**Wrong if:** renewing at a **negotiated** rent silently saves a different figure — the deal wins
over any rate calculation. Or a terminated lease keeps billing.

### Rentable items
*What it is:* things let that are **not shops** — parking bays, kiosks, signage panels, storage.
Same idea as a unit, different kind of space.

**Check:** attach a bay to a lease, confirm it shows as taken; release it and confirm it becomes
available; end the lease and confirm the bay is freed.
**Wrong if:** a bay stays *assigned* after its lease has ended — it becomes invisible to whoever is
looking for free space.

### Unit owners
*What it is:* some units are **sold**, not let. The buyer is an owner who pays a monthly service
assessment (صيانة) rather than rent.

**Check:** record an ownership, give it an assessment schedule, run the assessment billing.
**Wrong if:** an ownership with no schedule is silently skipped rather than reported — the owner
simply never gets billed and nothing says so.

---

## Billing and collecting

### Billing & invoices
*What it is:* turning a lease's charges into a monthly bill. <a href="/money/invoice-lifecycle">Life of an invoice →</a>

**Check:** run the monthly billing for the whole property; confirm each active lease produces one
invoice and inactive ones produce none *with a stated reason*; run it **twice** and confirm the
second run bills nothing.
**Wrong if:** a second run double-bills, or a lease is skipped with no explanation you can read.

### Payments
*What it is:* money in, matched against bills. <a href="/money/">The money spine →</a>

**Check:** split one payment across two invoices; pay by different methods (cash, bank transfer);
void a payment.
**Wrong if:** voiding a payment does not restore the invoice balance, or a voided payment still
counts toward what the tenant has paid. Also check the reason for the reversal is recorded — a
void, a refund and a bounced cheque are **three different events** and must not all be called the
same thing.

### Credit notes
*What it is:* the document that reduces what a tenant owes.
<a href="/money/credit-note-lifecycle">Life of a credit note →</a>

**Check:** issue one, apply it, then un-apply it; try to apply one to a cancelled invoice.
**Wrong if:** applying a credit changes the original invoice's figures instead of recording a
separate settlement.

### Post-dated cheques
*What it is:* very common in Egypt — a tenant hands over cheques dated months ahead. The operator
holds them and banks each on its date.

**Check:** lodge several cheques against a lease; clear one on its maturity date; mark one as
bounced.
**Wrong if:** clearing a cheque does not settle the invoice, or a bounced cheque leaves the invoice
looking paid.

---

## Recovering shared costs

### CAM — common area maintenance
*What it is:* the mall's shared running costs (cleaning, security, common-area power) recovered
from tenants in proportion to their space. <a href="/operations/cam-recovery">CAM cost recovery →</a>

**Check:** create a cost pool for the year, add costs, run the reconciliation.
**Wrong if:** the shares do not add back to the pool, or a tenant is billed for a period they were
not in occupation. A pool recovering **more than it cost** is serious.

### Utility meters
*What it is:* sub-metered electricity and water rebilled to the tenant who used it.

**Check:** record a reading, bill it, then try to edit the reading after billing.
**Wrong if:** a billed reading can be changed without the invoice following, or a reading is billed
to the wrong tenant when a unit changed hands mid-period.

### Tenant sales & percentage rent
*What it is:* many retail leases take a **share of the shop's turnover** above a threshold, on top
of base rent. The tenant declares monthly sales; the operator bills the overage.

**Check:** enter a sales declaration, lock it, confirm a percentage-rent charge appears.
**Wrong if:** a locked declaration can still be edited, or the overage is calculated on the wrong
threshold.

---

## The tenant relationship

### Tenant requests
*What it is:* the retailer's channel for problems and asks — a broken air conditioner, a fit-out
permit, a copy of their lease. <a href="/operations/request-lifecycle">Life of a request →</a>

**Check:** raise one from the portal, acknowledge, assign, resolve; confirm the tenant sees each
step; re-route a mis-filed one.
**Wrong if:** the tenant is not told when the state changes, or a maintenance request does not turn
into a work order.

### Violations
*What it is:* breaches of the mall rules — trading outside the lease line, a fire exit blocked. The
operator records it and may fine.

**Check:** record a violation, apply the standard fine, bill it.
**Wrong if:** the fine is silently re-priced when the tariff changes later. What was charged is
what was charged.

### Announcements & shopper posts
*What it is:* two different audiences. **Announcements** go to tenants (a fire drill, changed
opening hours). **Marketing posts** are the shopper-facing feed (an offer, an event).

**Check:** schedule an announcement, confirm it reaches the portal; publish a post with a validity
window.
**Wrong if:** an expired post is still shown, or a tenant announcement leaks into the shopper feed.

### Marketing budget
*What it is:* tenants pay a marketing levy; the operator spends it promoting the mall and must
account for it.

**Check:** record spend against a budget; push it over the limit.
**Wrong if:** over-budget is not visible — going over should be obvious, not something you find by
adding numbers up yourself.

---

## Keeping the building running

### Facility work orders & service plans
*What it is:* the maintenance engine. A **work order** is one job; a **service plan** is a
recurring schedule (service the lifts monthly) that generates them.
<a href="/operations/preventive-and-vendors">Preventive & vendors →</a> ·
<a href="/operations/what-a-job-costs">What a job costs →</a>

**Check:** raise a corrective job from a tenant request; create a service plan and generate its
next cycle; record labour hours, a part drawn from stock and a supplier bill against one job, then
complete it.
**Wrong if:** the job's cost does not equal labour + parts + bills, or a completed preventive job
is not counted as on-time when it was finished on its due day. **A job finished at 16:00 on the due
date is on time**, not late.

### Vendors
*What it is:* the contractors. They hold documents (insurance, commercial registration) that must
be current before they are sent onto the mall floor.

**Check:** add a vendor with an expiring insurance certificate; let it lapse; try to dispatch them.
**Wrong if:** a contractor with lapsed insurance can still be dispatched. That is a liability
decision, not a warning.

### Vendor portal
*What it is:* contractors' own logins, where they accept jobs, post updates, upload evidence and
submit quotes.

**Check:** as a contractor, confirm you see **only** jobs dispatched to you.
**Wrong if:** you can see or touch any job that was not sent to you — including by editing the URL.
Note a contractor **cannot** mark a job done: finishing is the operator's decision, not the
contractor's claim.

### Procurement
*What it is:* buying things — a purchase request, approval, a purchase order to a vendor, then
goods received.

**Check:** raise a request, take it through approval to an order, receive the goods partially then
fully.
**Wrong if:** receiving more than was ordered is allowed, or the stock does not increase when goods
are received.

### Inventory
*What it is:* the store of spare parts consumed by maintenance.
<a href="/operations/inventory-and-books">Inventory in the books →</a>

**Check:** receive stock, draw parts to a work order, check the stock value.
**Wrong if:** drawing a part does not reduce stock, or stock can go negative.

### Fixed assets
*What it is:* things the mall owns and writes down over time — chillers, lifts, generators.
<a href="/accounting/fixed-assets">Fixed assets & depreciation →</a>

**Check:** register an asset, run depreciation, view the register showing cost, accumulated
depreciation and net book value.
**Wrong if:** depreciation runs twice for one period, or the register's totals do not match the
balance sheet.

---

## People and money going out

### Employees & payroll
*What it is:* the operator's own staff and their monthly pay run.
<a href="/people/payroll">Payroll →</a>

**Check:** create an employee, generate a payroll run, approve it, then try to edit an approved run.
**Wrong if:** an approved payroll can be edited. Once approved it is a committed record and
corrections go through their own path.

### Treasury & custody
*What it is:* petty cash held by a named person (عهدة) — money handed out and later accounted for
with receipts. <a href="/people/advances-and-custody">Advances & custody →</a>

**Check:** grant custody, record spending against it, settle it.
**Wrong if:** custody can be spent past its balance, or a settled custody can be re-spent.

### Approvals
*What it is:* the rule that certain things need a second person's sign-off above a threshold.

**Check:** set a rule, raise something below the threshold (should pass straight through) and
something above it (should wait).
**Wrong if:** the person who raised it can also approve it, or something above the threshold
proceeds without approval.

---

## The books

### General ledger
*What it is:* where every money movement in the system lands as double-entry bookkeeping. Nobody
types entries — they are produced by the documents.
<a href="/accounting/the-ledger">The ledger & the rules →</a>

**Check:** trace an invoice you raised to its ledger entry and back; void a document and confirm
the ledger reverses rather than erases; close an accounting period and then try to change something
dated inside it.
**Wrong if:** debits and credits differ by any amount; a voided document leaves its entry standing;
or **a document inside a closed period can have its money changed**. A closed month is closed.

### Owner statements
*What it is:* what the operator sends the owner — income collected, costs incurred, the fee, and
what is owed. <a href="/accounting/close-and-reconcile">Close & reconcile →</a>

**Check:** generate a statement for the month, then a revised version.
**Wrong if:** the figures do not reconcile to the ledger for the same period, or a revision
silently replaces the issued original instead of superseding it.

### Owner requests
*What it is:* the owner's own channel to the operator — a question about a statement, a request for
information.

**Check:** raise one as `owner@atriom.test`, respond as the operator.
**Wrong if:** the owner sees anything belonging to a property they do not own, or a responded
request can be edited afterwards.

### Reports
*What it is:* the read-only views the business runs on — AR aging, rent roll, occupancy,
expirations, the financial statements.

**Check:** open each; export a few to CSV; compare a figure against the screen it came from.
**Wrong if:** an export disagrees with what is on screen, or a report shows data from a property
you are not looking at. Check the **caption** too: a statement captioned for one mall must not
contain another's figures.

---

## Cross-cutting — test these throughout

### Roles and access
*What it is:* 14 roles, each seeing a different panel.

**Check:** as a narrow role (`viewer`, `leasing`), walk the panel. Try opening a screen you should
not have by typing its URL.
**Wrong if:** you reach it. Also wrong: being **offered** an action you are then refused.

### Property isolation
*What it is:* an operator restricted to one mall must never see another's data.

**Check:** this box has **two** malls — Atriom Walk and Plaza Annex. Switch between them and
confirm every list changes. Try reaching a record in the other mall by editing the URL.
**Wrong if:** any list, report, picker or dropdown offers or shows a record from a property you are
not in. **This is the single most serious bug class in the system** — report anything suspicious
even if you are not sure.

### Search
*What it is:* the bar at the top of the panel, searching across everything you are allowed to see.

**Check:** search for a tenant by Arabic name, phone, tax ID; for an invoice by number.
**Wrong if:** a record you can see in a list cannot be found in search, or search returns something
from a property you are not in.

### Notifications and scheduled work
*What it is:* the system watches for things and tells people — overdue invoices, expiring leases,
lapsed vendor documents, breached SLAs.

**Check:** create an overdue invoice (back-date one), then look at the bell.
**Wrong if:** a notification arrives in the wrong language for its reader, or has no working link
to the record it is about.

### The tenant portal and mobile API
*What it is:* the same data as the panel, rendered for the retailer.

**Check:** everything a tenant sees at `/portal`, against what you know is true in the panel.
**Wrong if:** the portal and the panel disagree about a balance, or the portal shows anything in
**draft**.

---

## When you have been through all of it

Go back to **[the four passes](/testing/#four-passes-that-find-different-bugs)** — Arabic, other
roles, phone, deliberately breaking things — and do them across the areas you now understand. The
second pass over a module you know well finds different defects from the first.
