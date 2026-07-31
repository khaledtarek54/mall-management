<?php

/*
|--------------------------------------------------------------------------
| Money records cannot be deleted — enforced, not remembered
|--------------------------------------------------------------------------
| Operator decision, 2026-07-31: a financial document is a record of something that happened, not a
| row to remove. Under Egyptian bookkeeping (and ETA e-invoicing once live) an issued invoice is a
| legal record — you cancel it or credit-note it, and the trail survives.
|
| What it replaces: `Invoice`, `Payment` and `JournalEntry` had NO deletion guard at all (only
| CreditNote and MeterReading did), and all eight money resources offered Delete on their Edit page
| — two of them ForceDelete, which destroys the row outright. Soft deletes meant nothing vanished,
| but a super_admin could delete a PAID invoice: the tenant's AR simply changed, with no
| cancellation, no reason, and nothing to show an auditor.
|
| It also replaces a coverage gap. `DeleteAuthorizationScenarioTest` checks delete authorization
| against a HAND-MAINTAINED list of 10 resources — there are 41. This scans instead, so resource
| #42 cannot quietly ship a Delete button on a money record.
*/

use App\Support\DeletionPolicy;
use Illuminate\Support\Facades\DB;

/* ---- the model layer: the backstop behind the missing button -------------- */

it('guards every never-deletable model at the model layer', function () {
    // Removing the button stops the operator. The trait stops a console command, a future service,
    // or a relation manager someone adds next year.
    $unguarded = [];

    foreach (array_keys(DeletionPolicy::NEVER_DELETABLE) as $model) {
        if (! in_array(App\Models\Concerns\RefusesDeletionOfCommittedRecords::class, class_uses_recursive($model), true)) {
            $unguarded[] = class_basename($model);
        }
    }

    expect($unguarded)->toBe([], implode('', [
        'These are on the books but can still be deleted from code: '.implode(', ', $unguarded).'. ',
        'Add the RefusesDeletionOfCommittedRecords trait.',
    ]));
});

it('names a correction path for every never-deletable model', function () {
    // "You cannot delete this" without "do X instead" just moves the operator's problem.
    $missing = [];

    foreach (DeletionPolicy::NEVER_DELETABLE as $model => $correction) {
        if (trim($correction) === '') {
            $missing[] = class_basename($model);
        }
    }

    expect($missing)->toBe([], 'no correction path stated for: '.implode(', ', $missing));
});

/* ---- the UI layer --------------------------------------------------------- */

it('exposes no delete action on any money resource', function () {
    // Derived from DeletionPolicy::NEVER_DELETABLE, not a directory list someone remembers to
    // extend: every Filament resource whose model is never-deletable is scanned — resolved by its
    // own ::getModel(), wherever the resource lives — so resource #42 built on a money record
    // cannot quietly ship a Delete button under a name this test didn't anticipate.
    $offenders = [];

    $resources = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament/Admin/Resources'))
    );

    foreach ($resources as $file) {
        if ($file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));

        if (! class_exists($class) || ! is_subclass_of($class, Filament\Resources\Resource::class)) {
            continue;
        }

        if (! DeletionPolicy::isNeverDeletable($class::getModel())) {
            continue;
        }

        // A money resource — scan its whole tree (the Resource, its Pages/, Tables/, Schemas/).
        // DeleteAction and ForceDeleteAction; RestoreAction is fine — bringing a soft-deleted
        // record back destroys nothing.
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($file->getPathname())));

        foreach ($tree as $inner) {
            if ($inner->getExtension() !== 'php') {
                continue;
            }

            foreach (file($inner->getPathname()) as $no => $line) {
                $code = trim($line);

                if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*')) {
                    continue;
                }

                if (preg_match('/\b(Force)?DeleteAction::make\(/', $code)) {
                    $offenders[] = str_replace(base_path().'/', '', $inner->getPathname()).':'.($no + 1);
                }
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['A money resource offers deletion:'],
        array_map(fn (string $v): string => '  - '.$v, $offenders),
        ['', 'Correct these documents through their own workflow (cancel / void / credit note).'],
    )));
});

it('has retired the delete permissions rather than leaving them grantable', function () {
    // A surviving permission row still appears in the Roles screen, so an administrator could
    // grant `invoices.delete` and reasonably expect it to mean something.
    $this->seed(Database\Seeders\RolesPermissionsSeeder::class);

    $surviving = DB::table('permissions')
        ->whereIn('name', DeletionPolicy::RETIRED_PERMISSIONS)
        ->pluck('name')
        ->all();

    expect($surviving)->toBe([], 'these delete permissions are still seeded: '.implode(', ', $surviving));
});

