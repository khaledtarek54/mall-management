<?php

/**
 * Two dashboard charts that showed a number and offered no way to ask "which ones?", and an audit
 * trail that could not be exported.
 *
 * The roadmap listed AR-ageing, tenant-mix and top-tenants as static. Two of the three were stale:
 * AR-ageing gained its link earlier today, and **top-tenants has always been drillable** — it is a
 * TableWidget with a `recordUrl()` to the lease. Only tenant-mix was really a dead end.
 *
 * The activity log is the one log that earns a scheduled delivery: "who changed what, and when" is
 * a compliance question somebody is periodically asked to evidence, and evidencing it should not
 * depend on remembering to open a screen.
 */

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Widgets\TenantMix;
use App\Filament\Admin\Widgets\TopTenants;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/* ---- the chart is a doorway ---------------------------------------------- */

it('gives the tenant-mix chart a way through to the units behind it', function () {
    $description = (string) (new TenantMix)->getDescription();

    expect($description)
        ->toContain(__('admin.widgets.tenant_mix.drilldown'))
        ->toContain('href=')
        // The explanation survives — the link is added, not substituted for it.
        ->toContain(__('admin.widgets.tenant_mix.description'));
});

it('renders that link as HTML rather than printing the tag', function () {
    // `getDescription(): ?string` would print the anchor as visible text. Htmlable is what makes
    // Blade emit it — the same trap the AR-ageing chart had.
    expect((new TenantMix)->getDescription())->toBeInstanceOf(Htmlable::class);
});

it('confirms top-tenants was already drillable, so nobody rebuilds it', function () {
    // The roadmap said static. It is not: the table carries a recordUrl to the lease. Pinned so the
    // claim cannot come back — and because a widget that silently loses its recordUrl looks fine.
    $rendered = Livewire::test(TopTenants::class);

    expect($rendered->instance())->toBeInstanceOf(TopTenants::class);
    expect((new ReflectionClass(TopTenants::class))->getFileName())->not->toBeNull();
    expect(file_get_contents((new ReflectionClass(TopTenants::class))->getFileName()))
        ->toContain('->recordUrl(');
});

/* ---- the audit trail can be exported and scheduled ----------------------- */

it('makes the activity log a deliverable report', function () {
    expect(is_a(ActivityLog::class, DeliverableReport::class, true))->toBeTrue();
});

it('exports the columns the screen shows, as text rather than markup', function () {
    // The Changes column renders HTML for the browser; a CSV cell carrying `<span>` would be
    // unreadable in Excel. Same renderer either way, so the emailed file says what the page said.
    activity('invoice')->event('created')->log('invoice.created');

    $csv = Livewire::test(ActivityLog::class)->instance()->reportCsv();

    expect($csv['headers'])->toHaveCount(6)
        ->and($csv['filename'])->toStartWith('activity-log-')
        ->and($csv['rows'])->not->toBeEmpty();

    foreach ($csv['rows'] as $row) {
        expect($row)->toHaveCount(6);
        foreach ($row as $cell) {
            expect((string) $cell)->not->toContain('<');
        }
    }
});

it('honours the filters the operator set rather than dumping the whole log', function () {
    // A saved view is a saved QUESTION. Exporting everything would make the delivered file quietly
    // not the report that was saved.
    activity('invoice')->event('created')->log('invoice.created');
    activity('access_control')->event('updated')->log('access_control.updated');

    $all = Livewire::test(ActivityLog::class)->instance()->reportCsv();

    $filtered = Livewire::test(ActivityLog::class)
        ->filterTable('log_name', 'access_control')
        ->instance()
        ->reportCsv();

    expect(count($all['rows']))->toBeGreaterThan(count($filtered['rows']))
        ->and($filtered['rows'])->not->toBeEmpty();
});
