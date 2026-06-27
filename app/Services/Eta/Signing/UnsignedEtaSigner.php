<?php

namespace App\Services\Eta\Signing;

/**
 * Default no-op signer: returns the document unchanged.
 *
 * Lets the OAuth + submission plumbing run against ETA's mock/preproduction
 * environment before a real signing certificate is provisioned. NOT valid for
 * ETA production — bind a real {@see EtaDocumentSigner} (CAdES) in
 * AppServiceProvider before going live. EtaApiClient throws if signing is
 * enabled (ETA_SIGNING_ENABLED=true) while this passthrough is still bound, so
 * an unsigned document can never be submitted under the illusion of compliance.
 */
class UnsignedEtaSigner implements EtaDocumentSigner
{
    public function sign(array $documentJson): array
    {
        return $documentJson;
    }

    public function isSigning(): bool
    {
        return false;
    }
}
