<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * A customer books a slot (ISSUE-12). Only the shape of the input is checked
 * here; whether the slot is free is decided by CreateBooking. Access is
 * limited to customers by the `role` middleware on the route.
 *
 * Input: `service_id`, `employee_id`, `starts_at` as ISO 8601 with a numeric
 * offset (2026-12-01T09:00:00+01:00 or 2026-12-01T08:00:00+00:00, as
 * Carbon's toIso8601String() writes it; "Z" is not accepted), so a start
 * time on the daylight-saving switch night is never ambiguous, and
 * optional `notes`.
 */
class StoreBookingRequest extends FormRequest
{
    public const STARTS_AT_FORMAT = 'Y-m-d\TH:i:sP';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'starts_at' => ['required', 'string', 'date_format:'.self::STARTS_AT_FORMAT],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        $startsAt = CarbonImmutable::createFromFormat(self::STARTS_AT_FORMAT, (string) $this->validated('starts_at'))
            ?: throw new LogicException('starts_at passed validation but cannot be parsed.');

        return $startsAt->utc();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.required' => 'Kies een dienst.',
            'service_id.integer' => 'Kies een dienst.',
            'service_id.exists' => 'Kies een dienst.',
            'employee_id.required' => 'Kies een medewerker.',
            'employee_id.integer' => 'Kies een medewerker.',
            'employee_id.exists' => 'Kies een medewerker.',
            'starts_at.required' => 'Kies een tijdstip.',
            'starts_at.string' => 'Kies een tijdstip.',
            'starts_at.date_format' => 'Kies een tijdstip.',
            'notes.string' => 'De opmerking is ongeldig.',
            'notes.max' => 'De opmerking mag maximaal :max tekens zijn.',
        ];
    }
}
