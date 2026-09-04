<?php

/**
 * **"Who did this?" is the audit trail's most-asked question, and the portfolio-wide screen could
 * not answer it.**
 *
 * The feed is read in two places. The per-record **Activities** tab has carried a causer filter
 * since it shipped. The **Activity log** page — the portfolio-wide one, the one an auditor is
 * actually sent to, and the only one that DELIVERS a scheduled CSV — offered `log_name`, `event`
 * and two date controls, and nothing at all for the person. Measured on the dev database
 * 2026-09-04: 473 rows, 32 of them carrying a causer, and no control on that screen could narrow
 * to them.
 *
 * There is no search box to fall back on either, and that is correct rather than an oversight:
 * every column on both tables is derived at READ time — the event word, the subject's label, the
 * rendered change set — so no stored text exists for a `LIKE` to reach, which is why the tab says
 * `->searchable(false)` out loud. Typing into the causer picker IS the search here, against the
 * folded `search_text` blob, which is why the control is an `EntitySelectFilter` and not a bare
 * `Select`.
 *
 * **The subtle half is the morph.** `causer` is a `morphTo`, and a causer is not always a `User`:
 * a contractor accepting a job through the vendor portal is a `VendorContact`
 * (`AcceptWorkOrderService::accept()` takes a bare `Model` and is called with
 * `VendorScope::contact()`). `users` and `vendor_contacts` are independent id sequences — measured
 * on the dev database, `users` holds ids 1-6 and `tenant_users` holds id 1 — so the obvious
 * `where('causer_id', $id)` returns another table's rows under the name of the person the operator
 * picked. On an audit trail that is the worst available failure: it does not look empty, it looks
 * answered. `App\Support\Filament\CauserFilter` is the one definition both screens read, so the
 * type clause cannot be present on one and forgotten on the other.
 *
 * Every exclusion below is paired with a control that must still be visible — a filter that
 * returned nothing would satisfy the refusals on its own and read as a pass.
 */

use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Support\MorphMap;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // Build the fixture with the trail switched OFF, so the feed under test holds exactly the rows
    // this file wrote on purpose. Seeding roles, minting an asset and creating users are all
    // audited acts; left on, they put a dozen rows of scaffolding in front of the assertions and
    // make "only Sara's rows came back" depend on a page size.
    activity()->disableLogging();

    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Two named operators, so "whose row is this?" has a wrong answer available to give.
    // `makeUser()` names everyone after their role, which would make both read alike in the CSV.
    $named = function (string $name): User {
        $user = makeUser('super_admin');
        $user->update(['name' => $name]);

        return $user->fresh();
    };

    $this->sara = $named('Sara Fahmy');
    $this->omar = $named('Omar Naguib');

    // A contractor, filed under the SAME id as Sara. `AcceptWorkOrderService` writes exactly this
    // causer shape from the vendor portal, so the type is real; the id is PINNED rather than left
    // to the sequence because the collision is the whole point — left to chance, a type-blind
    // filter would exclude the contractor's row by accident and the morph test would pass while
    // proving nothing.
    $vendor = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $contact = VendorContact::create(['vendor_id' => $vendor->id, 'name' => 'Hani Contractor']);
    DB::table('vendor_contacts')->where('id', $contact->id)->update(['id' => $this->sara->id]);
    $this->contact = VendorContact::findOrFail($this->sara->id);

    $this->actingAs($this->sara);
    Filament::setTenant($this->asset);

    activity()->enableLogging();

    $this->saraRow = activity('tenant')->causedBy($this->sara)->event('updated')->log('tenant.updated');
    $this->omarRow = activity('invoice')->causedBy($this->omar)->event('created')->log('invoice.created');
    $this->contractorRow = activity('facility_work_order')
        ->causedBy($this->contact)->event('accepted')->log('facility_work_order.accepted');
});

afterEach(function () {
    activity()->enableLogging();
    Filament::setTenant(null, isQuiet: true);
});

