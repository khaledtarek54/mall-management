# Testing Atriom

<p class="eyebrow">For the tester</p>

You are testing the admin panel by **using it**, and you did not build it. This section gives you
the business context to judge whether what you see is *right* — not just whether it crashed.

Three pages:

- **This one** — how to work: getting in, what data is on the box, how to report a finding, and
  what not to waste time on.
- **[The money cycle →](/testing/cycle)** — one ordered pass from empty unit to balanced books,
  with the figure to expect at every step. **Start here once you are set up.**
- **[Every part of the system →](/testing/coverage)** — every module, what each is *for* in
  business terms, and what to check.

<div class="rule"><span class="lbl">Working with an AI assistant?</span>
There is a <b>self-contained copy of these three pages</b> — plus a system explanation and a
glossary, with no links out to this site — at <code>docs/qa/tester-pack/</code> in the repository.
Hand that folder to Claude and ask it about anything here. It is the same material written so a
model can answer <i>"is this a bug?"</i> from it.</div>

## 🔴 This box is running a month-long test — do not reset it

From 5 September 2026 the staging box is running an **unattended soak**: a second mall,
**Nile Gate (NG)**, seeded with things that are *supposed* to happen on known days, left to the
scheduler for a month while an automatic check reads the books every morning.

<div class="rule"><span class="lbl">Two rules, not negotiable</span>
<b>Never reset or reseed this box.</b> Older instructions mention <code>sudo atriom-qa-reset</code>
and <code>migrate:fresh --seed</code> — do not run either. It would destroy a month-long test that
cannot be restarted without losing the elapsed time that is the whole point of it.<br><br>
<b>Nile Gate (NG) is read-only for you.</b> Read it freely — it is the only mall with real history.
But do not create, edit, pay, void or delete anything in it: a change there makes the next morning's
check report a failure that is <i>you</i>, not the system. <b>Val Plaza (VP) is yours.</b></div>

If the data gets into a state you cannot work with, **say so and ask** — do not fix it by resetting.

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
phone · <b>a number that is right, with nothing on the page explaining where it came from</b> ·
<b>something that should have happened and did not</b> — a tenant never billed, a sweep that never
fired. Nothing errors; a figure is just missing. Those are the hardest and most valuable.
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
| Contractor portal | `/vendor` |
| Password | the same for every login below — ask whoever set the box up; deliberately not written here |

A Cloudflare Turnstile checkbox on the sign-in form is expected.

**Six staff logins exist, and no others.** If you were told about `operations@`, `marketing@` or
`hr@mall.test`, those belong to a different dataset and **do not exist here**.

| Login | Role | Use it for |
|---|---|---|
| `manager@mall.test` | manager | **Your main account.** The day-to-day operator. |
| `accounting@mall.test` | accounting | Invoices, payments, the ledger, statements |
| `leasing@mall.test` | leasing | Leases and tenants, no money screens |
| `viewer@mall.test` | viewer | Read-only — use it to check nothing is editable |
| `admin@mall.test` | super_admin | Setting things up. **Don't test with it** — it can do things nobody real can. |
| `owner@atriom.test` | owner | The landlord's view — scoped to what they own |

Roles like `operations`, `hr`, `marketing` and `technician` exist in the system; there is simply no
seeded login for them here. Ask for one rather than reporting it missing.

**Tenant portal** (`/portal`) — the first two are the pair that matters, because they are the same
company with different rights:

| Login | Who | |
|---|---|---|
| `cilantro@plaza.test` | Cilantro — **admin** | Can act: pay, raise requests |
| `test@example.com` | Cilantro — **read-only** | Must see the same data and be able to change **nothing** |
| `zara@valplaza.test` | Zara Egypt — admin | A second Val Plaza tenant |
| `carrefour@nilegate.test` | Carrefour Express | Nile Gate — *look, don't touch* |
| `tazaj@nilegate.test` | Al Tazaj | Nile Gate — *look, don't touch* |

<div class="plain"><b>One login now serves both the portal and the mobile app.</b> The same email and
password authenticate the web portal and the phone app, and the read-only flag applies to both. A
read-only user who can act through one and not the other is a finding.</div>

**Contractor portal** (`/vendor`) — `ops@nileclean.eg` · `ops@guardian-security.eg` ·
`service@delta-elevators.eg`. The one rule: a contractor sees **only** jobs dispatched to them, and
reaching another company's job by URL must give a **404**, never a 403 (a 403 confirms it exists).

## What is on the box

**Two malls, and the difference between them is the whole point.** Switch with the property picker
at the top of the panel.

| | | |
|---|---|---|
| **Val Plaza** `VP` | 12 units · 7 of them vacant · 3 leases · 2 invoices | **Yours.** Build your own cycle here. |
| **Nile Gate Mall** `NG` | 20 units · 8 leases · 66 invoices · a full ledger | **Read-only — this is the soak.** Come here to see what *correct* looks like at volume, and to test reports against real history. |

There is also a stray `007 "Plaza Mall"` (one unit, left over from earlier testing) and an
`ALL "All Properties"` pseudo-entry. **Neither is visible to the six staff logins** — only the
super admin sees `007` — so if one of them appears in your property picker, that is a finding.

