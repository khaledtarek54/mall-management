<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\TenantStatementPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/statement — streams the tenant's Statement of Account PDF
 * (12-month trailing window of invoices + payments). Same service the portal
 * "Download Statement" action uses.
 */
class StatementController extends ApiController
{
    public function __invoke(Request $request, TenantStatementPdfService $pdf): Response
    {
        $tenant = $request->user();

        return $this->streamPdf($pdf->build($tenant), $pdf->filename($tenant));
    }
}
