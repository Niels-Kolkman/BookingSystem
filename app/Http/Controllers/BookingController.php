<?php

namespace App\Http\Controllers;

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Http\Requests\AvailableSlotsRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\AvailableSlots;
use App\Scheduling\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingController extends Controller
{
    /**
     * Bookings the current user may see: own bookings for a customer, own
     * agenda for an employee, everything for an admin.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $bookings = Booking::query()
            ->visibleTo($user)
            ->with(['customer', 'employee', 'service'])
            ->orderBy('starts_at')
            ->paginate(25);

        return view('bookings.index', [
            'title' => $user->isCustomer() ? 'Mijn boekingen' : 'Boekingen',
            'bookings' => $bookings,
        ]);
    }

    /**
     * The booking page (ISSUE-15): service, employee, date, time, one step
     * after the other. The page script takes the employees from the list
     * embedded in the page and fetches the free times live from
     * BookingSlotController.
     *
     * Without the script the page works too: the first form comes back here
     * with `service_id`, `employee_id` and `date` in the query string and
     * the steps are rendered on the server. After a refused booking the
     * choice comes from the old input, so the customer sees the message
     * next to fresh free times. Ids that are not offered are ignored.
     */
    public function create(Request $request, AvailableSlots $availableSlots): View
    {
        $services = Service::query()
            ->active()
            ->whereHas('employees', fn (Builder $query) => $query->where('is_active', true))
            ->with(['employees' => fn (BelongsToMany $query) => $query->where('is_active', true)->orderBy('display_name')])
            ->orderBy('name')
            ->get();

        /** @var Service|null $service */
        $service = $services->firstWhere('id', $this->chosenId($request, 'service_id'));
        /** @var Employee|null $employee */
        $employee = $service?->employees->firstWhere('id', $this->chosenId($request, 'employee_id'));
        $date = $this->chosenDate($request);

        return view('bookings.create', [
            'services' => $services,
            'service' => $service,
            'employee' => $employee,
            'date' => $date?->format(AvailableSlotsRequest::DATE_FORMAT),
            'today' => LocalTime::toLocal(CarbonImmutable::now())->format(AvailableSlotsRequest::DATE_FORMAT),
            'slots' => $service !== null && $employee !== null && $date !== null
                ? $availableSlots->for($service, $employee, $date)
                : null,
        ]);
    }

    /**
     * A customer books a free slot (ISSUE-12). Only customers reach this
     * (`role:customer` on the route). A refused booking goes back to the
     * form with the reason on the field it belongs to.
     *
     * The booking page (ISSUE-15) posts as JSON and gets 201 with the URL of
     * the booking, or the refusal as {message, reason, errors}: 409 when
     * the time was just taken (BookingRefused::isConflict()), else 422, the
     * same shape as a validation error.
     */
    public function store(StoreBookingRequest $request, CreateBooking $createBooking): RedirectResponse|JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $booking = $createBooking->handle(
                $customer,
                Service::query()->findOrFail($request->integer('service_id')),
                Employee::query()->findOrFail($request->integer('employee_id')),
                $request->startsAt(),
                $request->validated('notes'),
            );
        } catch (BookingRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $refused->getMessage(),
                    'reason' => $refused->reason,
                    'errors' => [$refused->field => [$refused->getMessage()]],
                ], $refused->isConflict() ? 409 : 422);
            }

            return back()->withInput()->withErrors([$refused->field => $refused->getMessage()]);
        }

        $start = LocalTime::toLocal($booking->starts_at);
        $request->session()->flash('status', 'Je afspraak op '.$start->format('d-m-Y').' om '.LocalTime::time($start).' is geboekt.');

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('bookings.show', $booking)], 201);
        }

        return redirect()->route('bookings.show', $booking);
    }

    /**
     * Access is checked by the `can:view,booking` route middleware.
     */
    public function show(Booking $booking): View
    {
        $booking->load(['customer', 'employee', 'service']);

        return view('bookings.show', ['booking' => $booking]);
    }

    /**
     * An id from the query string, else from the old input of a refused
     * booking.
     */
    private function chosenId(Request $request, string $key): ?int
    {
        $value = $request->query($key, $request->old($key));

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The local date from the query string, else the date of the start the
     * customer tried to book (its local part, before the offset). Null when
     * missing or not a real date.
     */
    private function chosenDate(Request $request): ?CarbonImmutable
    {
        $value = $request->query('date');
        if (! is_string($value)) {
            $old = $request->old('starts_at');
            $value = is_string($old) ? substr($old, 0, 10) : null;
        }

        // Carbon throws on anything that is not shaped like a date.
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.AvailableSlotsRequest::DATE_FORMAT, $value, LocalTime::TIMEZONE);

        return $date instanceof CarbonImmutable && $date->format(AvailableSlotsRequest::DATE_FORMAT) === $value ? $date : null;
    }
}
