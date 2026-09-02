<?php

namespace App\Support;

use App\Models\{Announcement, Area, Asset, BankAccount, CreditNote, Custody, Department,
    DepositTransaction, Disbursement, Employee, Equipment, Expense, FacilityWorkOrder, FixedAsset, InventoryItem,
    Invoice, JournalEntry, Lease, LedgerAccount, MarketingPost, OwnerRequest, OwnerStatementRun,
    Payment, Payroll, PostDatedCheque, PurchaseRequest, RentableItem, ServicePlan, StockMovement,
    Tenant, TenantRequest, Unit, UnitOwnership, User, UtilityMeter, Vendor, VendorBill, Violation,
    Warehouse, WorkPermit};

/**
 * WHICH fields of a record the assistant may read back, and why the rest may not.
 *
 * ## An allowlist, never the row
 *
 * A record reaches the assistant because somebody named it — "what does Cilantro owe" — and the
 * honest answer needs a handful of facts, not every column. Handing back the row would mean handing
 * back whatever the table happens to carry: `password` is fillable on both `User` and `Tenant`,
 * `metadata` holds operator-defined custom fields, and half the money models carry internal
 * reconciliation stamps that mean nothing outside their own screen. So the fields are listed, and
 * `AssistantFieldsConformanceTest` fails the build on a model that is in neither list.
 *
 * ## Being findable is not the same as being summarisable
 *
 * All 39 models in `SearchPolicy::INDEXED` can be found — that is what makes "Cilantro" resolve to
 * a tenant. Far fewer should be READ BACK: a payroll run and an employee record are personal data
 * whose whole point is that they are seen on a screen with its own permission, not quoted into a
 * chat panel that any colleague may be looking at. Those are refused BY NAME with the reason, so
 * the decision is visible rather than an omission.
 *
 * ## Scope is inherited, not re-implemented
 *
 * Nothing here decides what a reader may see. The record was found through the resource's own
 * `getEloquentQuery()`, which carries the property scope and the permissions; this only narrows
 * WHICH COLUMNS of an already-visible record are worth quoting.
 */
final class AssistantFields
{
    /**
     * model => ['columns' => [...], 'derived' => [label => method]]
     *
     * `derived` names a METHOD on the model, for facts that are computed rather than stored — a
     * tenant's outstanding balance is the obvious one, and reading it any other way would be a
     * second answer to a question `Invoice::recomputeTotals()` already answers.
     *
     * @var array<class-string, array{columns: array<int, string>, derived?: array<string, string>}>
     */
    public const SUMMARISED = [
        Tenant::class => [
            'columns' => ['code', 'name', 'trade_name', 'status', 'phone', 'email'],
            'derived' => ['outstanding_balance' => 'outstandingBalance'],
        ],
        Lease::class => [
            'columns' => ['reference', 'status', 'commencement_date', 'expiry_date', 'base_rent_monthly'],
        ],
        Unit::class => [
            'columns' => ['code', 'status', 'area_sqm'],
        ],
        Invoice::class => [
            'columns' => ['number', 'status', 'issue_date', 'due_date', 'total', 'paid_amount', 'balance'],
        ],
        Payment::class => [
            // A receipt's own number is `reference`, not `number` — the gate caught the guess.
            'columns' => ['reference', 'amount', 'method', 'status', 'payment_date'],
        ],
        CreditNote::class => [
            'columns' => ['number', 'status', 'total'],
        ],
        Vendor::class => [
            'columns' => ['code', 'name', 'status'],
        ],
    ];

