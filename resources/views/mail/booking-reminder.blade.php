{{-- Reminder mail (ISSUE-20). Times are stored in UTC, shown in Europe/Amsterdam (ISSUE-14). --}}
<x-mail::message>
# Herinnering aan je afspraak

Hallo {{ $booking->customer->name }},

Je hebt binnenkort een afspraak bij ons. Hieronder staan de gegevens.

<x-mail::table>
| | |
|:--|:--|
| **Dienst** | {{ $booking->service->name }} |
| **Medewerker** | {{ $booking->employee->display_name }} |
| **Datum en tijd** | {{ \App\Scheduling\LocalTime::dateTime($booking->starts_at) }} - {{ \App\Scheduling\LocalTime::time($booking->ends_at) }} |
| **Duur** | {{ $booking->duration_minutes }} minuten |
| **Prijs** | € {{ number_format($booking->price_cents / 100, 2, ',', '.') }} |
| **Status** | {{ $booking->status->label() }} |
</x-mail::table>

@if ($cancellationDeadline)
Annuleren of verplaatsen kan nog tot {{ \App\Scheduling\LocalTime::dateTime($cancellationDeadline) }}.
@else
Kun je niet komen? Laat het ons dan zo snel mogelijk weten.
@endif

<x-mail::button :url="$url">
Bekijk je afspraak
</x-mail::button>

Tot ziens,<br>
{{ config('app.name') }}
</x-mail::message>
