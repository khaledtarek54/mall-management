{{--
    The shell every issued document is built in.

    Twelve templates each opened with the same twenty lines — doctype, `dir`, charset, title, a
    <style> block, a masthead table — and each had its own copy of all of it. This is that opening,
    once. A template now starts at the thing that makes it that document.

    **Direction D's band bleeds to the paper edge**, which is the whole difference between a masthead
    and a coloured box. That is only possible if the PAGE has no side margins, so every service that
    renders a template extending this shell calls `PdfDocument::bleed()` and the body below supplies
    its own margin through `.page-body`. `PdfLayoutBleedsConformanceTest` fails the build if a
    template extends this and its service forgets — the symptom otherwise is a band that stops 13mm
    short of the edge, which reads as a rendering fault rather than as a missing call.

    `$isRtl` arrives from `App\Support\Pdf\PdfDocument`, which sets the app locale around the whole
    render, so a template that still derives direction itself agrees with the page mpdf set up.

    Sections a document fills:
      · `document` — the right of the band: what this is, its number, its state
      · `content`  — the body (REQUIRED)
      · `closing`  — the operator's own words at the foot; omitted, nothing is drawn
      · `masthead` — the whole band, for a document whose header is not issuer-and-reference
--}}
@php
    // `App\Support\Pdf\PdfDocument` passes this in, and it is the only production caller. Derived
    // here as a fallback because a TEST — and any future caller that renders the document without
    // going through the renderer — has no reason to know the variable exists, and an undefined
    // `$isRtl` is a fatal rather than a wrong direction. It agrees with the renderer either way:
    // the renderer sets the app locale before rendering, so this reads the same answer.
    $isRtl = $isRtl ?? \App\Support\Pdf\DocumentLocale::isRtl();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? '' }}</title>
    @include('pdf._styles')
    @yield('styles')
</head>
<body>
    @hasSection('masthead')
        @yield('masthead')
    @else
        <div class="band">
            <table>
                <tr>
                    <td class="issuer">@include('pdf._issuer')</td>
                    <td class="document">@yield('document')</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="page-body">
        @yield('content')

        @hasSection('closing')
            <div class="closing">@yield('closing')</div>
        @endif
    </div>
</body>
</html>
