# Advances &amp; custody

<p class="eyebrow">A lifecycle + the books</p>

Two ways cash reaches a staff member's hands — and they're accounted for very differently. An **advance** is a loan they owe back. A **custody** is float they'll *spend on the company's behalf* and account for. Neither is an expense when it's handed over.

## An advance — a loan the staff repays

<p class="sub">There's no status field — the lifecycle is simply the balance still outstanding.</p>

<div class="track"><span class="pill p-amber">Outstanding<small>amount &gt; repaid</small></span><span class="conn">→</span><span class="pill p-green">Fully repaid<small>balance zero</small></span></div>

<div class="books"><div class="tcard"><div class="cap">Grant — 5,000 advanced</div><p class="say">It's a receivable — the staff owes it back, so it's an asset, not a cost.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Owed by the employee</span><br><small>11203001 · Employee Advances</small></td><td class="amt dr">Dr 5,000</td></tr><tr><td class="acc"><span class="crc">Cash out</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr 5,000</td></tr></table></div><div class="tcard"><div class="cap">Repayment — deducted from pay</div><p class="say">Cash comes back; the receivable clears.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Cash back in</span><br><small>11102001 · Bank Account</small></td><td class="amt dr">Dr 5,000</td></tr><tr><td class="acc"><span class="crc">No longer owed</span><br><small>11203001 · Employee Advances</small></td><td class="amt crc">Cr 5,000</td></tr></table></div></div>

## Custody — float to spend, then account for

<p class="sub">Also derived from its balance — money in the custodian's hands until it's spent or returned.</p>

<div class="track"><span class="pill p-amber">Outstanding<small>amount &gt; settled</small></span><span class="conn">→</span><span class="pill p-green">Fully settled<small>all accounted for</small></span></div>

<div class="books"><div class="tcard"><div class="cap">Grant — 3,000 custody</div><p class="say">Cash moves into a custody asset — held, not spent.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">In custodian's hands</span><br><small>11204001 · Custodies (Imprest)</small></td><td class="amt dr">Dr 3,000</td></tr><tr><td class="acc"><span class="crc">Cash out</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr 3,000</td></tr></table></div><div class="tcard"><div class="cap">Settle — spent, with a receipt</div><p class="say">2,500 spent on repairs → it becomes a real cost; 500 cash returned.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Maintenance cost</span><br><small>51102001 · Repairs &amp; Maintenance</small></td><td class="amt dr">Dr 2,500</td></tr><tr><td class="acc"><span class="dr">Cash returned</span><br><small>11102001 · Bank Account</small></td><td class="amt dr">Dr&nbsp;&nbsp;&nbsp;500</td></tr><tr><td class="acc"><span class="crc">Custody cleared</span><br><small>11204001 · Custodies (Imprest)</small></td><td class="amt crc">Cr 3,000</td></tr></table></div></div>

<div class="rule"><span class="lbl">Rule · a receivable vs. imprest cash — never AR</span>An <b>advance</b> lives in <b>11203001 · Employee Advances</b> (the staff owe it). A <b>custody</b> lives in <b>11204001 · Custodies</b> (your cash, in their hands). Both are deliberately kept out of tenant receivables so the AR tie-out stays clean. And neither is handed to a terminated employee — grants check the staff are active first.</div>

_Source of truth: `app/Services/{GrantEmployeeAdvance,RecordAdvanceRepayment,GrantCustody,SettleCustody}Service.php` and `docs/modules/25-treasury-custody.md`._
