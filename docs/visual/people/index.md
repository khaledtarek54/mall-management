# People &amp; money-out

<p class="eyebrow">Where cash leaves</p>

Leasing and the money spine bring cash *in*. This is where it goes *out* — to the **staff** who run the mall and the **suppliers** who service it. There are four channels, and Atriom keeps each one's running balance honest the same way it does for tenant debt: **derived from the records, never typed by hand**.

## Four ways money leaves

<p class="sub">Each channel hits its own accounts — none of them touch tenant receivables, which keeps the books clean.</p>

<div class="emap"><div class="enode"><span class="name">Payroll</span><span class="role">monthly staff wages — with tax &amp; insurance withheld</span><span class="rels"><span class="rel">Salaries expense</span><span class="rel has">Tax + insurance payable</span></span></div><div class="enode"><span class="name">Advances &amp; custody</span><span class="role">cash handed to staff — to repay, or to spend and account for</span><span class="rels"><span class="rel">Employee Advances</span><span class="rel">Custodies</span></span></div><div class="enode"><span class="name">Vendor bills</span><span class="role">suppliers billed on credit, paid later</span><span class="rels"><span class="rel">Expense + VAT</span><span class="rel has">Accounts Payable</span></span></div><div class="enode"><span class="name">Expenses &amp; marketing</span><span class="role">petty-cash and promotion, paid on the spot</span><span class="rels"><span class="rel">Expense</span><span class="rel">Cash / Bank</span></span></div></div>

<div class="rule"><span class="lbl">Invariant · every balance is derived</span>Just like a tenant's invoice balance, the money-out balances are <b>never set by hand</b> — they're summed from their children. An advance's <b>outstanding</b> = amount − Σ repayments. A custody's <b>outstanding</b> = amount − Σ settlements. A vendor bill's <b>balance</b> = total − Σ payments. The marketing fund's position = levy accrued − spend. And every one of those money-out actions <b>locks the parent and re-checks the balance inside the transaction</b>, so two payments racing can never over-pay.</div>

## The records, and how they connect

<div class="emap"><div class="enode"><span class="name">Employee</span><span class="role">operator staff on payroll (not an admin user)</span><span class="rels"><span class="rel has">has many Advance</span><span class="rel has">has many Custody</span></span></div><div class="enode"><span class="name">Payroll → Line</span><span class="role">a monthly run; one line per employee</span><span class="rels"><span class="rel has">Payroll has many Line</span><span class="rel">Line belongs to Employee</span></span></div><div class="enode"><span class="name">Employee Advance → Repayment</span><span class="role">a loan and the payments that clear it</span><span class="rels"><span class="rel has">has many Repayment</span></span></div><div class="enode"><span class="name">Custody → Transaction</span><span class="role">imprest cash and the settlements against it</span><span class="rels"><span class="rel has">has many Transaction</span></span></div><div class="enode"><span class="name">Vendor Bill → Payment</span><span class="role">a supplier bill and the payments on it</span><span class="rels"><span class="rel has">has many Payment</span></span></div><div class="enode"><span class="name">Marketing Budget → Spend</span><span class="role">the promotion fund and what's drawn from it</span><span class="rels"><span class="rel has">has many Spend</span></span></div></div>

## Go deeper

- **[Payroll →](/people/payroll)** — the run lifecycle and what it posts
- **[Advances &amp; custody →](/people/advances-and-custody)** — a loan vs. imprest cash
- **[Vendor bills &amp; expenses →](/people/vendor-bills-and-expenses)** — Accounts Payable, drawn

_Full written rules: `docs/modules/13-marketing.md`, `24-hr-employees.md`, `25-treasury-custody.md`._
