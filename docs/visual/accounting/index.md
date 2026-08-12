# Accounting &amp; close

<p class="eyebrow">Where everything converges</p>

Every money event in this handbook — an invoice, a payment, a payroll run, a deposit, a stock movement, a month's depreciation — flows to **one place**: the general ledger. Accounting is the destination, and the discipline that keeps it trustworthy enough to hand to a bank, an auditor, or the owner.

## Every document becomes a ledger entry

<p class="sub">You never post to the ledger by hand. Each kind of document has a translator that knows its debits and credits.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">A document</span><span class="d">An invoice, payment, bill, payroll run, disposal…</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Its journalizer</span><span class="d">A small translator that knows that doc's Dr/Cr.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">The ledger</span><span class="d">A balanced journal entry is posted automatically.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">04</span><span class="t">The statements</span><span class="d">Trial balance, income statement, balance sheet.</span></div></div>

<div class="rule"><span class="lbl">The bridge · LedgerPoster + 24 journalizers</span>A nightly sweep (<code>accounting:sync-ledger</code>) runs each document through its journalizer and <b>self-heals</b>: if a document changes (a late fee bumps an invoice total), it voids the stale entry and re-posts the new one; if a document loses its effect (cancelled, refunded, deleted), it voids the entry. You have seen several of these translators across the handbook — the full list of all 24, generated from the registry itself, is on <a href="/modules/">Every module →</a></div>

## The chart of accounts — the five buckets

<p class="sub">Every account is one of five types, and its type fixes whether it naturally grows on the debit or credit side.</p>

<div class="emap"><div class="enode"><span class="name">Assets <small>1····</small></span><span class="role">what you own or are owed — cash, receivables, stock, equipment</span><span class="rels"><span class="rel">grows on Debit</span></span></div><div class="enode"><span class="name">Liabilities <small>2····</small></span><span class="role">what you owe — payables, VAT, deposits held, withheld tax</span><span class="rels"><span class="rel has">grows on Credit</span></span></div><div class="enode"><span class="name">Equity <small>3····</small></span><span class="role">the owner's stake — capital, retained earnings</span><span class="rels"><span class="rel has">grows on Credit</span></span></div><div class="enode"><span class="name">Revenue <small>4····</small></span><span class="role">income earned — rent, service, CAM, marketing levy</span><span class="rels"><span class="rel has">grows on Credit</span></span></div><div class="enode"><span class="name">Expenses <small>5····</small></span><span class="role">costs incurred — salaries, maintenance, depreciation</span><span class="rels"><span class="rel">grows on Debit</span></span></div></div>

<div class="plain">The leading digit <b>is</b> the type (1=asset, 2=liability, 3=equity, 4=revenue, 5=expense) — a hard Egyptian-accounting convention Atriom enforces, so a revenue account can't accidentally be coded as an expense. Only the deepest <b>leaf</b> accounts take entries; the parents just roll up totals. And <b>normal balance is derived from the type</b> — never set by hand.</div>

## Go deeper

- **[The ledger &amp; the rules →](/accounting/the-ledger)** — journal entries, periods, and the iron double-entry laws
- **[Fixed assets &amp; depreciation →](/accounting/fixed-assets)** — capitalise, depreciate, dispose
- **[Close &amp; reconcile →](/accounting/close-and-reconcile)** — tie-out, month-end, year-end, and the owner's view

_Full written rules: `docs/modules/21-general-ledger.md`, `23-fixed-assets.md`, `17-reports.md`._
