# ServiceChannel — the contractor-loop yardstick

> **What this file is for.** Maximo tells you what a job *is* and what it *costs*. ServiceChannel
> tells you how an operator who does not employ the tradespeople actually gets work done and keeps
> control of the money — which is Atriom's business exactly: Eltizam runs malls it does not own,
> executes through a contractor network, and the work is largely raised by retail tenants.
>
> The single idea to take from this file is in §3: **the money decision happens BEFORE the work,
> not after the invoice.**

---

## 1. The shape of the platform

Three parties, one object:

```
        Operator / landlord                    Contractor ("provider")
        raises, approves, pays                 accepts, quotes, executes, invoices
                       \                      /
                        \                    /
                          the WORK ORDER
                                 |
                            Retail tenant
                            raises, tracks, confirms
```

**Every party sees the same work order, and each sees a different face of it.** That is the
structural difference from a CMMS where the contractor is a column: here the provider has a login,
a queue, and obligations that the system measures. The operator's leverage is that the provider's
next dispatch depends on their performance on this one.

**The location is the spine of the data model**, not the asset. A retail/mall FM platform is
organised around *this store, this mall, this trade* — because that is how spend is budgeted,
compared and argued about. Asset-level tracking exists and is secondary. *(This is the mirror image
of Maximo, and the reason both files are here.)*

---

## 2. Trades, categories and the taxonomy that routes the work

Work is classified on two axes before anything else happens:

| Axis | Example | What it drives |
|---|---|---|
| **Trade** | HVAC · plumbing · electrical · doors · refrigeration · janitorial | Which providers are eligible; which SLA applies; how spend is reported |
| **Problem code / category** | "no cooling" · "leak" · "lights out" | The description a non-technical caller can actually pick, and the routing |

**A tenant picks a problem, not a trade.** The shop manager reporting "it is hot in here" cannot be
asked whether that is HVAC or electrical, and a system that asks gets the wrong answer half the
time. The problem code maps to a trade; the operator can override.

