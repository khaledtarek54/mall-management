{{--
    The shell every issued document is built in.

    Twelve templates each opened with the same twenty lines — doctype, `dir`, charset, title, a
    <style> block, a masthead table — and each had its own copy of all of it. This is that opening,
    once. A template now starts at the thing that makes it that document.

    `$isRtl` arrives from `App\Support\Pdf\PdfDocument`, which sets the app locale around the whole
    render, so a template that still derives direction itself agrees with the page mpdf set up.

    Sections a document fills:
      · `document` — the right of the masthead: what this is, its number, its state
      · `content`  — the body (REQUIRED)
      · `closing`  — the operator's own words at the foot; omitted, nothing is drawn
      · `masthead` — the whole masthead, for a document whose header is not issuer-and-reference
--}}
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
        <table class="masthead">
            <tr>
                <td class="issuer">@include('pdf._issuer')</td>
                <td class="document">@yield('document')</td>
            </tr>
        </table>
    @endif

    <div class="masthead-rule"></div>

    @yield('content')

    @hasSection('closing')
        <div class="closing">@yield('closing')</div>
    @endif
</body>
</html>
