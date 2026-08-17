<?php

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\DerivedMoney;
use Illuminate\Database\Eloquent\Model;

/**
 * Self-enforcing gate — a derived money column may not be written by whoever posts the form.
 *
 * **It does not read the forms.** A form can render a field `readOnly()->dehydrated()`, which looks
 * locked and submits anyway, and any grep over that pattern would be one refactor away from
 * useless. This tampers with a COMMITTED record and asserts the value did not stick — the only
 * claim that actually matters, and the one a template change cannot quietly invalidate.
 *
 * The same shape as `ChangeImpactConformanceTest`, which proves every REFUSED field by dirtying it
 * on a committed fixture rather than asserting that a guard exists somewhere.
 *
 * Two halves:
 *   1. every model with a fillable money column is CLASSIFIED (derived or operator-entered), so a
 *      new module answers the question instead of inheriting "the form said readOnly";
 *   2. every column classified DERIVED actually resists a tampered mass-assignment.
 */

/** The vocabulary that makes a column "money" for classification purposes. */
const MONEY_COLUMNS = [
    'balance', 'paid_amount', 'subtotal', 'vat_amount', 'total',
    'applied_amount', 'credit_applied_amount',
];

/** @return array<class-string, array<int, string>> model => its fillable money columns */
function modelsWithFillableMoney(): array
{
    $found = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $money = array_values(array_intersect($reflection->newInstance()->getFillable(), MONEY_COLUMNS));

        if ($money !== []) {
            $found[$class] = $money;
        }
    }

    return $found;
}

it('classifies every model that has a fillable money column', function () {
    $unclassified = array_diff(array_keys(modelsWithFillableMoney()), DerivedMoney::classified());

    expect(array_values($unclassified))->toBe(
        [],
        'Unclassified model(s) with fillable money columns: '.implode(', ', $unclassified)
        .'. Add each to App\Support\DerivedMoney::DERIVED (the system owns the value) or ::ENTERED '
        .'(an operator types it) — with the reason.'
    );
});

it('carries no classification for a model that no longer has money columns', function () {
    // A stale entry reads as a considered decision and the next person inherits it by accident —
    // the same rot that let a fifth of the PHPStan baseline describe errors that did not exist.
    $stale = array_diff(DerivedMoney::classified(), array_keys(modelsWithFillableMoney()));

    expect(array_values($stale))->toBe([], 'Stale classification(s): '.implode(', ', $stale));
});

it('never classifies a model as both derived and operator-entered', function () {
    $both = array_intersect(array_keys(DerivedMoney::DERIVED), array_keys(DerivedMoney::ENTERED));

    expect(array_values($both))->toBe([]);
});

it('states a reason for every classification', function () {
    $blank = [];

    foreach (DerivedMoney::DERIVED as $model => $columns) {
        foreach ($columns as $column => $reason) {
            if (trim($reason) === '') {
                $blank[] = "{$model}::{$column}";
            }
        }
    }

    foreach (DerivedMoney::ENTERED as $model => $reason) {
        if (trim($reason) === '') {
            $blank[] = $model;
        }
    }

    expect($blank)->toBe([]);
});

describe('the derived columns resist tampering', function () {
    beforeEach(function () {
        $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant());

        $this->invoice = makeInvoice($lease, [
            'status' => 'issued',
            'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
            'paid_amount' => 0, 'balance' => 11400,
        ]);

        InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'type' => 'service_charge', 'description' => 'Service charge',
            'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
        ]);
    });

    it('holds every DERIVED column on a committed invoice', function (string $column) {
        $before = $this->invoice->fresh()->{$column};

        // A tampered payload either bounces off (reverted) or is refused outright. Both are
        // acceptable; persisting the value is not.
        try {
            $this->invoice->update([$column => is_numeric($before) ? 1 : 'TAMPERED']);
        } catch (DomainException) {
            // refused — the stronger of the two answers
        }

        expect((string) $this->invoice->fresh()->{$column})->toBe((string) $before);
    })->with(array_keys(DerivedMoney::DERIVED[Invoice::class]));

    it('holds a credit note\'s header against its own lines', function () {
        $note = CreditNote::create([
            'invoice_id' => $this->invoice->id,
            'tenant_id' => $this->invoice->tenant_id,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'reason' => 'billing_error',
            'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
            'applied_amount' => 0, 'balance' => 0,
        ]);

        CreditNoteItem::create([
            'credit_note_id' => $note->id, 'description' => 'Correction',
            'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000,
        ]);

        expect((float) $note->fresh()->total)->toBe(1000.0);
    });

    it('leaves an operator-entered column alone — the paired control', function () {
        // The gate must refuse the CLIENT, not the mechanism. If a line's own amount stopped being
        // writable, nobody could raise an invoice at all, and every test above would still pass.
        $item = $this->invoice->items()->sole();
        $item->update(['amount' => 2000, 'vat_rate' => 0]);

        expect((float) $item->fresh()->amount)->toBe(2000.0)
            ->and((float) $this->invoice->fresh()->subtotal)->toBe(2000.0);
    });
});
