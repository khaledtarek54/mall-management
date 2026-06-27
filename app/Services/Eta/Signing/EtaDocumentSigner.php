<?php

namespace App\Services\Eta\Signing;

/**
 * Applies the ETA-required digital signature (CAdES-BES, per the Egyptian
 * e-invoicing spec) to a document before submission.
 *
 * ETA PRODUCTION rejects unsigned B2B documents. The signature is produced from
 * the operator's certificate, normally held on a USB/HSM token or a cloud key
 * vault — an external dependency the operator provisions. Implement this contract
 * once that certificate is available and bind it in AppServiceProvider (replacing
 * the default {@see UnsignedEtaSigner}); the rest of the submission pipeline is
 * unchanged. Until then the unsigned signer is a no-op for mock/preprod plumbing.
 */
interface EtaDocumentSigner
{
    /**
     * @param  array<string,mixed>  $documentJson  the ETA invoice document
     * @return array<string,mixed>  the document ready to submit (with a `signatures` array when signed)
     */
    public function sign(array $documentJson): array;

    /** Whether this implementation actually applies a signature (false = passthrough). */
    public function isSigning(): bool;
}
