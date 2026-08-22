<?php

/*
| PARKED with the ETA freeze (2026-08-22). `Modules::enabled('eta')` now answers false
| unconditionally from `App\Support\Modules::FROZEN`, so these surfaces cannot be reached from any
| test either — the module flag is no longer a settings row a test can flip.
|
| Skipped rather than deleted, because the code they cover is intact and this is the coverage that
| proves it still works the day module 16 resumes: delete the `eta` entry from `Modules::FROZEN` and
| these go green again unchanged. The invisibility they used to assert the other way round is now
| `tests/Feature/Regression/EtaIsFrozenAndInvisibleTest.php`.
*/

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Jobs\SubmitInvoiceToEta;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| Regression guard: the ETA submit actions must QUEUE the submission (async job)
| rather than call EtaSubmissionService::submit() synchronously in the request —
| a slow/live ETA gateway must never block the admin UI (audit D6).
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    // ETA is postponed/off by default — this suite exercises the feature, so
    // enable the module for these tests.
    $settings = app(ModulesSettings::class);
    $settings->eta = true;
    $settings->save();
});

it('queues the ETA submission instead of submitting synchronously (single action)', function () {
    Queue::fake();

    $asset = makeAsset();
    $invoice = makeInvoice(makeLease(makeUnit($asset)), [
        'status' => 'issued', 'total' => 1000, 'balance' => 1000,
    ]);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($invoice) {
        Livewire::test(ListInvoices::class)
            ->callTableAction('submitToEta', $invoice)
            ->assertHasNoTableActionErrors();
    });

    Queue::assertPushed(SubmitInvoiceToEta::class, fn ($job) => $job->invoice->is($invoice));
})->skip('ETA is frozen (App\Support\Modules::FROZEN) — this surface cannot render. Unfreeze the module to run it.');

it('queues each invoice on the bulk ETA action (skipping already-valid ones)', function () {
    Queue::fake();

    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $toSubmit = makeInvoice($lease, ['status' => 'issued', 'total' => 1000, 'balance' => 1000]);
    $alreadyValid = makeInvoice($lease, ['status' => 'issued', 'total' => 500, 'balance' => 500, 'eta_status' => 'valid']);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($toSubmit, $alreadyValid) {
        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('bulkSubmitToEta', [$toSubmit, $alreadyValid])
            ->assertHasNoTableBulkActionErrors();
    });

    // Only the non-valid invoice is queued.
    Queue::assertPushed(SubmitInvoiceToEta::class, 1);
    Queue::assertPushed(SubmitInvoiceToEta::class, fn ($job) => $job->invoice->is($toSubmit));
})->skip('ETA is frozen (App\Support\Modules::FROZEN) — this surface cannot render. Unfreeze the module to run it.');
