# Money &amp; Accounts Receivable

<p class="eyebrow">The spine</p>

Everything in Atriom that involves money hangs off one path. Learn this line and the rest of the app becomes legible — every other part either **feeds the invoice** or **spends money**, and it all ends up in the books.

## How money moves through the mall

<p class="sub">Read it left to right. Each box is a real thing you can open in the app.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Lease</span><span class="d">A tenant signs. This fixes the rent, service charge and term.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Charges</span><span class="d">Rent + service charge + marketing levy attach to the lease.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Invoice</span><span class="d">Once a month the charges become a bill, with VAT added where it applies.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Payment</span><span class="d">The tenant pays. The bill's balance falls toward zero.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">The books</span><span class="d">Every step above posts to the ledger automatically.</span></div></div>

A **credit note** is the one thing not on this line — it runs *backwards*, reducing what a tenant owes (a refund or goodwill adjustment). It settles a bill just like a payment does.

## The one rule that keeps money honest

<div class="rule"><span class="lbl">Invariant · recomputeTotals()</span>A bill's <b>paid</b> and <b>balance</b> are never typed in by hand. Atriom always recomputes them from the facts:<br><br><code>paid_amount = captured payments + applied credit notes + applied tenant credit + netted deposit</code><br><code>balance = total − paid_amount</code><br><br>When the balance reaches zero, the bill flips to <b>Paid</b> on its own. This single formula — in one place in the code — is why the numbers can be trusted. Nothing else is allowed to set a balance directly.<br><br><b>There are FOUR settlement channels, not two</b>, and every calculation that decides "how much of this bill is settled" must count all four. Only the first is cash: a credit note, on-account tenant credit and a netted security deposit each settle a bill without a pound arriving. Missing one is how a surplus gets buried as negative receivables.</div>

## The records, and how they connect

<p class="sub">The handful of things the money spine is made of, and who links to whom.</p>

<div class="emap"><div class="enode"><span class="name">Lease</span><span class="role">the contract with a tenant</span><span class="rels"><span class="rel">belongs to Tenant</span><span class="rel has">has many Charge</span><span class="rel has">has many Invoice</span></span></div><div class="enode"><span class="name">Charge</span><span class="role">a recurring line — rent, service charge, levy</span><span class="rels"><span class="rel">belongs to Lease</span></span></div><div class="enode"><span class="name">Invoice</span><span class="role">one monthly bill</span><span class="rels"><span class="rel">belongs to Lease</span><span class="rel">belongs to Tenant</span><span class="rel has">has many Invoice&nbsp;Item</span><span class="rel">paid by Payment(s)</span></span></div><div class="enode"><span class="name">Invoice Item</span><span class="role">one line on the bill (with its VAT)</span><span class="rels"><span class="rel">belongs to Invoice</span></span></div><div class="enode"><span class="name">Payment</span><span class="role">money received, split across bills</span><span class="rels"><span class="rel">belongs to Tenant</span><span class="rel">applied to Invoice(s)</span></span></div><div class="enode"><span class="name">Credit Note</span><span class="role">reduces what a tenant owes</span><span class="rels"><span class="rel">belongs to Tenant</span><span class="rel">applied to Invoice(s)</span></span></div></div>

<div class="plain">A <b>payment</b> and an <b>invoice</b> are many-to-many: one payment can settle several bills, and one bill can be settled by several payments. That's why the balance is <em>recomputed</em>, never stored by hand.</div>

## Go deeper

- **[Life of an invoice →](/money/invoice-lifecycle)** — every stage a bill moves through
- **[Life of a credit note →](/money/credit-note-lifecycle)** — how a refund/adjustment works
- **[What happens in the books →](/money/the-books)** — the actual ledger entries, drawn as cards

_Full written rules & edge cases: `docs/modules/05-billing-invoices.md`, `06-payments.md`, `07-credit-notes.md`._
