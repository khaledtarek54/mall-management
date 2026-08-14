<?php

use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\File;

/**
 * `/handbook` serves the built site to signed-in staff, and to nobody else.
 *
 * The route reads files off disk by a path taken from the URL, which is two risks at once — who may
 * read it, and what they may reach. Both are asserted here, and the traversal case is asserted with
 * a REAL file outside the build directory, because a test that only tries `../nonexistent` passes
 * whether the guard exists or not.
 *
 * **These fixtures live in a throwaway directory, and that is load-bearing.** Until 2026-08-14 this
 * file wrote them into `storage/app/handbook` — the REAL build — so every run of the suite replaced
 * the operator's handbook with three one-line stubs. `/admin/handbook` then rendered a bare
 * `<h1>Atriom Visual Handbook</h1>` on a white page, and stayed that way until somebody re-ran
 * `npm run build`, which is why it kept coming back. A worse variant sat in the 503 case: it
 * RENAMED the real directory and renamed it back in a `finally`, so a crash or an interrupted run
 * left the handbook parked under `handbook-moved-by-test`.
 *
 * A test that breaks the feature it is testing passes every time while doing it. The route now
 * reads `config('handbook.root')`, so the fixtures point somewhere that is not the build and the
 * real one is never touched.
 */
beforeEach(function () {
    // Unique per test: the suite runs ten parallel workers, and a shared fixture directory would
    // have them deleting each other's files.
    $this->root = storage_path('app/handbook-test-'.bin2hex(random_bytes(6)));

    File::ensureDirectoryExists("{$this->root}/money");

    config(['handbook.root' => $this->root]);

    file_put_contents("{$this->root}/index.html", '<h1>Atriom Visual Handbook</h1>');
    file_put_contents("{$this->root}/map.html", '<h1>The whole system</h1>');
    file_put_contents("{$this->root}/money/index.html", '<h1>The money spine</h1>');
});

afterEach(function () {
    File::deleteDirectory($this->root);
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
    // '' and a test written that way fails even when the route is perfect.
    foreach ([
        '/handbook/' => "{$this->root}/index.html",
        '/handbook/map' => "{$this->root}/map.html",
        '/handbook/money/' => "{$this->root}/money/index.html",
    ] as $url => $expected) {
        $response = $this->get($url)->assertOk();

        expect(realpath($response->baseResponse->getFile()->getPathname()))
            ->toBe(realpath($expected), "GET {$url} served the wrong file");
    }
});

it('refuses to walk out of the build directory', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    // A real file, outside the build dir, that a traversal would otherwise reach. Asserting
    // against something that does not exist would pass with no guard at all.
    $outside = storage_path('app/handbook-secret-'.bin2hex(random_bytes(4)).'.html');
    file_put_contents($outside, 'SHOULD NEVER BE SERVED');

    try {
        foreach ([
            '../'.basename($outside),
            '..%2F'.basename($outside),
            'money/../../'.basename($outside),
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

    $this->get('/handbook/stray.php')->assertNotFound();
});

it('says the handbook is unbuilt rather than pretending the page is missing', function () {
    // "The handbook is missing" and "that page does not exist" send whoever sees it to completely
    // different places, and the cause is almost always a deploy that skipped `npm run docs:build`.
    //
    // Pointed at a path that was never built, rather than moving the real directory out of the way
    // and back — which is what the earlier version did, and which loses the operator's handbook if
    // the run is interrupted between the two renames.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('viewer'));

    config(['handbook.root' => storage_path('app/handbook-never-built-'.bin2hex(random_bytes(4)))]);

    $this->get('/handbook/')->assertStatus(503);
});

it('never lets a test write into the REAL built handbook', function () {
    // The gate for the bug this file caused. Fixtures belong in a throwaway directory; a test that
    // reaches for the build directory replaces the operator's handbook with whatever it writes, and
    // passes while doing it — so it is invisible until somebody opens /admin/handbook and finds a
    // white page. It happened, it survived every green run, and the only reason it was found is
    // that a human noticed the page was blank.
    //
    // Greps rather than reasons: the property is "no test names the real path", which is exactly
    // what a search can answer.
    $offenders = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // The build root named literally — but NOT the `-test-` / `-never-built-` suffixed roots
        // this file uses, which is why the quote must close immediately after the directory name.
        // (Written without spelling the pattern out in prose, so this gate does not flag itself.)
        if (preg_match("#storage_path\(\s*['\"]app/handbook['\"]#", $contents)
            || preg_match("#['\"]app/handbook/#", $contents)) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These tests write to (or read) the REAL built handbook directory:'],
        $offenders,
        ['', 'Point them at a throwaway root instead: config(["handbook.root" => $tmp]).'],
    )));
});
