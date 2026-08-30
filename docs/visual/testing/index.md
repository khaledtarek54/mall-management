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
| `operations@mall.test` | operations | Maintenance, work orders, vendors |
| `marketing@mall.test` | marketing | Campaigns, budget, the shopper feed |
| `hr@mall.test` | hr | Employees and payroll |
| `viewer@mall.test` | viewer | Read-only |
| `owner@atriom.test` | owner | The landlord's view — scoped to what they own |

**Tenant portal** (`/portal`) — use these two together, they are the pair that matters:

| Login | Who | |
|---|---|---|
| `tenant1@atriomwalk.test` | Cilantro — **admin** | Can act: pay, raise requests |
| `staff1@atriomwalk.test` | Cilantro — **read-only** | Must see the same data and be able to change **nothing** |

Also `tenant2@` (Magrabi Optical) and `tenant3@` (Buffalo Burger) — useful for checking one tenant
never sees another's data.

## What is on the box

**Two malls, and the difference between them is the whole point.** Switch between them with the
property picker at the top of the panel.

| | | |
|---|---|---|
| **Atriom Walk** `AW` | 50 units · 33 leases · 303 invoices · a full ledger | **The reference.** A mall mid-life. Come here to see what *correct* looks like, and to test reports and accounting against real volume. |
| **Plaza Annex** `PA` | 8 units, **all vacant**, no leases | **Yours.** Build your own cycle here. |

Work in **Plaza Annex** for anything you create. With a mall mid-life, every number on every screen
was put there by somebody else and **you cannot tell what your own action changed**. In Plaza Annex
every figure is one you caused — and Atriom Walk is next door when you need to see how a thing
looks once it has history.

Plaza Annex units are `PA-01` … `PA-08` (85 m² up to 120 m²).

<div class="rule"><span class="lbl">Two malls is not a convenience</span>
It is the only way <b>property isolation</b> can be tested at all — and that is the most serious bug
class in this system. An operator working in Plaza Annex must never see a single row belonging to
Atriom Walk: not in a list, not in a report, not in a dropdown, not by editing the URL. Check it
everywhere you go, not once.</div>

<div class="plain"><b>Every screen explains itself.</b> There is a <b>guide button on each screen</b>
giving its purpose, the steps, the rules — and most usefully <b>what else moves when you touch this
one</b>. Read it before testing a screen you do not know.</div>

## Two live integrations — know this before you test them

**Email really sends now**, through MailerSend. Two things follow:

- **Every message goes to `hello@tri-tech.net`**, whoever the app thinks it is writing to. That
  redirect is deliberate — the seeded data contains invented vendor addresses on `.eg`, a real
  Egyptian domain, and letting those bounce would damage the sending reputation of a domain that is
  used for real mail. So to check an email, look in **that** inbox, not at the address on screen.
- **There is a cap of 100 emails a day.** A long session exercising notifications can exhaust it. If
  mail stops arriving, check the cap before reporting a bug.

**Card payments are live against Paymob's TEST account.** The portal's Pay button works and will
open a real Paymob checkout. Use **Paymob's test card numbers** — never a real card. Nothing on this
box should ever be paid with real money.

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
WHAT I DID            Screen, then steps. "Leases → New → saved with unit PA-01."
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

It is a wrapper around three commands, and the third one matters:

```bash
php artisan migrate:fresh --seed --force
php artisan db:seed --class='Database\Seeders\PlaceholderIssuerIdentitySeeder' --force
```

`--seed` runs the whole chain — reference data (roles, chart of accounts, charge codes,
catalogues) **and** both malls. `migrate:fresh` also wipes the **settings**, so without that second
line the box comes back with **no tax registration**, every invoice stops calling itself a *Tax
Invoice*, and the [money cycle](/testing/cycle)'s step 6 would be wrong. The placeholder is a
separate seeder on purpose: it turns a *blocking* configuration check green, so it must never be
something you get by accident from asking for demo data, and it **refuses to run on production**.

<div class="plain"><b>This box holds no real data.</b> Every tenant, lease and figure is invented.
Nothing you do here reaches a real retailer, a real bank, or the tax authority.</div>
