<?php

use App\Models\Tenant;
use App\Models\TenantRequest;

/**
 * Regression — mobile §L L13. The reply box is offered where a reply will be taken, and nowhere else.
 *
 * `TenantRequestService::comment()` refuses a reply on a closed or cancelled request with a 422 — and
 * the resource had `canCancel`, `canRate` and `canConfirm` and no `canComment`, so the app offered the
 * box on every ticket and a finished one threw the tenant's typed words away. The flag is the refusal's
 * own predicate, `! isTerminal()`, so the two cannot disagree.
 *
 * Two tests, because either alone passes a wrong change: the SEMANTICS (a resolved ticket takes a reply
 * — that is how a tenant says "not quite" — and `is_open` is the wrong flag for exactly that reason),
 * and the AGREEMENT on every status the model defines, derived from `TenantRequest::STATUSES` so a
 * status added later is covered by existing. Agreement alone would pass a flag and a server that were
 * wrong together.
 */
function requestInStatus(Tenant $tenant, string $status): TenantRequest
{
    return makeTenantRequest(['tenant_id' => $tenant->id, 'status' => $status]);
}

it('offers the reply box on a resolved ticket and withholds it on a closed or cancelled one', function (string $status, bool $offered) {
    $tenant = makeTenant();
    $request = requestInStatus($tenant, $status);

    $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.canComment', $offered);
})->with([
    'in progress' => ['in_progress', true],
    'resolved — still takes a reply, unlike isOpen' => ['resolved', true],
    'closed' => ['closed', false],
    'cancelled' => ['cancelled', false],
]);

it('says exactly what the server will do with the reply, on every status the model defines', function () {
    $tenant = makeTenant();
    $headers = apiHeaders($tenant);
    $disagreements = [];

    foreach (TenantRequest::STATUSES as $status) {
        $request = requestInStatus($tenant, $status);

        $offered = $this->getJson("/api/v1/me/requests/{$request->id}", $headers)->json('data.canComment');
        $taken = $this->postJson("/api/v1/me/requests/{$request->id}/comments", ['body' => 'Still dripping.'], $headers)
            ->isSuccessful();

        if ($offered !== $taken) {
            $disagreements[] = $status.': canComment '.json_encode($offered).', server '.($taken ? 'took it' : 'refused it');
        }
    }

    expect($disagreements)->toBe([], "The flag and the server disagree:\n  - ".implode("\n  - ", $disagreements));
});
