<?php

namespace App\Support;

use App\Models\DocumentTemplate;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * The standing wording on a tenant-facing document — the operator's, if they have written it
 * (EG-15 slice 1, finding S-6).
 *
 * Resolution order, and it is the same shape every catalogue here uses:
 *
 *   1. an active row for this block **on this property**;
 *   2. an active row for this block with no property — the house default;
 *   3. the **FLOOR**: the translation key the document has always used, or nothing at all for a
 *      block that did not exist before.
 *
 * The floor is what makes this safe to deploy. An install with no rows renders exactly what it
 * rendered yesterday, and the operator adopts one block at a time.
 *
 * ## Tokens are a closed set, and the text is escaped
 *
 * `{days}` and friends are substituted from `$tokens`; anything else is left alone rather than
 * blanked, because a tenant reading `{}` in a footer can report it and a silently deleted sentence
 * cannot be noticed. The body is operator-typed and rendered into a PDF and later into mail, so it
 * is **plain text** and callers escape it — see the migration for why this is not a rich editor.
 *
 * ## Why a registry rather than a free-text key
 *
 * `KEYS` is the list of blocks a document actually renders. A template written for a slot nothing
 * reads is the inert-settings-screen failure: the operator writes it, saves it, and nothing happens.
 * `ValueSets` refuses a key outside this list at the model layer, and the conformance test requires
 * every key here to name a floor that exists or to say deliberately that it has none.
 */
final class DocumentText
{
    /**
     * The blocks a document renders, their floor, and the tokens each may use.
     *
     * `floor` is the translation key this block fell back to before it was operator-editable; null
     * means the block is NEW and simply does not render until somebody writes it.
     *
     * @var array<string, array{floor: ?string, tokens: list<string>}>
     */
    /**
     * The same set as {@see KEYS}, flat.
     *
     * `ValueSets::expand()` resolves a `[Class, 'CONST']` reference through `constant()`, and a
     * const cannot have computed keys — so the machine-readable list has to be its own constant.
     * `DocumentTextKeysAreRegisteredTest` asserts the two agree, which is what stops them drifting
     * into a key the column accepts and the resolver ignores.
     *
     * @var list<string>
     */
    public const KEY_NAMES = [
        'invoice.footer',
        'invoice.payment_instructions',
        'invoice.terms',
        'dunning.overdue_reminder',
        'dunning.late_fee_applied',
        'receipt.payment_received',
        'lease.expiry_approaching',
        'dunning.overdue_subject',
        'dunning.late_fee_subject',
        'receipt.payment_subject',
        'lease.expiry_subject',
        'invoice.email_body',
    ];

    public const KEYS = [
        // The line under every invoice. Its floor hardcodes three payment rails — "Bank transfer /
        // Card / InstaPay" — which stopped being true the day rails became an operator catalogue.
        'invoice.footer' => ['floor' => 'admin.pdf.footer', 'tokens' => ['days']],

        // NEW. There was nowhere on an invoice to say where to pay, so a tenant holding one could
        // not know. No floor: an install that has not written it renders no block, rather than a
        // heading with nothing under it.
        'invoice.payment_instructions' => ['floor' => null, 'tokens' => []],

        // NEW. Late-payment terms, disputes window, whatever the lease says in general.
        'invoice.terms' => ['floor' => null, 'tokens' => []],

        // SLICE 2 (EG-15). The dunning notice — the one message where the WORDING is the whole
        // artefact. A chasing email that reads as a system alert gets ignored; an operator wants
        // their own sentence, and a mall chasing an anchor tenant does not write what it writes to
        // a kiosk. The floor is the lang key the notification always used, so an install that has
        // written nothing sends exactly what it sent before.
        //
        // Tokens are the three figures the message cannot be written without. `:amount` arrives
        // already formatted, because a template author cannot be asked to think about thousands
        // separators.
        'dunning.overdue_reminder' => [
            'floor' => 'admin.notifications.invoice_overdue_reminder_mail',
            'tokens' => ['number', 'days', 'amount'],
        ],

        // A penalty notice. Yardi templates this for the reason an operator would give: a late fee
        // is the message most likely to be argued with, and the sentence that announces it is the
        // one a leasing manager wants to have written themselves.
        'dunning.late_fee_applied' => [
            'floor' => 'admin.notifications.late_fee_applied_mail',
            'tokens' => ['fee', 'number', 'balance'],
        ],

        // The acknowledgement. Short, and the one message a tenant is pleased to get — which is
        // exactly why an operator wants their own voice in it rather than the system's.
        'receipt.payment_received' => [
            'floor' => 'admin.notifications.payment_received_body',
            'tokens' => ['amount', 'method', 'date'],
        ],

        // The renewal conversation's opening line. Commercially the most valuable of the four: it
        // is the first thing said about whether the tenant is staying.
        'lease.expiry_approaching' => [
            'floor' => 'admin.notifications.lease_expiry_mail',
            'tokens' => ['unit', 'days', 'date'],
        ],

        // ── SUBJECT LINES ────────────────────────────────────────────────────────────────────
        //
        // The body is only read if the subject earns the open, so templating one without the other
        // gives an operator half a message. Registered as their own blocks rather than folded into
        // the body: a subject is a different piece of writing with a different constraint (short,
        // no newlines), and one field holding both would invite a paragraph into a mail header.
        //
        // `substitute()` is plain token replacement, so a newline typed into a subject would reach
        // the header — `MailMessage::subject()` is where that would land. Guarded at the resolver
        // rather than trusted: see `forSubject()`.
        'dunning.overdue_subject' => [
            'floor' => 'admin.notifications.invoice_overdue_reminder_subject',
            'tokens' => ['number'],
        ],
        'dunning.late_fee_subject' => [
            'floor' => 'admin.notifications.late_fee_applied_subject',
            'tokens' => ['number'],
        ],
        'receipt.payment_subject' => [
            'floor' => 'admin.notifications.payment_received_subject',
            'tokens' => ['reference'],
        ],
        'lease.expiry_subject' => [
            'floor' => 'admin.notifications.lease_expiry_subject',
            'tokens' => ['reference'],
        ],

        // The covering note on the monthly invoice EMAIL — the one message every tenant gets every
        // month, and the last exemption `TenantFacingWordingIsTheOperatorsConformanceTest` carried.
        // It was waived because the notification renders a markdown VIEW rather than `->line()`, so
        // templating it meant reaching into the blade; that is a reason to do it carefully, not a
        // reason to leave the most-read sentence in the product un-editable.
        'invoice.email_body' => [
            'floor' => 'admin.email.invoice_issued_body',
            'tokens' => ['number', 'due_date'],
        ],
    ];

