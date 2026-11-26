@php
    use App\Enums\BookingStatus;
    use App\Http\Controllers\Admin\BookingOverviewController;
    use App\Scheduling\LocalTime;

    /**
     * ISSUE-22. Times are stored in UTC and shown in Europe/Amsterdam
     * (docs/decisions.md, ISSUE-14).
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Booking> $bookings
     * @var \Illuminate\Support\Collection<int, \App\Models\Employee> $employees
     * @var \App\Models\Employee|null $employee
     * @var BookingStatus|null $status
     * @var \Carbon\CarbonImmutable $from
     * @var \Carbon\CarbonImmutable $until
     * @var bool $clipped
     * @var array<string, int> $perStatus
     * @var int $bookingCount
     * @var float|null $noShowRate
     * @var list<array{employee: \App\Models\Employee, occupancy: \App\Scheduling\Occupancy}> $occupancy
     * @var \App\Scheduling\Occupancy $totalOccupancy
     */
    $percent = fn (?float $rate): string => $rate === null ? '–' : number_format($rate * 100, 0, ',', '.').'%';
    $hours = fn (int $minutes): string => number_format($minutes / 60, 1, ',', '.').' u';
    $finished = $perStatus[BookingStatus::Completed->value] + $perStatus[BookingStatus::NoShow->value];
@endphp

<x-layout title="Boekingenoverzicht" wide>
    <form method="GET" action="{{ route('admin.bookings.index') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-3 text-sm">
        <div>
            <label for="filter-from" class="block text-gray-600">Van</label>
            <input id="filter-from" type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="rounded border border-gray-300 px-2 py-1">
        </div>
        <div>
            <label for="filter-until" class="block text-gray-600">Tot en met</label>
            <input id="filter-until" type="date" name="until" value="{{ $until->format('Y-m-d') }}" class="rounded border border-gray-300 px-2 py-1">
        </div>
        <div>
            <label for="filter-employee" class="block text-gray-600">Medewerker</label>
            <select id="filter-employee" name="employee" class="rounded border border-gray-300 px-2 py-1">
                <option value="">Alle medewerkers</option>
                @foreach ($employees as $option)
                    <option value="{{ $option->id }}" @selected($employee?->is($option))>{{ $option->display_name }}{{ $option->is_active ? '' : ' (inactief)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status" class="block text-gray-600">Status</label>
            <select id="filter-status" name="status" class="rounded border border-gray-300 px-2 py-1">
                <option value="">Alle statussen</option>
                @foreach (BookingStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ ucfirst($case->label()) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded bg-gray-900 px-3 py-1 text-white">Filteren</button>
        <a href="{{ route('admin.bookings.index') }}" class="py-1 hover:underline">Wis filters</a>
    </form>

    @if ($clipped)
        <p role="status" class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            Een periode is maximaal {{ BookingOverviewController::MAX_DAYS }} dagen; het overzicht loopt tot en met {{ $until->format('d-m-Y') }}.
        </p>
    @endif

    <section aria-labelledby="stats-heading" class="mb-8">
        <h2 id="stats-heading" class="mb-2 text-lg font-semibold">
            Statistiek {{ $from->format('d-m-Y') }} t/m {{ $until->format('d-m-Y') }}{{ $employee ? ' · '.$employee->display_name : '' }}
        </h2>

        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border border-gray-200 bg-white p-3">
                <dt class="text-gray-600">Bezettingsgraad</dt>
                <dd class="text-2xl font-semibold" data-stat="occupancy">{{ $percent($totalOccupancy->rate()) }}</dd>
                <dd class="text-gray-600">{{ $hours($totalOccupancy->bookedMinutes) }} geboekt van {{ $hours($totalOccupancy->workingMinutes) }} werktijd</dd>
            </div>
            <div class="rounded border border-gray-200 bg-white p-3">
                <dt class="text-gray-600">No-shows</dt>
                <dd class="text-2xl font-semibold" data-stat="no-show-rate">{{ $percent($noShowRate) }}</dd>
                <dd class="text-gray-600">{{ $perStatus[BookingStatus::NoShow->value] }} van {{ $finished }} afgeronde afspraken</dd>
            </div>
            <div class="rounded border border-gray-200 bg-white p-3">
                <dt class="text-gray-600">Boekingen</dt>
                <dd class="text-2xl font-semibold" data-stat="bookings">{{ $bookingCount }}</dd>
                <dd class="text-gray-600">
                    {{ implode(', ', array_map(fn (BookingStatus $case): string => $case->label().': '.$perStatus[$case->value], BookingStatus::cases())) }}
                </dd>
            </div>
        </dl>

        @if ($employee === null && count($occupancy) > 1)
            <table class="mt-3 w-full rounded border border-gray-200 bg-white text-sm">
                <caption class="sr-only">Bezettingsgraad per medewerker</caption>
                <thead class="text-left text-gray-600">
                    <tr>
                        <th scope="col" class="p-2">Medewerker</th>
                        <th scope="col" class="p-2 text-right">Werktijd</th>
                        <th scope="col" class="p-2 text-right">Geboekt</th>
                        <th scope="col" class="p-2 text-right">Bezetting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($occupancy as $row)
                        <tr>
                            <th scope="row" class="p-2 text-left font-normal">{{ $row['employee']->display_name }}</th>
                            <td class="p-2 text-right">{{ $hours($row['occupancy']->workingMinutes) }}</td>
                            <td class="p-2 text-right">{{ $hours($row['occupancy']->bookedMinutes) }}</td>
                            <td class="p-2 text-right">{{ $percent($row['occupancy']->rate()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="mt-2 text-xs text-gray-600">
            Bezetting: het deel van de werktijd (rooster, feestdagen en uitzonderingen) dat door een niet-geannuleerde boeking is gevuld.
            No-shows: het aandeel niet verschenen van de voltooide en niet verschenen afspraken. Het statusfilter telt hier niet mee.
        </p>
    </section>

    <section aria-labelledby="list-heading">
        <h2 id="list-heading" class="mb-2 text-lg font-semibold">
            Boekingen{{ $status ? ' · '.$status->label() : '' }} ({{ $bookings->total() }})
        </h2>

        @if ($bookings->isEmpty())
            <p class="text-sm text-gray-600">Er zijn geen boekingen die aan de filters voldoen.</p>
        @else
            <table class="w-full rounded border border-gray-200 bg-white text-sm">
                <thead class="text-left text-gray-600">
                    <tr>
                        <th scope="col" class="p-2">Datum en tijd</th>
                        <th scope="col" class="p-2">Dienst</th>
                        <th scope="col" class="p-2">Klant</th>
                        <th scope="col" class="p-2">Medewerker</th>
                        <th scope="col" class="p-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($bookings as $booking)
                        <tr>
                            <td class="p-2">
                                <a href="{{ route('bookings.show', $booking) }}" class="font-medium hover:underline">{{ LocalTime::dateTime($booking->starts_at) }}</a>
                            </td>
                            <td class="p-2">{{ $booking->service->name }}</td>
                            <td class="p-2">{{ $booking->customer->name }}</td>
                            <td class="p-2">{{ $booking->employee->display_name }}</td>
                            <td class="p-2">{{ $booking->status->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">{{ $bookings->links() }}</div>
        @endif
    </section>
</x-layout>
