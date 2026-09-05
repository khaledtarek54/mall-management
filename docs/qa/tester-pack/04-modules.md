# Every part of the system

Do **[the money cycle](03-the-cycle.md)** first — it gives you a working lease, invoices and books
to test everything else against.

Each entry says **what it is for in business terms**, what to check, and what would be wrong. That
order is deliberate: you cannot judge whether a screen is correct until you know what job it does.

**Work in Val Plaza.** Nile Gate is the soak — read it, don't write to it.

You do not have to finish this in one pass. The groups are roughly in the order a real mall uses
them.

---

# Setting up the mall

## Properties, floors & units
**What it is:** the physical building. A **property** is a mall; it has **floors**; floors hold
**units** (shops). Everything else hangs off a unit — you cannot let space that does not exist.

**Check:** create a floor and a unit; change a unit's area and confirm anything derived from it
(rent per m², occupancy, CAM share) follows; retire a unit that has never been let.

**Wrong if:** you can delete a unit that has a lease on it, or a unit's status contradicts the
leases holding it.

## Areas
**What it is:** zones within a mall (food court, parking, back-of-house) used to **route maintenance
work** to the right team.

**Check:** assign an area to a unit, raise a maintenance request against it, confirm the work order
lands with that area on it.

**Wrong if:** a work order comes out with no area when its unit has one.

## Departments
**What it is:** the operator's own internal teams — who work orders and purchases are assigned to.

**Check:** a department is offered on the work-order and employee forms.

**Wrong if:** the department picker is **empty**. Departments are shared across the whole portfolio,
so an empty list on a mall-specific screen is a real bug that has happened before.

## Tenants
**What it is:** the retailers. A tenant is a **company**, not a person — commercial registration,
tax ID, possibly several shops.

**Check:** create one with Arabic and English names; search for it by the Arabic name, by phone, by
tax ID; confirm the code is allocated automatically. Open the **tenant's own page** and check the
overview answers compliance (documents in date?) and turnover in one place.

**Wrong if:** searching an Arabic name with a different but equivalent spelling finds nothing —
search is supposed to be insensitive to the alternative spellings of the same Arabic word.

## Portal users
**What it is:** the retailer's own logins. One company can have several staff; only some may act.
**Since September 2026 the same login opens the web portal and the mobile app.**

**Check:** with `cilantro@plaza.test` (admin) and `test@example.com` (read-only) — confirm the
read-only one can **see** but not **pay** or **raise** anything, on both web and app.

**Wrong if:** a read-only portal user can perform an action, or one tenant's user sees another
tenant's data. The second is the most serious bug class in the portal.

## Custom fields
**What it is:** lets the operator add their own fields to tenants, leases, units, vendors and
properties without a developer.

**Check:** add a field, fill it on a record, then find that record by **filtering** and by
**sorting** on it; export the list and confirm the column is there; re-open the record and confirm
the value is still filled in.

**Wrong if:** the value disappears when you edit the record, or a record that never answered the
field is shown as blank rather than excluded when you filter.

## Document wording
**What it is:** the standing text on tenant-facing documents — payment instructions, terms — set by
the operator per mall or for the whole portfolio.

**Check:** set payment instructions for Val Plaza, generate an invoice PDF, confirm they appear.

**Wrong if:** wording set for the whole portfolio does not appear on a specific mall's document, or
a mall's own wording does not override the house default.

---

# Letting space

## Leases
**What it is:** the contract. Fixes the rent, the service charge, the term, the deposit and any
escalation. **Everything about money starts here.**

**Check:** renew a lease (the renewal is a *new* lease); amend one mid-term; terminate one early;
add a second unit to an existing lease; record an option being exercised.

**Wrong if:** renewing at a **negotiated** rent silently saves a different figure — the deal wins
over any rate-per-m² calculation. Or a terminated lease keeps billing.

## Rent escalation
**What it is:** the clause that steps the rent up on each anniversary — a fixed percentage, or
indexed.

**Check:** set an escalation, run the escalation sweep (or wait for the anniversary), confirm the
rent steps **once** and the step reaches the **rest of the lease**, not only the next invoice.
An escalation clause can now also cover the **service charge**, not only base rent — check both.

**Wrong if:** the rent steps twice for one anniversary, or the ladder of future rents does not
follow a change you make.

## Holdover
**What it is:** a tenant whose lease has ended but who is still trading. Usually priced at a
**premium** — e.g. 150% of the contracted rent.

