# Close &amp; reconcile

<p class="eyebrow">Making the numbers final</p>

At the end of each month you prove the books are right, lock them, and publish the statements. Once a year, you roll the profit into the owner's equity and start the income accounts fresh. This is the discipline that turns a pile of entries into figures you can sign.

## The month-end close

<p class="sub">Three steps: prove it, lock it, publish it.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Reconcile</span><span class="d">Prove the ledger matches reality — the tie-out.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Close</span><span class="d">Lock the month so its figures can't move.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">03</span><span class="t">Publish</span><span class="d">Hand over the statements + monthly-close pack.</span></div></div>

<div class="rule"><span class="lbl">The tie-out · the books must agree with reality</span>Before a month can close, a <b>read-only</b> audit re-derives the truth from source records and checks it against the ledger: it re-computes every invoice's total and balance, confirms payments aren't over-allocated, checks the marketing fund and CAM shares, and — the big one — ties the GL's <b>receivables and payables control accounts to the sum of real open invoices and bills</b>. If any of the seven checks disagrees, the books don't tie out, and you fix the cause before closing. It never changes anything — it only tells the truth.</div>

## The four statements

<p class="sub">All read-only, all re-derived live from the ledger — nothing is cached, so they can't drift.</p>

<div class="emap"><div class="enode"><span class="name">Trial Balance</span><span class="role">every account and its balance — total debits always equal total credits</span></div><div class="enode"><span class="name">Income Statement</span><span class="role">revenue − expenses = the period's profit</span></div><div class="enode"><span class="name">Balance Sheet</span><span class="role">what you own vs. owe vs. the owner's stake, at a moment</span></div><div class="enode"><span class="name">Cash Flow</span><span class="role">where cash actually moved (indirect method)</span></div></div>

## The year-end close

<p class="sub">Once a year, the profit is rolled into the owner's equity so the income accounts can start clean.</p>

<div class="tcard"><div class="cap">Closing entry — a profitable year</div><p class="say">Zero out revenue and expenses; the difference is the year's profit, moved to equity.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Revenue, zeroed</span><br><small>all 4···· revenue accounts</small></td><td class="amt dr">Dr 500,000</td></tr><tr><td class="acc"><span class="crc">Expenses, zeroed</span><br><small>all 5···· expense accounts</small></td><td class="amt crc">Cr 460,000</td></tr><tr><td class="acc"><span class="crc">Profit → owner's equity</span><br><small>32101001 · Retained Earnings</small></td><td class="amt crc">Cr&nbsp;&nbsp;40,000</td></tr></table></div>

<div class="rule"><span class="lbl">Rule · the year rolls into equity</span>A profitable year moves its result to <b>Retained Earnings</b> as a credit (equity up); a loss is a debit (equity down). This closing entry is flagged so it's <b>excluded from the income statement</b> (which must show the year's real activity) but included in the balance sheet (where P&amp;L accounts now read zero). It's idempotent and locked — the roll to equity can never post twice.</div>

## The owner's view

<div class="plain">There is no separate owner portal — <b>an owner is a user of the admin panel with an owner role</b>, and what they see is decided by the properties they currently own (the <code>asset_owner</code> tenure, so a sold-off property drops out of their view). It is a deliberately <b>narrow, read-only</b> window: their properties, invoices, requests and CAM shares. Owners see the <em>operational and billing</em> picture, <b>not the ledger, journals or periods</b> — that is the operator's workshop. What an owner is formally handed is the <b>owner statement</b>, built from these same ledger figures.</div>

_Source of truth: `app/Services/Reconciliation/BooksReconciliationService.php`, `app/Services/Accounting/{YearEndCloseService,LedgerReportService}.php`, `App\Support\PropertyIsolation`, and `docs/modules/17-reports.md`._
