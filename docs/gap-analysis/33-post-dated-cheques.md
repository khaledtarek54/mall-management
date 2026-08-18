# Module 33 — Post-dated Cheques · gap analysis

> **Round 3, 2026-08-18.** First audit — the module shipped after round 2 closed and was on the
> never-gap-analysed list in [PROJECT-MAP](../PROJECT-MAP.md).
> Method: [000-plan.md §Round-2 methodology](000-plan.md).

## 1. A benchmark caveat, stated rather than papered over

**There is no repo-grounded benchmark for this module, and I did not invent one.**
[competitors/03](competitors/03-deposits-utilities-portal-owner.md) ranks a PDC register as its #2
gap and records that Yardi handles PDCs only in region-specific builds, marked *(verify)* — it
describes the absence, not the behaviour. A post-dated cheque is a MENA instrument the Western
specialists do not model, so the comparison the other round-3 audits lean on does not exist here.

This audit is therefore against **the code and the project's own invariants** — settlement channels,
posting-date guards, property isolation, concurrency, deletion policy, and the reachability rule
added today. Where Egyptian banking practice informs a judgement it is labelled inline. Nothing here
claims "Yardi does X".

## 2. Verdict

**Well built, with one defect that made a whole feature unreachable.** The lifecycle guards are
sound, the AR treatment is *better than the benchmark's own description of Voyager* (see §4), and
`clear()` locks both the cheque and the invoice. The register is honest about what it holds.

## 3. Findings

### 🔴 F-A — the bounced-cheque fee could not be billed for any cheque the UI can create *(fixed)*

`BillBouncedChequeFeeService` refused when `$cheque->lease` was null, on the premise stated in its
own comment: *"a cheque always carries its own lease, so the property is never ambiguous here."*

**It does not.** Neither the create form nor the bulk-lodge action has a lease field
([PostDatedChequeForm](../../app/Filament/Admin/Resources/PostDatedCheques/Schemas/PostDatedChequeForm.php)
collects asset · tenant · invoice · cheque number · bank · amount), and nothing derives `lease_id`:
the column is fillable and written only by `lodgeSeries()` from an optional array key no caller
passes. **Every cheque an operator can produce carries `lease_id = null`, so the fee threw
`nsf_fee_failed_no_lease` every time.** The feature was built, translated, GL-mapped and unusable.

**Why the tests were green.** [`BouncedChequeFeeTest:43`](../../tests/Feature/Regression/BouncedChequeFeeTest.php)
sets `'lease_id' => $lease->id` directly in its fixture — a column no form, service or seeder
writes. That is the **F-100 shape** [000-plan.md](000-plan.md) records verbatim: *ask of every
fixture, could the product actually produce this state?* Here it could not, and nine assertions sat
on top of it. Worth noting the gate added today would NOT have caught this: the service is reachable
(an action calls it), it just refused on arrival. Reachability and *workability* are different
properties.

A second, quieter consequence in the same method: the fee amount was read as
`PropertySettings::get('billing.nsf_fee_amount', $locked->lease?->assetId())`, which with a null
lease resolved to null and priced **every** fee from the portfolio default instead of the property's
own — a per-property setting that no property could reach.

**FIXED** — the agreement is now resolved at billing time, most specific first: the cheque's own
lease if ever set → the invoice's agreement (it already names one) → the party's active lease in the
cheque's property → their handed-over ownership there. A party with none is still refused, because
inventing an agreement is worse than refusing. The fee amount reads the cheque's own `asset_id`,
which is required on the form and always present.

This also brings owner-occupiers in for free: `UnitOwnership implements BillableAgreement` and
`IssueInvoiceService` takes the contract, not a `Lease` — the same fix shape as
[module 31's violation fine](31-violations.md) earlier today.
([`BouncedChequeFeeBillsWhatTheUiCreatesTest`](../../tests/Feature/Regression/BouncedChequeFeeBillsWhatTheUiCreatesTest.php)
builds cheques the way the UI does — four cases: lessee, owner-occupier, the still-refused stranger,
and no-double-billing.)

## 4. Verified clean

| Hypothesis | Result |
|---|---|
| A lodged cheque reduces AR before it clears | **False**, and this is the module's best decision. No `Payment` exists until `clear()`, so a bounce has nothing to un-apply. competitors/03 describes Voyager as entering a receipt on deposit and reversing it on NSF — Atriom's shape is the safer one |
| A cheque can be cleared twice, double-settling an invoice | **False** — re-read under `lockForUpdate`, status re-checked inside the transaction, and the invoice locked too |
| A cheque can clear against another property's invoice | **False** — the picker pins to the cheque's `asset_id`, and the model re-checks on any `invoice_id`/`asset_id`/`tenant_id` change, including the edit-the-tenant path |
| A cleared cheque can be cancelled or bounced, stranding its payment | **False** — both refuse; the remedy named is to void the payment |
| An owner-occupier cannot lodge cheques at all | **False** — the tenant picker resolves through `OptionDisplay`, which scopes on properties a party leases **or owns** in |
| A PDC is deletable once it has moved money | **False** — `#[NeverDeletable]`, and blocked as a tenant's `blockedBy` relation |

## 5. Not assessed / open

- **Bounce after clearing.** Modelled as impossible: `bounce()` accepts only held/deposited. In
  practice a bank can return a cheque after provisional credit. The workaround (void the payment) is
  the documented remedy and the states are honest, so this is a scope question rather than a defect.
- **No cheque-book / series-exhaustion tracking**, and no alert when a tenant's lodged series runs
  out before the lease term does. An operator finds out by looking.
- **Egyptian legal escalation** on a bounced cheque is out of scope for the system entirely — noted
  because it is the operator's real next step and nothing here pretends to support it.
