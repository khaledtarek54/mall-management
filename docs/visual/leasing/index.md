# Leasing — where the money starts

<p class="eyebrow">Upstream of the spine</p>

Before there's a bill, there's a **deal**. Leasing is the front of the money spine: you take an empty unit, sign a tenant to it, and the lease you write decides *everything* the [money spine](/money/) bills from then on. Get the lease right and the invoices take care of themselves.

## From an empty unit to monthly rent

<p class="sub">Five steps turn floor space into recurring revenue. The last one hands off to the money spine.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Property</span><span class="d">The mall or site you manage (e.g. Atriom Walk).</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Unit</span><span class="d">A leasable space inside it, with a category and area.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Tenant</span><span class="d">The retailer who will occupy the unit.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Lease</span><span class="d">The contract: rent, term, deposit, the rules.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">Charges</span><span class="d">Recurring lines that the monthly run turns into invoices.</span></div></div>

<div class="plain">Step 5 is the handoff: a lease's <b>charges</b> are the template every monthly invoice is built from. That's literally where <a href="/money/">the money spine</a> picks up — no charges, no invoice.</div>

## What a lease decides

<p class="sub">One lease, and the money it sets in motion. These are the rules that seed every invoice.</p>

<div class="books"><div class="tcard"><div class="cap">Billed every month</div><p class="say">A typical retail lease — the recurring charges.</p><table class="t"><tr><th>Charge</th><th class="cr">Amount</th></tr><tr><td class="acc">Base rent <small>VAT-exempt</small></td><td class="amt">10,000</td></tr><tr><td class="acc">Service charge</td><td class="amt">2,000</td></tr><tr><td class="acc">VAT on service <small>14%</small></td><td class="amt crc">280</td></tr><tr><td class="acc">Marketing levy <small>5% of rent</small></td><td class="amt">500</td></tr><tr><td class="acc"><b>Total / month</b></td><td class="amt"><b>12,780</b></td></tr></table></div><div class="tcard"><div class="cap">Set once, or yearly</div><p class="say">The lease's other money terms.</p><table class="t"><tr><th>Term</th><th class="cr">Typical</th></tr><tr><td class="acc">Security deposit <small>held, not income</small></td><td class="amt">3× rent</td></tr><tr><td class="acc">Escalation <small>rent rises yearly</small></td><td class="amt">+7% / yr</td></tr><tr><td class="acc">Percentage rent <small>on strong sales</small></td><td class="amt">6% over</td></tr><tr><td class="acc">Term</td><td class="amt">1–5 yrs</td></tr></table></div></div>

<div class="rule"><span class="lbl">Rule · three charges, three treatments</span><b>Base rent</b> is VAT-exempt. The <b>service charge</b> carries 14% VAT. The <b>marketing levy</b> is 5% of base rent (it funds mall-wide promotion). The lease's <b>category</b> — food&nbsp;&amp;&nbsp;beverage, retail, wellness — is what decides the rent and whether percentage rent applies.</div>

<div class="plain"><b>Percentage rent</b> is the interesting one: an F&amp;B or retail tenant pays a share (e.g. 6%) of sales <em>above</em> a threshold, on top of base rent — so you share in their success. It's billed from their declared sales, separately from the monthly run.</div>

## The records, and how they connect

<div class="emap"><div class="enode"><span class="name">Property</span><span class="role">the mall / site you manage</span><span class="rels"><span class="rel has">has many Unit</span></span></div><div class="enode"><span class="name">Unit</span><span class="role">a leasable space (category · area)</span><span class="rels"><span class="rel">belongs to Property</span><span class="rel">has an active Lease</span></span></div><div class="enode"><span class="name">Tenant</span><span class="role">the retailer</span><span class="rels"><span class="rel has">has many Lease</span></span></div><div class="enode"><span class="name">Lease</span><span class="role">the contract</span><span class="rels"><span class="rel">belongs to Unit</span><span class="rel">belongs to Tenant</span><span class="rel has">has many Charge</span><span class="rel has">has many Invoice</span></span></div><div class="enode"><span class="name">Charge</span><span class="role">a recurring line that seeds invoices</span><span class="rels"><span class="rel">belongs to Lease</span></span></div></div>

## Go deeper

- **[Life of a lease →](/leasing/lease-lifecycle)** — draft, active, renewed, terminated, and how a unit follows along
- **[Units &amp; tenants →](/leasing/unit-and-tenant)** — the two supporting lifecycles
- **[Deposits in the books →](/leasing/deposits-in-the-books)** — why a deposit is money you *hold*, not earn
- **[The money spine →](/money/)** — where charges become invoices

_Full written rules: `docs/modules/01-properties-units.md`, `02-tenants.md`, `04-leases.md`._
