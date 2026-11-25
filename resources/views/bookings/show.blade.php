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
        @if ($booking->cancelled_at)
            <div>
                <dt class="text-gray-600">Geannuleerd</dt>
                <dd>
                    {{ \App\Scheduling\LocalTime::dateTime($booking->cancelled_at) }}
                    @if ($booking->cancelledBy)
                        door {{ $booking->cancelledBy->isAdmin() ? 'de beheerder' : $booking->cancelledBy->name }}
                    @endif
                    @if ($booking->cancellation_reason)
                        &middot; {{ $booking->cancellation_reason }}
                    @endif
                </dd>
            </div>
        @endif
    </dl>

    {{-- Moving (ISSUE-17): rules in BookingPolicy::reschedule(). --}}
    @if ($booking->status->blocksTime())
        @can('reschedule', $booking)
            <a href="{{ route('bookings.reschedule', $booking) }}"
               class="mt-4 inline-block rounded border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-100">
                Afspraak verplaatsen
            </a>
        @endcan
    @endif

    {{-- Cancelling (ISSUE-16): rules in BookingPolicy::cancel(). --}}
    @if ($booking->status->blocksTime())
        @can('cancel', $booking)
            <form method="POST" action="{{ route('bookings.cancel', $booking) }}" class="mt-4 space-y-2 rounded border border-gray-200 bg-white p-4 text-sm">
                @csrf
                <h2 class="font-semibold">Afspraak annuleren</h2>
                @if (auth()->user()?->isCustomer())
                    <p class="text-gray-600">Je kunt deze afspraak annuleren tot {{ \App\Scheduling\LocalTime::dateTime($booking->cancellationDeadline()) }}.</p>
                @endif
                <div>
                    <label for="cancellation_reason" class="block text-gray-600">Reden (optioneel)</label>
                    <input id="cancellation_reason" name="cancellation_reason" type="text" maxlength="{{ \App\Bookings\CancelBooking::REASON_MAX_LENGTH }}"
                           value="{{ is_string(old('cancellation_reason')) ? old('cancellation_reason') : '' }}"
                           @error('cancellation_reason') aria-invalid="true" aria-describedby="cancellation_reason-error" @enderror
                           class="block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none">
                    @error('cancellation_reason')
                        <p id="cancellation_reason-error" class="mt-1 text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="rounded border border-red-300 bg-white px-4 py-2 text-red-700 hover:bg-red-50">
                    Afspraak annuleren
                </button>
            </form>
        @elseif (auth()->user()?->isCustomer())
            <p class="mt-4 text-sm text-gray-600">{{ \App\Policies\BookingPolicy::pastDeadlineMessage() }}</p>
        @endif
    @endif

    <a href="{{ route('bookings.index') }}" class="mt-4 inline-block text-sm hover:underline">Terug naar boekingen</a>
</x-layout>
