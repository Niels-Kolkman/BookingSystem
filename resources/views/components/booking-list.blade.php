@props(['bookings', 'empty' => 'Er zijn geen boekingen.'])

{{-- Times are stored in UTC and shown in Europe/Amsterdam (docs/decisions.md, ISSUE-14). --}}
@if ($bookings->isEmpty())
    <p class="text-sm text-gray-600">{{ $empty }}</p>
@else
    <ul class="divide-y divide-gray-200 rounded border border-gray-200 bg-white">
        @foreach ($bookings as $booking)
            <li class="p-3 text-sm">
                <a href="{{ route('bookings.show', $booking) }}" class="font-medium hover:underline">
                    {{ $booking->starts_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}
                    &middot; {{ $booking->service->name }}
                </a>
                <div class="text-gray-600">
                    Klant: {{ $booking->customer->name }} &middot; Medewerker: {{ $booking->employee->display_name }}
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-4">{{ $bookings->links() }}</div>
@endif
