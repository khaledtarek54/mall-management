<?php

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Support\MorphMap;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

/* ───────── Vendor + relations + slug autogen ───────── */

it('Vendor auto-generates a unique slug on create', function () {
    $v1 = Vendor::create(['name' => 'Acme Cleaning', 'type' => 'service_provider', 'status' => 'active']);
    $v2 = Vendor::create(['name' => 'Acme Cleaning', 'type' => 'service_provider', 'status' => 'active']);

    expect($v1->slug)->toBe('acme-cleaning');
    expect($v2->slug)->toBe('acme-cleaning-2');
});

it('Vendor honors an explicit slug', function () {
    $v = Vendor::create(['name' => 'X', 'slug' => 'custom-slug', 'type' => 'service_provider', 'status' => 'active']);
    expect($v->slug)->toBe('custom-slug');
});

it('Vendor exposes contacts(), contracts(), primaryContact(), activeContractsCount()', function () {
    $v = Vendor::create(['name' => 'V', 'type' => 'service_provider', 'status' => 'active']);

    VendorContact::create(['vendor_id' => $v->id, 'name' => 'Older', 'is_primary' => false]);
    $primary = VendorContact::create(['vendor_id' => $v->id, 'name' => 'Pri', 'is_primary' => true]);

    expect($v->contacts)->toHaveCount(2);
    expect($v->primaryContact()->id)->toBe($primary->id);

    VendorContract::create([
        'vendor_id' => $v->id, 'asset_id' => $this->asset->id,
        'reference' => 'VC-1', 'name' => 'Janitorial', 'status' => 'active',
        'start_date' => now(), 'end_date' => now()->addYear(),
        'value' => 10000, 'currency' => 'EGP',
    ]);
    VendorContract::create([
        'vendor_id' => $v->id, 'asset_id' => $this->asset->id,
        'reference' => 'VC-2', 'name' => 'Old', 'status' => 'expired',
        'start_date' => now()->subYears(2), 'end_date' => now()->subYear(),
        'value' => 5000, 'currency' => 'EGP',
    ]);

    expect($v->activeContractsCount())->toBe(1);
});

it('Vendor.primaryContact falls back to oldest when nothing is flagged primary', function () {
    $v = Vendor::create(['name' => 'V', 'type' => 'service_provider', 'status' => 'active']);
    $first = VendorContact::create(['vendor_id' => $v->id, 'name' => 'A']);
    VendorContact::create(['vendor_id' => $v->id, 'name' => 'B']);

    expect($v->primaryContact()->id)->toBe($first->id);
});

it('VendorContract belongs to vendor + asset', function () {
    $v = Vendor::create(['name' => 'V', 'type' => 'service_provider', 'status' => 'active']);
    $c = VendorContract::create([
        'vendor_id' => $v->id, 'asset_id' => $this->asset->id,
        'reference' => 'VC-Z', 'name' => 'Z', 'status' => 'active',
        'start_date' => now(), 'value' => 1000, 'currency' => 'EGP',
    ]);
    expect($c->vendor->id)->toBe($v->id);
    expect($c->asset->id)->toBe($this->asset->id);
});

/* ───────── Note (polymorphic) ───────── */

it('Note morphs to a noteable and belongs to author', function () {
    $user = User::create(['name' => 'Op', 'email' => 'op@t.test', 'password' => bcrypt('x')]);

    $note = Note::create([
        'noteable_type' => get_class($this->tenant),
        'noteable_id' => $this->tenant->id,
        'author_id' => $user->id,
        'channel' => 'whatsapp',
        'subject' => 'Reminded about overdue',
        'body' => 'Promised to pay by EOD.',
        'contacted_at' => now(),
    ]);

    expect($note->noteable->is($this->tenant))->toBeTrue();
    expect($note->author->id)->toBe($user->id);
    expect(Note::CHANNELS)->toContain('whatsapp');
});

/* ───────── CreditNoteItem ───────── */

it('CreditNoteItem belongs to a CreditNote', function () {
    $cn = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'issue_date' => now(),
        'status' => 'issued',
        'reason' => 'discount',
        'subtotal' => 500, 'vat_amount' => 0,
        'total' => 500, 'applied_amount' => 0, 'currency' => 'EGP',
    ]);
    $item = CreditNoteItem::create([
        'credit_note_id' => $cn->id,
        'description' => 'Refund',
        'amount' => 500, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 500,
    ]);

    expect($item->creditNote->id)->toBe($cn->id);
    expect((float) $item->amount)->toBe(500.0);
});

/* ───────── TenantRequestComment ───────── */

it('TenantRequestComment relates to request + polymorphic author', function () {
    $user = User::create(['name' => 'U', 'email' => 'u@t.test', 'password' => bcrypt('x')]);
    $mr = TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id,
        'title' => 'AC', 'description' => 'broken',
        'status' => 'submitted', 'priority' => 'high', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);
    $c = TenantRequestComment::create([
        'tenant_request_id' => $mr->id,
        'author_type' => MorphMap::alias(User::class), 'author_id' => $user->id,
        'body' => 'Looking into it.',
        'is_internal' => true,
    ]);

    expect($c->request->id)->toBe($mr->id);
    expect($c->author->is($user))->toBeTrue();
    expect($c->is_internal)->toBeTrue();
});

/* ───────── MeterReading ───────── */

it('MeterReading belongs to UtilityMeter', function () {
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id, 'unit_id' => $this->unit->id,
        'meter_number' => 'M-'.uniqid(),
        'type' => 'water', 'unit_of_measurement' => 'm3', 'status' => 'active',
    ]);
    $reading = MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_value' => 100, 'reading_date' => now()->toDateString(),
        'consumption' => 50, 'cost' => 75,
    ]);

    expect($reading->meter->id)->toBe($meter->id);
    expect((float) $reading->consumption)->toBe(50.0);
});