**Check:** take a lease past its end date, convert it to holdover, confirm the rent is the uplift
and that the shop is still held.

**Wrong if:** converting re-writes the contracted rate itself (it must stay contractual — the
premium is applied on top), or a holdover lease cannot be found/actioned at all.

## Rentable items
**What it is:** things let that are **not shops** — parking bays, kiosks, signage panels, storage.

**Check:** attach a bay to a lease, confirm it shows as taken; release it and confirm it becomes
available; end the lease and confirm the bay is freed. Look at the **parking/rentable map**.

**Wrong if:** a bay stays *assigned* after its lease has ended — it becomes invisible to whoever is
looking for free space.

## Unit owners
**What it is:** some units are **sold**, not let. The buyer pays a monthly service assessment
(*siyana*) rather than rent, and shares in the recovery pools.

**Check:** record an ownership, give it an assessment schedule, run the assessment billing. Record a
**resale** part-way through a year.

**Wrong if:** an ownership with no schedule is silently skipped rather than reported — the owner
simply never gets billed and nothing says so. Or, after a resale, the seller and buyer are not each
billed for their own part of the year.

---

# Billing and collecting

## Billing & invoices
**What it is:** turning a lease's charges into a monthly bill.

**Check:** run the monthly billing for the whole property; confirm each active lease produces one
invoice and inactive ones produce none **with a stated reason you can read**; run it **twice** and
confirm the second run bills nothing.

**Wrong if:** a second run double-bills, or a lease is skipped with a reason that renders as a raw
code (e.g. `admin.billing_preview.reason.lease_not_billable`) instead of a sentence.

## Issuing
**What it is:** the deliberate act that turns a draft into a live document. Its own permission.

**Check:** as `manager@` (or `accounting@`) you can issue. After issuing, the status field and the
service period lock; the **due date** stays editable while money is owed. `viewer@` may read the
list but open no record; `leasing@` has no Invoices screen at all. (No seeded login here holds
*edit without issue* — testing that split needs a role created for it.)

**Wrong if:** anything makes a draft become issued without pressing Issue — for example saving a
line onto it.

## Payments
**What it is:** money in, matched against bills.

**Check:** split one payment across two invoices; pay by different methods (cash, bank transfer,
card); void a payment; confirm the **bank account** is asked for and defaulted.

**Wrong if:** voiding a payment does not restore the invoice balance; a voided payment still counts
as paid; or the reason for the reversal is not recorded — a void, a refund and a bounced cheque are
**three different events**.

## Credit notes
**What it is:** the document that reduces what a tenant owes.

**Check:** issue one, apply it, then un-apply it; try to apply one to a cancelled invoice.

**Wrong if:** applying a credit changes the original invoice's own figures instead of recording a
separate settlement.

## Late fees & dunning
**What it is:** a percentage charged on an overdue invoice after a grace period, and the reminders
that chase it.

**Check:** back-date an invoice so it becomes overdue, run the late-fee sweep, confirm one fee. Set
a **maximum** and confirm a large debt's fee is capped. Then **write off part** of a debt and
confirm the chase asks for the *remaining* amount, not the original.

**Wrong if:** a late fee earns its own late fee; a fee exceeds a cap the operator set; or a reminder
asks for money the operator already forgave.

## Write-offs
**What it is:** recording that a debt will not be collected.

**Check:** write off part of an invoice, then a whole one. Then check what the AR ageing, the
collections worklist and the tenant statement say.

**Wrong if:** a written-off amount is still being chased, or a fully written-off invoice disappears
from history rather than being marked.

## The public pay link
**What it is:** the link in an invoice email that lets a tenant pay **with no login at all**.

**Check:** open the link for an issued invoice; confirm the amount matches. Then try the link for a
**draft**, a **cancelled**, and a **partly written-off** invoice.

**Wrong if:** a draft is reachable at all (it names the tenant and the amount to anyone with the
link), or the amount asked for is more than is actually owed.

---

# Deposits and cheques

## Security deposits
**What it is:** money held against the lease — usually a few months' rent. It is the operator's
**liability**, not income: they are holding it, and give it back (less deductions) at move-out.

**Check:** bill the deposit, take payment, then **apply part of it** to an unpaid invoice. Then
settle a move-out and confirm the refund is what is genuinely left.

**Wrong if:** the "held" figure and the deposit register disagree; a partly credited or partly
written-off deposit invoice makes the held figure wrong; or a move-out refunds money that never
actually arrived. **Anything here that refunds money outward is serious.**

