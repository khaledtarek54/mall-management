{{--
    The mall's logo on a document header.

    One partial, included by every PDF that renders an issuer block, so a template cannot be missed
    the way twelve of them would be if each embedded its own <img>. `$issuerLogo` comes from
    `IssuingEntity::forView()`, which every one of those templates already receives.

    A LOCAL PATH, not a URL — mpdf renders server-side and a URL makes the document depend on the
    box fetching its own public address. Absent or unreadable, this renders nothing and the text
    header stands alone, which is what these documents did before.
--}}
@if (! empty($issuerLogo))
    <img src="{{ $issuerLogo }}" alt="" style="max-height: 48px; max-width: 180px; margin-bottom: 6px;">
@endif
