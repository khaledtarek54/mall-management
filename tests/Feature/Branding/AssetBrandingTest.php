<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('persists primary_color as a hex string and exposes it via fillable', function () {
    $asset = makeAsset(['primary_color' => '#0F766E']);

    expect($asset->fresh()->primary_color)->toBe('#0F766E');
});

it('registers the logo and favicon MediaLibrary collections as singleFile', function () {
    $asset = makeAsset();

    $asset->addMedia(UploadedFile::fake()->image('logo.png', 200, 80))
        ->toMediaCollection('logo');
    $asset->addMedia(UploadedFile::fake()->image('logo-v2.png', 200, 80))
        ->toMediaCollection('logo');

    expect($asset->getMedia('logo'))->toHaveCount(1);
});

it('returns null from logoUrl and faviconUrl when no media is uploaded', function () {
    $asset = makeAsset();

    expect($asset->logoUrl())->toBeNull();
    expect($asset->faviconUrl())->toBeNull();
});

it('returns the public URL from logoUrl/faviconUrl when media is uploaded', function () {
    $asset = makeAsset();

    $asset->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection('logo');
    $asset->addMedia(UploadedFile::fake()->image('favicon.png', 32, 32))->toMediaCollection('favicon');

    expect($asset->logoUrl())->toBeString()->toContain('logo.png');
    expect($asset->faviconUrl())->toBeString()->toContain('favicon.png');
});

it('includes primary_color in the activity-log allow-list', function () {
    $asset = makeAsset();
    $options = $asset->getActivitylogOptions();

    expect($options->logAttributes)->toContain('primary_color');
});
