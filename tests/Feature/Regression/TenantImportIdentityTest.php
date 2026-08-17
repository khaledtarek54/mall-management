<?php

use App\Filament\Imports\TenantImporter;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;

/**
 * Re-running a tenant import must not duplicate the roster.
 *
 * `resolveRecord()` keyed on `tenants.email` — a column that is **nullable and carries no unique
 * index**. So every email-less tenant was unrecognisable on a second pass and got a fresh row.
 * Re-running an import is the normal response to a partial one, and the duplicates are not
 * harmless: each acquires its own leases and invoices, splitting one retailer's AR across two
 * records that can no longer be merged, because `RefusesDeletionWhenReferenced` correctly refuses
 * to delete either once it has history.
 *
 * Identity is now `tax_id` first — the identifier the operator's records and the tax authority
 * both use, one per company — with `email` as the fallback.
 */
beforeEach(function () {
    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'tenants.csv',
        'file_path' => 'tenants.csv',
        'importer' => TenantImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importTenantRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new TenantImporter(test()->import, $columnMap, []))($row);
}

it('recognises a tenant by tax registration on re-import', function () {
    importTenantRow(['name' => 'Brand Egypt', 'tax_id' => '123-456-789', 'email' => null]);
    importTenantRow(['name' => 'Brand Egypt LLC', 'tax_id' => '123-456-789', 'email' => null]);

    // Before this, two email-less rows were two tenants — and Egyptian retailers routinely have a
    // TRN and no email on the operator's roster.
    expect(Tenant::count())->toBe(1)
        ->and(Tenant::first()->name)->toBe('Brand Egypt LLC');
});

it('still recognises a tenant by email when there is no tax registration', function () {
    importTenantRow(['name' => 'Small Shop', 'email' => 'shop@brand.test']);
    importTenantRow(['name' => 'Small Shop Renamed', 'email' => 'shop@brand.test']);

    expect(Tenant::count())->toBe(1)
        ->and(Tenant::first()->name)->toBe('Small Shop Renamed');
});

it('prefers tax registration over email when both are present', function () {
    importTenantRow(['name' => 'Brand', 'tax_id' => '123-456-789', 'email' => 'old@brand.test']);
    // The operator corrected the contact address; it is the same company.
    importTenantRow(['name' => 'Brand', 'tax_id' => '123-456-789', 'email' => 'new@brand.test']);

    expect(Tenant::count())->toBe(1)
        ->and(Tenant::first()->email)->toBe('new@brand.test');
});

it('creates separate tenants for genuinely different companies', function () {
    // The paired control: dedup that merges everything would pass the tests above.
    importTenantRow(['name' => 'Brand A', 'tax_id' => '111-111-111']);
    importTenantRow(['name' => 'Brand B', 'tax_id' => '222-222-222']);

    expect(Tenant::count())->toBe(2);
});
