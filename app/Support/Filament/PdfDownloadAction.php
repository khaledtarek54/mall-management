<?php

namespace App\Support\Filament;

use App\Support\Pdf\DocumentLocale;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **Download a document, and say which language it is written in.**
 *
 * Fifteen call sites each repeated the same six lines — resolve the service, build, `streamDownload`
 * with the filename and `application/pdf` — and every one of them rendered in `app()->getLocale()`,
 * the language of whoever pressed the button. So an operator working the panel in Arabic sent an
 * Arabic invoice to a retailer whose accountant files in English, with no way to produce the other
 * copy short of changing their own UI language, downloading again, and changing it back.
 *
 * This is that button, once, with the choice on it. It extends {@see AuthorizedAction} rather than
 * Filament's `Action` so the container's authorization layer still applies — a shared action class
 * that quietly stepped outside that seam would be a hole in it, and these are the actions most
 * likely to be copied for a new document.
 *
 * ## The default is the RECIPIENT's language, not the operator's
 *
 * `->recipient()` names the party the document is addressed to, and their stored `locale` pre-selects
 * the picker. That is the whole point: the common case is one click on a modal that already says the
 * right thing, and the picker exists for the case the stored preference cannot know about — a
 * tenant's foreign auditor, a landlord's lawyer who asked for the English copy. See
 * {@see DocumentLocale} for the full resolution order.
 *
 * A document with no counterparty — a trial balance, a work log — passes no recipient and defaults
 * to the language the operator is reading the panel in, which for an internal report is correct.
 *
 * ## Two shapes
 *
 * Most services are `build($record, ?string $locale)` + `filename($record)`, which {@see service()}
 * takes in one line. The five whose `build()` takes a period, an ID list or a scope label use
 * {@see document()} and {@see filename()} directly.
 */
class PdfDownloadAction extends AuthorizedAction
{
    /** The form field holding the operator's choice. Named once; read once. */
    public const LANGUAGE_FIELD = 'language';

    /** @var (Closure(mixed, string): string)|null returns the PDF bytes */
    protected ?Closure $documentUsing = null;

    /** @var (Closure(mixed): string)|string|null */
    protected Closure|string|null $filenameUsing = null;

    /** @var (Closure(mixed): ?object)|null the party the document is addressed to */
    protected ?Closure $recipientUsing = null;

    /** A language fixed by the call site — no picker is offered. */
    protected Closure|string|null $fixedLocale = null;

    public static function getDefaultName(): ?string
    {
        return 'downloadPdf';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('admin.actions.pdf'));
        $this->icon(Heroicon::OutlinedArrowDownTray);
        $this->color('gray');
        $this->modalWidth('sm');
        $this->modalSubmitActionLabel(__('admin.actions.download'));
        $this->modalIcon(Heroicon::OutlinedArrowDownTray);

        // A schema turns the action into a modal. Offered only when there is a genuine choice: with
        // one supported language, or a language the call site fixed, a modal asking nothing is pure
        // friction on a button people press all day.
        $this->schema(fn (mixed $record): array => $this->languageSchema($record));

        $this->action(fn (mixed $record, array $data): StreamedResponse => $this->stream($record, $data));

        // Deliberately no `->authorize()` here. It writes a single slot, so setting it in setUp()
        // would let any call site silently REPLACE the seam rather than narrow it — the mistake
        // eight relation managers made with the CRUD actions. A call site that needs a gate declares
        // its own; these are read-only renders of a record the reader can already see, which is what
        // `App\Support\ActionAuthz::EXEMPT` records for each of them.
    }

    /**
     * The common shape: a service with `build($record, ?string $locale)` and `filename($record)`.
     *
     * @param  class-string  $service
     */
    public function service(string $service): static
    {
        $this->documentUsing = fn (mixed $record, string $locale): string => app($service)->build($record, $locale);
        $this->filenameUsing = fn (mixed $record): string => app($service)->filename($record);

        return $this;
    }

    /**
     * Build the bytes yourself — for a service whose `build()` takes more than a record.
     *
     * The closure receives the resolved locale as its second argument and MUST pass it on. A
     * document built without it renders in whatever the request happens to be in, which is the
     * defect this whole action exists to remove and is invisible from the call site.
     *
     * @param  Closure(mixed, string): string  $callback
     */
    public function document(Closure $callback): static
    {
        $this->documentUsing = $callback;

        return $this;
    }

    /** @param  (Closure(mixed): string)|string  $filename */
    public function filename(Closure|string $filename): static
    {
        $this->filenameUsing = $filename;

        return $this;
    }

    /**
     * The party this document is addressed to — a Tenant, a Vendor, an Employee, a User.
     *
     * Their stored language pre-selects the picker. Anything with `preferredLocale()` or a `locale`
     * attribute; null is fine and means "no counterparty", not "unknown".
     *
     * @param  Closure(mixed): ?object  $callback
     */
    public function recipient(Closure $callback): static
    {
        $this->recipientUsing = $callback;

        return $this;
    }

    /**
     * Fix the language and drop the picker.
     *
     * For a document whose language is not the operator's to choose — one rendered to be filed
     * against a fixed-language submission, for instance. Not used today; it exists so that when such
     * a document appears the answer is a call-site line rather than a second action class.
     *
     * @param  (Closure(mixed): string)|string  $locale
     */
    public function language(Closure|string $locale): static
    {
        $this->fixedLocale = $locale;

        return $this;
    }

    /** @return array<int, Radio> */
    protected function languageSchema(mixed $record): array
    {
        if ($this->fixedLocale !== null || count(DocumentLocale::options()) < 2) {
            return [];
        }

        return [
            Radio::make(self::LANGUAGE_FIELD)
                ->label(__('admin.pdf.language'))
                ->helperText(__('admin.pdf.language_hint'))
                ->options(DocumentLocale::options())
                ->default($this->defaultLocale($record))
                ->required()
                // The value is re-clamped in DocumentLocale::resolve(), so this rule is the
                // operator's error message rather than the guard: a Livewire payload reaches the
                // action whatever the radio rendered.
                ->in(array_keys(DocumentLocale::options())),
        ];
    }

    protected function defaultLocale(mixed $record): string
    {
        return DocumentLocale::resolve(null, $this->resolveRecipient($record));
    }

    protected function resolveRecipient(mixed $record): ?object
    {
        $recipient = $this->recipientUsing ? $this->evaluate($this->recipientUsing, ['record' => $record]) : null;

        return is_object($recipient) ? $recipient : null;
    }

    /** @param array<string, mixed> $data */
    protected function stream(mixed $record, array $data): StreamedResponse
    {
        $locale = $this->fixedLocale !== null
            ? (string) $this->evaluate($this->fixedLocale, ['record' => $record])
            : ($data[self::LANGUAGE_FIELD] ?? null);

        $locale = DocumentLocale::resolve(
            is_string($locale) ? $locale : null,
            $this->resolveRecipient($record),
        );

        $pdf = ($this->documentUsing)($record, $locale);

        $filename = is_string($this->filenameUsing)
            ? $this->filenameUsing
            : (string) $this->evaluate($this->filenameUsing, ['record' => $record]);

        return Response::streamDownload(
            fn () => print ($pdf),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
