<?php

use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * The self-enforcing gate on uploaded-file privacy — sibling of
 * PropertyIsolationConformanceTest, AdminSmokeManifestConformanceTest and
 * GlRegistryConformanceTest.
 *
 * WHY THIS EXISTS. medialibrary's default disk is `env('MEDIA_DISK', 'public')`. The
 * project set neither the env var nor a config override, so the default was `public` —
 * the webroot. `Lease` and `Tenant` implemented HasMedia but registered no collection,
 * so their `documents` collection inherited that default: **every signed lease contract
 * and every tenant tax card was served from a guessable, unauthenticated URL** while
 * TenantRequest three files away correctly pinned `useDisk('local')` (fixed 2026-07-16).
 *
 * The lesson isn't "Lease was wrong" — it's that the default is **fail-open**, so
 * forgetting is indistinguishable from choosing. These tests make the disk an explicit,
 * reviewed decision on every collection, and make privacy the default answer.
 *
 * If you are here because a test failed: register your collection with an explicit
 * `->useDisk('local')`. Only add to PUBLIC_COLLECTIONS if the content is genuinely
 * non-confidential AND must be reachable by URL — and say why.
 */

/**
 * Collections that are deliberately world-readable. Branding only: a property's logo and
 * favicon are rendered as plain URLs by the admin panel (Asset::logoUrl()), and there is
 * nothing confidential in a mall's logo.
 *
 * Everything else is private. This list is the whole of the exception.
 */
const PUBLIC_COLLECTIONS = [
    'App\Models\Asset' => ['logo', 'favicon'],

    // ---- Module 36: the shopper-facing feed. These three are the only collections in the system
    // whose READER is deliberately unauthenticated, which is why they may sit on the public disk.
    //
    // A marketing post's hero image and gallery are artwork the mall is actively broadcasting to
    // the street — the offer exists to be seen by people with no account. Serving them through an
    // authenticated stream would mean the visitor app cannot render its own feed.
    'App\Models\MarketingPost' => ['hero', 'gallery'],

    // A store's logo is the brand mark already on its shutter, and the same category as a
    // property's. Note Tenant's OTHER collection — `documents`, holding the commercial register
    // and tax card — stays private; that they are separate collections on one model is precisely
    // what lets the brand mark be public without the paperwork following it.
    'App\Models\Tenant' => ['logo'],
];

/** @return array<int, class-string<HasMedia>> every model implementing HasMedia */
function mediaModels(): array
{
    $models = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        if (in_array(HasMedia::class, class_implements($class) ?: [], true)) {
            $models[] = $class;
        }
    }

    return $models;
}

/** Boot a model's collections without touching the database. */
function collectionsOf(string $class): array
{
    /** @var HasMedia $model */
    $model = new $class;
    $model->registerMediaCollections();

    return $model->mediaCollections;
}

it('finds the HasMedia models it is meant to be guarding', function () {
    // A guard that silently matches nothing is worse than no guard. If this fails, the
    // discovery above broke — fix it before trusting anything below.
    expect(mediaModels())->not->toBeEmpty()
        ->and(mediaModels())->toContain(\App\Models\Lease::class, \App\Models\Tenant::class);
});

it('registers every media collection it uses', function () {
    // A HasMedia model with no registerMediaCollections() gets the fail-open default for
    // every collection it stores — exactly how Lease and Tenant leaked.
    foreach (mediaModels() as $class) {
        expect(method_exists($class, 'registerMediaCollections'))
            ->toBeTrue("{$class} implements HasMedia but registers no collections — its media will inherit the default disk.")
            ->and(collectionsOf($class))
            ->not->toBeEmpty("{$class}::registerMediaCollections() registers nothing.");
    }
});

it('declares an explicit disk on every collection — never the default', function () {
    foreach (mediaModels() as $class) {
        foreach (collectionsOf($class) as $collection) {
            expect($collection->diskName)->not->toBe(
                '',
                "{$class}::{$collection->name} has no explicit disk — it will silently inherit ".
                "config('media-library.disk_name'), which defaults to the PUBLIC webroot. ".
                "Add ->useDisk('local')."
            );
        }
    }
});

it('keeps every collection private unless it is explicitly allowlisted as branding', function () {
    foreach (mediaModels() as $class) {
        $allowed = PUBLIC_COLLECTIONS[$class] ?? [];

        foreach (collectionsOf($class) as $collection) {
            if (in_array($collection->name, $allowed, true)) {
                continue; // deliberately public — see PUBLIC_COLLECTIONS
            }

            expect($collection->diskName)->not->toBe(
                'public',
                "{$class}::{$collection->name} is on the PUBLIC disk — anyone who guesses the ".
                'URL can read it, with no login. If that is genuinely intended, add it to '.
                'PUBLIC_COLLECTIONS with a reason; otherwise use the private `local` disk.'
            );
        }
    }
});

it('points every collection at a disk that is actually configured', function () {
    foreach (mediaModels() as $class) {
        foreach (collectionsOf($class) as $collection) {
            expect(config("filesystems.disks.{$collection->diskName}"))
                ->not->toBeNull("{$class}::{$collection->name} uses disk '{$collection->diskName}', which is not in config/filesystems.php.");
        }
    }
});

it('does not allowlist a collection that no longer exists', function () {
    // Stops the exception list rotting into a licence to be public.
    foreach (PUBLIC_COLLECTIONS as $class => $names) {
        $registered = array_map(fn ($c) => $c->name, collectionsOf($class));

        foreach ($names as $name) {
            expect(in_array($name, $registered, true))->toBeTrue(
                "PUBLIC_COLLECTIONS allowlists {$class}::{$name}, which is no longer registered — remove it."
            );
        }
    }
});

it('stores lease and tenant documents on a private disk', function () {
    // The specific regression, named: these two are the confidential ones — a signed
    // contract and a retailer's tax card.
    foreach ([\App\Models\Lease::class, \App\Models\Tenant::class] as $class) {
        $documents = collect(collectionsOf($class))->firstWhere('name', 'documents');

        expect($documents)->not->toBeNull("{$class} no longer registers a 'documents' collection.")
            ->and($documents->diskName)->toBe('local', Str::afterLast($class, '\\').' documents must stay off the webroot.');
    }
});
