<?php

namespace App\Support\Assistant;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\File;

/**
 * "How do I create one, and what goes on the form?" — derived from the forms themselves.
 *
 * ## Why this tier exists
 *
 * The screen guides answer *what a screen is for* and the handbook answers *how the system works*.
 * Neither names a FIELD, and neither links to the form. So "how do I raise an invoice, and what
 * does it want from me" was answered with a paragraph about invoices and a link to the list — true,
 * and two clicks short of useful.
 *
 * ## Read from the source, not from a list
 *
 * The fields are parsed out of each resource's own form class, so they cannot drift: adding a field
 * to the invoice form makes it appear here on the next index. The LABELS come from
 * `admin.fields.*` — the catalogue the forms themselves label from — which is why this is bilingual
 * for free and why a renamed label reaches the assistant the same day it reaches the screen.
 *
 * **Static parsing is a deliberate trade.** Building 66 form schemas needs a mounted Livewire page
 * apiece, and a page that throws would take the whole index with it. Reading the source cannot see
 * a field that only appears under a condition, or one built in a loop — so this is a good answer to
 * "what does this form want", not a specification. The form is authoritative; the reader is one
 * click from it, which is the point.
 */
final class TaskCorpus
{
    /** Layout components, which name a section rather than a field. */
    public const MAX_LISTED_FIELDS = 12;

    private const NOT_FIELDS = [
        'Section', 'Tabs', 'Tab', 'Grid', 'Group', 'Fieldset', 'Actions', 'Action',
        'Wizard', 'Step', 'Split', 'Livewire', 'View', 'Placeholder',
    ];