it('seeds no grantable delete permission for any never-deletable model', function () {
    // The teeth the hardcoded RETIRED_PERMISSIONS list can't grow on its own. That const stays a
    // concrete record of what was retired (the retire migration deletes exactly those rows); THIS
    // derives the invariant straight from NEVER_DELETABLE, so a money model added later — whose
    // scaffold seeds `{model}.{action}` by the standard plural-snake convention — is caught even if
    // nobody remembers to extend RETIRED_PERMISSIONS.
    $this->seed(Database\Seeders\RolesPermissionsSeeder::class);

    $seeded = DB::table('permissions')->pluck('name')->all();
    $live = [];

    foreach (array_keys(DeletionPolicy::NEVER_DELETABLE) as $model) {
        $permission = str(class_basename($model))->plural()->snake()->toString().'.delete';

        if (in_array($permission, $seeded, true)) {
            $live[] = $permission;
        }
    }

    expect($live)->toBe([], implode('', [
        'A never-deletable model still has a grantable delete permission: '.implode(', ', $live).'. ',
        'Drop it from RolesPermissionsSeeder and add it to DeletionPolicy::RETIRED_PERMISSIONS so the migration retires it.',
    ]));
});

/* ---- the behaviour, end to end -------------------------------------------- */

it('refuses to delete an issued invoice, and says what to do instead', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    expect(fn () => $invoice->delete())->toThrow(DomainException::class);

    expect(App\Models\Invoice::withTrashed()->whereKey($invoice->id)->first()->deleted_at)
        ->toBeNull('the invoice must still be there, not soft-deleted');
});

it('refuses to delete a received payment', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $payment = App\Models\Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'currency' => 'EGP', 'method' => 'bank_transfer', 'status' => 'captured',
        'payment_date' => now(),
    ]);

    expect(fn () => $payment->delete())->toThrow(DomainException::class);
});

it('still allows rolling back a payment that never became money', function () {
    // CreatePayment deletes the orphan row it just created when allocation fails. A blanket
    // refusal would break payment creation itself — the guard is about COMMITTED records.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $orphan = App\Models\Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'currency' => 'EGP', 'method' => 'bank_transfer', 'status' => 'initiated',
        'payment_date' => now(),
    ]);

    $orphan->delete();

    // fresh() ignores the soft-delete scope, so assert through a scoped query.
    expect(App\Models\Payment::whereKey($orphan->id)->exists())->toBeFalse()
        ->and(App\Models\Payment::withTrashed()->whereKey($orphan->id)->first()->deleted_at)->not->toBeNull();
});

it('still allows discarding a draft journal entry but refuses a posted one', function () {
    // A draft was never posted, so nothing is on the books yet. Anything posted is permanent —
    // correct it with a reversing entry. This carve-out matters: EditJournalEntry deliberately
    // allowed deleting drafts, and a blanket refusal would have removed a real workflow.
    $draft = App\Models\JournalEntry::create([
        'number' => 'JE-'.uniqid(), 'entry_date' => now(), 'status' => 'draft', 'is_manual' => true,
    ]);
    $draft->delete();

    expect(App\Models\JournalEntry::whereKey($draft->id)->exists())->toBeFalse();

    $posted = App\Models\JournalEntry::create([
        'number' => 'JE-'.uniqid(), 'entry_date' => now(), 'status' => 'posted', 'is_manual' => true,
    ]);

    expect(fn () => $posted->delete())->toThrow(DomainException::class);
});

/* ---- Tier B: master data with history ------------------------------------- */

it('guards every when-unused model at the model layer', function () {
    $unguarded = [];

    foreach (array_keys(DeletionPolicy::WHEN_UNUSED) as $model) {
        if (! in_array(App\Models\Concerns\RefusesDeletionWhenReferenced::class, class_uses_recursive($model), true)) {
            $unguarded[] = class_basename($model);
        }
    }

    expect($unguarded)->toBe([], 'missing RefusesDeletionWhenReferenced: '.implode(', ', $unguarded));
});

it('declares only relations that actually exist', function () {
    // The one that matters most. A mistyped relation name blocks NOTHING and looks exactly like a
    // working guard — the registry would claim a tenant's invoices are checked while the guard
    // silently skipped them. Same class of silent failure as everything else corrected this week.
    $bogus = [];

    foreach (DeletionPolicy::WHEN_UNUSED as $model => $config) {
        $instance = new $model;

        foreach ($config['blocked_by'] as $relation) {
            if (! method_exists($instance, $relation)) {
                $bogus[] = class_basename($model)."::{$relation}()";

                continue;
            }

            // Exists, but is it a relation? A scope or accessor would blow up at delete time.
            if (! $instance->{$relation}() instanceof Illuminate\Database\Eloquent\Relations\Relation) {
                $bogus[] = class_basename($model)."::{$relation}() is not a relation";
            }
        }
    }

    expect($bogus)->toBe([], 'DeletionPolicy names relations that do not exist: '.implode(', ', $bogus));
});

