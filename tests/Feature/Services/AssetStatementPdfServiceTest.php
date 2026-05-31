<?php

use App\Services\AssetStatementPdfService;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'HW', 'name' => 'Haya Walk']);
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->tenant = makeTenant(['name' => 'Café Crema', 'legal_name' => 'Crema Trading LLC']);
    $this->lease = makeLease($this->unit, $this->tenant);

    makeInvoice($this->lease, ['balance' => 1000, 'status' => 'issued', 'due_date' => now()->subDays(5)]);
    makeInvoice($this->lease, ['balance' => 0, 'status' => 'paid', 'paid_amount' => 5000]);
});

it('produces a PDF byte string for a real asset', function () {
    $bytes = app(AssetStatementPdfService::class)->build($this->asset);

    expect($bytes)
        ->toBeString()
        ->and(strlen($bytes))->toBeGreaterThan(2000); // mPDF output is several KB minimum

    // PDF magic header — every valid PDF starts with %PDF-
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
});

it('filenames embed the asset code and today date', function () {
    $name = app(AssetStatementPdfService::class)->filename($this->asset);

    expect($name)
        ->toStartWith('Property-Statement-hw-')
        ->toEndWith('.pdf')
        ->toContain(now()->format('Ymd'));
});

it('builds a statement for an empty property without errors', function () {
    $empty = makeAsset(['code' => 'EM', 'name' => 'Empty Mall']);

    $bytes = app(AssetStatementPdfService::class)->build($empty);

    expect($bytes)->toBeString()
        ->and(substr($bytes, 0, 5))->toBe('%PDF-');
});
