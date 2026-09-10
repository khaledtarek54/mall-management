<?php

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Exports\TenantExporter;
use App\Models\Tenant;
use App\Support\Filament\IdentifiedExport;
use App\Support\Filament\IdentifiedExportAction;
use App\Support\Filament\IdentifiedExportBulkAction;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Spatie\Permission\PermissionRegistrar;

/**
 * An exporter offering nothing that identifies a row — the one shape the rule must NOT refuse.
 * Named rather than anonymous because `Exporter` takes three constructor arguments and only its
 * class NAME is needed here.
 */
class ExporterWithNoIdentifier extends Exporter
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('status')];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return '';
    }
}

/**
 * An export has to say which rows it is about.
 *
 * Reported by the tester WITH THE FILE ATTACHED: a tenants export with only *Status* ticked — two
 * rows, both reading "active", and nothing to say which tenants they were. The file is not merely
 * thin, it is unusable; and it looks like a successful export, so the operator finds out later,
 * somewhere else.
 *
 * Every export tool worth the name guarantees a key column — Salesforce reports, NetSuite saved
 * searches and Yardi's Report Writer all carry the record's identity whether or not it was asked
 * for, because an anonymous row is not data.
 *
 * **It REFUSES rather than silently re-enabling the column.** Forcing it back on would be a submit
 * that quietly discards what the operator chose, which is the exact shape this panel has now been
 * reported for three separate times. Filament builds the column checkboxes inside its own modal
 * closure with no hook to lock one, so a refusal naming the columns that would satisfy it is the
 * honest version of "cannot be deselected".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
});

it('refuses an export with every identifying column switched off', function () {
    // The tester's file exactly: Status on, everything that names a tenant off.
    IdentifiedExport::assertIdentified(TenantExporter::class, [
        'status' => ['isEnabled' => true, 'label' => 'Status'],
        'name' => ['isEnabled' => false, 'label' => 'Name'],
        'code' => ['isEnabled' => false, 'label' => 'Code'],
    ]);
})->throws(DomainException::class);

it('accepts the same export once one identifying column is on', function () {
    // The control. The refusal above passes just as happily on a rule that refuses everything.
    IdentifiedExport::assertIdentified(TenantExporter::class, [
        'status' => ['isEnabled' => true, 'label' => 'Status'],
        'name' => ['isEnabled' => true, 'label' => 'Name'],
        'code' => ['isEnabled' => false, 'label' => 'Code'],
    ]);

    expect(true)->toBeTrue();
});

it('names the columns that would satisfy it, in the operator s own labels', function () {
    // A refusal that does not say what to do next is a dead end. It quotes the LABELS from the
    // modal, because the operator renamed them there and would not recognise the column names.
    try {
        IdentifiedExport::assertIdentified(TenantExporter::class, [
            'status' => ['isEnabled' => true, 'label' => 'Status'],
            'name' => ['isEnabled' => false, 'label' => 'Trading name'],
        ]);
        $message = null;
    } catch (DomainException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('Trading name')
        // Translated, not a raw key: this is the app talking to a person.
        ->and($message)->not->toContain('admin.refusals');
});

it('offers id LAST, so the rule is satisfied by something a person can read', function () {
    // `id` identifies a row to the database and is the least useful of these on a spreadsheet, so it
    // counts but is never what the message suggests first.
    $offered = IdentifiedExport::offeredBy(TenantExporter::class);

    expect($offered)->not->toBeEmpty()
        ->and(array_slice(IdentifiedExport::IDENTIFIERS, -1))->toBe(['id']);
});

it('stays quiet for an exporter that offers no identifying column at all', function () {
    // That is a question about THAT exporter, not something to block an operator's export over —
    // refusing would make the file unobtainable rather than merely unusable, which is worse.
    IdentifiedExport::assertIdentified(ExporterWithNoIdentifier::class, ['status' => ['isEnabled' => true]]);

    expect(IdentifiedExport::offeredBy(ExporterWithNoIdentifier::class))->toBe([]);
});

it('binds both export actions in the container, so every call site inherits the rule', function () {
    // Thirteen call sites across nine tables. The binding is what makes the fourteenth covered by
    // existing rather than by its author remembering — the argument that put the CRUD authorization
    // in the container too.
    expect(ExportAction::make('x'))->toBeInstanceOf(IdentifiedExportAction::class)
        ->and(ExportBulkAction::make('y'))->toBeInstanceOf(IdentifiedExportBulkAction::class);
});

it('leaves the tenants list export mounted and usable', function () {
    // The screen the card was filed from still works — a rule that broke the button would satisfy
    // every refusal above.
    asTenant(makeAsset(), function () {
        Livewire\Livewire::test(ListTenants::class)
            ->mountAction('export')
            ->assertHasNoActionErrors();
    });
});