it('states what to do instead for every when-unused model', function () {
    $missing = [];

    foreach (DeletionPolicy::WHEN_UNUSED as $model => $config) {
        if (trim($config['instead'] ?? '') === '') {
            $missing[] = class_basename($model);
        }
    }

    expect($missing)->toBe([], 'no deactivation path stated for: '.implode(', ', $missing));
});

it('refuses to delete a tenant that has history, and names what is holding it', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    makeInvoice($lease, ['status' => 'issued']);

    expect(fn () => $tenant->delete())->toThrow(DomainException::class);

    // The message has to be actionable — an operator who cannot find the blocker works around it.
    try {
        $tenant->delete();
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('invoice')
            ->and($e->getMessage())->toContain('inactive');
    }

    expect(App\Models\Tenant::whereKey($tenant->id)->exists())->toBeTrue();
});

it('still deletes a tenant that has no history at all', function () {
    // A record typed in by mistake must stay removable, or the operator's lists fill with rubbish
    // they cannot clear — which is how people end up wanting a delete button that ignores history.
    $tenant = makeTenant();

    $tenant->delete();

    expect(App\Models\Tenant::whereKey($tenant->id)->exists())->toBeFalse();
});

it('counts a soft-deleted child as history', function () {
    // A cancelled invoice is exactly what an auditor asks about, so a tenant whose only invoice was
    // soft-deleted is not a clean record.
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'draft']);
    $invoice->delete();   // draft, so the money guard allows it

    expect(fn () => $tenant->delete())->toThrow(DomainException::class);
});

it('sees a unit leased only through the pivot', function () {
    // allLeases, not leases: a multi-unit lease keeps its extra units in lease_unit, so the
    // master-only relation would report a leased unit as never used. This trap has bitten three
    // times already.
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'DP-M']);
    $extra = makeUnit($asset, ['code' => 'DP-X']);
    $lease = makeLease($master, makeTenant(), ['status' => 'active']);
    $lease->units()->syncWithoutDetaching([$extra->id]);

    expect(fn () => $extra->delete())->toThrow(DomainException::class);
});

/* ---- completeness: the register must grow with the codebase ---------------- */

it('classifies every model', function () {
    // The failure this closes is the one the rest of this week was spent on: a register that only
    // covers what someone remembered to add. The first version of THIS file listed 15 models out
    // of 77 and enforced nothing about the other 62 — the same shape as the delete test covering
    // 10 of 41 resources, and "the first eight" authz test.
    //
    // "Nobody classified this yet" and "we decided this is fine" look identical from outside.
    // Only one of them is a money record waiting to be deletable by accident.
    $classified = array_merge(
        array_keys(DeletionPolicy::NEVER_DELETABLE),
        array_keys(DeletionPolicy::WHEN_UNUSED),
        array_keys(DeletionPolicy::ALLOWED),
    );

    $unclassified = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $model = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($model) || ! is_subclass_of($model, Illuminate\Database\Eloquent\Model::class)) {
            continue;
        }

        if ((new ReflectionClass($model))->isAbstract() || in_array($model, $classified, true)) {
            continue;
        }

        $unclassified[] = class_basename($model);
    }

    sort($unclassified);

    expect($unclassified)->toBe([], implode("\n", array_merge(
        ['These models are not classified in App\\Support\\DeletionPolicy:'],
        array_map(fn (string $m): string => '  - '.$m, $unclassified),
        [
            '',
            'Decide which register it belongs in:',
            '  NEVER_DELETABLE — money or audit record; name the correction path instead',
            '  WHEN_UNUSED     — master data; name the relations that count as history',
            '  ALLOWED         — safe to delete; say WHY (parent-managed / configuration / operational)',
            '',
            'Check for a real deletion call site in app/ before choosing NEVER — guarding a row that',
            'a service legitimately deletes breaks the workflow rather than protecting it.',
        ],
    )));
});

it('classifies each model exactly once', function () {
    // A model in two registers is an unresolved disagreement, and which one wins depends on
    // lookup order — i.e. on nothing anybody decided.
    $all = array_merge(
        array_keys(DeletionPolicy::NEVER_DELETABLE),
        array_keys(DeletionPolicy::WHEN_UNUSED),
        array_keys(DeletionPolicy::ALLOWED),
    );

    $duplicates = array_keys(array_filter(array_count_values($all), fn (int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'classified in more than one register: '.implode(', ', array_map('class_basename', $duplicates)));
});

it('states a reason for every allowed model', function () {
    // "ALLOWED => ''" is an unclassified model wearing a badge.
    $blank = [];

    foreach (DeletionPolicy::ALLOWED as $model => $reason) {
        if (trim($reason) === '') {
            $blank[] = class_basename($model);
        }
    }

    expect($blank)->toBe([], 'no reason given for: '.implode(', ', $blank));
});
