<?php

use App\Models\SystemSetting;

it('stores, upserts, and retrieves a system setting (with a default)', function () {
    expect(SystemSetting::get('missing', 'fallback'))->toBe('fallback');
    expect(SystemSetting::get('missing'))->toBeNull();

    SystemSetting::put('k', 'v1');
    expect(SystemSetting::get('k'))->toBe('v1');

    SystemSetting::put('k', 'v2'); // upsert, not a duplicate row
    expect(SystemSetting::get('k'))->toBe('v2');
    expect(SystemSetting::where('key', 'k')->count())->toBe(1);
});
