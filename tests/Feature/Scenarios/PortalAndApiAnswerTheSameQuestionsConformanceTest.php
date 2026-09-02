<?php

use App\Support\PortalApiParity;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * **The portal and `/api/v1` are the same surface with different renderers — now gated.**
 *
 * The rule is stated in `docs/modules/03-tenant-portal-users.md` and in `ConfirmTenantRequestAction`
 * and was enforced by nothing, so it drifted seven times without a single red test: honoured for
 * VISIBILITY (drafts, fixed twice) and silently not for CONTENT. The two worst gaps were not
 * incompleteness but silence — a deposit shortfall that is never invoiced, so the portal figure was
 * the only channel by which a tenant was ever told; and the tenant's own on-account cash, which
 * looked lost and then part-settled an invoice.
 *
 * See {@see PortalApiParity} for the registry and the reasoning.
 *
 * **This gate reads SOURCE**, so it proves the weaker property that a field is DECLARED. The
 * behavioural half is each endpoint's own regression test. What it buys is the half no person
 * reliably does: noticing that a tenth portal screen, or a new field on an existing one, has no
 * counterpart.
 */

/** Every `X::make('path')` in a Filament schema file — the fields that screen renders. */
function portalFieldsIn(string $directory): array
{
    $files = array_merge(
        glob(base_path("app/Filament/Portal/Resources/{$directory}/Schemas/*.php")) ?: [],
        glob(base_path("app/Filament/Portal/Resources/{$directory}/Tables/*.php")) ?: [],
    );

    $fields = [];

    foreach ($files as $file) {
        preg_match_all("/(?:TextEntry|TextColumn|IconEntry|IconColumn|RepeatableEntry)::make\(\s*'([^']+)'/", file_get_contents($file), $m);

        foreach ($m[1] as $path) {
            // A payload answers at the granularity of its own key, so compare the LAST segment:
            // the portal flattens `pool.period_year` where the API nests, and the question both
            // are answering is the same one.
            $segments = explode('.', $path);
            // …and in snake_case: a portal relation is named `rentableItems` where the payload key
            // is `rentable_items`, and the same question in two casings is still one question.
            $fields[] = Str::snake(end($segments));
        }
    }

    return array_values(array_unique($fields));
}

/** Every key an API resource publishes, including the ones inside nested closures. */
function apiKeysIn(array $resourceClasses): array
{
    $keys = [];

    foreach ($resourceClasses as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        preg_match_all("/'([a-z0-9_]+)'\s*=>/", $source, $m);
        $keys = array_merge($keys, $m[1]);
    }

    return array_values(array_unique($keys));
}

it('gives every portal resource an /api/v1 counterpart', function () {
    $onDisk = collect(glob(base_path('app/Filament/Portal/Resources/*'), GLOB_ONLYDIR))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values();

    // Discovered from DISK, never from the registry: a gate that reads only the list it guards
    // cannot see what that list omits. A tenth portal resource fails here on the day it lands.
    expect($onDisk->all())->toBe(collect(PortalApiParity::PAIRS)->keys()->sort()->values()->all());
});

it('routes every counterpart it claims', function () {
    foreach (PortalApiParity::PAIRS as $directory => $pair) {
        expect(Route::has($pair['route']))->toBeTrue(
            "{$directory} claims the route {$pair['route']}, which does not exist",
        );
    }
});

it('publishes on the API every field the portal renders', function () {
    $missing = [];

    foreach (PortalApiParity::PAIRS as $directory => $pair) {
        $apiKeys = apiKeysIn($pair['resources']);

        foreach (portalFieldsIn($directory) as $field) {
            if (in_array($field, $apiKeys, true)) {
                continue;
            }

            if (array_key_exists("{$directory}::{$field}", PortalApiParity::FIELD_EXEMPT)) {
                continue;
            }

            $missing[] = "{$directory}::{$field}";
        }
    }

    expect($missing)->toBe([], implode("\n", array_merge(
        ['The portal renders these and the API does not publish them.'],
        ['Either add the field to the API resource, or register it in PortalApiParity::FIELD_EXEMPT with a reason:'],
        $missing,
    )));
});

it('rejects a stale exemption', function () {
    $stale = [];

    foreach (PortalApiParity::FIELD_EXEMPT as $key => $reason) {
        [$directory, $field] = explode('::', $key);

        // The portal stopped rendering it — the exemption describes nothing.
        if (! in_array($field, portalFieldsIn($directory), true)) {
            $stale[] = "{$key} (the portal no longer renders it)";

            continue;
        }

        // The API caught up — the exemption is now a false statement about the contract, and the
        // list must shrink rather than quietly keeping a decision nobody re-made.
        if (in_array($field, apiKeysIn(PortalApiParity::PAIRS[$directory]['resources']), true)) {
            $stale[] = "{$key} (the API publishes it now)";
        }
    }

    expect($stale)->toBe([]);
});

it('requires a real reason on every exemption', function () {
    foreach (PortalApiParity::FIELD_EXEMPT as $key => $reason) {
        // "will be added later" is what the vendor-scorecard backlog entry said for a month while
        // the work was already done. A reason has to say why the API is RIGHT not to send it.
        expect(strlen($reason))->toBeGreaterThan(30, "{$key} needs a reason somebody can review");
    }
});

it('names where every non-resource portal surface is answered', function () {
    $onDisk = collect(array_merge(
        glob(base_path('app/Filament/Portal/Pages/*.php')) ?: [],
        glob(base_path('app/Filament/Portal/Widgets/*.php')) ?: [],
    ))->map(fn (string $p) => str_replace([base_path('app/Filament/Portal/'), '.php'], '', $p))
        ->sort()->values();

    // `credit_on_account` hid on a WIDGET, not on a resource — which is exactly why a
    // resource-only sweep would have missed it and this second list exists.
    expect($onDisk->all())->toBe(collect(PortalApiParity::NON_RESOURCE_SURFACES)->keys()->sort()->values()->all());
});

it('is not sweeping an empty set', function () {
    // Every gate in this project that went blind did so silently, reporting a clean result over a
    // population it had stopped collecting. Count first.
    $fieldCount = collect(PortalApiParity::PAIRS)
        ->keys()
        ->sum(fn (string $directory) => count(portalFieldsIn($directory)));

    expect(count(PortalApiParity::PAIRS))->toBeGreaterThanOrEqual(9)
        ->and($fieldCount)->toBeGreaterThan(80)
        ->and(count(apiKeysIn(PortalApiParity::PAIRS['Leases']['resources'])))->toBeGreaterThan(20);
});
