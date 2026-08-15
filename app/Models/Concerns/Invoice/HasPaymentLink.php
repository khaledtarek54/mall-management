<?php

namespace App\Models\Concerns\Invoice;

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

    /** Whether there is still a balance that can be collected online. */
    public function isPayable(): bool
    {
        return ! in_array($this->status, ['cancelled', 'credited', 'written_off'], true)
            && round((float) $this->balance, 2) > 0;
    }
}
