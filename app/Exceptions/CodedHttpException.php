<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An HTTP refusal that also NAMES itself — so a client branches on a code and never parses a sentence.
 *
 * The API's error envelope is `{message, statusCode}` and the message is translated, so two refusals
 * sharing a status were indistinguishable to a client that must not read prose. Two of them shared 403
 * and meant opposite things: `EnsureTenantActive` (the COMPANY is blocked — the token has just been
 * destroyed, go to the Blocked screen) and `EnsurePortalAdminForWrites` (this PERSON may read but not
 * act — the session is fine, only this button is not theirs). The app told them apart by guessing from
 * the HTTP method and whether the token survived (mobile §L L14; their §9 #132).
 *
 * The API renderer in bootstrap/app.php adds `error` beside the envelope when an exception carries a
 * code — the key the Paymob endpoints already send (`paymob_disabled`, `use_real_payment`), and one
 * lowercase word, so the response casing cannot alter it. On the web it renders like any HttpException.
 *
 * The first exception class in this app, and the bar for the next: a refusal a client must ACT on
 * differently from another with the same status. One it only has to show stays an `abort()`.
 */
class CodedHttpException extends HttpException
{
    public function __construct(int $statusCode, string $message, private readonly string $errorCode)
    {
        parent::__construct($statusCode, $message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
