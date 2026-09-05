# The box you are testing

> **Everything below was read off the running box on 2026-09-05**, not copied from a seeder.
> If something here does not match what you see, that itself is worth mentioning — the box may
> have moved on.

---

## 🔴 Read this before you do anything

**This box is running a month-long unattended test — the "soak" — that started on 5 September 2026
and runs for a month.** A second mall, **Nile Gate (NG)**, was seeded with a calendar of things
that are *supposed* to happen on known days: a rent step on the 15th, a lease expiring at month
end, a cheque maturing on the 8th, a tenant two months in arrears. Every morning an automatic check
reads the books and reports whether the scheduler did the right thing.

Two consequences, and they are not negotiable:

### ❌ Never reset or reseed this box

There is a reset command in older instructions (`sudo atriom-qa-reset`, `migrate:fresh --seed`).
**Do not run it.** It would wipe a month-long test that cannot be restarted without losing the
elapsed time that is the whole point of it.

If the data gets into a state you cannot work with, **say so and ask** — do not fix it by resetting.

### ⚠️ Nile Gate (NG) is read-only for you

Look at it all you like — it is the only mall with real history, so it is where reports, ageing,
the ledger and volume look realistic. **But do not create, edit, pay, void or delete anything in
it.** Changing an NG record can make the next morning's check report a failure that is you, not the
system, and that costs a day to untangle.

**Val Plaza (VP) is yours.** Build everything there.

---

## Getting in

| | |
|---|---|
| **Admin panel** | `https://atriom.tri-tech.net/admin` |
| **Tenant portal** | `https://atriom.tri-tech.net/portal` |
| **Contractor portal** | `https://atriom.tri-tech.net/vendor` |
| **Password** | The same one for every login below — **ask whoever set the box up.** Deliberately not written down here. |

A **Cloudflare Turnstile** checkbox on the admin sign-in form is expected, not a bug.

---

## Admin logins — these six, and no others

**Only six staff logins exist on this box.** If you were told about `operations@`, `marketing@` or
`hr@mall.test` — those are from a different dataset and **do not exist here**. Trying them will
just fail to sign in.

| Login | Role | Use it for | Sees |
|---|---|---|---|
| `manager@mall.test` | manager | **Your main account.** The day-to-day operator. | Both malls |
| `accounting@mall.test` | accounting | Invoices, payments, the ledger, statements | Both malls |
| `leasing@mall.test` | leasing | Leases and tenants; no money screens | Both malls |
| `viewer@mall.test` | viewer | Read-only. Use it to check nothing is editable. | Both malls |
| `admin@mall.test` | super_admin | Setup only. **Don't test with it** — it can do things nobody real can. | Everything |
| `owner@atriom.test` | owner | The landlord's narrow view | What they own |

> **The missing roles are worth knowing about, not reporting.** Roles like `operations`, `hr`,
> `marketing` and `technician` exist in the system — there is simply no seeded login for them on
> this box. If you need to test one, ask for it to be created rather than reporting it missing.

---

## Tenant portal logins

The portal is the retailer's own view. One company can have several staff logins, and **only some
of them may act** — that difference is the thing worth testing.

| Login | Company | Mall | Can act? |
|---|---|---|---|
| `cilantro@plaza.test` | Cilantro | VP | ✅ **admin** — can pay, can raise requests |
| `test@example.com` | Cilantro | VP | ❌ **read-only** — must see the same data, change nothing |
| `zara@valplaza.test` | Zara Egypt | VP | ✅ admin |
| `carrefour@nilegate.test` | Carrefour Express | NG | ✅ admin — *read-only mall, look don't touch* |
| `tazaj@nilegate.test` | Al Tazaj | NG | ✅ admin — *read-only mall, look don't touch* |

**`cilantro@plaza.test` + `test@example.com` are the pair that matters.** They are the same company:
one may act, one may not. Sign in as each and confirm the read-only one sees identical data and can
change nothing.

