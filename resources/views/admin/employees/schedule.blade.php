@php
    use App\Enums\Weekday;
    use App\Http\Requests\Admin\ScheduleRequest;

    /** @var \App\Models\Employee $employee */
    $hasOldInput = session()->hasOldInput();
    $emptyRow = ['starts_at' => null, 'ends_at' => null];
@endphp

<x-layout :title="'Werkrooster van '.$employee->display_name">
    <p class="mb-6 text-sm text-gray-600">
        Vul per dag de tijden in waarop {{ $employee->display_name }} werkt. Gebruik twee blokken voor een pauze,
        bijvoorbeeld 09:00-12:30 en 13:30-17:00. Laat een dag leeg als dat een vrije dag is.
    </p>

    @error('blocks')
        <div role="alert" class="mb-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('admin.employees.schedule.update', $employee) }}" novalidate>
        @csrf
        @method('PUT')

        @foreach (Weekday::cases() as $weekday)
            @php
                $day = $weekday->value;

                // After a validation error, show the submitted rows with their
                // original keys so the errors line up with their inputs.
                // Otherwise the saved blocks plus a spare row.
                if ($hasOldInput) {
                    $rows = array_filter((array) old("blocks.{$day}", []), 'is_array');
                } else {
                    $rows = $employee->workingHoursOn($weekday)
                        ->map(fn ($block) => ['starts_at' => $block->starts_at, 'ends_at' => $block->ends_at])
                        ->all();
                    $rows[] = $emptyRow;
                }
                // At least two rows, so a day with a lunch break fits.
                while (count($rows) < 2) {
                    $rows[] = $emptyRow;
                }
                $rows = array_slice($rows, 0, ScheduleRequest::MAX_BLOCKS_PER_DAY, true);
            @endphp

            <fieldset class="mb-6">
                <legend class="mb-2 text-sm font-medium">{{ ucfirst($weekday->label()) }}</legend>

                @error("blocks.{$day}")
                    <p class="mb-2 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="space-y-2">
                    @foreach ($rows as $index => $row)
                        @php
                            $base = "blocks.{$day}.{$index}";
                            $idBase = "blocks-{$day}-{$index}";
                            $blockLabel = $weekday->label().', blok '.($loop->index + 1);
                            $rowError = $errors->first("{$base}.starts_at") ?: $errors->first("{$base}.ends_at");
                        @endphp
                        <div>
                            <div class="flex items-center gap-2 text-sm">
                                @foreach (['starts_at' => 'Van', 'ends_at' => 'tot'] as $field => $fieldLabel)
                                    <label for="{{ $idBase }}-{{ $field }}">
                                        {{ $fieldLabel }}<span class="sr-only"> ({{ $blockLabel }})</span>
                                    </label>
                                    <input
                                        id="{{ $idBase }}-{{ $field }}"
                                        name="blocks[{{ $day }}][{{ $index }}][{{ $field }}]"
                                        type="time"
                                        value="{{ is_string($row[$field] ?? null) ? $row[$field] : '' }}"
                                        @error("{$base}.{$field}") aria-invalid="true" @enderror
                                        @if ($rowError) aria-describedby="{{ $idBase }}-error" @endif
                                        class="block w-28 rounded border border-gray-300 px-2 py-1 focus:border-gray-500 focus:outline-none"
                                    >
                                @endforeach
                            </div>
                            @if ($rowError)
                                <p id="{{ $idBase }}-error" class="mt-1 text-sm text-red-600">{{ $rowError }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <p class="mb-6 text-xs text-gray-500">
            Meer blokken nodig op een dag? Sla op, dan verschijnt er een extra lege regel
            (maximaal {{ ScheduleRequest::MAX_BLOCKS_PER_DAY }} per dag). Bestaande afspraken blijven staan.
        </p>

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
                Opslaan
            </button>
            <a href="{{ route('admin.employees.index') }}" class="text-sm hover:underline">Annuleren</a>
        </div>
    </form>
</x-layout>
