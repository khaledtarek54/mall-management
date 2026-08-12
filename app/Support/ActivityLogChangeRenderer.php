<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Renders an activity row's payload as a readable HTML fragment — used by both the
 * standalone Activity Log page and the embedded ActivitiesRelationManager.
 *
 * This class owns **markup only**. Every word it emits comes from
 * `App\Support\ActivityVocabulary`, which is what makes the audit trail bilingual: the log
 * stores field keys and raw values, and the language is chosen at read time, so the same
 * historical row reads correctly in Arabic and in English. Nothing here should call `__()`
 * on activity data directly, or the two surfaces drift apart.
 *
 * Output shape per line:
 *   <strong>Field label</strong> <s>old value</s> → <span>new value</span>
 *
 * For "created" diffs (no prior value) the arrow + strikethrough are omitted. **The arrow
 * follows the reading direction** — `→` (U+2192) is not a bidi-mirrored character, so left
 * unchanged inside an Arabic sentence it points at the OLD value and reads backwards.
 *
 * Three payload shapes are normalised into one list of changes. **They live in two different
 * columns** — spatie writes a model diff to `attribute_changes` and anything passed to
 * `withProperties()` to `properties`:
 *   - spatie's model diff        `attribute_changes.attributes` + `attribute_changes.old`
 *   - the settings pages' diff   `properties.changes[key] = ['from' => …, 'to' => …]`
 *   - scalar context             `properties.reason`, `properties.amount`, `properties.asset_id`
 * Reading only the first column is why the Settings and Property Overrides audit rows rendered
 * a bare dash: they record real from→to money figures, in a column nothing here ever read.
 *
 * All values are e()-escaped — XSS-safe even though the log stores operator-entered names,
 * tenant addresses and free-text notes.
 */
class ActivityLogChangeRenderer
{
    /**
     * Payload keys that carry context rather than a field diff. Rendered as single values
     * under their own label instead of as old → new.
     *
     * Each must have a label in `admin.fields.*` in both locales — pinned by
     * `ActivityLogVocabularyConformanceTest`, since an unlabelled key here humanises to an
     * English word that would sit untranslated in the middle of an Arabic cell.
     */
    public const CONTEXT_KEYS = ['reason', 'amount', 'bill', 'asset_id'];

    public function __construct(private readonly ActivityVocabulary $vocabulary) {}

    public function render(Activity $activity): string
    {
        $logName = $activity->log_name;
        $subjectType = $activity->subject_type;
        $attributeChanges = $this->payload($activity->attribute_changes);
        $properties = $this->payload($activity->properties);

        $lines = [];

        foreach ($this->diffs($attributeChanges, $properties) as [$field, $old, $new, $hadOld]) {
            $parts = [$this->label($this->vocabulary->field($logName, $field))];

            if ($hadOld) {
                $parts[] = $this->oldValue($this->vocabulary->value($logName, $subjectType, $field, $old));
                $parts[] = $this->arrow();
            }

            $parts[] = $this->newValue($this->vocabulary->value($logName, $subjectType, $field, $new));
            $lines[] = $parts;
        }

        foreach (self::CONTEXT_KEYS as $key) {
            $value = $properties[$key] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $lines[] = [
                $this->label($this->vocabulary->field($logName, $key)),
                $this->newValue($this->vocabulary->value($logName, $subjectType, $key, $value)),
            ];
        }

        // Only when the row carries no figures at all: a void with no reason given still has to
        // say what happened. Skipped when it merely repeats the event — spatie defaults the
        // description to the event name, so ~1.8k rows here say "created"/"updated", which the
        // Event badge beside them already says, in the reader's language.
        if ($lines === [] && $activity->description !== $activity->event
            && ($description = $this->vocabulary->description($activity->description)) !== '') {
            $lines[] = ['<span class="opacity-75">'.e($description).'</span>'];
        }

        if ($lines === []) {
            return '<span class="fi-color-gray">—</span>';
        }

        // Each change is its own flex row, and the gap between label and value is LAYOUT rather
        // than a whitespace character. That is what fixes the RTL rendering: a plain space
        // between an Arabic label and a Latin value gets absorbed by the bidi algorithm when it
        // reorders the run, so «الاسم» and "Parking & rentable items" ran together with nothing
        // between them. See newValue()/oldValue() for the matching <bdi> isolation.
        //
        // The gap is an INLINE STYLE, not a utility class. This markup is injected through
        // `->html()` and is never seen by the Tailwind scanner that builds Filament's stylesheet,
        // so a class the panel does not already use compiles to nothing — and the failure mode is
        // silent, in the exact place this change exists to fix. Colours stay as classes because
        // they must follow the light/dark theme.
        $row = 'display:flex;flex-wrap:wrap;align-items:baseline;gap:0.125rem 0.375rem';

        return '<div style="display:flex;flex-direction:column;gap:0.25rem" class="text-xs">'.implode('', array_map(
            fn (array $parts): string => '<div style="'.$row.'">'.implode('', $parts).'</div>',
            $lines,
        )).'</div>';
    }

