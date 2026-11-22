<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Concerns\StoresDatesInUtc;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An appointment. See docs/erd.md.
 *
 * No attributes are mass assignable: bookings are created by
 * App\Bookings\CreateBooking (ISSUE-12), which sets every attribute itself
 * from the chosen slot and a snapshot of the service.
 *
 * All times are moments, stored in UTC whatever zone they are given in
 * (StoresDatesInUtc, ISSUE-14). Show them with App\Scheduling\LocalTime.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $employee_id
 * @property int $service_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_until
 * @property int $duration_minutes
 * @property int $price_cents
 * @property BookingStatus $status
 * @property string|null $notes
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    use StoresDatesInUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'status' => BookingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Bookings that take up the employee's time between two UTC moments:
     * a blocking status and an overlap including the buffer (docs/erd.md):
     * starts_at < $until AND blocked_until > $from. The slot calculation
     * (ISSUE-11) uses this; booking creation (ISSUE-12, CreateBooking)
     * re-checks with it.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function blockingBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $query->whereIn('status', self::blockingStatusValues())
            ->where('starts_at', '<', $until->utc())
            ->where('blocked_until', '>', $from->utc());
    }

    /**
     * Appointments the customer has to attend between two UTC moments: a
     * blocking status and an overlap of the appointment itself, without the
     * buffer, which is the employee's time (docs/erd.md):
     * starts_at < $until AND ends_at > $from. Booking creation (ISSUE-12)
     * uses this to refuse a customer's double booking with any employee.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function attendedByBetween(Builder $query, User $customer, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $query->where('customer_id', $customer->id)
            ->whereIn('status', self::blockingStatusValues())
            ->where('starts_at', '<', $until->utc())
            ->where('ends_at', '>', $from->utc());
    }

    /**
     * @return list<string>
     */
    private static function blockingStatusValues(): array
    {
        return array_map(fn (BookingStatus $s): string => $s->value, BookingStatus::blocking());
    }

    /**
     * Limit a query to the bookings the given user may see: a customer their
     * own bookings, an employee the bookings in their own agenda, an admin
     * everything. Must match BookingPolicy::view().
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => null,
            UserRole::Employee => $query->whereIn(
                'employee_id',
                Employee::query()->select('id')->where('user_id', $user->id),
            ),
            UserRole::Customer => $query->where('customer_id', $user->id),
        };
    }
}
