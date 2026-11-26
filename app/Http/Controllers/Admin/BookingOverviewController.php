<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Employee;
use App\Scheduling\LocalTime;
use App\Scheduling\Occupancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin overview of bookings (ISSUE-22): a list filtered by period,
 * employee and status, with the number of bookings per status, the no-show
 * rate and the occupancy of the working time. Only reachable with the admin
 * role (route group middleware). See docs/decisions.md, decision 12.
 */
class BookingOverviewController extends Controller
{
    /** The longest period shown, in days, so an edited URL stays cheap. */
    public const MAX_DAYS = 366;

    public const PER_PAGE = 25;

    /**
     * Query string, all optional; anything unreadable is ignored, like on
     * the agenda:
     * - `from`, `until`: local dates Y-m-d, inclusive, within the agenda's
     *   2000-2099; default this month. Swapped when reversed, cut off after
     *   MAX_DAYS;
     * - `employee`: an employee id;
     * - `status`: a BookingStatus value.
     *
     * A booking is in the period when it starts on one of its local dates.
     * The statistics follow the period and the employee, not the status: a
     * no-show rate of only the completed bookings would say nothing.
     */
    public function __invoke(Request $request): View
    {
        $today = LocalTime::midnight(LocalTime::toLocal(CarbonImmutable::now()));
        $from = $this->date($request, 'from') ?? $today->startOfMonth();
        $until = $this->date($request, 'until') ?? LocalTime::midnight($from->endOfMonth());
        if ($until < $from) {
            [$from, $until] = [$until, $from];
        }
        // Local midnights: a DST day is 23 or 25 hours, so count calendar days.
        $days = (int) round($from->diffInDays($until)) + 1;
        $clipped = $days > self::MAX_DAYS;
        if ($clipped) {
            $days = self::MAX_DAYS;
            $until = $from->addDays($days - 1);
        }

        $employees = Employee::query()->with('workingHours')->orderByDesc('is_active')->orderBy('display_name')->get();
        $employeeId = $request->query('employee');
        /** @var Employee|null $employee */
        $employee = is_string($employeeId) && ctype_digit($employeeId)
            ? $employees->firstWhere('id', (int) $employeeId)
            : null;
        $statusValue = $request->query('status');
        $status = is_string($statusValue) ? BookingStatus::tryFrom($statusValue) : null;

        $inPeriod = fn (): Builder => Booking::query()
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', LocalTime::midnight($until->addDay())->utc())
            ->when($employee, fn (Builder $q, Employee $e) => $q->where('employee_id', $e->id));

        $bookings = $inPeriod()
            ->when($status, fn (Builder $q, BookingStatus $s) => $q->where('status', $s->value))
            ->with(['customer', 'employee', 'service'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $inPeriod()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $perStatus = [];
        foreach (BookingStatus::cases() as $case) {
            $perStatus[$case->value] = (int) ($counts[$case->value] ?? 0);
        }
        $noShows = $perStatus[BookingStatus::NoShow->value];
        $finished = $perStatus[BookingStatus::Completed->value] + $noShows;

        // Without a filter: the active employees, whose time can be booked.
        $measured = $employee !== null ? [$employee] : $employees->where('is_active', true)->values()->all();
        $occupancy = array_map(
            fn (Employee $e): array => ['employee' => $e, 'occupancy' => Occupancy::forEmployee($e, $from, $days)],
            $measured,
        );
        $total = array_reduce(
            $occupancy,
            fn (Occupancy $sum, array $row): Occupancy => $sum->plus($row['occupancy']),
            Occupancy::none(),
        );

        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'employees' => $employees,
            'employee' => $employee,
            'status' => $status,
            'from' => $from,
            'until' => $until,
            'clipped' => $clipped,
            'perStatus' => $perStatus,
            'bookingCount' => array_sum($perStatus),
            'noShowRate' => $finished === 0 ? null : $noShows / $finished,
            'occupancy' => $occupancy,
            'totalOccupancy' => $total,
        ]);
    }

    private function date(Request $request, string $key): ?CarbonImmutable
    {
        $date = LocalTime::parseDate($request->query($key));
        if ($date === null) {
            return null;
        }

        $day = $date->format('Y-m-d');

        return $day < AgendaController::FIRST_DATE || $day > AgendaController::LAST_DATE ? null : $date;
    }
}
