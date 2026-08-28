<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\TenantStatementPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/statement — streams the tenant's Statement of Account PDF. Same service the
 * portal "Download Statement" action uses.
 *
 * Accepts an optional `from` / `to` window; without one it is the documented 12-month trailing
 * period. The parameters exist because a client cannot otherwise say what the document covers —
 * the app was computing a range from the device clock and printing it beside a server-built PDF,
 * which is a figure nobody can vouch for.
 */
class StatementController extends ApiController
{
    public function __invoke(Request $request, TenantStatementPdfService $pdf): Response
    {
        $tenant = $request->user();

        return $this->streamPdf(
            $pdf->build($tenant, null, $request->date('from'), $request->date('to'), $this->documentLocale($request)),
            $pdf->filename($tenant),
        );
    }
}
