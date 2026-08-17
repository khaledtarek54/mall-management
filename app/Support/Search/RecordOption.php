<?php

namespace App\Support\Search;

use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * How one record introduces itself in a picker — the value object every dropdown
 * option, and every "you picked this" display, is rendered from.
 *
 * ## The problem it replaces
 *
 * Every entity dropdown in this system rendered one column. `->relationship('tenant', 'name')`
 * puts the tenant's NAME in the list and nothing else, which is fine until a mall has «Zara»,
 * «Zara Home» and «Zara Kids», or two tenants genuinely called «محمد أحمد». At that point the
 * operator is picking between identical-looking rows and the only way to tell them apart is to
 * abandon the form, open the tenant list, find the record, remember something about it, and come
 * back. The picker had all the information — it had the whole model — and showed one string.
 *
 * The same shape repeated 119 times across the panel: units listed by `code` with no floor,
 * leases by `reference` with no tenant, invoices by `number` with no amount and no balance, users
 * by `name` with no role. The one place it had been fixed — the lease picker on the invoice form —
 * was fixed by hand-writing thirty lines of `getSearchResultsUsing` + `getOptionLabelFromRecordUsing`
 * inside that form, which is why it was fixed exactly once.
 *
 * ## The four fields, and why exactly these
 *
 * - **title** — what the operator came looking for. The name, the number, the code.
 * - **code** — the record's OTHER identifier, shown beside the title rather than buried in the
 *   subtitle, because a number is what gets read down a phone line and quoted in an email. A
 *   tenant has both a name and a code and neither is redundant.
 * - **subtitle** — the context that DISAMBIGUATES. This is the field that earns the second line:
 *   not more attributes, but specifically the ones that separate this record from the one above
 *   it. For a tenant that is its unit and property; for an invoice, its due date and what is
 *   still owed.
 * - **badge** — state, when state changes whether you should pick this record at all. An expired
 *   lease and an active one are both legitimate answers to "which lease?", and the difference is
 *   the whole question.
 *
 * There is deliberately no fifth field. A picker is not a report, and every extra token is one
 * more thing between the operator and the row they wanted.
 *
 * ## Escaping is not optional here, and this is the only place it happens
 *
 * A two-line option needs markup, and markup needs `Select::allowHtml()`, which makes Filament
 * emit the label through `{!! !!}` (see `vendor/filament/forms/resources/views/components/select.blade.php`)
 * and hand it to the Alpine renderer as `innerHTML`. Every value in an option is operator-typed —
 * tenant names, unit codes, vendor names — so an unescaped label is stored XSS reachable from any
 * dropdown that lists the record.
 *
 * So: `toHtml()` escapes every dynamic part, and it is the ONLY function in the codebase that
 * builds option markup. `SelectSearchConformanceTest` fails the build on an `allowHtml()` select
 * whose label does not come from here, because "remember to escape" is not a policy — it is a
 * thing someone forgets once, quietly, in the file nobody reviews.
 *
 * `toText()` exists for the places HTML would be wrong rather than merely unnecessary: filter
 * indicator chips (Filament renders those as text), a native `<select>`, exports, and tests —
 * where asserting against markup would pin the markup instead of the meaning.
 */
final class RecordOption implements Htmlable, Stringable
{
    /**
     * The tones a badge may carry.
     *
     * An allowlist rather than free-form, because the tone is interpolated into a CSS class name:
     * a caller passing a computed string would otherwise be able to write attributes into the
     * markup. Anything unrecognised degrades to the neutral tone rather than throwing — a wrong
     * colour is a cosmetic bug, a fatal in a dropdown is a broken form.
     */
    public const TONES = ['success', 'warning', 'danger', 'info', 'gray'];

    public const SEPARATOR = ' · ';

    public function __construct(
        public readonly string $title,
        public readonly ?string $code = null,
        public readonly ?string $subtitle = null,
        public readonly ?string $badge = null,
        public readonly ?string $tone = null,
    ) {}

    public static function make(
        ?string $title,
        ?string $code = null,
        ?string $subtitle = null,
        ?string $badge = null,
        ?string $tone = null,
    ): self {
        return new self(
            // A record with no title at all is a real state — a draft with no number yet, a row
            // mid-import. An em dash keeps it selectable and visibly blank, where an empty string
            // would render as a zero-height option the operator cannot click.
            title: filled($title) ? trim($title) : '—',
            code: filled($code) ? trim($code) : null,
            subtitle: filled($subtitle) ? trim($subtitle) : null,
            badge: filled($badge) ? trim($badge) : null,
            tone: in_array($tone, self::TONES, true) ? $tone : null,
        );
    }