**Val Plaza's seven vacant units are yours to let:** A-06 (15 m²), A-07 (45), **A-12 (200)**,
B-01 (100), B-02 (110), B-03 (130), B-04 (180). Start with A-12 — it is the one the worked examples
use. Three tenants exist with no lease between them: **Zara Egypt**, **Cilantro**, **Nike Egypt**.

<div class="rule"><span class="lbl">Val Plaza is not pristine — know the residue</span>
A demo left three leases behind: two <b>drafts with no dates</b> (Cilantro on A-01/A-02, Nike on
A-03) and one active (Cilantro on A-04/A-05). Their references read <code>LSE-AW-…</code> —
<b>the wrong mall's initials</b>, a real bug that was found and fixed. This is residue, not soak
data, so you may change it freely. <b>But if a lease you create now still says <code>AW</code>,
report it.</b> There is also <code>INV-VP-0001</code>, written off, which still shows a balance —
that is correct behaviour.</div>

<div class="rule"><span class="lbl">Val Plaza has no bank account — fix it first</span>
The bank register for VP is <b>empty</b>, so the bank picker on money forms has nothing to offer and
a payment on a rail that requires one cannot be completed. Create one before testing money in:
<i>Bank Accounts → New</i>, using the option on the ledger-account picker that <b>creates a chart
account</b> for it. Each bank account must post to a chart account nothing else uses — doing this
is itself a good test.</div>

<div class="rule"><span class="lbl">Two malls is not a convenience</span>
It is the only way <b>property isolation</b> can be tested at all — and that is the most serious bug
class in this system. An operator working in Val Plaza must never see a single row belonging to
Nile Gate: not in a list, not in a report, not in a dropdown, not by editing the URL. Check it
everywhere you go, not once.</div>

<div class="plain"><b>Every screen explains itself.</b> There is a <b>guide button on each screen</b>
giving its purpose, the steps, the rules — and most usefully <b>what else moves when you touch this
one</b>. Read it before testing a screen you do not know.</div>

## How this box is configured

| | |
|---|---|
| Configuration health | **all green** — nothing blocking |
| Seller tax registration | `000-000-000`, a deliberate placeholder so invoices can title themselves *Tax Invoice*. Nine zeros cannot collide with a real Egyptian registration; nothing here is a real tax document. |
| **"Ask Atriom"** | present, with its **model layer switched off**. It answers from the system's own records and screen guides rather than writing prose. **An answer with no AI-sounding sentence around it is correct, not broken.** |

**Two defects already fixed but not yet deployed here.** Do not report them: *Record violation*
gives a 404, and opening a form through a prefill link wipes the form's own defaults.

## Two live integrations — know this before you test them

**Email really sends now**, through MailerSend. Two things follow:

- **Every message goes to `hello@tri-tech.net`**, whoever the app thinks it is writing to. That
  redirect is deliberate — the seeded data contains invented addresses on real domains, and letting
  those bounce would damage the sending reputation of a domain used for real mail. So to check an
  email, look in **that** inbox, not at the address on screen.
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

## Things that are deliberately true and look like bugs

Check this before reporting. Each has surprised somebody already.

| What you see | Why it is correct |
|---|---|
| Base rent shows **no VAT** | Base rent is exempt in the shipped catalogue; the service charge carries 14%. |
| A **paid** invoice's status field is greyed out | Committed money documents lock. Correct via void or credit note. |
| No **Delete** button on an invoice, payment or journal entry | Money records are never deletable, by anyone. |
| A **draft** invoice is invisible in the portal | A draft is not a document. |
| A **written-off** invoice still shows a balance | Balance says what was *owed*; the write-off is recorded separately. |
| A **credit note** did not change the original invoice's figures | Correct — it settles it as its own document. |
| A job finished at **16:00 on its due date** counts as on time | Compliance is measured in whole days. |
| The **tenant code** was allocated for you and cannot be typed | Codes are allocated centrally so two people cannot collide. |
| An **expired** lease's shop shows vacant the next morning | A nightly sweep frees it. |

## Reporting what you find

A report that cannot be reproduced cannot be fixed. Always include these five:

```
WHAT I DID            Screen, then steps. "Leases → New → saved with unit A-12."
WHAT I EXPECTED       "An invoice of 15,420.00"
WHAT HAPPENED         "An invoice of 15,000.00 — no VAT on the service charge"
LOGGED IN AS          manager@mall.test
PROPERTY + LANGUAGE   Val Plaza · Arabic
```

<div class="rule"><span class="lbl">The last line matters more than it looks</span>
A large share of the defects in this system are either <b>property-scoping</b> or <b>language</b>
bugs. A report missing those two fields usually cannot be reproduced by anyone.</div>

Add a **screenshot** for anything visual, and **exact figures** for anything about money. "The
total was wrong" cannot be investigated; "expected 15,420.00, got 15,000.00" can.

**How bad is it?**

| | |
|---|---|
| **Serious** | Money is wrong · books do not balance · someone sees another property's or another tenant's data · a screen crashes. **Anything that sends money outward** — a refund, a credit, a disbursement — is serious even if small: money leaving wrongly has no recovery path. |
| **Normal** | A workflow does not work, or works and produces the wrong result |
| **Minor** | Wording, layout, confusing but correct |

<div class="plain"><b>This box holds no real data.</b> Every tenant, lease and figure is invented.
Nothing you do here reaches a real retailer, a real bank, or the tax authority.</div>
