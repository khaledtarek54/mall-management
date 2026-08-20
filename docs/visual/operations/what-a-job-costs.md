# What a job costs

<p class="eyebrow">From "we fixed it" to "it cost 15,800, and here's why"</p>

Every figure in this mall was always in the books — parts left the store, contractors sent invoices, technicians drew wages. What was missing was the **connection between the money and the job that spent it**. You could say what the mall spent last month; you could not say what the chiller cost, whether a fault was ever really fixed, or whether the contractor charged what they quoted.

That is what this part of the system answers, and it rests on one idea: **the work order is the cost object.**

## The five things a job now knows

<p class="sub">Each answers a question an operator or an owner actually asks.</p>

<div class="emap"><div class="enode"><span class="name">What kind of work</span><span class="role">the trade — routes the job, decides which contractors are eligible, and is the axis every spend report groups by</span><span class="rels"><span class="rel">Trades register</span></span></div><div class="enode"><span class="name">What it cost</span><span class="role">labour + materials + services, planned against actual</span><span class="rels"><span class="rel">Cost object</span></span></div><div class="enode"><span class="name">What was allowed</span><span class="role">the not-to-exceed ceiling, and the quote that raised it</span><span class="rels"><span class="rel">NTE &amp; quotes</span></span></div><div class="enode"><span class="name">What went wrong</span><span class="role">problem → cause → remedy, and whether we've been here before</span><span class="rels"><span class="rel">Failure codes</span></span></div><div class="enode"><span class="name">Was it on time</span><span class="role">for planned work, whether it was done inside its window</span><span class="rels"><span class="rel">PM compliance</span></span></div></div>

## The money on a job, in three buckets

<p class="sub">One number is useless. Four are meaningless. Three answer different questions and behave differently.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Labour</span><span class="d">Hours booked by your own team × the trade's rate. The bucket that used to be invisible.</span></div><span class="arrow">+</span><div class="step"><span class="n">02</span><span class="t">Materials</span><span class="d">Spare parts drawn from the store, at what the store paid.</span></div><span class="arrow">+</span><div class="step"><span class="n">03</span><span class="t">Services</span><span class="d">Contractor invoices and direct costs, net of VAT.</span></div><span class="arrow">=</span><div class="step hl"><span class="n">04</span><span class="t">What it cost</span><span class="d">Rolled up to the machine, the shop and the trade.</span></div></div>

<div class="rule"><span class="lbl">Invariant · asking for time, never for money</span>Nobody is ever asked <i>"what did this cost?"</i> — they are asked <b>how long it took and who did it</b>, which is a question a technician can answer truthfully. The trade's rate turns that into money, and the rate is <b>frozen on the line when it is booked</b>, so a pay rise next year never re-prices work done last March. A trade with no rate produces hours and <b>no</b> cost: visibly missing beats quietly invented.</div>

<div class="rule"><span class="lbl">Invariant · this posts nothing to the ledger</span>The money is <b>already</b> in the books — the store movement posted it, the vendor bill posted it, payroll posted it. These figures are a <b>management view over money already posted</b>, not a second set of entries. Posting them again would double every maintenance cost in the business <b>and still balance</b>, which is exactly what makes that mistake dangerous. A build-time check refuses it.</div>

## Spending control happens *before* the work

<p class="sub">Seeing the number on the invoice is not negotiating. This is the half that happens while you can still say no.</p>

<div class="branch"><div class="row"><span class="pill p-green">Within the ceiling</span><span>The contractor proceeds and invoices. Every job starts with a <b>not-to-exceed</b> amount, taken from its trade.</span></div><div class="row"><span class="pill p-amber">Will exceed it</span><span>They submit a <b>quote</b> first — labour, materials, services. You approve it (which <b>raises the ceiling</b> and becomes the job's estimate) or you refuse it, <b>before the wall comes down</b>.</span></div><div class="row"><span class="pill p-teal">Went over anyway</span><span>The overrun is <b>shown</b> against a figure somebody actually agreed to — never blocked, because a job can genuinely grow for something nobody could have quoted for.</span></div></div>

<div class="plain">Because an approved quote <b>becomes</b> the job's estimate, the planned-versus-actual figure answers a question worth asking: <b>did the contractor deliver what they quoted?</b> A quote can also be <b>extra work on top</b> of one already agreed, which adds to the ceiling instead of replacing it.</div>

## Two signals that only appear over time

<div class="branch"><div class="row"><span class="pill p-red">Repeat visit</span><span>A second job on the <b>same machine, same trade, within 30 days</b>. The cheapest high-value signal in facilities: it finds the fault that was never actually fixed, and the contractor who keeps coming back to bill twice. It shows on the job list and on the vendor scorecard.</span></div><div class="row"><span class="pill p-grey">Failure codes</span><span><b>Problem → cause → remedy</b>, recorded when the job is marked done. Optional on purpose — a required code gets whatever clears the box fastest, which is worse than a blank because it looks like data. Worth nothing on day one and everything in two years.</span></div></div>

## Planned work: was it done when it should have been?

<div class="track"><span class="pill p-grey">Due<small>inside its window</small></span><span class="conn">→</span><span class="pill p-green">On time<small>done by the due day</small></span><span class="conn">or</span><span class="pill p-amber">Late<small>done, but after</small></span><span class="conn">or</span><span class="pill p-red">Overdue<small>nobody did it</small></span></div>

<div class="plain">Rated <b>per plan</b>, not as one number: <i>"87% compliant"</i> tells you nothing to act on, where <i>"the generator monthly test-run is 40%"</i> names the thing to fix. A round that visits many machines — 42 fire extinguishers, say — raises <b>one</b> job with <b>a line per device</b>, so a failure names the extinguisher rather than being a sentence in a checklist.</div>

<div class="rule"><span class="lbl">Measured strictly, and that is deliberate</span>There is no grace period. A single tolerance would be wrong in both directions — three days is most of a weekly cleaning round and nothing at all on an annual overhaul — and strictness never <b>overstates</b> compliance. The late rows are there for you to judge.</div>

_Source of truth: `app/Models/Concerns/FacilityWorkOrder/`, `docs/modules/26-facility.md`, and the benchmarks in `docs/benchmarks/fm/`._
