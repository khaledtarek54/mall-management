# CAM cost recovery

<p class="eyebrow">Shared costs, split fairly</p>

Some costs aren't any one tenant's — cleaning the common areas, security, lighting the walkways. **CAM** (Common Area Maintenance) is how the mall spreads those costs across every tenant by their share of space, and squares up once a year.

## How a year of shared cost gets recovered

<p class="sub">Tenants pre-pay an estimate each month; at year-end you reconcile against what was actually spent.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Pool</span><span class="d">A year's actual shared costs for one property, in one bucket.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Allocate</span><span class="d">Split per lease by its share of leased area (m²).</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Compare</span><span class="d">Each tenant's share vs. what they pre-paid.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">True-up</span><span class="d">Bill the shortfall, or credit the overpayment.</span></div></div>

## The pool's lifecycle

<div class="track"><span class="pill p-grey">Draft<small>costs being tallied</small></span><span class="conn">→</span><span class="pill p-amber">Reconciling<small>allocations drawn</small></span><span class="conn">→</span><span class="pill p-green">Reconciled<small>trued-up</small></span></div>

## The true-up goes one of two ways

<div class="branch"><div class="row"><span class="pill p-green">Under-paid</span><span>The tenant owes more → a <b>recovery invoice</b> lands on the <a href="/money/">money spine</a>, posting <b>CAM Recovery Revenue</b> (41103001), collected like any other bill.</span></div><div class="row"><span class="pill p-teal">Over-paid</span><span>The tenant paid too much → a <b>credit note</b> is raised and <b>auto-applied</b> to their oldest open bills first; any remainder stays as standing credit.</span></div></div>

<div class="rule"><span class="lbl">Invariant · the split can't over-recover</span>Two hard-won rules keep CAM honest. <b>1.</b> A negative true-up is always a <b>credit note</b>, never a negative charge (which the invoice engine would floor to zero and silently lose). <b>2.</b> When a pool is re-run, the <b>original set of participating leases and its m² denominator are frozen</b> — a lease that joins the property later can't inflate the head-count and dilute everyone's share. Re-running is safe and idempotent; a share that's already billed is never touched again.</div>

<div class="plain">The recovery invoice is deliberately dated to the <b>reconciled CAM year</b>, not the current month — so it slots past the monthly billing engine's duplicate-guard without colliding, and its tracing charge is kept inactive so the monthly run never bills it a second time.</div>

_Source of truth: `app/Services/CamReconciliationService.php` and `docs/modules/08-cam.md`._
