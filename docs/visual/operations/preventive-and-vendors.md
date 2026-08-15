# Preventive maintenance &amp; vendors

<p class="eyebrow">Two supporting lifecycles</p>

The best request is the one that never happens. **Preventive maintenance** services kit on a schedule so it doesn't break; **vendors** are the outside suppliers who often do the work. Both run on their own state machines.

## From a plan to a done job

<p class="sub">A recurring plan raises a work order when it comes due — automatically, every cycle.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Plan</span><span class="d">"Service the HVAC filters, monthly" — with a checklist.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Due</span><span class="d">The nightly scan sees next-due has arrived.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Work order</span><span class="d">One job is raised (state: open), checklist copied in.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Done</span><span class="d">Team completes the checklist; the plan rolls to next cycle.</span></div></div>

## A work order's lifecycle

<div class="track"><span class="pill p-amber">Open<small>raised</small></span><span class="conn">→</span><span class="pill p-teal">In progress<small>being done</small></span><span class="conn">→</span><span class="pill p-green">Done<small>complete</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Cancelled</span><span>Called off before completion. Like <b>Done</b>, it's terminal and immutable — you can't reopen a finished or cancelled job.</span></div></div>

<div class="plain">The generator is careful: it <b>raises one work order per due plan, then advances the plan's next-due date by one cycle</b> — and a backstop stops a second order for a cycle that already has one. A plan that's been dormant catches up one cycle per run, never floods you with a year of missed jobs at once.</div>

## A vendor contract's lifecycle

<p class="sub">Suppliers work under dated contracts; Atriom retires them the day they lapse.</p>

<div class="track"><span class="pill p-grey">Draft<small>being set up</small></span><span class="conn">→</span><span class="pill p-green">Active<small>in force</small></span><span class="conn">→</span><span class="pill p-grey">Expired<small>end date passed</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Terminated</span><span>Ended early, by choice.</span></div></div>

<div class="rule"><span class="lbl">Rule · contracts expire themselves</span>The <code>vendors:expire-contracts</code> scan flips an <b>Active</b> contract to <b>Expired</b> the moment its end date passes — locking the row and re-checking the date first, and using a real update so the change lands in the activity log. No one has to remember to do it.</div>

_Source of truth: `app/Services/GeneratePreventiveWorkOrdersService.php`, `app/Models/ServicePlan.php`, `app/Models/VendorContract.php`, and `docs/modules/12-vendors.md`, `26-facility.md`._
