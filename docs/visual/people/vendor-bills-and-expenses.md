# Vendor bills &amp; expenses

<p class="eyebrow">A lifecycle + the books</p>

When a supplier does work, you owe them — that's **Accounts Payable**, the mirror image of tenant receivables. A vendor bill is approved, then paid over time. Small, immediate costs skip the credit step and are booked as **expenses** straight away.

## A vendor bill's lifecycle

<div class="track"><span class="pill p-grey">Draft<small>entered</small></span><span class="conn">→</span><span class="pill p-teal">Approved<small>a payable</small></span><span class="conn">→</span><span class="pill p-teal">Part-paid<small>some settled</small></span><span class="conn">→</span><span class="pill p-green">Paid<small>cleared</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Cancelled</span><span>Voided — but <b>refused</b> if any payment was made against it (reverse the payments first).</span></div></div>

## What a bill posts

<p class="sub">A cleaning contractor bills 11,400 (10,000 for the work + 1,400 VAT), paid later from the bank.</p>

<div class="books"><div class="tcard"><div class="cap">Approved — the bill is recognised</div><p class="say">The cost is booked now; you owe the supplier the whole total.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">The cost</span><br><small>51104001 · Cleaning &amp; Security</small></td><td class="amt dr">Dr 10,000</td></tr><tr><td class="acc"><span class="dr">VAT you can reclaim</span><br><small>11401001 · VAT Recoverable</small></td><td class="amt dr">Dr&nbsp;&nbsp;1,400</td></tr><tr><td class="acc"><span class="crc">Owed to the supplier</span><br><small>21101001 · Vendor Payables</small></td><td class="amt crc">Cr 11,400</td></tr></table></div><div class="tcard"><div class="cap">Paid — the bill is settled</div><p class="say">Cash leaves; what you owed clears.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">No longer owed</span><br><small>21101001 · Vendor Payables</small></td><td class="amt dr">Dr 11,400</td></tr><tr><td class="acc"><span class="crc">Cash out</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr 11,400</td></tr></table></div></div>

<div class="rule"><span class="lbl">Rule · input VAT is money back, and the balance is derived</span>The <b>VAT Recoverable</b> you pay a supplier is an <b>asset</b> — you reclaim it from the tax authority against the VAT you <em>charge</em> tenants. And just like a tenant invoice, a bill's <b>paid</b> and <b>balance</b> are summed from its payments, never set by hand — so AP always ties out to real, unpaid bills.</div>

## Paid on the spot — expenses &amp; marketing

<div class="plain">Not everything runs through a payable. A <b>direct expense</b> (petty cash, a one-off) is booked and paid at once: <b>Dr</b> the cost + <b>Dr</b> VAT Recoverable / <b>Cr</b> Cash or Bank — no approval stage, because the money's already gone. <b>Marketing spend</b> is the same shape (<b>Dr</b> 51105001 Marketing / <b>Cr</b> Cash), drawing down the property's promotion fund — the other side of which is the <b>5% marketing levy</b> you bill tenants. The fund's health is simply <em>levy collected − spend</em>.</div>

_Source of truth: `app/Services/Accounting/Journalizers/{VendorBill,VendorBillPayment,Expense,MarketingSpend}Journalizer.php` and `docs/modules/13-marketing.md`._