## Post-dated cheques
**What it is:** very common in Egypt — a tenant hands over cheques dated months ahead. The operator
holds them and banks each on its date.

**Check:** lodge several cheques against a lease; **deposit** one (it should ask which bank account
and on what date); clear one on maturity; mark one as bounced and re-present it.

**Wrong if:** clearing a cheque does not settle the invoice; a bounced cheque leaves the invoice
looking paid; a cheque can be lodged with a future date; or a cheque can be cleared against an
invoice that has already been written off (that would relieve the debt twice).

## Bank accounts & reconciliation
**What it is:** the operator's real bank accounts, each mapped to its **own** chart account, and the
matching of statement lines against what the system recorded.

**Check:** create a bank account (step 0 of the cycle); import or enter a statement; match a line to
a payment.

**Wrong if:** two bank accounts share a chart account; or the candidates offered for a statement
line include transactions from a different bank.

---

# Recovering shared costs

## CAM — common area maintenance
**What it is:** the mall's shared running costs (cleaning, security, common-area power) recovered
from tenants in proportion to their space.

**Check:** create a cost pool for the year, add costs, run the reconciliation. Check a tenant who
was only in occupation for **part** of the year. Check a **unit owner** is included.

**Wrong if:** the shares do not add back to the pool; a tenant is billed for a period they were not
in occupation; or a pool recovers **more than it cost** — that is over-charging retailers and is
serious.

**Refinements that are deliberate, not bugs:** an anchor tenant may be **carved out of the
denominator**; a lease may **exclude named cost accounts** from its own share.

## Utility meters
**What it is:** sub-metered electricity and water rebilled to the tenant who used it.

**Check:** record a reading, bill it, then try to edit the reading after billing.

**Wrong if:** a billed reading can be changed without the invoice following, or a reading is billed
to the wrong tenant when a unit changed hands mid-period.

## Tenant sales & percentage rent
**What it is:** many retail leases take a **share of the shop's turnover** above a threshold, on top
of base rent. The tenant declares monthly sales; the operator bills the overage.

**Check:** enter a sales declaration, lock it, confirm a percentage-rent charge appears. Enter a
**zero** declaration and confirm it is treated as "declared nothing", not "did not declare".

**Wrong if:** a locked declaration can still be edited; the overage is calculated on the wrong
threshold; or a deduction the operator typed is silently ignored.

---

# The tenant relationship

## Tenant requests
**What it is:** the retailer's channel for problems and asks — a broken air conditioner, a fit-out
permit, a copy of their lease.

**Check:** raise one from the portal, acknowledge, assign, resolve; confirm the tenant sees each
step; re-route a mis-filed one.

**Wrong if:** the tenant is not told when the state changes, or a **maintenance** request does not
turn into a work order carrying the right trade.

## Violations
**What it is:** breaches of the mall rules — trading outside the lease line, a blocked fire exit.
The operator records it and may fine.

**Check:** record a violation, apply the standard fine, bill it.

**Wrong if:** the fine is silently re-priced when the tariff changes later. What was charged is what
was charged.

> **Known:** *Record violation* currently 404s on this box. Already fixed — do not report.

## Announcements & shopper posts
**What it is:** two different audiences. **Announcements** go to tenants (a fire drill, changed
opening hours). **Marketing posts** are the shopper-facing feed (an offer, an event).

**Check:** schedule an announcement, confirm it reaches the portal; publish a post with a validity
window.

**Wrong if:** an expired post is still shown, or a tenant announcement leaks into the shopper feed.

## Marketing budget
**What it is:** tenants pay a marketing levy; the operator spends it promoting the mall and must
account for it.

**Check:** record spend against a budget; push it over the limit.

**Wrong if:** over-budget is not **visible** — going over should be obvious, not something you find
by adding numbers up yourself.

---

# Keeping the building running

## Facility work orders & service plans
**What it is:** the maintenance engine. A **work order** is one job; a **service plan** is a
recurring schedule (service the lifts monthly) that generates them.

**Check:** raise a corrective job from a tenant request; create a service plan and generate its next
cycle; record labour hours, a part drawn from stock and a supplier bill against one job, then
complete it. Look at a preventive job **on a phone** — its due date and its equipment should be
readable.

**Wrong if:** the job's cost does not equal labour + parts + bills; or a completed preventive job is
not counted as on-time when it was finished on its due day. **A job finished at 16:00 on the due
date is on time**, not late.

## Vendors
**What it is:** the contractors. They hold documents (insurance, commercial registration) that must
be current before they are sent onto the mall floor.

