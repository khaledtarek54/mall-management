<?php

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
});

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
});
