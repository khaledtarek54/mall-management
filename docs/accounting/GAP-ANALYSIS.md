# Accounting module — gap analysis & stability assessment
### تحليل الفجوات وتقييم الاستقرار

> Honest assessment of where Atriom's accounting stands versus a full accounting
> system, what's solid, what's missing, and what to build next. Companion to
> [README.md](README.md) (status), [WALKTHROUGH.md](WALKTHROUGH.md) (the tour), and
> [../modules/21-general-ledger.md](../modules/21-general-ledger.md) (technical spec).
>
> **Last verified against code: 2026-07-16.** This doc had drifted — it listed fixed
> assets, depreciation and payslips as missing months after they shipped, which is how
> "accounting feels incomplete" became a belief. Re-verify the ❌/🟡 rows against the
> code before trusting them; each now cites the class that would prove it wrong.

---

## 1. Verdict up front

**The core is stable and production-grade.** The full bookkeeping loop —
document → balanced journal entry → trial balance → financial statements → period
close — is complete, self-healing, reconciled (tie-out gate), permission-controlled,
audit-logged, and covered by 1,450+ automated tests with a multi-agent review on every
change. Nothing in the core is "shaky."

**What remains are *additive capabilities*, not instability.** A property operator can
run its books today. The gaps below are features a larger/older accounting suite has
accumulated — some matter for this business, several are compliance/accountant-driven,
and a few are genuinely optional. This is a backlog, not a list of bugs.

---

## 2. Capability matrix (vs. a standard accounting system)

Legend: ✅ done · 🟡 partial · ❌ missing · ⏭️ likely N/A for a single-entity EGP mall operator

### Core ledger — ✅ complete
| Capability | Status | Notes |
|---|---|---|
| Chart of accounts (tree, bilingual) | ✅ | Egyptian-style; see §3 for the code-entry hardening |
| Double-entry journal + manual entries | ✅ | Balanced-or-rejected; post/void; immutable |
| Fiscal years & periods, period close | ✅ | Closed period refuses postings |
| Year-end close (قيد الإقفال) | ✅ | Profit → retained earnings; reversible; in-sequence |
| Trial balance (ميزان المراجعة) | ✅ | Debits = credits, proven |
| General ledger / account statement | ✅ | Running balance per account |
| Audit trail | ✅ | Spatie activity log on every accounting model |

### Financial statements — ✅ (all 3 core statements)
| Capability | Status | Notes |
|---|---|---|
| Income statement (قائمة الدخل) | ✅ | + bilingual PDF |
| Balance sheet (قائمة المركز المالي) | ✅ | + bilingual PDF |
| Cash-flow statement (قائمة التدفقات النقدية) | ✅ | Indirect method, reconcile-by-construction, + bilingual PDF |
| Comparative / period-over-period | ❌ | Single-period only today |

### Sub-ledgers & operations — ✅ strong
| Capability | Status | Notes |
|---|---|---|
| Accounts receivable + aging | ✅ | Invoices, AR-aging report, net-of-credits |
| Accounts payable | ✅ | Vendor bills + payments, AP tie-out |
| Revenue recognition (accrual) | ✅ | At issue; deferred/unearned handled |
| Cash & bank movements | ✅ | Via payments / expenses |
| Security deposits (liability) | ✅ | Receipt / refund / forfeit |
| Payroll | ✅ | Batch per-run **+ per-employee payroll lines & bilingual payslip PDFs** (`PayslipPdfService`, `resources/views/payslips/`) — module 24 |

### Tax & compliance — 🟡 (accountant-driven)
| Capability | Status | Notes |
|---|---|---|
| VAT tracked (output + input/recoverable) | ✅ | 14% service charge; input VAT recoverable |
| **ETA e-invoicing (منظومة الفاتورة)** | ✅ code / 🔑 creds | Built and covered — `app/Services/Eta/` (client, JSON builder, submission service, CAdES signer seam) + `SubmitInvoiceToEta` job, module 16. **Runs in mock mode** (`EtaSettings.mock=true`); needs live credentials + a signing certificate to submit legally-binding documents. See the roadmap's go-live block. |
| **VAT return (الإقرار الضريبي)** | ❌ | The *periodic filing report* is genuinely not built — distinct from ETA submission above (confirm cadence + format with the accountant). |
| **Withholding tax on vendor payments (خصم من المنبع)** | ❌ | Confirm if required |

### Controls & integrity — ✅ strong
| Capability | Status | Notes |
|---|---|---|
| Reconciliation harness + GL↔AR/AP tie-out gate | ✅ | `billing:reconcile` gates the close |
| Period locking, void-not-edit, RBAC | ✅ | |
| "Ledger last synced" + on-demand post | ✅ | Trust/freshness affordance |

### Advanced / enterprise
| Capability | Status | Notes |
|---|---|---|
| **Fixed-asset register + depreciation (الإهلاك)** | ✅ | **Shipped** — module 23: register + straight-line `DepreciationService`, `accounting:post-depreciation` scheduled monthly (`routes/console.php:48`), full GL posting incl. disposal write-off with gain/loss |
| **Bank reconciliation (مطابقة البنك)** | ❌ | No statement-import/matching — verified absent 2026-07-16 |
| **Opening balances tool (أرصدة افتتاحية)** | 🟡 | Manual journal works; no guided importer — matters at go-live |
| **Recurring / accrual journals** | 🟡 | Depreciation is automated on a monthly schedule (module 23); there is **no generic recurring-journal engine** for prepaid amortization and friends |
| Cost centers / dimensions | 🟡 | Property (`asset_id`) is a dimension; no free-form cost centers |
| Budget vs. actual (GL-wide) | 🟡 | Marketing budget only |
| Multi-currency / FX revaluation | ⏭️ | EGP throughout; unlikely needed |
| Inter-property due-to/due-from | ❌ | Cross-property single payments only exact in consolidated |
| Multi-entity consolidation | 🟡 | Consolidates across properties; not across legal entities |
| KPI dashboard | ❌ | Statements yes; no at-a-glance dashboard |

