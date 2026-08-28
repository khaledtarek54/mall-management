<?php

namespace App\Services\OwnerAccounting;

use App\Models\OwnerStatement;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * Renders an owner statement (كشف حساب المالك) to a PDF — the deliverable the owner receives:
 * the property's income − expenses = net for the period, and the owner's share of it. Bilingual
 * (Arabic RTL for the accountant/owner), mpdf, returned as a string for streaming/storage.
 */
class OwnerStatementPdfService
{
    /**
     * The owner statement as a PDF, in the language the OWNER reads.
     *
     * This is the document Jawad actually receives — an account of his own money rendered by his
     * managing agent — so it follows his stored language rather than the accounting clerk's.
     */
    public function build(OwnerStatement $statement, ?string $locale = null): string
    {
        $statement->loadMissing(['run.asset', 'run.accountingPeriod', 'owner']);

        return PdfDocument::make('owner-statements.statement')
            ->locale(DocumentLocale::resolve($locale, $statement->owner))
            ->data(fn (): array => [
                'statement' => $statement,
                'run' => $statement->run,
                'asset' => $statement->run->asset,
                'owner' => $statement->owner,
                // The run's property: the statement is issued BY the managing agent ABOUT the
                // property, and passing the asset is what puts that mall's logo beside the issuer.
                ...IssuingEntity::forView($statement->run->asset),
            ])
            ->reference($statement->reference)
            ->render();
    }

    public function filename(OwnerStatement $statement): string
    {
        return 'Owner-Statement-'.str($statement->reference)->slug().'.pdf';
    }
}
