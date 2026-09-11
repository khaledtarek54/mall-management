<?php

/**
 * Regression — mobile §L L1 (drift A1). Browsing the mall does not spend the sign-in.
 *
 * Laravel keys a guest throttle on the IP alone — `sha1($domain.'|'.$ip)`: the route's PATH is not
 * in it, nor the limit, only its domain, which no route here declares — so every unnamed `throttle:` in the app spent ONE counter per address, each route
 * measuring that shared count against its own ceiling. The tightest ceiling therefore set the budget
 * for all of them: five screens of the shopper feed and the next sign-in answered 429; three wrong
 * passwords and the reset refused before it was asked; the web pay page's four-second poll spent the
 * app's sign-in. Behind one NAT — a mall's Wi-Fi, the QA office — that is one person's browsing
 * locking another out of their first sign-in. (The mobile team's live-box reading — one counter
 * falling 119 → 115 across four public routes — does not tell the two apart: those routes are one
 * group and share a counter by design. The first case below is the reading that does.)
 *
 * Every case but the last is one the shared counter FAILS. A throttled request is refused before it is
 * counted, so a shared count stops at the first ceiling it meets — each case crosses one route's
 * ceiling with another route's traffic, which is the only order that tells shared from separate. The
 * last case is the control: every counter still enforces its own limit, or a "fix" that dropped the
 * throttles would satisfy the others alone. `NoTwoThrottlesShareACounterConformanceTest` keeps the
 * counters named.
 */
it('lets a shopper sign in after browsing five screens of the mall — the feed spends its own counter', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->getJson('/api/v1/public/malls')->assertOk();
    }

    // Five was the sign-in's whole budget while the two shared a counter.
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@atriomwalk.test', 'password' => 'wrong', 'device_name' => 'probe'])
        ->assertUnauthorized();
});

it('lets a tenant ask for a reset after three wrong passwords — the sign-in spends its own counter', function () {
    $wrong = ['email' => 'nobody@atriomwalk.test', 'password' => 'wrong', 'device_name' => 'probe'];

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/login', $wrong)->assertUnauthorized();
    }

    // The realistic order: a forgotten password is found out by failing to sign in. Three was the
    // reset's whole budget, so it refused before it was asked. The answer is the generic 200 the
    // endpoint gives every address, registered or not.
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@atriomwalk.test'])->assertOk();
});

it('keeps the public pay page open after thirty screens of the feed — the pay link spends its own counter', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/public/malls')->assertOk();
    }

    // No invoice carries this token, so a 404 is the ANSWER. The shared counter refused with 429
    // before the page was asked: thirty was the pay group's whole budget.
    $this->get('/pay/'.str_repeat('0', 40))->assertNotFound();
});

it('still refuses a sixth sign-in inside the minute, and the feed stays open beside it — each counter is enforced', function () {
    $wrong = ['email' => 'nobody@atriomwalk.test', 'password' => 'wrong', 'device_name' => 'probe'];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', $wrong)->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', $wrong)->assertStatus(429);

    // The sign-in's spent counter costs the feed nothing.
    $this->getJson('/api/v1/public/malls')->assertOk();
});
