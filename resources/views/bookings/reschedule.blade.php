@php
    /**
     * Move a booking to another time (ISSUE-17). Rendered on the server, no
     * script: the date form reloads this page with the free times of that
     * date, the second form posts the chosen time.
     *
     * @var \App\Models\Booking $booking
     * @var string $date
     * @var string $today
     * @var list<\App\Scheduling\Slot> $slots
     */
    $oldStart = is_string(old('starts_at')) ? old('starts_at') : null;
    $optionClass = 'flex cursor-pointer items-center justify-center gap-2 rounded border border-gray-300 bg-white px-3 py-2 text-sm has-[:checked]:border-gray-900 has-[:checked]:bg-gray-100 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-gray-500';
@endphp

<x-layout title="Afspraak verplaatsen">
    <p class="mb-6 text-sm text-gray-600">
        {{ $booking->service->name }} bij {{ $booking->employee->display_name }},
        nu op {{ \App\Scheduling\LocalTime::dateTime($booking->starts_at) }}.
        De huidige tijd blijft gereserveerd tot het verplaatsen gelukt is.
    </p>

    <form method="GET" action="{{ route('bookings.reschedule', $booking) }}" class="space-y-2">
        <label for="date" class="block text-sm font-semibold">1. Kies een datum</label>
        <div class="flex gap-2">
            <input id="date" name="date" type="date" min="{{ $today }}" value="{{ $date }}"
                   class="block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none">
            <button type="submit" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-100">
                Toon tijden
            </button>
        </div>
    </form>

    <form method="POST" action="{{ route('bookings.reschedule.store', $booking) }}" class="mt-6 space-y-6">
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <fieldset>
            <legend class="mb-2 text-sm font-semibold">2. Kies een nieuwe tijd</legend>
            @if ($slots === [])
                <p class="text-sm text-gray-600">Er zijn geen vrije tijden op deze dag. Kies een andere datum.</p>
            @else
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($slots as $slot)
                        <label class="{{ $optionClass }}">
                            <input type="radio" name="starts_at" value="{{ $slot->inputValue() }}" @checked($oldStart === $slot->inputValue()) class="sr-only">
                            <span>{{ $slot->label() }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        @if ($slots !== [])
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
                Afspraak verplaatsen
            </button>
        @endif
    </form>

    <a href="{{ route('bookings.show', $booking) }}" class="mt-4 inline-block text-sm hover:underline">Terug naar de afspraak</a>
</x-layout>
