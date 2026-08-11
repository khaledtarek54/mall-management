<?php

use App\Support\OwnerVisibility;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * The owner is a counterparty, not a colleague — the gate that keeps it that way.
 *
 * `owner` used to be granted every `.view` permission in the catalogue, on the reasoning that
 * property isolation would keep it honest. It does not: sixteen models are SHARED and carry no
 * `asset_id` at all, and for payroll the property axis is the wrong question entirely. So Jawad
 * could read Eltizam's supplier register across every mall it operates, its staff accounts, its
 * salary bill and its own bank accounts.
 *
 * This gate fails the build on an unclassified module, so a new one forces the decision rather than
 * inheriting "the owner sees it" — and on a stale one, because an entry naming a module that no
 * longer exists reads as a considered decision the next person will inherit by accident.
 */
function catalogue(): array
{
    $seeder = new RolesPermissionsSeeder();
    $method = (new ReflectionClass($seeder))->getMethod('flatPermissionList');
    $method->setAccessible(true);

    return $method->invoke($seeder);
}

it('classifies every permission group as owner-visible or operator-internal', function () {
    $unclassified = OwnerVisibility::unclassified(catalogue());

    expect($unclassified)->toBe(
        [],
        'Unclassified permission group(s): '.implode(', ', $unclassified)
        .'. Add each to App\Support\OwnerVisibility::VISIBLE or ::OPERATOR_INTERNAL with a reason.'
    );
});

it('carries no classification for a module that no longer exists', function () {
    $stale = OwnerVisibility::stale(catalogue());

    expect($stale)->toBe(
        [],
        'App\Support\OwnerVisibility classifies group(s) that are not in the permission catalogue: '
        .implode(', ', $stale).'. Remove them.'
    );
});

it('never classifies a group as both visible and internal', function () {
    $both = array_intersect(
        array_keys(OwnerVisibility::VISIBLE),
        array_keys(OwnerVisibility::OPERATOR_INTERNAL),
    );

    expect($both)->toBe([]);
});

it('states a reason for every classification', function () {
    // A registry entry without a reason is a list, and a list is what this replaced.
    $blank = collect(OwnerVisibility::VISIBLE + OwnerVisibility::OPERATOR_INTERNAL)
        ->filter(fn (string $reason): bool => trim($reason) === '')
        ->keys()
        ->all();

    expect($blank)->toBe([]);
});

describe('the seeded owner role', function () {
    beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

    it('holds nothing from an operator-internal module', function () {
        $held = Role::findByName('owner')->permissions->pluck('name');

        $leaked = $held
            ->filter(fn (string $p): bool => array_key_exists(
                OwnerVisibility::group($p),
                OwnerVisibility::OPERATOR_INTERNAL,
            ))
            ->values()
            ->all();

        expect($leaked)->toBe([], 'Owner holds operator-internal permission(s): '.implode(', ', $leaked));
    });

    it('still holds the oversight it is contractually owed', function () {
        // The paired control. A refusal test passes just as happily when the role was granted
        // nothing at all, and an owner who can see nothing is a different bug, not a fix.
        $held = Role::findByName('owner')->permissions->pluck('name');

        expect($held)->toContain('leases.view')
            ->toContain('invoices.view')
            ->toContain('owner_statements.view_own')
            ->toContain('owner_requests.create')
            ->toContain('general_ledger.view')
            ->toContain('reports.download')
            // `.view_all` matters specifically: without it AssignmentScope narrows an oversight
            // role to the work assigned to them, which for an owner is nothing.
            ->toContain('maintenance.view_all');
    });

    it('specifically cannot read payroll, staff accounts, the vendor register or settings', function () {
        // The four the sweep named. Spelled out rather than left to the generic check, because
        // these are the ones an operator would be asked about in a contract dispute.
        $held = Role::findByName('owner')->permissions->pluck('name');

        expect($held)->not->toContain('payrolls.view')
            ->not->toContain('employees.view')
            ->not->toContain('users.view')
            ->not->toContain('vendors.view')
            ->not->toContain('bank_accounts.view')
            ->not->toContain('settings.view');
    });

    it('grants the owner no write authority beyond raising a request', function () {
        // Oversight, not authority. The filter only ever passes `.view`/`.view_all`, so anything
        // else getting through means the grant list grew a hole.
        $writes = Role::findByName('owner')->permissions->pluck('name')
            ->reject(fn (string $p): bool => str_ends_with($p, '.view')
                || str_ends_with($p, '.view_all')
                || str_ends_with($p, '.view_own')
                || $p === 'reports.download'
                || $p === 'owner_requests.create')
            ->values()
            ->all();

        expect($writes)->toBe([]);
    });
});
