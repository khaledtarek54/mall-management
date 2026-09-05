<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\Warehouse;
use App\Services\VendorBillService;
use Illuminate\Database\Eloquent\Model;

/**
 * Committed instances of the GL posting sources, for the two gates that must agree on what
 * "committed" means.
 *
 * `refusalFixtures()` is the set `ChangeImpactConformanceTest` has always owned — one source per
 * REFUSED-declaring model, built in the state its own `committed` sentence describes, self-contained
 * down to its assets. It moved here from that file's scope for the reason `Tests\Support\FilterSweep`
 * exists: a second gate (`AMoneyFormIsClosedOnceCommittedTest`) needs the same notion of committed,
 * a parallel Pest worker only loads the test files it owns, and re-declaring file-scope helpers per
 * file is a fatal redeclaration the moment one worker loads both.
 *
 * `uiFixtures()` is the SAME question under a different constraint, and the difference is the whole
 * reason it is a second set rather than a reuse: a Filament Edit page 404s on a record outside the
 * selected property, and scoping differs per model — an invoice scopes through its lease's unit, a
 * PAYMENT through the invoices it settles, so the refusal set's bare captured payment (no
 * allocations) is committed and yet UNMOUNTABLE. Every UI fixture is therefore built under the ONE
 * asset the caller has already selected as the Filament tenant. It also covers two sources the
 * refusal set must NOT contain (its completeness tooth requires refusal fixtures for exactly the
 * REFUSED-declaring sources): `DepositTransaction` and `FixedAsset` declare no REFUSED field — their
 * guards are bespoke (`hasBeenDrawnOn()`) or deliberately absent (§15.2) — and both have Edit pages.
 */
class CommittedMoneyFixtures
{
    /** One employee for the custody/advance fixtures. */
    public static function employee(): Employee
    {
        return Employee::create([
            'asset_id' => makeAsset()->id,
            'name' => 'Impact Employee '.uniqid(),
            'code' => 'EMP-'.substr(uniqid(), -6),
            'status' => 'active',
            'hire_date' => now()->subYear()->toDateString(),
            'base_salary' => 12000,
            'payment_method' => 'bank',
        ]);
    }

