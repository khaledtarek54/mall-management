<?php

/*
|--------------------------------------------------------------------------
| The notification centre — the full history behind the bell
|--------------------------------------------------------------------------
| The bell is a peek: a dropdown two lines high, and once an entry scrolls out
| of it there is no way back to what it said. That is a problem for the alerts
| this system raises — "contract notice deadline passed, it auto-renews" is
| something somebody needs to find again a week later — and it is the ONLY
| destination for the notifications that have no record to open (a department
| message; an announcement or a violation notice read by a tenant, whose panel
| has no resource for either).
|
| What is asserted here is the part that could leak: the page renders exactly
| the reader's own notifications and nothing else, on both panels, and the
| read/unread machinery cannot be pointed at somebody else's row.
*/

use App\Filament\Admin\Pages\NotificationCenter as AdminCentre;
use App\Filament\Portal\Pages\NotificationCenter as PortalCentre;
use App\Notifications\DepartmentMessageNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\WorkOrderAssignedNotification;
use App\Notifications\WorkOrderSlaBreachedNotification;
use App\Support\NotificationLink;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    NotificationLink::flushCache();

    $this->asset = makeAsset(['code' => 'CENTRE']);
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant(['name' => 'Haya Cafe']);
    $this->lease = makeLease($this->unit, $this->tenant);
    $this->invoice = makeInvoice($this->lease);

    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->portalUser = makeTenantUser($this->tenant, isAdmin: true);
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('renders for an operator inside the selected property', function () {
    $this->actingAs($this->operator);

    $this->get(AdminCentre::getUrl(panel: 'admin', tenant: $this->asset))
        ->assertSuccessful()
        ->assertSee(__('admin.notifications.centre.page_title'));
});

it('renders for a tenant with no property segment at all', function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs($this->portalUser, 'portal');

    $this->get(PortalCentre::getUrl(panel: 'portal'))->assertSuccessful();
});

it('shows the reader their own alerts and nobody else\'s', function () {
    $colleague = makeUser('accounting', [$this->asset->id]);

    $this->operator->notify(new DepartmentMessageNotification('Roof crew at 6am.', 'Facilities'));
    $colleague->notify(new DepartmentMessageNotification('Payroll cut-off moved.', 'Finance'));

    $this->actingAs($this->operator);

    Livewire::test(AdminCentre::class)
        ->assertCanSeeTableRecords($this->operator->notifications)
        ->assertCanNotSeeTableRecords($colleague->notifications);
});

it('does not show one tenant the alerts of another', function () {
    $rival = makeTenantUser(makeTenant(['name' => 'Rival Co']), isAdmin: true);

    $this->portalUser->notify(new InvoiceIssuedNotification($this->invoice));
    $rival->notify(new DepartmentMessageNotification('Not yours.', 'Facilities'));

    // A portal component mounted without its panel resolves the DEFAULT panel's guard, i.e. the
    // admin one — which is the same class of mistake this whole change is about.
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs($this->portalUser, 'portal');

    Livewire::test(PortalCentre::class)
        ->assertCanSeeTableRecords($this->portalUser->notifications)
        ->assertCanNotSeeTableRecords($rival->notifications);
});

it('marks an alert read, and counts down the badge', function () {
    $this->operator->notify(new InvoiceIssuedNotification($this->invoice));
    $row = $this->operator->notifications()->first();

    $this->actingAs($this->operator);
    expect(AdminCentre::getNavigationBadge())->toBe('1');

    Livewire::test(AdminCentre::class)->callTableAction('toggle_read', $row);

    expect($row->refresh()->read_at)->not->toBeNull();
    // Blank rather than "0" — a badge that always shows is a badge nobody reads.
    expect(AdminCentre::getNavigationBadge())->toBeNull();
});

it('marks every alert read in one action', function () {
    foreach (range(1, 3) as $ignored) {
        $this->operator->notify(new DepartmentMessageNotification('ping', 'Facilities'));
    }

    $this->actingAs($this->operator);

    Livewire::test(AdminCentre::class)->callAction('mark_all_read');

    expect($this->operator->unreadNotifications()->count())->toBe(0);
});

it('refuses to touch a notification addressed to somebody else', function () {
    // The table query is already scoped to the reader, but the record key arrives from the browser.
    // The ownership check is the gate; the query is only what produced the row.
    $colleague = makeUser('accounting', [$this->asset->id]);
    $colleague->notify(new DepartmentMessageNotification('Not yours.', 'Finance'));
    $theirs = $colleague->notifications()->first();

    $this->actingAs($this->operator);

    $this->operator->notify(new DepartmentMessageNotification('Mine.', 'Facilities'));
    $mine = $this->operator->notifications()->first();

    $centre = new AdminCentre;
    $owns = new ReflectionMethod($centre, 'owns');
    $owns->setAccessible(true);

    expect($owns->invoke($centre, $theirs))->toBeFalse();
    // Paired with a control: a refusal passes just as happily when the check refuses everything.
    expect($owns->invoke($centre, $mine))->toBeTrue();
});

it('does not offer a link back to the page you are already reading', function () {
    // A notification with no record falls back to the centre's own URL. Rendering that as "Open"
    // inside the centre would be a link to the current page.
    $this->operator->notify(new DepartmentMessageNotification('Lift 3 is out.', 'Facilities'));
    $row = $this->operator->notifications()->first();

    $this->actingAs($this->operator);

    $centre = new AdminCentre;
    $link = new ReflectionMethod($centre, 'linkUrl');
    $link->setAccessible(true);

    expect($row->data['actions'][0]['url'])->toContain('/notifications');
    expect($link->invoke($centre, $row))->toBeNull();
});

it('groups alerts by what they are about rather than by class name', function () {
    // Four separate notification classes concern a work order. A reader hunting for "that SLA
    // thing" wants the work-order group, not a menu of thirty-six PHP class names.
    $this->actingAs($this->operator);

    $centre = new AdminCentre;
    $subject = new ReflectionMethod($centre, 'subjectLabel');
    $subject->setAccessible(true);

    expect($subject->invoke($centre, WorkOrderSlaBreachedNotification::class))
        ->toBe($subject->invoke($centre, WorkOrderAssignedNotification::class));

    expect($subject->invoke($centre, DepartmentMessageNotification::class))
        ->toBe(__('admin.notifications.centre.subject_other'));
});