    /**
     * Every from→to pair the row recorded, whichever column and shape holds it.
     *
     * @param  array<string, mixed>  $attributeChanges
     * @param  array<string, mixed>  $properties
     * @return list<array{0: string, 1: mixed, 2: mixed, 3: bool}>
     */
    private function diffs(array $attributeChanges, array $properties): array
    {
        $diffs = [];

        // spatie's model diff, from the `attribute_changes` column.
        $attributes = $attributeChanges['attributes'] ?? null;
        if (is_array($attributes)) {
            $old = (array) ($attributeChanges['old'] ?? []);

            foreach ($attributes as $field => $new) {
                $hadOld = array_key_exists($field, $old) && $old[$field] !== null && $old[$field] !== '';
                $diffs[] = [(string) $field, $old[$field] ?? null, $new, $hadOld];
            }
        }

        // The settings pages' diff.
        $changes = $properties['changes'] ?? null;
        if (is_array($changes)) {
            foreach ($changes as $field => $change) {
                if (! is_array($change)) {
                    $diffs[] = [(string) $field, null, $change, false];

                    continue;
                }

                $from = $change['from'] ?? null;
                $diffs[] = [(string) $field, $from, $change['to'] ?? null, $from !== null && $from !== ''];
            }
        }

        return $diffs;
    }

    /**
     * Both columns are cast to a Collection, but a hand-built fixture may hold a plain array
     * and a legacy row may hold null.
     *
     * @return array<string, mixed>
     */
    private function payload(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * `→` in a left-to-right reading, `←` in a right-to-left one. Arrows are not mirrored by
     * the bidi algorithm, so the glyph has to be chosen rather than left to the browser.
     */
    private function arrow(): string
    {
        return in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? '←' : '→';
    }

    /**
     * Every label and value is a `<bdi>`, not a `<span>`.
     *
     * This cell mixes scripts by nature: an Arabic label beside a Latin value («المرجع» /
     * "OSR-2026-0002"), a money figure beside an Arabic status. Left to the bidi algorithm those
     * form one directional run and are reordered together — a trailing number jumps to the wrong
     * side of its label, and adjacent values merge into each other. `<bdi>` isolates each
     * fragment so it is placed as one opaque unit, whichever direction the page runs.
     */
    private function label(string $text): string
    {
        return '<bdi class="font-medium text-gray-900 dark:text-gray-100">'.e($text).'</bdi>';
    }

    private function newValue(string $text): string
    {
        return '<bdi class="text-success-600 dark:text-success-400">'.e($text).'</bdi>';
    }

    private function oldValue(string $text): string
    {
        return '<bdi class="line-through opacity-60">'.e($text).'</bdi>';
    }
}
