# A month in the life

<p class="eyebrow">The scenarios</p>

The [map](/map) shows what exists. This page shows it **running** — the real sequences the
mall goes through, in the order they happen, with the money and the books following along.

If you want to be sure you understand Atriom end to end, read these nine. They cover every
subsystem, and each one names the invariant it must not break.

## The rhythm

<p class="sub">Everything below hangs on this cadence. Nothing here is a person remembering to do something — except where it says so.</p>

<div class="track"><span class="pill p-grey">Day 1<small>billing run</small></span><span class="conn">→</span><span class="pill p-teal">Every day<small>scans, SLA, ledger sync</small></span><span class="conn">→</span><span class="pill p-amber">Month end<small>depreciation · reconcile · close</small></span><span class="conn">→</span><span class="pill p-green">Year end<small>CAM true-up · closing entry</small></span></div>

---

## 1 · A tenant moves in

<p class="sub">Empty unit → signed lease → the first bill. The start of every money story.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Unit is vacant</span><span class="d">It exists, it has a size in m², nobody is in it.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Lease signed</span><span class="d">Rent, term, deposit, escalation. The unit is now occupied.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Charges attach</span><span class="d">Base rent + service charge + the 5% marketing levy.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Deposit taken</span><span class="d">Cash in — but it is a liability, not income.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">First bill</span><span class="d">Prorated if they moved in mid-month.</span></div></div>

<div class="rule"><span class="lbl">Invariant · the deposit is not yours</span>A security deposit is <b>money you are holding</b>, not money you earned. It credits a <b>liability</b>, never revenue — and it only becomes income if it's forfeited. Getting this wrong overstates profit and understates what you owe back.</div>

**Deeper:** [Life of a lease →](/leasing/lease-lifecycle) · [Deposits in the books →](/leasing/deposits-in-the-books)

---

## 2 · The monthly billing run

<p class="sub">The single biggest money event. One command, every lease, once a month.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Every active lease</span><span class="d">Walked one by one; a failure on one must not stop the rest.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Charges → lines</span><span class="d">Each charge becomes an invoice line, prorated if needed.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">VAT applied</span><span class="d">14% on service charges. Base rent is exempt.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Invoice issued</span><span class="d">Emailed with its PDF; posted to the ledger.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">ETA submission</span><span class="d">The tax authority gets its copy.</span></div></div>

<div class="rule"><span class="lbl">Invariant · VAT 14%, rent exempt</span>Service charges carry <b>14% VAT</b>; <b>base rent does not</b>. The <b>marketing levy is 5% of base rent</b>. These three numbers are the most consequential constants in the system — they're registered in <code>docs/BUSINESS-RULES.md</code> for the accountant to sign off, not buried in code.</div>

<div class="plain"><b>Idempotency matters here.</b> Re-running the month must not double-bill. The run is safe to repeat — which is exactly why it can be automated at all.</div>

**Deeper:** [Life of an invoice →](/money/invoice-lifecycle)

---

## 3 · A tenant pays

<p class="sub">Cash arrives. The one calculation that must never be typed by hand.</p>

<div class="track"><span class="pill p-grey">Draft<small>not owed yet</small></span><span class="conn">→</span><span class="pill p-amber">Issued<small>owed</small></span><span class="conn">→</span><span class="pill p-teal">Partially paid<small>some in</small></span><span class="conn">→</span><span class="pill p-green">Paid<small>balance zero</small></span></div>

<div class="rule"><span class="lbl">Invariant · recomputeTotals()</span>A bill's <b>paid</b> and <b>balance</b> are never set directly. They are always re-derived:<br><br><code>paid_amount = captured payments + applied credit notes</code><br><code>balance = total − paid_amount</code><br><br>One payment can settle several bills; one bill can take several payments. That's why it must be <em>recomputed</em>, never stored by hand. When the balance hits zero the bill flips to Paid on its own. <b>This single formula, in one place, is why the numbers can be trusted.</b></div>

**Deeper:** [Life of an invoice →](/money/invoice-lifecycle) · [What happens in the books →](/money/the-books)

---

## 4 · A tenant disputes a charge

<p class="sub">The spine, running backwards. A credit note is not a negative invoice.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Dispute raised</span><span class="d">They were billed for something wrong.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Credit note issued</span><span class="d">A document in its own right, with its own approval.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Applied to the bill</span><span class="d">It settles the balance exactly as a payment would.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Books reversed</span><span class="d">Revenue comes back out. The audit trail keeps both.</span></div></div>

<div class="plain">Why not just edit the invoice? Because <b>an issued bill is a statement you made to a customer and to the tax authority.</b> You don't rewrite history; you issue a correcting document. Same reason the ledger voids rather than edits.</div>

**Deeper:** [Life of a credit note →](/money/credit-note-lifecycle)

---

## 5 · Something breaks

