<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\VendorBill;
use App\Models\Vendor;
use App\Services\Accounting\LedgerPoster;
use App\Support\ChangeImpact;
use App\Support\LedgerRealtimeSync;

/**
 * Conformance gate — every field of every money document has a stated change policy, and the
 * refusals actually refuse.
 *
 * WHY THIS EXISTS. Atriom's ledger is derived: `LedgerPoster::sync()` re-reads a document and, when
 * its posted entry no longer matches, voids the entry and posts a fresh one. That makes "may this
 * change reach the books?" a policy question, and the policy was previously spread across four
 * `updating` hooks with twenty sources having none — which is fine right up until a new money source
 * ships and nobody decides. `App\Support\ChangeImpact` is the register; this is its teeth.
 *
 * The four checks, in order of what they catch:
 *
 *   A. **Completeness** — a new posting source, or a new column on one, cannot ship unclassified.
 *   B. **The refusals are real** — every REFUSED field is dirtied on a COMMITTED fixture and must
 *      throw. Not "a guard exists": the guard fires. Delete an `updating` hook and this goes red.
 *   C. **Nothing a journalizer reads is called neutral** — derived textually from the journalizer
 *      sources, so a mis-classification is caught by the code rather than by a reviewer.
 *   D. **A posting-date column is never neutral** — the column that decides an entry's period is
 *      the one field that can never be GL-irrelevant. Cross-checked against
 *      `LedgerRealtimeSync::SOURCE_DATE_COLUMNS`, the registry that declares it.
 *
 * @see docs/accounting/CHANGE-IMPACT-PLAN.md
 */

// ─────────────────────────── A. Completeness ───────────────────────────

it('classifies exactly the models that post to the ledger', function () {
    $posting = LedgerPoster::sources();
    sort($posting);
    $classified = ChangeImpact::sources();
    sort($classified);

    expect($classified)->toBe(
        $posting,
        'Every GL posting source needs a change policy, and only they do. Add or remove the entry '
        .'in App\Support\ChangeImpact::POLICY.',
    );
});

it('classifies every fillable field of every posting source, exactly once', function () {
    $problems = [];

    foreach (ChangeImpact::sources() as $model) {
        $fillable = (new $model)->getFillable();

        foreach ($fillable as $field) {
            if (ChangeImpact::verdictFor($model, $field) === null) {
                $problems[] = class_basename($model).".{$field} is unclassified";
            }
        }

        // The reverse direction catches a rename: a policy entry for a column that no longer
        // exists is a rule guarding nothing, and reads identically to a working one.
        foreach (ChangeImpact::VERDICTS as $verdict) {
            foreach (ChangeImpact::fields($model, $verdict) as $field) {
                if (! in_array($field, $fillable, true)) {
                    $problems[] = class_basename($model).".{$field} is classified {$verdict} but is not fillable";
                }
            }
        }

        // One verdict per field. A field in two blocks means two people decided differently.
        $seen = [];
        foreach (ChangeImpact::VERDICTS as $verdict) {
            foreach (ChangeImpact::fields($model, $verdict) as $field) {
                $seen[$field] = ($seen[$field] ?? 0) + 1;
            }
        }
        foreach (array_filter($seen, fn ($n) => $n > 1) as $field => $n) {
            $problems[] = class_basename($model).".{$field} carries {$n} verdicts";
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});

it('records a reason for every decided field, and states when each document commits', function () {
    $problems = [];

    foreach (ChangeImpact::sources() as $model) {
        if (blank(ChangeImpact::POLICY[$model]['committed'] ?? null)) {
            $problems[] = class_basename($model).' does not say when it becomes committed';
        }

        foreach ([ChangeImpact::REFUSED, ChangeImpact::DERIVED, ChangeImpact::PROSPECTIVE, ChangeImpact::DESCRIPTIVE] as $verdict) {
            foreach (ChangeImpact::fields($model, $verdict) as $field) {
                if (blank(ChangeImpact::reasonFor($model, $field))) {
                    $problems[] = class_basename($model).".{$field} ({$verdict}) has no reason";
                }
            }
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});

// ────────────────────── B. The refusals actually refuse ──────────────────────

/**
 * A committed instance of each source that declares REFUSED fields, in the state its `committed`
 * sentence describes. Only these sources need a fixture: a model with nothing to refuse has
 * nothing for this check to prove.
 *
 * Kept in the test rather than in `ChangeImpact` because a registry is a policy statement and
 * should not carry factories.
 *
 * @return array<class-string, callable(): \Illuminate\Database\Eloquent\Model>
 */
function committedFixtures(): array
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
                'reason' => 'goodwill',
                'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
            ]);
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
    ];
}

