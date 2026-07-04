# Payroll

<p class="eyebrow">A lifecycle + the books</p>

A payroll run gathers every employee's pay for the month, then posts a single ledger entry that recognises the wage cost, sets aside the taxes you withheld, and pays out the net. It's built line by line, then approved.

## A run's lifecycle

<div class="track"><span class="pill p-grey">Draft<small>lines being added</small></span><span class="conn">→</span><span class="pill p-green">Approved<small>posted to the books</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Cancelled</span><span>Scrapped before or after approval — terminal.</span></div></div>

<div class="plain">The run's header totals — gross, tax, insurance, net — aren't typed in; they're <b>summed from the per-employee lines</b>. Add or remove a line and the header re-derives itself. Only an <b>Approved</b> run posts to the ledger, and approval is refused if deductions somehow exceed gross (a negative net) — caught at the gate, not silently dropped.</div>

## What an approved run posts

<p class="sub">Ten thousand in gross wages, with 1,000 salary tax and 500 social insurance withheld — 8,500 paid out.</p>

<div class="tcard"><div class="cap">Payroll run — approved</div><p class="say">One entry: the full wage cost as an expense, the withholdings as debts to remit, and the net leaving the bank.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Wage cost (gross)</span><br><small>51101001 · Salaries &amp; Wages</small></td><td class="amt dr">Dr 10,000</td></tr><tr><td class="acc"><span class="crc">Tax withheld, owed to gov't</span><br><small>21302001 · Salary Tax Payable</small></td><td class="amt crc">Cr&nbsp;&nbsp;1,000</td></tr><tr><td class="acc"><span class="crc">Insurance withheld, owed</span><br><small>21601001 · Social Insurance Payable</small></td><td class="amt crc">Cr&nbsp;&nbsp;&nbsp;500</td></tr><tr><td class="acc"><span class="crc">Net paid to staff</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr&nbsp;&nbsp;8,500</td></tr></table></div>

<div class="rule"><span class="lbl">Rule · withheld ≠ paid</span>The money you withhold for salary tax and social insurance <b>isn't yours and isn't gone</b> — it's a <b>liability</b> you'll remit to the authorities later. That's why only the <b>net</b> (8,500) leaves the bank now, while the full <b>gross</b> (10,000) is the true cost of employing the team. <code>net = gross − tax − insurance</code>, enforced on every save.</div>

_Source of truth: `app/Models/Payroll.php`, `app/Services/Accounting/Journalizers/PayrollJournalizer.php`, and `docs/modules/24-hr-employees.md`._
