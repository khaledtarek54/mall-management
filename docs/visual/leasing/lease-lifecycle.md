# Life of a lease

<p class="eyebrow">A lifecycle</p>

A lease is the longest-lived money record in Atriom — it can run for years, and how it *ends* matters as much as how it starts. Here's every stage, and how the unit underneath it follows along.

## From draft to live

<p class="sub">A lease is written, approved, then commences — that's when it starts billing.</p>

<div class="track"><span class="pill p-grey">Draft<small>being written</small></span><span class="conn">→</span><span class="pill p-amber">Pending approval<small>awaiting sign-off</small></span><span class="conn">→</span><span class="pill p-green">Active<small>live · billing</small></span></div>

The moment a lease turns **Active**, two things happen automatically: its **unit flips to Occupied**, and its **charges** become eligible for the monthly billing run. Everything the tenant owes flows from here.

## How an active lease ends

<p class="sub">Three ways out — and they're not the same for your books or your occupancy.</p>

<div class="branch"><div class="row"><span class="pill p-teal">Renewed</span><span>The term is extended — Atriom writes a fresh lease (carrying the active recurring charges forward, re-escalated) and marks this one <b>Renewed</b>. The unit stays <b>Occupied</b> under the new lease, so billing never gaps.</span></div><div class="row"><span class="pill p-grey">Expired</span><span>The term simply ran out and wasn't renewed. Billing stops; the unit returns to <b>Vacant</b>.</span></div><div class="row"><span class="pill p-red">Terminated</span><span>Ended <em>early</em> — a break clause, a default, a mutual exit. Billing stops and the unit returns to <b>Vacant</b>; this is where a deposit gets refunded or forfeited.</span></div></div>

<div class="branch" style="border-top:none;padding-top:0;"><div class="row"><span class="pill p-grey">Cancelled</span><span>Killed while still a <b>Draft</b> or <b>Pending</b> — it never went live, so it never billed and never touched a unit.</span></div></div>

## The unit follows the lease

<div class="rule"><span class="lbl">Coupling · lease ↔ unit</span>You never set a unit's status by hand — the lease drives it. <b>Active</b> lease → unit <b>Occupied</b>. <b>Expired</b> or <b>Terminated</b> → unit back to <b>Vacant</b> (ready to re-let). <b>Renewed</b> → unit stays <b>Occupied</b>, seamlessly, under the successor lease. See <a href="/leasing/unit-and-tenant">Units &amp; tenants →</a></div>

<div class="plain">A lease renewal is deliberately careful about what it carries forward: it clones the <b>active, recurring</b> charges (re-escalated for the new term) but <b>drops one-time and deactivated charges</b> — so a renewed lease never re-bills something the tenant already settled once.</div>

_Source of truth: `app/Services/LeaseRenewalService.php`, `app/Observers/LeaseObserver.php`, and `docs/modules/04-leases.md`._