**Check:** add a vendor with an expiring insurance certificate; let it lapse; try to dispatch them.

**Wrong if:** a contractor with lapsed insurance can still be dispatched. That is a liability
decision, not a warning.

## Vendor portal
**What it is:** contractors' own logins at `/vendor`, where they accept jobs, post updates, upload
evidence and submit quotes.

**Check:** as `ops@nileclean.eg`, confirm you see **only** jobs dispatched to you. Try another
company's job by URL.

**Wrong if:** you can see or touch any job that was not sent to you. **A refused job must 404, not
403** — a 403 confirms the job exists. Note a contractor **cannot** mark a job done: finishing is
the operator's decision, not the contractor's claim.

## Vendor contracts & recurring costs
**What it is:** retainers and schedules — a cleaning contract that bills monthly, a municipal levy
that arrives on a calendar.

**Check:** create a recurring cost tied to a vendor contract, then **end the contract** and confirm
the schedule stops with it.

**Wrong if:** a schedule keeps drafting bills under a contract that has expired.

## Procurement
**What it is:** buying things — a purchase request, approval, a purchase order to a vendor, then
goods received.

**Check:** raise a request, take it through approval to an order, receive the goods partially then
fully.

**Wrong if:** receiving more than was ordered is allowed, or the stock does not increase when goods
are received.

## Inventory
**What it is:** the store of spare parts consumed by maintenance.

**Check:** receive stock, draw parts to a work order, check the stock value.

**Wrong if:** drawing a part does not reduce stock, or stock can go negative.

## Fixed assets
**What it is:** things the mall owns and writes down over time — chillers, lifts, generators.

**Check:** register an asset, run depreciation, view the register showing cost, accumulated
depreciation and net book value.

**Wrong if:** depreciation runs twice for one period, or the register's totals do not match the
balance sheet.

---

# People and money going out

## Employees & payroll
**What it is:** the operator's own staff and their monthly pay run. Egyptian salary tax and social
insurance are computed from a **dated** table of statutory rates.

**Check:** create an employee, generate a payroll run, approve it, then try to edit an approved run.

**Wrong if:** an approved payroll can be edited. Once approved it is a committed record and
corrections go through their own path.

## Employee advances
**What it is:** money lent to a staff member and recovered from later payslips.

**Check:** grant an advance, run payroll, confirm the deduction; confirm the outstanding falls.

**Wrong if:** an advance is deducted twice, or the outstanding does not account for a run that has
been approved.

## Treasury & custody
**What it is:** petty cash held by a named person (*ohda*) — money handed out and later accounted
for with receipts.

**Check:** grant custody, record spending against it, settle it.

**Wrong if:** custody can be spent past its balance, or a settled custody can be re-spent.

## Vendor bills & expenses
**What it is:** what the operator owes suppliers, and costs paid directly.

**Check:** enter a bill, approve it, pay it partially then fully; check withholding tax if enabled.

**Wrong if:** a bill can be paid more than once for the same amount, or an expense category books to
the wrong account.

## Approvals
**What it is:** the rule that certain things need a second person's sign-off above a threshold.

**Check:** set a rule, raise something below the threshold (should pass straight through) and
something above it (should wait).

**Wrong if:** the person who raised it can also approve it, or something above the threshold
proceeds without approval.

---

# The books

## General ledger
**What it is:** where every money movement lands as double-entry bookkeeping. Nobody types entries —
they are produced by the documents.

**Check:** trace an invoice you raised to its ledger entry and back; void a document and confirm the
ledger **reverses** rather than erases; close an accounting period and then try to change something
dated inside it.

**Wrong if:** debits and credits differ by any amount; a voided document leaves its entry standing;
or **a document inside a closed period can have its money changed**. A closed month is closed.

## Financial statements
**What it is:** trial balance, income statement, balance sheet, cash flow.

**Check:** the income statement stops at **Net Operating Income** before interest and depreciation.
Read it as **month beside year-to-date**, and as a **twelve-month spread**. Export to CSV and to PDF
and confirm all three agree.

**Wrong if:** a figure differs between the screen, the CSV and the PDF; or a statement scoped to one
mall carries another mall's figures under its caption. Watch for the notice about entries filed
against **no property** — it should appear only when there are some.

## Owner statements
**What it is:** what the operator sends the owner — income collected, costs incurred, the fee, and
what is owed.

**Check:** generate a statement for the month, then a revised version.