<p class="sub">The operational loop — and the one place the mall can charge a contractor for being late.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Fault appears</span><span class="d">A tenant reports it, or a PPM check fails.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Work order raised</span><span class="d">Internal team or external vendor — never both.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">SLA clock runs</span><span class="d">Per-property, per-priority. Starts on acceptance.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Parts drawn</span><span class="d">Stock leaves the warehouse — and posts as cost.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">Late? Penalty</span><span class="d">Deducted from the vendor's next bill.</span></div></div>

<div class="rule"><span class="lbl">Rule · a penalty reduces cost, it is not income</span>Money from a supplier is presumed to <b>adjust the price you paid them</b>, not to be new revenue. So an SLA penalty credits the <b>same expense</b> the bill debited — the penalty follows the cost. And no VAT: liquidated damages are compensation, not a supply.<br><br><b>⚠️ CAM does not follow automatically.</b> The year's actual CAM spend is typed in by an operator, not read from the ledger. Whoever records it must use the figure <b>net of penalties</b> — otherwise tenants over-pay CAM and the mall keeps the penalty.</div>

**Deeper:** [Life of a request →](/operations/request-lifecycle) · [Preventive & vendors →](/operations/preventive-and-vendors)

---

## 6 · A shop has a good year

<p class="sub">Percentage rent — the mall takes a cut above a threshold.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Sales declared</span><span class="d">The tenant reports what they sold.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Locked</span><span class="d">Declared figures freeze; the tenant is told.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Breakpoint tested</span><span class="d">Only sales above the threshold count.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Overage billed</span><span class="d">Its own invoice, immediately.</span></div></div>

<div class="plain">Sales are <b>manual twice over</b> today: the tenant uploads a report, and staff transcribe the figure. A POS integration is on the roadmap — that's a known gap, not a hidden one.</div>

---

## 7 · The shared costs get recovered

<p class="sub">CAM — the year's big reconciliation, and the trickiest rule in the system.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Pool collects</span><span class="d">Security, cleaning, common-area power — all year.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Estimates billed</span><span class="d">Tenants pay monthly against an estimate.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Year ends</span><span class="d">Actual spend is compared to what was collected.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">True-up</span><span class="d">Split pro-rata by leased m².</span></div></div>

<div class="rule"><span class="lbl">Invariant · the true-up goes both ways, differently</span><b>Under-collected</b> → an immediate recovery invoice.<br><b>Over-collected</b> → a <b>credit note</b>, auto-applied — <em>not</em> a negative charge.<br><br>A negative charge would corrupt the next billing run. This was hard-won over four review rounds; don't re-break it.</div>

**Deeper:** [CAM cost recovery →](/operations/cam-recovery)

---

## 8 · Staff get paid

<p class="sub">Money out, and the part that isn't about tenants at all.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Payroll run</span><span class="d">Gross, tax withheld, insurance withheld, net.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Advances netted</span><span class="d">A سلفة taken earlier is recovered here.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Payslips</span><span class="d">Bilingual PDF, per employee.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Books</span><span class="d">Dr salary expense / Cr the withholdings + Cr bank.</span></div></div>

<div class="plain">What's <b>withheld</b> isn't yours either — salary tax and social insurance are liabilities until you remit them, exactly like a deposit.</div>

**Deeper:** [Payroll →](/people/payroll) · [Advances & custody →](/people/advances-and-custody)

---

## 9 · The month gets closed

<p class="sub">The end of the story. Where every thread above has to tie out.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Ledger synced</span><span class="d">Every document has posted; the sweep self-heals any that didn't.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Reconcile</span><span class="d">The books are re-derived from source and compared.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Statements</span><span class="d">Trial balance, income, balance sheet, cash flow.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">Period locked</span><span class="d">Closed. Postings into it are refused.</span></div></div>

<div class="rule"><span class="lbl">The gate · billing:reconcile</span><code>php artisan billing:reconcile</code> independently <b>re-derives the books from source</b> — line items, captured allocations, applied credits — and confirms the stored totals agree. Read-only; exits non-zero on any discrepancy.<br><br><b>Run it before a close or a tax filing.</b> It's the difference between believing the numbers and knowing them. The <b>GL ↔ AR</b> and <b>GL ↔ AP</b> tie-outs are the two that matter most: if the ledger's receivables don't equal the sum of open bills, something above went wrong.</div>

<div class="plain"><b>Closing is a one-way door — on purpose.</b> Once a period is locked, a document dated inside it can no longer post. That's why the close gate checks for documents <em>dated</em> in the period that haven't posted yet: closing over one would strand it forever.</div>

**Deeper:** [Close & reconcile →](/accounting/close-and-reconcile)

---

## What this page doesn't cover

Being honest about the edges is part of the map:

- **Owner requests** (Jawad → Eltizam) and the **owner portal**, which is feature-flagged off.
- **Procurement** — purchase requests → approval → goods receipt. Being built.
- **Fault attribution** — charging a repair back to the tenant who caused it. Not built; the
  largest open commercial gap.
- **The revenue split** between Jawad and Eltizam — deferred pending a finance workshop.

The live status of each is in `docs/ROADMAP.md` — the single prioritized list.

_Source of truth for every rule above: `docs/modules/NN-*.md` and `docs/BUSINESS-RULES.md`._
