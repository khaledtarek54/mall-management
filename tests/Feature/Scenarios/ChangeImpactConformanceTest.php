<?php

use App\Filament\Actions\ReversalReasonField;
use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\Warehouse;
use App\Services\Accounting\LedgerPoster;
use App\Services\VendorBillService;
use App\Support\ChangeImpact;
use App\Support\FieldHelp;
use App\Support\LedgerRealtimeSync;
use App\Support\Reversals;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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
 * @return array<class-string, callable(): Model>
 */
/** One employee for the custody/advance fixtures — named for this file, per the helper-uniqueness gate. */
function impactEmployee(): Employee
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
                // A registered classification: credit_notes.reason is a ValueSets column.
                'reason' => 'discount',
                'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
            ]);
        },

        Expense::class => function () {
            // `recorded` is the state an expense is BORN in — there is no draft — so this fixture
            // is a plain create, which is also the only way the system makes one.
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
            $bill = committedFixtures()[VendorBill::class]();

            // Through the service, not a bare create: a fixture that sets columns no writer sets
            // proves the guard against a state the system cannot reach.
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
            // APPROVED, not draft — a draft run is meant to be correctable, and the whole point of
            // the guard is that approval is the line.
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
            // SETTLED against, because that — not the grant — is when a عهدة's terms lock. A fixture
            // built on grant alone would prove a refusal in a state the app deliberately leaves open.
            $custody = Custody::create([
                'employee_id' => impactEmployee()->id,
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
            // A bare grant, not the settled Custody fixture — that one already carries a transaction
            // and this must build its own subject.
            $custody = Custody::create([
                'employee_id' => impactEmployee()->id,
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
                'employee_id' => impactEmployee()->id,
                'asset_id' => makeAsset()->id,
                'type' => 'advance',
                'amount' => 10000,
                'advance_date' => now()->toDateString(),
                'paid_from' => 'bank',
            ]);
        },

        EmployeeAdvanceRepayment::class => function () {
            $advance = committedFixtures()[EmployeeAdvance::class]();

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

/** A different, type-appropriate value for a field — enough to make the record dirty. */
function mutatedValue(Model $model, string $field): mixed
{
    $current = $model->getAttribute($field);

    // A foreign key needs a value the DATABASE would accept, or the write is refused by the FK
    // constraint and the test cannot tell that apart from the model guard refusing it. It still
    // goes red either way — a QueryException is not caught below — but "the write succeeded" is
    // the message that names the actual defect, and a database error is not.
    if (str_ends_with($field, '_id') && $current !== null) {
        $stem = Str::beforeLast($field, '_id');

        // Two candidates, because Laravel relations are named either way: `vendor_bill_id` is
        // reached by `vendorBill()` on some models and by the shortened `bill()` on others — which
        // is exactly what VendorBillPayment does.
        $candidates = [
            Str::camel($stem),
            Str::camel(Str::afterLast($stem, '_')),
        ];

        foreach (array_unique($candidates) as $relation) {
            if (! method_exists($model, $relation)) {
                continue;
            }

            $result = $model->{$relation}();
            if (! $result instanceof BelongsTo) {
                continue;
            }

            $related = $result->getRelated();
            $sibling = $related->newQuery()->whereKeyNot($current)->value($related->getKeyName());

            // No sibling exists in this test's data — fall back to the bogus id below. The check
            // still fails when the guard is gone; only the message is less direct.
            if ($sibling !== null) {
                return $sibling;
            }
        }
    }

    return match (true) {
        $current instanceof DateTimeInterface => Carbon::instance($current)->copy()->subMonth(),
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
                // Correct — the model guard fired, which is the thing being proved.
            } catch (QueryException) {
                // The DATABASE refused it — a foreign key, or a CHECK constraint on an enum-ish
                // column. The write did not land, so the books are safe, but the model guard is
                // NOT proved: it may be missing entirely and this would look identical. Reported
                // as its own outcome rather than swallowed, because a refusal from the wrong layer
                // carries no correction path — the operator gets a database error instead of
                // "cancel and re-enter".
                $problems[] = class_basename($model).".{$field} was refused by the database, not by "
                    .'the model guard — its REFUSED verdict is unproved';
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
                .'but is classified '.($verdict ?? 'nothing');
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});

// ────────────── C. Every source can be undone, and the undo records why ──────────────

/**
 * **The twenty-fifth question a money source must answer.** Every other registry in this project
 * asks whether a new source is CLASSIFIED; none asked whether it can be UNDONE. The 2026-08-28
 * sweep found the answer varied without anyone having decided it varied — 13 of 24 had a named
 * reversal, 5 of those recorded a reason, and `MarketingSpend` offered a bare Delete button on a
 * document that posts to the general ledger.
 *
 * Lives here rather than in a new file because this test already walks all 24 sources, and a second
 * file walking the same list is a second list to keep in step.
 */
it('classifies every posting source as reversible or deliberately not', function () {
    expect(Reversals::classified())->toEqualCanonicalizing(
        LedgerPoster::sources(),
        'Every GL source must name the act that undoes it in App\Support\Reversals::ACTS, or say in '
        .'NO_REVERSAL why it has none. A source in neither is one nobody decided about; a source in '
        .'the registry that no longer posts is a stale entry.',
    );
});

it('names a reversal act that actually exists in the panel', function () {
    // The act NAME, not a service method — which is the distinction §12.4 turned on:
    // `WriteOffInvoiceService::reverse()` was built, tested, and reachable from no button at all,
    // so a registry pointing at services would have called that source reversible.
    $source = collect(File::allFiles(app_path('Filament')))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->map(fn ($f) => $f->getContents())
        ->implode("\n");

    $missing = [];
    foreach (Reversals::ACTS as $model => $act) {
        if (! str_contains($source, "Action::make('{$act}')")
            && ! str_contains($source, "'{$act}'")) {
            $missing[] = class_basename($model)." names '{$act}', which no action in app/Filament declares";
        }
    }

    expect($missing)->toBe([], "\n".implode("\n", $missing));
});

it('records a reason on every reversal', function () {
    // An audit control, not a preference: Yardi, MRI and Entrata all require a reason code on a
    // reversal, and it is the first thing an auditor asks for. Asserted structurally — every act
    // must reach `ReversalReason::record()` somewhere, whether from its service or its action.
    $source = collect(File::allFiles([app_path('Services'), app_path('Filament')]))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->map(fn ($f) => $f->getContents())
        ->implode("\n");

    // **A THRESHOLD, and it proves a weaker property than its name suggests** — say so rather than
    // let a future reader assume otherwise. It catches the seam being abandoned wholesale; it does
    // NOT prove that act X records a reason, because an act name cannot be tied to the service
    // method it calls by reading text (four drafts of the method-level reachability gate above are
    // the evidence for that). What DOES hold per act is the field: every one asks for a reason
    // through the same factory, asserted directly below.
    expect(substr_count($source, 'ReversalReason::record('))
        ->toBeGreaterThanOrEqual(
            10,
            'Reversal reasons are recorded through App\Support\ReversalReason so they land in the '
            .'audit trail rather than in an editable `notes` column. A sharp drop here means an act '
            .'went back to discarding the reason.',
        );
});

it('offers the reason field from one place, so it cannot drift', function () {
    // `->required()` missing on ONE action is the silent failure this factory exists to prevent: an
    // optional reason nobody notices is optional until the reversal that matters has an empty one.
    $field = ReversalReasonField::make();

    expect($field->isRequired())->toBeTrue()
        ->and($field->getMaxLength())->toBe(FieldHelp::REVERSAL_REASON_MAX_LENGTH);
});

it('shows what the document did to the books, wherever the document has a screen', function () {
    // The third assertion of the twenty-fifth question, and it was promised before it was written.
    // CHANGE-IMPACT-PLAN §6.1 built `LedgerEntryAction` and mounted it on five tables; D4 extended it
    // to nine Edit headers and said "all 24 sources" — which was never literally reachable, because
    // twelve of the 24 have no admin screen at all. Six that DO had neither, so the one question a
    // DERIVED ledger makes an operator ask — "what happened to my entry?" — had no answer on the
    // stock register, the owner-statement runs, the disbursements, the custody transactions, the
    // marketing spends or the employee advances.
    //
    // Derived from **Filament's own registry**, not from a list kept here: a gate that reads only the
    // registry it guards cannot see what that registry omits, which is the failure
    // `CatalogueWidensItsColumnsConformanceTest` exists for. A source with no resource is exempt by
    // BEING one — there is nothing to mount the panel on.
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $missing = [];

    foreach (LedgerPoster::sources() as $model) {
        $resource = rescue(fn () => Filament::getModelResource($model), null, false);

        if (! $resource) {
            continue;
        }

        $dir = dirname((new ReflectionClass($resource))->getFileName());

        $mounted = collect(File::allFiles($dir))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->contains(fn ($f) => str_contains($f->getContents(), 'LedgerEntryAction::make()'));

        if (! $mounted) {
            $missing[] = class_basename($model).' posts to the general ledger and has a screen, but no '
                .'LedgerEntryAction — an operator cannot see what the document did to the books.';
        }
    }

    expect($missing)->toBe([], "\n".implode("\n", $missing));
});
