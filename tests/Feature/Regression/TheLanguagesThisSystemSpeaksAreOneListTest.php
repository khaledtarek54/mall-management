<?php

use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * SW-188 — "which languages this system speaks" was written down six times.
 *
 * The row said `SetApiLocale::SUPPORTED` was UNREFERENCED, and that half is wrong:
 * `UpdateProfileRequest:39` validates the tenant's own language against it, under a comment saying
 * it is checked against "the ONE supported list rather than a copy" — which is exactly what it was
 * not. Measured at HEAD 2026-09-04, `app/` held FIVE files stating the pair beside
 * `SetLocale::SUPPORTED`: that const, both branches of `PaymentLinkController::locale()` (the
 * public pay link, the one money surface with no login in front of it),
 * `ChargeCode::flushLookupCaches()`, `Health::checkTranslations()`, and
 * `IsCodeCatalogue::catalogueLocales()` — that last one reading
 * `config('app.supported_locales', …)`, a key `config/app.php` does not define, so its
 * "configurable" branch had never once been taken.
 *
 * Nothing was wrong: all six said en + ar. What was wrong is that a THIRD language would have
 * reached `ValueSets`, `DocumentLocale`, `NotificationLocale` and the web switcher and stopped at
 * the mobile app and the pay link — silently, because `__()` falls through an unknown locale into
 * the fallback, so the tenant's column looks set and every document arrives in English.
 */
it('negotiates the same languages on the mobile API as the panel speaks', function () {
    $arabic = Request::create('/api/v1/me', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar']);
    (new SetApiLocale)->handle($arabic, fn () => new Response);

    expect(app()->getLocale())->toBe('ar');

    // Control — a language nobody here speaks falls back rather than being set, so the assertion
    // above is about the negotiation and not about the middleware setting whatever it is handed.
    $french = Request::create('/api/v1/me', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-CA']);
    (new SetApiLocale)->handle($french, fn () => new Response);

    expect(app()->getLocale())->toBe('en');
});

it('accepts a language the panel speaks on the tenant profile, and refuses one it does not', function () {
    $rules = (new UpdateProfileRequest)->rules();

    expect(Validator::make(['locale' => 'ar'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['locale' => 'fr-CA'], $rules)->fails())->toBeTrue();
});

it('writes the language list down in exactly one place', function () {
    // Comments stripped, so a sentence ABOUT the list is never mistaken for a second copy of it —
    // a gate that fires on prose is one that gets weakened rather than fixed.
    $stripComments = function (string $source): string {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    };

    $scanned = 0;
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($iterator as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $path = $file->getPathname();

        // The ONE list is allowed to state itself. Nothing else is.
        if (str_ends_with($path, 'Http/Middleware/SetLocale.php')) {
            continue;
        }

        if (preg_match("/\[\s*'(?:en|ar)'\s*,\s*'(?:en|ar)'\s*\]/", $stripComments((string) file_get_contents($path)))) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($offenders);

    // Premise: 1,419 files under app/ at HEAD. And the one list must still SAY something — a gate
    // that passes because `SUPPORTED` was emptied would be worse than the drift it replaced.
    expect($scanned)->toBeGreaterThan(1000)
        ->and(SetLocale::SUPPORTED)->toContain('en')
        ->and(SetLocale::SUPPORTED)->toContain('ar')
        ->and($offenders)->toBe([]);
});