### Property-specific (Atriom's strength) — ✅
CAM reconciliation → GL, marketing levy, lease-based billing, percentage rent, dedicated
CAM-recovery revenue — all posting to the ledger. This is where Atriom **exceeds** a
generic accounting package.

---

## 3. The chart-of-accounts code concern (your question) — best practice

**Your worry is valid but the blast radius is limited.** In Atriom, journal lines
reference an account's **database id, not its code**, so an accountant editing or
mistyping a code **cannot corrupt existing postings** (unlike systems that key on the
code string). What a bad manual code *can* do is: mis-classify an account, break the
tree/rollups, or make reports group oddly.

**What Atriom enforces today:** code is numeric-only, required, unique, ≤20 chars;
`normal_balance` is derived from `type` automatically; only `is_postable` leaves accept
lines (enforced at the posting engine); delete is super-admin-only.

**What it does NOT enforce (the real gaps):**
1. **Parent is picked by hand, independent of the code** — he can code `41103001`
   (looks like it belongs under 411 Revenue) but parent it under Liabilities.
2. **Type isn't checked against the code's leading digit** — Egyptian convention is
   1=assets, 2=liabilities, 3=equity, 4=revenue, 5=expense; nothing stops an "expense"
   typed under code `1…`.
3. **Type isn't checked against the parent's type.**
4. **No "next code" suggestion**, so he types full codes by hand.

**Best practice in other systems (SAP / Oracle / Dynamics / QuickBooks / Xero):**
- **Suggest the next code under a chosen group** — the user picks "Revenue → Operating
  Revenue" and the system proposes the next free leaf code. (He rarely types a full code.)
- **Tie the code to the tree** — derive the parent from the code (longest existing
  prefix) *or* require the chosen parent's code to be a prefix of the child's.
- **Enforce nature consistency** — leading digit ↔ type, and child type = parent type.
- **Post only to leaf accounts** (Atriom already does at the engine).
- **Restrict who edits the chart**, and **guard editing a code once it has postings**
  (rename freely; re-code as a controlled action).
- **Deactivate, don't delete**, accounts with history.

**Chart guardrails — ✅ SHIPPED.** The two high-value guardrails are now in place:
1. **`parent_id` is auto-derived from the code** on save (deepest existing prefix,
   mirroring the seeder) — the tree can no longer drift from the code; the manual parent
   field was removed from the form.
2. **Leading-digit ↔ type is validated** (1 asset / 2 liability / 3 equity / 4 revenue /
   5 expense) — a mismatch throws in the model and shows an inline form error
   (`App\Rules\AccountCodeMatchesType`); custom ranges (6-9/0) stay unconstrained.

Net: the accountant keeps full control of his chart, but the system stops the mistakes
that would make the reports look wrong. *(Still optional, not built: a "suggest next code
under a group" helper, and a guard on re-coding an account that already has posted lines —
low value since lines FK to the account id, not the code.)*

---

## 4. Prioritized enhancement backlog

Ranked by value-for-effort for *this* business. "Needs accountant" = decide at the meeting.

| # | Enhancement | Why it matters | Effort | Needs accountant? |
|---|-------------|----------------|--------|-------------------|
| ~~1~~ | ~~**Chart-of-accounts guardrails** (§3)~~ | ✅ **Shipped** — auto-parent + leading-digit↔type guard | — | — |
| 2 | **Opening balances tool** | Load the current position at go-live | S–M | Yes (if migrating) |
| ~~3~~ | ~~**Fixed assets + depreciation**~~ | ✅ **Shipped** — module 23, monthly scheduled posting | — | — |
| ~~4~~ | ~~**Cash-flow statement**~~ | ✅ **Shipped** — indirect method, reconciles to actual cash | — | — |
| 5 | **VAT return** | Statutory periodic filing (ETA submission itself is built — see §2) | M | Yes |
| ~~6~~ | ~~**Per-employee payslips**~~ | ✅ **Shipped** — module 24, bilingual payslip PDFs | — | — |
| 7 | **Bank reconciliation** | Match bank statement to ledger | M–L | Yes (bank feed?) |
| 8 | **Inter-property accounts** | Exact per-property split of shared payments | M | No |
| 9 | **Comparative / period-over-period statements** | Every statement is single-period; owners expect vs-last-year | S–M | No |

**The real remaining set is four items:** opening balances, VAT return, bank reconciliation,
and comparative statements (+ inter-property accounts if shared payments get common). That's
the honest answer to *"is accounting missing something?"* — yes, four additive things, none
of which make today's books wrong.

---

## 5. Internal consistency (accounting vs. Atriom's other modules)

The accounting module is now the **most mature** in the codebase: double-entry
integrity, tie-out gating, immutability, and a per-phase multi-agent review discipline
the other modules don't all have. The **journalizer + sweep** pattern means other
modules (billing, CAM, payroll, expenses, deposits) feed the GL through one clean
contract without the GL knowing their internals — so integration is loose and stable,
and adding a new money source is a one-class change. No architectural debt is being
accumulated as the module grows *wide*.

**One cross-cutting note:** the accounting module's "review every phase + tie-out +
regression test" bar is worth back-porting to the money-adjacent modules (billing/CAM)
that feed it — they're already solid, but they don't all have a formal reconciliation
gate the way AR/AP now do.

---

*Keep this current: when a backlog item ships, move it to the matrix as ✅.*
