# Deposits in the books

<p class="eyebrow">The ledger, drawn</p>

A security deposit is the one piece of money that arrives but **isn't yours to keep**. You *hold* it for the tenant — so on your books it's a **liability** (something you owe back), never income. It only becomes income if the tenant forfeits it. Here's how each event posts, with your real account codes.

## Take it in, then give it back

<p class="sub">A tenant pays a deposit of 30,000 (3× the monthly rent) at signing, and gets it back when they leave clean.</p>

<div class="books"><div class="tcard"><div class="cap">Receipt — deposit paid at signing</div><p class="say">Cash arrives, but it lands in a <em>liability</em> — you owe it back.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Cash in the bank</span><br><small>11102001 · Bank Account</small></td><td class="amt dr">Dr 30,000</td></tr><tr><td class="acc"><span class="crc">Deposit you owe back</span><br><small>21201001 · Tenant Deposits Held</small></td><td class="amt crc">Cr 30,000</td></tr></table></div><div class="tcard"><div class="cap">Refund — tenant leaves clean</div><p class="say">You hand it back; the liability clears to zero.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Deposit you owed back</span><br><small>21201001 · Tenant Deposits Held</small></td><td class="amt dr">Dr 30,000</td></tr><tr><td class="acc"><span class="crc">Cash out of the bank</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr 30,000</td></tr></table></div></div>

<div class="legend"><span><b class="dr">Dr</b> (debit) = an asset up, or a debt you owe going <b>down</b></span><span><b class="crc">Cr</b> (credit) = a debt you owe going <b>up</b>, or income</span></div>

## Or keep it — when a tenant defaults

<p class="sub">If the tenant breaches and forfeits the deposit, the money you were holding finally becomes yours.</p>

<div class="tcard"><div class="cap">Forfeit — the deposit is kept</div><p class="say">The liability is cleared not by paying it back, but by recognising it as income.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Deposit no longer owed</span><br><small>21201001 · Tenant Deposits Held</small></td><td class="amt dr">Dr 30,000</td></tr><tr><td class="acc"><span class="crc">Now it's income</span><br><small>42101001 · Miscellaneous Income</small></td><td class="amt crc">Cr 30,000</td></tr></table></div>

## Why this matters

<div class="rule"><span class="lbl">Rule · a held deposit is a liability, not profit</span>For the whole tenancy the deposit sits in <b>21201001 · Tenant Deposits Held</b>. It inflates your bank balance, but you <b>can't count it as profit</b> — you might have to give every pound back tomorrow. Only a <b>forfeit</b> moves it into income. Getting this wrong is a classic way to overstate profit; Atriom keeps it honest by parking every deposit in its own liability account.</div>

_Source of truth: `app/Services/Accounting/Journalizers/DepositTransactionJournalizer.php` and `docs/modules/04-leases.md`._
