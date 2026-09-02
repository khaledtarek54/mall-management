<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\TenantRequestType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\TenantRequest;
use App\Models\TenantRequestSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/request-types — what a tenant may raise, and under which sub-category.
 *
 * **The app had to hardcode this list, and the list is a CATALOGUE the operator edits.** Since
 * EG-14 `POST /me/requests` validates `category` against `TenantRequestSubcategory::optionsFor()`
 * — database rows, with the PHP enum as their floor — so the maintenance set went from 7 to 14
 * (a tenant literally could not report a stuck lift, a generator fault or a fire-safety problem)
 * and can move again with no deploy on either side. A client shipping its own copy is structurally
 * one release behind the operator, and the failure is a 422 on a picker the tenant was offered.
 *
 * **Both languages ship on every row and the client picks** — the same convention as
 * `AnnouncementResource` and `MarketingPostResource`, and for the same reason: switching language
 * must not need a round trip, and a cached catalogue must not go monolingual when the reader
 * changes their mind. The labels come from `optionsFor()` itself, which is the resolution the panel
 * and the portal use — including the per-code floor and `IsCodeCatalogue`'s rule that an
 * operator-added code with no lang key reads as ITSELF rather than as `admin.enums.…fawry`.
 *
 * `requires_decision` is published so the client knows which types are QUESTIONS before it renders
 * the outcome — the distinction that let a staff rejection display to a tenant as an approval.
 * `has_sla` says whether a target resolution time will ever be set, so the app can show or omit the
 * countdown rather than rendering an empty one.
 *
 * No `?lang=`: this reads `Accept-Language` like every other JSON response, and it carries both
 * languages anyway.
 */
class ListRequestTypesController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $types = collect(TenantRequestType::cases())
            // A type can be retired without removing the case, so old rows still resolve while
            // nobody is offered it. The picker must honour that.
            ->filter(fn (TenantRequestType $type) => $type->isActive())
            ->map(fn (TenantRequestType $type) => [
                'code' => $type->value,
                'label' => $this->inLocale('en', fn () => $type->label()),
                'label_ar' => $this->inLocale('ar', fn () => $type->label()),
                'requires_decision' => $type->requiresDecision(),
                'has_sla' => $type->hasSla(),
                // The sub-categories THIS type defines. An empty array is meaningful and not a
                // failure: `inquiry` and `billing` have none, and sending one is `prohibited` on
                // the create endpoint — so the client must render no picker at all rather than an
                // empty one.
                'subcategories' => $this->subcategoriesFor($type),
            ])
            ->values()
            ->all();

        return $this->ok([
            'types' => $types,
            // The priority set is a closed `ValueSets` list, not a catalogue — but the app has been
            // hardcoding it too, and shipping it here costs one key and removes a second list.
            'priorities' => TenantRequest::PRIORITIES,
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function subcategoriesFor(TenantRequestType $type): array
    {
        // `optionsFor()` returns [code => label] in the CURRENT locale, so it is asked once per
        // language rather than reaching past it into the rows — the labels then come from exactly
        // the resolution the panel and the portal use, including the per-code floor for a shipped
        // code the operator never wrote a row for.
        $en = $this->inLocale('en', fn () => TenantRequestSubcategory::optionsFor($type));
        $ar = $this->inLocale('ar', fn () => TenantRequestSubcategory::optionsFor($type));

        return collect($en)
            ->map(fn (string $label, string $code) => [
                'code' => $code,
                'label' => $label,
                'label_ar' => $ar[$code] ?? $label,
            ])
            ->values()
            ->all();
    }
}
