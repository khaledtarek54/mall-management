<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\Filament\CustomFieldsSchema;

/**
 * Load the operator's own field answers into an Edit form (D-7 / EG-32).
 *
 * ## Why this is needed at all
 *
 * `HasCustomFields` exposes the answers as a virtual `custom_fields` attribute, and that is enough
 * to WRITE them: Filament calls `$record->update($data)`, `custom_fields` is fillable, and the
 * setter routes through `fillCustomFields()`. Reading is not symmetric. Filament fills an Edit form
 * from `$record->attributesToArray()`, which contains only real columns and whatever is in
 * `$appends` — a virtual accessor is invisible to it, so the section opened EMPTY on every edit and
 * the next save would have looked exactly like the operator clearing every answer.
 *
 * Appending it instead would have worked and been wrong: `$appends` reaches `toArray()`, and
 * `docs/api/openapi.json` is GENERATED from the API resources' `toArray()`. A display concern would
 * have quietly rewritten the mobile contract.
 *
 * Caught by driving the real Create and Edit pages in a test. Building a schema and asserting its
 * shape passes whether or not this exists — the same trap that shipped two live 500s in August.
 */
trait FillsCustomFields
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data[CustomFieldsSchema::KEY] = CustomFieldsSchema::fill($this->getRecord());

        return $data;
    }
}
