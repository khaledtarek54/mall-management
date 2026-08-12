<x-mail::message>
# {{ $name }}

{{ __('mail.saved_report.body', ['count' => $rowCount]) }}

{{ __('mail.saved_report.footer') }}
</x-mail::message>