    /**
     * Join the parts of a subtitle, dropping the ones this record does not have.
     *
     * Every subtitle in the registry is built from optional values — a unit may have no floor, a
     * tenant no phone — and the naive `implode` leaves ' ·  · ' scars where the blanks were. This
     * is the reason the registry can write its presenters as a flat list of "whatever is true"
     * instead of a ladder of null checks.
     *
     * @param  array<int, string|int|float|null>  $parts
     */
    public static function join(array $parts): ?string
    {
        $filled = array_values(array_filter(
            array_map(fn ($part): string => trim((string) ($part ?? '')), $parts),
            fn (string $part): bool => $part !== '',
        ));

        return $filled === [] ? null : implode(self::SEPARATOR, $filled);
    }

    /**
     * The same option with one more fact on its second line.
     *
     * For the screen-specific warning that belongs on ONE picker and not on the model everywhere:
     * the lease form warns that a unit is encumbered by an outstanding option, which matters when
     * you are letting the space and is noise on the work-order form. Returning a new instance
     * rather than mutating keeps the registry's presenter reusable — the decorated option is the
     * screen's, not the model's.
     */
    public function append(?string $part): self
    {
        if (blank($part)) {
            return $this;
        }

        return new self(
            title: $this->title,
            code: $this->code,
            subtitle: self::join([$this->subtitle, $part]),
            badge: $this->badge,
            tone: $this->tone,
        );
    }

    /** The same option carrying a different badge — a screen-specific state on a shared presenter. */
    public function withBadge(?string $badge, ?string $tone = null): self
    {
        return new self(
            title: $this->title,
            code: $this->code,
            subtitle: $this->subtitle,
            badge: filled($badge) ? trim($badge) : null,
            tone: in_array($tone, self::TONES, true) ? $tone : $this->tone,
        );
    }

    /**
     * The option as markup — two lines, identity above context, state at the far end.
     *
     * Class names rather than utility classes: this string is emitted once per option and travels
     * over the wire on every keystroke, and — more importantly — Tailwind only generates classes
     * it can see in its `@source` globs, which cover `app/Filament` and the views but not
     * `app/Support`. A utility-class label would render unstyled in production and perfectly in
     * whatever the developer had open. The styles live in `resources/css/filament/theme.css`.
     */
    public function toHtml(): string
    {
        // A literal space between the spans. It costs nothing visually — a flex container
        // discards whitespace-only text nodes between items, and the gap does the spacing — but
        // it is the difference between a screen reader announcing "Cilantro TN-0000002 Active"
        // and "CilantroTN-0000002Active". Filament builds the option's `aria-label` from
        // `textContent`, which concatenates with no separator at all.
        $head = '<span class="atriom-option-title">'.self::isolate($this->title).'</span>';

        if ($this->code !== null) {
            $head .= ' <span class="atriom-option-code">'.self::isolate($this->code).'</span>';
        }

        if ($this->badge !== null) {
            $head .= ' <span class="atriom-option-badge atriom-option-badge-'.($this->tone ?? 'gray').'">'
                .e($this->badge).'</span>';
        }

        $html = '<span class="atriom-option"><span class="atriom-option-head">'.$head.'</span>';

        if ($this->subtitle !== null) {
            // Each PART isolated separately, not the line as a whole: the subtitle is where the
            // Latin tokens live, and they are what the bidi algorithm reorders.
            $parts = array_map(
                fn (string $part): string => self::isolate($part),
                explode(self::SEPARATOR, $this->subtitle),
            );

            $html .= ' <span class="atriom-option-sub">'.implode(e(self::SEPARATOR), $parts).'</span>';
        }

        return $html.'</span>';
    }

    /**
     * Escape a value AND stop the bidi algorithm reordering it.
     *
     * `<bdi>` is not decoration in a bilingual panel. In an Arabic (RTL) paragraph a phone number
     * like `+20100000001` is a run of neutral `+` followed by weak-direction digits, so the
     * algorithm moves the sign to the other end and the operator reads `20100000001+`. Same class
     * of failure for a unit code, an IBAN, a document number — every identifier in an option is a
     * Latin token sitting inside Arabic text.
     *
     * `<bdi>` makes each value its own bidi context, which is exactly the guarantee wanted: the
     * token keeps its internal order, and the LINE still lays out right-to-left around it. Doing it
     * in markup rather than CSS is deliberate — this must hold wherever the option is rendered,
     * including surfaces that never load the panel stylesheet.
     */
    private static function isolate(string $value): string
    {
        return '<bdi>'.e($value).'</bdi>';
    }

    /**
     * The same option as one plain line — for filter chips, native selects, exports and tests.
     *
     * Deliberately the same ORDER as the markup, so an assertion written against the text is an
     * assertion about what the operator sees, not about a parallel format that could drift.
     */
    public function toText(): string
    {
        return (string) self::join([$this->title, $this->code, $this->subtitle, $this->badge]);
    }

    public function __toString(): string
    {
        return $this->toText();
    }
}
