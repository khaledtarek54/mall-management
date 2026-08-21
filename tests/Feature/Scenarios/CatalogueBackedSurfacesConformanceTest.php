<?php

/*
|--------------------------------------------------------------------------
| A row the operator activates reaches every screen that names one
|--------------------------------------------------------------------------
| EG-11 made the payment rails a catalogue and then converted three of nineteen surfaces. The
| result was worse than not having done it: the payments LIST filter offered Fawry while the
| payments CREATE form still offered the static seven, so a Fawry receipt could be filtered for and
| never recorded — and the deposit modal offered eight methods of which six rendered as raw
| snake_case, with an InstaPay deposit printing `admin.enums.expense_paid_from.instapay` in the list.
|
| `grep` would have found all sixteen in two seconds. It is a gate now, for the same reason the
| null-lease chain became one.
|
| ## The rule, and the half that is easy to get backwards
|
| A surface may read a payment-method LANG ARRAY only for a column the catalogue does NOT widen.
| Pointing `payrolls.paid_from` or `custodies.paid_from` at `PaymentMethod::options()` would be the
| SAME bug mirrored: those columns hold `cash|bank` and would then be offered rails they refuse. So
| this gate is scoped by column, not by string.
*/

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Support\ValueSets;
use Illuminate\Support\Facades\File;

/**
 * The lang groups that label a payment rail, and the columns each one is used for.
 *
 * A file touching one of these groups is inspected; whether it is an offence depends on which
 * column it serves, which is why each entry names its files rather than the gate guessing.
 */
const RAIL_LABEL_GROUPS = [
    // Payment rails — `payment_methods`.
    'admin.enums.method',
    'admin.enums.expense_paid_from',
    'admin.enums.vendor_bill_payment_method',
    'admin.disbursements.methods',
    // Expense categories — `expense_categories`. The same shape and the same failure: the category
    // decides which P&L account a supplier bill hits, so a screen offering a stale list offers the
    // wrong accounting.
    'admin.enums.vendor_bill_category',
    // House rules — `violation_categories`. A rule the operator added has no lang key, so a screen
    // still reading the array both fails to offer it and prints its raw code where it appears.
    'admin.violations.categories',
    // Supplier document types — `vendor_document_types`. The worst of the six to leave stale: a type
    // an operator added to block dispatch cannot be FILED from a screen that does not offer it, so
    // the liability decision they made has no way of reaching a vendor's record.
    'admin.vendors.documents.types',
];

/**
 * Files that legitimately still read a rail lang group, because the column they serve is NOT
 * catalogue-widened. Each must say which column, so the next reader can check the claim.
 */
const NOT_CATALOGUE_WIDENED = [
    'app/Filament/Admin/Resources/Payrolls/Tables/PayrollsTable.php' => 'payrolls.paid_from — cash|bank only, not widened. Offering rails here would be the same bug mirrored.',
    'app/Filament/Admin/Resources/Payrolls/Schemas/PayrollForm.php' => 'payrolls.paid_from — cash|bank only, not widened.',
    'app/Filament/Admin/Resources/MarketingBudgets/RelationManagers/MarketingSpendsRelationManager.php' => 'marketing_spends.paid_from — cash|bank only, not widened.',
    'app/Filament/Admin/Resources/MarketingBudgets/MarketingBudgetResource.php' => 'marketing_spends.paid_from — cash|bank only, not widened.',
    'app/Filament/Admin/Resources/Custodies/CustodyResource.php' => 'custodies.paid_from — cash|bank only, not widened.',
    'app/Support/ActivityVocabulary.php' => 'A field->lang-group registry covering BOTH widened and unwidened columns; the activity log resolves a stored value, and a catalogue row with no lang key falls through to the raw code by design (logged as a known gap, not a screen).',
    'app/Models/PaymentMethod.php' => 'The catalogue itself — these groups are its FALLBACK for the shipped codes.',
    'app/Models/ExpenseCategory.php' => 'The catalogue itself — the category group is its FALLBACK for the six shipped codes.',
    'app/Models/DepositTransaction.php' => 'Passes the group to PaymentMethod::labelFor() as a fallback, which is the sanctioned shape.',
];