**The trade → provider assignment is per location.** The HVAC contractor at one mall is not the
HVAC contractor at another, and the assignment carries the terms: rates, SLA, NTE.
*(cited — provider assignment by location/trade is core to the platform's setup.)*

---

## 3. NTE and the proposal loop — **the money control**

This is the section that matters most, and the one Atriom has no equivalent of.

### 3.1 Not-to-exceed

Every work order carries an **NTE amount** — the maximum the provider may spend without coming
back for approval. It is set from the trade/location default and can be overridden per job.

- Work **at or under NTE**: the provider proceeds and invoices; the invoice is checked against the
  NTE automatically.
- Work **expected to exceed NTE**: the provider must submit a **proposal** *before* doing the work.

**The whole point is that the decision is made before the money is spent.** An operator who only
sees the number on the invoice is negotiating after the work is done, which is not negotiating.
Atriom's three-way match (`PurchaseRequest::billingVariance()`) is the *after* control and is
correct; NTE is the *before* control and is missing.

### 3.2 The proposal

A proposal is a structured quote: labour hours × rate, materials, subcontract, tax — not a number
in an email. The operator can approve, reject, or approve a revised figure; approval **raises the
NTE** on the work order and creates the commitment.

**Proposals are compared, not just accepted** where more than one provider is asked. This is the
retail-FM version of what the gap analysis calls O7 (bid comparison) and shows it is not only a
capex-procurement idea — it belongs on any job above a threshold.

### 3.3 The invoice

The invoice arrives against the work order and is checked against **the NTE, the proposal, and the
contracted rates** before it can be paid. A line at a rate not in the contract, or a total above an
approved proposal, is held rather than paid. *(cited — invoice validation against NTE and rate
sheets is a documented platform capability.)*

**The three checks are different and all are needed:**

| Check | Catches |
|---|---|
| Against NTE | Work that grew without permission |
| Against the proposal | An approved job billed for more than approved |
| Against the rate sheet | Correct hours at a rate nobody agreed to |

---

## 4. Dispatch and the provider's obligations

1. **Issue** — the work order is dispatched to a provider with a trade, a priority, an NTE and an
   SLA clock.
2. **Accept** — the provider acknowledges. Not accepting is itself a measured failure; the clock
   does not wait for goodwill.
3. **Check in on site** — via mobile/IVR, with location verification. This is what turns "we
   attended" into evidence.
4. **Work** — notes, photographs, parts used.
5. **Check out** — with a resolution and, where required, the tenant's confirmation.
6. **Invoice** — §3.3.

**Check-in/check-out is the mechanism that makes the SLA real.** Response time measured from a
column somebody edits is measured from when the contractor says they arrived. Measured from a
check-in with a location and a timestamp, it is measured from when they arrived. *(verify — the
verification method and its strictness are configuration-sensitive.)*

**Recall / repeat-visit tracking**: a second work order at the same location, same trade, same
problem within a window is flagged as a repeat. This is one of the highest-value cheap signals in
retail FM — it identifies the fault that was never actually fixed and the provider who keeps
returning to bill twice.

---

## 5. Compliance — the dispatch gate

Providers carry **compliance documents** — insurance certificates, licences, tax registration,
safety accreditation — each with an expiry. A provider out of compliance is **not dispatchable**.

*(Atriom already implements exactly this: `VendorDocument` + `Vendor::isDispatchable()`, and the
gap analysis correctly records it as at parity. It is noted here because it is the one place a
hard block is right — there is a genuine dispatch decision to gate — and because §9 of the Maximo
file and this section together define what "the contractor may not start" means.)*

---

## 6. The retailer's own view

The tenant is a first-class user, not a form:

- Raise a request with a problem code, a photograph and a location within their own store
- **See status without telephoning** — the single largest driver of call volume in mall operations
- Confirm completion, or reject it and reopen
- See their own history

**A tenant confirming completion is a control, not a courtesy.** It is what stops a job being
closed by the person who was paid to do it.

---

## 7. Spend analytics — what the model is for

Because every job carries a location, a trade, an NTE and an invoice, the platform answers:

| Question | Why an operator asks it |
|---|---|
| Spend by location, trade, provider, period | Budget, and the owner's questions |
| Spend per m² | The only fair comparison between a 90 m² unit and a 900 m² anchor |
| Invoices held, and why | Where money is stuck and who must decide |
| Repeat visits by provider and problem | The fault that was never fixed |
| Proposal turnaround and approval rate | Whether the operator's own approvals are the bottleneck |
| Provider scorecard — SLA %, first-time-fix, cost vs peers | The next dispatch decision |

*(Atriom has `VendorScorecardService` and a screen for it; what it lacks is the spend spine —
because spend per location/trade needs the cost object from the Maximo file plus the trade
taxonomy from §2 here.)*

---

## 8. What NOT to copy

Written explicitly, because a benchmark read uncritically is how a system grows features nobody in
this market wants:

- **The provider marketplace.** ServiceChannel monetises a network of contractors that operators
  can shop across. Eltizam has its own vendor list, and building a marketplace for one operator is
  building an empty room.
- **Invoice financing / payments rails.** A US-market product wrapped around ACH. Egypt's AP runs
  through the operator's bank and its own cheque practice, which Atriom already models (module 33).
- **Refrigerant tracking as a first-class module.** It exists because of US EPA reporting
  obligations. Egypt's equivalent obligations are not the same and should not be assumed.
- **IVR check-in.** The mechanism is a 2005 answer to a phone-only workforce. The property that
  matters is *verified arrival*; a mobile check-in with a timestamp is the same control.

---

## Sources

- ServiceChannel Support Centre and product documentation: Work Order lifecycle, Proposals, NTE,
  Invoice management, Compliance Manager, Provider Search & Scorecards, Analytics.
- ServiceChannel public API documentation (work order, proposal and invoice objects) for the
  object model in §3.
- Corrigo (JLL) and Facilio product documentation, consulted for corroboration on the dispatch and
  tenant-facing loop; cited in the gap analysis where either leads.
- Concepts marked ***(verify)*** are configuration-sensitive.
