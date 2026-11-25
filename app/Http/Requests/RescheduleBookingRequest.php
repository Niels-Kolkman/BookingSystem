<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Move a booking to another time (ISSUE-17). Only the shape of the input is
 * checked here; who may move it is BookingPolicy::reschedule() and whether
 * the new time is free is RescheduleBooking.
 *
 * Input: `starts_at` in the same format as for a new booking
 * (StoreBookingRequest::STARTS_AT_FORMAT, ISO 8601 with a numeric offset).
 */
class RescheduleBookingRequest extends FormRequest
{
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
            'starts_at' => ['required', 'string', 'date_format:'.StoreBookingRequest::STARTS_AT_FORMAT],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        $startsAt = CarbonImmutable::createFromFormat(StoreBookingRequest::STARTS_AT_FORMAT, (string) $this->validated('starts_at'))
            ?: throw new LogicException('starts_at passed validation but cannot be parsed.');

        return $startsAt->utc();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.*' => 'Kies een nieuw tijdstip.',
        ];
    }
}
