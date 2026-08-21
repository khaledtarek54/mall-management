<?php

return [
    /*
    |--------------------------------------------------------------------------
    | The tenant portal's own words
    |--------------------------------------------------------------------------
    | The portal otherwise borrows the `admin.*` catalogue, because a retailer and an operator read
    | the same invoice fields and duplicating them would be two words for one column. What belongs
    | here is what only the portal says — starting with its name, which was an untranslated English
    | literal in the panel provider until EG-22.
    |
    | It is a FALLBACK, not the title: a tenant trading in one mall sees that mall's name instead.
    */
    'brand' => 'Atriom · Tenant Portal',
];
