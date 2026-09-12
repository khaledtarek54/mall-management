<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * How a GL source document names itself to a reader — the other half of {@see SourceDocumentUrl}.
 *
 * The journal register's "Source document" column tried `number`, then `reference`, then
 * `label()`, and then printed the MORPH ALIAS: `tenant_credit_application`, and `depreciation_entry`
 * on every one of the 141 depreciation rows of the demo books (the reports audit, 2026-09-12).
 * Seven of the twenty-five posting sources carry none of the three, and a raw storage key in the
 * reader's language column is a defect on the one register an auditor reads end to end.
 *
 * The vocabulary already existed: `ActivityVocabulary` names every audited record type in both
 * languages (`admin.activity.subjects.{log_name}`) and knows how a record names itself
 * (`describeSubject()` — the project's `label()`/`displayName()` convention, then its reference
 * columns). This composes the two: the document's own number where it has one, else its kind in the
 * reader's language and its id — *"Depreciation Entry #141"*, «قيد إهلاك #141» — and only for a
 * source that has been DELETED, whose kind is all the row still knows, the alias made readable.
 *
 * A source with no log name (it is not audited) is named by its morph alias in the same vocabulary,
 * so a subjects key can be added for it without teaching it the audit trail.
 */
final class SourceDocumentLabel
{
    public static function for(?Model $source, ?string $alias = null): ?string
    {
        if ($source !== null) {
            $own = $source->getAttribute('number')
                ?? $source->getAttribute('reference')
                ?? app(ActivityVocabulary::class)->describeSubject($source);

            if (is_string($own) && $own !== '') {
                return $own;
            }

            $kind = self::kind(self::logNameOf($source) ?? $alias ?? (string) $source->getMorphClass());

            return $kind.' #'.$source->getKey();
        }

        return $alias === null ? null : self::kind($alias);
    }

    /** The record type in the reader's language, or the alias made readable when no key names it. */
    private static function kind(string $key): string
    {
        if (Lang::has("admin.activity.subjects.{$key}")) {
            return __("admin.activity.subjects.{$key}");
        }

        $class = Relation::getMorphedModel($key) ?? $key;

        return Str::headline(class_basename($class));
    }

    private static function logNameOf(Model $source): ?string
    {
        if (! method_exists($source, 'getActivitylogOptions')) {
            return null;
        }

        $logName = $source->getActivitylogOptions()->logName;

        return is_string($logName) && $logName !== '' ? $logName : null;
    }
}