**Cross-tenant check:** sign in as Zara and confirm you cannot see one byte of Cilantro's data — not
by list, not by URL. That is the portal's most serious bug class.

> **Since September 2026 a tenant has ONE login for both the portal and the mobile app.** The same
> email and password open the web portal and authenticate the phone app, and the read-only flag
> applies to both. If a read-only user can act through the app but not the web (or vice versa),
> that is a finding.

---

## Contractor (vendor) portal logins

Contractors get their own panel at `/vendor` where they accept jobs, post updates, upload evidence
and submit quotes.

| Login | Company |
|---|---|
| `ops@nileclean.eg` | Nile Clean (cleaning) |
| `ops@guardian-security.eg` | Guardian Security |
| `service@delta-elevators.eg` | Delta Elevators |

**The one rule:** a contractor may only ever see or touch a job **dispatched to them**. Try to
reach another company's job by URL — you must get a **404**, not a "forbidden" (a 403 would confirm
the job exists).

**A contractor cannot mark a job done.** Finishing is the operator's decision, not the contractor's
claim. That is deliberate.

---

## The malls on this box

Switch between them with the **property picker at the top** of the panel.

### 🟢 Val Plaza `VP` — **yours, build here**

An almost-empty mall. 12 units on two floors, 1,275 m² leasable.

| Unit | Floor | Type | Area | State |
|---|---|---|---|---|
| A-01, A-02 | Ground | retail | 60, 75 m² | reserved *(draft lease)* |
| A-03 | Ground | retail | 90 m² | reserved *(draft lease)* |
| A-04, A-05 | Ground | food & beverage | 120, 150 m² | occupied |
| **A-06** | Ground | kiosk | 15 m² | **vacant — free for you** |
| **A-07** | Ground | service | 45 m² | **vacant — free for you** |
| **A-12** | Ground | retail | 200 m² | **vacant — free for you** |
| **B-01, B-02** | 1st | retail | 100, 110 m² | **vacant — free for you** |
| **B-03** | 1st | office | 130 m² | **vacant — free for you** |
| **B-04** | 1st | wellness | 180 m² | **vacant — free for you** |

**Seven vacant units are yours to let.** Start with **A-12 (200 m²)** — the biggest, and the one the
worked examples use.

**Three tenants already exist** with no lease between them, so you have a leasing pipeline to work
with: **Zara Egypt**, **Cilantro**, **Nike Egypt**.

#### What is already in Val Plaza, and why it looks odd

VP is not pristine — a demo left some things behind. **This is residue, not soak data, so you may
change it freely.** Knowing what it is stops you reporting it as new:

| What | State | Note |
|---|---|---|
| 3 leases | 2 **draft** (Cilantro A-01/A-02, Nike A-03) + 1 **active** (Cilantro A-04/A-05) | The two drafts have **no start or end date**. |
| Their references | `LSE-AW-2026-0001…0003` | **`AW` is the wrong mall's initials** — a real bug that was found and fixed. Leases you create now should read `LSE-VP-…`. **If a lease you create still says `AW`, report it.** |
| `INV-VP-0001` | written off, 2,038.50 | Useful: a written-off invoice still shows a balance. That is correct. |
| `INV-VP-0002` | paid, 67,500.00 | |

#### ⚠️ Val Plaza has no bank account

The Bank Accounts register for VP is **empty**. Consequences you will hit:

- The **bank picker on money forms is empty**, so a payment on a rail that requires a bank account
  cannot be completed.
- Receipts fall to a generic account instead of a named bank.

**Fix it yourself before testing money in:** *Bank Accounts → New*, and use the option on the ledger
account picker that **creates a chart account for it**. Each bank account must post to a chart
account nothing else uses. Doing this is itself a good test.

### 🔴 Nile Gate `NG` — **read-only, this is the soak**

