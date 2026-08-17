<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Plan 2 (QA) — API contract guard. The committed OpenAPI spec
 * (docs/api/openapi.json, the artefact the mobile app codes against) must stay
 * complete: every live /api/v1 route has to be documented. Catches the classic
 * drift — "added/renamed an endpoint but didn't regenerate the spec".
 *
 * Regenerate after API changes:  php artisan api:export-spec
 */
function apiSpec(): array
{
    $path = base_path('docs/api/openapi.json');
    expect(file_exists($path))->toBeTrue('Run `php artisan api:export-spec` to generate docs/api/openapi.json');

    return json_decode((string) file_get_contents($path), true);
}

it('is a valid OpenAPI 3.x document', function () {
    $spec = apiSpec();

    expect($spec['openapi'] ?? '')->toStartWith('3.')
        ->and($spec['info']['title'] ?? null)->not->toBeNull()
        ->and($spec['paths'] ?? [])->not->toBeEmpty();
});

it('documents every live /api/v1 route (no undocumented endpoints)', function () {
    $spec = apiSpec();
    $documented = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $_) {
            $documented[strtoupper($method).' '.$path] = true;
        }
    }

    $missing = [];
    foreach (Route::getRoutes() as $route) {
        if (! Str::startsWith($route->uri(), 'api/v1/')) {
            continue;
        }
        // api/v1/me/balance  ->  /v1/me/balance  (Scramble strips the `api` base path)
        $specPath = '/'.Str::after($route->uri(), 'api/');
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }
            if (! isset($documented[$method.' '.$specPath])) {
                $missing[] = $method.' '.$route->uri();
            }
        }
    }

    expect($missing)->toBe([], 'Undocumented /api/v1 routes — regenerate the spec (php artisan api:export-spec): '.implode(', ', $missing));
});
