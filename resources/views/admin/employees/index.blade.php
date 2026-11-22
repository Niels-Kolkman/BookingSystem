@php
    use App\Enums\Weekday;
@endphp

<x-layout title="Medewerkers">
    @if ($employees->isEmpty())
        <p class="text-sm text-gray-600">Er zijn nog geen medewerkers.</p>
    @else
        <ul class="divide-y divide-gray-200 rounded border border-gray-200 bg-white">
            @foreach ($employees as $employee)
                <li class="p-3 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="font-medium">{{ $employee->display_name }}</span>
                            @unless ($employee->is_active)
                                <span class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-700">inactief</span>
                            @endunless

                            @if ($employee->workingHours->isEmpty())
                                <div class="text-amber-700">Nog geen werkrooster: deze medewerker kan nog niet geboekt worden.</div>
                            @else
                                <dl class="mt-1 text-gray-600">
                                    @foreach (Weekday::cases() as $weekday)
                                        @php($blocks = $employee->workingHoursOn($weekday))
                                        @if ($blocks->isNotEmpty())
                                            <div class="flex gap-2">
                                                <dt class="w-20">{{ ucfirst($weekday->label()) }}</dt>
                                                <dd>{{ $blocks->map->label()->implode(', ') }}</dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            @endif
                        </div>

                        <a href="{{ route('admin.employees.schedule.edit', $employee) }}" class="shrink-0 hover:underline">
                            Werkrooster bewerken
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layout>
