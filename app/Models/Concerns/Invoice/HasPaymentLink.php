<?php

namespace App\Models\Concerns\Invoice;

use App\Support\InvoiceSettlement;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * **The tenant-facing online payment link, its token, and its QR code.**
 *
 * The cleanest cut in `Invoice`, and the reason it moved first: no money semantics, no event hooks,
 * no shared state. It touches `payment_link_token` and reads `status`/`balance`, and the four
 * BaconQrCode imports leave the model with it.
 *
 * It is deliberately NOT part of the AR money core. Nothing here participates in
 * `recomputeTotals()` or in the four settlement channels — a payment link is a way to *reach* an
 * invoice, not a statement about what has been paid against it.
 */
trait HasPaymentLink
{
    /**
     * Stable, unguessable token behind the public pay link. Lazily generated +
     * persisted on first access, so existing invoices get one on demand.
     */
    public function paymentLinkToken(): string
    {
        if (blank($this->payment_link_token)) {
            $this->forceFill(['payment_link_token' => Str::random(48)])->save();
        }

        return $this->payment_link_token;
    }

    /**
     * Mint a NEW pay token, killing every URL previously issued for this invoice.
     *
     * The pay link is a bearer credential: whoever holds the URL can read the
     * tenant's name, the line items and the amounts, with no login and no expiry.
     * That is fine while it sits in the addressee's inbox and useless afterwards —
     * except links leak. They get forwarded, land in shared or wrong inboxes, sit
     * in browser history on a shop-floor PC, and survive in screenshots.
     *
     * Without this there is no remedy for that: the operator cannot take the link
     * back. Rotation is the remedy — and the reason it is not an expiry is that an
     * expiry would silently kill legitimate links in already-sent emails, turning
     * every late payer into a support call. Rotation is deliberate and per-invoice.
     *
     * Safe mid-payment: an in-flight Paymob session is keyed by the gateway's
     * order id, not by this token, so rotating never strands a payment that is
     * already at the gateway — the browser return resolves the CURRENT token.
     */
    public function rotatePaymentLinkToken(): string
    {
        // forceFill + save, matching paymentLinkToken(): the column is guarded, and
        // this must persist even on an issued invoice (the immutability guard covers
        // GL-identity fields, not the pay token).
        $this->forceFill(['payment_link_token' => Str::random(48)])->save();

        return $this->payment_link_token;
    }

    /** Public, no-login URL a client can open to pay this invoice. */
    public function paymentLinkUrl(): string
    {
        return route('pay.show', ['token' => $this->paymentLinkToken()]);
    }

    /** Inline SVG QR code of the pay link, for scan-to-pay (no GD/imagick needed). */
    public function paymentLinkQrSvg(int $size = 170): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($this->paymentLinkUrl());

        // Strip the XML prolog so the SVG embeds cleanly inside HTML.
        return (string) preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg);
    }

    /**
     * Whether a TENANT may still put money on this invoice online.
     *
     * **One question with one answer, and it had four.** This was a hand-rolled denylist of three
     * statuses beside `App\Support\InvoiceSettlement` — the register built for exactly this
     * question, with a reason written against every status on both sides of the partition. The
     * portal's own View page then carried two MORE opinions three lines apart: `canPayDemo()`
     * repeated the same three, and `canPayNow()` — the one that opens a LIVE gateway — checked no
     * status at all, so a written-off invoice offered real card checkout while the demo button
     * beside it correctly refused.
     *
     * The status the denylist missed is `draft`. Measured over the real route: a draft invoice
     * answered **200** at `/pay/{token}` to an unauthenticated visitor, naming the tenant and the
     * amount, and would take the money — an eighth surface for the invariant that says the tenant
     * never sees a draft, and the only one with no login in front of it. `InvoiceSettlement` had
     * refused `draft` since the day it was written, under a reason explaining that nothing was ever
     * posted so cash against it credits a receivable that does not exist.
     *
     * @see payableAmount() for the other half — WHICH invoice and HOW MUCH are two questions.
     */
    public function isPayable(): bool
    {
        return InvoiceSettlement::accepts($this) && $this->payableAmount() > 0;
    }

    /**
     * The most a tenant may be charged for this invoice — `balance` net of anything written off.
     *
     * The other half of the same defect, and the one that moves money. Every gateway path charged
     * the raw `balance`: `PaymobPaymentInitiator` built its session, its allocation and its
     * reuse-check from it, `RecordDemoPaymentAction` captured it, and the public pay page printed
     * it. So a 10,000 invoice with 6,000 forgiven asked a tenant for 10,000 — measured on the real
     * page, which showed 10,000 and never 4,000. Paying it drives AR negative for that debt and
     * leaves bad-debt expense standing for money that was collected, which is the permanently red
     * `billing:reconcile --deep` that blocks the next deploy.
     *
     * `InvoiceSettlement::settleableAmount()` already answered this and was already load-bearing —
     * SEVEN call sites, capping the payment form, tenant credit, credit notes and post-dated
     * cheques. **It was applied on every channel an OPERATOR drives and on none a TENANT drives**,
     * which is the more useful way to state the gap: the careful netting went in beside the code
     * whose author was thinking about write-offs, and the public pay link, the portal and the
     * mobile API were each written by somebody thinking about a gateway.
     *
     * It composes correctly with the write-off: paying 4,000 leaves `balance` at 6,000, which the
     * write-off has already relieved, so `collectableBalance()` reads 0 and the invoice is finished
     * from both sides.
     */
    public function payableAmount(): float
    {
        return InvoiceSettlement::settleableAmount($this);
    }
}
