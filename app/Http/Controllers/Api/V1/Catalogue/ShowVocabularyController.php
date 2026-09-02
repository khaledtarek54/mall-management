<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Http\Controllers\Api\V1\ApiController;
use App\Support\ApiVocabulary;
use App\Support\Translate;
use App\Support\ValueSets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/vocabulary — every classification this API emits, in BOTH languages.
 *
 * The API sends machine codes, correctly: `"status": "overdue"`, `"method": "instapay"`. What it
 * never sent is what those words ARE, so the app carried its own EN+AR table for 25 vocabularies
 * and kept it in step with a backend it cannot see — and for five of them it structurally cannot,
 * because they are catalogues the operator edits with no deploy on either side.
 *
 * **One call, cached, keyed by `version`.** Fetch on launch, keep it, and re-fetch when `version`
 * changes; the hash covers the rendered labels, so a renamed charge code changes it and nothing
 * else does. Falling back to a stale copy is safe — a code you cannot label is far better rendered
 * as itself than as a blank cell, which is exactly what `IsCodeCatalogue` does on the panel.
 *
 * **Nothing here is a second vocabulary.** A closed set takes its values from `ValueSets` (the
 * registry the column is enforced against) and its words from the lang group the panel labels from;
 * an open catalogue takes both from its own rows. See {@see ApiVocabulary}.
 */
class ShowVocabularyController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $vocabularies = [];

        foreach (ApiVocabulary::VOCABULARIES as $path => $spec) {
            $vocabularies[$path] = $this->resolve($spec);
        }

        return $this->ok([
            // A hash of the rendered words, not of the registry: what a client caches is the
            // LABELS, so a renamed row must change this and a refactor must not.
            'version' => substr(hash('sha256', json_encode($vocabularies)), 0, 16),
            // Named so the app can tell the two apart: these are the ones a shipped table can never
            // be right about, because the operator adds to them between releases.
            'open_catalogues' => ApiVocabulary::openCatalogues(),
            'vocabularies' => $vocabularies,
        ]);
    }

    /**
     * @param  array{set?: string, group?: string, catalogue?: class-string}  $spec
     * @return array<string, array{en: string, ar: string}>
     */
    private function resolve(array $spec): array
    {
        if (isset($spec['catalogue'])) {
            return $this->fromCatalogue($spec['catalogue'], $spec['args'] ?? [], $spec['floor'] ?? null, $spec['label_via'] ?? null);
        }

        [$table, $column] = explode('.', $spec['set']);

        // The values come from the registry the COLUMN is enforced against, so a widened set
        // appears here the day it is widened and there is no list to remember.
        $codes = ValueSets::allowed($table, $column) ?? [];

        $out = [];

        foreach ($codes as $code) {
            $out[$code] = [
                // `Translate::orHumanized`, not a bare `__()`: a missing key must read as the code
                // humanised, never as the literal `admin.statuses.invoice.foo` printed on a
                // retailer's screen. The gate below fails the build on a missing one anyway; this
                // is what the tenant sees in the window between the two.
                'en' => $this->inLocale('en', fn () => Translate::orHumanized("{$spec['group']}.{$code}", $code)),
                'ar' => $this->inLocale('ar', fn () => Translate::orHumanized("{$spec['group']}.{$code}", $code)),
            ];
        }

        return $out;
    }

    /**
     * @param  array{0: class-string, 1: string}  $catalogue
     * @param  list<mixed>  $args
     * @param  class-string|null  $floor  a backed enum of the codes that SHIP
     * @param  array{0: class-string, 1: string}|null  $labelVia
     * @return array<string, array{en: string, ar: string}>
     */
    private function fromCatalogue(array $catalogue, array $args, ?string $floor, ?array $labelVia): array
    {
        [$model, $method] = $catalogue;

        // The SAME public option method the panel's own picker calls — named in the registry rather
        // than reimplemented — so a shipped code the operator never wrote a row for still appears
        // (the per-code floor), a retired one disappears, and an operator-added one carries its own
        // bilingual pair. Reaching into the rows here would be a second resolver, and the whole
        // failure this endpoint exists for is a second copy of a vocabulary.
        $en = $this->inLocale('en', fn () => $model::$method(...$args));
        $ar = $this->inLocale('ar', fn () => $model::$method(...$args));

        $out = [];

        foreach ($en as $code => $label) {
            $out[$code] = ['en' => (string) $label, 'ar' => (string) ($ar[$code] ?? $label)];
        }

        // A rows-only option method answers EMPTY on a box whose catalogue has not been seeded,
        // which reads to a client as "there are no charge types" rather than as an unconfigured
        // install. The shipped codes are the floor; their words come from the catalogue's own
        // `labelFor()`, so a code with a row is named by the row and one without falls through to
        // the lang key and then to itself — never to a blank cell.
        foreach ($floor === null ? [] : $floor::values() as $code) {
            if (isset($out[$code])) {
                continue;
            }

            [$labelModel, $labelMethod] = $labelVia;

            $out[$code] = [
                'en' => (string) $this->inLocale('en', fn () => $labelModel::$labelMethod($code)),
                'ar' => (string) $this->inLocale('ar', fn () => $labelModel::$labelMethod($code)),
            ];
        }

        return $out;
    }
}
