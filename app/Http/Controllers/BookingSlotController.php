<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailableSlotsRequest;
use App\Models\Employee;
use App\Models\Service;
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Illuminate\Http\JsonResponse;

/**
 * Free times as JSON for the booking page (ISSUE-15), fetched live each
 * time the customer picks a service, employee or date.
 *
 * Response: {"date": "2026-12-01", "slots": [{"starts_at":
 * "2026-12-01T09:15:00+01:00", "label": "09:15"}, ...]}. `starts_at` is
 * what POST /bookings expects; `label` is for display. An inactive
 * service, or an employee who cannot perform it, simply has no slots.
 */
class BookingSlotController extends Controller
{
    public function __invoke(AvailableSlotsRequest $request, AvailableSlots $availableSlots): JsonResponse
    {
        $date = $request->localDate();

        $slots = $availableSlots->for(
            Service::query()->findOrFail($request->integer('service_id')),
            Employee::query()->findOrFail($request->integer('employee_id')),
            $date,
        );

        return response()->json([
            'date' => $date->format(AvailableSlotsRequest::DATE_FORMAT),
            'slots' => array_map(fn (Slot $slot): array => [
                'starts_at' => $slot->inputValue(),
                'label' => $slot->label(),
            ], $slots),
        ]);
    }
}
