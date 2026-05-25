<?php

namespace App\Support;

use Spatie\Activitylog\Models\Activity;

/**
 * Renders Spatie\Activitylog's `attribute_changes` payload as a readable
 * HTML fragment — used by both the standalone Activity Log page and the
 * embedded ActivitiesRelationManager.
 *
 * Output shape per change line:
 *   <strong>Field name</strong> <s>old value</s> → <span>new value</span>
 *
 * For "created" diffs (no prior value) the arrow + strikethrough are
 * omitted. Null / empty values render as italic dimmed "(empty)". Booleans
 * humanise to yes/no; arrays/objects compact-JSON. Acronyms like
 * ETA / VAT / ID are uppercased in field labels.
 *
 * All values are e()-escaped — XSS-safe even though the activity log
 * stores operator-entered names, tenant addresses, free-text notes, etc.
 */
class ActivityLogChangeRenderer
{
    /** @var array<string, string> Lowercase acronym → display form. */
    private const ACRONYMS = [
        'eta' => 'ETA',
        'vat' => 'VAT',
        'id' => 'ID',
        'pdf' => 'PDF',
        'url' => 'URL',
    ];

    public function render(Activity $activity): string
    {
        $changes = $activity->attribute_changes;
        if (! $changes || ! isset($changes['attributes'])) {
            return '<span class="fi-color-gray">—</span>';
        }

        $old = $changes['old'] ?? [];
        $lines = [];

        foreach ($changes['attributes'] as $field => $newValue) {
            $hadOld = array_key_exists($field, $old) && $old[$field] !== null && $old[$field] !== '';
            $label = '<strong class="text-gray-900 dark:text-gray-100">'
                . e($this->humaniseField($field)) . '</strong>';

            $newDisplay = $this->renderValue($newValue, isNew: true);

            if ($hadOld) {
                $oldDisplay = '<span class="line-through opacity-60">'
                    . e($this->formatValue($old[$field])) . '</span>';
                $lines[] = $label . ' ' . $oldDisplay . ' → ' . $newDisplay;
            } else {
                $lines[] = $label . ' ' . $newDisplay;
            }
        }

        return '<div class="flex flex-col gap-1 text-xs">' . implode('', array_map(
            fn (string $line): string => '<div>' . $line . '</div>',
            $lines,
        )) . '</div>';
    }

    private function renderValue(mixed $value, bool $isNew): string
    {
        if ($value === null || $value === '') {
            return '<em class="opacity-60">' . __('admin.activity.empty_value') . '</em>';
        }

        $colour = $isNew
            ? 'text-success-600 dark:text-success-400'
            : 'text-gray-500';

        return '<span class="' . $colour . '">' . e($this->formatValue($value)) . '</span>';
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('admin.activity.bool_true') : __('admin.activity.bool_false');
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private function humaniseField(string $field): string
    {
        $words = explode(' ', str_replace('_', ' ', $field));
        $words[0] = ucfirst($words[0]);

        return implode(' ', array_map(
            fn (string $w): string => self::ACRONYMS[strtolower($w)] ?? $w,
            $words,
        ));
    }
}
