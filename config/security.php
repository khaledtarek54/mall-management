<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Force two-factor setup for these roles
    |--------------------------------------------------------------------------
    |
    | Admin users holding any of these roles are forced through TOTP two-factor
    | setup before they can use the panel. Defaults to super_admin only (so the
    | demo/test logins keep working); production should add the write-capable
    | roles via SECURITY_FORCE_2FA_ROLES, e.g.:
    |
    |   SECURITY_FORCE_2FA_ROLES="super_admin,manager,accounting,leasing,operations,hr"
    |
    */

    'force_2fa_roles' => array_filter(array_map(
        'trim',
        explode(',', (string) env('SECURITY_FORCE_2FA_ROLES', 'super_admin'))
    )),

];