    /**
     * The text for `$key` on `$assetId`'s documents, or null when there is nothing to render.
     *
     * @param  array<string, string|int|float>  $tokens
     */
    public static function for(string $key, ?int $assetId = null, array $tokens = []): ?string
    {
        if (! array_key_exists($key, self::KEYS)) {
            return null;
        }

        $body = self::operatorText($key, $assetId);

        if ($body !== null) {
            return self::substitute($body, $tokens);
        }

        $floor = self::KEYS[$key]['floor'];

        // The floor is a translation key and takes Laravel's own `:token` replacements, not ours.
        return $floor !== null && Lang::has($floor) ? __($floor, $tokens) : null;
    }

    /**
     * The same resolution, flattened for a mail HEADER.
     *
     * A subject is one line by definition, and `substitute()` is plain token replacement — so an
     * operator who presses Enter in the subject field would otherwise put a newline into a header.
     * Depending on the transport that is either a stripped character or a header-injection attempt,
     * and neither is something to leave to the operator's typing. Collapsed to single spaces and
     * trimmed here, once, rather than at four call sites.
     *
     * @param  array<string, string|int|float>  $tokens
     */
    public static function forSubject(string $key, ?int $assetId = null, array $tokens = []): ?string
    {
        $text = self::for($key, $assetId, $tokens);

        if ($text === null) {
            return null;
        }

        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $flat === '' ? null : $flat;
    }

    /** Is there anything at all to render for this block? Lets a template skip its whole section. */
    public static function has(string $key, ?int $assetId = null, array $tokens = []): bool
    {
        return filled(self::for($key, $assetId, $tokens));
    }

    /**
     * The operator's own text: this property's row, else the portfolio row.
     *
     * Not memoised. A PDF render asks for three blocks once, and a per-request cache keyed by
     * (key, asset, locale) would be three entries to invalidate for no measurable gain — the
     * memo on `ChargeCode` exists because it is asked per invoice LINE.
     */
    private static function operatorText(string $key, ?int $assetId): ?string
    {
        $rows = DocumentTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->when(
                $assetId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('asset_id')->orWhere('asset_id', $assetId)),
                fn ($q) => $q->whereNull('asset_id'),
            )
            // The property's own row wins. `orderByRaw` on a nullability test rather than
            // `orderByDesc('asset_id')`, which puts NULL first on one driver and last on the other.
            ->orderByRaw('CASE WHEN asset_id IS NULL THEN 1 ELSE 0 END')
            ->get();

        foreach ($rows as $row) {
            if (filled($body = $row->bodyFor(App::getLocale()))) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Replace `{token}` with its value.
     *
     * An unknown token is LEFT AS WRITTEN. Blanking it would delete a sentence silently; leaving
     * `{amont}` visible on an invoice gets it reported and fixed.
     *
     * @param  array<string, string|int|float>  $tokens
     */
    private static function substitute(string $body, array $tokens): string
    {
        foreach ($tokens as $name => $value) {
            $body = str_replace('{'.$name.'}', (string) $value, $body);
        }

        return $body;
    }
}
