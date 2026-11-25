{{-- Confirmation mail (ISSUE-19). Times are stored in UTC, shown in Europe/Amsterdam (ISSUE-14). --}}
<x-mail::message>
# Je afspraak is geboekt

Hallo {{ $booking->customer->name }},

Bedankt voor je boeking. Hieronder staan de gegevens van je afspraak.

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

@if (filled($booking->notes))
**Je opmerking:** {{ $booking->notes }}
@endif

Zet de afspraak in je agenda met het bijgevoegde bestand ({{ \App\Bookings\BookingCalendarEvent::FILENAME }}).

Annuleren of verplaatsen kan tot {{ \App\Scheduling\LocalTime::dateTime($booking->cancellationDeadline()) }}.

<x-mail::button :url="$url">
Bekijk je afspraak
</x-mail::button>

Tot ziens,<br>
{{ config('app.name') }}
</x-mail::message>