A mall mid-life: **20 units, 8 leases, 66 invoices, 2 bank accounts**, history back to September
2025. This is where you go to see what *correct* looks like at volume — reports, AR ageing, the
ledger, the trial balance, occupancy.

Some of what is deliberately in it, so you can recognise it:

| Tenant | Unit | Deliberately |
|---|---|---|
| Carrefour Express | B-01 | Anchor. Rent steps on 15 Sep. Pays by a series of cheques. Insurance lapses 22 Sep. |
| Al Tazaj | A-04 | **Two months in arrears** → late fees, dunning, ageing. Insurance already lapsed. Urgent HVAC request open. |
| Cairo Optics | A-01 | Lease **expires 30 Sep** → the shop should free itself on 1 Oct. |
| Nano Pharmacy | A-07 | Started mid-August → **prorated** first invoice. Deposit billed, unpaid. |
| Fit Zone Gym | B-04 | August **half-paid**. Renewal option window closes 25 Sep. |
| Koshary Abou Tarek | A-05 | Pays by cheque; one matured 8 Sep. |
| Orange Kiosk | A-06 | Started 1 Sep → first invoice, unpaid, overdue from 9 Sep. |
| Bershka | B-02 | **Draft** lease from 1 Oct. Must not bill until activated. |
| Hassan Mahmoud | B-03 | **Unit owner** — pays a monthly assessment, not rent. |
| Layla Farouk | A-02 | Unit owner who **stopped paying** in August. |

**Read it, do not write to it.** If you think you have found a bug in NG, describe it — do not try
to reproduce it by changing the record.

### ⬜ `007` "Plaza Mall" and `ALL` "All Properties"

`007` is one unit left over from somebody's earlier testing; `ALL` is a pseudo-entry, not a real
mall. **Neither is visible to the six staff logins** — only the super admin sees `007`, and `ALL`
is not something you should be able to select and work in.

**If either appears in your property picker while signed in as `manager@`, `accounting@`,
`leasing@` or `viewer@`, that is a finding.**

---

## Two live integrations — know this before you test them

### Email really sends

Through MailerSend. Two things follow:

- **Every message goes to `hello@tri-tech.net`**, whoever the app thinks it is writing to. The
  seeded data contains invented addresses on real domains, and letting those bounce would damage a
  domain used for real mail. **To check an email, look in that inbox** — not at the address on
  screen.
- **There is a cap of about 100 emails a day.** A long session exercising notifications can exhaust
  it. If mail stops arriving, check the cap before reporting a bug.

### Card payments are live against Paymob's TEST account

The portal's Pay button opens a **real Paymob checkout**. Use **Paymob's test card numbers** — never
a real card. Nothing on this box should ever be paid with real money.

---

## The state of the box, as configured today

| | |
|---|---|
| Environment | `staging` |
| Configuration health | **All green** — nothing blocking |
| Seller tax registration | `000-000-000` — a **deliberate placeholder** so invoices can title themselves *Tax Invoice*. Nine zeros cannot collide with a real Egyptian registration. Nothing here is a real tax document. |
| Billing contact on documents | `billing@valplaza.test` |
| **"Ask Atriom" assistant** | Present, with its **model layer switched off**. It answers from the system's own records and screen guides, and does not write prose. **An answer with no AI-sounding sentence around it is correct, not broken.** |

### Two defects already fixed but **not yet on this box**

The box is three commits behind. Do **not** report these:

1. **"Record violation" gives a 404.** Fixed already.
2. **Opening a form through a prefill link wipes the form's own defaults.** Fixed already.

If you meet either, note it and move on.

---

## Where to work — a summary

| Do this | Where |
|---|---|
| Create leases, invoices, payments, everything | **Val Plaza**, on the 7 vacant units |
| See what volume and history look like | **Nile Gate** — reading only |
| Prove property isolation | **Switch between the two** and confirm nothing crosses |
| Portal testing | `cilantro@plaza.test` and `test@example.com` |
| Contractor testing | `/vendor` as `ops@nileclean.eg` |