/** A different, type-appropriate value for a field — enough to make the record dirty. */
function mutatedValue(\Illuminate\Database\Eloquent\Model $model, string $field): mixed
{
    $current = $model->getAttribute($field);

    return match (true) {
        $current instanceof \DateTimeInterface => \Illuminate\Support\Carbon::instance($current)->copy()->subMonth(),
        is_bool($current) => ! $current,
        is_numeric($current) => (float) $current + 7,
        // An unset or non-scalar field: an integer is a valid dirty value for both an FK and a
        // string column, and the guard fires on isDirty() rather than on the value.
        default => is_string($current) ? $current.'-changed' : 424242,
    };
}

it('has a committed fixture for every source that refuses a field', function () {
    $needFixture = array_values(array_filter(
        ChangeImpact::sources(),
        fn ($model) => ChangeImpact::refusedFields($model) !== [],
    ));

    expect(array_keys(committedFixtures()))->toEqualCanonicalizing(
        $needFixture,
        'A source that declares REFUSED fields needs a committed fixture here, or nothing proves '
        .'its refusals fire. A source with no REFUSED fields must not have one.',
    );
});

it('refuses every field it says it refuses, on a committed record', function () {
    $problems = [];

    foreach (committedFixtures() as $model => $make) {
        foreach (ChangeImpact::refusedFields($model) as $field) {
            $record = $make();

            // `lease_id` on a credit note may be bound ONCE from null; the refusal only applies to
            // re-homing an already-scoped note, which is the state the fixture is in.
            $record->setAttribute($field, mutatedValue($record, $field));

            try {
                $record->save();
                $problems[] = class_basename($model).".{$field} is classified REFUSED but the write succeeded";
            } catch (DomainException) {
                // Correct — the guard fired.
            }
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});

it('still allows a committed record to change a field it does NOT refuse', function () {
    // The control. Without it every refusal above would pass just as happily if `save()` threw
    // for some unrelated reason — a broken fixture reads exactly like a working guard.
    $invoice = committedFixtures()[Invoice::class]();
    $invoice->notes = 'chased by phone';
    $invoice->save();

    expect($invoice->fresh()->notes)->toBe('chased by phone');

    $bill = committedFixtures()[VendorBill::class]();
    $bill->description = 'February deep clean';
    $bill->save();

    expect($bill->fresh()->description)->toBe('February deep clean');
});

// ───────────────── C. Nothing a journalizer reads is neutral ─────────────────

it('never classifies as neutral a field its journalizer reads', function () {
    $problems = [];

    foreach (LedgerPoster::JOURNALIZERS as $model => $journalizer) {
        $source = file_get_contents((new ReflectionClass($journalizer))->getFileName());
        preg_match_all('/->([a-zA-Z_][a-zA-Z0-9_]*)/', $source, $matches);

        $fillable = (new $model)->getFillable();
        $read = array_intersect($fillable, array_unique($matches[1]));

        foreach ($read as $field) {
            $verdict = ChangeImpact::verdictFor($model, $field);
            if ($verdict === ChangeImpact::NEUTRAL) {
                $problems[] = class_basename($model).".{$field} is classified NEUTRAL but "
                    .class_basename($journalizer).' reads it';
            }
        }

        // And the reverse: a field claimed DESCRIPTIVE must actually appear in the journalizer,
        // or the claim is decoration on a field that is simply neutral.
        foreach (ChangeImpact::fields($model, ChangeImpact::DESCRIPTIVE) as $field) {
            if (! in_array($field, $read, true)) {
                $problems[] = class_basename($model).".{$field} is classified DESCRIPTIVE but "
                    .class_basename($journalizer).' never reads it';
            }
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
})->note(
    'Textual, so it under-reports: a field read through a helper (VendorBill::isPostable) or a '
    .'relation is invisible here. That is safe — the check only ever says "this IS read, so it '
    .'cannot be neutral", never "this is not read, so it must be".'
);

// ──────────────── D. A posting-date column is never neutral ────────────────

it('never classifies a source\'s posting-date column as neutral or prospective', function () {
    $problems = [];

    foreach (LedgerRealtimeSync::SOURCE_DATE_COLUMNS as $model => $column) {
        $verdict = ChangeImpact::verdictFor($model, $column);

        // A date column that is not fillable is set by the system and cannot be operator-typed,
        // which is a stronger guarantee than any verdict — skip it rather than demand a policy.
        if (! in_array($column, (new $model)->getFillable(), true)) {
            continue;
        }

        if (! in_array($verdict, [ChangeImpact::REFUSED, ChangeImpact::DERIVED], true)) {
            $problems[] = class_basename($model).".{$column} decides the entry's accounting period "
                ."but is classified ".($verdict ?? 'nothing');
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});
