<?php

use Database\Seeders\RolesPermissionsSeeder;

/**
 * `/handbook` serves the built site to signed-in staff, and to nobody else.
 *
 * The route reads files off disk by a path taken from the URL, which is two risks at once — who may
 * read it, and what they may reach. Both are asserted here, and the traversal case is asserted with
 * a REAL file outside the build directory, because a test that only tries `../nonexistent` passes
 * whether the guard exists or not.
 */
beforeEach(function () {
    $this->root = storage_path('app/handbook');

    if (! is_dir("{$this->root}/money")) {
        mkdir("{$this->root}/money", 0755, true);
    }

    file_put_contents("{$this->root}/index.html", '<h1>Atriom Visual Handbook</h1>');
    file_put_contents("{$this->root}/map.html", '<h1>The whole system</h1>');
    file_put_contents("{$this->root}/money/index.html", '<h1>The money spine</h1>');
});

it('refuses an anonymous reader', function () {
    // It documents posting rules and internal controls. A redirect to the login, not a 200.
    $this->get('/handbook/')->assertRedirect();
    $this->get('/handbook/map')->assertRedirect();
});

it('serves the handbook to a signed-in user', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    // The three shapes the site actually produces: the root, a `cleanUrls` page (map -> map.html),
    // and a directory (money/ -> money/index.html).
    //
    // Asserted on the RESOLVED FILE rather than with assertSee. The route answers with a
    // BinaryFileResponse, whose body is empty until it is sent — so `assertSee` compares against
    // '' and a test written that way fails even when the route is perfect. Checking which file was
    // chosen is also the stronger assertion: it is exactly what the path resolution decides.
    foreach ([
        '/handbook/' => 'index.html',
        '/handbook/map' => 'map.html',
        '/handbook/money/' => 'money/index.html',
    ] as $url => $expected) {
        $response = $this->get($url);

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('text/html');
        expect($response->baseResponse->getFile()->getPathname())
            ->toBe(realpath("{$this->root}/{$expected}"), "{$url} resolved to the wrong file");
    }
});

it('refuses to walk out of the build directory', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    // A real file, outside the build dir, that a traversal would otherwise reach. Asserting
    // against something that does not exist would pass with no guard at all.
    $outside = storage_path('app/handbook-secret.html');
    file_put_contents($outside, 'SHOULD NEVER BE SERVED');

    try {
        foreach ([
            '../handbook-secret.html',
            '..%2Fhandbook-secret.html',
            'money/../../handbook-secret.html',
        ] as $attempt) {
            $response = $this->get('/handbook/'.$attempt);

            expect($response->status())->not->toBe(200, "Traversal reached a file outside the build: {$attempt}");
            $response->assertDontSee('SHOULD NEVER BE SERVED');
        }
    } finally {
        @unlink($outside);
    }
});

it('serves only the file types the build actually emits', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    // A `.php` inside the build directory must not be readable through here — an open-ended
    // content-type map is how a stray file becomes a download.
    file_put_contents("{$this->root}/stray.php", '<?php echo "nope";');

    try {
        $this->get('/handbook/stray.php')->assertNotFound();
    } finally {
        @unlink("{$this->root}/stray.php");
    }
});

it('says the handbook is unbuilt rather than pretending the page is missing', function () {
    // "The handbook is missing" and "that page does not exist" send whoever sees it to completely
    // different places, and the cause is almost always a deploy that skipped `npm run docs:build`.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    $moved = storage_path('app/handbook-moved-by-test');
    rename($this->root, $moved);

    try {
        $this->get('/handbook/')->assertStatus(503);
    } finally {
        rename($moved, $this->root);
    }
});