    /**
     * Findable, and deliberately never quoted back. Each reason answers "why not this one".
     *
     * @var array<class-string, string>
     */
    public const NOT_SUMMARISED = [
        // ---- Personal data. Findable so an HR user can navigate to it; never read into a chat
        //      panel a colleague may be looking over. -------------------------------------------
        Employee::class => 'A person. Salary, national ID and documents live here, and the screen that shows them has its own permission for exactly that reason — quoting them into a chat panel bypasses the room the screen assumes.',
        Payroll::class => 'A payroll run: what individuals were paid. Same reasoning as Employee, and worse, because a run aggregates the whole department.',
        User::class => 'A colleague\'s account. `password` is fillable here; nothing about a user is a business answer.',
        Custody::class => 'Petty cash held BY A NAMED PERSON. The balance is a statement about that individual\'s handling of money and belongs on the custody screen with its approvals.',

        // ---- Records whose meaning is the screen, not the row -----------------------------------
        JournalEntry::class => 'A journal entry means nothing without its lines, and its lines mean nothing without the chart. The general ledger report is the answer to any question about it.',
        StockMovement::class => 'One movement in a running balance. The number that matters is the stock level, which the inventory screens compute.',
        DepositTransaction::class => 'One movement against a deposit pot. The pot is per LEASE and the figure that matters is the holding, not the line.',
        Disbursement::class => 'A payment to an owner, which only makes sense inside its statement run.',
        OwnerStatementRun::class => 'A run is a container; its figures are the statement, which is a report.',

        // ---- Operational records with no figure worth quoting -----------------------------------
        FacilityWorkOrder::class => 'An operational record whose state is a workflow, not a number. The screen shows the thread, the costs and the SLA clock together; a field list would misrepresent it.',
        TenantRequest::class => 'Same as a work order: the value is the conversation and the clock.',
        OwnerRequest::class => 'A thread. Quoting fields out of it loses the exchange that is the record.',
        WorkPermit::class => 'A safety document whose meaning is its window and its closure, both read on the screen.',
        ServicePlan::class => 'A schedule; the answer to any question about it is the plan screen or the PM compliance figure.',
        Violation::class => 'A breach record tied to a fine and an evidence trail.',
        PurchaseRequest::class => 'Sits inside an approval ladder; a field list would state a status without the tier that governs it.',
        VendorBill::class => 'A payable whose figure is only meaningful against its payments and any SLA penalty. The payables reports answer this.',
        Expense::class => 'One cost line. The weekly-spend and P&L reports are the answer.',

        // ---- Master data that is a setting, not a fact about the business ----------------------
        Asset::class => 'The property itself, which is the scope of every other answer rather than an answer.',
        Area::class => 'A routing zone for work orders. Its fields are a name and a property; the answer to any question about it is which zone a job went to, which the work order itself states.',
        Department::class => 'An organisational unit. It groups people and approvals, and neither of those is a fact worth quoting out of context — the approval ladder and the employee register are the screens that mean something.',
        BankAccount::class => 'Account details are a payment instruction. The reconciliation screens are where balances belong.',
        LedgerAccount::class => 'A chart row, and its NAME is ordinary business vocabulary — "Accounting", "Bad debts", "Rent". Measured against the operator playbook, that hijacked conceptual questions: "write off a bad debt" returned account 51109 and "close the accounting period" returned an account called Accounting, instead of the act and the screen. The ledger reports are how anybody asks about an account.',
        Warehouse::class => 'A stock location. The figure anybody wants is what is IN it, which is a stock level the inventory screens compute rather than a column on this row.',
        InventoryItem::class => 'A catalogue row; the figure that matters is stock on hand.',
        Equipment::class => 'An asset register row read on its own screen with its service history.',
        UtilityMeter::class => 'A meter; the answer to a question about it is a reading or a consumption trend.',
        FixedAsset::class => 'Cost, depreciation and NBV are a schedule, and the register report states it.',
        RentableItem::class => 'A bay or kiosk; its status is answered by the rentable-item map.',
        UnitOwnership::class => 'An ownership with a handover and a share; the owner statement is the answer.',
        PostDatedCheque::class => 'A lodged cheque whose meaning is its maturity board.',
        Announcement::class => 'A message that was sent; the text is the record and it is read on its screen.',
        MarketingPost::class => 'Shopper-facing content — a title, a body and a run of dates. It is written and read on its own screen, and quoting a marketing post into an operator\'s chat answers no question about the business.',
    ];

    /** @return array<class-string> */
    public static function summarisable(): array
    {
        return array_keys(self::SUMMARISED);
    }

    public static function isSummarisable(string $model): bool
    {
        return isset(self::SUMMARISED[$model]);
    }
}