it('lets no surface on a catalogue-widened column read a static rail list', function () {
    $offenders = [];

    foreach (array_merge(File::allFiles(base_path('app')), File::allFiles(resource_path('views'))) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (array_key_exists($relative, NOT_CATALOGUE_WIDENED)) {
            continue;
        }

        $body = $file->getContents();
        // Comments describe these groups when explaining why they are no longer read.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $body) ?? $body;

        // Passing a group to `PaymentMethod::options()` / `::labelFor()` as the FALLBACK for the
        // shipped codes is the sanctioned shape — that call reads the catalogue first. What is
        // banned is resolving the group directly with `__()`, which never sees a rail at all.
        $code = preg_replace('~(PaymentMethod|ExpenseCategory|ViolationCategory|VendorDocumentType)::(options|labelFor)\([^;]*?\)~s', '', $code) ?? $code;

        foreach (RAIL_LABEL_GROUPS as $group) {
            if (preg_match('~__\(\s*["\']'.preg_quote($group, '~').'~', $code)) {
                $offenders[] = "{$relative} — resolves {$group} with __()";
                break;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These label or offer a payment rail from a STATIC lang array. A rail the operator activates',
        'will not appear in them, and one they add has no lang key so it renders as its raw code:',
        '  '.implode("\n  ", $offenders),
        '',
        'Use the catalogue: PaymentMethod / ExpenseCategory ::options() to OFFER and ::labelFor() to',
        'LABEL. If the column is genuinely not catalogue-widened, add the file to',
        'NOT_CATALOGUE_WIDENED naming the column.',
    ]));
});

it('has no stale exemption', function () {
    $stale = [];

    foreach (array_keys(NOT_CATALOGUE_WIDENED) as $relative) {
        $path = base_path($relative);

        if (! file_exists($path)) {
            $stale[] = "{$relative} (gone)";

            continue;
        }

        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($path)) ?? '';
        $reads = false;

        foreach (RAIL_LABEL_GROUPS as $group) {
            $reads = $reads || str_contains($code, $group);
        }

        if (! $reads) {
            $stale[] = "{$relative} (no longer reads a rail group)";
        }
    }

    expect($stale)->toBe([], 'Remove from NOT_CATALOGUE_WIDENED: '.implode(', ', $stale));
});

it('names a real column in every exemption, and proves the widened set differs', function () {
    foreach (NOT_CATALOGUE_WIDENED as $relative => $reason) {
        expect(strlen($reason))->toBeGreaterThan(40, "The exemption for {$relative} does not say which column.");
    }

    // The premise: with a rail activated, the widened columns really do hold more than the
    // unwidened ones — so the distinction this gate draws is a real one and not one between two
    // identical sets. Without this row the catalogue widens nothing and the assertion below would
    // pass for a reason unrelated to what it claims.
    PaymentMethod::create([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'for_inbound' => true,
        'for_outbound' => true,
    ]);
    ExpenseCategory::create(['code' => 'insurance', 'name_en' => 'Insurance', 'name_ar' => 'تأمين']);

    expect(count(ValueSets::allowed('expenses', 'paid_from') ?? []))
        ->toBeGreaterThan(count(ValueSets::allowed('payrolls', 'paid_from') ?? []))
        ->and(ValueSets::allowed('expenses', 'category'))->toContain('insurance');
});

it('keeps the three expense-category floors identical', function () {
    // `ExpenseCategory::options()` is keyed to ONE of them and drives pickers on all three columns,
    // so a floor that drifts would offer a vendor-bill category the expenses column refuses, or the
    // reverse — the offer/accept split again, one table along. They are hand-kept lists in
    // `ValueSets::SETS`; nothing but this makes them agree.
    $sets = [
        'expenses.category' => ValueSets::allowed('expenses', 'category'),
        'vendor_bills.category' => ValueSets::allowed('vendor_bills', 'category'),
        'custody_transactions.category' => ValueSets::allowed('custody_transactions', 'category'),
    ];

    foreach ($sets as $key => $values) {
        sort($values);
        $sets[$key] = $values;
    }

    expect(array_values(array_unique(array_map('serialize', $sets))))->toHaveCount(1, implode("\n", [
        'The three expense-category columns accept different sets. `ExpenseCategory::options()` is',
        'keyed to `expenses.category` and drives the pickers on all three, so a picker will offer a',
        'value one of the other columns refuses:',
        '  '.json_encode($sets, JSON_UNESCAPED_SLASHES),
    ]));

    // The premise: these are non-empty, so agreeing is a real property and not three empty arrays.
    expect($sets['expenses.category'])->not->toBeEmpty();
});
