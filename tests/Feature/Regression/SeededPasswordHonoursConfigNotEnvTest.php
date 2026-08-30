<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The seeded demo password must actually be the one the operator set
|--------------------------------------------------------------------------
| Both seeders read `env('DEMO_USER_PASSWORD', 'password')` directly. `env()` returns NULL for
| everything once `php artisan config:cache` has run — a cached config means the `.env` file is
| never loaded — and `deploy.sh` caches the config on every release. So on EVERY deployed box the
| seeders fell back to the literal string `password`, whatever the operator had configured.
|
| That is precisely the control `.env.example` and STATUS §1.3 tell an operator to set BEFORE the
| URL is shareable. Measured on the staging box 2026-08-30: DEMO_USER_PASSWORD was set to a
| 20-character random string, the box was reseeded, and `admin@mall.test` — a super_admin, on a
| publicly reachable hostname — authenticated with `password`.
|
| The failure was silent in both directions: nothing warned, and LearningSeeder's console output
| PRINTED the word "password" unconditionally, so the one thing that could have revealed it instead
| confirmed the wrong answer.
*/

use App\Models\User;
use Database\Seeders\LearningSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

it('gives seeded accounts the configured password, not the shipped default', function () {
    config(['demo.user_password' => 'a-deliberately-set-password']);

    $this->seed(LearningSeeder::class);

    $admin = User::where('email', 'admin@mall.test')->first();

    expect($admin)->not->toBeNull();
    expect(Hash::check('a-deliberately-set-password', $admin->password))->toBeTrue();

    // The control that makes the assertion above mean something: the shipped default must NOT
    // also work, or the test would pass on exactly the bug it exists to catch.
    expect(Hash::check('password', $admin->password))->toBeFalse();
});

it('reads the password from config, never env(), anywhere under database/seeders', function () {
    // The general shape, not just this one call site: `env()` outside a config file is null on
    // every box that has run config:cache, which is every deployed box. Config files are the one
    // place it is guaranteed to work, because they are what gets cached.
    $offenders = [];

    foreach (File::allFiles(base_path('database/seeders')) as $file) {
        $source = $file->getContents();

        // Strip comments first — this file's own docblock quotes env() and would flag itself.
        $stripped = implode('', array_map(
            fn (array|string $t): string => is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1])
                : $t,
            token_get_all($source)
        ));

        if (preg_match('/(?<![_a-zA-Z0-9$>])env\s*\(/', $stripped)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe(
        [],
        'These seeders call env(), which returns null on any box that has run config:cache: '
        .implode(', ', $offenders)
    );
});
