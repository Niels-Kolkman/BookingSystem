@php
    use App\Enums\BookingStatus;
    use App\Enums\Weekday;
    use App\Http\Controllers\AgendaController;
    use App\Scheduling\LocalTime;

    /**
     * ISSUE-21. Laid out in local wall-clock time; times are stored in UTC
     * and shown in Europe/Amsterdam (docs/decisions.md, ISSUE-14).
     *
     * @var \App\Models\Employee $employee
     * @var string $view 'day' or 'week'
     * @var \Carbon\CarbonImmutable $date
     * @var list<\App\Scheduling\AgendaDay> $days
     * @var array{int, int} $hours visible window, minutes since midnight
     * @var string $previous
     * @var string $next
     * @var string $today
     */
    $isWeek = $view === AgendaController::VIEW_WEEK;
    [$windowStart, $windowEnd] = $hours;
    $span = $windowEnd - $windowStart;
    $rem = ($span / 60) * ($isWeek ? 3 : 4);

    $place = fn (int $start, int $end): string => sprintf(
        'top: %.3f%%; height: %.3f%%;',
        ($start - $windowStart) / $span * 100,
        ($end - $start) / $span * 100,
    );
    $clock = fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $dayName = fn (\DateTimeInterface $d): string => ucfirst(Weekday::fromDate($d)->label());
    $link = fn (string $view, string $date): string => route('employees.agenda', [$employee, 'view' => $view, 'date' => $date]);

    $first = $days[0]->date();
    $last = $days[array_key_last($days)]->date();
    $period = $isWeek
        ? 'Week '.$first->isoWeek().': '.$first->format('d-m-Y').' t/m '.$last->format('d-m-Y')
        : $dayName($first).' '.$first->format('d-m-Y');
    $bookingCount = array_sum(array_map(fn ($day): int => count($day->bookings), $days));
@endphp

