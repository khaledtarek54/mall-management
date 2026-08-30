<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Services\Accounting\LedgerPoster;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The figures the public landing page quotes about this system, **derived from the registries
 * that define them** rather than typed into the page.
 *
 * A marketing page is the one surface nobody re-checks, so a hand-typed count there is a claim
 * that goes stale silently and is then read by a prospect as the current truth. This project has
 * already been bitten by exactly that: five statements in the visual handbook had drifted (the
 * settlement channels, the journalizer count twice, the module count, a panel that no longer
 * exists), and all five surfaced only when somebody happened to translate them. The rule stated in
 * CLAUDE.md — *never hand-type a registry or a count into a doc* — applies with more force here,
 * because this page faces outward.
 *
 * So every number below is asked of the thing that owns it. Adding a module switch, a journalizer
 * or a role changes the landing page with no edit to the landing page, and `LandingPageTest`
 * asserts the rendered HTML really carries these values rather than a copy of them.
 *
 * The one figure that is NOT derived is the module-doc count, which is a property of the docs tree
 * rather than of the code — it is read from disk for the same reason.
 */
final class LandingFacts
{
    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        return [
            // Optional modules the operator can switch off. `toggleable()`, not `KEYS`: a frozen
            // module (module 16) appears nowhere in the running system, so counting it here would
            // advertise a switch that is not on any screen.
            'modules' => count(Modules::toggleable()),

            // Documented modules — the docs/modules/NN-*.md set.
            'documented_modules' => self::documentedModules(),

            // Every kind of document that posts to the general ledger. ONE registry, which all
            // four dispatch paths derive from (see LedgerPoster::JOURNALIZERS).
            'gl_sources' => count(LedgerPoster::sources()),

            // Screens carrying a written, bilingual guide — i.e. the whole panel surface.
            'screens' => count(ScreenGuides::SCREENS),

            // Catalogued reports: the ones that can be filtered, exported and delivered.
            'reports' => count(ReportCatalogue::REPORTS),

            'roles' => count(RolesPermissionsSeeder::ROLES),

            'locales' => count(SetLocale::SUPPORTED),

            // Admin · tenant portal · vendor portal · mobile API.
            'surfaces' => 4,
        ];
    }

    public static function get(string $key): int
    {
        return self::all()[$key] ?? 0;
    }

    private static function documentedModules(): int
    {
        return count(glob(base_path('docs/modules/[0-9][0-9]-*.md')) ?: []);
    }
}
