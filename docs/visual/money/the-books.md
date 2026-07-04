# What happens in the books

<p class="eyebrow">The ledger, drawn</p>

This is the part that's hardest to picture — so here it is as cards. Every money event in Atriom becomes a balanced **double-entry**: something goes up on one side, something goes up (or down) on the other, and the two sides always match. The account codes and names below are the real ones from your chart of accounts.

## Bill a tenant, then get paid

<p class="sub">Take one lease: base rent 10,000 (VAT-exempt) plus a service charge of 2,000 (which carries 14% VAT = 280). Total bill: 12,280.</p>

<div class="books"><div class="tcard"><div class="cap">Moment A — the bill is issued</div><p class="say">Revenue is recognised now, the moment the bill goes out — not when it's paid.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Money owed to you</span><br><small>11201001 · Tenant Receivables</small></td><td class="amt dr">Dr 12,280</td></tr><tr><td class="acc"><span class="crc">Rent income</span><br><small>41101001 · Base Rent Revenue</small></td><td class="amt crc">Cr 10,000</td></tr><tr><td class="acc"><span class="crc">Service income</span><br><small>41102001 · Service Charge Revenue</small></td><td class="amt crc">Cr&nbsp;&nbsp;2,000</td></tr><tr><td class="acc"><span class="crc">Tax you owe the gov't</span><br><small>21301001 · VAT Payable</small></td><td class="amt crc">Cr&nbsp;&nbsp;&nbsp;&nbsp;280</td></tr></table></div><div class="tcard"><div class="cap">Moment B — the tenant pays</div><p class="say">12,280 lands in the bank; what they owed you drops back to zero.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Cash in the bank</span><br><small>11102001 · Bank Account</small></td><td class="amt dr">Dr 12,280</td></tr><tr><td class="acc"><span class="crc">Money owed to you</span><br><small>11201001 · Tenant Receivables</small></td><td class="amt crc">Cr 12,280</td></tr><tr><td class="acc" style="color:var(--taupe);"><em>"owed to you" is now back to zero</em></td><td class="amt" style="color:var(--taupe);">✓</td></tr></table></div></div>

<div class="legend"><span><b class="dr">Dr</b> (debit) = a thing you <b>own</b> or are <b>owed</b> goes up</span><span><b class="crc">Cr</b> (credit) = income earned, or a tax/debt you now carry</span></div>

<div class="plain">If the tenant pays with <b>physical cash</b> instead, only Moment B changes — the debit lands in <code>11101001 · Main Cashier</code> rather than the bank. Everything else is identical.</div>

## When you credit a tenant

<p class="sub">Say you grant a 1,140 credit against that service charge (1,000 + its 140 VAT). A credit note runs the sale <em>backwards</em>.</p>

<div class="tcard"><div class="cap">A credit note is issued / applied</div><p class="say">You take back the income, return the VAT, and reduce what they owe.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Income given back</span><br><small>43101001 · Sales Returns &amp; Allowances</small></td><td class="amt dr">Dr 1,000</td></tr><tr><td class="acc"><span class="dr">VAT returned</span><br><small>21301001 · VAT Payable</small></td><td class="amt dr">Dr&nbsp;&nbsp;&nbsp;140</td></tr><tr><td class="acc"><span class="crc">Money owed to you</span><br><small>11201001 · Tenant Receivables</small></td><td class="amt crc">Cr 1,140</td></tr></table></div>

## The VAT rule you can see right here

<div class="rule"><span class="lbl">Rule · VAT is service-charge only</span>Look at Moment A: <b>base rent has no VAT</b> — it's exempt. Only the <b>service charge</b> carries 14% VAT (and the marketing levy is 5% of base rent). This is a real Egyptian-VAT rule baked into the invoice, and the picture makes it obvious: rent 10,000 → no tax line; service 2,000 → 280 tax.</div>

## Read it yourself in the app

Everything above happens automatically. To *see* it as the accountant does, log in as `accounting@mall.test` and open, under the **Accounting** group:

- **General Ledger** → the *Tenant Receivables* account — you'll find these exact debits and credits with a running balance.
- **Trial Balance** → proof the whole system balances: total debits = total credits, always.
- **Income Statement** → the rent and service income from Moment A, as profit.

<div class="plain">There are <b>~25</b> of these entries across Atriom — one for every kind of money event (payroll, depreciation, vendor bills, custody…). Each becomes one card exactly like these. This page draws the three that matter most; the rest follow when we extend the handbook.</div>

_Source of truth: `app/Services/Accounting/Journalizers/` and `docs/modules/21-general-ledger.md`._