<x-layout :title="'Agenda van '.$employee->display_name" wide>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm">
        <nav aria-label="Weergave" class="inline-flex overflow-hidden rounded border border-gray-300 bg-white">
            @foreach ([AgendaController::VIEW_DAY => 'Dag', AgendaController::VIEW_WEEK => 'Week'] as $option => $label)
                <a href="{{ $link($option, $date->format('Y-m-d')) }}"
                   @if ($option === $view) aria-current="page" @endif
                   @class(['px-3 py-1', 'bg-gray-900 text-white' => $option === $view, 'hover:bg-gray-100' => $option !== $view])>{{ $label }}</a>
            @endforeach
        </nav>

        <nav aria-label="Periode" class="flex items-center gap-3">
            <a href="{{ $link($view, $previous) }}" class="hover:underline">&larr; {{ $isWeek ? 'Vorige week' : 'Vorige dag' }}</a>
            <a href="{{ $link($view, $today) }}" class="hover:underline">Vandaag</a>
            <a href="{{ $link($view, $next) }}" class="hover:underline">{{ $isWeek ? 'Volgende week' : 'Volgende dag' }} &rarr;</a>
        </nav>

        <form method="GET" action="{{ route('employees.agenda', $employee) }}" class="flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            <label for="agenda-date" class="sr-only">Ga naar datum</label>
            <input id="agenda-date" name="date" type="date" value="{{ $date->format('Y-m-d') }}"
                   class="rounded border border-gray-300 px-2 py-1">
            <button type="submit" class="rounded border border-gray-300 bg-white px-3 py-1 hover:bg-gray-100">Ga</button>
        </form>
    </div>

    <p class="mb-2 font-medium">{{ $period }}</p>

    @if ($bookingCount === 0)
        <p class="mb-2 text-sm text-gray-600">
            {{ $isWeek ? 'Er staan geen afspraken in deze week.' : 'Er staan geen afspraken op deze dag.' }}
        </p>
    @endif

    <ul class="mb-4 flex flex-wrap gap-4 text-xs text-gray-600" aria-label="Legenda">
        <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-gray-300 bg-white"></span> Werktijd</li>
        <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-blue-300 bg-blue-100"></span> Afspraak</li>
        <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-dashed border-blue-400 bg-blue-50"></span> Aangevraagd</li>
        <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-dashed border-amber-400 bg-amber-50"></span> Pauze</li>
        <li class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-rose-300 bg-rose-100"></span> Afwezig</li>
    </ul>

    <div class="overflow-x-auto rounded border border-gray-200 bg-white">
        <div class="grid"
             style="grid-template-columns: 3.5rem repeat({{ count($days) }}, minmax({{ $isWeek ? '7.5rem' : '0' }}, 1fr));">
            {{-- Header row: one cell per day. --}}
            <div class="border-b border-gray-200"></div>
            @foreach ($days as $day)
                @php
                    $holiday = $day->holiday();
                    $dayOff = $day->wholeDayOff();
                @endphp
                <div @class(['border-b border-l border-gray-200 p-2 text-sm', 'bg-blue-50' => $day->isToday()])
                     data-agenda-day="{{ $day->date()->format('Y-m-d') }}">
                    <h2 class="font-medium">
                        @if ($isWeek)
                            <a href="{{ $link(AgendaController::VIEW_DAY, $day->date()->format('Y-m-d')) }}" class="hover:underline">
                                {{ $dayName($day->date()) }} {{ $day->date()->format('d-m') }}
                            </a>
                        @else
                            {{ $dayName($day->date()) }} {{ $day->date()->format('d-m') }}
                        @endif
                        @if ($day->isToday())
                            <span class="ml-1 text-xs font-normal text-blue-700">vandaag</span>
                        @endif
                    </h2>
                    @if ($holiday)
                        <p class="text-xs text-purple-700">{{ $holiday->name }}{{ $holiday->isClosed() ? ' (gesloten)' : '' }}</p>
                    @endif
                    @if ($dayOff)
                        <p class="text-xs text-rose-700">Afwezig, hele dag{{ $dayOff->reason ? ': '.$dayOff->reason : '' }}</p>
                    @elseif (! $day->schedule->isAvailable() && ! ($holiday?->isClosed() ?? false))
                        <p class="text-xs text-gray-500">Vrij</p>
                    @endif
                </div>
            @endforeach

            {{-- Hour labels. --}}
            <div class="relative" style="height: {{ $rem }}rem;" aria-hidden="true">
                @for ($minute = $windowStart; $minute < $windowEnd; $minute += 60)
                    <span class="absolute right-2 text-xs text-gray-500" style="top: {{ sprintf('%.3f', ($minute - $windowStart) / $span * 100) }}%;">
                        {{ $clock($minute) }}
                    </span>
                @endfor
            </div>

            {{-- One column per day. Items are in time order, so they read in order without the layout. --}}
            @foreach ($days as $day)
                <div class="relative border-l border-gray-200 bg-gray-50" style="height: {{ $rem }}rem;">
                    @for ($minute = $windowStart + 60; $minute < $windowEnd; $minute += 60)
                        <div class="absolute inset-x-0 border-t border-gray-100" style="top: {{ sprintf('%.3f', ($minute - $windowStart) / $span * 100) }}%;" aria-hidden="true"></div>
                    @endfor

                    @foreach ($day->schedule->ranges as $range)
                        <div class="absolute inset-x-0 border-y border-gray-200 bg-white" style="{{ $place($range->start, $range->end) }}"
                             data-agenda-item="working">
                            <span class="sr-only">Werktijd {{ $range->label() }}</span>
                        </div>
                    @endforeach

                    @if ($day->wholeDayOff())
                        <div class="absolute inset-0 bg-rose-50/70" aria-hidden="true"></div>
                    @endif

                    @foreach ($day->schedule->breaks as $break)
                        <div class="absolute inset-x-1 overflow-hidden rounded border border-dashed border-amber-400 bg-amber-50 px-1 text-xs text-amber-900"
                             style="{{ $place($break->start, $break->end) }}" title="Pauze {{ $break->label() }}"
                             data-agenda-item="break">
                            Pauze {{ $break->label() }}
                        </div>
                    @endforeach

                    @foreach ($day->partialTimeOff() as $off)
                        <div class="absolute inset-x-1 overflow-hidden rounded border border-rose-300 bg-rose-100 px-1 text-xs text-rose-900"
                             style="{{ $place($off->range()->start, $off->range()->end) }}"
                             title="Afwezig {{ $off->timeLabel() }}{{ $off->reason ? ': '.$off->reason : '' }}"
                             data-agenda-item="time-off">
                            Afwezig {{ $off->timeLabel() }}
                            @if ($off->reason)
                                <span class="block truncate">{{ $off->reason }}</span>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($day->bookings as $item)
                        @php
                            $booking = $item->booking;
                            $requested = $booking->status === BookingStatus::Requested;
                            $past = in_array($booking->status, [BookingStatus::Completed, BookingStatus::NoShow], true);
                        @endphp
                        <a href="{{ route('bookings.show', $booking) }}"
                           @class([
                               'absolute inset-x-1 z-10 block min-h-5 overflow-hidden rounded border px-1 text-xs leading-tight hover:z-20 hover:shadow focus:z-20',
                               'border-dashed border-blue-400 bg-blue-50 text-blue-950' => $requested,
                               'border-gray-300 bg-gray-100 text-gray-600' => $past,
                               'border-blue-300 bg-blue-100 text-blue-950' => ! $requested && ! $past,
                           ])
                           style="{{ $place($item->start, $item->end) }}"
                           title="{{ $item->timeLabel() }} {{ $booking->service->name }}, {{ $booking->customer->name }}"
                           data-agenda-item="booking">
                            <span class="sr-only">{{ LocalTime::dateTime($booking->starts_at) }}:</span>
                            <span class="font-medium">{{ $item->timeLabel() }}</span>
                            <span class="block truncate">{{ $booking->service->name }}</span>
                            <span class="block truncate">{{ $booking->customer->name }}</span>
                            @if ($requested || $past)
                                <span class="block italic">{{ $booking->status->label() }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</x-layout>
