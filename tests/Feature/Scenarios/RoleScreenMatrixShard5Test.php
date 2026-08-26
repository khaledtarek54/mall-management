<?php

/**
 * Shard 5 of the role × screen matrix — see EveryRoleMeetsEveryScreenTest for what it proves and
 * Tests\Support\RoleMatrix for the scaffolding and for why there are five of these.
 */

use Tests\Support\RoleMatrix;

it('answers every screen with a 200 or a 403 for its share of the roles', function () {
    RoleMatrix::assertShard($this, 5);
});
