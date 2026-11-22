@php
    use App\Enums\ScheduleExceptionType;
    use App\Enums\Weekday;

    /**
     * @var \App\Models\Employee $employee
     * @var \Illuminate\Support\Collection<string, array{schedule: \App\Scheduling\DaySchedule, exceptions: \Illuminate\Support\Collection<int, \App\Models\ScheduleException>}> $days
     * @var list<array{holiday: \App\Scheduling\Holiday, schedule: \App\Scheduling\DaySchedule}> $holidays
     * @var string $today
     */
    $dateLabel = fn (\DateTimeInterface $date): string => ucfirst(Weekday::fromDate($date)->label()).' '.$date->format('d-m-Y');
    $inputClass = 'block w-full rounded border border-gray-300 px-2 py-1 focus:border-gray-500 focus:outline-none';
    $selectedType = old('type', ScheduleExceptionType::Unavailable->value);
@endphp

<x-layout :title="'Uitzonderingen voor '.$employee->display_name">
    <p class="mb-6 text-sm text-gray-600">
        Afwijkingen van het <a href="{{ route('admin.employees.schedule.edit', $employee) }}" class="underline">werkrooster</a>
        voor een bepaalde datum. Afwezigheid gaat altijd voor; extra uren komen bovenop het rooster en gelden ook op feestdagen.
    </p>

    <form method="POST" action="{{ route('admin.employees.exceptions.store', $employee) }}" novalidate class="mb-10 space-y-4">
        @csrf

        <fieldset>
            <legend class="mb-1 text-sm font-medium">Soort</legend>
            @foreach (ScheduleExceptionType::cases() as $type)
                <label class="mr-4 inline-flex items-center gap-1 text-sm">
                    <input type="radio" name="type" value="{{ $type->value }}" @checked($selectedType === $type->value)>
                    {{ $type->label() }}
                </label>
            @endforeach
            @error('type')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="grid grid-cols-2 gap-4">
            @foreach (['date' => 'Datum', 'until' => 'Tot en met (optioneel)'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="date" min="{{ $today }}"
                           value="{{ is_string(old($field)) ? old($field) : '' }}"
                           @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                           class="{{ $inputClass }}">
                    @error($field)
                        <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-4">
            @foreach (['starts_at' => 'Van', 'ends_at' => 'Tot'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="time"
                           value="{{ is_string(old($field)) ? old($field) : '' }}"
                           @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                           class="{{ $inputClass }}">
                    @error($field)
                        <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">Laat de tijden leeg voor een hele dag afwezig. Extra uren hebben altijd tijden nodig.</p>

        <div>
            <label for="reason" class="mb-1 block text-sm font-medium">Omschrijving (optioneel)</label>
            <input id="reason" name="reason" type="text" maxlength="255" placeholder="Bijvoorbeeld: vakantie"
                   value="{{ is_string(old('reason')) ? old('reason') : '' }}"
                   @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror
                   class="{{ $inputClass }}">
            @error('reason')
                <p id="reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">Toevoegen</button>
            <a href="{{ route('admin.employees.index') }}" class="text-sm hover:underline">Terug naar medewerkers</a>
        </div>
    </form>

    <h2 class="mb-2 text-lg font-semibold">Geplande uitzonderingen</h2>
    @if ($days->isEmpty())
        <p class="mb-10 text-sm text-gray-600">Er zijn geen uitzonderingen gepland.</p>
    @else
        <ul class="mb-10 divide-y divide-gray-200 rounded border border-gray-200 bg-white">
            @foreach ($days as $day)
                <li class="p-3 text-sm">
                    <div class="font-medium">
                        {{ $dateLabel($day['schedule']->date) }}
                        @if ($day['schedule']->holiday)
                            <span class="ml-1 text-gray-600">({{ $day['schedule']->holiday->name }})</span>
                        @endif
                    </div>
                    <ul class="mt-1 space-y-1">
                        @foreach ($day['exceptions'] as $exception)
                            <li class="flex items-center justify-between gap-4">
                                <span>
                                    {{ $exception->type->label() }}: {{ $exception->timeLabel() }}
                                    @if ($exception->reason)
                                        <span class="text-gray-600">- {{ $exception->reason }}</span>
                                    @endif
                                </span>
                                <form method="POST" action="{{ route('admin.employees.exceptions.destroy', [$employee, $exception]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-700 hover:underline">
                                        Verwijderen<span class="sr-only"> ({{ $exception->type->label() }}, {{ $dateLabel($exception->date) }})</span>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-1 text-gray-600">Werktijden op deze dag: {{ $day['schedule']->label() }}</div>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mb-2 text-lg font-semibold">Feestdagen</h2>
    <p class="mb-2 text-sm text-gray-600">
        Op gesloten feestdagen is niemand te boeken. Voeg extra uren toe om {{ $employee->display_name }} toch te laten werken.
    </p>
    <ul class="divide-y divide-gray-200 rounded border border-gray-200 bg-white">
        @foreach ($holidays as ['holiday' => $holiday, 'schedule' => $schedule])
            <li class="flex justify-between gap-4 p-3 text-sm">
                <span>
                    <span class="font-medium">{{ $holiday->name }}</span>
                    <span class="text-gray-600">{{ $dateLabel($holiday->date) }}</span>
                    @unless ($holiday->isClosed())
                        <span class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-700">open</span>
                    @endunless
                </span>
                <span class="text-gray-600">{{ $schedule->label() }}</span>
            </li>
        @endforeach
    </ul>
</x-layout>
