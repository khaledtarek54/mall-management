<?php

/**
 * Shard 2 of the admin filter sweep — see AllFiltersSweepTest for what this proves
 * and Tests\Support\FilterSweep::assertAdminShard() for why it is split across files.
 *
 * Do not add or remove a shard file without changing FilterSweep::ADMIN_SHARDS to match;
 * AllFiltersSweepTest fails if the two disagree, because a stale count would silently
 * stop sweeping whole pages.
 */

use Database\Seeders\RolesPermissionsSeeder;
use Tests\Support\FilterSweep;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('runs every filter on its share of the admin tables, over real demo data', function () {
    FilterSweep::assertAdminShard($this, 2);
});
