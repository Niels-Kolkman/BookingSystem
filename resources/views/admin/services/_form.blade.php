@php
    use App\Http\Requests\Admin\ServiceRequest;

    /** @var \App\Models\Service $service */
    $price = $service->exists ? number_format($service->price_cents / 100, 2, ',', '') : null;
    $isActive = (bool) old('is_active', $service->is_active ?? true);
@endphp

@csrf

<x-input name="name" label="Naam" :value="$service->name" required maxlength="100" autofocus />

<div class="mb-4">
    <label for="description" class="mb-1 block text-sm font-medium">Omschrijving (optioneel)</label>
    <textarea
        id="description"
        name="description"
        rows="3"
        maxlength="1000"
        @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
        class="block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none"
    >{{ old('description', $service->description) }}</textarea>
    @error('description')
        <p id="description-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<x-input
    name="duration_minutes"
    label="Duur (minuten)"
    type="number"
    :value="$service->duration_minutes"
    required
    min="5"
    :max="ServiceRequest::MAX_DURATION_MINUTES"
    hint="Hoe lang de afspraak zelf duurt."
/>

<x-input
    name="buffer_minutes"
    label="Buffertijd na de afspraak (minuten)"
    type="number"
    :value="$service->buffer_minutes"
    required
    min="0"
    :max="ServiceRequest::MAX_BUFFER_MINUTES"
    hint="Tijd na de afspraak waarin de medewerker niet geboekt kan worden, bijvoorbeeld om op te ruimen."
/>

<x-input
    name="price"
    label="Prijs (€)"
    :value="$price"
    required
    inputmode="decimal"
    hint="Bijvoorbeeld 25 of 25,50."
/>

<div class="mb-6">
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked($isActive) class="rounded border-gray-300">
        Actief (klanten kunnen deze dienst boeken)
    </label>
</div>

@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Employee> $employees */
    $checkedEmployeeIds = array_map('intval', (array) old('employee_ids', $linkedEmployeeIds));
    // Errors on single entries (employee_ids.0, ...) are shown as one message.
    $employeeError = $errors->first('employee_ids') ?: $errors->first('employee_ids.*');
@endphp

<fieldset class="mb-6" @if ($employeeError) aria-describedby="employee_ids-error" @endif>
    <legend class="mb-1 block text-sm font-medium">Medewerkers die deze dienst uitvoeren</legend>

    @if ($employees->isEmpty())
        <p class="text-sm text-gray-600">Er zijn nog geen medewerkers.</p>
    @else
        <p class="mb-2 text-xs text-gray-500">Klanten kunnen deze dienst alleen boeken bij de aangevinkte, actieve medewerkers.</p>
        <ul class="space-y-1">
            @foreach ($employees as $employee)
                <li>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="employee_ids[]"
                            value="{{ $employee->id }}"
                            @checked(in_array($employee->id, $checkedEmployeeIds, true))
                            class="rounded border-gray-300"
                        >
                        {{ $employee->display_name }}
                        @unless ($employee->is_active)
                            <span class="rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-700">inactief</span>
                        @endunless
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($employeeError)
        <p id="employee_ids-error" class="mt-1 text-sm text-red-600">{{ $employeeError }}</p>
    @endif
</fieldset>
