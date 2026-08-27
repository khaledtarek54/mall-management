<?php

/**
 * Shard 2 of the RESTRICTED-operator filter sweep — see AllFiltersSweepTest for the partition
 * guard and Tests\Support\FilterSweep::assertRestrictedShard() for what it proves and why it is a
 * different operator from the four AdminFilterSweepShard files.
 *
 * Do not add or remove a shard file without changing FilterSweep::RESTRICTED_SHARDS to match;
 * AllFiltersSweepTest fails if the two disagree, because a stale count would silently stop
 * sweeping whole pages.
 */

use Database\Seeders\RolesPermissionsSeeder;
use Tests\Support\FilterSweep;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('runs every admin filter for a property-scoped operator', function () {
    FilterSweep::assertRestrictedShard($this, 2);
});