    /**
     * A committed instance of each source that declares REFUSED fields, in the state its
     * `committed` sentence describes. Only these sources need one here: a model with nothing to
     * refuse has nothing for the refusal check to prove, and that gate's completeness tooth
     * REQUIRES this map to match the REFUSED-declaring set exactly.
     *
     * @return array<class-string, callable(): Model>
     */
    public static function refusalFixtures(): array
    {
        return [
            Invoice::class => function () {
                $lease = makeLease(makeUnit(makeAsset()));

                return Invoice::create([
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'status' => 'issued',
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(15)->toDateString(),
                    'period_start' => now()->startOfMonth()->toDateString(),
                    'period_end' => now()->endOfMonth()->toDateString(),
                    'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
                ]);
            },

            Payment::class => function () {
                $lease = makeLease(makeUnit(makeAsset()));

                return Payment::create([
                    'tenant_id' => $lease->tenant_id,
                    'amount' => 500,
                    'method' => 'bank_transfer',
                    'status' => 'captured',
                    'payment_date' => now()->toDateString(),
                ]);
            },

            CreditNote::class => function () {
                $lease = makeLease(makeUnit(makeAsset()));

                return CreditNote::create([
                    'tenant_id' => $lease->tenant_id,
                    'lease_id' => $lease->id,
                    'status' => 'issued',
                    'issue_date' => now()->toDateString(),
                    // A registered classification: credit_notes.reason is a ValueSets column.
                    'reason' => 'discount',
                    'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
                ]);
            },

            Expense::class => function () {
                // `recorded` is the state an expense is BORN in — there is no draft — so this
                // fixture is a plain create, which is also the only way the system makes one.
                return Expense::create([
                    'asset_id' => makeAsset()->id,
                    'category' => 'utilities',
                    'description' => 'Generator diesel',
                    'amount' => 1000,
                    'vat_amount' => 0,
                    'paid_from' => 'cash',
                    'expense_date' => now()->toDateString(),
                    'status' => 'recorded',
                ]);
            },

            VendorBillPayment::class => function () {
                $bill = self::refusalFixtures()[VendorBill::class]();

                // Through the service, not a bare create: a fixture that sets columns no writer
                // sets proves the guard against a state the system cannot reach.
                app(VendorBillService::class)->recordPayment($bill, 400.0);

                return $bill->refresh()->payments()->sole();
            },

            VendorBill::class => function () {
                $asset = makeAsset();
                $vendor = Vendor::create(['name' => 'Impact Co '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

                return VendorBill::create([
                    'vendor_id' => $vendor->id,
                    'asset_id' => $asset->id,
                    'category' => 'cleaning_security',
                    'status' => 'approved',
                    'bill_date' => now()->toDateString(),
                    'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
                ]);
            },

            // ── The nine promoted from DERIVED on 2026-08-28. Each is built in the state its own
            // `committed` sentence describes, which is what makes the refusal proved rather than
            // asserted: a fixture in a state the system never reaches proves a guard nobody meets.

            Payroll::class => function () {
                // APPROVED, not draft — a draft run is meant to be correctable, and the whole
                // point of the guard is that approval is the line.
                return Payroll::create([
                    'asset_id' => makeAsset()->id,
                    'period_month' => now()->startOfMonth()->toDateString(),
                    'gross_salaries' => 100000, 'allowances' => 0, 'salary_tax' => 5000,
                    'social_insurance' => 11000, 'employer_social_insurance' => 18000,
                    'advance_deductions' => 0, 'other_deductions' => 0, 'net_paid' => 84000,
                    'paid_from' => 'bank', 'status' => 'approved',
                ]);
            },

            Custody::class => function () {
                // SETTLED against, because that — not the grant — is when a عهدة's terms lock. A
                // fixture built on grant alone would prove a refusal in a state the app
                // deliberately leaves open.
                $custody = Custody::create([
                    'employee_id' => self::employee()->id,
                    'asset_id' => makeAsset()->id,
                    'amount' => 20000,
                    'custody_date' => now()->toDateString(),
                    'paid_from' => 'bank',
                    'purpose' => 'Site petty cash',
                ]);

                CustodyTransaction::create([
                    'custody_id' => $custody->id,
                    'asset_id' => $custody->asset_id,
                    'type' => 'expense',
                    'amount' => 500,
                    'transaction_date' => now()->toDateString(),
                    'category' => 'maintenance',
                    'method' => 'cash',
                ]);

                return $custody;
            },

            CustodyTransaction::class => function () {
                // A bare grant, not the settled Custody fixture — that one already carries a
                // transaction and this must build its own subject.
                $custody = Custody::create([
                    'employee_id' => self::employee()->id,
                    'asset_id' => makeAsset()->id,
                    'amount' => 20000,
                    'custody_date' => now()->toDateString(),
                    'paid_from' => 'bank',
                    'purpose' => 'Site petty cash',
                ]);

                return CustodyTransaction::create([
                    'custody_id' => $custody->id,
                    'asset_id' => $custody->asset_id,
                    'type' => 'expense',
                    'amount' => 500,
                    'transaction_date' => now()->toDateString(),
                    'category' => 'maintenance',
                    'method' => 'cash',
                ]);
            },

            EmployeeAdvance::class => function () {
                return EmployeeAdvance::create([
                    'employee_id' => self::employee()->id,
                    'asset_id' => makeAsset()->id,
                    'type' => 'advance',
                    'amount' => 10000,
                    'advance_date' => now()->toDateString(),
                    'paid_from' => 'bank',
                ]);
            },

            EmployeeAdvanceRepayment::class => function () {
                $advance = self::refusalFixtures()[EmployeeAdvance::class]();

                return EmployeeAdvanceRepayment::create([
                    'employee_advance_id' => $advance->id,
                    'asset_id' => $advance->asset_id,
                    'amount' => 2000,
                    'repaid_on' => now()->toDateString(),
                    'method' => 'cash',
                ]);
            },

            StockMovement::class => function () {
                $asset = makeAsset();
                $warehouse = Warehouse::create([
                    'asset_id' => $asset->id,
                    'name' => 'Main store '.uniqid(),
                    'code' => 'WH-'.substr(uniqid(), -6),
                    'is_active' => true,
                ]);
                $item = InventoryItem::create([
                    'sku' => 'SKU-'.substr(uniqid(), -6),
                    'name' => 'Filter cartridge',
                    'unit' => 'pc',
                    'unit_cost' => 150,
                    'reorder_level' => 1,
                    'is_active' => true,
                ]);

                return StockMovement::create([
                    'warehouse_id' => $warehouse->id,
                    'inventory_item_id' => $item->id,
                    'type' => 'receipt',
                    'quantity' => 10,
                    'unit_cost' => 150,
                    'moved_on' => now()->toDateString(),
                ]);
            },
        ];
    }

    /**
     * A committed, MOUNTABLE instance of every GL source with an Edit page, built under the one
     * property the caller has selected as the Filament tenant.
     *
     * Deliberate states, because each is the state whose openness the UI gate judges:
     * the payment is captured AND ALLOCATED (a bare one is invisible to its own Edit page — the
     * page scopes through settled invoices); the deposit receipt is UNDRAWN, which is the state the
     * model deliberately leaves correctable, so its open fields are judged against the registry
     * rather than against the drawn-on freeze that `AnActOnAPostedDocumentIsWhereItCanBeSeenTest`
     * already proves; the fixed asset is ACTIVE, the state §15.2 keeps editable by design.
     *
     * @return array<class-string, callable(): Model>
     */
    public static function uiFixtures(Asset $asset): array
    {
        $lease = fn () => makeLease(makeUnit($asset, ['code' => 'CF-'.substr(uniqid(), -5)]));

        return [
            Invoice::class => fn () => makeInvoice($lease()),

            Payment::class => fn () => settleInvoiceInFull(makeInvoice($lease())),

            CreditNote::class => fn () => CreditNote::create([
                'tenant_id' => ($l = $lease())->tenant_id,
                'lease_id' => $l->id,
                'status' => 'issued',
                'issue_date' => now()->toDateString(),
                'reason' => 'discount',
                'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
            ]),

            VendorBill::class => function () use ($asset) {
                $vendor = Vendor::create(['name' => 'Closed Form Co '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

                return VendorBill::create([
                    'vendor_id' => $vendor->id,
                    'asset_id' => $asset->id,
                    'reference' => 'CF-'.substr(uniqid(), -6),
                    'category' => 'cleaning_security',
                    'status' => 'approved',
                    'bill_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
                ]);
            },

            Expense::class => fn () => Expense::create([
                'asset_id' => $asset->id,
                'category' => 'utilities',
                'description' => 'Generator diesel',
                'amount' => 1000, 'vat_amount' => 0,
                'paid_from' => 'cash',
                'expense_date' => now()->toDateString(),
                'status' => 'recorded',
            ]),

            Payroll::class => fn () => Payroll::create([
                'asset_id' => $asset->id,
                'period_month' => now()->startOfMonth()->toDateString(),
                'gross_salaries' => 100000, 'net_paid' => 84000,
                'paid_from' => 'bank', 'status' => 'approved',
            ]),

            DepositTransaction::class => fn () => depositMovement($lease(), 'receipt', 100000),

            FixedAsset::class => fn () => FixedAsset::create([
                'asset_id' => $asset->id,
                'name' => 'Chiller '.uniqid(),
                'tag' => 'FA-'.substr(uniqid(), -6),
                'acquisition_date' => now()->toDateString(),
                'acquisition_cost' => 50000,
                'salvage_value' => 0,
                'useful_life_months' => 60,
                'funded_from' => 'cash',
                'status' => 'active',
            ]),

            Custody::class => function () use ($asset) {
                $employee = Employee::create([
                    'asset_id' => $asset->id,
                    'name' => 'Closed Form Custodian '.uniqid(),
                    'code' => 'EMP-'.substr(uniqid(), -6),
                    'status' => 'active',
                    'hire_date' => now()->subYear()->toDateString(),
                ]);

                $custody = Custody::create([
                    'employee_id' => $employee->id,
                    'asset_id' => $asset->id,
                    'amount' => 20000,
                    'custody_date' => now()->toDateString(),
                    'paid_from' => 'bank',
                    'purpose' => 'Site petty cash',
                ]);

                CustodyTransaction::create([
                    'custody_id' => $custody->id,
                    'asset_id' => $asset->id,
                    'type' => 'expense',
                    'amount' => 500,
                    'transaction_date' => now()->toDateString(),
                    'category' => 'maintenance',
                    'method' => 'cash',
                ]);

                return $custody;
            },
        ];
    }
}