    /**
     * One entry per resource an operator can create something in.
     *
     * @return array<int, AssistantEntry>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $create = self::createPage($resource);

            if ($create === null) {
                continue;
            }

            $fields = self::fieldsFor($resource);

            if ($fields === []) {
                continue;
            }

            $label = rescue(fn (): string => (string) $resource::getModelLabel(), '', report: false);

            if ($label === '') {
                continue;
            }

            $title = __('admin.assistant.task.create', ['thing' => $label]);

            // WEIGHTED ON THE LABEL ALONE, and the two things NOT weighted here are the finding.
            //
            // A shared verb list ("create · add · new · raise · issue · open · record") looked like
            // the obvious way to catch how people actually phrase this. At keyword weight it gave
            // all SIXTY-ONE task entries the same score for any question containing a common verb,
            // so "issue a credit note" and "open the rent roll" tied every task in the system and
            // crowded the real answer out of the five result slots. The title has the same defect
            // in miniature — every one of them begins "New" — so the terms come from the resource
            // LABEL only, which is the word that actually selects WHICH task.
            //
            // Field labels contribute at BODY weight: enough that "the late fee cap" plus the word
            // "lease" finds the lease form, not enough for a field name alone to outrank a screen
            // that answers the question directly.
            $terms = [];
            self::add($terms, $label, AssistantCorpus::WEIGHT_TITLE);

            foreach ($fields as $field) {
                self::add($terms, $field['label'], AssistantCorpus::WEIGHT_BODY);
            }

            $entries[] = new AssistantEntry(
                kind: 'task',
                key: $resource,
                screen: $create,
                title: $title,
                terms: $terms,
            );
        }

        return $entries;
    }

    /**
     * The fields on a resource's form, with the label the screen shows.
     *
     * @return array<int, array{name: string, label: string, required: bool}>
     */
    public static function fieldsFor(string $resource): array
    {
        $source = self::formSource($resource);

        if ($source === null) {
            return [];
        }

        // TWO PASSES, and the one-pass version was subtly wrong in a way that looked right.
        //
        // Capturing the chain in the same expression — "everything up to the next `::make(`" —
        // consumes the NEXT component's class name before it stops, because the lookahead only
        // refuses the `::make(` itself. The scan then resumes mid-identifier and cannot match, so
        // EVERY OTHER FIELD was silently dropped: `lease_id` and `due_date` were missing from the
        // invoice form while the list still looked plausible enough to ship.
        preg_match_all(
            '/(?:^|[\s(\[.])([A-Z][A-Za-z]*)::make\(\s*[\'"]([a-z0-9_.]+)[\'"]\s*\)/m',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $fields = [];
        $seen = [];

        foreach ($matches as $i => $match) {
            $component = $match[1][0];
            $name = $match[2][0];

            if (in_array($component, self::NOT_FIELDS, true) || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            // The chain is whatever sits between this component and the next one.
            $from = $match[0][1] + strlen($match[0][0]);
            $to = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : strlen($source);
            $chain = substr($source, $from, max(0, $to - $from));

            $fields[] = [
                'name' => $name,
                // `admin.fields.*` is the catalogue the forms label from, so this is the SAME word
                // the operator reads on screen, in their own language.
                'label' => trans()->has("admin.fields.{$name}")
                    ? (string) __("admin.fields.{$name}")
                    : str_replace('_', ' ', $name),
                // Approximate in BOTH directions, and worth stating plainly rather than claiming
                // one-sided safety: a field required by a rule or a closure reads as optional, and
                // one that is `->required()` only when another field is set reads as always
                // required. The form is authoritative and is one click away; this is a good answer
                // to "what will it ask me for", not a specification.
                'required' => str_contains($chain, '->required('),
            ];
        }

        return $fields;
    }

    /** A sentence listing what the form asks for. */
    public static function fieldSentence(string $resource): string
    {
        $fields = self::fieldsFor($resource);

        if ($fields === []) {
            return '';
        }

        $required = array_column(array_filter($fields, fn (array $f): bool => $f['required']), 'label');
        $optional = array_column(array_filter($fields, fn (array $f): bool => ! $f['required']), 'label');

        $parts = [];

        if ($required !== []) {
            $parts[] = __('admin.assistant.task.required_fields', ['fields' => self::list($required)]);
        }

        if ($optional !== []) {
            $parts[] = __('admin.assistant.task.optional_fields', ['fields' => self::list($optional)]);
        }

        return implode(' ', $parts);
    }

    /**
     * Join a list with the separator the reader's language actually uses.
     *
     * Arabic separates with «،» (U+060C), not a Latin comma — a comma in an Arabic sentence reads
     * the way a full stop mid-word would in English. It was hardcoded to the Arabic one, which put
     * it into every English list too.
     *
     * Capped, because a long form would otherwise spend the model's whole prompt on field names and
     * leave no room for the guide beside it. The form is one click away and is authoritative.
     *
     * @param  array<int, string>  $labels
     */
    private static function list(array $labels): string
    {
        $separator = app()->getLocale() === 'ar' ? '، ' : ', ';

        if (count($labels) <= self::MAX_LISTED_FIELDS) {
            return implode($separator, $labels);
        }

        return implode($separator, array_slice($labels, 0, self::MAX_LISTED_FIELDS))
            .' '.__('admin.assistant.task.and_more', ['count' => count($labels) - self::MAX_LISTED_FIELDS]);
    }

    /** @return class-string|null */
    public static function createPage(string $resource): ?string
    {
        return rescue(function () use ($resource): ?string {
            foreach ($resource::getPages() as $registration) {
                $page = $registration->getPage();

                if (is_subclass_of($page, CreateRecord::class)) {
                    return $page;
                }
            }

            return null;
        }, null, report: false);
    }

    /**
     * The resource's form class source — `…/Schemas/*Form.php` beside it.
     */
    private static function formSource(string $resource): ?string
    {
        $file = rescue(fn (): string|false => (new \ReflectionClass($resource))->getFileName(), false, report: false);

        if ($file === false || $file === null) {
            return null;
        }

        $schemas = dirname($file).'/Schemas';

        if (! is_dir($schemas)) {
            return null;
        }

        $source = '';

        foreach (File::files($schemas) as $candidate) {
            if (str_ends_with($candidate->getFilename(), 'Form.php')) {
                $source .= File::get($candidate->getRealPath());
            }
        }

        return $source !== '' ? $source : null;
    }

    /**
     * @param  array<string, int>  $terms
     */
    private static function add(array &$terms, ?string $phrase, int $weight): void
    {
        foreach (\App\Support\Search\SearchText::words($phrase) as $word) {
            if (in_array($word, AssistantCorpus::STOP_WORDS, true)) {
                continue;
            }

            $terms[$word] = max($terms[$word] ?? 0, $weight);
        }
    }
}
