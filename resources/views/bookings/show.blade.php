<x-layout title="Boeking">
    <dl class="space-y-2 rounded border border-gray-200 bg-white p-4 text-sm">
        <div>
            <dt class="text-gray-600">Datum en tijd</dt>
            <dd>
                {{-- Stored in UTC, shown in Europe/Amsterdam (ISSUE-14). --}}
                {{ \App\Scheduling\LocalTime::dateTime($booking->starts_at) }}
                - {{ \App\Scheduling\LocalTime::time($booking->ends_at) }}
            </dd>
        </div>
        <div>
            <dt class="text-gray-600">Dienst</dt>
            <dd>{{ $booking->service->name }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Medewerker</dt>
            <dd>{{ $booking->employee->display_name }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Klant</dt>
            <dd>{{ $booking->customer->name }}</dd>
        </div>
        <div>
            <dt class="text-gray-600">Status</dt>
            <dd>{{ $booking->status->value }}</dd>
        </div>
    </dl>

    <a href="{{ route('bookings.index') }}" class="mt-4 inline-block text-sm hover:underline">Terug naar boekingen</a>
</x-layout>