**Wrong if:** the figures do not reconcile to the ledger for the same period, or a revision silently
replaces the issued original instead of superseding it.

## Owner requests
**What it is:** the owner's own channel to the operator.

**Check:** raise one as `owner@atriom.test`, respond as the operator.

**Wrong if:** the owner sees anything belonging to a property they do not own, or a responded
request can be edited afterwards.

## Reports
**What it is:** the read-only views the business runs on — AR ageing, rent roll, occupancy,
expirations, collections, financial statements.

**Check:** open each; export a few to CSV; compare a figure against the screen it came from; search
within the rent roll.

**Wrong if:** an export disagrees with what is on screen, or a report shows data from a property you
are not looking at. **Check the caption too** — a statement captioned for one mall must not contain
another's figures.

---

# Cross-cutting — test these throughout

## Property isolation
**The single most serious bug class.** An operator in one mall must never see another's data.

**Check:** switch between Val Plaza and Nile Gate and confirm every list changes. Try reaching a
record in the other mall by editing the URL. Check **dropdowns and search**, not just lists.

**Wrong if:** any list, report, picker or dropdown offers or shows a record from a property you are
not in. Report anything suspicious even if you are not sure.

## Roles and access
14 roles, each seeing a different panel.

**Check:** as `viewer@` and `leasing@`, walk the panel. Try opening a screen you should not have by
typing its URL.

**Wrong if:** you reach it. Also wrong: being **offered** an action you are then refused.

## Search and the command palette
**What it is:** the bar at the top, searching across everything you are allowed to see — records
**and screens**.

**Check:** search for a tenant by Arabic name, phone, tax ID; for an invoice by number; and for a
**screen** by name ("rent roll", "trial balance").

**Wrong if:** a record you can see in a list cannot be found in search, or search returns something
from a property you are not in.

## Dropdowns and pickers
Every record picker should **open showing something** — not an empty box waiting to be typed into.

**Check:** open pickers across the panel — unit, tenant, lease, vendor, department, bank account,
chart account.

**Wrong if:** a picker is **empty** when records exist (an empty dropdown reads as "no such record",
so it gets reported as missing data rather than as a bug); it offers a record from another mall; or
it shows the same placeholder sentence twice.

## Lists — filters, saved views, columns
**Check:** filter a list and confirm the **chip** in the bar names the record you filtered by (not a
number, and not nothing). Save a view; set one as your default; open it from a link; use "All
records" to get back. Reorder and hide columns; export.

**Wrong if:** a filter chip is missing or unclear; a saved view opens with somebody else's filters
still applied; switching mall carries a search term across and leaves you looking at an empty list;
or adopting a colleague's shared view overwrites **their** default.

## Dashboard
**Check:** each KPI card can be **interrogated** — clicking through should land you on the records
behind the number.

**Wrong if:** a card's number and the list it leads to disagree.

## "Ask Atriom" — the assistant
**What it is:** a question box on every page. It retrieves from the system's own records, screen
guides and handbook, and answers.

**On this box the model layer is switched off**, so answers are assembled from what it found rather
than written as prose. **That is correct, not broken.**

**Check:** ask it operator-language questions — "what does *tenant name* owe", "how many units are
vacant", "how do I record a receipt", "where do I raise a credit note".

**Wrong if:** it answers with a confident figure about the wrong thing (ask "how many invoices are
**unpaid**" and check it did not answer with the total), or it sends you to the wrong screen for a
word an operator would really use.

## Notifications and scheduled work
**What it is:** the system watches for things and tells people — overdue invoices, expiring leases,
lapsed vendor documents, breached SLAs.

**Check:** back-date an invoice so it becomes overdue, then look at the bell.

**Wrong if:** a notification arrives in the wrong language for its reader, or has no working link to
the record it is about.

## The tenant portal and mobile API
**What it is:** the same data as the panel, rendered for the retailer. One login serves both.

**Check:** everything a tenant sees at `/portal`, against what you know is true in the panel.

**Wrong if:** the portal and the panel disagree about a balance, or the portal shows anything in
**draft**.

## Screen guides
Every screen has a **guide button** giving its purpose, the steps, the rules, and most usefully
**what else moves when you touch this one**.

**Check:** read it before testing a screen you do not know.

**Wrong if:** a guide is missing, is in the wrong language, or describes something the screen does
not do.

---

## When you have been through all of it

Go back and do the **four passes** — Arabic, another role, a phone, deliberately breaking things —
across the areas you now understand. The second pass over a module you know well finds different
defects from the first.
