# Testing Atriom

<p class="eyebrow">For the tester</p>

You are testing the admin panel by **using it**, and you did not build it. This section gives you
the business context to judge whether what you see is *right* — not just whether it crashed.

Three pages:

- **This one** — how to work: getting in, what data is on the box, how to report a finding, and
  what not to waste time on.
- **[The money cycle →](/testing/cycle)** — one ordered pass from empty unit to balanced books,
  with the figure to expect at every step. **Start here once you are set up.**
- **[Every part of the system →](/testing/coverage)** — all 38 modules, what each is *for* in
  business terms, and what to check.

## Read this first — what NOT to test

About **3,700 automated tests** run on every change, plus a browser sweep over every admin screen
for every role. Repeating that work is the fastest way to spend a week and find nothing.

Do **not** systematically re-verify:

- That totals and balances add up — the arithmetic is checked continuously.
- That every screen loads for every role — swept automatically.
- That a permission blocks someone — the whole 14-role matrix is tested.

<div class="rule"><span class="lbl">What only a person catches</span>
Wording that is <b>correct but misleading</b> · a workflow that technically works and <b>makes no
sense in that order</b> · a screen that is right but you cannot tell what to do on it · something
that <b>looks</b> broken though the data is fine · anything that only appears in Arabic or on a
phone · <b>a number that is right, with nothing on the page explaining where it came from</b>.
</div>

## The business, in one minute

Three parties, and almost everything in Atriom is about the money between them.

| | Who | What they want |
|---|---|---|
| **Operator** | Eltizam | Runs the mall day to day. Bills tenants, collects, maintains the building, pays suppliers and staff. **Most logins you test are theirs.** |
| **Owner** | Jawad | Owns the property. Wants their money and a statement explaining it. |
| **Tenants** | The retailers | Rent shops. Pay rent, a service charge, their share of shared costs, sometimes a slice of sales. |

The core loop: **a tenant signs a lease → the lease produces monthly charges → charges become an
invoice → the tenant pays → everything posts to the accounts.** Every other part of the system
either *feeds that invoice* or *spends money*, and it all lands in the books.

Read **[The money spine →](/money/)** and **[A month in the life →](/scenarios)** before you start.
Twenty minutes there will save you a day of testing things you did not understand.

## Getting in

| | |
|---|---|
| Admin panel | `/admin` on this box |
| Tenant portal | `/portal` |
| Password | ask whoever set the box up — deliberately not written here |

A Cloudflare Turnstile checkbox on the sign-in form is expected.

| Login | Role | Use it for |
|---|---|---|
| `admin@mall.test` | super_admin | Setting things up. **Don't test with it** — it can do things nobody real can. |
| `manager@mall.test` | manager | **Your main account.** The day-to-day operator. |
| `leasing@mall.test` | leasing | Leases and tenants, no money screens |
| `accounting@mall.test` | accounting | Invoices, payments, the ledger |
| `viewer@mall.test` | viewer | Read-only |
| `owner@atriom.test` | owner | The landlord's view |
| `zara@atriomwalk.test` | *portal* | A retailer's own login |

## What is on the box

**A deliberately empty mall.** One property (*Atriom Walk*), **12 vacant units**, **3 tenants who
lease nothing**, and no leases, invoices, payments or accounting entries at all.

That is on purpose. With a mall mid-life, every number on every screen was put there by somebody
else and **you cannot tell what your own action changed**. Here, every figure you see is one you
caused.

Units: `A-01` (60 m²), `A-02` (75 m²), `A-03` (90 m²), `A-04` (120 m²), and eight more.

<div class="plain"><b>Every screen explains itself.</b> There is a <b>guide button on each screen</b>
giving its purpose, the steps, the rules — and most usefully <b>what else moves when you touch this
one</b>. Read it before testing a screen you do not know.</div>

## Four passes that find different bugs

Do these *as well as* the module coverage, not instead of it.

### 1. In Arabic
Switch the panel to Arabic and repeat screens you have already tested. This system's history is
full of defects visible **only** in Arabic: untranslated headings, English text on an otherwise
Arabic screen, text clipped by its own box, columns in the wrong order under right-to-left.

**Anything in English on an Arabic screen is a bug** — except a person's name, a company's name,
or a code.

### 2. As another role
Each role should see a **different, smaller** panel.

- `viewer@` can open things and **cannot** change them.
- **A button you are allowed to press and are then told you may not use is a bug**, even though
  nothing broke. You should not be offered actions you cannot take.

### 3. On a phone
Open the panel on a real phone. Tables, forms and the sidebar should be usable — not beautiful,
but usable.

### 4. Deliberately breaking things
Submit empty forms. Type letters into money fields. Put an end date before a start date. Enter
negative amounts. Paste a 500-character name. Click Save twice quickly.

*Expect* a clear message saying what is wrong. **Never** a white page, a raw error, or a silent
"nothing happened".

## Reporting what you find

A report that cannot be reproduced cannot be fixed. Always include these five:

```
WHAT I DID            Screen, then steps. "Leases → New → saved with unit A-01."
WHAT I EXPECTED       "An invoice of 15,420.00"
WHAT HAPPENED         "An invoice of 15,000.00 — no VAT on the service charge"
LOGGED IN AS          manager@mall.test
PROPERTY + LANGUAGE   Atriom Walk · Arabic
```

<div class="rule"><span class="lbl">The last line matters more than it looks</span>
A large share of the defects in this system are either <b>property-scoping</b> or <b>language</b>
bugs. A report missing those two fields usually cannot be reproduced by anyone.</div>

Add a **screenshot** for anything visual, and **exact figures** for anything about money. "The
total was wrong" cannot be investigated; "expected 15,420.00, got 15,000.00" can.

**How bad is it?**

| | |
|---|---|
| **Serious** | Money is wrong · books do not balance · someone sees another property's or another tenant's data · a screen crashes |
| **Normal** | A workflow does not work, or works and produces the wrong result |
| **Minor** | Wording, layout, confusing but correct |

## Starting over

You will make a mess — that is the job, and this box exists to be messed up. To wipe everything and
get a clean empty mall back (about 30 seconds, no undo):

```bash
sudo atriom-qa-reset
```

Needs SSH to the box; ask whoever set it up. Reset whenever the data stops making sense to you.

<div class="plain"><b>This box holds no real data.</b> Every tenant, lease and figure is invented.
Nothing you do here reaches a real retailer, a real bank, or the tax authority.</div>