it('has a fixture whose ids really do collide across the two causer tables', function () {
    // The premise every morph assertion below rests on. Without it those tests would be green for
    // a reason that has nothing to do with the clause under test.
    expect($this->contact->id)->toBe($this->sara->id)
        ->and($this->contractorRow->causer_type)->toBe(MorphMap::alias(VendorContact::class))
        ->and($this->saraRow->causer_type)->toBe(MorphMap::alias(User::class));
});

it('narrows the portfolio-wide audit trail to one person', function () {
    // The control: unfiltered, the screen shows everybody. If it did not, the refusal below would
    // be measuring an empty table.
    Livewire::test(ActivityLog::class)
        ->assertCanSeeTableRecords([$this->saraRow, $this->omarRow]);

    Livewire::test(ActivityLog::class)
        ->filterTable('causer_id', $this->sara->id)
        ->assertCanSeeTableRecords([$this->saraRow])
        ->assertCanNotSeeTableRecords([$this->omarRow]);
});

it('does not answer with rows from another table that share an id', function () {
    // The morph tooth. Sara and the contractor carry the SAME causer_id; only the type tells them
    // apart, and only if the filter thinks to ask.
    Livewire::test(ActivityLog::class)
        ->filterTable('causer_id', $this->sara->id)
        ->assertCanSeeTableRecords([$this->saraRow])
        ->assertCanNotSeeTableRecords([$this->contractorRow]);
});

it('carries the answer into the scheduled CSV, not just onto the screen', function () {
    // `reportCsv()` exports `getFilteredTableQuery()`, so mounting the filter is what makes the
    // delivered file answer the saved question. Column 1 is Who — see ActivityLog::reportCsv().
    $unfiltered = Livewire::test(ActivityLog::class)->instance()->reportCsv();

    expect(collect($unfiltered['rows'])->pluck(1)->all())
        ->toContain('Sara Fahmy')
        ->toContain('Omar Naguib');

    $filtered = Livewire::test(ActivityLog::class)
        ->filterTable('causer_id', $this->sara->id)
        ->instance()
        ->reportCsv();

    expect($filtered['rows'])->not->toBeEmpty()
        ->and(collect($filtered['rows'])->pluck(1)->unique()->values()->all())->toBe(['Sara Fahmy']);
});

it('answers the same way on the record tab as on the portfolio page', function () {
    // One definition, two screens. The tab had the filter first; what must not happen is the two
    // drifting apart, so the morph tooth is asked of the tab as well. A hand-rolled copy on either
    // side that forgets `causer_type` turns this red.
    $tenant = makeTenant();

    $tabSaraRow = activity('tenant')
        ->performedOn($tenant)->causedBy($this->sara)->event('updated')->log('tenant.updated');
    $tabContractorRow = activity('tenant')
        ->performedOn($tenant)->causedBy($this->contact)->event('updated')->log('tenant.updated');

    Livewire::test(ActivitiesRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->filterTable('causer_id', $this->sara->id)
        ->assertCanSeeTableRecords([$tabSaraRow])
        ->assertCanNotSeeTableRecords([$tabContractorRow]);
});

it('offers the filter on both screens rather than on whichever one was remembered', function () {
    $pageFilters = Livewire::test(ActivityLog::class)->instance()->getTable()->getFilters();

    $tabFilters = Livewire::test(ActivitiesRelationManager::class, [
        'ownerRecord' => makeTenant(),
        'pageClass' => EditTenant::class,
    ])->instance()->getTable()->getFilters();

    // The same NAME on both, so a saved view and a query string mean the same thing wherever the
    // link is opened, and the same entity so both search the one folded blob.
    expect($pageFilters)->toHaveKey('causer_id')
        ->and($tabFilters)->toHaveKey('causer_id')
        ->and($pageFilters['causer_id']->getEntityModel())->toBe(User::class)
        ->and($tabFilters['causer_id']->getEntityModel())->toBe(User::class);
});

it('still shows the whole feed when nobody has been picked', function () {
    // The blank branch. A filter whose query ran unconditionally would empty the screen for every
    // operator who never touched it — the failure that gets reported as "the audit log is broken".
    Livewire::test(ActivityLog::class)
        ->assertCanSeeTableRecords([$this->saraRow, $this->omarRow, $this->contractorRow]);
});
