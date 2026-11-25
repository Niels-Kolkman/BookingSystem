<?php

namespace App\Http\Requests;

use App\Scheduling\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The booking page asks for the free times of a service with an employee on
 * a local date (ISSUE-15). Input: `service_id`, `employee_id` and `date` as
 * Y-m-d (Europe/Amsterdam). Whether the employee can perform the service is
 * left to AvailableSlots, which then offers nothing.
 */
class AvailableSlotsRequest extends FormRequest
{
    public const DATE_FORMAT = 'Y-m-d';

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
            'date' => ['required', 'string', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    /**
     * Local midnight of the chosen date.
     */
    public function localDate(): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, (string) $this->validated('date'), LocalTime::TIMEZONE)
            ?: throw new LogicException('date passed validation but cannot be parsed.');

        return LocalTime::midnight($date);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.*' => 'Kies een dienst.',
            'employee_id.*' => 'Kies een medewerker.',
            'date.*' => 'Kies een geldige datum.',
        ];
    }
}
