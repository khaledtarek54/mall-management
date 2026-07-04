---
layout: home
hero:
  name: Atriom
  text: The mall business, drawn.
  tagline: Pictures first, words second — a browsable reference for how money moves, how each record lives, and what lands in the books. Start with the money.
  actions:
    - theme: brand
      text: Start with the money spine
      link: /money/
    - theme: alt
      text: What this is
      link: '#what-this-is'
features:
  - title: Flows
    details: How a lease turns into rent, into a bill, into cash. The one path everything else hangs off.
  - title: Lifecycles
    details: Every stage a record can be in — an invoice, a credit note — as a coloured map, not buried in code.
  - title: The books
    details: What each money event debits and credits, as small T-account cards a non-accountant can read.
---

## What this is {#what-this-is}

Atriom has two kinds of guide, and they do different jobs.

- **The playbook** is the **tour** — you follow one correct sequence, hands-on, and run a whole month as each role. Do it once to get oriented.
- **This handbook** is the **reference** — a browsable book of pictures for *"wait, how does that actually work?"* It doesn't assume you'll read it top to bottom; you land on the part you need and see it drawn.

It layers on top of what you already have. Your detailed module docs in `docs/modules/*.md` stay the source of truth for every rule and edge case — this handbook draws the concepts over them so the whole team can see the shape before reading the detail.

Because these pictures live **inside the code repository**, they can't quietly go stale: when the logic changes, the picture changes in the same commit — the same rule your team already follows for the written docs. And it's built to be edited: [**Adding to this handbook**](/contributing) is a copy-paste guide to every component, so anyone on the team can extend it.

## The whole business, drawn {#the-plan}

The full system is mapped — five subsystems, following the money from the deal all the way to the owner's equity:

- **[Leasing](/leasing/)** — properties, units, tenants, leases, and the charges that seed every invoice
- **[Money &amp; AR](/money/)** — the money spine, invoice + credit-note lifecycles, the books
- **[Operations](/operations/)** — requests, CAM, meters, inventory, preventive upkeep, vendors
- **[People &amp; money-out](/people/)** — payroll, advances, custody, vendor bills, marketing
- **[Accounting &amp; close](/accounting/)** — the ledger, fixed assets, reconciliation, month/year-end, the owner's view

Each subsystem opens with a **flow** and an **entity map**, then draws its **lifecycles** and its **ledger entries** as pictures. To extend it, see **[Adding to this handbook](/contributing)**.

_Run `npm run docs:dev` to browse this locally._
