<?php

namespace App\Services\OwnerAccounting;

use App\Models\OwnerStatement;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders an owner statement (كشف حساب المالك) to a PDF — the deliverable the owner receives:
 * the property's income − expenses = net for the period, and the owner's share of it. Bilingual
 * (Arabic RTL for the accountant/owner), mpdf, returned as a string for streaming/storage.
 */
class OwnerStatementPdfService
{
    public function build(OwnerStatement $statement): string
    {
        $statement->loadMissing(['run.asset', 'run.accountingPeriod', 'owner']);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('owner-statements.statement', [
            'statement' => $statement,
            'run' => $statement->run,
            'asset' => $statement->run->asset,
            'owner' => $statement->owner,
        ])->render();

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'default_font' => $isRtl ? 'xbriyaz' : 'dejavusans',
            'default_font_size' => 10.5,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(OwnerStatement $statement): string
    {
        return 'Owner-Statement-'.str($statement->reference)->slug().'.pdf';
    }
}
