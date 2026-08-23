<?php

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\DerivedMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use App\Models\CreditNoteApplication;

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

    it('holds a credit note\'s applied_amount to its application rows', function () {
        // Not covered by the data-driven Invoice sweep above — CreditNote's cases are written out,
        // so classifying the column in the registry does NOT give it a test. Written here on
        // purpose, because the exploit is concrete: reset a spent note to zero and it reads
        // unspent, so the same credit can be applied a second time.
        $note = CreditNote::create([
            'invoice_id' => $this->invoice->id,
            'tenant_id' => $this->invoice->tenant_id,
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'reason' => 'billing_error',
            'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
            'applied_amount' => 1000, 'balance' => 0,
        ]);

        CreditNoteApplication::create([
            'credit_note_id' => $note->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 1000,
            'applied_at' => now(),
        ]);

        $note->update(['applied_amount' => 0]);

        expect((float) $note->fresh()->applied_amount)->toBe(1000.0)
            ->and((float) $note->fresh()->balance)->toBe(0.0);
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

/**
 * Money columns a DERIVED model may leave unclassified, and why.
 *
 * The check below is deliberately strict, so an exception has to be written down rather than
 * absorbed. Each entry is a column that is fillable, is money, and is NOT proven to resist a
 * crafted payload.
 */
const DERIVED_MONEY_UNGUARDED = [
    // Empty since 2026-08-23. The one entry was `CreditNote::applied_amount`, and it was fixed
    // rather than waived — see the guard in `CreditNote::updating`. An entry here is a money
    // column a crafted payload can still write, so the list is meant to stay empty.
];

it('classifies every fillable money column on a model it already guards', function () {
    // THE HOLE THIS CLOSES. The tampering cases below are generated from the registry
    // (`->with(array_keys(DerivedMoney::DERIVED[Invoice::class]))`), so a column missing from the
    // registry has no test — and the gate stays green while the column is unprotected. Coverage
    // that shrinks with the thing it measures.
    //
    // Found exactly that way: `invoices.credit_applied_amount`, the second of the four settlement
    // channels, was fillable, unclassified and unguarded. A payload set it to 5,000 and the next
    // recomputeTotals() folded it into `paid_amount` — an invoice reading part-settled with no
    // credit note, no payment and no deposit behind it.
    //
    // Derived from the SCHEMA, not from the registry: every decimal column a guarded model marks
    // fillable is money someone can post, so it is either classified or exempt with a reason.
    $unclassified = [];
    $moneyColumnsSeen = 0;

    foreach (DerivedMoney::DERIVED as $model => $columns) {
        $instance = new $model;
        $fillable = $instance->getFillable();

        foreach (Schema::getColumns($instance->getTable()) as $column) {
            // BOTH spellings. MySQL reports `decimal`, SQLite reports `numeric` for the same
            // column — and the suite runs on SQLite, so matching only `decimal` swept ZERO columns
            // and this check was vacuously green. The measurement that found the underlying defect
            // ran against MySQL and the test runs against SQLite; nothing reconciled the two, and
            // the mutation audit is the only reason it did not stay that way.
            $type = strtolower($column['type_name'] ?? $column['type'] ?? '');

            if (! str_contains($type, 'decimal') && ! str_contains($type, 'numeric')) {
                continue;
            }

            if (! in_array($column['name'], $fillable, true)) {
                continue;   // not mass-assignable — a payload cannot reach it
            }

            $moneyColumnsSeen++;

            if (array_key_exists($column['name'], $columns)) {
                continue;
            }

            $key = $model.'::'.$column['name'];

            if (! array_key_exists($key, DERIVED_MONEY_UNGUARDED)) {
                $unclassified[] = $key;
            }
        }
    }

    // The sweep must have SEEN money columns before reporting none unclassified — see the note
    // on `decimal` vs `numeric` above for why this guard is not theoretical.
    expect($moneyColumnsSeen)->toBeGreaterThan(5);

    expect($unclassified)->toBe([], implode("\n  ", array_merge(
        ['These money columns are fillable on a model this registry already guards, so a crafted',
            'payload can write them and nothing proves otherwise — they get no tampering case:'],
        $unclassified,
        ['Add each to DerivedMoney::DERIVED (and make it resist), or to DERIVED_MONEY_UNGUARDED with the reason.'],
    )));
});

it('keeps every unguarded-money exemption honest', function () {
    // Asserted even when the list is empty — an empty loop makes no assertions and Pest marks the
    // test RISKY, which reads as coverage that is not there. Bounded at one because an entry is a
    // money column a crafted payload can still write.
    expect(count(DERIVED_MONEY_UNGUARDED))->toBeLessThanOrEqual(1);

    foreach (array_keys(DERIVED_MONEY_UNGUARDED) as $key) {
        [$model, $column] = explode('::', $key, 2);

        expect(class_exists($model))->toBeTrue("{$key} is exempt and the model no longer exists.");
        expect(in_array($column, (new $model)->getFillable(), true))
            ->toBeTrue("{$key} is exempt but is no longer fillable — drop the exemption.");
    }

    foreach (DERIVED_MONEY_UNGUARDED as $key => $reason) {
        expect(str_word_count($reason))->toBeGreaterThan(12, "{$key}'s exemption reason is too thin to review.");
    }
});
