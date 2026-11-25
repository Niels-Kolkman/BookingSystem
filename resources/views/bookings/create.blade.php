@php
    /**
     * Booking page (ISSUE-15). Rendered completely on the server, so it also
     * works without JavaScript; resources/js/booking-flow.js takes over the
     * `data-booking-*` elements and loads the free times live.
     *
     * @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Service> $services
     * @var \App\Models\Service|null $service
     * @var \App\Models\Employee|null $employee
     * @var string|null $date
     * @var string $today
     * @var list<\App\Scheduling\Slot>|null $slots
     */
    $catalogue = $services->map(fn (\App\Models\Service $s): array => [
        'id' => $s->id,
        'employees' => $s->employees->map(fn (\App\Models\Employee $e): array => ['id' => $e->id, 'name' => $e->display_name])->values(),
    ])->values();
    $oldStart = is_string(old('starts_at')) ? old('starts_at') : null;
    $optionClass = 'flex cursor-pointer items-center gap-2 rounded border border-gray-300 bg-white px-3 py-2 text-sm has-[:checked]:border-gray-900 has-[:checked]:bg-gray-100 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-gray-500';
    $legendClass = 'mb-2 text-sm font-semibold';
@endphp

<x-layout title="Afspraak maken">
    @if ($services->isEmpty())
        <p class="text-sm text-gray-600">Er zijn op dit moment geen diensten die je online kunt boeken.</p>
    @else
        <div data-booking-flow data-slots-url="{{ route('bookings.slots') }}">
            <script type="application/json" data-booking-catalogue>@json($catalogue)</script>

            <form method="GET" action="{{ route('bookings.create') }}" data-booking-choose class="space-y-6">
                <fieldset>
                    <legend class="{{ $legendClass }}">1. Kies een dienst</legend>
                    <div class="space-y-2">
                        @foreach ($services as $option)
                            <label class="{{ $optionClass }}">
                                <input type="radio" name="service_id" value="{{ $option->id }}" @checked($service?->is($option))>
                                <span class="flex-1">{{ $option->name }}</span>
                                <span class="text-gray-600">{{ $option->duration_minutes }} min · {{ $option->formattedPrice() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset data-booking-step="employee" @if (! $service) hidden @endif>
                    <legend class="{{ $legendClass }}">2. Kies een medewerker</legend>
                    <div class="space-y-2" data-booking-employees data-option-class="{{ $optionClass }}">
                        @foreach ($service?->employees ?? [] as $option)
                            <label class="{{ $optionClass }}">
                                <input type="radio" name="employee_id" value="{{ $option->id }}" @checked($employee?->is($option))>
                                <span>{{ $option->display_name }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset data-booking-step="date" @if (! $employee) hidden @endif>
                    <legend class="{{ $legendClass }}">3. Kies een datum</legend>
                    <label for="date" class="sr-only">Datum</label>
                    <input id="date" name="date" type="date" min="{{ $today }}" value="{{ $date ?? $today }}"
                           class="block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none">
                </fieldset>

                {{-- Without JavaScript every step goes back to the server; the script hides this button. --}}
                <button type="submit" data-booking-next class="rounded border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-100">
                    Verder
                </button>
            </form>

            <form method="POST" action="{{ route('bookings.store') }}" data-booking-book class="mt-6 space-y-6"
                  @if ($slots === null) hidden @endif>
                @csrf
                <input type="hidden" name="service_id" value="{{ $service?->id }}">
                <input type="hidden" name="employee_id" value="{{ $employee?->id }}">

                <div role="alert" data-booking-error
                     class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                     @if (! $errors->any()) hidden @endif>
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>

                <fieldset>
                    <legend class="{{ $legendClass }}">4. Kies een tijd</legend>
                    <p aria-live="polite" data-booking-status class="mb-2 text-sm text-gray-600">@if ($slots === [])Er zijn geen vrije tijden op deze dag. Kies een andere datum.@endif</p>
                    <div class="grid grid-cols-3 gap-2" data-booking-slots data-option-class="{{ $optionClass }} justify-center">
                        @foreach ($slots ?? [] as $slot)
                            <label class="{{ $optionClass }} justify-center">
                                <input type="radio" name="starts_at" value="{{ $slot->inputValue() }}" @checked($oldStart === $slot->inputValue()) class="sr-only">
                                <span>{{ $slot->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label for="notes" class="mb-1 block text-sm font-medium">Opmerking (optioneel)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"
                              class="block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none">{{ is_string(old('notes')) ? old('notes') : '' }}</textarea>
                </div>

                <button type="submit" data-booking-submit
                        class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50">
                    Afspraak boeken
                </button>
            </form>
        </div>
    @endif
</x-layout>
