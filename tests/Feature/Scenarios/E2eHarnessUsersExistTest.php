<?php

use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\File;

/**
 * **Every login the E2E harness performs must be a user that exists.**
 *
 * `tests/e2e/global-setup.js` signs in as ten demo users and saves a session for each. A single one
 * of those addresses being wrong does not fail one spec — **global setup throws, and Playwright
 * runs no tests at all**.
 *
 * That is exactly what happened. The 2026-08-15 "maintenance is not an identifier" rename moved the
 * operations user to `operations@mall.test` and swept `app/`; `ROLE_USERS` kept
 * `maintenance@mall.test`, an address that has not existed since. So the browser suite was not
 * advisory-and-passing, it was **dead** — and the failure reads as
 * `page.waitForFunction: Timeout 30000ms exceeded`, which looks like a slow page rather than a
 * missing account. It cost this session two wrong diagnoses (login throttling, then a stale session
 * file) before anyone read the address.
 *
 * Nothing caught it because the E2E suite is not in the push loop, CI is paused, and a suite that
 * cannot start produces no red — it produces nothing, which is indistinguishable from not having run
 * it. A gate in the PHP suite is the only place this gets noticed, so it lives here.
 *
 * Quoted string literals only, which is why the comment recording the old address one line above the
 * fix does not trip this — it names it in backticks.
 */
it('signs in as users that actually exist', function () {
    $this->seed(DatabaseSeeder::class);

    $harness = base_path('tests/e2e/global-setup.js');
    $helpers = base_path('tests/e2e/helpers.js');

    expect(File::exists($harness))->toBeTrue('The E2E global setup has moved; this gate is pointing at nothing.');

    $source = File::get($harness).File::get($helpers);

    preg_match_all("/'([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})'/", $source, $matches);

    $addresses = array_values(array_unique($matches[1]));

    // The sweep must find the logins before it reports on them — a regex that quietly stops matching
    // is this codebase's most repeated gate failure, and it has shipped three times.
    expect(count($addresses))->toBeGreaterThan(8);

    $missing = [];

    foreach ($addresses as $address) {
        // Both auth surfaces: `/admin` is a `User`, `/portal` is a `TenantUser`. Checking only the
        // first would report the portal login as missing on every run.
        $exists = User::query()->where('email', $address)->exists()
            || TenantUser::query()->where('email', $address)->exists();

        if (! $exists) {
            $missing[] = $address;
        }
    }

    expect($missing)->toBe([], implode("\n  ", array_merge(
        ['The E2E harness signs in as these addresses and the seeders create no such user.',
            'Global setup throws on the first one, so the ENTIRE browser suite runs zero tests:'],
        $missing,
    )));
});
