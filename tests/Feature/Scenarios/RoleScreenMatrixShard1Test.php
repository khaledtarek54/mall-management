<?php

/**
 * Shard 1 of the role × screen matrix — see EveryRoleMeetsEveryScreenTest for what it proves and
 * Tests\Support\RoleMatrix for the scaffolding. Sharded because the whole matrix is 14 roles ×
 * 99 screens of real HTTP requests: one case took 255s, and Pest parallelises per FILE, so it
 * would have set the floor under the entire suite on its own.
 */

use Tests\Support\RoleMatrix;

it('answers every screen with a 200 or a 403 for its share of the roles', function () {
    RoleMatrix::assertShard($this, 1);
});
